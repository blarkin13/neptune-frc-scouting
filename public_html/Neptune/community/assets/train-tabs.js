'use strict';
// Train & test: status bar, one tab per trainable part, and the detector cards. Action training keeps training-control.js.
(()=>{
 const app=document.getElementById('training-app');if(!app)return;
 const season=app.dataset.season,csrf=app.dataset.csrf;
 const $$=s=>[...document.querySelectorAll(s)];
 const esc=t=>String(t??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
 const when=t=>t?new Date(t*1000).toLocaleString(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}):'—';
 const pct=v=>v==null?'—':Math.round(v*100)+'%';
 const part=name=>document.querySelector(`.tt-part[data-part="${name}"]`);
 const slot=(name,key)=>part(name).querySelector(`[data-slot="${key}"]`);

 // Tabs: one visible at a time, remembered in the address (#robot, #field, …).
 const tabs=$$('.tt-tabs [data-tab]');
 function show(name){
  if(!tabs.some(t=>t.dataset.tab===name))name='robot';
  for(const t of tabs)t.setAttribute('aria-selected',String(t.dataset.tab===name));
  for(const p of $$('.tt-part'))p.hidden=p.dataset.part!==name;
  if(location.hash!=='#'+name)history.replaceState(null,'','#'+name);
 }
 for(const t of tabs)t.addEventListener('click',()=>show(t.dataset.tab));
 // Older code scrolls to sections (for example the run report); open their tab first.
 const scroll=Element.prototype.scrollIntoView;
 Element.prototype.scrollIntoView=function(...a){const p=this.closest('.tt-part');if(p&&p.hidden)show(p.dataset.part);return scroll.apply(this,a)};
 show(location.hash.slice(1)||'robot');
 addEventListener('hashchange',()=>show(location.hash.slice(1)));

 async function send(values){
  const body=new FormData();body.set('csrf',csrf);body.set('season',season);for(const [k,v] of Object.entries(values))body.set(k,v);
  const r=await fetch('train.php',{method:'POST',body,headers:{Accept:'application/json'}});const d=await r.json().catch(()=>({}));
  if(!r.ok)throw Error(d.error||'Could not save');return d;
 }

 const runState=r=>r.state==='done'?(r.report?.adopted?'<span class="tt-badge ok">Switched to it</span>':'<span class="tt-badge">Kept the one in use</span>'):r.state==='failed'?'<span class="tt-badge warn">Failed</span>':r.state==='claimed'?'<span class="tt-badge ok">Running</span>':'<span class="tt-badge">Waiting</span>';
 function runs(list,score){
  if(!list.length)return '<p class="training-note">No runs yet.</p>';
  return '<table class="tt-runs"><tr><th>Started</th><th>Result</th><th>New vs in use</th><th>Details</th></tr>'+list.slice(0,8).map(r=>`<tr><td>${when(r.created_at)}</td><td>${runState(r)}</td><td>${r.report?score(r.report):'—'}</td><td class="tt-msg">${esc(r.message)}</td></tr>`).join('')+'</table>';
 }
 const placed=p=>!p||!p.found?'none found':`${p.found}/${p.expected} · ${(p.mean_error*100).toFixed(1)}% off`;
 const next=(items)=>items.map(([ok,text])=>`<li class="${ok?'tt-ok':'tt-todo'}">${text}</li>`).join('');

 function robot(d,busy){
  const c=d.current,rep=c?.report||{};
  slot('robot','current').innerHTML=c?`<dl><dt>Adopted</dt><dd>${when(c.adopted_at)}</dd><dt>Held-out match</dt><dd>${esc(rep.holdout_match||'—')}</dd><dt>Bumpers found</dt><dd>${pct(rep.new?.recall)} (precision ${pct(rep.new?.precision)})</dd><dt>Learned from</dt><dd>${rep.train_frames??'—'} frames, ${(rep.matches||[]).length} matches</dd></dl>`:'<p>The built-in detector that came with the helper. It has not been retrained here yet.</p>';
  part('robot').querySelector('[data-rollback]').hidden=!d.history;
  const m=Object.entries(d.matches),ready=m.filter(([,n])=>n>=d.min_frames),frames=m.reduce((a,[,n])=>a+n,0);
  slot('robot','data').innerHTML=`<dl><dt>Labelled frames</dt><dd>${frames}</dd>${d.external?.images?`<dt>Imported training images</dt><dd>${d.external.images} (${d.external.synthetic_images||0} variations)</dd><dt>Imported bumper boxes</dt><dd>${d.external.boxes}</dd>`:''}<dt>Matches</dt><dd>${m.length} (${ready.length} with ${d.min_frames}+ frames)</dd><dt>Events</dt><dd>${d.events.length?d.events.map(esc).join(', '):'—'}</dd></dl>`;
  const newer=c&&d.runs.length&&!d.runs.some(r=>r.state==='done'&&r.finished_at>c.adopted_at);
  slot('robot','next').innerHTML=next([
   [ready.length>=2,`Label ${d.min_frames}+ frames in at least 2 matches (${ready.length} so far). The newest match is held back for checking.`],
   [d.events.length>=2,`Label matches from a second event so the detector works beyond one venue (${d.events.length} so far).`],
   [!!c,c?'A trained detector is in use. Label more frames and retrain whenever tracking misses robots.':'Train the first detector.'],
  ]);
  const btn=part('robot').querySelector('[data-train]');btn.disabled=busy||ready.length<2;
  slot('robot','train-note').textContent=busy?'A robot detector run is already queued or running.':ready.length<2?'Needs labelled frames in 2 matches first.':'Takes about an hour on a helper (CPU). It only switches if the new detector is clearly better.';
  slot('robot','runs').innerHTML=runs(d.runs,r=>`F1 ${r.new?.f1?.toFixed(2)??'—'} vs ${r.current?r.current.f1.toFixed(2):'none'}`);
  return {needs:!c||ready.length<2};
 }
 function field(d,busy){
  const c=d.current,rep=c?.report||{};
  slot('field','current').innerHTML=c?`<dl><dt>Adopted</dt><dd>${when(c.adopted_at)}</dd><dt>Held-out match</dt><dd>${esc(rep.holdout_match||'—')}</dd><dt>Targets & corners</dt><dd>${placed(rep.new)}</dd><dt>Learned from</dt><dd>${rep.trained_on?.length??rep.matches??'—'} matches</dd></dl>`:d.baseline?`<p>The helper’s built-in ${esc(d.game_key)} field detector is in use. No retrained replacement has passed the held-out check yet.</p>`:`<p>No field detector is available for ${esc(d.game_key)} yet.</p>`;
  part('field').querySelector('[data-rollback]').hidden=!d.history;
  const m=Object.entries(d.matches),ready=m.filter(([,v])=>v.targets&&v.corners);
  slot('field','data').innerHTML=`<dl><dt>Marked matches</dt><dd>${m.length}</dd><dt>With targets + corners</dt><dd>${ready.length}</dd><dt>Events</dt><dd>${d.events.length?d.events.map(esc).join(', '):'—'}</dd></dl>`;
  slot('field','next').innerHTML=next([
   [ready.length>=2,`Mark scoring targets and all four field corners on at least 2 ${esc(d.game_key)} matches (${ready.length} so far).`],
   [d.events.length>=2,`Mark a match from a second event; camera angles differ between venues (${d.events.length} so far).`],
   [!!c,c?'A retrained field detector is in use.':d.baseline?'The built-in detector remains in use. Retrain to test an improved replacement; your saved marks are retained.':'Train the first field detector for this game.'],
  ]);
  const btn=part('field').querySelector('[data-train]');btn.disabled=busy||ready.length<2;
  slot('field','train-note').textContent=busy?'A field detector run is already queued or running.':ready.length<2?'Needs scoring targets and corners on 2 matches of this game first.':'Takes about 20–40 minutes on a helper. It only switches if targets and corners land closer.';
  slot('field','runs').innerHTML=runs(d.runs,r=>`${placed(r.new)} vs ${r.current?placed(r.current):'none'}`);
  return {needs:!c||ready.length<2};
 }
 function actions(d,o){
  const l=d.latest,c=d.counts||{};
  slot('actions','current').innerHTML=l?`<dl><dt>Latest model</dt><dd>${when(l.created_at)}</dd><dt>Released</dt><dd>${d.released?(d.released===l.id?'Yes, this one':'An earlier model'):'Not yet — evaluate it on Test & release'}</dd></dl>`:'<p>No action model yet.</p>';
  slot('actions','data').innerHTML=`<dl><dt>Tagged matches</dt><dd>${c.matches??0}</dd><dt>Human tags</dt><dd>${c.tags??0}</dd><dt>Scoring estimates</dt><dd>${c.scores??0}</dd><dt>Imported actions</dt><dd>${c.external??0}</dd></dl>`;
  const changed=[['robot',o.robot.current],['field',o.field.current]].filter(([,cur])=>cur&&l&&cur.adopted_at>l.created_at).map(([n])=>n==='robot'?'robot detector':'field detector');
  slot('actions','next').innerHTML=next([
   [!!o.robot.current,'Robot detector trained (tab 1) — tracking follows robots better.'],
   [(c.matches??0)>=10,`Tag at least 10 matches on Watch & tag or import scouting (${c.matches??0} so far).`],
   [!changed.length,changed.length?`The ${changed.join(' and ')} changed after this model was trained — retrain so it learns from the better tracking.`:'Model is up to date with the detectors.'],
   [!!l,l?'Try it on a match, then evaluate it on Test & release.':'Train the first model below.'],
  ]);
  return {needs:!l||changed.length>0};
 }

 async function refresh(){
  let o;try{const r=await fetch(`train.php?overview=1&season=${encodeURIComponent(season)}`,{headers:{Accept:'application/json'}});o=await r.json();if(!r.ok)throw Error(o.error)}catch(e){document.querySelector('#train-status [data-slot="helpers"]').textContent='Status unavailable: '+e.message;return}
  const status=k=>document.querySelector(`#train-status [data-slot="${k}"]`);
  const on=o.helpers.filter(h=>h.online);
  status('helpers').textContent=on.length?on.map(h=>`${h.name} · ${h.working?'working':h.ready?'ready':h.status}`).join('\n'):(o.helpers.length?'No helper open. Open the helper app on your computer.':'No helper connected — see Helpers & settings.');
  const running=o.queue.filter(q=>q.state==='running'),waiting=o.queue.filter(q=>q.state==='waiting');
  status('now').textContent=running.length?running.map(q=>q.message&&q.message.includes(' · step ')?q.message:q.title+(q.message?'\n'+q.message:'')).join('\n\n'):'Nothing';
  status('waiting').textContent=waiting.length?waiting.map((q,i)=>`${i+1}. ${q.title}`).join('\n'):'Nothing';
  const busy=k=>o.queue.some(q=>q.part===k);
  const flags={robot:robot(o.robot,busy('robot')),field:field(o.field,busy('field')),actions:actions(o.actions,o)};
  for(const t of tabs){t.querySelector('.tt-flag')?.remove();if(flags[t.dataset.tab]?.needs){const f=document.createElement('span');f.className='tt-flag';f.title='Needs attention';t.append(f)}}
 }
 for(const b of $$('[data-train]'))b.addEventListener('click',async()=>{b.disabled=true;try{await send({action:'queue_detector',kind:b.dataset.train==='field'?'train_field':'train'});await refresh()}catch(e){alert(e.message);b.disabled=false}});
 for(const b of $$('[data-rollback]'))b.addEventListener('click',async()=>{if(!confirm('Go back to the previous model? The current one is set aside, and helpers switch within an hour.'))return;try{await send({action:'rollback_detector',which:b.dataset.rollback});await refresh()}catch(e){alert(e.message)}});
 refresh();setInterval(()=>{if(!document.hidden)refresh()},10000);
})();
