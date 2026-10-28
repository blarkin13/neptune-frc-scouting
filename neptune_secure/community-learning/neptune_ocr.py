"""Shared OCR interface for the facts pipeline. Every backend runs on this computer; none is paid.

Backends
  apple-vision  helper/bin/neptune-ocr (Apple Vision, macOS 14+ on Apple Silicon), built from vision/ocr.swift.
  rapidocr      RapidOCR on ONNX Runtime with its bundled PP-OCR models (Windows, Linux, Intel Macs).

recognize(paths) returns {path: [item]} where an item is
  {'t': text, 'c': confidence 0..1 or None, 'x','y','w','h': box as fractions of the image, top-left origin}.
"""
import os
import json
import platform
import re
import shutil
import subprocess
import sys
from pathlib import Path

APPLE_BIN = Path(__file__).resolve().with_name('bin') / 'neptune-ocr'


class OcrUnavailable(ValueError):
    pass


class OcrBackend:
    name = 'none'

    def version(self) -> dict:
        raise NotImplementedError

    def recognize(self, paths) -> dict:
        raise NotImplementedError

    def describe(self) -> dict:
        return dict(self.version(), backend=self.name)


class AppleVisionBackend(OcrBackend):
    name = 'apple-vision'

    def __init__(self, binary=APPLE_BIN):
        self.binary = Path(binary)
        self._version = None

    @classmethod
    def available(cls, binary=APPLE_BIN) -> bool:
        return sys.platform == 'darwin' and platform.machine() == 'arm64' and Path(binary).is_file()

    def version(self):
        if self._version is None:
            out = subprocess.run([str(self.binary), '--version'], capture_output=True, text=True, timeout=30)
            if out.returncode != 0:
                raise OcrUnavailable('The Apple Vision text reader could not start.')
            self._version = json.loads(out.stdout)
            self._version.pop('backend', None)
        return self._version

    def _server(self):
        """One long-lived reader (--serve) instead of a launch per call; None with an older reader."""
        if getattr(self, '_serve', None) is None:
            self._serve = False
            try:
                caps = subprocess.run([str(self.binary), '--capabilities'], capture_output=True, text=True, timeout=30).stdout.split()
                if 'serve' in caps:
                    self._serve = subprocess.Popen([str(self.binary), '--serve'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True, bufsize=1)
            except OSError:
                pass
        if self._serve and self._serve.poll() is not None:   # it exited; start again next time
            self._serve = None
            return None
        return self._serve or None

    def recognize(self, paths):
        paths = [str(p) for p in paths]
        out = {}
        server = self._server() if paths else None
        if server:
            for path in paths:
                if '\n' in path:
                    raise OcrUnavailable('Image path contains a line break.')
                server.stdin.write(path+'\n'); server.stdin.flush()
                line = server.stdout.readline()
                if not line:
                    self._serve = None
                    raise OcrUnavailable('The Apple Vision text reader stopped.')
                d = json.loads(line)
                if d.get('file') != Path(path).name:
                    raise OcrUnavailable('The Apple Vision text reader returned results out of order.')
                if d.get('error'):raise OcrUnavailable('Apple Vision could not read an image: '+str(d['error'])[:160])
                out[path] = d.get('items', [])
            return out
        for i in range(0, len(paths), 48):
            chunk = paths[i:i + 48]
            proc = subprocess.run([str(self.binary)] + chunk, capture_output=True, text=True, timeout=900)
            if proc.returncode != 0:
                raise OcrUnavailable('The Apple Vision text reader failed: ' + (proc.stderr.strip()[:200] or 'unknown error'))
            lines = proc.stdout.splitlines()
            if len(lines) != len(chunk):
                raise OcrUnavailable('The Apple Vision text reader returned %d results for %d images.' % (len(lines), len(chunk)))
            for path, line in zip(chunk, lines):   # one line per image, in input order
                d = json.loads(line)
                if d.get('file') != Path(path).name:
                    raise OcrUnavailable('The Apple Vision text reader returned results out of order.')
                if d.get('error'):raise OcrUnavailable('Apple Vision could not read an image: '+str(d['error'])[:160])
                out[path] = d.get('items', [])
        return out


class RapidOcrBackend(OcrBackend):
    name = 'rapidocr'
    # Detector resolution: bumper digits are small, so do not shrink large crops to the 736 px default.
    # Broadcast text is upright, so the rotation classifier is off (about 10% faster, same digits on tuning frames).
    PARAMS = {'Global.log_level': 'error', 'Global.use_cls': False, 'Det.limit_type': 'max', 'Det.limit_side_len': 2000}

    def __init__(self):
        self._engine = None
        self._cv2 = None
        self.provider = 'cpu'

    def _params(self):
        """CPU by default; the NVIDIA card when the helper trains on it and onnxruntime-gpu is installed."""
        params = dict(self.PARAMS)
        if os.environ.get('NEPTUNE_OCR_DEVICE') != 'cuda':
            return params
        try:
            import onnxruntime
            if 'CUDAExecutionProvider' not in onnxruntime.get_available_providers():
                return params
            if hasattr(onnxruntime, 'preload_dlls'):
                onnxruntime.preload_dlls()      # CUDA and cuDNN from the nvidia-* wheels
        except Exception:
            return params
        # Crop sizes vary, so an exhaustive cuDNN search would re-run for almost every image.
        params.update({'EngineConfig.onnxruntime.use_cuda': True, 'EngineConfig.onnxruntime.cuda_ep_cfg.cudnn_conv_algo_search': 'HEURISTIC'})
        self.provider = 'cuda'
        return params

    @classmethod
    def available(cls) -> bool:
        try:
            import importlib.util
            return importlib.util.find_spec('rapidocr') is not None and importlib.util.find_spec('onnxruntime') is not None
        except (ImportError, ValueError):
            return False

    def _load(self):
        if self._engine is None:
            try:
                import cv2
                from rapidocr import RapidOCR
            except ImportError as error:
                raise OcrUnavailable('RapidOCR is not installed. Run the Neptune Helper installer again.') from error
            self._cv2 = cv2
            self._engine = RapidOCR(params=self._params())
        return self._engine

    def version(self):
        from importlib.metadata import version
        models = sorted(p.name for p in self._models_folder().glob('*.onnx'))
        try:
            runtime = version('onnxruntime')
        except Exception:
            runtime = version('onnxruntime-gpu')
        return {'rapidocr': version('rapidocr'), 'onnxruntime': runtime,
                'opencv': version('opencv-python-headless'), 'models': models,
                'detector_limit_side_len': self.PARAMS['Det.limit_side_len'], 'rotation_classifier': self.PARAMS['Global.use_cls']}

    @staticmethod
    def _models_folder():
        import importlib.util
        spec = importlib.util.find_spec('rapidocr')
        return Path(spec.origin).parent / 'models'

    def recognize(self, paths):
        engine = self._load()
        out = {}
        for p in paths:
            p = str(p)
            image = self._cv2.imread(p)
            if image is None:
                out[p] = []
                continue
            height, width = image.shape[:2]
            result = engine(image)
            items = []
            if result.boxes is not None and result.txts is not None:
                for box, text, score in zip(result.boxes, result.txts, result.scores):
                    xs = [float(q[0]) for q in box]
                    ys = [float(q[1]) for q in box]
                    items.append({'t': str(text), 'c': float(score), 'x': min(xs) / width, 'y': min(ys) / height,
                                  'w': (max(xs) - min(xs)) / width, 'h': (max(ys) - min(ys)) / height})
            out[p] = items
        return out


BACKENDS = {'apple-vision': AppleVisionBackend, 'rapidocr': RapidOcrBackend}


def available_backends():
    return [name for name, cls in BACKENDS.items() if cls.available()]


def select_backend(preference='auto') -> OcrBackend:
    """Apple Vision on Apple Silicon Macs, otherwise RapidOCR. Raises OcrUnavailable if neither exists."""
    if shutil.which('ffmpeg') is None:
        raise OcrUnavailable('FFmpeg is not installed. Run the Neptune Helper installer again.')
    if preference != 'auto':
        if preference not in BACKENDS:
            raise OcrUnavailable('Unknown text reader: ' + preference)
        if not BACKENDS[preference].available():
            raise OcrUnavailable('The %s text reader is not installed on this computer.' % preference)
        return BACKENDS[preference]()
    for name in ('apple-vision', 'rapidocr'):
        if BACKENDS[name].available():
            return BACKENDS[name]()
    raise OcrUnavailable('No text reader is installed. Run the Neptune Helper installer again.')


# ---------------------------------------------------------------- bumper numbers on one detection

# Bumper numbers fill most of the strip's height. Measured on the hand-read benchmark (tools/ocr-benchmark), both
# readers find more numbers when the strip is enlarged to about BUMPER_TARGET_PX tall (never shrunk, at most 4x)
# and the window stays tight around the strip.
BUMPER_TARGET_PX = 160
BUMPER_MAX_SCALE = 4
LOOKALIKE_DIGITS = {'O': '0', 'o': '0', 'D': '0', 'Q': '0', 'U': '0', 'I': '1', 'l': '1', 'i': '1', '|': '1', 'L': '1', 'T': '7',
                    'Z': '2', 'z': '2', 'S': '5', 's': '5', 'B': '8', 'g': '9', 'q': '9', 'G': '6', 'b': '6', 'A': '4'}


def bumper_window(box, width, height):
    """Crop around one bumper box (x, y, w, h in source pixels) -> ((x0, y0, x1, y1), scale, inner).

    The window keeps the strip with a small margin (numbers often run past a tight detector box); the chassis
    above it only adds clutter. `inner` is the box in fractions of the window, for checking that a read sits on
    this bumper and not a neighbour's."""
    x, y, w, h = box
    x0, x1 = max(0, int(x - 0.1 * w - 2)), min(width, int(x + 1.1 * w + 2))
    y0, y1 = max(0, int(y - 0.3 * h - 2)), min(height, int(y + 1.6 * h + 2))
    scale = max(1, min(BUMPER_MAX_SCALE, round(BUMPER_TARGET_PX / max(1.0, h))))
    cw, ch = max(1, x1 - x0), max(1, y1 - y0)
    inner = ((x - x0) / cw, (y - y0) / ch, (x + w - x0) / cw, (y + h - y0) / ch)
    return (x0, y0, x1, y1), scale, inner


def white_rows(rows):
    """Rows of BGR bytes -> rows of BGR bytes showing only how white each pixel is (255 - min channel), so white
    numbers on red or blue fabric become dark digits on a light ground. Apple Vision misses many numbers on
    the coloured crop and finds them on this one; the coloured crop stays the second pass for black numbers."""
    out = []
    for row in rows:
        grey = bytes(255 - min(t) for t in zip(row[0::3], row[1::3], row[2::3]))
        out.append(bytes(v for v in grey for _ in range(3)))
    return out


def bumper_crop(image, box, white=False):
    """OpenCV image + bumper box -> (enlarged crop, inner box). Used by the benchmark and image tools."""
    import cv2
    (x0, y0, x1, y1), scale, inner = bumper_window(box, image.shape[1], image.shape[0])
    crop = image[y0:y1, x0:x1]
    if scale > 1:
        crop = cv2.resize(crop, ((x1 - x0) * scale, (y1 - y0) * scale), interpolation=cv2.INTER_CUBIC)
    if white:
        crop = cv2.cvtColor(255 - crop.min(axis=2), cv2.COLOR_GRAY2BGR)
    return crop, inner


def _digits(text):
    """OCR text -> digit string with look-alike letters mapped, or None when other characters remain."""
    s = re.sub(r'[\s.,:;\'"`\-_•*#~]', '', text)
    fixed = ''.join(LOOKALIKE_DIGITS.get(ch, ch) for ch in s)
    return (fixed, s) if fixed.isdigit() else (None, s)


def _near(a, b):
    """True when a and b differ by one substitution, insertion or deletion."""
    if abs(len(a) - len(b)) > 1 or a == b:
        return False
    if len(a) == len(b):
        return sum(x != y for x, y in zip(a, b)) == 1
    short, long_ = (a, b) if len(a) < len(b) else (b, a)
    return any(long_[:k] + long_[k + 1:] == short for k in range(len(long_)))


def read_team(items, roster, alliance=None, inner=None):
    """OCR items of one bumper crop -> {'team', 'kind', 'text', 'confidence', 'correction'} or None.

    Only the match's own teams can be answers. kind is 'exact' when the digits read (allowing a bumper's number
    printed twice, or split into pieces by the reader) equal a roster number with no character changed; 'corrected'
    when one look-alike letter or one digit differs from exactly one team of the bumper's alliance (four-digit
    reads and longer only); 'ambiguous' when the read fits two teams. Items whose centre is off the bumper
    (`inner`, fractions of the crop) are ignored."""
    teams = [int(t) for t in roster['red'] + roster['blue']]
    side = [int(t) for t in roster[alliance]] if alliance in ('red', 'blue') else teams
    if inner is not None:
        mx = 0.2 * (inner[2] - inner[0]) + 0.04
        bh = inner[3] - inner[1]
        items = [i for i in items if inner[0] - mx <= i['x'] + i['w'] / 2 <= inner[2] + mx
                 and inner[1] - 1.0 * bh - 0.04 <= i['y'] + i['h'] / 2 <= inner[3] + 0.3 * bh + 0.04]
    if not items:
        return None
    # Candidate texts: each item, each whitespace token, and the items on one line joined left to right
    # (Apple Vision and RapidOCR sometimes return "94 4 4" or "10" + "340" for one number).
    cands = []
    for i in items:
        cands.append((i['t'], i))
        cands += [(tok, i) for tok in i['t'].split() if tok != i['t']]
    line = sorted(items, key=lambda i: i['x'])
    for a in range(len(line)):
        group = [line[a]]
        for b in line[a + 1:]:
            if abs((b['y'] + b['h'] / 2) - (group[-1]['y'] + group[-1]['h'] / 2)) <= 0.6 * max(b['h'], group[-1]['h']):
                group.append(b)
                if len(group) > 1:
                    cands.append((''.join(g['t'] for g in group), min(group, key=lambda g: g.get('c') or 0)))
    exact, corrected = {}, {}
    for text, item in cands:
        fixed, raw = _digits(text)
        if not fixed or len(fixed) < 2:
            continue
        conf = item.get('c')
        for t in teams:
            ts = str(t)
            if raw == ts or (raw.isdigit() and len(raw) == 2 * len(ts) and raw == ts + ts):
                exact.setdefault(t, (text, conf))
        if exact:
            continue
        for t in side:
            ts = str(t)
            if len(fixed) >= 4 and (fixed == ts or fixed == ts + ts or _near(fixed, ts)):
                note = 'look-alike characters' if fixed == ts or fixed == ts + ts else 'one character'
                corrected.setdefault(t, (text, conf, 'read %s as %d (%s)' % (raw, t, note)))
    if len(exact) == 1:
        (t, (text, conf)), = exact.items()
        return {'team': t, 'kind': 'exact', 'text': text, 'confidence': conf, 'correction': None}
    if len(exact) > 1:
        return {'team': None, 'kind': 'ambiguous', 'text': ' / '.join(v[0] for v in exact.values()), 'confidence': None, 'candidates': sorted(exact)}
    if len(corrected) == 1:
        (t, (text, conf, note)), = corrected.items()
        return {'team': t, 'kind': 'corrected', 'text': text, 'confidence': conf, 'correction': note}
    if len(corrected) > 1:
        return {'team': None, 'kind': 'ambiguous', 'text': ' / '.join(v[0] for v in corrected.values()), 'confidence': None, 'candidates': sorted(corrected)}
    return None
