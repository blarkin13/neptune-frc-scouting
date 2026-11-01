"""Learned field context: find scoring targets and field corners in a new broadcast, then carry the marked
regions of a reference match onto it. Supporting evidence only; it never proves scoring.

Training data comes from matches with marked regions: every in-match frame of every match at a marked event
is labelled by that event's regions (the broadcast camera is fixed within an event). At a new event the
detector finds the targets and the four field-boundary corners; marked zones are mapped through the field
plane (reference corners -> field -> detected corners), so they follow the new camera angle. Without four
corners, the two wide-view targets place them by a similarity transform; without those, nothing is applied.
"""
import json
import math
from pathlib import Path

VERSION = 'learned-field-context-v1'
CLASSES = ['scoring_target', 'field_corner']
CORNER_BOX = (.035, .045)          # normalized width/height of the box drawn around each corner point
FRAME_WIDTH = 1280                  # training/inference frame width (keeps small hubs legible)
MIN_CONFIDENCE = .35
EDGE_MARGIN = .01                   # a marked corner this close to the frame edge is where the picture ends, not a corner


def model_dir():
    """The engine's field-detector folder (reference regions; shipped weights)."""
    return Path(__file__).with_name('field-detector')


def shared_dir(game_key):
    """This game's field detector adopted on Neptune (downloaded by the helper)."""
    import os
    return Path(os.environ.get('NEPTUNE_FIELD_DETECTOR', Path.home()/'.neptune-trainer'/'field-detector'))/game_key


def shipped_game():
    """The game the engine's shipped field detector was trained for (from its reference regions)."""
    try:
        regions = json.loads((model_dir()/'reference.json').read_text()).get('regions') or []
    except (OSError, ValueError):
        return None
    return regions[0].get('game_key') if regions else None


def model_path(game_key=None):
    """The field detector for this game: the one adopted on Neptune, else the shipped one if it is for this game.
    Field elements change every season, so a detector never crosses games."""
    game_key = game_key or shipped_game()
    if not game_key:
        return None
    for path in (shared_dir(game_key)/'model.pt', model_dir()/'model.pt' if game_key == shipped_game() else None):
        if path and path.is_file():
            return path
    return None


def reference(game_key):
    """Marked regions the detector's finds are matched against: the adopted one's, else the shipped one's."""
    for path in (shared_dir(game_key)/'reference.json', model_dir()/'reference.json' if game_key == shipped_game() else None):
        if path and path.is_file():
            return json.loads(path.read_text())
    return None


def ordered_quad(points):
    """Order four points as top-left, top-right, bottom-right, bottom-left (image coordinates)."""
    if len(points) != 4:
        return None
    top = sorted(sorted(points, key=lambda p: p[1])[:2], key=lambda p: p[0])
    bottom = sorted(sorted(points, key=lambda p: p[1])[2:], key=lambda p: p[0])
    return [top[0], top[1], bottom[1], bottom[0]]


def boundary_corners(doc):
    """Calibration requires four physical corners, never a clipped boundary outline."""
    for kind in ('field_calibration', 'field_boundary'):
        for r in doc['regions']:
            if r['kind'] != kind or r['camera_view'] != 'wide':
                continue
            points = r.get('points', [])
            if kind == 'field_boundary' and 'corner_indices' in r:
                indices = r['corner_indices']
                if len(indices) != 4 or r.get('inferred_corner_indices'):
                    continue
                points = [points[i] for i in indices]
            if len(points) == 4:
                return points if kind == 'field_calibration' else ordered_quad(points)
    return None


def visible_corners(doc):
    """Only explicit physical corners are detector truth. Inferred points are not labels.

    Legacy four-point outlines keep their calibration meaning. Other boundary
    polygons, including hexagons, need explicit corner_indices; their turns may
    simply follow a crop, overlay or obstruction.
    """
    for kind in ('field_calibration', 'field_boundary'):
        for r in doc['regions']:
            if r['kind'] != kind or r['camera_view'] != 'wide':
                continue
            points = r.get('points', [])
            indices = r.get('corner_indices', list(range(4)) if len(points) == 4 else [])
            inferred = set(r.get('inferred_corner_indices', []))
            corners = [points[i] for i in indices if i not in inferred]
            corners = [p for p in corners if all(EDGE_MARGIN < x < 1-EDGE_MARGIN for x in p)]
            if corners:
                return corners
    return []


def training_geometry(doc):
    """Targets plus a visible boundary polygon are usable, even without calibration."""
    regions = doc.get('regions', [])
    return (any(r.get('kind') == 'scoring_target' and r.get('camera_view') == 'wide' for r in regions)
            and any(r.get('kind') in ('field_boundary', 'field_calibration')
                    and r.get('camera_view') == 'wide' and len(r.get('points', [])) >= 3 for r in regions))


def box_of(points):
    xs = [p[0] for p in points]; ys = [p[1] for p in points]
    return [min(xs), min(ys), max(xs), max(ys)]


def yolo_labels(doc):
    """YOLO lines (class cx cy w h, normalized) for every target and field corner in a marked document."""
    lines = []
    for r in doc['regions']:
        if r['kind'] == 'scoring_target':
            x0, y0, x1, y1 = box_of(r['points'])
            lines.append((0, (x0+x1)/2, (y0+y1)/2, x1-x0, y1-y0))
    for x, y in visible_corners(doc):
        w, h = CORNER_BOX
        x0, x1 = max(0., x-w/2), min(1., x+w/2); y0, y1 = max(0., y-h/2), min(1., y+h/2)
        lines.append((1, (x0+x1)/2, (y0+y1)/2, x1-x0, y1-y0))
    return ['%d %.6f %.6f %.6f %.6f' % l for l in lines]


def in_match_times(facts, step=1.0):
    """Sample times inside observed auto/teleop periods."""
    out = []
    for name in ('auto', 'teleop'):
        p = (facts.get('periods') or {}).get(name)
        if p and type(p.get('start')) in (int, float):
            t = max(0., p['start'])
            while t <= p['end']:
                out.append(round(t, 3)); t += step
    return sorted(set(out))


def build_dataset(matches, out, step=1.0, holdout=(), progress=print):
    """matches: [(match_key, video, facts, doc)]. Writes a YOLO dataset; holdout match keys go to validation.

    Frames that do not look like the reference broadcast view (replays, full-screen graphics) are dropped by
    comparing a small grayscale thumbnail with the match's median frame.
    """
    import cv2
    import numpy as np
    import subprocess
    out = Path(out)
    for split in ('train', 'val'):
        (out/'images'/split).mkdir(parents=True, exist_ok=True); (out/'labels'/split).mkdir(parents=True, exist_ok=True)
    counts = {'train': 0, 'val': 0, 'dropped': 0}
    ffmpeg = Path(__file__).with_name('vendor')/'bin'/'ffmpeg'
    ffmpeg = str(ffmpeg) if ffmpeg.exists() else 'ffmpeg'
    for key, video, facts, doc in matches:
        labels = yolo_labels(doc)
        if not labels:
            continue
        split = 'val' if key in holdout else 'train'
        frames = []
        for t in in_match_times(facts, step):
            raw = subprocess.run([ffmpeg, '-v', 'error', '-ss', str(t), '-i', str(video), '-frames:v', '1', '-vf', 'scale=%d:-2' % FRAME_WIDTH,
                                  '-f', 'image2pipe', '-vcodec', 'mjpeg', '-'], capture_output=True).stdout
            image = cv2.imdecode(np.frombuffer(raw, np.uint8), cv2.IMREAD_COLOR) if raw else None
            if image is not None:
                frames.append((t, image))
        if not frames:
            continue
        thumbs = np.stack([cv2.resize(cv2.cvtColor(i, cv2.COLOR_BGR2GRAY), (64, 36)).astype('float32') for _, i in frames])
        median = np.median(thumbs, axis=0)
        diffs = np.abs(thumbs-median).mean(axis=(1, 2)); limit = np.percentile(diffs, 50)*2.5+4
        for (t, image), diff in zip(frames, diffs):
            if diff > limit:
                counts['dropped'] += 1; continue
            name = '%s_%07d' % (key, round(t*1000))
            cv2.imwrite(str(out/'images'/split/(name+'.jpg')), image, [cv2.IMWRITE_JPEG_QUALITY, 90])
            (out/'labels'/split/(name+'.txt')).write_text('\n'.join(labels)+'\n')
            counts[split] += 1
        progress('%s: %d frames' % (key, len(frames)))
    (out/'data.yaml').write_text('path: %s\ntrain: images/train\nval: images/val\nnames:\n  0: scoring_target\n  1: field_corner\n' % out)
    return counts


def train(dataset, base_weights, out, epochs=60, device='mps', progress=print):
    """Fine-tune a YOLO detector. Strong translation/scale augmentation discourages memorising one camera."""
    import os
    os.environ.setdefault('YOLO_OFFLINE', '1')
    from ultralytics import YOLO
    model = YOLO(str(base_weights))
    result = model.train(data=str(Path(dataset)/'data.yaml'), epochs=epochs, imgsz=FRAME_WIDTH, batch=8, device=device,
                         project=str(out), name='run', exist_ok=True, verbose=False, plots=False,
                         translate=.3, scale=.6, degrees=3, perspective=.0005, fliplr=.5, mosaic=1.0, hsv_v=.4, hsv_s=.6,
                         patience=20, workers=0, seed=7, deterministic=True)
    return Path(out)/'run'/'weights'/'best.pt', result


def homography(src, dst):
    """3x3 matrix (row-major list) mapping four src points onto four dst points."""
    from neptune_homography import solve
    a = []; b = []
    for (x, y), (u, v) in zip(src, dst):
        a.extend([[x, y, 1, 0, 0, 0, -u*x, -u*y], [0, 0, 0, x, y, 1, -v*x, -v*y]]); b.extend([u, v])
    return solve(a, b)+[1.]


def apply(h, p):
    x, y = p; d = h[6]*x+h[7]*y+h[8]
    return None if abs(d) < 1e-9 else [(h[0]*x+h[1]*y+h[2])/d, (h[3]*x+h[4]*y+h[5])/d]


def similarity(a0, a1, b0, b1):
    """Map segment a0-a1 onto b0-b1 (scale, rotation, translation)."""
    ax, ay = a1[0]-a0[0], a1[1]-a0[1]; bx, by = b1[0]-b0[0], b1[1]-b0[1]
    n = ax*ax+ay*ay
    if n < 1e-9:
        return None
    c = (ax*bx+ay*by)/n; s = (ax*by-ay*bx)/n
    return lambda p: [b0[0]+c*(p[0]-a0[0])-s*(p[1]-a0[1]), b0[1]+s*(p[0]-a0[0])+c*(p[1]-a0[1])]


def centre(box):
    return [(box[0]+box[2])/2, (box[1]+box[3])/2]


def view_of(x, y, doc):
    """Which camera view a detection falls in, from the reference's region extents."""
    best = None
    for r in doc['regions']:
        x0, y0, x1, y1 = box_of(r['points'])
        if x0-.02 <= x <= x1+.02 and y0-.02 <= y <= y1+.02:
            area = (x1-x0)*(y1-y0)
            if best is None or (r['camera_view'] == 'wide') < (best[1] == 'wide') or area < best[0]:
                best = (area, r['camera_view'])
    return best[1] if best else None


def transfer(reference, targets, corners):
    """Carry the reference's marked regions onto a new view.

    targets/corners: detected boxes [x0,y0,x1,y1] (normalized) aggregated over the match. Wide-view regions
    move through the field plane when four corners are found, else by the two wide targets; close-up regions
    move with their own target. Returns (regions, method) - regions empty when nothing could be placed.
    """
    ref_corners = boundary_corners(reference)
    wide_ref = sorted([r for r in reference['regions'] if r['kind'] == 'scoring_target' and r['camera_view'] == 'wide'],
                      key=lambda r: centre(box_of(r['points']))[0])
    wide_now = sorted([t for t in targets if t['view'] == 'wide'], key=lambda t: centre(t['box'])[0])
    mapper = None; method = None
    quad = ordered_quad([centre(c['box']) for c in corners]) if len(corners) == 4 else None
    if ref_corners and quad:
        to_field = homography(ref_corners, [[0, 0], [1, 0], [1, 1], [0, 1]]); from_field = homography([[0, 0], [1, 0], [1, 1], [0, 1]], quad)
        mapper = lambda p: apply(from_field, apply(to_field, p) or p); method = 'field_plane'
    elif len(wide_ref) >= 2 and len(wide_now) == len(wide_ref):
        # Same number of wide targets in both views: the outermost pair fixes position, scale and rotation.
        mapper = similarity(centre(box_of(wide_ref[0]['points'])), centre(box_of(wide_ref[-1]['points'])), centre(wide_now[0]['box']), centre(wide_now[-1]['box']))
        method = 'outer_targets'
    out = []
    for r in reference['regions']:
        if r['camera_view'] == 'wide':
            if mapper is None:
                continue
            pts = [mapper(p) for p in r['points']]
        else:
            ref_t = [x for x in reference['regions'] if x['kind'] == 'scoring_target' and x['camera_view'] == r['camera_view']]
            now_t = [t for t in targets if t['view'] == r['camera_view']]
            if len(ref_t) != 1 or len(now_t) != 1:
                continue
            a = box_of(ref_t[0]['points']); b = now_t[0]['box']
            sx = (b[2]-b[0])/max(1e-6, a[2]-a[0]); sy = (b[3]-b[1])/max(1e-6, a[3]-a[1])
            pts = [[b[0]+(p[0]-a[0])*sx, b[1]+(p[1]-a[1])*sy] for p in r['points']]
        if any(p is None for p in pts):
            continue
        pts = [[min(1., max(0., p[0])), min(1., max(0., p[1]))] for p in pts]
        out.append(dict(r, points=pts, detected=True, reviewed=True, event_template=True, start_ms=0, reference_ms=0, end_ms=10**9))
    return out, method


def aggregate(detections, reference, min_frames=.3):
    """Stable boxes over a match: median box per (class, view, left/right order), kept when seen often enough."""
    import statistics
    frames = len(detections)
    groups = {}
    for dets in detections:
        for cls in ('scoring_target', 'field_corner'):
            rows = sorted([d for d in dets if d['class'] == cls], key=lambda d: (d['view'] or '', centre(d['box'])[0], centre(d['box'])[1]))
            per_view = {}
            for d in rows:
                per_view.setdefault(d['view'], []).append(d)
            for view, items in per_view.items():
                for i, d in enumerate(items):
                    groups.setdefault((cls, view, i, len(items)), []).append(d['box'])
    out = {'scoring_target': [], 'field_corner': []}
    best = {}
    for (cls, view, i, n), boxes in groups.items():
        if len(boxes) < max(3, min_frames*frames):
            continue
        key = (cls, view)
        if key not in best or len(boxes) > best[key][0]:
            best[key] = (len(boxes), n, {})
        if best[key][1] == n:
            best[key][2][i] = [statistics.median(b[j] for b in boxes) for j in range(4)]
    for (cls, view), (_, n, boxes) in best.items():
        out[cls].extend({'view': view, 'box': boxes[i]} for i in sorted(boxes))
    return out


def detect(video, facts, reference, weights=None, step=2.0, device=None, progress=lambda s: None, game_key=None):
    """Find targets/corners across a match and return a field-regions document for it, or a status."""
    import cv2
    import numpy as np
    import os
    import subprocess
    os.environ.setdefault('YOLO_OFFLINE', '1')
    weights = Path(weights) if weights else model_path(game_key)
    if not weights or not weights.is_file():
        return {'status': 'no_field_detector', 'version': VERSION, 'regions': []}
    from ultralytics import YOLO
    model = YOLO(str(weights))
    ffmpeg = Path(__file__).with_name('vendor')/'bin'/'ffmpeg'
    ffmpeg = str(ffmpeg) if ffmpeg.exists() else 'ffmpeg'
    per_frame = []
    for t in in_match_times(facts, step):
        raw = subprocess.run([ffmpeg, '-v', 'error', '-ss', str(t), '-i', str(video), '-frames:v', '1', '-vf', 'scale=%d:-2' % FRAME_WIDTH,
                              '-f', 'image2pipe', '-vcodec', 'mjpeg', '-'], capture_output=True).stdout
        image = cv2.imdecode(np.frombuffer(raw, np.uint8), cv2.IMREAD_COLOR) if raw else None
        if image is None:
            continue
        h, w = image.shape[:2]
        r = model.predict(image, imgsz=FRAME_WIDTH, conf=MIN_CONFIDENCE, verbose=False, device=device)[0]
        dets = []
        for xyxy, cls, conf in zip(r.boxes.xyxy.cpu().tolist(), r.boxes.cls.cpu().tolist(), r.boxes.conf.cpu().tolist()):
            box = [xyxy[0]/w, xyxy[1]/h, xyxy[2]/w, xyxy[3]/h]; c = centre(box)
            dets.append({'class': CLASSES[int(cls)], 'box': box, 'confidence': conf, 'view': view_of(c[0], c[1], reference)})
        per_frame.append(dets)
    if not per_frame:
        return {'status': 'no_frames', 'version': VERSION, 'regions': []}
    found = aggregate(per_frame, reference)
    regions, method = transfer(reference, found['scoring_target'], found['field_corner'])
    # Alliance sides for this match come from robots at the start of auto, as for marked event regions.
    from neptune_field_regions import event_document
    doc = dict(reference, regions=regions, source_sha256=facts['source']['sha256'])
    applied = event_document(facts, reference['regions'][0]['game_key'], [doc]) if regions else None
    return {'status': 'detected' if applied and applied.get('regions') else 'not_found', 'version': VERSION, 'method': method,
            'frames': len(per_frame), 'targets': len(found['scoring_target']), 'corners': len(found['field_corner']),
            **({'document': dict(applied, application='detected', detector=VERSION)} if applied and applied.get('regions') else {})}
