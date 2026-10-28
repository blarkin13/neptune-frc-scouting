"""Robot labels made on the website (Auto Scout -> Robot labels) become detector training frames here.

The site stores, per YouTube video, frames keyed by video time with every bumper boxed (normalized to the full
frame). This module downloads those labels with the helper's connection, finds or downloads each match video,
extracts the exact frames, and writes the reviewed bumper-box manifest neptune_detector_training.prepare reads.
"""
import hashlib
import json
import subprocess
from pathlib import Path

VERSION = 'site-robot-labels-v1'
HOME = Path.home()/'.neptune-trainer'
# Every job's match videos, kept 14 days (5 GB cap) so a video is downloaded from YouTube once, not per run.
VIDEOS = HOME/'cache'/'videos'
BLOCKED = 'YouTube is blocking downloads from this computer for now (bot check). Nothing was trained on missing videos. Try again in a few hours.'


def sync(config):
    """The organization's completed robot labels from Neptune: [{video_id, match_key, frames:{t_ms:{boxes}}}]."""
    import urllib.request
    from neptune_helper import public_https, NoRedirect
    url = public_https(config['site'].rstrip('/')+'/api/robot-labels.php')
    request = urllib.request.Request(url, data=b'{}', headers={'Content-Type': 'application/json', 'Authorization': 'Bearer '+config['token']})
    with urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect()).open(request, timeout=30) as response:
        raw = response.read(67108865)
    if len(raw) > 67108864:
        raise ValueError('Robot labels too large')
    docs = json.loads(raw).get('documents', [])
    for d in docs:
        # PHP sends {"0": …} as a list when the only frame is at 0 ms.
        if isinstance(d.get('frames'), list):
            d['frames'] = {str(i): f for i, f in enumerate(d['frames'])}
    return docs


def local_video(video_id):
    """A copy of this YouTube video already on this computer, if any."""
    for folder in (HOME/'identity-reviews', HOME/'cache'):
        for path in folder.glob('**/'+video_id+'.mp4') if folder.is_dir() else []:
            if path.is_file() and not path.is_symlink():
                return path
    return None


def ffmpeg():
    vendored = Path(__file__).with_name('vendor')/'bin'/'ffmpeg'
    return str(vendored) if vendored.is_file() else 'ffmpeg'


def box_xywh(b):
    """Clip a saved box to the frame and convert to YOLO centre/size, or None if nothing is left."""
    x0, y0 = max(0., b['x']), max(0., b['y']); x1, y1 = min(1., b['x']+b['w']), min(1., b['y']+b['h'])
    if x1 <= x0 or y1 <= y0:
        return None
    return [(x0+x1)/2, (y0+y1)/2, x1-x0, y1-y0]


def build(docs, out, holdout=(), download=None, progress=print, snapshot_download=None):
    """Write <out>/manifest.json + images for every completed frame. `holdout` match keys become validation.

    download(video_id, path) fetches a video that is not already on this computer; without it those matches
    are skipped and reported.
    """
    out = Path(out); (out/'images').mkdir(parents=True, exist_ok=True); (out/'videos').mkdir(exist_ok=True)
    rows, skipped = [], {}
    for doc in docs:
        vid, match = doc['video_id'], doc.get('match_key') or ''
        frames = {int(t): f for t, f in (doc.get('frames') or {}).items() if f.get('complete')}
        if not frames or not match[:4].isdigit():
            continue
        video = local_video(vid)
        if video is None and download:
            video = VIDEOS/(vid+'.mp4'); video.parent.mkdir(parents=True, exist_ok=True)
            if not video.is_file():
                progress('Downloading '+match)
                download(vid, video)
        if video is None or not video.is_file():
            skipped[match] = 'video_not_available'; continue
        source = hashlib.sha256(video.read_bytes()).hexdigest()
        for t, f in sorted(frames.items()):
            image = out/'images'/('%s_%08d.jpg' % (vid, t))
            saved_sha=f.get('frame_sha256')
            if saved_sha:
                if not isinstance(saved_sha,str) or len(saved_sha)!=64 or any(c not in '0123456789abcdef' for c in saved_sha):raise ValueError('Invalid saved training-frame checksum')
                if not image.is_file() or hashlib.sha256(image.read_bytes()).hexdigest()!=saved_sha:
                    if snapshot_download is None:raise ValueError('This frame requires the updated source-still training helper')
                    snapshot_download(vid,t,saved_sha,image)
                if hashlib.sha256(image.read_bytes()).hexdigest()!=saved_sha:raise ValueError('Training image does not match the image that was labelled')
            elif not image.is_file():
                subprocess.run([ffmpeg(), '-v', 'error', '-y', '-ss', '%.3f' % (t/1000), '-i', str(video), '-frames:v', '1', '-q:v', '2', str(image)], check=True)
            boxes = [{'class_id': 0 if b['alliance'] == 'red' else 1, 'xywh': xywh} for b in f['boxes'] if (xywh := box_xywh(b))]
            rows.append({'match_key': match, 'source_sha256': source, 'split': 'val' if match in holdout else 'train', 'reviewed': True,
                         'all_visible_bumpers_labeled': True, 'image': 'images/'+image.name,
                         'image_sha256': hashlib.sha256(image.read_bytes()).hexdigest(), 'boxes': boxes, 'video_time_ms': t, 'origin': VERSION})
        progress('%s: %d frames' % (match, len(frames)))
    manifest = {'schema_version': 1, 'names': ['red', 'blue'], 'season': 2026, 'frames': rows, 'skipped': skipped}
    (out/'manifest.json').write_text(json.dumps(manifest, indent=1))
    return {'frames': len(rows), 'train': sum(r['split'] == 'train' for r in rows), 'val': sum(r['split'] == 'val' for r in rows),
            'boxes': sum(len(r['boxes']) for r in rows), 'skipped': skipped, 'manifest': str(out/'manifest.json')}


def youtube_download(video_id, path):
    """Download a catalog match video the way the helper does for training."""
    from neptune_helper import download_video
    try:
        download_video({'source_url': 'https://www.youtube.com/watch?v='+video_id, 'source_sha256': None}, Path(path), [''])
    except Exception as error:
        if youtube_blocked(error):
            raise RuntimeError(BLOCKED) from error
        raise


def youtube_blocked(error):
    """YouTube's bot check: retrying now only makes it last longer."""
    text = str(error).lower()
    return 'confirm you' in text and 'not a bot' in text or 'sign in to confirm' in text


def main():
    import argparse
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--connection', required=True, help="the helper's trainer-connection.json")
    ap.add_argument('--out', required=True); ap.add_argument('--holdout', default='', help='comma-separated match keys for validation')
    a = ap.parse_args()
    docs = sync(json.loads(Path(a.connection).read_text()))
    print(json.dumps(build(docs, a.out, set(filter(None, a.holdout.split(','))), youtube_download), indent=1))


if __name__ == '__main__':
    main()
