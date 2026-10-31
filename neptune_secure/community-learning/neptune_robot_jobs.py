"""Robot-detector work done by a connected helper while it is idle (Auto Scout -> Robot labels).

  prepare  run the current detector on a match's labelling frames and upload its boxes as pre-fill
  train    train a wide-shot bumper detector from every completed robot label, score it against the current
           detector on a held-out match, and adopt it on Neptune only if it is better
  train_field  the same for the field detector (scoring targets + field corners for the top-down map), one per game, from every match
           marked on Scoring areas; adopted only if it places targets and corners better on a held-out match
Adopted detectors are shared through Neptune; every helper keeps a copy in ~/.neptune-trainer/robot-detector.
"""
import base64
import hashlib
import json
import math
import shutil
import subprocess
import time
from pathlib import Path

VERSION = 'robot-detector-jobs-v3-field-warm-start'
HOME = Path.home()/'.neptune-trainer'
SHARED = HOME/'robot-detector'
FIELD_ADOPT_MARGIN = .002         # mean placement error must improve by this much (fraction of frame width)
FRAME_STEP_S = 4.0
SMALL_BUMPER_H = .025             # bumpers shorter than this (of frame height) are far-field robots
WIDE_BAND = (.24, .6506)          # 2026 broadcast wide-camera band (normalized y)
ADOPT_MARGIN = .02                # new detector must beat the current one's held-out F1 by this much
MIN_FRAMES_PER_MATCH = 8
MODEL_SIZE = 's'                  # YOLO11 Small; a Nano detector in use is replaced by a fresh Small run, not fine-tuned
SMALL_BASE = {'file': 'yolo11s.pt', 'sha256': '85a76fe86dd8afe384648546b56a7a78580c7cb7b404fc595f97969322d502d5',
              'url': 'https://github.com/ultralytics/assets/releases/download/v8.3.0/yolo11s.pt'}
WARM_RECIPE = 'adopted-bumper-warm-start-v2-train-validation'
SMALL_RECIPE = 'coco-yolo11s-first-small-v1'


def api(config, action, payload=None, timeout=120):
    import urllib.request
    from neptune_helper import public_https, NoRedirect
    url = public_https(config['site'].rstrip('/')+'/api/robot-detector.php')
    body = json.dumps(dict(payload or {}, action=action)).encode()
    request = urllib.request.Request(url, data=body, headers={'Content-Type': 'application/json', 'Authorization': 'Bearer '+config['token']})
    with urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect()).open(request, timeout=timeout) as response:
        return json.loads(response.read(80*1024*1024))


# ---------- the shared detector ----------

def sync_detector(config):
    """Download Neptune's adopted wide-shot detector when it differs from this computer's copy."""
    manifest = SHARED/'manifest.json'
    have = None
    if manifest.is_file():
        have = (json.loads(manifest.read_text()).get('by_camera', {}).get('wide') or {}).get('sha256')
    reply = api(config, 'current', {'have': have})
    current = reply.get('detector')
    if not current or not reply.get('weights_b64'):
        return {'status': 'unchanged' if current else 'none_adopted'}
    raw = base64.b64decode(reply['weights_b64'])
    row = current['by_camera']['wide']
    if hashlib.sha256(raw).hexdigest() != row['sha256']:
        raise ValueError('Shared detector failed verification')
    SHARED.mkdir(parents=True, exist_ok=True)
    weights = SHARED/row['file']; tmp = weights.with_suffix('.pending'); tmp.write_bytes(raw); tmp.replace(weights)
    tmp = manifest.with_suffix('.pending'); tmp.write_text(json.dumps(current)); tmp.replace(manifest)
    for old in SHARED.glob('*.pt'):
        if old.name != row['file']:
            old.unlink()
    return {'status': 'updated', 'sha256': row['sha256']}


def train_device():
    """NVIDIA GPU when there is one; otherwise CPU. Never the Apple GPU: detector training hangs on it with this
    PyTorch build. Batch sizes stay the same on every device so results are comparable."""
    try:
        import torch
        return 0 if torch.cuda.is_available() else 'cpu'
    except ImportError:
        return 'cpu'


def all_cores():
    """Train on every CPU core. Tracking pins torch to one thread for the rest of the helper's session, which made
    detector training run on 1 of N cores; restore that setting afterwards."""
    import contextlib
    import os
    import torch

    @contextlib.contextmanager
    def scope():
        before = torch.get_num_threads()
        torch.set_num_threads(max(1, os.cpu_count() or 1))
        try:
            yield
        finally:
            torch.set_num_threads(before)
    return scope()


def batch_progress(model, label, epochs, progress):
    """Report within each pass, not only at its end, so Neptune sees the helper working during long passes."""
    seen = {'epoch': None, 'batch': 0, 'said': 0.}
    def on_batch(trainer):
        if seen['epoch'] != trainer.epoch:
            seen.update(epoch=trainer.epoch, batch=0)
        seen['batch'] += 1
        if time.monotonic()-seen['said'] < 15:     # the helper log gets a line at most every 15 s
            return
        seen['said'] = time.monotonic()
        batches = len(trainer.train_loader) if getattr(trainer, 'train_loader', None) is not None else '?'
        progress('%s: pass %d of up to %d · batch %d of %s' % (label, trainer.epoch+1, epochs, seen['batch'], batches))
    model.add_callback('on_train_batch_end', on_batch)
    # Running now console: batch counters and speed, and one line per finished pass with losses and mAP.
    import neptune_telemetry as telemetry
    model.add_callback('on_train_batch_end', telemetry.batch_counter(label, epochs))
    model.add_callback('on_fit_epoch_end', lambda trainer: telemetry.epoch_line(label, trainer, epochs))


def adopted_holdout(manifest):
    """The match the adopted detector was checked on (None before the first adoption)."""
    try:
        return (json.loads(Path(manifest).read_text()).get('report') or {}).get('holdout_match')
    except (OSError, ValueError):
        return None


def pick_holdout(keys, previous, trained_on=()):
    """The held-out match: never one the detector in use trained on (else its score is not a fair comparison).
    Among the rest, the previous one while still eligible (scores stay comparable run to run), else the last by name.
    Neptune makes this choice when Train is pressed (request 'holdout'); this is the fallback for older requests."""
    fair = sorted(k for k in keys if k not in set(trained_on)) or sorted(keys)
    return previous if previous in fair else fair[-1]


def adopted_trained_on(manifest):
    """Matches the adopted (or shipped) detector learned from."""
    try:
        doc = json.loads(Path(manifest).read_text())
    except (OSError, ValueError):
        return []
    report = doc.get('report') or {}
    if doc.get('trained_on') or report.get('trained_on'):
        return list(doc.get('trained_on') or report['trained_on'])
    return [m for m in report.get('matches') or [] if m != report.get('holdout_match')]


def current_wide_weights():
    from neptune_yolo import bundled_detectors
    row = bundled_detectors().get('wide')
    return Path(row['path']) if row else None


def model_scale(weights):
    """The YOLO size letter ('n', 's', ...) recorded in a checkpoint."""
    from ultralytics import YOLO
    return (getattr(YOLO(str(weights)).model, 'yaml', None) or {}).get('scale')


def small_base():
    """COCO-pretrained YOLO11 Small, downloaded once and checked against its pinned fingerprint."""
    import urllib.request
    path = HOME/'base-models'/SMALL_BASE['file']
    if path.is_file() and hashlib.sha256(path.read_bytes()).hexdigest() == SMALL_BASE['sha256']:
        return path
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix('.part')
    with urllib.request.urlopen(SMALL_BASE['url'], timeout=300) as r, open(tmp, 'wb') as f:
        shutil.copyfileobj(r, f, 1 << 20)
    if hashlib.sha256(tmp.read_bytes()).hexdigest() != SMALL_BASE['sha256']:
        tmp.unlink(missing_ok=True)
        raise ValueError('The YOLO11 Small starting model failed its fingerprint check.')
    tmp.replace(path)
    return path


def training_base(holdout):
    """(starting weights, recipe). Continue the proven bumper detector when it is already Small;
    a Nano detector in use is replaced by a fresh Small run from COCO, which never saw any match.

    Refuse an inherited model that learned the evaluation match: excluding it
    from this run alone would not remove its influence from the weights.
    """
    current = current_wide_weights()
    manifest = SHARED/'manifest.json' if (SHARED/'manifest.json').is_file() else Path(__file__).with_name('robot-detector')/'manifest.json'
    if not (current and current.is_file()):
        raise ValueError('Install the current wide bumper detector before fine-tuning; its training provenance is required.')
    if model_scale(current) != MODEL_SIZE:
        return small_base(), SMALL_RECIPE
    if holdout in adopted_trained_on(manifest):
        raise ValueError('The starting detector trained on the evaluation match; choose an independent holdout.')
    return current, WARM_RECIPE


def dataset_audit(manifest, holdout):
    """Report the actual built dataset, including matches with fewer than eight frames."""
    rows = manifest['frames']
    counts = {}
    for row in rows:
        key = row['match_key']
        if key == holdout and row['split'] != 'val':
            raise ValueError('Evaluation frames must never enter detector training.')
        if key != holdout and row['split'] != 'train':
            raise ValueError('Unexpected validation match in detector dataset.')
        counts[key] = counts.get(key, 0)+1
    validation = [{k: row[k] for k in ('match_key', 'source_sha256', 'image_sha256', 'video_time_ms', 'boxes')}
                  for row in rows if row['split'] == 'val']
    validation.sort(key=lambda row: (row['match_key'], row['video_time_ms'], row['image_sha256']))
    digest = hashlib.sha256(json.dumps(validation, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    return {'matches': sorted(counts), 'frames_per_match': counts,
            'trained_on': sorted({row['match_key'] for row in rows if row['split'] == 'train'}),
            'evaluation_sha256': digest}


# ---------- frames ----------

def ffmpeg(name='ffmpeg'):
    vendored = Path(__file__).with_name('vendor')/'bin'/name
    return str(vendored) if vendored.is_file() else name


def duration_s(video):
    out = subprocess.check_output([ffmpeg('ffprobe'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', str(video)], text=True, timeout=60)
    return float(out.strip())


def frame(video, t):
    import cv2
    import numpy as np
    raw = subprocess.run([ffmpeg(), '-v', 'error', '-ss', '%.3f' % t, '-i', str(video), '-frames:v', '1', '-f', 'image2pipe', '-vcodec', 'mjpeg', '-'],
                         capture_output=True, timeout=120).stdout
    return cv2.imdecode(np.frombuffer(raw, np.uint8), cv2.IMREAD_COLOR) if raw else None


def detect(model, image, conf=.25):
    """Bumper boxes {alliance,x,y,w,h} normalized to the full frame."""
    h, w = image.shape[:2]
    from neptune_yolo import unique_bumpers
    r = model.predict(image, imgsz=1280, conf=conf, verbose=False, device=train_device())[0]
    found = []
    for box, cls, score in zip(r.boxes.xyxy.tolist(), r.boxes.cls.tolist(), r.boxes.conf.tolist()):
        name = str(r.names[int(cls)]).lower()
        alliance = 'red' if 'red' in name else 'blue' if 'blue' in name else None
        if alliance:
            found.append({'alliance': alliance, 'box': box, 'confidence': float(score)})
    # The same containment cleanup tracking uses: a smaller box nested in a stronger one on the same bumper
    # is one bumper, not two, in the held-out check and in the labelling guesses.
    return [{'alliance': d['alliance'], 'x': d['box'][0]/w, 'y': d['box'][1]/h, 'w': (d['box'][2]-d['box'][0])/w, 'h': (d['box'][3]-d['box'][1])/h}
            for d in unique_bumpers(found)]


def match_video(video_id, folder):
    from neptune_robot_labels import local_video, youtube_download
    video = local_video(video_id)
    if video:
        return video
    from neptune_robot_labels import VIDEOS
    video = VIDEOS/(video_id+'.mp4'); video.parent.mkdir(parents=True, exist_ok=True)
    if not video.is_file():
        youtube_download(video_id, video)
    return video


def frame_times(length_s, labelled=(), picked=None):
    """The regular grid, frames already labelled, and hard frames picked from tracking reviews (neptune_hard_frames)."""
    extra = {t for t in picked or [] if type(t) is int and 0 <= t < length_s*1000}
    return sorted(set(range(0, int(length_s*1000), int(FRAME_STEP_S*1000))) | set(labelled) | extra)


def prepare(config, req, progress):
    """Detector guesses on frames every FRAME_STEP_S seconds of the match video, uploaded for Robot labels."""
    import os
    os.environ.setdefault('YOLO_OFFLINE', '1')
    from ultralytics import YOLO
    weights = current_wide_weights()
    if not weights:
        raise ValueError('No robot detector is installed on this helper yet.')
    work = HOME/'robot-detector-work'/req['id']; work.mkdir(parents=True, exist_ok=True)
    progress('Downloading '+req['match_key'])
    video = match_video(req['video_id'], work)
    length = duration_s(video); model = YOLO(str(weights)); frames = {}
    source_sha=hashlib.sha256(video.read_bytes()).hexdigest()
    from neptune_robot_labels import sync
    labelled={int(t) for d in sync(config) if d.get('video_id')==req['video_id'] for t in d.get('frames',{})}
    times = frame_times(length, labelled, req.get('times_ms'))
    for i, t in enumerate(times):
        still=work/('%08d.jpg'%t)
        subprocess.run([ffmpeg(),'-v','error','-y','-ss','%.3f'%(t/1000),'-i',str(video),'-frames:v','1','-q:v','2',str(still)],check=True,timeout=120)
        raw=still.read_bytes()
        api(config,'label_frame_put',{'request_id':req['id'],'lease':req['lease'],'video_id':req['video_id'],'t_ms':t,'source_sha256':source_sha,'image_b64':base64.b64encode(raw).decode()})
        import cv2
        image = cv2.imread(str(still))
        if image is not None:
            frames[str(t)] = detect(model, image)
        if i % 10 == 0:
            progress('Preparing clear source stills and bumper guesses: frame %d of %d' % (i+1, len(times)))
    api(config, 'prefill_put', {'request_id': req['id'], 'lease': req['lease'], 'video_id': req['video_id'], 'frames': frames,
                                'detector': hashlib.sha256(weights.read_bytes()).hexdigest()})
    return {'frames': len(frames), 'boxes': sum(len(b) for b in frames.values())}


# ---------- training ----------

def iou(a, b):
    w = max(0., min(a[2], b[2])-max(a[0], b[0])); h = max(0., min(a[3], b[3])-max(a[1], b[1]))
    u = (a[2]-a[0])*(a[3]-a[1])+(b[2]-b[0])*(b[3]-b[1])-w*h
    return w*h/u if u > 0 else 0.


def score(truth, pred, diagnose=None):
    """One-to-one matches at IoU >= 0.3, split into the wide band and close-ups: {band: [matched, truth, pred]}.

    Boxes are [x0, y0, x1, y1] with an optional fifth alliance. diagnose (a dict) collects where the
    detector fails without changing the score: red/blue mix-ups and missed far (small) bumpers.
    """
    band = lambda b: 'wide' if WIDE_BAND[0] <= (b[1]+b[3])/2 <= WIDE_BAND[1] else 'closeup'
    out = {'wide': [0, 0, 0], 'closeup': [0, 0, 0]}; used = set(); notes = diagnose if diagnose is not None else {}
    for t in truth:
        out[band(t)][1] += 1
        best = max(((iou(t, p), i) for i, p in enumerate(pred) if i not in used), default=(0, None))
        if best[0] >= .3:
            used.add(best[1]); out[band(t)][0] += 1
            if len(t) > 4 and len(pred[best[1]]) > 4 and t[4] != pred[best[1]][4]:
                notes['alliance_mixed'] = notes.get('alliance_mixed', 0)+1
        else:
            key = 'missed_small' if t[3]-t[1] < SMALL_BUMPER_H else 'missed_large'
            notes[key] = notes.get(key, 0)+1
    for i, p in enumerate(pred):
        out[band(p)][2] += 1
        if i not in used:
            notes['false_positive_'+band(p)] = notes.get('false_positive_'+band(p), 0)+1
    return out


def evaluate(weights, frames):
    """frames: [(image path, truth xyxy boxes)] -> recall/precision/F1 overall and per band."""
    import cv2
    from ultralytics import YOLO
    model = YOLO(str(weights)); tot = {'wide': [0, 0, 0], 'closeup': [0, 0, 0]}; notes = {}
    for path, truth in frames:
        image = cv2.imread(str(path))
        pred = [[b['x'], b['y'], b['x']+b['w'], b['y']+b['h'], b['alliance']] for b in detect(model, image)]
        for k, v in score(truth, pred, notes).items():
            tot[k] = [a+b for a, b in zip(tot[k], v)]
    def summary(m, t, p):
        r = m/t if t else 0.; pr = m/p if p else 0.
        return {'recall': round(r, 3), 'precision': round(pr, 3), 'f1': round(2*r*pr/(r+pr), 3) if r+pr else 0., 'bumpers': t, 'detections': p,
                'matched': m, 'missed': t-m, 'false_positives': p-m}
    all_ = [sum(v[i] for v in tot.values()) for i in range(3)]
    return dict(summary(*all_), wide=summary(*tot['wide']), closeup=summary(*tot['closeup']), failures=notes)


def external_training_datasets(config):
    packages=api(config,'training_supplement_index').get('packages',[])
    if not isinstance(packages,list) or len(packages)>8:raise ValueError('Invalid external training package index')
    datasets=[]
    for item in packages:
        datasets.extend(api(config,'training_supplements',{'id':item['id'],'sha256':item['sha256']}).get('datasets',[]))
    return datasets


def yolo_validation_from_training(dataset, every=5):
    """Move every fifth training frame to YOLO validation, away from the held-out match.

    YOLO picks best.pt and stops early by its validation score, so the held-out match must never be that
    set: it is only for the comparison that decides adoption. Validation images are removed from training.
    """
    dataset = Path(dataset)
    for kind in ('images', 'labels'):
        shutil.rmtree(dataset/kind/'val', ignore_errors=True); (dataset/kind/'val').mkdir(parents=True)
    picked = sorted((dataset/'images'/'train').iterdir())[::every]
    if not picked:
        raise ValueError('Detector training needs labelled training frames.')
    for image in picked:
        shutil.move(str(image), str(dataset/'images'/'val'/image.name))
        label = dataset/'labels'/'train'/(image.stem+'.txt')
        shutil.move(str(label), str(dataset/'labels'/'val'/label.name))
    return len(picked)


def train(config, req, progress):
    """Train from every completed robot label; adopt on Neptune only if better on a held-out match."""
    import os
    os.environ.setdefault('YOLO_OFFLINE', '1')
    from ultralytics import YOLO
    import neptune_robot_labels as labels
    from neptune_detector_training import prepare as prepare_dataset
    progress('Downloading robot labels')
    docs = labels.sync(config)
    counts = {d.get('match_key'): sum(1 for f in d.get('frames', {}).values() if f.get('complete')) for d in docs}
    usable = sorted(k for k, n in counts.items() if k and n >= MIN_FRAMES_PER_MATCH)
    if len(usable) < 2:
        raise ValueError('Label at least %d frames in two different matches first.' % MIN_FRAMES_PER_MATCH)
    in_use = SHARED/'manifest.json' if (SHARED/'manifest.json').is_file() else Path(__file__).with_name('robot-detector')/'manifest.json'
    holdout = req.get('holdout') if req.get('holdout') in usable else pick_holdout(usable, adopted_holdout(SHARED/'manifest.json'), adopted_trained_on(in_use))
    work = HOME/'robot-detector-work'/req['id']; shutil.rmtree(work, ignore_errors=True); work.mkdir(parents=True)   # a restarted run starts clean
    def snapshot_download(video_id,t,sha,path):
        reply=api(config,'label_frame_get',{'video_id':video_id,'t_ms':t,'sha256':sha})
        raw=base64.b64decode(reply['image_b64'],validate=True)
        if hashlib.sha256(raw).hexdigest()!=sha:raise ValueError('Training frame failed its saved-image checksum')
        path.write_bytes(raw)
    built = labels.build(docs, work/'frames', holdout={holdout}, download=labels.youtube_download, progress=progress,snapshot_download=snapshot_download)
    config_yaml = prepare_dataset(Path(built['manifest']), work/'dataset')
    yolo_validation_from_training(config_yaml.parent)   # before workshop images join training
    from neptune_external_bumpers import append_workshop
    supplemental = append_workshop(external_training_datasets(config), work/'dataset')
    if supplemental['frames']:
        progress('Including %d reviewed workshop images (%d bumper boxes) in training only' % (supplemental['frames'], supplemental['boxes']))
    base, recipe = training_base(holdout)
    base_sha256 = hashlib.sha256(base.read_bytes()).hexdigest()
    model = YOLO(str(base))
    if recipe == WARM_RECIPE:
        from neptune_yolo import bumper_classes
        bumper_classes(model.names)  # refuse a generic context model even if the path is wrong
        progress('Fine-tuning the current bumper detector; evaluation match remains excluded')
    else:
        progress('Training a larger (YOLO11 Small) bumper detector from scratch; evaluation match remains excluded')
    epochs = int(req.get('epochs') or (80 if recipe == WARM_RECIPE else 120))
    def epoch_end(trainer):
        progress('Training robot detector: pass %d of up to %d' % (trainer.epoch+1, epochs))
    model.add_callback('on_fit_epoch_end', epoch_end)
    batch_progress(model, 'Training robot detector', epochs, progress)
    # NVIDIA GPU or CPU (train_device): Apple GPU training hangs with this PyTorch build.
    with all_cores():
        result = model.train(data=str(config_yaml), epochs=epochs, imgsz=1280, batch=4, device=train_device(), workers=0, patience=20, seed=2026,
                         deterministic=True, plots=False, project=str(work/'runs'), name='bumper', exist_ok=True, verbose=False,
                         translate=.2, scale=.5, fliplr=.5, mosaic=1.0, close_mosaic=10)
    best = Path(result.save_dir)/'weights'/'best.pt'
    manifest = json.loads(Path(built['manifest']).read_text())
    audit = dataset_audit(manifest, holdout)
    check = [(work/'frames'/f['image'], [[b['xywh'][0]-b['xywh'][2]/2, b['xywh'][1]-b['xywh'][3]/2, b['xywh'][0]+b['xywh'][2]/2, b['xywh'][1]+b['xywh'][3]/2,
               'red' if b['class_id'] == 0 else 'blue'] for b in f['boxes']]) for f in manifest['frames'] if f['split'] == 'val']
    progress('Comparing new and current detector on %s' % holdout)
    new = evaluate(best, check)
    current = current_wide_weights(); old = evaluate(current, check) if current else None
    adopt = old is None or new['f1'] >= old['f1']+ADOPT_MARGIN
    report = {'engine': VERSION, 'holdout_match': holdout, 'trained_on': audit['trained_on'], 'external_training': supplemental, 'frames': built['frames']+supplemental['frames'], 'train_frames': built['train']+supplemental['frames'], 'check_frames': built['val'],
              'bumpers': built['boxes']+supplemental['boxes'], 'matches': audit['matches'], 'frames_per_match': audit['frames_per_match'],
              'evaluation_sha256': audit['evaluation_sha256'], 'starting_weights_sha256': base_sha256,
              'training_recipe': recipe, 'model_size': MODEL_SIZE, 'new': new, 'current': old, 'adopted': adopt, 'margin': ADOPT_MARGIN, 'skipped': built['skipped']}
    progress('Making the review video')
    report['review'] = send_review(config, req, report, lambda out: __import__('neptune_review_video').robot_video(out, check, best, current, report))
    if adopt:
        progress('Sharing the new detector with Neptune')
        api(config, 'adopt', {'request_id': req['id'], 'lease': req['lease'], 'weights_b64': base64.b64encode(best.read_bytes()).decode(), 'report': report}, timeout=600)
        sync_detector(config)
    return report


# ---------- field detector ----------

def sync_field_detector(config, game_key=None):
    """Download Neptune's adopted field detector for one game (or every configured game) when it differs."""
    import neptune_field_detector as fd
    if game_key is None:
        seasons = json.loads(Path(__file__).with_name('season_rules.json').read_text())['seasons'].values()
        return {s['game_key']: sync_field_detector(config, s['game_key']) for s in seasons if s.get('configured') and s.get('game_key')}
    folder = fd.shared_dir(game_key); manifest = folder/'manifest.json'
    have = json.loads(manifest.read_text()).get('sha256') if manifest.is_file() else None
    reply = api(config, 'current_field', {'have': have, 'game_key': game_key})
    current = reply.get('detector')
    if not current or not reply.get('weights_b64'):
        return {'status': 'unchanged' if current else 'none_adopted'}
    raw = base64.b64decode(reply['weights_b64'])
    if hashlib.sha256(raw).hexdigest() != current['sha256']:
        raise ValueError('Shared field detector failed verification')
    folder.mkdir(parents=True, exist_ok=True)
    tmp = folder/'model.pending'; tmp.write_bytes(raw); tmp.replace(folder/'model.pt')
    if isinstance(reply.get('reference'), dict):
        tmp = folder/'reference.pending'; tmp.write_text(json.dumps(reply['reference'])); tmp.replace(folder/'reference.json')
    tmp = manifest.with_suffix('.pending'); tmp.write_text(json.dumps(current)); tmp.replace(manifest)
    return {'status': 'updated', 'sha256': current['sha256']}


def region_documents(config):
    """Every saved Scoring-areas document this owner can train from."""
    import urllib.request
    from neptune_helper import public_https, NoRedirect
    url = public_https(config['site'].rstrip('/')+'/api/field-regions.php')
    request = urllib.request.Request(url, data=json.dumps({'all': True}).encode(), headers={'Content-Type': 'application/json', 'Authorization': 'Bearer '+config['token']})
    with urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect()).open(request, timeout=60) as response:
        return json.loads(response.read(32*1024*1024)).get('documents', [])


def doc_video(doc):
    source = doc.get('source') or {}
    vid = source.get('video_id') or str(source.get('name', '')).rsplit('.', 1)[0]
    return vid if len(vid) == 11 else None


def sample_frames(video, step=2.0):
    """Frames across the video that look like the broadcast's usual view (replays and graphics dropped)."""
    import cv2
    import numpy as np
    length = duration_s(video); frames = []
    t = length*.03
    while t < length*.97:
        image = frame(video, t)
        if image is not None:
            frames.append((t, cv2.resize(image, (1280, 720))))
        t += step
    if not frames:
        return []
    thumbs = np.stack([cv2.resize(cv2.cvtColor(i, cv2.COLOR_BGR2GRAY), (64, 36)).astype('float32') for _, i in frames])
    diffs = np.abs(thumbs-np.median(thumbs, axis=0)).mean(axis=(1, 2)); limit = np.percentile(diffs, 50)*2.5+4
    return [f for f, d in zip(frames, diffs) if d <= limit]


def placement(weights, frames, doc):
    """How close a field detector puts scoring targets and corners to where they were marked on a held-out match."""
    import neptune_field_detector as fd
    from ultralytics import YOLO
    model = YOLO(str(weights)); groups={}
    for item in frames:
        _,image=item[:2];truth=item[2] if len(item)>2 else doc
        key=json.dumps(fd.yolo_labels(truth),sort_keys=True);group=groups.setdefault(key,{'doc':truth,'per':[]})
        per=group['per']
        h, w = image.shape[:2]
        r = model.predict(image, imgsz=fd.FRAME_WIDTH, conf=fd.MIN_CONFIDENCE, verbose=False, device=train_device())[0]
        per.append([{'class': fd.CLASSES[int(c)], 'box': [b[0]/w, b[1]/h, b[2]/w, b[3]/h],
                     'view': fd.view_of((b[0]+b[2])/2/w, (b[1]+b[3])/2/h, truth)} for b, c in zip(r.boxes.xyxy.tolist(), r.boxes.cls.tolist())])
    errors=[];expected=0
    for group in groups.values():
        doc=group['doc'];per=group['per']
        found=fd.aggregate(per,doc)
        for target in [x for x in doc['regions'] if x['kind'] == 'scoring_target']:
            expected += 1; c = fd.centre(fd.box_of(target['points']))
            near = [math.dist(c, fd.centre(t['box'])) for t in found['scoring_target'] if t['view'] == target['camera_view']]
            if near and min(near) < .1:
                errors.append(min(near))
        # Each visible marked corner counts on its own: matched one-to-one to the nearest found corner within 10%,
        # so finding three of four corners scores three rather than none.
        corners = fd.visible_corners(doc); finds = [fd.centre(c['box']) for c in found['field_corner']]
        expected += len(corners); matched_marks = set(); matched_finds = set()
        for d, i, j in sorted((math.dist(a, b), i, j) for i, a in enumerate(corners) for j, b in enumerate(finds)):
            if d >= .1:
                break
            if i not in matched_marks and j not in matched_finds:
                errors.append(d); matched_marks.add(i); matched_finds.add(j)
    return {'found': len(errors), 'expected': expected, 'mean_error': round(sum(errors)/len(errors), 4) if errors else None}


def better_placement(new, old):
    if old is None or not old['found']:
        return bool(new['found'])
    if new['found'] != old['found']:
        return new['found'] > old['found']
    return new['mean_error'] is not None and new['mean_error'] <= old['mean_error']-FIELD_ADOPT_MARGIN


def field_training_base(game,holdout):
    import neptune_field_detector as fd
    from neptune_yolo import bundle,bundle_path
    current=fd.model_path(game)
    if current:
        manifest=fd.shared_dir(game)/'manifest.json'
        if manifest.is_file():trained=adopted_trained_on(manifest)
        else:
            source=fd.model_dir()/'trained_on.json'
            if not source.is_file():raise ValueError('Field baseline training provenance is missing')
            trained=json.loads(source.read_text()).get(game,[])
        if holdout in trained:raise ValueError('The field baseline already learned from the proposed evaluation match')
        return current,'current-game-field-warm-start-v1'
    return bundle_path()/bundle()['context']['file'],'first-field-model-from-context-v1'


def train_field(config, req, progress):
    """Train one game's field detector from every match marked for that game; adopt only if it places scoring
    targets and field corners better on a held-out match."""
    import os
    os.environ.setdefault('YOLO_OFFLINE', '1')
    import cv2
    from ultralytics import YOLO
    import neptune_field_detector as fd
    import neptune_field_training as ft
    from neptune_training import HELD_OUT_MATCHES,HELD_OUT_SOURCES
    game = req.get('game_key') or fd.shipped_game()
    progress('Downloading scoring areas')
    docs = [dict(d, regions=[r for r in d.get('regions', []) if r.get('game_key') == game]) for d in region_documents(config)]
    docs = [d for d in docs if doc_video(d) and fd.yolo_labels(d) and fd.training_geometry(d) and (d.get('source') or {}).get('match_key') not in HELD_OUT_MATCHES and d.get('source_sha256') not in HELD_OUT_SOURCES]
    if len(docs) < 2:
        raise ValueError('Mark scoring targets and a visible field boundary on at least two %s matches first.' % game)
    docs=list({doc_video(d):d for d in docs}.values())  # site corrections replace earlier seed documents
    docs.sort(key=lambda d: ((d.get('source') or {}).get('match_key') or '', doc_video(d)))
    doc_key = lambda d: (d.get('source') or {}).get('match_key') or doc_video(d)   # Neptune adds match_key
    keys = [doc_key(d) for d in docs]
    shipped = json.loads((fd.model_dir()/'trained_on.json').read_text()).get(game, []) if (fd.model_dir()/'trained_on.json').is_file() else []
    in_use = adopted_trained_on(fd.shared_dir(game)/'manifest.json') if (fd.shared_dir(game)/'manifest.json').is_file() else shipped
    corner_keys = [doc_key(d) for d in docs if fd.visible_corners(d)]
    if not corner_keys:raise ValueError('The held-out check still needs a match with confirmed physical corners; boundary-only matches can train targets')
    requested_holdout = req.get('holdout')
    if requested_holdout and requested_holdout not in corner_keys:raise ValueError('The requested held-out match needs confirmed physical corners; it was not replaced')
    held_key = requested_holdout or pick_holdout(corner_keys, adopted_holdout(fd.shared_dir(game)/'manifest.json'), in_use)
    holdout = next(d for d in docs if doc_key(d) == held_key)   # checked, never trained on
    base,recipe=field_training_base(game,held_key)
    base_sha256=hashlib.sha256(base.read_bytes()).hexdigest()
    frame_counts={} ; evaluation_sha256=None; source_fingerprints={}
    work = HOME/'field-detector-work'/req['id']; shutil.rmtree(work, ignore_errors=True); data = work/'dataset'; held = []   # a restarted run starts clean
    for split in ('train', 'val'):
        (data/'images'/split).mkdir(parents=True, exist_ok=True); (data/'labels'/split).mkdir(parents=True, exist_ok=True)
    for d in docs:
        vid = doc_video(d); split = 'val' if d is holdout else 'train'
        progress('Preparing field frames from '+((d.get('source') or {}).get('match_key') or vid))
        video=match_video(vid,work)
        source_fingerprints[doc_key(d)]=hashlib.sha256(Path(video).read_bytes()).hexdigest()
        if source_fingerprints[doc_key(d)] in HELD_OUT_SOURCES:raise ValueError('Reserved benchmark source cannot train or tune a field detector')
        frames=ft.samples(d,sample_frames(video,step=2.0 if split=='val' else 4.0),lambda t:frame(video,t))
        frame_counts[doc_key(d)]=len(frames)
        # The held-out match is only for the comparison below; it never reaches YOLO, not even as the validation
        # set that picks the best pass. Every fifth training frame stands in as that validation set.
        for i, (t, image, frame_doc) in enumerate(frames if split == 'train' else []):
            labels=fd.yolo_labels(frame_doc)
            name = '%s_%07d' % (vid, round(t*1000))
            for folder in ('train', 'val') if i % 5 == 0 else ('train',):
                cv2.imwrite(str(data/'images'/folder/(name+'.jpg')), image, [cv2.IMWRITE_JPEG_QUALITY, 90])
                (data/'labels'/folder/(name+'.txt')).write_text('\n'.join(labels)+'\n')
        if split == 'val':
            held = frames[::3]
            evaluation_sha256=ft.fingerprint(held)
    if len(held)<3:raise ValueError('At least three distinct held-out field frames are required for the stable placement check; your marks are unchanged')
    (data/'data.yaml').write_text('path: %s\ntrain: images/train\nval: images/val\nnames:\n  0: scoring_target\n  1: field_corner\n' % data)
    model = YOLO(str(base)); epochs = int(req.get('epochs') or 30)
    if recipe=='current-game-field-warm-start-v1' and list(dict(model.names).values())!=fd.CLASSES:raise ValueError('Field baseline class mapping does not match targets/corners')
    progress('Fine-tuning this game’s field detector; saved annotation intervals and evaluation match are preserved')
    model.add_callback('on_fit_epoch_end', lambda trainer: progress('Training field detector: pass %d of up to %d' % (trainer.epoch+1, epochs)))
    batch_progress(model, 'Training field detector', epochs, progress)
    # Same picture size the detector was first trained at and is always checked at (small corners stay legible).
    with all_cores():
        result = model.train(data=str(data/'data.yaml'), epochs=epochs, imgsz=fd.FRAME_WIDTH, batch=4, device=train_device(), workers=0, patience=8, seed=7,
                         deterministic=True, plots=False, project=str(work/'runs'), name='field', exist_ok=True, verbose=False,
                         translate=.3, scale=.6, degrees=3, perspective=.0005, fliplr=.5, mosaic=1.0, hsv_v=.4, hsv_s=.6)
    best = Path(result.save_dir)/'weights'/'best.pt'
    key = held_key
    progress('Comparing new and current field detector on '+key)
    new = placement(best, held, holdout)
    current = fd.model_path(game); old = placement(current, held, holdout) if current else None
    adopt = better_placement(new, old)
    report = {'engine': VERSION, 'game_key': game, 'holdout_match': key, 'trained_on': sorted(set(in_use)|{k for k in keys if k != key}), 'fine_tuned_on':[k for k in keys if k != key], 'starting_model_trained_on':in_use, 'matches': len(docs), 'new': new, 'current': old, 'adopted': adopt, 'margin': FIELD_ADOPT_MARGIN, 'training_recipe':recipe,'starting_weights_sha256':base_sha256,'frames_per_match':frame_counts,'evaluation_sha256':evaluation_sha256,'evaluation_frames':len(held),'source_fingerprints':source_fingerprints}
    progress('Making the review video')
    report['review'] = send_review(config, req, report, lambda out: __import__('neptune_review_video').field_video(out, held, best, holdout, report))
    if adopt:
        progress('Sharing the new field detector with Neptune')
        # The held-out match's marks travel with the detector: unmarked events of this game are matched against them.
        api(config, 'adopt_field', {'request_id': req['id'], 'lease': req['lease'], 'weights_b64': base64.b64encode(best.read_bytes()).decode(),
                                    'reference': holdout, 'report': report}, timeout=600)
        sync_field_detector(config, game)
    return report


# ---------- review videos ----------

def send_review(config, req, report, render):
    """Render this run's review video and send it to Neptune, which uploads it to YouTube. Never fails the run."""
    import neptune_review_video as rv
    try:
        out = HOME/'review-videos'/(req['id']+'.mp4'); out.parent.mkdir(parents=True, exist_ok=True)
        render(out)
        if out.stat().st_size > 45*1024*1024:
            return {'status': 'too_large'}
        title, description = rv.describe(req['kind'], report, VERSION)
        reply = api(config, 'review_put', {'request_id': req['id'], 'lease': req['lease'], 'title': title, 'description': description,
                                           'video_b64': base64.b64encode(out.read_bytes()).decode()}, timeout=900)
        out.unlink()
        return reply.get('review') or {'status': 'sent'}
    except Exception as error:
        return {'status': 'failed', 'error': str(error)[:200]}


def test_robot(config, req, progress):
    """Run the current robot detector, tracker, bumper OCR, constant identity, and top-down homography on a match,
    then render and upload the review video to Neptune for viewing and optional YouTube upload."""
    import neptune_games as games
    import neptune_training as training
    import neptune_review_video as rv
    from neptune_facts import analyze_video
    from neptune_field_regions import sync as sync_regions, sync_events, event_key, match_key_of
    try:
        sync_detector(config)
        sync_field_detector(config)
    except Exception:
        pass
    work = HOME/'robot-detector-work'/req['id']; shutil.rmtree(work, ignore_errors=True); work.mkdir(parents=True, exist_ok=True)
    match_key = req['match_key']
    title = req.get('title') or match_key
    progress('Downloading '+match_key)
    video = match_video(req['video_id'], work)
    sha = hashlib.sha256(Path(video).read_bytes()).hexdigest()
    dur = duration_s(video)
    game = games.select(match_key)
    roster = req.get('roster') if isinstance(req.get('roster'), dict) else None
    site_key = hashlib.sha256(config['site'].rstrip('/').encode()).hexdigest()[:24]
    cache_dir = HOME/'cache'/site_key/'localizations'/sha/game['fingerprint']
    cache_dir.mkdir(parents=True, exist_ok=True)
    facts_file = cache_dir/'facts.json'

    class Stage(list):
        def __setitem__(self, key, value):
            super().__setitem__(key, value); progress(str(value))

    facts = None
    if facts_file.is_file():
        try:
            cand = json.loads(facts_file.read_text())
            if cand.get('source', {}).get('sha256') == sha:
                facts = cand
        except Exception:
            facts = None
    match_rec = {'key': match_key, 'title': title, 'roster': roster or {'red': [], 'blue': []},
                 'source_url': 'https://www.youtube.com/watch?v='+req['video_id']}
    if facts is None:
        progress('Reading scoreboard and bumper numbers in '+match_key)
        facts = analyze_video(video, match_rec, cache_dir, Stage(['Reading match video']), duration=dur, source_sha256=sha)
        training.write(facts_file, facts)
    else:
        facts = training.refresh_cached_facts(facts_file, facts, match_rec, game, sha, video=video, progress=progress)
    progress('Tracking robots, reading bumpers, and resolving constant identity')
    facts = training.ensure_tracking(video, facts, game, cache_dir, progress)
    training.write(facts_file, facts)
    progress('Mapping top-down field homography')
    try:
        sync_regions(config, video, facts)
        sync_events(config, [event_key(match_key_of(facts) or match_key)])
    except Exception:
        pass
    progress('Rendering original view with boxes, bumper OCR, and top-down homography')
    out = HOME/'review-videos'/('robot-test-'+req['id']+'.mp4'); out.parent.mkdir(parents=True, exist_ok=True)
    _, summary = rv.robot_test_video(out, video, facts, game['game_key'], title)
    if out.stat().st_size > 45*1024*1024:
        out.unlink(missing_ok=True)
        raise ValueError('Rendered match test video exceeds the 45 MB upload limit.')
    yt_title, yt_desc = rv.describe_robot_test(title, match_key, summary, VERSION)
    progress('Sending match test video to Neptune')
    reply = api(config, 'robot_test_put', {
        'request_id': req['id'], 'lease': req['lease'],
        'title': yt_title, 'description': yt_desc, 'summary': summary,
        'video_b64': base64.b64encode(out.read_bytes()).decode()
    }, timeout=900)
    out.unlink(missing_ok=True)
    return {'match_key': match_key, 'summary': summary, 'test': reply.get('test')}


# ---------- polling ----------

def run_pending(config, progress, busy=lambda value: None):
    """Claim and run one queued request. Returns True when a request was handled."""
    from neptune_steps import Steps, PLANS
    req = api(config, 'claim').get('request')
    if not req:
        return False
    busy(True); last = [0.]; steps = Steps(req['kind'] if req['kind'] in PLANS else 'train')
    def report(message):
        message = steps.describe(message)
        progress(message)
        if time.monotonic()-last[0] > 20:
            last[0] = time.monotonic()
            try:
                reply = api(config, 'progress', {'request_id': req['id'], 'lease': req['lease'], 'message': str(message)[:600]})
            except Exception:
                reply = {}
            if reply.get('cancelled'):
                raise InterruptedError('Cancelled on Neptune')
    try:
        result = prepare(config, req, report) if req['kind'] == 'prepare' else \
                 test_robot(config, req, report) if req['kind'] == 'test_robot' else \
                 train_field(config, req, report) if req['kind'] == 'train_field' else train(config, req, report)
        fmt = lambda p: 'none found' if not p or not p['found'] else '%d/%d within %.1f%%' % (p['found'], p['expected'], p['mean_error']*100)
        message = ('Prepared %d frames' % result['frames']) if req['kind'] == 'prepare' else \
            ('Tested %s: %d OCR verified, %d constant ID' % (result['match_key'], result['summary']['verified_points'], result['summary']['constant_points'])) if req['kind'] == 'test_robot' else \
            (('Adopted: targets/corners %s vs %s' if result['adopted'] else 'Kept the current field detector: new %s vs %s') % (fmt(result['new']), fmt(result['current']))) if req['kind'] == 'train_field' else \
            ('Adopted: held-out F1 %.2f vs %s' % (result['new']['f1'], '%.2f' % result['current']['f1'] if result['current'] else 'none')) if result['adopted'] else \
            ('Kept the current detector: new held-out F1 %.2f vs %.2f' % (result['new']['f1'], result['current']['f1']))
        api(config, 'finish', {'request_id': req['id'], 'lease': req['lease'], 'ok': True, 'message': message, 'report': result})
        progress(message)
    except Exception as error:
        try:
            api(config, 'finish', {'request_id': req['id'], 'lease': req['lease'], 'ok': False, 'message': str(error)[:400]})
        except Exception:
            pass
        progress('Robot detector request failed: '+str(error)[:200])
    finally:
        # Frames, datasets and downloaded videos for this run; the result is already on Neptune.
        import shutil
        for name in ('robot-detector-work', 'field-detector-work'):
            shutil.rmtree(HOME/name/req['id'], ignore_errors=True)
        busy(False)
    return True
