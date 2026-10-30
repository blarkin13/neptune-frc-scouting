'use strict';
// Robot Labels: box every bumper on paused YouTube frames; boxes are normalized to the full video frame.
(()=>{
 const app=document.getElementById('robot-labels-app');if(!app)return;
 const $=id=>document.getElementById(id),cv=$('canvas'),ctx=cv.getContext('2d'),stage=$('stage'),zoomer=$('zoomer'),frame=$('frame');
 const testOnly=new URLSearchParams(location.search).get('ui_test')==='1';
 const endpoint=location.pathname.endsWith('robot-labels-preview.php')?'robot-labels-preview.php':'robot-labels.php';
 const still=$('training-frame'),sourceStatus=$('frame-source-status');let exact=null,imageIndex={};
 const video=app.dataset.youtube,matchKey=app.dataset.matchKey,v=window.NeptuneVideo;
 let playing=false,times=[],idx=-1,boxes=[],sel=-1,color='red',saved={},drag=null,zoom=1,pan=[0,0],space=false,dirty=false,busy=false;
 const say=t=>$('status').textContent=t;
 // Detector requests (prepare frames / train) run on the connected helper; this shows their state.
 async function detectorStatus(){try{const r=await fetch(endpoint+'?detector_status=1');const d=await r.json();
  const names={prepare:'Prepare frames',train:'Train robot detector'};
  $('requests').innerHTML='';for(const q of d.requests||[]){const row=document.createElement('div');row.className='rl-request '+q.state;
   row.textContent=`${names[q.kind]||q.kind}${q.match_key?' · '+q.match_key.replace(/^\d{4}[a-z0-9]+_/,''):''} — ${q.state==='queued'?'waiting for a helper':q.state==='claimed'?'running':q.state}${q.message?': '+q.message:''}`;$('requests').append(row)}
  const c=d.current;const rep=c&&c.report;$('current').textContent=c?`Current detector adopted ${new Date(c.adopted_at*1000).toLocaleDateString()}${rep&&rep.new?` · held-out ${rep.holdout_match||''}: found ${Math.round(rep.new.recall*100)}%, right ${Math.round(rep.new.precision*100)}%`:''}`:'Using the detector shipped with the helper.'}catch(e){}}
 async function queue(action){try{const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-Robot-Labels-CSRF':app.dataset.csrf},body:JSON.stringify({action,video_id:app.dataset.youtube,match_key:app.dataset.matchKey})});
  const d=await r.json();if(!r.ok)throw Error(d.error||'Could not queue');say(action==='queue_train'?'Detector training queued for your helper.':'Frame preparation queued for your helper.');detectorStatus()}catch(e){say(e.message)}}
 $('prepare').onclick=()=>queue('queue_prepare');
 detectorStatus();setInterval(detectorStatus,15000);
 const stamp=s=>{const m=Math.floor(s/60),r=(s%60).toFixed(2).padStart(5,'0');return String(m).padStart(2,'0')+':'+r};
 function setColor(c){color=c;$('red').classList.toggle('active',c==='red');$('blue').classList.toggle('active',c==='blue');$('red').setAttribute('aria-pressed',c==='red');$('blue').setAttribute('aria-pressed',c==='blue')}
 function place(){zoomer.style.transform=`translate(${pan[0]}px,${pan[1]}px) scale(${zoom})`;draw()}
 function fit(){zoom=1;pan=[0,0];place()}
 function draw(){saveState();const r=stage.getBoundingClientRect();cv.width=Math.round(r.width/zoom*devicePixelRatio);cv.height=Math.round(r.height/zoom*devicePixelRatio);
  const W=cv.width,H=cv.height,lw=Math.max(1.5,2*devicePixelRatio/zoom);ctx.clearRect(0,0,W,H);$('boxcount').textContent=boxes.length;
  if(playing||!exact)return; // boxes belong to the paused frame; hide them while the video moves
  boxes.forEach((b,i)=>{ctx.setLineDash(b.source==='detector'?[6*devicePixelRatio/zoom,4*devicePixelRatio/zoom]:[]);ctx.lineWidth=i===sel?lw*2:lw;ctx.strokeStyle=b.alliance==='red'?'#ff4d5e':'#3d8bff';
   ctx.strokeRect(b.x*W,b.y*H,b.w*W,b.h*H);if(i===sel){ctx.setLineDash([]);ctx.fillStyle='#fff';const s=7*devicePixelRatio/zoom;for(const[cx,cy]of corners(b))ctx.fillRect(cx*W-s/2,cy*H-s/2,s,s)}});
  ctx.setLineDash([])}
 const corners=b=>[[b.x,b.y],[b.x+b.w,b.y],[b.x+b.w,b.y+b.h],[b.x,b.y+b.h]];
 function point(e){const r=cv.getBoundingClientRect();return[(e.clientX-r.left)/r.width,(e.clientY-r.top)/r.height]}
 function hit(p){const tol=.006/zoom;if(sel>=0){const k=corners(boxes[sel]).findIndex(c=>Math.abs(c[0]-p[0])<tol*1.5&&Math.abs(c[1]-p[1])<tol*2.7);if(k>=0)return{i:sel,corner:k}}
  for(let i=boxes.length-1;i>=0;i--){const b=boxes[i];if(p[0]>=b.x-tol&&p[0]<=b.x+b.w+tol&&p[1]>=b.y-tol&&p[1]<=b.y+b.h+tol)return{i}}return null}
 // Undo (U): a snapshot of this frame's boxes is taken before each change.
 const hist=[],remember=()=>{hist.push(JSON.stringify(boxes));if(hist.length>100)hist.shift()},undo=()=>{if(!hist.length)return;boxes=JSON.parse(hist.pop());sel=-1;dirty=true;draw()};
 cv.addEventListener('mousedown',e=>{if(!video||idx<0||!exact||playing||busy)return;if(space||e.button===1){drag={pan:[e.clientX,e.clientY,...pan]};return}remember();
  v.pause();const p=point(e),h=hit(p);
  if(h&&h.corner!==undefined)drag={resize:h.i,corner:h.corner};else if(h){sel=h.i;drag={move:h.i,from:p,orig:{...boxes[h.i]}}}else{sel=-1;drag={make:p}}draw()});
 window.addEventListener('mousemove',e=>{if(!drag)return;if(drag.pan){pan=[drag.pan[2]+e.clientX-drag.pan[0],drag.pan[3]+e.clientY-drag.pan[1]];return place()}
  const p=point(e);
  if(drag.make){drag.now=p;draw();const[a,b]=[drag.make,p];ctx.strokeStyle=color==='red'?'#ff4d5e':'#3d8bff';ctx.lineWidth=2*devicePixelRatio/zoom;
   ctx.strokeRect(Math.min(a[0],b[0])*cv.width,Math.min(a[1],b[1])*cv.height,Math.abs(b[0]-a[0])*cv.width,Math.abs(b[1]-a[1])*cv.height)}
  else if(drag.move!==undefined){const o=drag.orig;Object.assign(boxes[drag.move],{x:o.x+p[0]-drag.from[0],y:o.y+p[1]-drag.from[1],source:'person'});dirty=true;draw()}
  else if(drag.resize!==undefined){const b=boxes[drag.resize],opp=corners(b)[(drag.corner+2)%4];
   Object.assign(b,{x:Math.min(p[0],opp[0]),y:Math.min(p[1],opp[1]),w:Math.abs(p[0]-opp[0]),h:Math.abs(p[1]-opp[1]),source:'person'});
   const l=p[0]<opp[0],t=p[1]<opp[1];drag.corner=l&&t?0:!l&&t?1:!l&&!t?2:3;dirty=true;draw()}});
 window.addEventListener('mouseup',()=>{if(drag&&!drag.pan)setTimeout(()=>{if(hist.at(-1)===JSON.stringify(boxes))hist.pop()});if(drag&&drag.make&&drag.now){const[a,b]=[drag.make,drag.now],w=Math.abs(b[0]-a[0]),h=Math.abs(b[1]-a[1]);
  if(w>.002&&h>.003){boxes.push({alliance:color,x:Math.min(a[0],b[0]),y:Math.min(a[1],b[1]),w,h,source:'person'});sel=boxes.length-1;dirty=true}}drag=null;draw()});
 frame.addEventListener('wheel',e=>{if(!video)return;e.preventDefault();const r=frame.getBoundingClientRect(),mx=e.clientX-r.left,my=e.clientY-r.top,z=Math.min(10,Math.max(1,zoom*Math.exp(-e.deltaY*.0015)));
  pan=[mx-(mx-pan[0])*z/zoom,my-(my-pan[1])*z/zoom];zoom=z;if(zoom===1)pan=[0,0];place()},{passive:false});
 async function api(q,body){const r=await fetch(endpoint+q,body?{method:'POST',headers:{'Content-Type':'application/json','X-Robot-Labels-CSRF':app.dataset.csrf},body:JSON.stringify(body)}:{});
  const d=await r.json();if(!r.ok)throw Error(d.error||'Request failed');return d}
 async function save(extra){if(testOnly){dirty=false;say('UI test only — labels are not saved.');return;}if(!extra.skipped&&!extra.delete&&(!exact||playing))throw Error('Load the clear source still before saving labels.');const t=times[idx];await api('',{source_still:true,frame_sha256:exact?.sha256||'',video_id:video,match_key:matchKey,t_ms:t,boxes:boxes.map(b=>({alliance:b.alliance,x:b.x,y:b.y,w:b.w,h:b.h})),...extra});
  if(extra.delete)delete saved[t];else saved[t]={boxes:boxes.map(b=>({...b})),complete:!!extra.complete,skipped:!!extra.skipped};dirty=false;counts()}
 function counts(){const done=Object.values(saved).filter(f=>f.complete).length;$('donecount').textContent=done;$('framecount').textContent=times.length?`frame ${idx+1} of ${times.length}`:'';frameList();saveState()}
 // Frame picker: every frame with its state, so any frame can be reopened directly.
 const frameState=t=>{const s=saved[t];return s?(s.skipped?'Skipped':s.complete?'Labeled':'Draft'):imageIndex[t]?'Prepared':'Unlabeled'};
 function frameList(){const p=$('framepick');if(!p)return;p.hidden=!times.length;p.replaceChildren(...times.map((t,i)=>new Option(`${i+1} · ${stamp(t/1000)} · ${frameState(t)}`,i,false,i===idx)))}
 function saveState(){const e=$('savestate');if(!e)return;const s=idx>=0&&saved[times[idx]];e.textContent=idx<0?'':dirty?'Edited · not saved yet':s?'Saved':'Not saved';e.dataset.state=dirty?'dirty':s?'saved':'none'}
 async function open(i){if(busy||i<0||i>=times.length)return;busy=true;try{
  if(idx>=0&&dirty)await save({complete:!!(saved[times[idx]]?.complete&&saved[times[idx]]?.frame_sha256)});
  exact=null;still.hidden=true;const yt=$('ytplayer');if(yt)yt.style.visibility='hidden';$('complete').disabled=true;cv.style.pointerEvents='auto';sourceStatus.textContent='Loading clear source still…';$('framebadge').textContent=(matchKey||video)+' · LOADING STILL';
  idx=i;hist.length=0;const t=times[i];$('clock').textContent=stamp(t/1000);v.pause();sel=-1;
  const s=saved[t];if(s){boxes=s.boxes.map(b=>({...b,source:'person'}));say(s.skipped?'Skipped. Box the bumpers and press Enter to use it after all.':s.complete?'Complete — edit and press Enter to update.':'Saved draft.')}
  else{const d=await api(`?prefill=${video}&t_ms=${t}`);boxes=d.boxes;say(boxes.length?'Dashed boxes are the detector\'s guesses — check every bumper.':'No detector guesses here — box each bumper.')}
  dirty=false;playing=false;$('play').textContent='Play';draw();counts();
  if(!imageIndex[t]){sourceStatus.textContent='Source still not prepared. Press Prepare frames for this match; labeling stays locked until it is available.';say('Existing labels are preserved. Prepare clear source frames before editing.');return}
  const sha=s?.frame_sha256||imageIndex[t].sha256;
  still.src=`${endpoint}?frame_image=${video}&t_ms=${t}&sha256=${sha}`;
  await still.decode();still.hidden=false;exact={sha256:sha};cv.style.pointerEvents='auto';$('complete').disabled=false;
  $('framebadge').textContent=(matchKey||video)+' · SOURCE STILL';$('clock').textContent=stamp(t/1000);
  sourceStatus.textContent=(testOnly?'UI TEST · changes are not saved · ':'')+`Exact training image · ${stamp(t/1000)} · no YouTube subtitles or player overlays`;
  if(s&&!s.frame_sha256)say('Earlier labels: check their alignment on this exact source still, then press Enter to confirm.');
  draw();
 }catch(e){say(e.message)}finally{busy=false}}
 const settled=t=>saved[t]&&(saved[t].complete||saved[t].skipped);
 async function complete(){if(idx<0||busy)return;try{const recheck=settled(times[idx]);await save({complete:true});
  // Rechecking a finished frame steps to the very next frame; a new label jumps to the next unfinished one.
  const n=recheck?idx+1:times.findIndex((t,i)=>i>idx&&!settled(t));open(n>=0&&n<times.length?n:idx)}catch(e){say(e.message)}}
 async function skip(){if(idx<0)return;try{boxes=[];await save({skipped:true});open(Math.min(times.length-1,idx+1))}catch(e){say(e.message)}}
 $('red').onclick=()=>setColor('red');$('blue').onclick=()=>setColor('blue');$('complete').onclick=complete;$('skip').onclick=skip;
 $('prevframe').onclick=()=>open(idx-1);if($('framepick'))$('framepick').onchange=e=>{e.target.blur();open(+e.target.value)};$('nextframe').onclick=()=>open(idx+1);
 // Play is for looking around; the frame list decides what gets labelled, so pausing returns to it.
 $('play').onclick=async()=>{if(!v||busy)return;if(!playing){
  try{if(dirty)await save({complete:false});exact=null;still.hidden=true;const yt=$('ytplayer');if(yt)yt.style.visibility='visible';playing=true;cv.style.pointerEvents='none';$('complete').disabled=true;draw();v.currentTime=times[idx]/1000;v.play().catch(()=>{});sourceStatus.textContent='Watching context on YouTube. Pause to return to the clear labeling still.';}catch(e){say(e.message)}
 }else{v.pause();open(idx)}};
 window.addEventListener('keydown',e=>{if(['INPUT','SELECT','TEXTAREA'].includes(document.activeElement.tagName))return;
  if(e.code==='Space'){space=true;e.preventDefault()}if(e.key==='1')setColor('red');if(e.key==='2')setColor('blue');if(e.key==='0')fit();
  if(!exact||playing||busy)return;
  if((e.key==='Delete'||e.key==='Backspace')&&sel>=0){remember();boxes.splice(sel,1);sel=-1;dirty=true;draw();e.preventDefault()}
  if((e.key==='x'||e.key==='X')&&sel>=0){remember();boxes[sel].alliance=boxes[sel].alliance==='red'?'blue':'red';boxes[sel].source='person';dirty=true;draw()}
  if(e.key==='u'||e.key==='U'){e.preventDefault();undo()}
  if(e.key==='Enter'){e.preventDefault();complete()}if(e.key==='ArrowRight')open(idx+1);if(e.key==='ArrowLeft')open(idx-1)});
 window.addEventListener('keyup',e=>{if(e.code==='Space')space=false});
 let autosave=null;setInterval(()=>{if(dirty&&exact&&!playing&&idx>=0&&!busy&&!drag){clearTimeout(autosave);autosave=setTimeout(()=>{if(dirty)save({complete:!!(saved[times[idx]]?.complete&&saved[times[idx]]?.frame_sha256)}).then(()=>say('Draft saved.')).catch(e=>say(e.message))},1500)}},1000);
 window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue=''}});
 new ResizeObserver(draw).observe(stage);
 if(!video||!v){say('Choose a match.');return}
 $('empty').hidden=true;$('framebadge').textContent=(matchKey||video)+' · YOUTUBE';
 v.onplay=()=>{$('play').textContent='Pause';playing=true;draw()};
 v.onpause=()=>{$('play').textContent='Play';if(playing&&!busy)open(idx)};
 v.ontimeupdate=()=>{if(playing)$('clock').textContent=stamp(v.currentTime||0)};
 v.onerror=()=>say('YouTube could not play this match. Check its video link on Browse matches.');
 let initialized=false;async function initialize(){if(initialized)return;initialized=true;try{
  const [labels,pre,images]=await Promise.all([api('?labels='+video),api(`?prefill=${video}&t_ms=0`),api('?frame_index='+video)]);imageIndex=images.frames||{};
  for(const[t,f]of Object.entries(labels.frames||{}))saved[+t]=f;
  // Frames every ~4 s: the tracking review's sample times when there is one, else across the whole video.
  let pool=pre.times&&pre.times.length?pre.times:[];if(!pool.length){for(let t=0;t<(v.duration||0)*1000;t+=1000)pool.push(t)}
  const picked=[];let last=-1e9;for(const t of pool)if(t-last>=4000){picked.push(t);last=t}
  times=[...new Set([...(Object.keys(imageIndex).length?[]:picked),...Object.keys(saved).map(Number),...Object.keys(imageIndex).map(Number)])].sort((a,b)=>a-b);
  const first=times.findIndex(t=>!settled(t));await open(first>=0?first:0);
 }catch(e){say(e.message)}}
 v.onloadedmetadata=initialize;initialize();
 setInterval(async()=>{if(busy||dirty||playing)return;try{const d=await api('?frame_index='+video);if(JSON.stringify(d.frames)!==JSON.stringify(imageIndex)){const current=times[idx];imageIndex=d.frames||{};times=[...new Set([...times,...Object.keys(imageIndex).map(Number)])].sort((a,b)=>a-b);idx=times.indexOf(current);if(idx>=0&&!exact)open(idx);else counts()}}catch(e){}},15000);
})();
