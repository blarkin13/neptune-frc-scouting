'use strict';
// Auto Scout pillars (Robots, Field, Actions): helper status bar, model in use, what's needed next, Train, past runs.
// Fills whichever pillar cards are on the page. Data and actions go through train.php.
(()=>{
 const app=document.getElementById('pillar');if(!app)return;
 const season=app.dataset.season,csrf=app.dataset.csrf,endpoint=app.dataset.endpoint||'/autoscout/train.php';
 const $$=s=>[...document.querySelectorAll(s)];
 const esc=t=>String(t??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
 const when=t=>t?new Date(t*1000).toLocaleString(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}):'—';
 const pct=v=>v==null?'—':Math.round(v*100)+'%';
 const slot=(name,key)=>document.querySelector(`[data-part="${name}"] [data-slot="${key}"], [data-part="${name}"][data-slot="${key}"]`);
 const fill=(name,key,html)=>{const s=slot(name,key);if(s)s.innerHTML=html};

 // In-page tabs: [data-tabs] holds buttons [data-tab]; panels are [data-panel] in the same pillar. Remembered in the address.
 const tabs=$$('[data-tabs] [data-tab]'),panels=$$('[data-panel]');
 function show(name){
  if(!tabs.length||!tabs[0].closest('[data-tabs]').offsetParent)return;   // no tabs, or on another view of the page
  if(!tabs.some(t=>t.dataset.tab===name))name=tabs[0].dataset.tab;
  for(const t of tabs)t.setAttribute('aria-selected',String(t.dataset.tab===name));
  for(const p of panels)p.hidden=p.dataset.panel!==name;
  if(location.hash!=='#'+name)history.replaceState(null,'','#'+name);
 }
 for(const t of tabs)t.addEventListener('click',()=>show(t.dataset.tab));
 // Older code scrolls to sections (for example the run report); open their tab first.
 const scroll=Element.prototype.scrollIntoView;
 Element.prototype.scrollIntoView=function(...a){const p=this.closest('[data-panel]');if(p&&p.hidden)show(p.dataset.panel);const d=this.closest('details');if(d)d.open=true;return scroll.apply(this,a)};
 show(location.hash.slice(1));
 addEventListener('hashchange',()=>show(location.hash.slice(1)));

 async function send(values){
  const body=new FormData();body.set('csrf',csrf);body.set('season',season);for(const [k,v] of Object.entries(values))body.set(k,v);
  const r=await fetch(endpoint,{method:'POST',body,headers:{Accept:'application/json'}});const d=await r.json().catch(()=>({}));
  if(!r.ok)throw Error(d.error||'Could not save');return d;
 }

 const runState=r=>r.state==='done'?(r.report?.adopted?'<span class="tt-badge ok">Switched to it</span>':'<span class="tt-badge">Kept the one in use</span>'):r.state==='failed'?'<span class="tt-badge warn">Failed</span>':r.state==='cancelled'?'<span class="tt-badge">Cancelled</span>':r.state==='claimed'?'<span class="tt-badge ok">Running</span>':'<span class="tt-badge">Waiting</span>';
 function runs(list,score){
  if(!list.length)return '<p class="training-note">No runs yet.</p>';
  return '<table class="tt-runs"><tr><th>Started</th><th>Result</th><th>New vs in use</th><th>Review video</th><th>Details</th></tr>'+list.slice(0,8).map(r=>`<tr><td>${when(r.created_at)}</td><td>${runState(r)}${['queued','claimed'].includes(r.state)?` <button type="button" class="button-secondary tt-cancel" data-cancel="${esc(r.id)}">Cancel</button>`:''}</td><td>${r.report?score(r.report):'—'}</td><td>${video(r.review)}</td><td class="tt-msg">${esc(r.message)}</td></tr>`).join('')+'</table>';
 }
 // Review video on YouTube (private until the API project passes YouTube's audit).
 const video=v=>!v?'—':v.youtube_id?`<a href="https://youtu.be/${esc(v.youtube_id)}" target="_blank" rel="noopener">▶ Watch</a>${v.privacy==='private'?' <small>(private)</small>':''}`:v.status==='youtube_not_connected'?'<a href="/autoscout/youtube.php">Connect YouTube</a>':v.status==='upload_failed'?`<span title="${esc(v.error)}">Upload failed</span>`:'—';
 const placed=p=>!p||!p.found?'none found':`${p.found}/${p.expected} · ${(p.mean_error*100).toFixed(1)}% off`;
 const next=(items)=>items.map(([ok,text])=>`<li class="${ok?'tt-ok':'tt-todo'}">${text}</li>`).join('');
 function trainButton(name,disabled,note){
  const b=document.querySelector(`[data-part="${name}"] [data-train]`);if(b)b.disabled=disabled;
  const s=slot(name,'train-note');if(s)s.textContent=note;
 }
 // Summary card tabs (What's trained · Your data · What's needed next). Opens on "next" when something needs doing, until you pick one.
 const picked={};
 function sumTab(part,name,user){const card=document.querySelector(`[data-part="${part}"] .tt-sum`);if(!card)return;if(user)picked[part]=true;
  for(const b of card.querySelectorAll('[data-sum]'))b.setAttribute('aria-selected',String(b.dataset.sum===name));
  for(const p of card.querySelectorAll('[data-sum-panel]'))p.hidden=p.dataset.sumPanel!==name}
 document.addEventListener('click',e=>{const b=e.target.closest('[data-sum]');if(b)sumTab(b.closest('[data-part]').dataset.part,b.dataset.sum,true)});
 const rollback=(name,has)=>{for(const b of $$(`[data-rollback="${name}"]`))b.hidden=!has};

 function robot(d,busy){
  const c=d.current,rep=c?.report||{};
  fill('robot','current',c?`<dl><dt>Adopted</dt><dd>${when(c.adopted_at)}</dd><dt>Held-out match</dt><dd>${esc((rep.holdout_matches||[rep.holdout_match||'—']).join(', '))}</dd><dt>Bumpers found</dt><dd>${pct(rep.new?.recall)} (precision ${pct(rep.new?.precision)})</dd><dt>Learned from</dt><dd>${rep.train_frames??'—'} frames, ${(rep.matches||[]).length} matches</dd></dl>`:'<p>The built-in detector that came with the helper. It has not been retrained here yet.</p>');
  rollback('robot',d.history);
  const m=Object.entries(d.matches),ready=m.filter(([,n])=>n>=d.min_frames),frames=m.reduce((a,[,n])=>a+n,0);
  // Frames per match, fewest first: which matches still need frames before training can use them.
  const rows=(d.rows||[]).map(r=>`<tr${r.frames<d.min_frames?' class="tt-short"':''}><td>${r.match_id?`<a href="/autoscout/robot-labels.php?match_id=${r.match_id}">${esc(r.match_key||r.video_id)}</a>`:esc(r.match_key||r.video_id)}${(d.holdouts||[d.holdout]).includes(r.match_key)?' <small>(held out)</small>':''}</td><td>${r.frames}</td><td>${r.frames<d.min_frames?`needs ${d.min_frames-r.frames} more`:'✓ used'}</td></tr>`).join('');
  fill('robot','data',`<dl><dt>Labelled frames</dt><dd>${frames}</dd>${d.external?.images?`<dt>Imported training images</dt><dd>${d.external.images} (${d.external.synthetic_images||0} variations)</dd><dt>Imported bumper boxes</dt><dd>${d.external.boxes}</dd>`:''}<dt>Matches</dt><dd>${m.length} (${ready.length} with ${d.min_frames}+ frames)</dd><dt>Events</dt><dd>${d.events.length?d.events.map(esc).join(', '):'—'}</dd></dl>`+(rows?`<details class="tt-runs-more" open><summary>Frames per match</summary><table class="tt-runs"><tr><th>Match</th><th>Frames</th><th></th></tr>${rows}</table></details>`:''));
  fill('robot','next',next([
   [ready.length>=2,`Label ${d.min_frames}+ frames in at least 2 matches (${ready.length} so far). One is held back for checking: never one the detector in use learned from.`],
   ...(d.holdout?[[true,`Next run is checked on <strong>${esc(d.holdout)}</strong>.`]]:[]),
   [d.events.length>=2,`Label matches from a second event so the detector works beyond one venue (${d.events.length} so far).`],
   [!!c,c?'A trained detector is in use. Label more frames and retrain whenever tracking misses robots.':'Train the first detector.'],
  ]));
  trainButton('robot',busy||ready.length<2,busy?'A robot detector run is already queued or running.':ready.length<2?'Needs labelled frames in 2 matches first.':'About an hour on a helper. It only switches if the new detector is clearly better.');
  fill('robot','runs',runs(d.runs,r=>`F1 ${r.new?.f1?.toFixed(2)??'—'} vs ${r.current?r.current.f1.toFixed(2):'none'}`));
  return !c||ready.length<2;
 }
 function field(d,busy){
  const c=d.current,rep=c?.report||{};
  fill('field','current',c?`<dl><dt>Adopted</dt><dd>${when(c.adopted_at)}</dd><dt>Held-out match</dt><dd>${esc(rep.holdout_match||'—')}</dd><dt>Targets & corners</dt><dd>${placed(rep.new)}</dd><dt>Learned from</dt><dd>${rep.trained_on?.length??rep.matches??'—'} matches</dd></dl>`:d.baseline?`<p>The helper’s built-in ${esc(d.game_key)} field detector is in use. No retrained replacement has passed the held-out check yet.</p>`:`<p>No field detector is available for ${esc(d.game_key)} yet.</p>`);
  rollback('field',d.history);
  const m=Object.entries(d.matches),ready=m.filter(([,v])=>v.targets&&v.boundary);
  fill('field','data',`<dl><dt>Marked matches</dt><dd>${m.length}</dd><dt>With targets + boundary</dt><dd>${ready.length}</dd><dt>Events</dt><dd>${d.events.length?d.events.map(esc).join(', '):'—'}</dd></dl>`);
  fill('field','next',next([
   [ready.length>=2,`Mark scoring targets and a visible field boundary on at least 2 ${esc(d.game_key)} matches (${ready.length} so far). Boundary polygons may have more than four vertices. Physical corners are optional and must be marked separately.`],
   ...(d.holdout?[[true,`Next run is checked on <strong>${esc(d.holdout)}</strong>, a match the detector in use never learned from.`]]:[]),
   // One marked match covers its whole event (the camera is fixed); unmarked events get no guessed zones.
   [true,`Mark one match at each event you scout; its marks apply to every match at that event. Events marked so far: ${d.events.length}.`],
   [true,'Scouting at an event with no marked match runs without scoring-zone hints; the field detector’s guesses are not used.'],
   [true,'Training the field detector is optional; scouting uses your marks, not the detector.'],
  ]));
  trainButton('field',busy||ready.length<2,busy?'A field detector run is already queued or running.':ready.length<2?'Needs scoring targets and a visible boundary on 2 matches of this game first.':'Uses your saved marks and the current field detector. Runtime depends on the helper. Only a better held-out result replaces it.');
  fill('field','runs',runs(d.runs,r=>`${placed(r.new)} vs ${r.current?placed(r.current):'none'}`));
  return !c||ready.length<2;
 }
 // Tracking: the robot-identity model trains on the helper where tracking is reviewed, after every saved review,
 // and is shared with every helper only once it passes the held-out identity check there. No Train button.
 function tracking(d){
  d=d||{};const r=d.report,s=d.shared,v=r?.validation||{},iv=s?.identity_validation||r?.identity_validation||{},reviews=d.reviews||[];
  fill('tracking','current',s?`<dl><dt>Shared since</dt><dd>${when(s.at)}</dd><dt>Trained on</dt><dd>${esc(s.trained_by)}</dd><dt>Identity check</dt><dd>${pct(iv.precision)} of names right${iv.points?` on ${iv.points} reviewed boxes`:''}</dd><dt>Learned from</dt><dd>${(s.matches||[]).map(esc).join(', ')||'—'}</dd></dl>`
   :'<p>No shared identity model yet. Robots are named from bumper reads and the tracker’s own rules until one passes its check.</p>');
  fill('tracking','data',`<dl><dt>Reviewed matches on Neptune</dt><dd>${reviews.length?reviews.map(x=>esc(x.match_key)).join(', '):'none yet'}</dd>${r?`<dt>Latest report</dt><dd>${esc(r.computer)}, ${when(r.at)}</dd><dt>Same-robot pairs</dt><dd>${r.pairs??0} from ${(r.matches||[]).length} matches</dd>${v.tp!=null?`<dt>Pair check (held-out match)</dt><dd>${v.tp} linked, ${v.fp} wrong links, ${v.fn} missed</dd>`:''}`:''}</dl>`);
  const matches=(r?.matches||[]).length,eligible=v.tp>=30&&v.fp===0;
  fill('tracking','next',next([
   [matches>=3,`Review tracking on at least 3 matches in the helper (${matches} so far). Correct every box you watch, including the ones it named.`],
   [eligible,eligible?'Pair check passed on held-out matches.':'The pair check needs 30+ same-robot links with none wrong, on matches it did not learn from.'],
   [!!s,s?'The model passed the identity check and is shared with every helper.':'The identity check needs 98% of names right on held-out reviewed matches, and at least 150 right.'],
   [true,'Test a match below to watch detection, tracking and bumper reads together.'],
  ]));
  const st=slot('tracking','training-status');if(st)st.textContent=r?`Trains on ${r.computer} after each saved tracking review.`:'Trains on the helper where you review tracking, after each saved review.';
  return !s;
 }
 function actions(d,o,busy){
  const l=d.latest,c=d.counts||{};
  fill('actions','current',l?`<dl><dt>Latest model</dt><dd>${when(l.created_at)}</dd><dt>Released</dt><dd>${d.released?(d.released===l.id?'Yes, this one':'An earlier model'):'Not yet — evaluate it on Test & release'}</dd></dl>`:'<p>No action model yet.</p>');
  fill('actions','data',`<dl><dt>Tagged matches</dt><dd>${c.matches??0}</dd><dt>Human tags</dt><dd>${c.tags??0}</dd><dt>Scoring estimates</dt><dd>${c.scores??0}</dd><dt>Imported actions</dt><dd>${c.external??0}</dd></dl>`);
  const changed=[['robot',o.robot.current],['field',o.field.current]].filter(([,cur])=>cur&&l&&cur.adopted_at>l.created_at).map(([n])=>n==='robot'?'robot detector':'field detector');
  fill('actions','next',next([
   [!!o.robot.current,'Robots pillar trained — tracking follows robots better.'],
   [(c.matches??0)>=10,`Tag at least 10 matches or import scouting (${c.matches??0} so far).`],
   [!changed.length,changed.length?`The ${changed.join(' and ')} changed after this model was trained — retrain so it learns from the better tracking.`:'Model is up to date with the detectors.'],
   [!!l,l?'Try it on a match, then evaluate it on Test & release.':'Train the first model below.'],
  ]));
  fill('actions','runs','<p class="training-note">Every run with its report is under <a href="#actions-results" onclick="document.getElementById(\'actions-results\').open=true">Training results</a> below.</p>');
  trainButton('actions',busy,busy?'An Actions run is already queued or running.':'Pick the computer and how many matches in the next window.');
  return !l||changed.length>0;
 }

 // Each connected computer is a self-contained management card, not a row in one shared panel.
 const kinds={robot:'Robot detector',field:'Field detector',prepare:'Prepare frames',actions:'Actions training',test:'Match test',evaluation:'Evaluation'};
 const ago=t=>{if(!t)return 'never';const s=Math.max(0,Date.now()/1000-t);return s<90?'just now':s<3600?Math.round(s/60)+' min ago':s<86400?Math.round(s/3600)+' h ago':when(t)};
 const usageMeter=(label,percent,display)=>{
  const n=Number(percent),usable=percent!=null&&Number.isFinite(n),value=usable?Math.max(0,Math.min(100,n)):0;
  return `<div class="tt-computer-meter"><div class="tt-computer-meter-label"><span>${label}</span><b>${esc(display)}</b></div><div class="tt-computer-meter-track" role="meter" aria-label="${label}" aria-valuemin="0" aria-valuemax="100" ${usable?'aria-valuenow="'+value.toFixed(0)+'"':'aria-valuetext="unavailable"'}><span style="width:${value}%"></span></div></div>`;
 };
 function computerCard(c){
  const g=c.stats?.gpu,st=c.stats||{},working=!!c.job,online=!!c.online;
  const state=working?'Training':online?'Available':'Offline',stateClass=working?'working':online?'available':'offline';
  const isMac=/mac|apple/i.test(c.platform||c.device||'');
  const device=String(c.platform||c.device||'Computer').replace(/ · CPU$/,'');
  const gpuName=g?.name&&!device.includes(g.name)?g.name.replace(/^NVIDIA GeForce /,''):'';
  const version=c.version?'Helper '+c.version:'Helper version unavailable';
  const verdict=c.selfcheck?.summary?.verdict;
  const ramPct=st.ram_total_mb?100*st.ram_used_mb/st.ram_total_mb:null;
  const vramPct=g?.mem_total_mb?100*g.mem_used_mb/g.mem_total_mb:null;
  const stats=c.stats?`<div class="tt-computer-meters" aria-label="System resources">
    ${usageMeter('CPU',st.cpu,st.cpu!=null?st.cpu+'%':'—')}
    ${usageMeter('GPU',g?.util,g?.util!=null?g.util+'%':'—')}
    ${usageMeter('RAM',ramPct,st.ram_total_mb?gb(st.ram_used_mb)+' / '+gb(st.ram_total_mb)+' GB':'—')}
    ${usageMeter('VRAM',vramPct,g?.mem_total_mb?gb(g.mem_used_mb)+' / '+gb(g.mem_total_mb)+' GB':'—')}
   </div>`:`<p class="tt-computer-no-stats">${c.polls?'Waiting for the first hardware reading.':'Live CPU and GPU readings require an updated helper.'}</p>`;
  const stop=working?(c.job.request?`<button type="button" class="button-secondary" data-cancel="${esc(c.job.request)}">Stop</button>`:c.job.job?`<button type="button" class="button-secondary" data-stop-job="${esc(c.job.job)}">Stop after match</button><button type="button" class="button-secondary" data-cancel-job="${esc(c.job.job)}">Cancel now</button>`:''):'';
  const waiting=(c.waiting||[]).length?`<div class="tt-computer-queue"><span class="tt-computer-subheading">Queued on this helper</span>${c.waiting.map(w=>`<div>${esc(kinds[w.kind]||w.kind||'Training job')} <button type="button" class="button-secondary tt-cancel" data-cancel="${esc(w.request)}">Cancel</button></div>`).join('')}</div>`:'';
  const task=c.job?`<div class="tt-computer-job"><span class="tt-computer-subheading">Running now</span><strong>${esc(kinds[c.job.kind]||c.job.kind||'Training')}</strong></div>`:`<div class="tt-computer-job tt-computer-job--idle"><span class="tt-computer-subheading">Current work</span><span>${online?'Ready for a training job':'Open the helper app to reconnect'}</span></div>`;
  const start=working?'':`<button type="button" class="tt-icon-action" data-start="train" data-computer="${esc(c.id)}" title="Train robot detector" aria-label="Train robot detector on ${esc(c.name)}"><i class="fa-solid fa-robot" aria-hidden="true"></i><span>Robots</span></button><button type="button" class="tt-icon-action" data-start="train_field" data-computer="${esc(c.id)}" title="Train field detector" aria-label="Train field detector on ${esc(c.name)}"><i class="fa-solid fa-draw-polygon" aria-hidden="true"></i><span>Field</span></button><a class="tt-icon-action" href="/autoscout/train.php?connect=1&amp;computer=${encodeURIComponent(c.id)}#train" title="Train actions" aria-label="Train actions on ${esc(c.name)}"><i class="fa-solid fa-bolt" aria-hidden="true"></i><span>Actions</span></a>`;
  const awake=`<label class="tt-awake">Keep awake <select data-awake="${esc(c.id)}">${[['jobs','During jobs'],['always','Always while open'],['off','Off']].map(([v,l])=>`<option value="${v}"${c.settings?.keep_awake===v?' selected':''}>${l}</option>`).join('')}</select></label><small>${!c.polls?'Needs the updated helper':online?(c.awake?'Awake now':'May sleep when idle'):''}</small>`;
  const u=c.update||{};
  // Wake: another helper on the same home network sends this sleeping computer a Wake-on-LAN packet.
  const wakeHtml=online||!c.polls?'':`<div class="tt-computer-update">${c.settings?.wake_requested&&Date.now()/1000-c.settings.wake_requested<900?`<span class="tt-badge ok">Wake sent · waiting for it to check in</span><button type="button" class="button-secondary tt-cancel" data-wake="cancel" data-computer="${esc(c.id)}">Cancel</button>`:`<button type="button" class="button-secondary" data-wake="request" data-computer="${esc(c.id)}">Wake</button><span class="training-note">Needs another helper running on the same home network.</span>`}</div>`;
  const upd=!u.remote?`<div class="tt-computer-update"><p class="training-note">${u.behind?`Update ${esc(u.latest)} available · update this helper manually to enable future remote updates.`:'Remote updates are enabled by the next helper version.'}</p></div>`
   :`<div class="tt-computer-update">${u.requested?`<span class="tt-badge ok">${esc(u.message||'Update requested · installs when idle')}</span><button type="button" class="button-secondary tt-cancel" data-update="cancel" data-computer="${esc(c.id)}">Cancel update</button>`:u.behind?`<span class="tt-badge warn">Update ${esc(u.latest)} available</span><button type="button" class="button-secondary" data-update="request" data-computer="${esc(c.id)}">Update</button>`:`<span class="training-note">Up to date${u.latest?' · '+esc(u.latest):''}</span>`}
    <label class="tt-auto-update"><input type="checkbox" role="switch" data-auto-update="${esc(c.id)}"${u.auto?' checked':''}><span class="tt-auto-update-track" aria-hidden="true"></span><span>Automatic updates</span></label></div>`;
  const check=c.selfcheck,checkHtml=check?`<details class="tt-selfcheck"><summary>Hardware &amp; training check <small>${when(check.checked_at)}</small></summary><p class="training-note">${esc(check.summary?.verdict||'Check results')}</p><div class="tt-computer-check-scroll"><table class="tt-runs">${(check.checks||[]).map(x=>`<tr><td>${x.ok?'✓':'⚠'}</td><td>${esc(x.check)}</td><td class="tt-msg">${esc(x.detail)}</td></tr>`).join('')}</table></div></details>`:'<p class="tt-computer-no-check">Hardware checks appear after this helper starts.</p>';
  return `<article class="tt-computer tt-computer--${stateClass}">
   <header class="tt-computer-head"><span class="tt-computer-device-icon"><i class="fa-solid ${isMac?'fa-laptop':'fa-desktop'}" aria-hidden="true"></i></span>
    <div class="tt-computer-identity"><button type="button" class="tt-name" data-edit-name="${esc(c.id)}" aria-label="Rename ${esc(c.name)}">${esc(c.name)} <i class="fa-solid fa-pen" aria-hidden="true"></i></button><form class="tt-name-form" data-name-form="${esc(c.id)}" hidden><input aria-label="Computer name" maxlength="80" value="${esc(c.name)}" data-original="${esc(c.name)}"><button type="submit" hidden>Save</button><button type="button" data-cancel-name>Cancel</button></form><small>${esc(device)}</small></div>
    <span class="tt-computer-status tt-computer-status--${stateClass}"><i aria-hidden="true"></i>${state}</span></header>
   <div class="tt-computer-facts"><span>${esc(version)}</span>${gpuName?`<span title="Graphics processor">${esc(gpuName)}</span>`:''}<span>${online?'Last check-in '+ago(c.reported_at||c.last_seen):'Last seen '+ago(c.last_seen)}</span></div>
   ${task}${stats}${waiting}
   <div class="tt-computer-operations"><span class="tt-computer-subheading">Training controls</span><div class="training-actions tt-model-actions">${start}${stop}${!online&&!working?`<button type="button" class="button-secondary tt-computer-remove" data-remove="${esc(c.id)}" data-name="${esc(c.name)}">Remove</button>`:''}</div></div>
   <div class="tt-computer-settings">${checkHtml}${upd}<div class="tt-computer-awake">${awake}</div>${wakeHtml}</div>
  </article>`;
 }
 document.addEventListener('click',e=>{const b=e.target.closest('[data-edit-name],[data-cancel-name]');if(!b)return;const card=b.closest('.tt-computer'),form=card.querySelector('[data-name-form]'),name=card.querySelector('[data-edit-name]');form.hidden=b.hasAttribute('data-cancel-name');name.hidden=!form.hidden;if(!form.hidden){const input=form.querySelector('input');input.focus();input.select()}});
 document.addEventListener('input',e=>{const input=e.target.closest('[data-name-form] input');if(input)input.closest('form').querySelector('[type="submit"]').hidden=input.value.trim()===input.dataset.original||!input.value.trim()});
 document.addEventListener('submit',async e=>{const form=e.target.closest('[data-name-form]');if(!form)return;e.preventDefault();const input=form.querySelector('input'),name=input.value.trim();if(!name||name===input.dataset.original)return;const save=form.querySelector('[type="submit"]');save.disabled=true;try{await send({action:'rename_computer',computer:form.dataset.nameForm,name});document.activeElement.blur();await refresh()}catch(err){alert(err.message);save.disabled=false}});
 document.addEventListener('click',e=>{if(e.target.closest('[data-linux-install]'))document.getElementById('linux-install-modal')?.showModal();if(e.target.closest('[data-linux-close]'))document.getElementById('linux-install-modal')?.close()});
 document.addEventListener('click',async e=>{
  const b=e.target.closest('[data-start],[data-stop-job],[data-cancel-job],[data-remove],[data-update]');if(!b)return;
  try{
   if(b.dataset.update==='all'){const ids=[...document.querySelectorAll('[data-update="request"]')].map(x=>x.dataset.computer);if(!ids.length){alert('Every computer that can update remotely is up to date.');return}
    if(!confirm(`Update ${ids.length} computer${ids.length>1?'s':''}? Each helper downloads the newest version, installs it once it has no job running, then reopens.`))return;b.disabled=true;for(const id of ids)await send({action:'computer_settings',computer:id,update:'request'})}
   else if(b.dataset.update){b.disabled=true;await send({action:'computer_settings',computer:b.dataset.computer,update:b.dataset.update})}
   else if(b.dataset.start){b.disabled=true;await send({action:'queue_detector',kind:b.dataset.start,computer:b.dataset.computer,match_only:document.getElementById('match-video-only')?.checked?'1':''})}
   else if(b.dataset.remove){if(!confirm(`Remove ${b.dataset.name} from your computers? It would need to connect again to come back.`))return;await send({action:'delete_computer',computer:b.dataset.remove})}
   else if(b.dataset.cancelJob){if(!confirm('Cancel this run now? Use this when the helper is closed or stuck. Saved video work and human tags are kept.'))return;await send({action:'cancel',job:b.dataset.cancelJob})}
   else if(b.dataset.stopJob){if(!confirm('Stop this run after the current match? Progress is saved and it can be continued later.'))return;await send({action:'stop',job:b.dataset.stopJob})}
   b.blur();await refresh();
  }catch(err){alert(err.message);b.disabled=false}
 });
 document.addEventListener('change',async e=>{const a=e.target.closest('[data-auto-update]');if(a){try{await send({action:'computer_settings',computer:a.dataset.autoUpdate,auto_update:a.checked?'true':'false'});a.blur();await refresh()}catch(err){alert(err.message)}return}});
 document.addEventListener('click',async e=>{const w=e.target.closest('[data-wake]');if(!w)return;w.disabled=true;try{await send({action:'computer_settings',computer:w.dataset.computer,wake:w.dataset.wake});await refresh()}catch(err){alert(err.message);w.disabled=false}});
 document.addEventListener('change',async e=>{const s=e.target.closest('[data-awake]');if(!s)return;try{await send({action:'computer_settings',computer:s.dataset.awake,keep_awake:s.value});s.blur();await refresh()}catch(err){alert(err.message)}});

 const tbar=(p,w=22)=>{p=Math.max(0,Math.min(100,p||0));const cells=p/100*w,full=Math.floor(cells),part=' ▏▎▍▌▋▊▉'[Math.floor((cells-full)*8)];return '▕'+'█'.repeat(full)+(full<w?part+' '.repeat(w-full-1):'')+'▏'};
 const gb=mb=>mb==null?'?':(mb/1024).toFixed(mb<10240?1:0);
 const clock=s=>{s=Math.max(0,Math.round(s));return (s>=3600?Math.floor(s/3600)+':':'')+String(Math.floor(s/60)%60).padStart(s>=3600?2:1,'0')+':'+String(s%60).padStart(2,'0')};
 // Test & release as four steps: model → preview → score (live checklist, eligible answer-key matches) → release.
 const keyCache={};
 async function testFlow(o){
  const menu=document.getElementById('trial-model');if(!menu||!slot('test','gates'))return;
  const id=menu.value,m=(o.actions.models||[]).find(x=>x.id===id),released=o.actions.released&&o.actions.released===id;
  const newer=[['robot detector',o.robot.current],['field detector',o.field.current]].filter(([,c])=>c&&m&&c.adopted_at>m.created_at).map(([n])=>n);
  fill('test','model-info',m?`<dl><dt>Trained</dt><dd>${when(m.created_at)}</dd><dt>Status</dt><dd>${released?'Released':m.evaluated?'Passed its checks — ready to release':'Experimental — not yet scored'}</dd></dl>`+(newer.length?`<p class="tr-warn">⚠ The ${newer.join(' and ')} changed after this model was trained. Its results may be off; retrain on 4 · Actions for a fair test.</p>`:''):'');
  const vids=o.test_videos||[];fill('test','latest-video',vids[0]?`<p class="training-note">Latest match test: ${esc(vids[0].match_key||'')} · ${video(vids[0])} <small>(${when(vids[0].saved_at)})</small></p>`:'');
  fill('test','release-state',released?'✓ This model is what Auto Scout uses now.':m?.evaluated?'All checks passed. Review the report, then release.':o.actions.released?'Another model is released. This one unlocks when its checks pass.':'Release unlocks when every check in step 3 passes.');
  const done={model:!!m,preview:vids.length>0,score:!!m?.evaluated,release:!!released};
  for(const li of $$('.tr-steps [data-step]'))li.dataset.done=String(done[li.dataset.step]);
  if(!m){fill('test','gates','<p class="training-note">Choose a model to see its checks.</p>');return}
  let k=keyCache[id];
  if(!k){try{const r=await fetch(`${endpoint}?answer_key_candidates=${encodeURIComponent(id)}&season=${encodeURIComponent(season)}`,{headers:{Accept:'application/json'}});k=await r.json();if(!r.ok)throw Error(k.error);keyCache[id]=k}catch(e){fill('test','gates',`<p class="training-note">Checks unavailable: ${esc(e.message)}</p>`);return}}
  const tick=(ok,text)=>`<li class="${ok?'tt-ok':'tt-todo'}">${text}</li>`;
  fill('test','gates','<ul class="tt-next">'+
   tick(k.eligible>=k.need.matches,`Answer-key matches: <b>${k.eligible}</b> of ${k.need.matches} (all six robots tagged, from events this model never trained on)`)+
   tick(k.eligible_events>=k.need.events,`Events: <b>${k.eligible_events}</b> of ${k.need.events}`)+
   Object.entries(k.by_tag).map(([t,n])=>tick(n>=k.need.per_tag,`<code>${esc(t)}</code>: <b>${n}</b> of ${k.need.per_tag} reference actions`)).join('')+
   tick(!!m.evaluated,m.evaluated?'Scored: precision ≥ 90% and recall ≥ 80% on every action':'Scored against the answer key (precision ≥ 90%, recall ≥ 80% per action)')+'</ul>');
  fill('test','answer-key',`<p class="training-note">Trained on events: ${k.trained_events.map(esc).join(', ')||'—'}. Matches from those events can't be used.</p><table class="tt-runs"><tr><th>Match</th><th>Robots tagged</th><th>Tags</th><th></th></tr>`+
   k.matches.slice(0,60).map(x=>`<tr${x.eligible?'':' class="tt-short"'}><td>${esc(x.match_key)}</td><td>${x.robots_tagged}/6</td><td>${x.tags}</td><td>${x.eligible?'✓ usable':x.trained_event?'trained event':'tag all six robots'}</td></tr>`).join('')+'</table>');
 }
 document.addEventListener('change',e=>{if(e.target.id==='trial-model')refresh()});
 // Status area: each part collapses from its heading; remembered in this browser.
 const folded=(()=>{try{return JSON.parse(localStorage.getItem('neptune.status.folded')||'{}')}catch{return {}}})();
 function fold(sec,on){sec.classList.toggle('ts-folded',on);sec.querySelector('.ts-toggle')?.setAttribute('aria-expanded',String(!on))}
 for(const sec of $$('#pillar-status [data-sec]'))fold(sec,!!folded[sec.dataset.sec]);
 document.addEventListener('click',e=>{const b=e.target.closest('.ts-toggle');if(!b)return;const sec=b.closest('[data-sec]'),on=!sec.classList.contains('ts-folded');fold(sec,on);folded[sec.dataset.sec]=on;try{localStorage.setItem('neptune.status.folded',JSON.stringify(folded))}catch{}});
 document.addEventListener('click',async e=>{const b=e.target.closest('.ts-fullscreen');if(!b)return;const sec=b.closest('[data-sec="now"]');if(document.fullscreenElement===sec)await document.exitFullscreen();else{fold(sec,false);await sec.requestFullscreen()}});
 document.addEventListener('fullscreenchange',()=>{const b=document.querySelector('.ts-fullscreen');if(!b)return;const on=document.fullscreenElement===b.closest('[data-sec]');b.setAttribute('aria-label',on?'Exit full screen':'Expand Running now to full screen');b.title=on?'Exit full screen':'Full screen';b.querySelector('i').className='fa-solid '+(on?'fa-compress':'fa-expand')});
 document.addEventListener('click',async e=>{const b=e.target.closest('[data-log]');if(!b)return;const box=b.closest('[data-log-key]'),key=box.dataset.logKey,book=(window.__neptuneLogs||{})[key]||[];
  const text=book.map(([t,l])=>new Date(t*1000).toISOString().replace('T',' ').slice(0,19)+'  '+l).join('\n');
  if(b.dataset.log==='pause'){const st=(window.__neptuneLogState||(window.__neptuneLogState={}))[key]||(window.__neptuneLogState[key]={});st.paused=!st.paused;b.textContent=st.paused?'Resume scroll':'Pause scroll';if(!st.paused){const l=box.querySelector('.tc-log');l.scrollTop=l.scrollHeight}}
  else if(b.dataset.log==='copy'){try{await navigator.clipboard.writeText(text);b.textContent='Copied ✓';setTimeout(()=>b.textContent='Copy log',1500)}catch{alert('Copy is blocked here; use Download log.')}}
  else{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([text+'\n'],{type:'text/plain'}));a.download='neptune-'+key.slice(0,12)+'.log';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1000)}});
 async function refresh(){
  const status=k=>document.querySelector(`#pillar-status [data-slot="${k}"]`);
  let o;try{const r=await fetch(`${endpoint}?overview=1&season=${encodeURIComponent(season)}`,{headers:{Accept:'application/json'}});o=await r.json();if(!r.ok)throw Error(o.error)}catch(e){if(status('helpers'))status('helpers').textContent='Status unavailable: '+e.message;return}
  const comps=[...(o.computers||[])].sort((a,b)=>a.name.localeCompare(b.name)||a.id.localeCompare(b.id)),online=comps.filter(c=>c.online);
  const running=o.queue.filter(q=>q.state==='running'),waiting=o.queue.filter(q=>q.state==='waiting');
  window.__neptuneFast=running.length>0;
  const count=(k,t)=>{const e=status(k+'-count');if(e)e.textContent=t};
  count('now',running.length?running.length+' active':'idle');
  const mini=(k,t)=>{const e=status(k+'-mini');if(e)e.textContent=t};
  mini('now',running.map(q=>{const p=(q.message||'').match(/Progress: (\d+)%/);return (q.label||q.title).replace(/^.*? — /,'')+(p?' · '+p[1]+'%':'')}).join('  ·  '));
  mini('helpers',online.map(c=>c.name+(c.job?' · training':'')+(c.stats?.gpu?.util!=null?' · GPU '+c.stats.gpu.util+'%':'')).join('  ·  '));count('helpers',online.length+' online');count('waiting',waiting.length+' waiting');
  const ago=t=>{if(!t)return 'never';const s=Math.max(0,Date.now()/1000-t);return s<90?Math.round(s)+'s ago':s<3600?Math.round(s/60)+' min ago':Math.round(s/3600)+' h ago'};
  const meter=(label,pct,value,hot)=>`<div class="hm"><div class="hm-top"><span>${label}</span><span>${value}</span></div><div class="hm-bar"><i class="${hot?'hot':''}" style="width:${pct==null?0:Math.max(0,Math.min(100,pct))}%"></i></div></div>`;
  const pct=(u,t)=>u!=null&&t?100*u/t:null;
  const runner=q=>comps.find(c=>c.job&&((q.request&&c.job.request===q.request)||(q.job&&c.job.job===q.job)));
  const kinds={robot:'Robot detector',field:'Field detector',prepare:'Prepare frames',actions:'Actions & scoring',test:'Match test',evaluation:'Evaluation'};
  // Connected helpers: one card per computer. Status (Training / Available / Offline) is separate from system load.
  function helperCard(c){const st=c.stats||{},g=st.gpu,q=c.job&&o.queue.find(x=>(x.request&&x.request===c.job.request)||(x.job&&x.job===c.job.job));
   const stepNo=((q?.message||'').match(/step (\d+ of \d+)/)||[])[1];
   const gpuCap=g?.name&&/nvidia|rtx|gtx|geforce/i.test(g.name)?'GPU training':c.selfcheck?.summary?.verdict?.startsWith('GPU ready')?'GPU training':'CPU training';   // Macs train on the CPU (Apple-GPU training hangs with this PyTorch build)
   const badge=c.job?'<span class="hc-badge on">Training</span>':c.online?'<span class="hc-badge">Available</span>':'<span class="hc-badge off">Offline</span>';
   const meters=c.stats?[meter('CPU',st.cpu,st.cpu!=null?st.cpu+'%':'—'),meter('GPU',g?.util,g?.util!=null?g.util+'%':'—',!!c.job),
     meter('RAM',pct(st.ram_used_mb,st.ram_total_mb),st.ram_total_mb?`${gb(st.ram_used_mb)} / ${gb(st.ram_total_mb)} GB`:'—'),
     g?.mem_total_mb?meter('VRAM',pct(g.mem_used_mb,g.mem_total_mb),`${gb(g.mem_used_mb)} / ${gb(g.mem_total_mb)} GB`):g?.mem_used_mb!=null?meter('GPU memory',null,gb(g.mem_used_mb)+' GB'):meter('VRAM',null,'—')].join('')
     :`<p class="hc-note">${c.polls?'Waiting for its first reading…':'No live readings: update this helper to see CPU, GPU and memory.'}</p>`;
   return `<div class="hc${c.online?'':' hc-off'}"><div class="hc-head"><i class="fa-solid ${(c.platform||'').startsWith('Mac')?'fa-laptop':'fa-desktop'}" aria-hidden="true"></i><div><strong>${esc(c.name)}</strong><small>${esc((c.platform||c.device||'Computer').replace(/ · CPU$/,''))}${g?.name&&!(c.platform||'').includes(g.name)?' · '+esc(g.name.replace(/^NVIDIA GeForce /,'')):''} · ${gpuCap}${c.version?' · helper '+esc(c.version):''}</small></div>${badge}</div>
    <div class="hc-meters">${meters}</div><div class="hc-foot"><span>${c.job?`Job: ${esc(kinds[c.job.kind]||c.job.kind)}${stepNo?' · Step '+stepNo.replace(' of ','/'):''}`:'No assigned job'}${c.awake?' · kept awake':''}</span><span title="Last check-in">Seen ${ago(c.reported_at||c.last_seen)}</span></div></div>`}
  const hs=status('helpers');
  if(hs)hs.innerHTML=comps.length?comps.filter(c=>c.online||(Date.now()/1000-(c.last_seen||0))<86400).map(helperCard).join('')||'<p class="hc-note">No helper open. Open the helper app on a computer.</p>':'<p class="hc-note">No helper connected — see Helpers &amp; settings.</p>';
  // Running now: one console per job.
  const logs=window.__neptuneLogs||(window.__neptuneLogs={}),keep=window.__neptuneLogState||(window.__neptuneLogState={});
  const level=l=>/fail|error|could not|refused|unavailable|timed out/i.test(l)?'err':/warn|skipp|retry|slow|missing/i.test(l)?'warn':/finish|saved|adopted|done|complete|kept|passed|✓/i.test(l)?'ok':'info';
  const matchOf=t=>(String(t).match(/\b(\d{4}[a-z0-9]+_[a-z0-9]+)\b/)||[])[1];
  const job=(q,c)=>{const key=q.request||q.job||q.title,lines=(q.message||'').split('\n');const get=p=>(lines.find(l=>l.startsWith(p))||'').slice(p.length).trim();
   const step=(lines.find(l=>l.includes(' · step '))||'').replace(/^.*? · step (\d+ of \d+): ?/,'Step $1 · ');const prog=get('Progress:').match(/(\d+)%(?: · (.*))?/);
   let now=get('Now:').replace(/^Training (robot|field) detector: /,''),all=prog?+prog[1]:null,eta=prog?prog[2]:null;
   const pass=!prog&&now.match(/pass (\d+) of up to (\d+)(?: · batch (\d+) of (\d+))?/);
   if(pass){const f=((+pass[1]-1)+(pass[3]?pass[3]/pass[4]:0))/pass[2];all=Math.round(100*(.1+.82*f));
    const r=q.started?Date.now()/1000-q.started:0;if(r>60&&all>3){const s=r/(all/100)*(1-all/100);eta=(s<3600?'about '+Math.round(s/60)+' min':'about '+(s/3600).toFixed(1)+' h')+' left'}}
   // Keep every line seen this visit (the helper sends its last 14), for scrolling back, copy and download.
   const book=logs[key]||(logs[key]=[]);for(const [t,l] of (c?.log||[]))if(t>=(q.started||q.since||0)&&!book.some(([a,b])=>a===t&&b===l))book.push([t,l]);book.sort((a,b)=>a[0]-b[0]);if(book.length>800)book.splice(0,book.length-800);
   // Stage progress: detector passes/batches, or matches prepared ([i/n]) plus the part of this match, from the helper's own lines.
   const k=c?.counters||{};let stage=null,stageText='';
   if(k.pass&&k.passes){stage=100*((k.pass-1)+(k.batches?k.batch/k.batches:0))/k.passes;stageText=`pass ${k.pass}/${k.passes} · batch ${k.batch}/${k.batches??'?'}${k.it_s?` · ${k.it_s} it/s`:''}`}
   else{const all2=[...book.map(x=>x[1]),now].join('\n'),m=k.matches?[null,k.match,k.matches]:[...all2.matchAll(/\[(\d+)\/(\d+)\]/g)].pop();if(m){let part=0;const fr=now.match(/frames \d+\D(\d+) of (\d+)/),vs=now.match(/(\d+) of (\d+) video seconds/);if(fr)part=.3*fr[1]/fr[2];else if(vs)part=.3+.4*vs[1]/vs[2];stage=100*((m[1]-1)+part)/m[2];stageText=`match ${m[1]} of ${m[2]}${fr?` · reading frames ${fr[1]}/${fr[2]}`:vs?` · tracking ${vs[1]}/${vs[2]} s`:''}`}
    else{const src=[now,...book.map(x=>x[1]).reverse()].find(l=>/frames \d+\D\d+ of \d+|\d+ of \d+ video seconds/.test(l))||'',fr=src.match(/frames \d+\D(\d+) of (\d+)/),vs=src.match(/(\d+) of (\d+) video seconds/),what=(src.match(/·\s*([A-Z][a-z]+(?: the [a-z ]+?| robot [a-z]+)?)\s*·/)||[])[1];
     if(vs){stage=100*vs[1]/vs[2];stageText=`this match · tracking ${vs[1]}/${vs[2]} s`}else if(fr){stage=100*fr[1]/fr[2];stageText=`this match · ${(what||'reading').toLowerCase()} ${fr[1]}/${fr[2]} frames`}}}
   const ran=(q.started||q.since)?Date.now()/1000-(q.started||q.since):null,match=matchOf(now)||matchOf(book.length?book[book.length-1][1]:'');
   const bar=(label,p,text)=>`<div class="tc-row"><span class="tc-k">${label}</span> ${tbar(p,26)} <b>${p==null?'  —':String(Math.round(p)).padStart(3)+'%'}</b>  <span class="tc-dim">${text}</span></div>`;
   const st=c?.stats||{},g=st.gpu,sys=c?.stats?[g?`GPU ${g.util??'?'}%`:'',g?.mem_used_mb!=null?`VRAM ${gb(g.mem_used_mb)}${g.mem_total_mb?'/'+gb(g.mem_total_mb):''} GB`:'',g?.temp_c!=null?g.temp_c+'°C':'',st.cpu!=null?`CPU ${st.cpu}%`:'',st.ram_total_mb?`RAM ${gb(st.ram_used_mb)}/${gb(st.ram_total_mb)} GB`:''].filter(Boolean).join('  ·  '):'';
   const logHtml=book.map(([t,l])=>`<div class="tl tl-${level(l)}"><span class="tc-t">${new Date(t*1000).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',second:'2-digit'})}</span> ${esc(l)}</div>`).join('');
   return `<div class="tt-job tt-console" data-log-key="${esc(key)}"><div class="tc-head"><span><i class="tc-dot"></i>${esc(q.label||q.title)}</span><span>${c?esc(c.name)+(g?.name?' · '+esc(g.name.replace(/^NVIDIA GeForce /,'')):''):'waiting for its helper'}</span></div>`+
    `<div class="tc-title">${esc(step||q.title)}${match?` · <b>${esc(match)}</b>`:''}</div>`+`<div class="tc-sys">${sys||'no live readings from this helper yet'}</div>`+
    `<div class="tc-bars">${bar('stage',stage,stageText||esc(now||'—'))}${bar('overall',all,`${ran?clock(ran)+' elapsed':''}${eta?' · '+esc(eta.replace(/^about /,'~')):all!=null?' · estimating':''}`)}</div>`+
    `<div class="tc-dim tc-next">${get('Next:')?'next → '+esc(get('Next:')):'&nbsp;'}</div>`+
    `<div class="tc-logbar"><span>log · ${book.length} lines</span><span><button type="button" data-log="pause">${keep[key]?.paused?'Resume scroll':'Pause scroll'}</button><button type="button" data-log="copy">Copy log</button><button type="button" data-log="download">Download log</button></span></div>`+
    `<div class="tc-log" tabindex="0" aria-label="Helper log">${logHtml||'<div class="tl tl-info">Waiting for the helper’s first lines…</div>'}<div class="tc-cursor">▌</div></div>`+
    `<div class="tc-foot">${q.about?esc(q.about):''}${q.job?` <button type="button" class="button-secondary" data-cancel-job="${esc(q.job)}">Cancel this run</button>`:q.request?` <button type="button" class="button-secondary" data-cancel="${esc(q.request)}">Cancel this run</button>`:''}</div></div>`};
  const nowSlot=status('now');
  if(nowSlot){
   const active=new Set(running.map(q=>String(q.request||q.job||q.title)));
   for(const key of Object.keys(logs))if(!active.has(key)){delete logs[key];delete keep[key]}
   // Remember where each log was scrolled; follow new lines unless scrolled up or paused.
   for(const el of nowSlot.querySelectorAll('[data-log-key] .tc-log')){const key=el.closest('[data-log-key]').dataset.logKey,st=keep[key]||(keep[key]={});st.top=el.scrollTop;st.bottom=el.scrollHeight-el.scrollTop-el.clientHeight<40}
   nowSlot.innerHTML=running.length?running.map(q=>job(q,runner(q))).join(''):'<p class="hc-note">Nothing running. Start a run from a pillar’s Train button or from My computers.</p>';
   for(const el of nowSlot.querySelectorAll('[data-log-key] .tc-log')){const st=keep[el.closest('[data-log-key]').dataset.logKey]||{};el.scrollTop=(st.paused||st.bottom===false)&&st.top!=null?st.top:el.scrollHeight}
  }
  // Queue: just the count when empty; otherwise position, stage and which helpers can take each job.
  const qs=status('waiting');
  if(qs){qs.closest('.ts-sec')?.classList.toggle('ts-empty',!waiting.length);
   qs.innerHTML=waiting.map((q,i)=>{const target=q.target&&comps.find(c=>c.id===q.target);const who=target?esc(target.name)+' (chosen)':online.length?online.map(c=>esc(c.name)).join(', '):'the next helper that opens';
    return `<div class="tq"><b>#${i+1}</b> ${esc(q.label||q.title)}<small>waiting since ${when(q.since)} · can run on ${who}</small></div>`}).join('')}
  for(const part of ['robot','field','actions']){
   const el=slot(part,'training-status');if(!el)continue;
   const active=running.filter(q=>q.part===part);
   el.textContent=active.length?'Training in progress'+(active.length>1?' · '+active.length+' runs':'')+'.':'Not currently training.';
  }
  const busy=k=>o.queue.some(q=>q.part===k);
  const needs={robot:robot(o.robot,busy('robot')),tracking:tracking(o.tracking),field:field(o.field,busy('field')),actions:actions(o.actions,o,busy('actions'))};
  for(const [part,need] of Object.entries(needs)){const f=document.querySelector(`[data-part="${part}"] .tt-sum-flag`);if(f)f.hidden=!need;if(!picked[part])sumTab(part,need?'next':'current')}
  fill('test','videos',(o.test_videos||[]).length?'<table class="tt-runs"><tr><th>Made</th><th>Match</th><th>Video</th></tr>'+o.test_videos.map(v=>`<tr><td>${when(v.saved_at)}</td><td>${esc(v.match_key||'')}</td><td>${video(v)}</td></tr>`).join('')+'</table>':'<p class="training-note">No match test videos yet. Run Preview a match below.</p>');
  // Helpers & settings → My computers. Not redrawn while you are using one of its controls.
  const mine=slot('helpers','computers'),computerToolbar=slot('helpers','computers-actions');
  if(mine&&!mine.contains(document.activeElement)&&!computerToolbar?.contains(document.activeElement)){
   if(computerToolbar)computerToolbar.innerHTML=comps.some(c=>c.update?.remote&&c.update?.behind&&!c.update?.requested)?'<button type="button" class="button-secondary" data-update="all">Update all computers</button>':'';
   mine.innerHTML=comps.length?comps.map(computerCard).join(''):'<p class="tt-computer-empty">No computers connected yet. Install the helper below and connect it.</p>';
  }
  // Helpers & settings: each helper's last GPU self-check.
  testFlow(o);
  // Test & release: the detectors a test uses are always the ones in use; only the Actions model is chosen.
  fill('test','detectors',`Uses the robot detector ${o.robot.current?'you trained (adopted '+when(o.robot.current.adopted_at)+')':'built into the helper'} and ${o.field.current?'the field detector you trained for '+esc(o.field.game_key)+' (adopted '+when(o.field.current.adopted_at)+')':'the field detector built into the helper for '+esc(o.field.game_key)}. Where you marked scoring areas on 3 · Field, those marks are used instead.`);
  const menu=document.getElementById('trial-model'),empty=slot('test','no-model');
  if(menu&&empty){const has=[...menu.options].some(x=>x.value);empty.hidden=has;menu.hidden=!has}
  // A dot on each pillar in the Auto Scout menu that needs something.
  for(const a of $$('.community-nav [data-pillar]')){a.querySelector('.tt-flag')?.remove();if(needs[a.dataset.pillar]){const f=document.createElement('span');f.className='tt-flag';f.title='Needs attention';a.append(f)}}
 }
 for(const b of $$('[data-train]'))b.addEventListener('click',async()=>{if(b.dataset.train==='actions'){document.getElementById('use-helper')?.click();return}b.disabled=true;try{await send({action:'queue_detector',kind:b.dataset.train==='field'?'train_field':'train',match_only:document.getElementById('match-video-only')?.checked?'1':''});await refresh()}catch(e){alert(e.message);b.disabled=false}});
 document.addEventListener('click',async e=>{const b=e.target.closest('[data-cancel]');if(!b)return;if(!confirm('Cancel this run? The helper stops at its next progress report.'))return;b.disabled=true;try{await send({action:'cancel_detector',request:b.dataset.cancel});await refresh()}catch(err){alert(err.message);b.disabled=false}});
 for(const b of $$('[data-rollback]'))b.addEventListener('click',async()=>{if(!confirm('Go back to the previous model? The current one is set aside, and helpers switch within an hour.'))return;try{await send({action:'rollback_detector',which:b.dataset.rollback});await refresh()}catch(e){alert(e.message)}});
 refresh();let tick=0;setInterval(()=>{tick++;if(!document.hidden&&(window.__neptuneFast||tick%2===0))refresh()},5000);   // every 5 s while something runs, 10 s otherwise
})();
