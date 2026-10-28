"""Private local review of bumper detections in user-selected close-up photographs."""
import base64
import hashlib
import io
import json
import math
from pathlib import Path
import secrets
import threading
import time
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from urllib.parse import urlsplit

EXTENSIONS={'.jpg','.jpeg','.png','.webp','.bmp'}

def class_ids(names):
    if isinstance(names,list):names=dict(enumerate(names))
    if not isinstance(names,dict):return None
    result={}
    for k,v in names.items():
        name=str(v).lower().replace('_',' ').replace('-',' ').strip()
        if name in ('red','red bumper','robot red'):result[int(k)]=0
        elif name in ('blue','blue bumper','robot blue'):result[int(k)]=1
        else:return None
    return result if set(result.values())=={0,1} else None


def validate_boxes(boxes):
    if not isinstance(boxes,list) or len(boxes)>100:raise ValueError('At most 100 bumper boxes per image')
    clean=[]
    for b in boxes:
        c=b.get('class_id');q=b.get('xywh')
        if type(c) is not int or c not in (0,1) or not isinstance(q,list) or len(q)!=4 or not all(type(x) in (int,float) and math.isfinite(x) for x in q):raise ValueError('Invalid bumper class or coordinates')
        x,y,w,h=q
        if w<=0 or h<=0 or x-w/2< -1e-8 or y-h/2< -1e-8 or x+w/2>1+1e-8 or y+h/2>1+1e-8:raise ValueError('Box must stay inside the image')
        clean.append({'class_id':c,'xywh':[float(x) for x in q]})
    return clean


def imported_labels(folder,image_folder=None):
    """Return labels with explicit class names; never guess the order of unnamed YOLO classes."""
    folder=Path(folder);image_folder=Path(image_folder or folder);result={};warnings=[]
    for ann in sorted(folder.glob('_annotations*.txt')):
        classes=ann.with_name(ann.name.replace('annotations','classes'))
        mapping=class_ids(classes.read_text().splitlines()) if classes.is_file() else None
        if not mapping:warnings.append(ann.name+': unknown class mapping; model suggestions will be used');continue
        for line in ann.read_text().splitlines():
            p=line.split()
            if not p:continue
            try:
                boxes=[]
                for item in p[1:]:
                    x,y,x1,y1,c=map(float,item.split(','));boxes.append((mapping[int(c)],[x,y,x1,y1]))
                result[p[0]]={'pixel_boxes':boxes,'origin':ann.name}
            except (ValueError,KeyError):warnings.append('Invalid annotation for '+p[0])
    mapping=None
    for p in [folder/'data.yaml',folder/'dataset.yaml']:
        if p.is_file():
            import yaml
            mapping=class_ids((yaml.safe_load(p.read_text()) or {}).get('names'));break
    if mapping:
        for image in sorted(image_folder.iterdir()):
            if image.suffix.lower() not in EXTENSIONS:continue
            labels=[folder/'labels'/image.with_suffix('.txt').name,folder/image.with_suffix('.txt').name,folder/'labels/train'/image.with_suffix('.txt').name]
            label=next((p for p in labels if p.is_file()),None)
            if not label:continue
            try:
                boxes=[]
                for line in label.read_text().splitlines():
                    if not line.strip():continue
                    c,x,y,w,h=map(float,line.split());boxes.append({'class_id':mapping[int(c)],'xywh':[x,y,w,h]})
                result[image.name]={'boxes':validate_boxes(boxes),'origin':str(label.relative_to(folder))}
            except (ValueError,KeyError):warnings.append('Invalid YOLO labels for '+image.name)
    elif (folder/'labels').is_dir() and any((folder/'labels').glob('*.txt')):
        warnings.append('YOLO labels need data.yaml or dataset.yaml declaring red/blue class names')
    return result,warnings


def closeup_color_proposals(path):
    """Conservative color components are drafts, useful when a wide-shot model misses a close-up."""
    import cv2
    import numpy as np
    image=cv2.imread(str(path));h,w=image.shape[:2];hsv=cv2.cvtColor(image,cv2.COLOR_BGR2HSV);rows=[]
    masks=[cv2.inRange(hsv,np.array([0,95,35]),np.array([12,255,255]))|cv2.inRange(hsv,np.array([170,95,35]),np.array([179,255,255])),cv2.inRange(hsv,np.array([95,85,30]),np.array([135,255,255]))]
    k=max(3,min(9,round(min(w,h)*.008)));kernel=np.ones((k,k),np.uint8)
    for c,mask in enumerate(masks):
        mask=cv2.morphologyEx(mask,cv2.MORPH_CLOSE,kernel)
        n,labels,stats,centers=cv2.connectedComponentsWithStats(mask)
        for x,y,bw,bh,area in stats[1:]:
            if bw/w<.045 or bh/h<.02 or not 0.65<=bw/bh<=10 or not .0015<=area/(w*h)<=.45 or area/(bw*bh)<.2:continue
            rows.append((int(area),{'class_id':c,'xywh':[(float(x)+float(bw)/2)/w,(float(y)+float(bh)/2)/h,float(bw)/w,float(bh)/h]}))
    threshold=max(.003*w*h,max((area for area,_ in rows),default=0)*.25)
    return [b for area,b in sorted(rows,key=lambda r:-r[0]) if area>=threshold][:8]


class FolderReview:
    def __init__(self,folder,output,detector=None):
        from PIL import Image,ImageOps
        self.folder=Path(folder).resolve();self.output=Path(output).resolve();self.detector=detector;self.model=None;self.current_index=0;self.lock=threading.RLock();self.predict_lock=threading.Lock();self.rows=[]
        if not self.folder.is_dir():raise ValueError('Choose a folder containing robot images')
        if self.output==self.folder or self.output.is_relative_to(self.folder):raise ValueError('Review output must be outside the source folder')
        image_folder=self.folder
        for candidate in [self.folder,self.folder/'images/train',self.folder/'images']:
            if candidate.is_dir() and any(p.suffix.lower() in EXTENSIONS for p in candidate.iterdir() if p.is_file()):image_folder=candidate;break
        images=[p for p in sorted(image_folder.iterdir()) if p.suffix.lower() in EXTENSIONS and p.is_file() and not p.is_symlink()]
        if not images:raise ValueError('No JPG, PNG, WebP or BMP images found in this folder')
        if len(images)>5000:raise ValueError('Choose at most 5,000 images at a time')
        state_path=self.output/'review-state.json'
        if state_path.is_file():
            saved=json.loads(state_path.read_text())
            if Path(saved['source_folder']).resolve()!=self.folder:raise ValueError('Saved review belongs to another image folder')
            self.rows=saved['rows'];self.warnings=saved.get('warnings',[]);self.current_index=min(max(0,int(saved.get('current_index',0))),len(self.rows)-1)
            for i,r in enumerate(self.rows):
                if r['id']!=i or Path(r['name']).name!=r['name'] or Path(r['display']).name!=r['display']:raise ValueError('Invalid saved review paths')
                if not (self.output/'display'/r['display']).is_file():raise ValueError('Saved review image is missing')
                r['boxes']=validate_boxes(r['boxes'])
            return
        self.output.mkdir(parents=True,exist_ok=False);(self.output/'display').mkdir();labels,self.warnings=imported_labels(self.folder,image_folder);seen=set()
        for p in images:
            try:
                if p.stat().st_size>40*1024*1024:raise ValueError('image exceeds 40 MB')
                raw=p.read_bytes();sha=hashlib.sha256(raw).hexdigest()
                if sha in seen:
                    self.warnings.append(p.name+': duplicate image skipped');continue
                seen.add(sha)
                with Image.open(io.BytesIO(raw)) as original:
                    if original.width*original.height>50_000_000:raise ValueError('image exceeds 50 megapixels')
                    orientation=original.getexif().get(274,1);w,h=original.size;im=ImageOps.exif_transpose(original).convert('RGB')
                name=f'{len(self.rows):05d}.jpg';im.save(self.output/'display'/name,quality=95);boxes=[];item=labels.get(p.name);origin='not yet detected'
                if item and orientation==1:
                    if 'boxes' in item:boxes=item['boxes']
                    else:
                        for c,(x,y,x1,y1) in item['pixel_boxes']:boxes.append({'class_id':c,'xywh':[(x+x1)/2/w,(y+y1)/2/h,(x1-x)/w,(y1-y)/h]})
                    boxes=validate_boxes(boxes);origin='imported: '+item['origin']
                elif item:self.warnings.append(p.name+': EXIF rotation normalized; existing boxes require new review')
                self.rows.append({'id':len(self.rows),'name':p.name,'source_sha256':sha,'display':name,'width':im.width,'height':im.height,'boxes':boxes,'origin':origin,'reviewed':False,'has_labels':bool(item and orientation==1)})
            except Exception as error:self.warnings.append(p.name+': '+str(error)[:120])
        if not self.rows:raise ValueError('No readable images found')
        self.persist()
    def persist(self):
        p=self.output/'review-state.json';tmp=p.with_suffix('.pending');tmp.write_text(json.dumps({'source_folder':str(self.folder),'current_index':self.current_index,'rows':self.rows,'warnings':self.warnings},indent=2));tmp.replace(p)
    def state(self):
        with self.lock:return {'output':str(self.output),'current_index':self.current_index,'rows':json.loads(json.dumps(self.rows)),'warnings':self.warnings,'reviewed':sum(r['reviewed'] for r in self.rows)}
    def predict(self,i):
        with self.predict_lock:
            with self.lock:
                r=self.rows[i]
                if r['reviewed'] or r['has_labels']:return r
            if self.detector:boxes=self.detector(self.output/'display'/r['display'])
            else:
                if self.model is None:
                    from neptune_robot_jobs import current_wide_weights
                    from ultralytics import YOLO
                    weights=current_wide_weights()
                    if not weights:return self.color_predict(i)
                    self.model=YOLO(str(weights));self.model_sha=hashlib.sha256(Path(weights).read_bytes()).hexdigest()
                mapping=class_ids(self.model.names)
                if not mapping:raise ValueError('Installed model does not have explicit red/blue bumper classes')
                result=self.model.predict(str(self.output/'display'/r['display']),imgsz=1280,conf=.25,device='cpu',verbose=False)[0];boxes=[]
                for b in result.boxes:
                    x,y,x1,y1=map(float,b.xyxyn[0].tolist());x=max(0,min(1,x));y=max(0,min(1,y));x1=max(x,min(1,x1));y1=max(y,min(1,y1))
                    if x1>x and y1>y:boxes.append({'class_id':mapping[int(b.cls[0])],'xywh':[(x+x1)/2,(y+y1)/2,x1-x,y1-y]})
            proposal_origin='model suggestion'
            if not boxes and not self.detector:
                boxes=closeup_color_proposals(self.output/'display'/r['display']);proposal_origin='color-based close-up suggestion — check carefully'
            with self.lock:
                # A user may edit while inference is running; never overwrite that work.
                if not r['has_labels'] and not r['reviewed']:
                    r['boxes']=validate_boxes(boxes);r['origin']=proposal_origin;r['model_sha256']=getattr(self,'model_sha',None);r['has_labels']=True;self.persist()
                return json.loads(json.dumps(r))
    def color_predict(self,i):
        with self.lock:
            r=self.rows[i]
            if not r['has_labels'] and not r['reviewed']:
                r['boxes']=validate_boxes(closeup_color_proposals(self.output/'display'/r['display']));r['origin']='color-based close-up suggestion — check carefully';r['has_labels']=True;self.persist()
            return json.loads(json.dumps(r))
    def save(self,i,boxes,reviewed):
        if type(reviewed) is not bool:raise ValueError('Reviewed status must be explicit')
        boxes=validate_boxes(boxes)
        with self.lock:
            r=self.rows[i];r['boxes']=boxes;r['has_labels']=True;r['reviewed']=reviewed;r['origin']='user edited' if not reviewed else 'user checked all visible bumpers'
            image=self.output/'images/train'/(r['source_sha256']+'.jpg');label=self.output/'labels/train'/(r['source_sha256']+'.txt')
            if reviewed:
                import shutil
                image.parent.mkdir(parents=True,exist_ok=True);label.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(self.output/'display'/r['display'],image)
                label.write_text('\n'.join(str(b['class_id'])+' '+' '.join(str(v) for v in b['xywh']) for b in boxes)+ ('\n' if boxes else ''))
                (self.output/'dataset.yaml').write_text('path: .\ntrain: images/train\nnames:\n  0: red\n  1: blue\n')
            else:
                # Unchecking a reviewed frame removes only this generated export, never source labels.
                for p in (image,label):
                    if p.is_file():p.unlink()
            self.persist();return json.loads(json.dumps(r))


def serve(folder,output,closing,url_ready,detector=None,resume=None):
    review=FolderReview(folder,output,detector);token=(resume or {}).get('token') or secrets.token_urlsafe(24);prefix='/'+token+'/';html=Path(__file__).with_name('image-folder-labeler.html')
    class Handler(BaseHTTPRequestHandler):
        def log_message(self,*args):pass
        def respond(self,status,body,kind='application/json'):
            if not isinstance(body,bytes):body=json.dumps(body).encode()
            self.send_response(status);self.send_header('Content-Type',kind);self.send_header('Cache-Control','no-store');self.send_header('X-Content-Type-Options','nosniff');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
        def route(self):
            if self.headers.get('Host')!=f'127.0.0.1:{self.server.server_port}':raise ValueError('Invalid local host')
            path=urlsplit(self.path).path
            if not path.startswith(prefix):raise ValueError('Invalid review session')
            return path[len(prefix):]
        def do_GET(self):
            try:
                route=self.route()
                if route=='':return self.respond(200,html.read_bytes(),'text/html; charset=utf-8')
                if route=='state':return self.respond(200,review.state())
                if route.startswith('image/'):
                    i=int(route[6:]);assert 0<=i<len(review.rows);return self.respond(200,(review.output/'display'/review.rows[i]['display']).read_bytes(),'image/jpeg')
                return self.respond(404,{'error':'Not found'})
            except Exception as e:self.respond(400,{'error':str(e)[:200]})
        def do_POST(self):
            try:
                route=self.route();origin=self.headers.get('Origin')
                if origin and origin!=f'http://127.0.0.1:{self.server.server_port}':raise ValueError('Invalid local origin')
                length=int(self.headers.get('Content-Length',0));assert 0<length<=65536;p=json.loads(self.rfile.read(length));i=p['id'];assert type(i) is int and 0<=i<len(review.rows)
                if route=='position':
                    with review.lock:review.current_index=i;review.persist()
                    return self.respond(200,{'id':i})
                if route=='predict':return self.respond(200,review.predict(i))
                if route=='save':return self.respond(200,review.save(i,p['boxes'],p.get('reviewed',False)))
                return self.respond(404,{'error':'Not found'})
            except Exception as e:self.respond(400,{'error':str(e)[:200]})
    port=int((resume or {}).get('port',0))
    try:server=ThreadingHTTPServer(('127.0.0.1',port),Handler)
    except OSError:server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
    server.daemon_threads=True;server.timeout=.4
    session={'folder':str(review.folder),'output':str(review.output),'port':server.server_port,'token':token}
    session_path=review.output.parent/'last-session.json';session_path.write_text(json.dumps(session));session_path.chmod(0o600)
    url_ready(f'http://127.0.0.1:{server.server_port}{prefix}')
    try:
        while not closing.is_set():server.handle_request()
    finally:server.server_close()
