"""Automatic tracked-robot clips and a frozen, pretrained R3D-18 video encoder.

The 400 human-action outputs are never used as FRC tags. Neptune learns separate
season-specific heads on the 512-dimensional clip embedding. No manual clips.
"""
import functools
import hashlib
import json
import math
import os
from pathlib import Path
import subprocess
import tempfile
import urllib.request

VERSION = 'robot-r3d18-clip-v1'
DIMENSION = 512
FRAMES = 16
FPS = 4
SECONDS = FRAMES/FPS
WEIGHT_FILE = 'r3d_18-b3b3357e.pth'
WEIGHT_URL = 'https://download.pytorch.org/models/'+WEIGHT_FILE
WEIGHT_SHA256 = 'b3b3357ead25631ec9c57362ff2128a92d0427e01e2cd184951a44380c3f2e9d'


class ClipUnavailable(ValueError):
    def __init__(self, reason):
        self.reason = reason
        super().__init__(reason.replace('_', ' '))


def encoder_document():
    return {'name': 'torchvision-r3d18-kinetics400-v1', 'feature_version': VERSION,
            'dimension': DIMENSION, 'weights_sha256': WEIGHT_SHA256,
            'frames': FRAMES, 'fps': FPS, 'seconds': SECONDS,
            'backbone_frozen': True, 'head_training': 'per-game-Neptune-action-labels'}


def weights_path():
    root = Path(os.environ.get('NEPTUNE_ACTION_MODEL_DIR', Path.home()/'.neptune-trainer/action-encoder'))
    return root/WEIGHT_FILE


def verify_weights():
    p = weights_path()
    if not p.is_file() or hashlib.sha256(p.read_bytes()).hexdigest() != WEIGHT_SHA256:
        raise ValueError('The video action model is missing or changed. Run the updated helper setup to download its verified weights.')
    return p


def prepare_weights(progress=print):
    try:
        return verify_weights()
    except ValueError:
        pass
    target = weights_path(); target.parent.mkdir(parents=True, exist_ok=True)
    progress('Downloading the video action encoder (128 MB, once). Full match videos and saved tags are kept.')
    # Only the fixed official weights URL is used. No model URLs from imports/jobs.
    temp = None
    try:
        with tempfile.NamedTemporaryFile(dir=target.parent, prefix='r3d-download-', delete=False) as out:
            temp = Path(out.name)
            with urllib.request.urlopen(WEIGHT_URL, timeout=60) as response:
                if response.geturl().split('/')[2] != 'download.pytorch.org':
                    raise ValueError('Unexpected action-model download destination.')
                total = 0
                while True:
                    block = response.read(1024**2)
                    if not block:
                        break
                    total += len(block)
                    if total > 150*1024**2:
                        raise ValueError('Action-model download exceeded its expected size.')
                    out.write(block)
        if hashlib.sha256(temp.read_bytes()).hexdigest() != WEIGHT_SHA256:
            raise ValueError('Action-model fingerprint check failed. Retry setup; existing labels are untouched.')
        temp.replace(target)
        return target
    finally:
        if temp is not None:
            temp.unlink(missing_ok=True)


def _current_revision():
    """The tracker's own revision always gets segment-bounded clips (it was missed after v6 once)."""
    from neptune_yolo import CONTINUITY_REVISION
    return CONTINUITY_REVISION


def plan(facts, sight):
    from neptune_clock import phase_at
    phase_name, phase = phase_at(facts, sight['t'])
    if not phase or phase['end']-phase['start'] < SECONDS:
        raise ClipUnavailable('temporal_phase_window_unavailable')
    start = max(0., phase['start'], min(sight['t']-SECONDS/2, phase['end']-SECONDS))
    end = start+SECONDS
    if end > phase['end']+.001:
        raise ClipUnavailable('temporal_phase_window_unavailable')
    if end > facts['source']['duration_s']+.001:
        raise ClipUnavailable('temporal_video_window_unavailable')
    # Require a trusted track at the label time. A nearby readable number from
    # another camera is not enough to identify sixteen subsequent frames.
    track = facts.get('tracking', {})
    candidates = [s for s in track.get('localizations', []) if s['team'] == sight['team']
                  and s.get('identity_method') in ('yolo_botsort_ocr','human_reviewed_track','roster_inferred')
                  and (not sight.get('camera') or s.get('camera')==sight['camera'])
                  and (sight.get('identity_method')!='human_reviewed_track' or s.get('identity_method')=='human_reviewed_track')]
    anchor = min(candidates, key=lambda s: abs(s['t']-sight['t']), default=None)
    if anchor is None or abs(anchor['t']-sight['t']) > .25:
        raise ClipUnavailable('temporal_robot_track_unavailable')
    points = [s for s in candidates if s['track_id'] == anchor['track_id'] and s['scene'] == anchor['scene'] and s.get('camera')==anchor.get('camera')
              and (anchor.get('identity_segment') is None or s.get('identity_segment')==anchor['identity_segment']
                   # same track and team: an OCR-verified stretch may continue as a solver-inferred one
                   or 'roster_inferred' in (s.get('identity_method'),anchor.get('identity_method'))
                   # a robot confirmed after brief contact continues its segment from before the contact
                   or s.get('carried_from')==anchor.get('identity_segment') or anchor.get('carried_from')==s.get('identity_segment'))]
    cuts=track.get('camera_cuts',{}).get(anchor.get('camera'),track.get('scene_cuts', []))
    if anchor.get('identity_method')=='human_reviewed_track' or track.get('continuity_revision') in ('segment-verified-v3','bidirectional-roster-review-v4','reviewed-flow-continuity-v5','wide-tile-and-gap-continuity-v6','robot26-reacquisition-ocr3-v2',_current_revision()):
        # A clip can start at a track's visible boundary rather than insist on
        # two seconds of invisible pre-roll. It must still contain the anchor,
        # have sixteen detected positions and stay inside one phase/camera/segment.
        low=max(0.,phase['start'],min(p['t'] for p in points));high=min(phase['end']-SECONDS,facts['source']['duration_s']-SECONDS,max(p['t'] for p in points)-(FRAMES-1)/FPS)
        starts=[start,max(low,min(high,start)),low,high]
        valid=[]
        for trial in starts:
            if not low-.001<=trial<=high+.001 or not trial-.001<=sight['t']<=trial+SECONDS+.001:continue
            if any(trial<t<trial+SECONDS for t in cuts):continue
            if all(min(abs(p['t']-(trial+k/FPS)) for p in points)<=.2501 for k in range(FRAMES)):valid.append(trial)
        if not valid:raise ClipUnavailable('temporal_robot_identity_gap')
        start=min(valid,key=lambda t:abs(t-start));end=start+SECONDS
    elif any(start < t < end for t in cuts):
        raise ClipUnavailable('temporal_camera_cut')
    bounds=anchor.get('camera_bounds')
    band = (bounds[1],bounds[3]) if bounds else track.get('band')
    left,right=(bounds[0],bounds[2]) if bounds else (0.,1.)
    if not band or len(band) != 2:
        raise ClipUnavailable('temporal_camera_bounds_unavailable')
    frames = []
    for k in range(FRAMES):
        t = start+k/FPS
        pt = min(points, key=lambda p: abs(p['t']-t), default=None)
        if pt is None or abs(pt['t']-t) > .2501:
            raise ClipUnavailable('temporal_robot_identity_gap')
        x, y, w, h = pt['box']
        if not all(type(v) in (int, float) and math.isfinite(v) for v in (x,y,w,h)) or w <= 0 or h <= 0:
            raise ClipUnavailable('temporal_invalid_robot_box')
        # Context includes nearby interactions while staying inside this camera,
        # never feeding scoreboard numbers or another broadcast panel to the model.
        a, b = max(left, x-.5*w), max(band[0], y-.25*h)
        c, d = min(right, x+1.5*w), min(band[1], y+1.25*h)
        if c <= a or d <= b:
            raise ClipUnavailable('temporal_invalid_robot_box')
        frames.append({'t': round(t, 5), 'box': [a, b, c-a, d-b]})
    return {'version': VERSION, 'start': start, 'end': end, 'phase': phase_name,
            'track_id': anchor['track_id'], 'scene': anchor['scene'], 'team': sight['team'],
            'frames': frames, 'identity_supervision': anchor.get('identity_evidence') if anchor.get('identity_method')=='human_reviewed_track' else None, 'weights_sha256': WEIGHT_SHA256}


@functools.lru_cache(maxsize=2)
def encoder(device):
    import torch
    from torchvision.models.video import r3d_18, R3D_18_Weights
    path = verify_weights()
    model = r3d_18(weights=None)
    model.load_state_dict(torch.load(path, map_location='cpu', weights_only=True))
    model.fc = torch.nn.Identity()
    model.requires_grad_(False); model.eval(); model.to(device)
    if device == 'cpu':
        import os
        torch.set_num_threads(max(1, (os.cpu_count() or 2)-1))   # all but one core
    return model, R3D_18_Weights.KINETICS400_V1.transforms()


def features(video, sight, duration_ms, facts):
    """Stream four seconds, crop along the identified track, return one embedding."""
    import torch
    clip = plan(facts, sight)
    device = os.environ.get('NEPTUNE_TRAIN_DEVICE', 'cpu')
    if device == 'cuda':device = 'cuda:0'
    if device not in ('cpu','mps','cuda:0'):raise ValueError('Unsupported action-model device.')
    model, transform = encoder(device)
    crops = decoded_crops(video,clip)
    tensor = torch.from_numpy(crops).permute(0,3,1,2)
    with torch.inference_mode():
        vector = model(transform(tensor).unsqueeze(0).to(device)).squeeze(0).float().cpu().tolist()
    if len(vector) != DIMENSION or not all(math.isfinite(x) for x in vector):raise ValueError('Invalid temporal action embedding.')
    sight['temporal_clip'] = clip
    return vector


def decoded_crops(video,clip):
    """Shared frame selection/cropping for independent action encoders."""
    import numpy as np
    import cv2
    probe = subprocess.run(['ffprobe','-v','error','-select_streams','v:0','-show_entries','stream=width,height','-of','csv=p=0',str(video)], capture_output=True, text=True, check=True, timeout=60)
    sw, sh = [int(v) for v in probe.stdout.strip().split(',')[:2]]
    w = min(960, sw); h = max(2, round(sh*w/sw/2)*2)
    command = ['ffmpeg','-nostdin','-v','error','-threads','1','-ss',str(clip['start']),'-t',str(SECONDS),'-i',str(video),
               '-vf','fps=%d:round=up,scale=%d:%d'%(FPS,w,h),'-frames:v',str(FRAMES),'-pix_fmt','rgb24','-f','rawvideo','-']
    crops = []; proc = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    try:
        for frame in clip['frames']:
            size = w*h*3; buf = bytearray()
            while len(buf) < size:
                block = proc.stdout.read(size-len(buf))
                if not block:
                    break
                buf.extend(block)
            if len(buf) != size:
                raise ClipUnavailable('temporal_incomplete_decoded_clip')
            image = np.frombuffer(buf, dtype=np.uint8).reshape(h,w,3)
            x,y,bw,bh = frame['box']; a,b,c,d = int(x*w),int(y*h),min(w,math.ceil((x+bw)*w)),min(h,math.ceil((y+bh)*h))
            if c <= a or d <= b:
                raise ClipUnavailable('temporal_invalid_robot_box')
            crops.append(cv2.resize(image[b:d,a:c], (171,128)))
        if proc.wait(timeout=60):
            raise ClipUnavailable('temporal_video_decode_failed')
    finally:
        proc.stdout.close()
        if proc.poll() is None:
            proc.terminate()
            try:proc.wait(timeout=5)
            except subprocess.TimeoutExpired:proc.kill();proc.wait(timeout=5)
    return np.stack(crops)
