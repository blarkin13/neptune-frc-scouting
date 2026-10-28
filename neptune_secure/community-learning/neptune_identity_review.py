"""Private loopback video/box reviewer with saved, source-bound identity corrections.
Run --video match.mp4 --review identity-review.json, or --prepare --labels labels.ndjson --match KEY.
Only reviewed tracking data may be backed up to Neptune; video stays local.
Inferred/corrected review identities do not silently become action labels.
"""
import argparse,hashlib,json,mimetypes,os,re,secrets,socket,subprocess,threading,urllib.parse,webbrowser
from pathlib import Path
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from neptune_identity import apply_corrections,digest

HTML='''<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Neptune · Robot identity review</title>
<style>:root{color-scheme:dark;--bg:#080c16;--panel:#101827;--line:#23314a;--text:#eaf2ff;--muted:#92a4bd;--cyan:#48d9ed}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px system-ui}main{max-width:1440px;margin:auto;padding:24px}header{display:flex;justify-content:space-between;gap:20px;align-items:center}h1{font-size:26px;margin:8px 0}p{color:var(--muted);line-height:1.5}.pill{color:var(--cyan);font-size:12px;letter-spacing:.12em}button,select,input{font:inherit;color:inherit;background:#18263d;border:1px solid #344a68;border-radius:8px;padding:9px}button{cursor:pointer}button:disabled{opacity:.5}button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid var(--cyan)}.grid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:20px;margin-top:20px}.panel{border:1px solid var(--line);background:var(--panel);border-radius:16px;padding:18px;min-width:0}.player{position:relative;background:black;line-height:0}video{width:100%;height:auto;display:block}canvas{position:absolute;inset:0;width:100%;height:100%}.controls{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0}label{display:block;margin:10px 0 5px;color:var(--muted)}input[type=number],select{width:100%}.range{display:grid;grid-template-columns:1fr 1fr;gap:12px}#timeline{width:100%;padding:0}#gaps{max-height:260px;overflow:auto}#gaps button{display:block;width:100%;text-align:left;margin:7px 0;font-size:13px}#status{min-height:24px;color:var(--cyan)}.legend{font-size:13px;color:var(--muted)}small{color:var(--muted)}.good{color:#69deb4}.inferred{color:#48d9ed}.unknown{color:#ffc773}#selection{overflow-wrap:anywhere}.primary{background:var(--cyan);color:#07131a;font-weight:700}.notice{padding:12px;border-left:3px solid var(--cyan);background:#18263d;border-radius:8px}a{color:var(--cyan)}@media(max-width:900px){.grid{grid-template-columns:1fr}main{padding:12px}header{align-items:flex-start;flex-direction:column}} </style>
<main><header><div><span class="pill">NEPTUNE / IDENTITY REVIEW</span><h1 id="title">Robot tracking, forward & backward</h1><small>Corrections are reused for tracking and action clips on this computer</small></div><div class="controls"><button id="export">Export corrected tracking</button><button id="transfer">Transfer learning to another helper</button></div></header>
<p class="notice">Watch an interval, then save its robot number to approve those visible boxes for future training. Correct each fragment to the same number to join its training track. Later bumper readings and the three-red/three-blue roster help resolve earlier gaps. Inferred identities still need review; invisible robots are never given invented boxes.</p><div id="status" role="status">Loading tracking…</div><div class="grid"><section class="panel"><div class="player"><video id="video" preload="metadata" playsinline></video><canvas id="boxes" aria-label="Robot bounding boxes. Choose a track below to correct it."></canvas></div><div class="controls"><button id="play">Play</button><button id="back">− 1 second</button><button id="next">+ 1 second</button><label for="speed">Speed</label><select id="speed" style="width:auto"><option value=".25">¼×</option><option value=".5">½×</option><option value="1" selected>1×</option><option value="2">2×</option></select><span id="clock">0:00</span></div><input id="timeline" type="range" min="0" max="1" step=".05" value="0" aria-label="Video time"><p class="legend"><span class="good">Solid green: verified number</span> · <span class="inferred">Dashed cyan: inferred</span> · <span class="unknown">Amber: unresolved</span> · Purple: your correction</p><small id="counts"></small></section><aside class="panel"><h2 style="font-size:19px">Learning from your review</h2><p id="learning-status">Reading saved corrections…</p><h2 style="font-size:19px">Correct a gap</h2><label for="track">Visible track</label><select id="track"><option value="">Click a box or choose a track</option></select><p id="selection">No box selected.</p><div id="quick-teams" class="controls" aria-label="Label selected robot"></div><label for="team">Robot number</label><select id="team"><option value="">Unknown</option></select><div class="range"><div><label for="start">From video second</label><input id="start" type="number" step=".05" min="0"></div><div><label for="end">Through video second</label><input id="end" type="number" step=".05" min="0"></div></div><div class="controls"><button id="save" class="primary" disabled>Save correction</button><button id="undo" disabled>Undo last</button><small style="display:block;margin-top:8px;color:var(--muted)">Keys: 1–3 pick a team · Enter saves · Delete hides an Unknown box that is not a robot · Z undoes</small></div><p>Save only intervals you have watched. Reviewed boxes are reused on future runs of this video and supply robot-centered action clips. Suggestions outside your saved interval stay unapproved. Two boxes cannot have the same team in one camera frame.</p><h2 style="font-size:19px">Missing box?</h2><p>Pause, choose its view and alliance, then draw around the robot. Inspect the short tracking proposal and save its correct number to approve it.</p><label for="draw-camera">Camera view</label><select id="draw-camera"></select><label for="draw-alliance">Alliance</label><select id="draw-alliance"><option value="red">Red</option><option value="blue">Blue</option></select><button id="draw-box">Draw missing robot box</button><h2 style="font-size:19px">Intervals to inspect</h2><div id="gaps"></div></aside></div></main>
<script>'use strict';let project,base,revision,edits=[],visible=[],hitRegions=[],tracks=new Map(),selected='';const $=id=>document.getElementById(id),video=$('video'),canvas=$('boxes'),ctx=canvas.getContext('2d'),root=location.pathname.replace(/\\/$/,'');let frameMap=[],lastIndex=-1,drawing=false,drawStart=null,displayedTime=0,hasPresentedFrame=false;const frameCallbacks=typeof video.requestVideoFrameCallback==='function';const frameTime=()=>hasPresentedFrame?displayedTime:video.currentTime;
const msg=(text,error=false)=>{$('status').textContent=text;$('status').style.color=error?'#ffb0a4':'#48d9ed'};
function learningStatus(d){const l=d.learning||{},a=l.association||{},cloud=l.cloud||{};let location=cloud.status==='saved'?'Backed up on Neptune.':cloud.status==='conflict'?'Cloud conflict: '+cloud.reason:cloud.status==='pending'?'Saved locally; cloud backup pending: '+cloud.reason:'Saved locally.';$('learning-status').textContent=l.status==='not_synced'?'Not connected to training: '+l.reason:`${l.reviewed_samples||0} reviewed positions ready for action training. ${a.pairs||0} identity pairs from ${(a.matches||[]).length} matches. ${location}`;}
const time=t=>Math.floor(t/60)+':'+String(Math.floor(t%60)).padStart(2,'0');
async function load(){let r=await fetch(root+'/project',{signal:AbortSignal.timeout(20000)});if(!r.ok)throw Error('Cannot open the tracking review');let d=await r.json();base=d.review;project=d.corrected;revision=d.revision;learningStatus(d);edits=base.corrections||[];video.src=root+'/video';render();msg(`Review ready. ${d.learning?.reviewed_samples||0} reviewed samples available for action training. Automatic suggestions still need review.`);}
function render(){tracks=new Map();for(const p of project.points){if(!tracks.has(p.review_track))tracks.set(p.review_track,[]);tracks.get(p.review_track).push(p)}let choice=$('track');choice.replaceChildren(new Option('Click a box or choose a track',''));const gaps=$('gaps');gaps.replaceChildren();let pending=0;
for(const [id,points]of tracks){points.sort((a,b)=>a.t-b.t);let p=points[0],uncertain=points.filter(p=>p.identity_status==='unknown'||p.identity_status==='inferred').length;choice.add(new Option(`${p.alliance} · ${p.camera} · ${time(p.t)}–${time(points.at(-1).t)} · track ${p.track_id}`,id));if(uncertain&&pending++<100){let b=document.createElement('button');b.textContent=`${p.alliance} · ${time(p.t)}–${time(points.at(-1).t)} · ${uncertain} points to inspect`;b.onclick=()=>{select(id);video.currentTime=p.t};gaps.append(b)}}
const bins=new Map();for(const p of project.points){let key=p.t;if(!bins.has(key))bins.set(key,[]);bins.get(key).push(p)}frameMap=[...bins].sort((a,b)=>a[0]-b[0]);lastIndex=-1;
$('draw-camera').replaceChildren(...[...new Set(project.points.map(p=>p.camera))].map(c=>new Option(c,c)));
$('counts').textContent=Object.entries(project.summary||{}).map(([k,v])=>`${v} ${k} points`).join(' · ');$('undo').disabled=!edits.length;if(selected&&tracks.has(selected))select(selected);else selected='';draw();}
function select(id){selected=id;$('track').value=id;const points=tracks.get(id);$('save').disabled=!points;if(!points)return;const first=points[0],last=points.at(-1);$('selection').textContent=`${first.alliance} alliance · ${first.camera} · track ${first.track_id}. ${String(first.reason||'identity unresolved').replaceAll('_',' ')}.`;let team=$('team');$('quick-teams').replaceChildren();for(const n of project.roster[first.alliance]){const b=document.createElement('button');b.textContent=String(n);b.type='button';b.setAttribute('aria-label','Label selected robot '+n);b.onclick=()=>{team.value=String(n);msg('Robot '+n+' selected. Check the interval, then Save correction.');};$('quick-teams').append(b)}team.replaceChildren(new Option('Unknown',''),new Option('Not a robot — hide this box','not_robot'));for(const n of project.roster[first.alliance])team.add(new Option(String(n),String(n)));const other=first.alliance==='red'?'blue':'red';for(const n of project.roster[other]||[])team.add(new Option(`${n} (${other}: the colour is wrong)`,String(n)));let here=points.reduce((a,b)=>Math.abs(b.t-frameTime())<Math.abs(a.t-frameTime())?b:a);team.value=here.team==null?'':String(here.team);$('selection').textContent=`${first.alliance} alliance · ${first.camera} · track ${first.track_id}. ${String(here.reason||'identity unresolved').replaceAll('_',' ')}.`;$('start').value=first.t.toFixed(4);$('end').value=last.t.toFixed(4);if(video.currentTime<first.t||video.currentTime>last.t)video.currentTime=first.t;draw();}
function nearest(t){let lo=0,hi=frameMap.length-1;while(lo<=hi){let m=(lo+hi)>>1;if(frameMap[m][0]<t)lo=m+1;else hi=m-1}let a=frameMap[lo],b=frameMap[hi];let hit=!a?b:!b?a:Math.abs(a[0]-t)<Math.abs(b[0]-t)?a:b;return hit&&Math.abs(hit[0]-t)<=.36?hit[1]:[];}
function draw(){if(!video.videoWidth)return;if(canvas.width!==video.videoWidth||canvas.height!==video.videoHeight){canvas.width=video.videoWidth;canvas.height=video.videoHeight}ctx.clearRect(0,0,canvas.width,canvas.height);ctx.drawImage(video,0,0,canvas.width,canvas.height);hitRegions=[];visible=nearest(frameTime()).filter(p=>p.identity_status!=='excluded');for(const p of visible){let [x,y,w,h]=p.box.map((n,i)=>n*(i%2?canvas.height:canvas.width));let colour=p.identity_status==='verified'?'#69deb4':p.identity_status==='corrected'?'#c3a1ff':p.team==null?'#ffc773':'#48d9ed';ctx.strokeStyle=colour;ctx.lineWidth=p.review_track===selected?5:3;ctx.setLineDash(p.identity_status==='inferred'?[12,7]:[]);ctx.strokeRect(x,y,w,h);ctx.setLineDash([]);let label=(p.team==null?'Unknown':p.team)+(p.identity_status==='inferred'?' ?':'');ctx.font=`bold ${Math.max(16,canvas.width/75)}px system-ui`;let tw=ctx.measureText(label).width+12,lh=Math.max(24,canvas.width/55);ctx.fillStyle=colour;const labelY=Math.max(0,y-lh);ctx.fillRect(x,labelY,tw,lh);hitRegions.push({point:p,box:[x,y,w,h],label:[x,labelY,tw,lh]});ctx.fillStyle='#07131a';ctx.fillText(label,x+6,Math.max(lh-5,y-5))}canvas.dataset.videoTime=String(frameTime());$('clock').textContent=time(frameTime())+' / '+time(video.duration||0);$('timeline').value=frameTime();}
function contains(rect,x,y){return x>=rect[0]&&x<=rect[0]+rect[2]&&y>=rect[1]&&y<=rect[1]+rect[3]}
canvas.onclick=e=>{if(drawing)return;const r=canvas.getBoundingClientRect(),x=(e.clientX-r.left)/r.width*canvas.width,y=(e.clientY-r.top)/r.height*canvas.height;let labels=hitRegions.filter(h=>contains(h.label,x,y)),boxes=hitRegions.filter(h=>contains(h.box,x,y));let hit=(labels.length?labels:boxes).sort((a,b)=>a.box[2]*a.box[3]-b.box[2]*b.box[3])[0];if(hit){video.pause();select(hit.point.review_track);msg('Choose the robot number, check the interval, then Save correction.');$('quick-teams').scrollIntoView({block:'nearest'});}};
$('draw-box').onclick=()=>{video.pause();drawing=!drawing;$('draw-box').textContent=drawing?'Drag a box around the robot…':'Draw missing robot box';canvas.style.cursor=drawing?'crosshair':'default'};
canvas.onpointerdown=e=>{if(!drawing)return;const r=canvas.getBoundingClientRect();drawStart=[(e.clientX-r.left)/r.width,(e.clientY-r.top)/r.height];canvas.setPointerCapture(e.pointerId)};
canvas.onpointerup=async e=>{if(!drawing||!drawStart)return;const r=canvas.getBoundingClientRect(),end=[(e.clientX-r.left)/r.width,(e.clientY-r.top)/r.height],box=[Math.min(drawStart[0],end[0]),Math.min(drawStart[1],end[1]),Math.abs(end[0]-drawStart[0]),Math.abs(end[1]-drawStart[1])];drawStart=null;drawing=false;$('draw-box').textContent='Following drawn box…';$('draw-box').disabled=true;try{let response=await fetch(root+'/propose-box',{method:'POST',headers:{'Content-Type':'application/json','If-Match':revision},body:JSON.stringify({t:frameTime(),box,camera:$('draw-camera').value,alliance:$('draw-alliance').value})});let d=await response.json();if(!response.ok)throw Error(d.error||'Could not follow box');base=d.review;project=d.corrected;revision=d.revision;render();let p=project.points.filter(p=>p.manual_proposal).reduce((a,b)=>!a||Math.abs(b.t-frameTime())<Math.abs(a.t-frameTime())?b:a,null);if(p)select(p.review_track);msg('Box proposal ready. Inspect it, then save the correct number and interval to use it in training.')}catch(e){msg(e.message,true)}finally{$('draw-box').disabled=false;$('draw-box').textContent='Draw missing robot box';canvas.style.cursor='default'}};
canvas.onpointermove=e=>{if(!drawing||!drawStart)return;const r=canvas.getBoundingClientRect();draw();ctx.strokeStyle='#c3a1ff';ctx.lineWidth=3;ctx.setLineDash([8,5]);ctx.strokeRect(drawStart[0]*canvas.width,drawStart[1]*canvas.height,((e.clientX-r.left)/r.width-drawStart[0])*canvas.width,((e.clientY-r.top)/r.height-drawStart[1])*canvas.height);ctx.setLineDash([])};
$('track').onchange=e=>select(e.target.value);$('play').onclick=()=>video.paused?video.play():video.pause();video.onplay=()=>{$('play').textContent='Pause'};video.onpause=()=>{$('play').textContent='Play'};video.onloadedmetadata=()=>{$('timeline').max=video.duration;draw()};video.ontimeupdate=()=>{if(!frameCallbacks&&video.readyState>=2&&!video.seeking){displayedTime=video.currentTime;hasPresentedFrame=true;draw()}};video.onseeked=()=>{if(!frameCallbacks&&video.readyState>=2){displayedTime=video.currentTime;hasPresentedFrame=true;draw()}};video.onseeking=()=>{ctx.clearRect(0,0,canvas.width,canvas.height);hitRegions=[];visible=[]};video.onerror=()=>msg('The review video could not be decoded. Reopen tracking review; your saved corrections are kept.',true);$('timeline').oninput=e=>{video.currentTime=Number(e.target.value)};$('back').onclick=()=>video.currentTime=Math.max(0,video.currentTime-1);$('next').onclick=()=>video.currentTime=Math.min(video.duration,video.currentTime+1);$('speed').onchange=e=>video.playbackRate=Number(e.target.value);
async function persist(next){$('save').disabled=true;try{let r=await fetch(root+'/corrections',{method:'POST',headers:{'Content-Type':'application/json','If-Match':revision},body:JSON.stringify({corrections:next})});let d=await r.json();if(!r.ok)throw Error(d.error||'Correction failed');revision=d.revision;learningStatus(d);edits=next;project=d.corrected;render();msg(d.learning?.status==='not_synced'?'Correction saved, but learning could not be updated: '+d.learning.reason:`Saved for future training: ${d.learning?.reviewed_samples||0} reviewed samples. Retrain actions to use them. Original action labels stay unchanged.`)}catch(e){msg(e.message,true)}finally{$('save').disabled=!selected}}
async function persist(next){$('save').disabled=true;try{let r=await fetch(root+'/corrections',{method:'POST',headers:{'Content-Type':'application/json','If-Match':revision},body:JSON.stringify({corrections:next})});let d=await r.json();if(!r.ok)throw Error(d.error||'Correction failed');revision=d.revision;learningStatus(d);edits=next;project=d.corrected;render();const cloud=d.learning?.cloud||{};msg(d.learning?.status==='not_synced'?'Correction saved locally, but learning could not be updated: '+d.learning.reason:cloud.status==='saved'?'Correction saved here and backed up on Neptune. Retrain actions to use it.':cloud.status==='conflict'?'Correction saved locally. Another helper has a different cloud review; resolve the conflict before backup.':'Correction saved locally. Cloud backup pending: '+(cloud.reason||'connect this helper to Neptune.'))}catch(e){msg(e.message,true)}finally{$('save').disabled=!selected}}
$('save').onclick=()=>{if(!selected)return;const start=Number($('start').value),end=Number($('end').value);if(!Number.isFinite(start)||!Number.isFinite(end)||start<0||end<start){msg('Choose a valid start and end time.',true);return}persist([...edits,{review_track:selected,start,end,team:$('team').value&&$('team').value!=='not_robot'?Number($('team').value):null,...($('team').value==='not_robot'?{ignored:true}:{})}])};$('undo').onclick=()=>persist(edits.slice(0,-1));
document.addEventListener('keydown',e=>{if(e.metaKey||e.ctrlKey||e.altKey||['INPUT','SELECT','TEXTAREA'].includes(document.activeElement?.tagName))return;const quick=[...$('quick-teams').querySelectorAll('button')];
if(/^[1-3]$/.test(e.key)&&quick[e.key-1]){quick[e.key-1].click();e.preventDefault()}
else if(e.key==='Enter'&&!$('save').disabled){$('save').click();e.preventDefault()}
else if((e.key==='Delete'||e.key==='Backspace')&&selected&&!$('save').disabled){const pts=tracks.get(selected)||[];const here=pts.reduce((a,b)=>Math.abs(b.t-frameTime())<Math.abs(a.t-frameTime())?b:a,pts[0]);e.preventDefault();
 // Only an unnamed box can be hidden from the keyboard; a named one needs the menu, so a slip cannot erase a real robot.
 if(!here||here.team!=null){msg('Delete only hides Unknown boxes. Use the Robot number menu for named boxes.',true);return}$('team').value='not_robot';$('save').click()}
else if((e.key==='z'||e.key==='Z')&&!$('undo').disabled){$('undo').click();e.preventDefault()}});$('transfer').onclick=async()=>{try{const r=await fetch(root+'/learning');if(!r.ok)throw Error('Save your corrections before exporting learning data');const data=await r.json(),blob=new Blob([JSON.stringify(data)],{type:'application/json'}),a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='Neptune-reviewed-tracking-learning.json';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1000)}catch(e){msg(e.message,true)}};$('export').onclick=()=>{const blob=new Blob([JSON.stringify(project,null,2)],{type:'application/json'}),a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='Neptune-corrected-identities.json';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1000)};function presentFrame(now,metadata){displayedTime=metadata.mediaTime;hasPresentedFrame=true;draw();video.requestVideoFrameCallback(presentFrame)}if(frameCallbacks)video.requestVideoFrameCallback(presentFrame);load().catch(e=>msg('Tracking review connection lost. Keep the helper open and reopen Review robot tracking. Your saved corrections are kept.',true));</script></html>'''

HTML=HTML.replace('Corrections are reused for tracking and action clips on this computer','Saved corrections back up to Neptune when connected')

def save_json(path,value):
    temp=path.with_suffix('.pending');data=json.dumps(value,allow_nan=False,separators=(',',':')).encode()
    fd=os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    try:
        with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
        os.replace(temp,path)
    finally:temp.unlink(missing_ok=True)

class ReviewServer(ThreadingHTTPServer):
    def __init__(self,video,review,path,cloud=None,cloud_revision=None,cloud_conflict=None):
        from neptune_cache import session
        self._media_lease=session(Path(video).parent);self._media_lease.__enter__()
        self.video=Path(video);self.review=review;self.path=Path(path);self.lock=threading.Lock();self.token=secrets.token_urlsafe(24)
        self.cloud=cloud;self.cloud_revision=cloud_revision;self.cloud_conflict=cloud_conflict
        self.corrected=apply_corrections(review,review.get('corrections',[]));self.learning=self.sync_learning();super().__init__(('127.0.0.1',0),Handler)
    def sync_learning(self):
        if not self.review.get('match_key'):return {'status':'match_key_required'}
        from neptune_review_learning import publish,train_association
        from neptune_video_labels import sha256_file
        try:
            if sha256_file(self.video)!=self.review['source_sha256']:raise ValueError('Video changed; learning was not updated')
            result=publish(self.review,video=self.video,expected_revision=self.review.get('learning_revision'));self.review['learning_revision']=result['revision'];save_json(self.path,self.review);result['association']=train_association()
            if self.cloud_conflict:result['cloud']={'status':'conflict','reason':self.cloud_conflict}
            elif self.cloud and self.review.get('corrections'):
                try:self.cloud_revision=self.cloud.save(self.review,self.cloud_revision);result['cloud']={'status':'saved','revision':self.cloud_revision}
                except Exception as error:result['cloud']={'status':'pending','reason':str(error)[:200]}
            elif self.cloud:result['cloud']={'status':'no_corrections'}
            else:result['cloud']={'status':'local_only'}
            return result
        except (OSError,ValueError,RuntimeError,subprocess.SubprocessError) as error:return {'status':'not_synced','reason':str(error)[:200]}
    def server_close(self):
        try:super().server_close()
        finally:
            lease=getattr(self,'_media_lease',None)
            if lease is not None:self._media_lease=None;lease.__exit__(None,None,None)

    @property
    def url(self):return 'http://127.0.0.1:%d/%s/'%(self.server_port,self.token)
    def revision(self):return digest({'corrections':self.review.get('corrections',[]),'proposals':self.review.get('proposal_revision')}) if self.review.get('proposal_revision') else digest(self.review.get('corrections',[]))
    def project(self):return {'review':self.review,'corrected':self.corrected,'revision':self.revision(),'learning':self.learning}

class Handler(BaseHTTPRequestHandler):
    def setup(self):
        super().setup();self.connection.settimeout(15);self.connection.setsockopt(socket.SOL_SOCKET,socket.SO_SNDBUF,128*1024)
    def log_message(self,*args):pass
    def headers_for(self,status,kind,length):
        self.send_response(status);self.send_header('Content-Type',kind);self.send_header('Content-Length',str(length));self.send_header('Cache-Control','no-store');self.send_header('X-Content-Type-Options','nosniff');self.send_header('Referrer-Policy','no-referrer');self.send_header('Content-Security-Policy',"default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; media-src 'self'; connect-src 'self'; img-src 'self' blob:; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
    def reply(self,status,value):
        data=json.dumps(value,allow_nan=False).encode();self.headers_for(status,'application/json',len(data));self.end_headers();self.wfile.write(data)
    def route(self):
        if self.headers.get('Host')!='127.0.0.1:%d'%self.server.server_port:return None
        path=urllib.parse.urlsplit(self.path).path;prefix='/'+self.server.token+'/'
        return path[len(prefix):] if path.startswith(prefix) else None
    def do_GET(self):
        route=self.route()
        if route is None:return self.reply(404,{'error':'Not found'})
        if route=='':
            data=HTML.encode();self.headers_for(200,'text/html; charset=utf-8',len(data));self.end_headers();self.wfile.write(data)
        elif route=='project':
            with self.server.lock:self.reply(200,self.server.project())
        elif route=='learning':
            from neptune_review_learning import load
            document=load(self.server.review['source_sha256'])
            if document is None:return self.reply(404,{'error':'No saved learning data'})
            self.reply(200,document)
        elif route=='video':
            size=self.server.video.stat().st_size;start=0;end=size-1;status=200
            if 'Range' in self.headers:
                match=re.fullmatch(r'bytes=(\d+)-(\d*)',self.headers['Range'])
                if not match:return self.reply(416,{'error':'Unsupported video range'})
                start=int(match[1]);end=min(size-1,int(match[2])) if match[2] else size-1;status=206
                if not 0<=start<=end<size:return self.reply(416,{'error':'Video range outside file'})
            self.headers_for(status,mimetypes.guess_type(self.server.video.name)[0] or 'video/mp4',end-start+1);self.send_header('Accept-Ranges','bytes')
            if status==206:self.send_header('Content-Range','bytes %d-%d/%d'%(start,end,size))
            self.end_headers()
            try:
                with self.server.video.open('rb') as f:
                    f.seek(start);remaining=end-start+1
                    while remaining:
                        data=f.read(min(64*1024,remaining))
                        if not data:break
                        # Bounded chunks and socket timeout prevent abandoned seeks from filling macOS buffers.
                        self.wfile.write(data);remaining-=len(data)
            except (BrokenPipeError,ConnectionResetError,TimeoutError,OSError):pass
        else:self.reply(404,{'error':'Not found'})
    def do_POST(self):
        route=self.route()
        if route not in ('corrections','propose-box'):return self.reply(404,{'error':'Not found'})
        if self.headers.get('Origin')!='http://127.0.0.1:%d'%self.server.server_port:return self.reply(403,{'error':'Origin rejected'})
        if self.headers.get('Content-Type')!='application/json':return self.reply(415,{'error':'JSON required'})
        try:
            length=int(self.headers.get('Content-Length','0'))
            if not 1<=length<=200_000:raise ValueError('Correction request too large')
            value=json.loads(self.rfile.read(length))
            if route=='propose-box':
                if set(value)!={'t','box','camera','alliance'}:raise ValueError('Invalid box proposal')
                with self.server.lock:
                    if self.headers.get('If-Match')!=self.server.revision():return self.reply(409,{'error':'Review changed; reload before drawing'})
                    from neptune_box_follow import propose
                    from neptune_identity import reconcile
                    proposed=propose(self.server.video,self.server.review,**value)
                    if not proposed:raise ValueError('Could not follow the drawn box')
                    existing={p['point_id']:p for p in self.server.review['points']}
                    observations=list(existing.values())+proposed
                    unique={}
                    for p in observations:unique[digest([p['camera'],p['scene'],p['t'],p['track_id'],p['box']])]=p
                    observations=list(unique.values())
                    fresh=reconcile(observations,self.server.review['roster'],self.server.review['source_sha256'])
                    for p in fresh['points']:
                        if p['point_id'] in existing:p['review_track']=existing[p['point_id']]['review_track']
                    updated={**self.server.review,'points':fresh['points'],'proposal_revision':digest(observations)}
                    corrected=apply_corrections(updated,updated.get('corrections',[]));save_json(self.server.path,updated)
                    self.server.review=updated;self.server.corrected=corrected
                    self.reply(200,self.server.project());return
            if set(value)!={'corrections'}:raise ValueError('Unexpected correction fields')
            with self.server.lock:
                if self.headers.get('If-Match')!=self.server.revision():return self.reply(409,{'error':'Another window changed this review. Reload before saving.'})
                corrected=apply_corrections(self.server.review,value['corrections']);updated={**self.server.review,'corrections':value['corrections']}
                save_json(self.server.path,updated);self.server.review=updated;self.server.corrected=corrected;self.server.learning=self.server.sync_learning()
                self.reply(200,self.server.project())
        except (ValueError,TypeError,KeyError) as e:self.reply(400,{'error':str(e)[:200]})
        except OSError:self.reply(500,{'error':'Could not save the correction; previous data is kept'})

def prepare(video,labels_path,match_key,folder,progress=print):
    from neptune_video_labels import sha256_file,probe_duration_ms
    from neptune_facts import analyze_video
    from neptune_training import ensure_tracking
    from neptune_games import select
    rows=[json.loads(line) for line in Path(labels_path).read_text().splitlines() if line.strip()];row=next((r for r in rows if r['match_key']==match_key),None)
    if not row:raise ValueError('Match is not in the selected label snapshot')
    from neptune_identity import roster_check
    roster_check(row['roster']);folder=Path(folder);folder.mkdir(parents=True,exist_ok=True,mode=0o700);source=sha256_file(video)
    class Stage(list):
        def __setitem__(self,key,value):super().__setitem__(key,value);progress(str(value))
    saved=folder/'facts.json'
    facts=json.loads(saved.read_text()) if saved.exists() else None
    if not facts or facts.get('source',{}).get('sha256')!=source:facts=analyze_video(video,{'key':match_key,'roster':row['roster']},folder,Stage(['']),source_sha256=source)
    game=select(match_key);facts=ensure_tracking(video,facts,game,folder,progress);save_json(saved,facts)
    review=facts.get('tracking',{}).get('identity_review')
    if not review:raise ValueError('No tracked detections available. See the saved facts and layout/phase diagnostics.')
    review['match_key']=match_key
    if row.get('video_id'):review['video_id']=row['video_id']
    path=folder/'identity-review.json'
    if path.exists():
        old=json.loads(path.read_text())
        if old.get('source_sha256')!=source:raise ValueError('This review folder already contains corrections for a different video. Choose a new folder; the original review is kept.')
        # Preserve explicitly reviewed geometry/IDs even when a newer tracker
        # uses different numeric IDs. Replacing those detections avoids silently
        # dropping earlier work or applying it to a recycled tracker ID.
        edits=old.get('corrections',[])
        preserved=[p for p in old.get('points',[]) if p.get('manual_proposal') or any(e['review_track']==p['review_track'] and e['start']<=p['t']<=e['end'] for e in edits)]
        if preserved:
            from neptune_identity import reconcile
            from neptune_training import boxes_overlap
            kept=[p for p in review['points'] if not any(q['camera']==p['camera'] and abs(q['t']-p['t'])<.08 and boxes_overlap(q['box'],p['box'])>.35 for q in preserved)]
            existing={p['point_id']:p for p in old['points']}
            fresh=reconcile(kept+preserved,review['roster'],source)
            for p in fresh['points']:
                if p['point_id'] in existing:p['review_track']=existing[p['point_id']]['review_track']
            review['points']=fresh['points'];review['proposal_revision']=old.get('proposal_revision')
        if old.get('learning_revision'):review['learning_revision']=old['learning_revision']
        review['corrections']=old.get('corrections',[])
    apply_corrections(review,review['corrections']);save_json(path,review);return path

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--video',type=Path);p.add_argument('--choose',action='store_true');p.add_argument('--review',type=Path);p.add_argument('--prepare',action='store_true');p.add_argument('--labels',type=Path);p.add_argument('--match');p.add_argument('--output',type=Path,default=Path.home()/'.neptune-trainer/identity-reviews');p.add_argument('--no-browser',action='store_true');a=p.parse_args()
    if a.choose:
        closing=threading.Event()
        try:choose(Path.home()/'.neptune-trainer/jobs',a.output,closing,lambda url:(print(url,flush=True),None if a.no_browser else webbrowser.open(url)))
        except KeyboardInterrupt:closing.set()
        return
    if not a.video:p.error('--video is required for a saved review')
    if a.prepare:
        if not a.labels or not a.match:p.error('--prepare requires --labels and --match')
        a.review=prepare(a.video,a.labels,a.match,a.output/a.match)
    if not a.review:p.error('Choose a review JSON or prepare one from the video and roster snapshot')
    review=json.loads(a.review.read_text())
    from neptune_video_labels import sha256_file
    if sha256_file(a.video)!=review['source_sha256']:raise ValueError('This review belongs to a different video')
    with ReviewServer(a.video,review,a.review) as server:
        print(server.url,flush=True)
        if not a.no_browser:webbrowser.open(server.url)
        try:server.serve_forever()
        except KeyboardInterrupt:pass

CATALOG_HTML='''<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Neptune · Choose tracking video</title><style>body{background:#080c16;color:#eaf2ff;font:16px system-ui;max-width:820px;margin:50px auto;padding:24px}section{background:#101827;border:1px solid #23314a;border-radius:18px;padding:24px}h1{font-size:28px}p{color:#92a4bd;line-height:1.6}select,button{font:inherit;padding:12px;border:1px solid #344a68;border-radius:8px;background:#18263d;color:inherit;margin:8px 0}select{width:100%}button{background:#48d9ed;color:#07131a;font-weight:bold}button:disabled{opacity:.5}a{color:#48d9ed}#status{white-space:pre-wrap}</style><section><small>NEPTUNE / ROBOT IDENTITY</small><h1>Choose a tagged match</h1><p>The helper reads the verified roster from your saved label snapshot, downloads that match video, and tracks it forward and backward. Your labels stay unchanged. The review video is kept in your identity-review folder so you can return and correct gaps.</p><select id="match" aria-label="Tagged match"></select><button id="start">Prepare tracking review</button><p id="status" role="status">Reading saved matches…</p><a id="open" hidden>Open video with robot boxes →</a><p>Close the tracking review in the helper when finished. Imported scouting-only matches are excluded from this picker. No video or corrections are uploaded to Neptune.</p><h2>Bring corrections from another helper</h2><p>Choose its exported reviewed-tracking learning file. Video fingerprints keep corrections attached to the exact same footage.</p><input id="import-file" type="file" accept="application/json,.json" aria-label="Reviewed tracking learning file"><button id="import">Import reviewed tracking</button></section><script>const root=location.pathname.replace(/\\/$/,''),q=s=>document.getElementById(s);let revision='',polling=false;async function poll(){if(polling)return;polling=true;try{let r=await fetch(root+'/status',{signal:AbortSignal.timeout(10000)});if(!r.ok)throw Error('Review connection lost');let d=await r.json();q('status').textContent=d.message;q('start').disabled=d.busy||!d.matches.length;if(!revision){for(const m of d.matches)q('match').add(new Option(m.key+' · '+m.labels+' saved labels',m.key));revision='loaded'}if(d.url){q('open').href=d.url;q('open').hidden=false}}finally{polling=false}}q('start').onclick=async()=>{q('start').disabled=true;try{let r=await fetch(root+'/start',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({match:q('match').value})});let d=await r.json();if(!r.ok)q('status').textContent=d.error;else q('open').hidden=true;await poll()}catch(e){disconnected()}};q('import').onclick=async()=>{try{const file=q('import-file').files[0];if(!file)throw Error('Choose a learning file first');if(file.size>12000000)throw Error('Learning file is too large');const response=await fetch(root+'/import',{method:'POST',headers:{'Content-Type':'application/json'},body:await file.text()}),data=await response.json();if(!response.ok)throw Error(data.error);q('status').textContent='Imported '+data.reviewed_samples+' reviewed samples. They will be used the next time you train on this video.'}catch(e){q('status').textContent=e.message}};function disconnected(){q('status').textContent='Tracking review connection lost. Keep Neptune Training Helper open. If you closed review or updated the helper, reopen Review robot tracking to get a new link. Saved corrections are kept.';q('start').disabled=true;}poll().catch(disconnected);document.addEventListener('visibilitychange',()=>{if(!document.hidden)poll().catch(disconnected)});setInterval(()=>{if(!document.hidden)poll().catch(disconnected)},3000);</script></html>'''

CATALOG_HTML=CATALOG_HTML.replace('No video or corrections are uploaded to Neptune.','Videos stay on this computer. Saved tracking reviews back up to Neptune when the helper is connected.')
CATALOG_HTML=CATALOG_HTML.replace("if(!revision){for(const m of d.matches)q('match').add(new Option(m.key+' · '+m.labels+' saved labels',m.key));revision='loaded'}", "const selected=q('match').value;q('match').replaceChildren();for(const m of d.matches)q('match').add(new Option(m.key+' · '+(m.cloud_only?'saved on Neptune':m.labels+' saved labels'),m.key));if(selected&&d.matches.some(m=>m.key===selected))q('match').value=selected;")

def local_matches(jobs):
    matches={}
    for path in sorted(Path(jobs).glob('*/labels.ndjson'),key=lambda p:p.stat().st_mtime):
        if path.is_symlink() or path.stat().st_size>40_000_000:continue
        for line in path.read_text().splitlines():
            try:row=json.loads(line)
            except ValueError:continue
            if not isinstance(row,dict):continue
            if row.get('evidence_type')!='human_video_label' or not re.fullmatch(r'20\d{2}[A-Za-z0-9_-]{1,100}',str(row.get('match_key',''))) or not re.fullmatch(r'[A-Za-z0-9_-]{11}',str(row.get('video_id',''))):continue
            from neptune_identity import roster_check
            try:roster_check(row.get('roster',{}))
            except ValueError:continue
            key=row['match_key'];entry=matches.get(key)
            if not entry or entry['snapshot']!=path:matches[key]={'key':key,'snapshot':path,'video_id':row['video_id'],'labels':0}
            matches[key]['labels']+=1
    return matches

class CatalogServer(ThreadingHTTPServer):
    def __init__(self,jobs,output,closing=None,cloud=None):
        self.matches=local_matches(jobs);self.output=Path(output);self.closing=closing or threading.Event();self.cloud=cloud;self.lock=threading.Lock();self.busy=False;self.message='Choose one of your saved tagged matches.' if self.matches else 'No saved human-tagged job snapshots were found. Run a tagged training selection first.';self.review_url=None;self.children=[];self.worker=None;self.token=secrets.token_urlsafe(24);super().__init__(('127.0.0.1',0),CatalogHandler)
        if cloud:threading.Thread(target=self.load_cloud_reviews,daemon=True).start()
    def load_cloud_reviews(self):
        result=self.cloud.backfill(self.output,{key:r['video_id'] for key,r in self.matches.items()})
        try:rows=self.cloud.listing()
        except Exception as error:rows=[];result['conflicts'].append('Cloud review list: '+str(error)[:100])
        with self.lock:
            for row in rows:
                key=row['match_key']
                if key not in self.matches:self.matches[key]={'key':key,'snapshot':None,'video_id':row['video_id'],'source_sha256':row['source_sha256'],'labels':0,'cloud_only':True}
            if not self.busy and result['conflicts']:self.message='Some local reviews still need cloud backup: '+', '.join(result['conflicts'][:3])
            elif not self.busy and (result['backed_up'] or rows):self.message=str(result['backed_up'])+' saved reviews backed up; '+str(len(rows))+' available on Neptune. Choose a match.'
    @property
    def url(self):return 'http://127.0.0.1:%d/%s/'%(self.server_port,self.token)
    def start(self,key):
        if key not in self.matches:raise ValueError('Choose a saved tagged match')
        with self.lock:
            if self.busy:raise ValueError('A tracking review is already being prepared')
            self.busy=True;self.message='Checking video tools and available disk space…';self.review_url=None
        def work():
            try:
                from neptune_video_labels import fetch_video
                from neptune_cache import require_space
                self.output.mkdir(parents=True,exist_ok=True,mode=0o700);require_space(self.output);row=self.matches[key];folder=self.output/key;media=folder/'media';media.mkdir(parents=True,exist_ok=True,mode=0o700)
                def progress(value):
                    if self.closing.is_set():raise InterruptedError('Tracking review stopped; your saved files are kept')
                    with self.lock:self.message=str(value)
                progress('Downloading '+key+' for local review…');video=fetch_video(row['video_id'],media)
                cloud_revision=None;cloud_conflict=None
                if self.cloud:
                    from neptune_video_labels import sha256_file
                    path=folder/'identity-review.json'
                    try:
                        source=sha256_file(video)
                        if row.get('source_sha256') and row['source_sha256']!=source:raise ValueError('Downloaded video differs from this saved review')
                        remote,cloud_revision=self.cloud.fetch(source,key)
                        if remote:
                            local=json.loads(path.read_text()) if path.exists() else None
                            if local and local.get('corrections') and local.get('corrections')!=remote.get('corrections'):
                                cloud_conflict='This Mac and Neptune have different saved corrections for '+key+'. Both copies are kept.'
                            elif not local or not local.get('corrections'):save_json(path,remote)
                    except Exception as error:
                        if row.get('cloud_only'):raise
                        progress('Cloud backup unavailable; keeping local review: '+str(error)[:180])
                if row.get('cloud_only'):
                    review_path=folder/'identity-review.json'
                    if not review_path.is_file():raise ValueError('Saved cloud review could not be restored')
                else:review_path=prepare(video,row['snapshot'],key,folder,progress)
                review=json.loads(review_path.read_text());child=ReviewServer(video,review,review_path,self.cloud,cloud_revision,cloud_conflict);self.children.append(child);threading.Thread(target=child.serve_forever,daemon=True).start()
                with self.lock:self.review_url=child.url;self.message='Tracking review ready. Open the video to inspect boxes and correct identities.'
            except Exception as e:
                with self.lock:self.message='Review needs attention: '+str(e)[:500]
            finally:
                with self.lock:self.busy=False
        self.worker=threading.Thread(target=work,daemon=True);self.worker.start()

class CatalogHandler(Handler):
    def do_GET(self):
        route=self.route()
        if route=='':
            data=CATALOG_HTML.encode();self.headers_for(200,'text/html; charset=utf-8',len(data));self.end_headers();self.wfile.write(data)
        elif route=='status':
            with self.server.lock:self.reply(200,{'matches':[{'key':r['key'],'labels':r['labels'],'cloud_only':r.get('cloud_only',False)} for r in self.server.matches.values()],'busy':self.server.busy,'message':self.server.message,'url':self.server.review_url})
        else:self.reply(404,{'error':'Not found'})
    def do_POST(self):
        route=self.route()
        if route not in ('start','import'):return self.reply(404,{'error':'Not found'})
        if self.headers.get('Origin')!='http://127.0.0.1:%d'%self.server.server_port:return self.reply(403,{'error':'Origin rejected'})
        try:
            length=int(self.headers.get('Content-Length','0'))
            if self.headers.get('Content-Type')!='application/json' or not 1<=length<=(12000000 if route=='import' else 500):raise ValueError('Valid JSON request required')
            data=json.loads(self.rfile.read(length))
            if route=='import':
                from neptune_review_learning import import_document,train_association
                result=import_document(data);result['association']=train_association();self.reply(200,result);return
            if set(data)!={'match'}:raise ValueError('Unexpected request fields')
            self.server.start(data['match']);self.reply(202,{'state':'preparing'})
        except (ValueError,KeyError,TypeError) as e:self.reply(400,{'error':str(e)[:200]})

def choose(jobs,output,closing,opened,connection=None):
    from neptune_review_cloud import ReviewCloud
    with CatalogServer(jobs,output,closing,ReviewCloud(connection) if connection else None) as server:
        server.timeout=.5;opened(server.url)
        while not closing.is_set():server.handle_request()
        for child in server.children:child.shutdown();child.server_close()
        if server.worker:server.worker.join(timeout=60)

if __name__=='__main__':main()
