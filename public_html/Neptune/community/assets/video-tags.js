/* Local UI state only; video timestamps come from the YouTube player API. */
(() => {
'use strict';
const config=JSON.parse(document.getElementById('tagConfig').textContent);
const byId=id=>document.getElementById(id), buttons=[...document.querySelectorAll('[data-tag]')];
let player,ready=false,team=null,active=null,pending=0;
const status=message=>{byId('tagStatus').textContent=message;};
buttons.forEach(b=>b.dataset.original=b.innerHTML);

// Custom action shortcuts are local preferences, never changes to game actions or labels.
// Keyed by published Game Builder revision so other games and future revisions stay isolated.
const shortcutStoreKey=`neptune.community.tag-shortcuts.v1:${config.game.season}:${config.game.version}`;
const defaultShortcuts=new Map(buttons.map(button=>[button.dataset.tag,button.dataset.shortcut]));
const reservedShortcuts=new Map([['r','rewind'],['u','undo'],['o','score estimate'],['1','robot 1'],['2','robot 2'],['3','robot 3'],['4','robot 4'],['5','robot 5'],['6','robot 6'],[' ','play/pause']]);
let keyEditMode=false,keyEditTarget=null;
const validShortcut=value=>/^(?:shift\+)?[a-z7-9]$/.test(value)&&!reservedShortcuts.has(value);
const shortcutName=value=>value.startsWith('shift+')?'Shift+'+value.slice(6).toUpperCase():value.toUpperCase();
function setButtonShortcut(button,value){
 button.dataset.shortcut=value;
 const hint=button.querySelector('kbd');if(hint)hint.textContent=shortcutName(value);
 button.setAttribute('aria-keyshortcuts',value.startsWith('shift+')?'Shift+'+value.slice(6).toUpperCase():value.toUpperCase());
 button.title=`${button.closest('.tag-control-group')?.querySelector('h2')?.textContent||'Action'} · ${button.dataset.caption} (${shortcutName(value)})`;
 button.dataset.original=button.innerHTML;
}
function applyShortcuts(mapping){
 if(!mapping||typeof mapping!=='object'||Array.isArray(mapping))return false;
 const assigned=new Set();
 // A revision update can add or remove actions: never partially apply a stale map.
 if(Object.keys(mapping).length!==buttons.length)return false;
 for(const button of buttons){const value=mapping[button.dataset.tag];
  if(typeof value!=='string'||!validShortcut(value)||assigned.has(value))return false;
  assigned.add(value);
 }
 buttons.forEach(button=>setButtonShortcut(button,mapping[button.dataset.tag]));return true;
}
function restoreCustomKeys(){
 try{const raw=localStorage.getItem(shortcutStoreKey);if(raw)applyShortcuts(JSON.parse(raw));}
 catch(error){ /* Private mode or restricted storage: keep defaults, allow this session. */ }
}
function saveCustomKeys(){
 const values=Object.fromEntries(buttons.map(button=>[button.dataset.tag,button.dataset.shortcut]));
 try{localStorage.setItem(shortcutStoreKey,JSON.stringify(values));return true;}
 catch(error){return false;}
}
function keyEditInstruction(){return keyEditTarget?`Selected ${keyEditTarget.dataset.caption}. Press a letter or 7–9 (Shift allowed), or select another action. Reserved: R, U, O, 1–6, Space.`:'Custom keys: click any outlined action, then press the key you want. Click another action to continue; Custom keys exits.';}
function updateKeyEditSelection(){
 byId('tagStudio').classList.toggle('key-editing',keyEditMode);
 byId('customKeys').setAttribute('aria-pressed',String(keyEditMode));
 buttons.forEach(button=>{
  button.classList.toggle('key-binding-target',keyEditMode&&button===keyEditTarget);
  button.setAttribute('aria-pressed',String(keyEditMode&&button===keyEditTarget));
 });
 refresh();
}
function exitKeyEditMode(){keyEditMode=false;keyEditTarget=null;updateKeyEditSelection();status('Custom keys saved. Select a robot and use your chosen shortcuts to tag.');}
byId('customKeys').addEventListener('click',()=>{
 if(keyEditMode){exitKeyEditMode();return;}
 if(active||!byId('scoringEditor').hidden||!byId('shareEditor').hidden){status('Finish or close the current observation before editing keys.');return;}
 keyEditMode=true;keyEditTarget=null;updateKeyEditSelection();status(keyEditInstruction());
});
byId('resetCustomKeys').addEventListener('click',()=>{
 buttons.forEach(button=>setButtonShortcut(button,defaultShortcuts.get(button.dataset.tag)));
 try{localStorage.removeItem(shortcutStoreKey);status('Action shortcuts restored to the published game defaults.');}
 catch(error){status('Defaults restored for this session. Browser storage could not be updated.');}
 keyEditTarget=null;updateKeyEditSelection();
});
restoreCustomKeys();

const stamp=ms=>`${Math.floor(ms/60000)}:${String(Math.floor(ms/1000)%60).padStart(2,'0')}.${Math.floor(ms%1000/100)}`;
const now=()=>Math.round(player.getCurrentTime()*1000);
function refresh(){byId('addScoring').disabled=!ready||team===null||Boolean(active)||keyEditMode;history?.forEach(h=>h.render());buttons.forEach(b=>b.disabled=!keyEditMode&&(!ready||team===null||(active&&b.dataset.tag!==active.tag_key)));byId('cancelInterval').hidden=!active;buttons.forEach(b=>{b.innerHTML=active&&b.dataset.tag===active.tag_key?'Stop: '+b.dataset.caption:b.dataset.original;});}
async function post(action,extra){
 const response=await fetch('tag-video.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:config.csrf,action,...extra})});
 let result;try{result=await response.json();}catch(e){throw new Error('Saving did not finish. Check your connection and retry.');}
 if(!response.ok||!result.ok)throw new Error(result.error||'Unable to save. Retry this label.');return result;
}
const history=new Map();let scoringDraft=null;
const scoreText=s=>{
 if(!s)return '';
 if(s.mode==='alliance_share')return `${s.robot_points} ${s.method} points · ${s.share_percent===null?'percentage unknown':Number(s.share_percent).toFixed(1)+'% of '+s.alliance_score+' alliance points'} · whole match`;
 const units=s.method==='unknown'?'amount unknown':s.method==='counted'?`${s.count_min} counted`:`${s.count_min}–${s.count_max} estimated`;
 const points=s.points_min===null?'points unknown':s.points_min===s.points_max?`${s.points_min} pts`:`${s.points_min}–${s.points_max} pts`;
 return `${s.component_label} · ${units} · ${points}`;
};
function scoringTotals(){
 if(team===null)return;
 const share=[...history.values()].find(h=>h.saved()&&!h.withdrawn()&&Number(h.data.team_number??h.data.team)===team&&h.data.scoring?.mode==='alliance_share');
 if(share){byId('scoringTotals').textContent='Your whole-match contribution for '+team+': '+scoreText(share.data.scoring)+'. Timestamped counts are separate evidence.';return;}
 let counted=0,low=0,high=0,unknown=0,n=0,estimatedEntries=0;
 history.forEach(h=>{if(!h.saved()||h.withdrawn()||Number(h.data.team_number??h.data.team)!==team||!h.data.scoring)return;n++;const s=h.data.scoring;if(s.points_min===null){unknown++;return;}if(s.method==='counted')counted+=s.points_min;else{estimatedEntries++;low+=s.points_min;high+=s.points_max;}});
 byId('scoringTotals').textContent=n?`Your partial contribution for ${team}: ${counted} counted points${estimatedEntries?' + '+low+'–'+high+' estimated points':''}${unknown?' · '+unknown+' entries with unknown points':''}. Not a complete-match total.`:`No scoring amounts recorded by you for ${team} yet. Scoring clicks without amounts remain unknown.`;
}
function row(data,saved=false){
 if(data.scoring_json&&!data.scoring){data.scoring=JSON.parse(data.scoring_json);delete data.scoring_json;}
 const li=document.createElement('li'),text=document.createElement('span'),state=document.createElement('span'),button=document.createElement('button'),amount=document.createElement('button');text.className='label-text';button.type='button';amount.type='button';amount.className='amount-action';
 text.textContent=`${data.polarity==='absent'?'Training counterexample · NOT ':''}${data.team_number??data.team} · ${data.tag_category??config.tags[data.tag_key]?.category} · ${data.tag_label??config.tags[data.tag_key]?.label} · ${data.label_scope==='match'||data.scope==='match'?'whole match / phase':stamp(data.start_ms)+(data.end_ms>data.start_ms?'–'+stamp(data.end_ms):'')} · ${data.certainty}${data.scoring?' · '+scoreText(data.scoring):''}`;
 li.append(text,state,amount,button);byId('recentLabels').prepend(li);
 let isSaved=saved,withdrawn=data.review_state==='withdrawn',busy=false;
 const entry={data,saved:()=>isSaved,withdrawn:()=>withdrawn,withdraw:()=>{withdrawn=true;render();}};history.set(data.id,entry);
 function render(){button.disabled=busy;button.hidden=withdrawn;button.textContent=isSaved?'Undo':'Retry';state.textContent=withdrawn?'Withdrawn':busy?'Saving…':isSaved?'Saved':'Not saved';amount.textContent=data.scoring?.mode==='alliance_share'?'Edit estimate':data.scoring?'Edit amount':'Add amount';amount.hidden=withdrawn||data.tag_key!=='scoring'||data.polarity==='absent';amount.disabled=!isSaved||busy||!ready;scoringTotals();}
 async function save(){if(busy)return;busy=true;pending++;render();try{await post('save',{match_id:config.match_id,label:JSON.stringify(data)});isSaved=true;if(data.replaces_id)history.get(data.replaces_id)?.withdraw();status(data.scoring?'Scoring contribution saved.':'Label saved. Ready for the next action.');}catch(e){status(e.message);}finally{busy=false;pending--;render();}}
 amount.addEventListener('click',()=>data.scoring?.mode==='alliance_share'||(data.scope??data.label_scope)==='match'?openShare(data):openScoring(data));
 button.addEventListener('click',async()=>{if(!isSaved){save();return;}busy=true;render();try{await post('undo',{id:data.id});withdrawn=true;status('Label withdrawn.');}catch(e){status(e.message);}finally{busy=false;render();}});
 entry.render=render;render();if(!saved)save();
}
function readScoreTime(value){const m=/^(\d{1,2}):([0-5]\d)(?:\.(\d{1,3}))?$/.exec(value.trim());if(!m)throw new Error('Use video time m:ss, for example 1:23.5.');return Number(m[1])*60000+Number(m[2])*1000+Number((m[3]||'').padEnd(3,'0'));}
function previewScore(){
 const rules=config.scoring_rules;if(!rules)return;
 const def=rules.components[byId('scoreComponent').value],method=byId('scoreMethod').value,phase=byId('scorePhase').value;
 const fuel=rules.season===2026&&byId('scoreComponent').value==='fuel';byId('hubField').hidden=!fuel;
 byId('countMinField').hidden=method==='unknown';byId('countMaxField').hidden=method!=='estimated';byId('countMinCaption').textContent=method==='estimated'?'At least':'Successful count';
 byId('scoreMin').required=method!=='unknown';byId('scoreMax').required=method==='estimated';byId('scoreMin').max=byId('scoreMax').max=String(def[3]);
 let rate=phase==='auto'?def[1]:phase==='teleop'?def[2]:null;
 if(fuel){if(byId('scoreHub').value==='unknown')rate=null;else if(byId('scoreHub').value==='inactive'&&rate!==null)rate=0;}
 const capped=rules.season===2026&&phase==='auto'&&byId('scoreComponent').value==='tower_l1';if(capped)rate=null;
 let message='Points remain unknown until the successful amount and scoring conditions are known.';
 const min=Number(byId('scoreMin').value),max=method==='counted'?min:Number(byId('scoreMax').value);
 if(method!=='unknown'&&rate!==null&&byId('scoreMin').value!==''&&(method==='counted'||byId('scoreMax').value!=='')&&min>=0&&max>=min&&max<=def[3])message=`${min*rate}${max!==min?'–'+max*rate:''} rule-based points for this entry (${method}).`;
 if(capped)message='AUTO tower credit is capped at two robots per alliance. The climb count is saved; its points stay unknown.';
 if(phase!=='unknown'&&(phase==='auto'?def[1]:def[2])===null)message='This outcome is not available in the selected period. Choose the correct period.';
 byId('scorePreview').textContent=message;
}
function openScoring(original=null){
 if(!ready||(!original&&team===null)){status('Wait for the video and choose a robot first.');return;}
 if(active){status('Finish or cancel the current duration first.');return;}
 if(!config.scoring_rules){status('Scoring rules are not configured for this season yet. Ordinary tags remain available.');return;}
 if(byId('trainingNegative').checked){status('Turn off counterexample mode before recording a scoring contribution.');return;}
 const time=now(),duration=Math.round(player.getDuration()*1000);if(duration<1000||duration>600000){status('Wait for an individual match video to load.');return;}
 const source=original?{...original,team:Number(original.team_number??original.team),scope:original.label_scope??original.scope}:null;
 scoringDraft=source?{...source,id:crypto.randomUUID().replaceAll('-',''),replaces_id:source.id,duration_ms:duration}:{id:crypto.randomUUID().replaceAll('-',''),tag_key:'scoring',team,scope:'moment',start_ms:time,end_ms:time,duration_ms:duration,certainty:byId('certainty').value,polarity:'present',note:byId('tagNote').value};
 const s=source?.scoring;byId('scoringTitle').textContent=source?'Add or correct scoring amount':'Scoring contribution';byId('scoringRobot').textContent=`Robot ${scoringDraft.team} · ${config.scoring_rules.game} · ${source?'Existing tag time is preserved.':'This saves one scoring entry.'}`;
 byId('scoreComponent').value=s?.component||Object.keys(config.scoring_rules.components)[0];byId('scorePhase').value=s?.phase||'unknown';byId('scoreHub').value=s?.hub_state==='not_applicable'?'unknown':s?.hub_state||'unknown';byId('scoreMethod').value=s?.method||'counted';byId('scoreMin').value=s?.count_min??'';byId('scoreMax').value=s?.count_max??'';
 byId('scoreStart').value=stamp(scoringDraft.start_ms);byId('scoreEnd').value=stamp(scoringDraft.end_ms);byId('scoreStart').readOnly=byId('scoreEnd').readOnly=Boolean(source);byId('scoreSetEnd').disabled=Boolean(source);byId('scoreNote').value=scoringDraft.note||'';byId('scoreError').textContent='';
 player.pauseVideo();byId('scoringEditor').hidden=false;previewScore();byId('scoreComponent').focus();
}
let shareDraft=null;
function shareChange(from){
 const total=config.alliance_totals?.[shareDraft?.alliance];
 if(!shareDraft)return;
 if(from==='percent'&&total>0)byId('sharePoints').value=String(Math.round(Number(byId('sharePercent').value)*total/100));
 const value=byId('sharePoints').value,points=Number(value),valid=value!==''&&Number.isInteger(points)&&points>=0;
 const percent=valid&&total>0?100*points/total:null;
 if(percent!==null)byId('sharePercent').value=String(Math.min(100,percent));
 byId('sharePercentText').textContent=percent===null?'Percentage unknown':percent.toFixed(1)+'%';
 byId('shareSummary').textContent=!valid?'No estimate saved. Move the slider or enter points.':`${points} robot points${percent!==null?' = '+percent.toFixed(1)+'% of '+total+' alliance points':'. Alliance final score is unavailable, so the percentage remains unknown.'}`;
 byId('sharePoints').max=String(total??100000);
}
function openShare(original=null){
 if(!ready||(!original&&team===null)){status('Wait for the video and choose a robot first.');return;}
 if(active){status('Finish or cancel the active duration first.');return;}
 if(byId('trainingNegative').checked){status('Turn off counterexample mode before entering a contribution.');return;}
 if(!original){const prior=[...history.values()].find(h=>h.saved()&&!h.withdrawn()&&Number(h.data.team_number??h.data.team)===team&&h.data.scoring?.mode==='alliance_share');if(prior)original=prior.data;}
 const robot=Number(original?.team_number??original?.team??team);const red=config.roster.red.includes(robot),alliance=red?'red':'blue';const total=config.alliance_totals?.[alliance];
 const duration=Math.round(player.getDuration()*1000);if(duration<1000||duration>600000){status('Wait for a match video to load.');return;}
 shareDraft={id:crypto.randomUUID().replaceAll('-',''),tag_key:'scoring',team:robot,scope:'match',start_ms:null,end_ms:null,duration_ms:duration,polarity:'present',note:original?.note||'',alliance};
 if(original)shareDraft.replaces_id=original.id;
 const scoring=original?.scoring;
 byId('shareRobot').textContent=`Robot ${robot} · ${red?'Red':'Blue'} alliance · Whole-match contribution`;
 byId('shareAlliance').textContent=total===undefined||total===null?'Final alliance score is not available yet. You can record points; the percentage will remain unknown.':`${red?'Red':'Blue'} alliance final score: ${total} points (full score, including penalties).`;
 byId('sharePercent').disabled=!(total>0);byId('sharePercent').value='0';byId('sharePoints').value=scoring?.robot_points??'';byId('shareMethod').value=scoring?.method||'estimated';byId('shareNote').value=original?.note||'';byId('shareError').textContent='';
 byId('scoringEditor').hidden=true;scoringDraft=null;byId('shareEditor').hidden=false;player.pauseVideo();shareChange('points');byId('sharePoints').focus({preventScroll:true});byId('shareEditor').scrollTop=0;
}
byId('sharePercent').addEventListener('input',()=>shareChange('percent'));
byId('sharePoints').addEventListener('input',()=>shareChange('points'));
byId('shareCancel').addEventListener('click',()=>{shareDraft=null;byId('shareEditor').hidden=true;status('Contribution cancelled. Nothing saved.');});
byId('openSequence').addEventListener('click',()=>{if(shareDraft?.replaces_id){byId('shareError').textContent='Cancel this correction first, then add a separate sequence if you need one.';return;}shareDraft=null;byId('shareEditor').hidden=true;openScoring();});
byId('shareForm').addEventListener('submit',event=>{
 event.preventDefault();if(!shareDraft)return;
 const points=Number(byId('sharePoints').value),total=config.alliance_totals?.[shareDraft.alliance],method=byId('shareMethod').value;
 if(byId('sharePoints').value===''||!Number.isInteger(points)||points<0||points>(total??100000)){byId('shareError').textContent='Enter whole-number robot points between zero and the alliance final score.';return;}
 const scoring={schema_version:1,mode:'alliance_share',source:'human_video_contribution',component_label:'Whole-match contribution',method,robot_points:points,points_min:points,points_max:points,alliance:shareDraft.alliance,alliance_score:total??null,share_percent:total>0?100*points/total:null,count_min:null,count_max:null};
 const data={...shareDraft,certainty:method==='estimated'?'uncertain':'observed',note:byId('shareNote').value,scoring};delete data.alliance;
 byId('shareEditor').hidden=true;shareDraft=null;row(data);
});
for(const id of ['shareEditor','scoringEditor'])byId(id).addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();byId(id==='shareEditor'?'shareCancel':'scoreCancel').click();}});

if(config.scoring_rules)Object.entries(config.scoring_rules.components).forEach(([key,def])=>{const o=document.createElement('option');o.value=key;o.textContent=def[0];byId('scoreComponent').append(o);});
byId('addScoring').addEventListener('click',()=>openShare());
for(const id of ['scoreComponent','scorePhase','scoreHub','scoreMethod','scoreMin','scoreMax'])byId(id).addEventListener('input',previewScore);
byId('scoreCancel').addEventListener('click',()=>{byId('scoringEditor').hidden=true;scoringDraft=null;status('Scoring amount cancelled. No new entry saved.');});
byId('scorePlay').addEventListener('click',()=>byId('playPause').click());
byId('scoreSetEnd').addEventListener('click',()=>{if(ready)byId('scoreEnd').value=stamp(now());});
byId('scoringForm').addEventListener('submit',event=>{
 event.preventDefault();if(!scoringDraft)return;
 try{
  let start=scoringDraft.start_ms,end=scoringDraft.end_ms;
  if(!scoringDraft.replaces_id){start=readScoreTime(byId('scoreStart').value);end=readScoreTime(byId('scoreEnd').value);}
  if(start<0||end<start||end>scoringDraft.duration_ms)throw new Error('Keep the start and end inside the video, with end at or after start.');
  const method=byId('scoreMethod').value;
  const min=method==='unknown'?null:Number(byId('scoreMin').value),max=method==='unknown'?null:method==='counted'?min:Number(byId('scoreMax').value);
  const def=config.scoring_rules.components[byId('scoreComponent').value],phase=byId('scorePhase').value;
  if(phase!=='unknown'&&(phase==='auto'?def[1]:def[2])===null)throw new Error('Choose a period where this outcome can score.');
  if(method!=='unknown'&&(!Number.isInteger(min)||!Number.isInteger(max)||min<0||max<min||max>def[3]||(method==='estimated'&&min===max)))throw new Error('Enter a successful whole-number count, or a range with different minimum and maximum.');
  let rate=phase==='auto'?def[1]:phase==='teleop'?def[2]:null;
  const hub=config.scoring_rules.season===2026&&byId('scoreComponent').value==='fuel'?byId('scoreHub').value:'not_applicable';
  if(hub==='unknown')rate=null;else if(hub==='inactive'&&rate!==null)rate=0;
  if(config.scoring_rules.season===2026&&phase==='auto'&&byId('scoreComponent').value==='tower_l1')rate=null;
  const scoring={component:byId('scoreComponent').value,component_label:def[0],phase,method,count_min:min,count_max:max,hub_state:hub,points_min:min===null||rate===null?null:min*rate,points_max:max===null||rate===null?null:max*rate};
  const data={...scoringDraft,start_ms:start,end_ms:end,scope:scoringDraft.replaces_id?scoringDraft.scope:end>start?'interval':'moment',note:byId('scoreNote').value,scoring};
  byId('scoringEditor').hidden=true;scoringDraft=null;row(data);byId('tagNote').value='';
 }catch(e){byId('scoreError').textContent=e.message;}
});

config.rows.slice().reverse().forEach(r=>row(r,true));
document.querySelectorAll('[data-team]').forEach(button=>button.addEventListener('click',()=>{if(active){status('Finish or cancel the current duration before changing robots.');return;}team=Number(button.dataset.team);document.querySelectorAll('[data-team]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));byId('selectedRobot').textContent=`Robot ${team}`;refresh();scoringTotals();status('Ready. One click or shortcut records one observed action. Space: play/pause.');}));
buttons.forEach(button=>button.addEventListener('click',()=>{
 if(keyEditMode){keyEditTarget=button;updateKeyEditSelection();status(keyEditInstruction());return;}
 if(!ready||team===null)return;
 const key=button.dataset.tag,definition=config.tags[key],time=now(),duration=Math.round(player.getDuration()*1000);
 if(duration<1000||duration>600000){status('This page supports individual match videos of ten minutes or less.');return;}
 if(active){
  if(time<=active.start_ms){status('The end must be after the start. Move forward or cancel this duration.');return;}
  row({...active,end_ms:time,duration_ms:duration});byId('tagNote').value='';active=null;buttons.forEach(b=>b.classList.remove('is-recording'));refresh();return;
 }
 const scope=key.startsWith('gb:')?'moment':(definition.scope==='match'?'match':'moment');
 if(scope==='match'&&!window.confirm(`Save “${definition.label}” as a whole-match / phase assessment for ${team}? Only do this after watching the relevant footage.`))return;
 if(byId('trainingNegative').checked&&(scope==='match'||definition.scope==='match')){status('A training counterexample needs a moment or duration for an action tag.');return;}
 const data={polarity:byId('trainingNegative').checked?'absent':'present',id:crypto.randomUUID().replaceAll('-',''),tag_key:key,team,scope,start_ms:scope==='match'?null:time,end_ms:scope==='match'?null:time,duration_ms:duration,certainty:byId('certainty').value,note:byId('tagNote').value};
 if(scope==='interval'){active=data;button.classList.add('is-recording');refresh();status(`Recording ${definition.label} for ${team}. Click it again to stop.`);}else{row(data);byId('tagNote').value='';}
}));
byId('cancelInterval').addEventListener('click',()=>{active=null;buttons.forEach(b=>{b.classList.remove('is-recording');b.innerHTML=b.dataset.original;});refresh();status('Duration cancelled; nothing saved.');});
byId('undoLast').addEventListener('click',()=>{const latest=[...byId('recentLabels').querySelectorAll('button')].find(b=>!b.hidden&&!b.disabled&&b.textContent==='Undo');if(latest)latest.click();else status('No saved tag to undo on this browser.');});
byId('forward').addEventListener('click',()=>{if(ready)player.seekTo(Math.min(player.getDuration(),player.getCurrentTime()+5),true);});
byId('rewind').addEventListener('click',()=>{if(ready)player.seekTo(Math.max(0,player.getCurrentTime()-5),true);});
byId('playPause').addEventListener('click',()=>{if(ready)player.getPlayerState()===1?player.pauseVideo():player.playVideo();});
byId('speed').addEventListener('change',()=>{if(ready)player.setPlaybackRate(Number(byId('speed').value));});
// Fit the player to the remaining screen, including the actual rendered dock height.
function fitVideo(){
 if(innerWidth<851)return;
 const viewport=byId('videoViewport'),top=viewport.getBoundingClientRect().top+window.scrollY;
 const overhead=document.querySelector('.studio-transport').getBoundingClientRect().height+document.querySelector('.studio-dock').getBoundingClientRect().height;
 const screenTop=document.fullscreenElement?viewport.getBoundingClientRect().top:top;
 viewport.style.height=Math.max(180,innerHeight-screenTop-overhead-12)+'px';
}
window.addEventListener('resize',fitVideo);
new ResizeObserver(fitVideo).observe(document.querySelector('.studio-dock'));
new ResizeObserver(fitVideo).observe(document.querySelector('.studio-transport'));
if(document.readyState==='complete')fitVideo();else window.addEventListener('load',fitVideo);
// Zoom/pan changes presentation only: all saved times still come from the original player.
let zoom=1,origin=[50,50],drag=null;
function applyView(){byId('videoSurface').style.transformOrigin=`${origin[0]}% ${origin[1]}%`;byId('videoSurface').style.transform=`scale(${zoom})`;byId('zoomPan').hidden=zoom===1;byId('videoZoom').value=String(zoom);}
function panView(event){
 const directions={arrowleft:[-1,0],arrowright:[1,0],arrowup:[0,-1],arrowdown:[0,1]};
 const direction=directions[event.key.toLowerCase()];if(!direction||zoom<=1)return false;
 const rect=byId('videoViewport').getBoundingClientRect(),pixels=event.shiftKey?72:24;
 origin=[Math.max(0,Math.min(100,origin[0]+direction[0]*pixels/Math.max(1,rect.width)*100/(zoom-1))),Math.max(0,Math.min(100,origin[1]+direction[1]*pixels/Math.max(1,rect.height)*100/(zoom-1)))];
 event.preventDefault();applyView();return true;
}
byId('videoZoom').addEventListener('change',()=>{zoom=Number(byId('videoZoom').value);applyView();if(zoom>1)byId('zoomPan').focus({preventScroll:true});});
byId('videoView').addEventListener('change',()=>{const views={center:[50,50],top:[50,0],left:[0,50],right:[100,50],'bottom-left':[0,100],'bottom-right':[100,100]};origin=views[byId('videoView').value];if(zoom===1&&byId('videoView').value!=='center')zoom=2;applyView();if(zoom>1)byId('zoomPan').focus({preventScroll:true});});
byId('resetZoom').addEventListener('click',()=>{zoom=1;origin=[50,50];byId('videoView').value='center';applyView();});
byId('zoomPan').addEventListener('pointerdown',event=>{event.currentTarget.focus({preventScroll:true});drag={x:event.clientX,y:event.clientY,origin:[...origin]};event.currentTarget.setPointerCapture(event.pointerId);});
byId('zoomPan').addEventListener('pointermove',event=>{if(!drag)return;const rect=byId('videoViewport').getBoundingClientRect();origin=[Math.max(0,Math.min(100,drag.origin[0]-(event.clientX-drag.x)/rect.width*100/(zoom-1))),Math.max(0,Math.min(100,drag.origin[1]-(event.clientY-drag.y)/rect.height*100/(zoom-1)))];applyView();});
for(const event of ['pointerup','pointercancel'])byId('zoomPan').addEventListener(event,()=>{drag=null;});
byId('focusMode').addEventListener('click',async()=>{try{if(document.fullscreenElement)await document.exitFullscreen();else await byId('tagStudio').requestFullscreen();}catch(e){status('Full screen is unavailable in this browser. The video zoom controls still work.');}});
document.addEventListener('fullscreenchange',()=>{fitVideo();byId('focusMode').textContent=document.fullscreenElement?'Exit full screen':'Full screen';});
document.addEventListener('keydown',event=>{
 if(keyEditMode){
  if(event.key==='Escape'){event.preventDefault();exitKeyEditMode();return;}
  if(event.key==='Tab'||event.ctrlKey||event.metaKey||event.altKey||event.isComposing)return;
  if(event.target.closest('input,select,textarea,[contenteditable=true]'))return;
  if(event.repeat){event.preventDefault();return;}
  // While editing, keys cannot accidentally trigger video playback or save a label.
  event.preventDefault();
  if(!keyEditTarget){status('Click an outlined action first, then press its new key.');return;}
  const key=event.key.toLowerCase(),proposed=(event.shiftKey?'shift+':'')+key;
  if(reservedShortcuts.has(key)&&!event.shiftKey){status(`${shortcutName(key)} is reserved for ${reservedShortcuts.get(key)}. Choose another key.`);return;}
  if(!validShortcut(proposed)){status('Choose a letter A–Z or 7–9, optionally with Shift. R, U, O, 1–6 and Space are reserved.');return;}
  const button=keyEditTarget,old=button.dataset.shortcut;
  const taken=buttons.find(other=>other!==button&&other.dataset.shortcut===proposed);
  if(old!==proposed){
   if(taken)setButtonShortcut(taken,old); // Swap; never leave another action without a shortcut.
   setButtonShortcut(button,proposed);
  }
  const saved=saveCustomKeys();
  keyEditTarget=null;updateKeyEditSelection();
  status(`${button.dataset.caption} → ${shortcutName(proposed)}.${taken?' Swapped with '+taken.dataset.caption+'.':''}${saved?' Saved in this browser.':' Browser storage unavailable; changes last until reload.'} Choose another action, or click Custom keys to exit.`);
  return;
 }
 if(event.ctrlKey||event.metaKey||event.altKey||event.isComposing||event.target.closest('input,select,textarea,[contenteditable=true]'))return;
 if(!byId('scoringEditor').hidden||!byId('shareEditor').hidden)return;
 if(panView(event))return;
 if(event.repeat)return;
 const key=event.key.toLowerCase();let target;
 if(/^[1-6]$/.test(key))target=[...document.querySelectorAll('[data-team]')][Number(key)-1];
 else if(key===' ')target=byId('playPause');else if(key==='r')target=byId('rewind');else if(key==='u')target=byId('undoLast');else if(key==='o')target=byId('addScoring');
 else target=buttons.find(button=>button.dataset.shortcut===(event.shiftKey?'shift+':'')+key);
 if(target&&!target.disabled){event.preventDefault();target.click();}
});
window.addEventListener('beforeunload',e=>{if(pending||active||scoringDraft||shareDraft||[...byId('recentLabels').querySelectorAll('button')].some(b=>b.textContent==='Retry'&&!b.hidden)){e.preventDefault();e.returnValue='';}});
window.onYouTubeIframeAPIReady=()=>{player=new YT.Player('tagPlayer',{videoId:config.video_id,host:'https://www.youtube-nocookie.com',playerVars:{playsinline:1,origin:location.origin},events:{onReady:()=>{ready=true;refresh();byId('videoTime').textContent='Video ready';status(keyEditMode?keyEditInstruction():'Choose a robot. Use the keys displayed on actions; one press saves one observation.');},onError:()=>{ready=false;refresh();byId('videoError').textContent='The video could not be embedded. Try another match; no timestamps will be invented.';}}});};
const script=document.createElement('script');script.src='https://www.youtube.com/iframe_api';script.onerror=()=>{byId('videoError').textContent='The video player could not load. Check your connection or content blocker.';};document.head.append(script);
setInterval(()=>{if(ready)byId('videoTime').textContent=`Video ${stamp(now())}`;},250);
byId('trainingNegative')?.addEventListener('change',()=>status(byId('trainingNegative').checked?'COUNTEREXAMPLE MODE: clicks mean this action is NOT happening for the selected robot.':'Scouting mode: clicks record actions you see.'));
})();
