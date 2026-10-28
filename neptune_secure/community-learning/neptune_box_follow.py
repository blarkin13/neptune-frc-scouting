"""Short, image-supported detector-gap following. Never creates OCR identity anchors.

Forward/backward optical-flow agreement, feature support and shot boundaries limit
propagation. The detector resumes authority on the next reliable detection.
"""
import math
VERSION='lk-forward-backward-v1'

class BoxFollower:
    def __init__(self,max_gap=.75):
        self.max_gap=max_gap;self.previous=None;self.tracks={};self.serial=-1;self.scene=None
        self.diagnostics={'detected_samples':0,'followed_samples':0,'flow_rejected':0,'cut_resets':0,'untracked_detections_retained':0}
    def update(self,gray,t,scene,detections):
        import cv2
        import numpy as np
        from neptune_yolo import overlap
        if scene!=self.scene or self.previous is not None and self.previous.shape!=gray.shape:
            self.previous=None;self.tracks={};self.scene=scene;self.diagnostics['cut_resets']+=1
        predictions={}
        h,w=gray.shape
        if self.previous is not None:
            for key,old in self.tracks.items():
                if t-old['last_detection']>self.max_gap:continue
                a,b,c,d=[int(v) for v in old['box']];mask=np.zeros_like(gray);mask[max(0,b):min(h,d),max(0,a):min(w,c)]=255
                pts=cv2.goodFeaturesToTrack(self.previous,maxCorners=60,qualityLevel=.02,minDistance=3,mask=mask)
                if pts is None or len(pts)<8:self.diagnostics['flow_rejected']+=1;continue
                nxt,ok,_=cv2.calcOpticalFlowPyrLK(self.previous,gray,pts,None,winSize=(21,21),maxLevel=3)
                if nxt is None:continue
                back,ok2,_=cv2.calcOpticalFlowPyrLK(gray,self.previous,nxt,None,winSize=(21,21),maxLevel=3)
                if back is None:continue
                err=np.linalg.norm(back-pts,axis=2).reshape(-1);keep=(ok.reshape(-1)>0)&(ok2.reshape(-1)>0)&(err<1.5)
                if keep.sum()<8 or keep.mean()<.6:self.diagnostics['flow_rejected']+=1;continue
                shifts=(nxt-pts).reshape(-1,2)[keep];delta=np.median(shifts,axis=0);spread=float(np.median(np.linalg.norm(shifts-delta,axis=1)))
                if spread>3 or float(np.linalg.norm(delta))>max(12.,(c-a)*.8):self.diagnostics['flow_rejected']+=1;continue
                dx,dy=map(float,delta);box=[a+dx,b+dy,c+dx,d+dy]
                if box[0]<0 or box[1]<0 or box[2]>w or box[3]>h:continue
                predictions[key]=dict(old,box=box,t=t,box_source='optical_flow',flow_support=int(keep.sum()),flow_error=round(float(np.median(err[keep])),3))
        output=[];claimed=set()
        for raw in detections:
            det=dict(raw);self.diagnostics['detected_samples']+=1
            options=sorted([(overlap(det['box'],q['box']),key) for key,q in predictions.items() if q['alliance']==det['alliance'] and key not in claimed],reverse=True)
            best=options[0] if options else (0,None)
            # Reconnect only a unique spatial match; two plausible robots remain separate.
            unique=best[0]>.6 and (len(options)<2 or best[0]-options[1][0]>.2)
            if unique:
                key=best[1];claimed.add(key)
            elif det.get('id') is not None and det['id'] in self.tracks and det['id'] not in claimed and self.tracks[det['id']]['alliance']==det['alliance']:
                # Recycled IDs must also agree with current visual position.
                old=self.tracks[det['id']]
                key=det['id'] if overlap(det['box'],old['box'])>.4 else self.serial
                if key==self.serial:self.serial-=1
            elif det.get('id') is not None and det['id'] not in self.tracks and det['id'] not in claimed:key=det['id']
            else:key=self.serial;self.serial-=1;self.diagnostics['untracked_detections_retained']+=1
            claimed.add(key);det.update(id=key,t=t,last_detection=t,box_source=det.get('box_source','detector'));output.append(det)
        for key,q in predictions.items():
            if key in claimed:continue
            # No ghost boxes on a neighboring robot, person or ambiguous contact.
            if any(overlap(q['box'],d['box'])>.2 for d in output):continue
            q.update(id=key,confidence=min(.49,q.get('confidence',.4)),occluded=False);output.append(q);self.diagnostics['followed_samples']+=1
        self.previous=gray.copy();self.tracks={d['id']:d for d in output};return output

def propose(video,review,t,box,alliance,camera):
    """User seeds a missing box; follow two seconds both ways for review, not truth."""
    import numpy as np
    from neptune_tracking import decode
    from neptune_identity import digest
    if type(t) not in (int,float) or not math.isfinite(t) or t<0:raise ValueError('Invalid video time')
    if alliance not in review['roster']:raise ValueError('Choose red or blue alliance')
    if not isinstance(box,list) or len(box)!=4 or not all(type(v) in (int,float) and math.isfinite(v) for v in box):raise ValueError('Draw a valid box')
    x,y,bw,bh=box
    nearby=[p for p in review['points'] if p['camera']==camera and abs(p['t']-t)<=.4]
    if not nearby:raise ValueError('Choose a camera with visible tracking at this time')
    anchor=min(nearby,key=lambda p:abs(p['t']-t));bounds=anchor.get('camera_bounds')
    if not bounds or len(bounds)!=4:raise ValueError('Camera bounds unavailable')
    left,top,right,bottom=bounds
    if bw<.01 or bh<.01 or x<left or y<top or x+bw>right or y+bh>bottom:raise ValueError('Draw the robot inside the selected camera view')
    t=anchor['t'];same_shot=[p['t'] for p in review['points'] if p['camera']==camera and p['scene']==anchor['scene']]
    start=max(0,t-2,min(same_shot));end=min(t+2,max(same_shot))
    frames=[]
    for ts,w,h,Y,U,V in decode(video,top,bottom,start,end,6.,960,left=left,right=right):
        frames.append((ts,np.frombuffer(Y,dtype=np.uint8).reshape(h,w).copy()))
    if not frames:raise ValueError('Could not read this video interval')
    index=min(range(len(frames)),key=lambda i:abs(frames[i][0]-t));h,w=frames[index][1].shape
    pixel=[(x-left)/(right-left)*w,(y-top)/(bottom-top)*h,(x+bw-left)/(right-left)*w,(y+bh-top)/(bottom-top)*h]
    token='manual-'+digest([review['source_sha256'],camera,t,box])[:16];output={}
    for direction in (1,-1):
        follower=BoxFollower(max_gap=2.01);previous=None
        for i in range(index,len(frames) if direction==1 else -1,direction):
            ts,gray=frames[i];clock=abs(ts-frames[index][0])
            # Cheap broadcast-cut guard as well as the per-feature flow checks.
            small=gray[::max(1,h//16),::max(1,w//32)].astype('float32')
            if previous is not None and float(np.mean(np.abs(previous-small)))>38:break
            previous=small
            rows=follower.update(gray,clock,0,[{'id':1,'box':pixel,'alliance':alliance,'confidence':1.}] if i==index else [])
            if not rows:break
            a,b,c,d=rows[0]['box']
            output[round(ts,4)]={'t':round(ts,4),'box':[left+a/w*(right-left),top+b/h*(bottom-top),(c-a)/w*(right-left),(d-b)/h*(bottom-top)],
                'track_id':token,'alliance':alliance,'camera':camera,'scene':anchor['scene'],'camera_bounds':bounds,'verified_team':None,
                'read_teams':[],'box_source':'human_seed_flow_proposal','manual_proposal':True,'flow_support':rows[0].get('flow_support')}
    return sorted(output.values(),key=lambda p:p['t'])
