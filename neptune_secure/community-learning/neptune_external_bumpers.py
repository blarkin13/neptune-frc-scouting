"""Explicit workshop-image supplements; never masquerade as reviewed match frames."""
import base64
import hashlib
import io
import json
import math
from pathlib import Path


def append_workshop(datasets, dataset):
    """Validate the entire authenticated payload before adding train-only JPEG/YOLO pairs.

    The normal reviewed-match manifest and validation split are untouched. A source group
    is always train-only, regardless of the source export's original split assignments.
    """
    from PIL import Image
    root = Path(dataset).resolve()
    if not isinstance(datasets, list) or len(datasets) > 8:
        raise ValueError('Invalid external bumper dataset list')
    existing = {hashlib.sha256(p.read_bytes()).hexdigest() for p in (root/'images').rglob('*') if p.is_file()}
    pending = []; reports = []; ids = set()
    for data in datasets:
        if not isinstance(data, dict) or data.get('schema_version') != 1 or data.get('source_kind') not in ('workshop_photographs','reviewed_robot_photographs') or data.get('names') != ['red', 'blue'] or data.get('split') != 'train':
            raise ValueError('External bumper images require explicit train-only workshop provenance')
        identifier = data.get('dataset_id')
        if not isinstance(identifier, str) or not identifier or len(identifier)>100 or identifier in ids:
            raise ValueError('Invalid/duplicate external dataset ID')
        ids.add(identifier)
        if data.get('reviewed') is not True or not isinstance(data.get('source_group'), str) or not data['source_group']:
            raise ValueError('External bumper review/source group required')
        frames = data.get('frames')
        if not isinstance(frames,list) or not 1 <= len(frames) <= 200:
            raise ValueError('Invalid external frame count')
        counts = [0,0]
        for frame in frames:
            raw = base64.b64decode(frame['image_b64'], validate=True)
            if len(raw)>8*1024*1024 or not raw.startswith(b'\xff\xd8'):
                raise ValueError('External image must be a bounded JPEG')
            digest=hashlib.sha256(raw).hexdigest()
            if digest != frame.get('image_sha256') or digest in existing:
                raise ValueError('External image checksum mismatch or duplicate training/evaluation image')
            existing.add(digest)
            with Image.open(io.BytesIO(raw)) as image:
                if image.format!='JPEG' or not 16<=image.width<=8192 or not 16<=image.height<=8192:
                    raise ValueError('Invalid external image dimensions')
                image.verify()
            boxes = frame.get('boxes'); labels=[]
            if not isinstance(boxes,list) or not 0<=len(boxes)<=100 or (not boxes and (data['source_kind']!='reviewed_robot_photographs' or frame.get('all_visible_bumpers_checked') is not True)):
                raise ValueError('Reviewed workshop images require visible bumper labels')
            for box in boxes:
                c=box.get('class_id'); xy=box.get('xywh')
                if type(c) is not int or c not in (0,1) or not isinstance(xy,list) or len(xy)!=4 or not all(type(v) in (int,float) and math.isfinite(v) for v in xy):
                    raise ValueError('Invalid external bumper class/coordinates')
                x,y,w,h=xy
                if w<=0 or h<=0 or x-w/2< -1e-8 or y-h/2< -1e-8 or x+w/2>1+1e-8 or y+h/2>1+1e-8:
                    raise ValueError('External bumper box outside image')
                labels.append(str(c)+' '+' '.join(str(v) for v in xy));counts[c]+=1
            pending.append((digest, raw, '\n'.join(labels)+'\n'))
        reports.append({'dataset_id':identifier,'source_kind':data['source_kind'],'source_group':data['source_group'],
                        'source_date':data.get('source_date'),'split':'train','frames':len(frames),'boxes':sum(counts),
                        'boxes_by_class':dict(zip(['red','blue'],counts)),
                        'synthetic_frames':sum(bool(f.get('synthetic')) for f in frames),'empty_reviewed_images':sum(not f['boxes'] for f in frames),'manifest_sha256':hashlib.sha256(json.dumps(data,sort_keys=True,separators=(',',':')).encode()).hexdigest()})
    for digest,raw,labels in pending:
        image=root/'images/train'/('external_'+digest+'.jpg'); label=root/'labels/train'/('external_'+digest+'.txt')
        image.parent.mkdir(parents=True,exist_ok=True);label.parent.mkdir(parents=True,exist_ok=True)
        if image.exists() or label.exists():raise ValueError('External output already exists')
        image.write_bytes(raw);label.write_text(labels)
    if reports:(root/'external-training-provenance.json').write_text(json.dumps(reports,indent=2))
    return {'datasets':reports,'frames':len(pending),'boxes':sum(r['boxes'] for r in reports)}
