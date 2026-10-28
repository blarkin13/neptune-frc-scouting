"""Source-timed field annotations; keep complete, unambiguous camera geometry together."""
import hashlib
import json
from pathlib import Path


def document_at(doc,t):
    import neptune_field_detector as fd
    ms=round(t*1000);regions=doc.get('regions',[])
    active=lambda r:r.get('start_ms',0)<=ms<=r.get('end_ms',float('inf'))
    chosen={}
    views={r.get('camera_view') for r in regions if r.get('kind')=='scoring_target'}
    for view in views:
        groups={r.get('shot_id','legacy') for r in regions if r.get('kind')=='scoring_target' and r.get('camera_view')==view}
        valid=[]
        for shot in groups:
            targets=[r for r in regions if r.get('kind')=='scoring_target' and r.get('camera_view')==view and r.get('shot_id','legacy')==shot]
            if targets and all(active(r) for r in targets):valid.append(shot)
        if len(valid)!=1:return None
        chosen[view]=valid[0]
    kept=[r for r in regions if active(r) and (r.get('camera_view') not in chosen or r.get('shot_id','legacy')==chosen[r['camera_view']])]
    result=dict(doc,regions=kept)
    if not fd.boundary_corners(result) or not any(r['kind']=='scoring_target' and r['camera_view']=='wide' for r in kept):return None
    calibrations=[r for r in kept if r['kind']=='field_calibration' and r['camera_view']=='wide']
    if len(calibrations)>1:return None
    return result


def samples(doc,frames,reference_frame):
    """Add marked instants only when they show the same field view as sampled frames."""
    import cv2
    import numpy as np
    rows={round(t*1000):(t,image) for t,image in frames}
    # The saved timestamp may land on a replay or title card. The regularly sampled
    # frames have already passed the broadcast-view filter; a forced reference must
    # pass that same visual check before its field boxes become training labels.
    thumbs=np.stack([cv2.resize(cv2.cvtColor(image,cv2.COLOR_BGR2GRAY),(64,36)).astype('float32') for _,image in rows.values()]) if rows else None
    median=np.median(thumbs,axis=0) if thumbs is not None else None
    distances=np.abs(thumbs-median).mean(axis=(1,2)) if median is not None else None
    limit=float(np.percentile(distances,50)*2.5+4) if distances is not None else None
    refs={r.get('reference_ms') for r in doc.get('regions',[]) if r.get('kind') in ('scoring_target','field_calibration','field_boundary')}
    for ms in sorted(x for x in refs if type(x) in (int,float) and x>=0):
        t=ms/1000
        if round(ms) not in rows and document_at(doc,t) and median is not None:
            image=reference_frame(t)
            if image is not None:
                image=cv2.resize(image,(1280,720))
                thumb=cv2.resize(cv2.cvtColor(image,cv2.COLOR_BGR2GRAY),(64,36)).astype('float32')
                if float(np.abs(thumb-median).mean())<=limit:
                    rows[round(ms)]=(t,image)
    out=[]
    for _,(t,image) in sorted(rows.items()):
        truth=document_at(doc,t)
        if truth:out.append((t,image,truth))
    if not out:raise ValueError('No frames fall within a complete saved target/corner interval; labels have been preserved.')
    return out


def fingerprint(rows):
    import neptune_field_detector as fd
    return hashlib.sha256(json.dumps([{'t':t,'labels':fd.yolo_labels(doc),'decoded_image_sha256':hashlib.sha256(image.tobytes()).hexdigest()} for t,image,doc in rows],sort_keys=True,separators=(',',':')).encode()).hexdigest()
