"""Retrain the bumper detector from reviewed boxes; action/score labels stay separate.

Input is a local reviewed-box manifest, not an executable dataset YAML or an
untrusted checkpoint URL. Imported scouting totals never become robot boxes.
"""
import argparse
import hashlib
import json
import re
from pathlib import Path
import shutil

from neptune_yolo import bundle, bundle_path


def prepare(manifest, output):
    from neptune_training import HELD_OUT_MATCHES, HELD_OUT_SOURCES
    manifest = Path(manifest).resolve(); root = manifest.parent
    data = json.loads(manifest.read_text()); output = Path(output)
    if data.get('schema_version') != 1 or data.get('names') != ['red', 'blue'] or data.get('season') != 2026:
        raise ValueError('Use a reviewed 2026 bumper-box dataset with red and blue classes.')
    groups = {}; images = {}; accepted = []; totals = {'train': 0, 'val': 0}
    for row in data.get('frames', []):
        match, video, split = row.get('match_key'), row.get('source_sha256'), row.get('split')
        if match in HELD_OUT_MATCHES or video in HELD_OUT_SOURCES:
            raise ValueError('Reserved evaluation video cannot train or calibrate the detector.')
        # Bumpers look alike every season, so labelled matches from other years train the same detector.
        if not isinstance(match, str) or not re.match(r'\d{4}[a-z]', match) or not isinstance(video, str) or len(video) != 64:
            raise ValueError('Every frame needs its match and source-video fingerprint.')
        if split not in totals or row.get('reviewed') is not True or row.get('all_visible_bumpers_labeled') is not True:
            raise ValueError('Review all visible bumpers, including empty frames, before detector training.')
        for key in ('match:'+match, 'video:'+video):
            if key in groups and groups[key] != split:
                raise ValueError('A match or source video cannot appear in both training and validation.')
            groups[key] = split
        image = (root/row['image']).resolve()
        if not image.is_relative_to(root) or image.suffix.lower() not in ('.png', '.jpg', '.jpeg') or not image.is_file():
            raise ValueError('Frame images must be local files inside the dataset folder.')
        digest = hashlib.sha256(image.read_bytes()).hexdigest()
        if digest != row.get('image_sha256') or digest in images:
            raise ValueError('A frame fingerprint changed or the same image appears twice.')
        images[digest] = split
        labels = []
        for box in row.get('boxes', []):
            cls = box.get('class_id'); xywh = box.get('xywh')
            if type(cls) is not int or cls not in (0, 1) or not isinstance(xywh, list) or len(xywh) != 4 or not all(type(v) in (int, float) and 0 <= v <= 1 for v in xywh):
                raise ValueError('Bumper boxes need class 0/1 and normalized center-x, center-y, width, height.')
            x, y, w, h = xywh
            if w <= 0 or h <= 0 or x-w/2 < 0 or y-h/2 < 0 or x+w/2 > 1 or y+h/2 > 1:
                raise ValueError('A bumper box lies outside its frame.')
            labels.append(str(cls)+' '+' '.join(str(v) for v in xywh))
        accepted.append((image, digest, split, labels)); totals[split] += 1
    if not all(totals.values()):
        raise ValueError('Provide reviewed frames from separate training and validation matches.')
    if output.exists() and any(output.iterdir()):
        raise ValueError('Use a new output folder; existing training files are never overwritten.')
    for image, digest, split, labels in accepted:
        dest = output/'images'/split/(digest+image.suffix.lower()); dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(image, dest)
        label = output/'labels'/split/(digest+'.txt'); label.parent.mkdir(parents=True, exist_ok=True)
        label.write_text('\n'.join(labels)+'\n' if labels else '')
    config = output/'dataset.yaml'
    config.write_text(json.dumps({'path': str(output.resolve()), 'train': 'images/train', 'val': 'images/val', 'names': ['red', 'blue']}))
    (output/'provenance.json').write_text(json.dumps({'manifest_sha256': hashlib.sha256(manifest.read_bytes()).hexdigest(), 'frames': totals, 'groups': groups}, indent=2))
    return config


def train(manifest, output, epochs=30, device='cpu'):
    from ultralytics import YOLO
    if type(epochs) is not int or not 1 <= epochs <= 300 or device not in ('cpu', 'mps', '0'):
        raise ValueError('Choose CPU, Mac GPU or NVIDIA GPU and 1–300 training epochs.')
    meta = bundle(); output = Path(output)
    config = prepare(manifest, output/'dataset')
    model = YOLO(str(bundle_path()/meta['bumper']['file']))
    result = model.train(data=str(config), epochs=epochs, imgsz=960, batch=2,
                         workers=0, cache=False, device=device, project=str(output/'fit'),
                         name='bumper', seed=2026, exist_ok=False, plots=False, amp=False)
    best = Path(result.save_dir)/'weights/best.pt'
    if not best.is_file():
        raise ValueError('Detector training did not produce its best checkpoint.')
    target = output/'candidate'; target.mkdir(exist_ok=False)
    shutil.copy2(best, target/'bumper.pt')
    shutil.copy2(bundle_path()/meta['context']['file'], target/meta['context']['file'])
    meta['bumper'] = {'file': 'bumper.pt', 'sha256': hashlib.sha256(best.read_bytes()).hexdigest()}
    meta['source'] = {'kind': 'reviewed-bumper-box-training', 'parent_source': meta['source'],
                      'dataset_sha256': hashlib.sha256(Path(manifest).read_bytes()).hexdigest(),
                      'release_status': 'candidate_not_automatically_activated'}
    (target/'manifest.json').write_text(json.dumps(meta, indent=2)+'\n')
    return target


def main():
    p = argparse.ArgumentParser(description='Train Neptune bumper detection from reviewed robot-box labels.')
    p.add_argument('manifest', type=Path); p.add_argument('output', type=Path)
    p.add_argument('--prepare-only', action='store_true'); p.add_argument('--epochs', type=int, default=30)
    p.add_argument('--device', choices=('cpu', 'mps', '0'), default='cpu'); args = p.parse_args()
    print(prepare(args.manifest, args.output) if args.prepare_only else train(args.manifest, args.output, args.epochs, args.device))


if __name__ == '__main__':
    main()
