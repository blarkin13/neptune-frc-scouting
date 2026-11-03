<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
require_once dirname(__DIR__,3).'/neptune_secure/platform-security.php';
$u=require_role(['owner','admin']);
if((int)$u['organization_id']!==(int)($config['app']['platform_organization_id']??1))access_denied('Auto Scout is for admins of the owner organization.');
header('Cache-Control: private, no-store');
// Match tests are queued, listed and served by robot-labels.php; this page shares its session token for those calls.
$_SESSION['robot_labels_csrf']??=bin2hex(random_bytes(32));
require_once dirname(__DIR__).'/community/layout.php';require_once dirname(__DIR__).'/community/pillar.php';
community_page_start('2 · Tracking','Teach Auto Scout which robot is which: review tracking in the helper, share the robot-identity model, and test a whole match.','tracking','AUTO SCOUT · PILLAR 2');
pillar_open((int)gmdate('Y'));
pillar_summary('tracking','Follows each robot from frame to frame and keeps its team number through collisions, replays and camera cuts. Names come from bumper reads first; between reads the tracker carries them by motion and look. Review tracking in the helper (<b>Review robot tracking</b>): every saved review trains the robot-identity model there, and it is shared with every helper only after it gets 98% of names right on reviewed matches it did not learn from.');
?>
<link rel="stylesheet" href="../community/assets/match-picker/match-picker.css?v=1">
<div id="tracking-app" data-csrf="<?=e($_SESSION['robot_labels_csrf'])?>">
<section class="tt-card rl-test-card" id="review-section">
 <span class="stage-label">REVIEW TRACKING</span>
 <h2>Review a match</h2>
 <p>Choose any match with a video and a roster. The helper you pick tracks it and opens the review in that computer's browser: click a box, give it the right team number, and save. Every saved review teaches the robot-identity model and backs up to Neptune.</p>
 <div class="rl-test-bar">
  <div class="match-picker-v2" id="review-picker" data-match-picker data-endpoint="robot-labels.php" data-select-only="1" ></div>
  <label class="rl-test-helper"><span>Open on (the computer you are at)</span><select id="review-computer"><option value="">Choose a computer</option></select></label>
  <button type="button" id="review-btn" class="primary save" disabled>Review</button>
 </div>
 <p id="review-status" class="training-note" role="status" aria-live="polite">Choose a match and the computer you are sitting at.</p>
</section>
<section class="tt-card rl-test-card" id="robot-test-section">
 <span class="stage-label">TEST TRACKING ON A MATCH</span>
 <h2>Test a match</h2>
 <p>Runs the current robot detector, tracker, bumper OCR, and constant identity on the match video—drawing colored bounding boxes and bumper reads on the original view (green = OCR verified, cyan = constant/inferred identity, amber = unresolved) alongside a top-down homography field view.</p>
 <div class="rl-test-bar">
  <div class="match-picker-v2" id="robot-test-picker" data-match-picker data-endpoint="robot-labels.php" data-select-only="1" ></div>
  <label class="rl-test-helper"><span>Helper</span><select id="robot-test-computer"><option value="">First available helper</option></select></label>
  <button type="button" id="robot-test-btn" class="primary save" disabled>Test</button>
 </div>
 <p id="robot-test-status" class="training-note" role="status" aria-live="polite">Choose a match above and press Test.</p>
 <div id="robot-test-viewer" class="rl-test-viewer" hidden>
  <div class="rl-test-head">
   <label>Saved match tests <select id="robot-test-select"></select></label>
   <span id="robot-test-summary" class="training-note"></span>
  </div>
  <div class="rl-test-grid">
   <div class="rl-test-player-wrap">
    <video id="robot-test-video" class="rl-test-video" controls preload="metadata" playsinline></video>
    <a id="robot-test-download" hidden download>Download test video</a>
   </div>
   <div class="rl-test-yt">
    <h3>Upload to YouTube</h3>
    <p class="training-note">Review and edit the pre-filled title and description before uploading to YouTube.</p>
    <label class="rl-test-field"><span>Title</span><input type="text" id="robot-test-title" maxlength="100"></label>
    <label class="rl-test-field"><span>Description</span><textarea id="robot-test-desc" rows="7" maxlength="4900"></textarea></label>
    <div class="rl-test-yt-actions">
     <button type="button" id="robot-test-upload" class="primary save">Upload to YouTube</button>
     <span id="robot-test-yt-status" class="training-note" role="status"></span>
    </div>
   </div>
  </div>
 </div>
</section>
</div>
<script src="../community/assets/match-picker/match-picker.js?v=2-multi"></script>
<script>
// Plain reason for a failed helper job; the raw message stays in the helper's log.
window.trackingReason=m=>/Unsupported broadcast layout/.test(m||'')?"this event's broadcast layout is not supported yet, so its matches cannot be tracked. Try a match from another event.":String(m||'').replace(/^Review needs attention: /,'');
</script>
<style>
.rl-test-card{margin-top:20px;padding:18px;border:1px solid var(--as-line,var(--line,#1e2b3c));border-radius:12px;background:var(--as-card,var(--panel,#0b121c));color:var(--as-text,var(--text,#eaf2ff))}
.rl-test-card h2{margin:4px 0 8px}
.rl-test-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:12px 0}
.rl-test-bar .match-picker-v2{flex:1 1 520px}
.rl-test-helper{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--as-muted,var(--muted,#9aa7b8));font-weight:600;min-width:210px}
.rl-test-helper select{background:var(--panel2,#070d15);color:var(--text,#eaf2ff);border:1px solid var(--as-line,var(--line,#28384f));border-radius:9px;padding:9px 11px;font:inherit;font-size:13px;line-height:1.4}
.rl-test-bar #robot-test-btn{width:auto;min-width:120px;margin:0;padding:10px 20px}
.rl-test-viewer{margin-top:14px;padding-top:14px;border-top:1px solid var(--as-line,var(--line,#1e2b3c))}
.rl-test-head{display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between;margin-bottom:12px}
.rl-test-head select{margin-left:8px;max-width:360px}
.rl-test-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(300px,1fr);gap:18px;align-items:start}
@media(max-width:960px){.rl-test-grid{grid-template-columns:1fr}}
.rl-test-video{width:100%;max-height:640px;background:#000;border-radius:8px;border:1px solid var(--as-line,var(--line,#1e2b3c));display:block}
.rl-test-yt{background:var(--panel2,#0f1826);border:1px solid var(--as-line,var(--line,#1e2b3c));border-radius:10px;padding:14px}
.rl-test-yt h3{margin:0 0 6px;font-size:16px}
.rl-test-field{display:block;margin:10px 0}
.rl-test-field span{display:block;font-size:12px;color:var(--as-muted,var(--muted,#9aa7b8));margin-bottom:4px;font-weight:600}
.rl-test-field input,.rl-test-field textarea{width:100%;box-sizing:border-box;background:var(--panel,#070d15);color:var(--text,#eaf2ff);border:1px solid var(--as-line,var(--line,#28384f));border-radius:6px;padding:8px 10px;font:13px/1.45 system-ui,sans-serif}
.rl-test-field textarea{resize:vertical;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
.rl-test-yt-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:12px}
.rl-test-yt-actions button{width:auto;margin:0;padding:9px 16px}
</style>
<script>
(()=>{
 const app=document.getElementById('tracking-app'),picker=document.getElementById('robot-test-picker'),
       compSel=document.getElementById('robot-test-computer'),
       btn=document.getElementById('robot-test-btn'),status=document.getElementById('robot-test-status'),
       viewer=document.getElementById('robot-test-viewer'),sel=document.getElementById('robot-test-select'),
       vid=document.getElementById('robot-test-video'),sum=document.getElementById('robot-test-summary'),
       titleIn=document.getElementById('robot-test-title'),descIn=document.getElementById('robot-test-desc'),
       upBtn=document.getElementById('robot-test-upload'),ytStatus=document.getElementById('robot-test-yt-status');
 if(!app||!picker||!btn)return;
 const endpoint='robot-labels.php';
 let tests=[],computers=[],currentId='',edited=false,ytConnected=false,uploading=false;
 picker.addEventListener('matchselect',e=>{
  const d=e.detail||{};
  btn.disabled=!d.id;
  if(d.id)status.textContent=`Ready to test ${d.title||d.match_key} with the current robot detector, bumper OCR, and homography map.`;
 });
 titleIn.addEventListener('input',()=>{edited=true});
 descIn.addEventListener('input',()=>{edited=true});
 function renderTest(id,forceFields){
  const t=tests.find(x=>x.id===id)||tests[0];
  if(!t){viewer.hidden=true;return;}
  viewer.hidden=false;
  const changed=currentId!==t.id;
  currentId=t.id;
  sel.value=t.id;
  const download=document.getElementById('robot-test-download');
  const videoUrl=t.has_video?`${endpoint}?robot_test_video=${encodeURIComponent(t.id)}`:'';
  if(download){download.hidden=!t.has_video;download.href=videoUrl;}
  if(vid.getAttribute('src')!==videoUrl){
   vid.setAttribute('src',videoUrl);vid.load();
  }
  if(changed||forceFields||!edited){
   titleIn.value=t.title||`Robot detection test - ${t.match_key||''}`;
   descIn.value=t.description||'';
   edited=false;
  }
  const s=t.summary||{};
  const parts=[
   s.verified_points!=null?`OCR verified: ${s.verified_points}`:'',
   s.constant_points!=null?`Constant ID: ${s.constant_points}`:'',
   s.inferred_points!=null?`Inferred: ${s.inferred_points}`:'',
   s.unknown_points!=null?`Unresolved: ${s.unknown_points}`:'',
   s.homography_status?`Top-down map: ${s.homography_status}`:''
  ].filter(Boolean);
  sum.textContent=parts.join(' · ');
  if(t.youtube_id){
   ytStatus.innerHTML=`Uploaded: <a href="https://youtu.be/${encodeURIComponent(t.youtube_id)}" target="_blank" rel="noopener">▶ Watch on YouTube</a>${t.privacy==='private'?' <small>(private)</small>':' <small>(unlisted)</small>'}`;
   upBtn.textContent='Upload again to YouTube';
  }else if(!ytConnected){
   ytStatus.innerHTML='<a href="/autoscout/youtube.php">Connect YouTube</a> to upload this test video.';
   upBtn.textContent='Upload to YouTube';
  }else{
   ytStatus.textContent='Ready to upload to YouTube as unlisted.';
   upBtn.textContent='Upload to YouTube';
  }
  upBtn.disabled=!t.has_video||!ytConnected;
 }
 async function refreshTests(){
  try{
   const r=await fetch(endpoint+'?detector_status=1');if(!r.ok)return;
   const d=await r.json();
   ytConnected=!!(d.youtube&&d.youtube.connected);
   if(compSel&&Array.isArray(d.computers)){
    computers=d.computers;
    const prevComp=compSel.value;
    compSel.replaceChildren(
     new Option('First available helper',''),
     ...computers.map(c=>new Option(`${c.name} (${!c.supports_robot_test?'update required':c.online?(c.busy?'online · busy':'online · idle'):'offline'})`,c.id))
    );
    if(prevComp&&computers.some(c=>c.id===prevComp))compSel.value=prevComp;
   }
   const latest=(d.requests||[]).find(q=>q.kind==='test_robot');
   if(latest&&['failed','cancelled'].includes(latest.state))status.textContent=`Test ${latest.state}: ${latest.message?trackingReason(latest.message):'No video was produced. Retry after resolving the error.'}`;
   const active=(d.requests||[]).find(q=>q.kind==='test_robot'&&['queued','claimed'].includes(q.state));
   if(active){
    const targetName=active.target&&(computers.find(c=>c.id===active.target)?.name||'chosen helper');
    status.textContent=`Test match (${active.match_key||''}): ${active.state==='queued'?(targetName?`waiting for ${targetName}`:'waiting for a connected helper'):((active.message||'running on helper').split('\n').filter(line=>!line.startsWith('Progress:')).join('\n'))}`;
   }
   if(uploading)return; // Keep the upload status and controls stable while the POST is in flight.
   const list=d.robot_tests||[];
   const prevNewest=tests[0]?.id;
   tests=list;
   if(!list.length){viewer.hidden=true;return;}
   sel.replaceChildren(...list.map(t=>{
    const dstr=t.saved_at?new Date(t.saved_at*1000).toLocaleString(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}):'';
    return new Option(`${t.match_title||t.match_key||t.id} (${dstr})${t.youtube_id?' · YouTube ✓':''}`,t.id);
   }));
   if(list[0].id!==prevNewest){currentId='';edited=false;status.textContent=`Finished testing ${list[0].match_title||list[0].match_key}. Watch the video below or upload to YouTube.`;}
   renderTest(currentId&&list.some(x=>x.id===currentId)?currentId:list[0].id,false);
  }catch(e){status.textContent='Could not refresh match tests: '+e.message}
 }
 vid.addEventListener('error',()=>{if(vid.getAttribute('src'))sum.textContent='Video could not play. Retry loading this test or download the MP4.'});
 sel.addEventListener('change',()=>{edited=false;renderTest(sel.value,true)});
 btn.addEventListener('click',async()=>{
  const mid=Number(picker.dataset.matchId||0);if(!mid)return;
  const comp=compSel?compSel.value:'';
  btn.disabled=true;status.textContent='Queuing robot detection match test for your helper…';
  try{
   const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-Robot-Labels-CSRF':app.dataset.csrf},body:JSON.stringify({action:'queue_test_robot',match_id:mid,computer:comp})});
   const d=await r.json();if(!r.ok)throw Error(d.error||'Could not queue match test');
   const who=comp?(computers.find(c=>c.id===comp)?.name||'your chosen helper'):'the first available helper';
   status.textContent=`Match test queued (${d.request?.match_key||''}) for ${who}. It will track robots, read bumpers, map top-down homography, and send the video here.`;
   await refreshTests();
  }catch(e){status.textContent=e.message}finally{btn.disabled=!Number(picker.dataset.matchId||0)}
 });
 upBtn.addEventListener('click',async()=>{
  if(!currentId)return;
  uploading=true;sel.disabled=true;upBtn.disabled=true;ytStatus.textContent='Uploading video to YouTube…';
  try{
   const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-Robot-Labels-CSRF':app.dataset.csrf},body:JSON.stringify({action:'upload_robot_test_youtube',request_id:currentId,title:titleIn.value,description:descIn.value})});
   const d=await r.json();if(!r.ok)throw Error(d.error||'YouTube upload failed');
   const idx=tests.findIndex(x=>x.id===currentId);if(idx>=0&&d.test)tests[idx]=d.test;
   edited=false;renderTest(currentId,true);
  }catch(e){ytStatus.textContent=e.message}
  finally{uploading=false;sel.disabled=false;upBtn.disabled=!ytConnected||!tests.find(t=>t.id===currentId)?.has_video}
 });
 refreshTests();setInterval(refreshTests,10000);
})();
</script>
<script>
(()=>{
 const app=document.getElementById('tracking-app'),picker=document.getElementById('review-picker'),comp=document.getElementById('review-computer'),
       btn=document.getElementById('review-btn'),status=document.getElementById('review-status'),endpoint='robot-labels.php';
 if(!app||!picker||!btn)return;
 let computers=[];
 const ready=()=>{btn.disabled=!Number(picker.dataset.matchId||0)||!comp.value};
 picker.addEventListener('matchselect',ready);comp.addEventListener('change',ready);
 async function refresh(){
  try{
   const r=await fetch(endpoint+'?detector_status=1');if(!r.ok)return;const d=await r.json();
   computers=Array.isArray(d.computers)?d.computers:[];const prev=comp.value;
   comp.replaceChildren(new Option('Choose a computer',''),...computers.map(c=>{const o=new Option(`${c.name} (${!c.supports_tracking_review?'update required':c.online?(c.busy?'online · busy':'online'):'offline'})`,c.id);o.disabled=!c.supports_tracking_review;return o}));
   if(prev&&computers.some(c=>c.id===prev))comp.value=prev;ready();
   const q=(d.requests||[]).find(x=>x.kind==='review_tracking');if(!q)return;
   const who=computers.find(c=>c.id===q.target)?.name||'the chosen computer';
   status.textContent=q.state==='queued'?`Review of ${q.match_key}: waiting for ${who}. Keep its helper open.`:
    q.state==='claimed'?`Review of ${q.match_key} on ${who}: ${(q.message||'preparing').split('\n')[0]}`:
    q.state==='done'?`${q.message||'Review open'} (${who}). Check that computer's browser.`:`Review of ${q.match_key} ${q.state}: ${trackingReason(q.message)}`;
  }catch(e){}
 }
 btn.addEventListener('click',async()=>{
  btn.disabled=true;status.textContent='Sending the review to the helper…';
  try{
   const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-Robot-Labels-CSRF':app.dataset.csrf},body:JSON.stringify({action:'queue_review_tracking',match_id:Number(picker.dataset.matchId||0),computer:comp.value})});
   const d=await r.json();if(!r.ok)throw Error(d.error||'Could not send the review');await refresh();
  }catch(e){status.textContent=e.message}finally{ready()}
 });
 refresh();setInterval(refresh,10000);
})();
</script>
<?php pillar_close();community_page_end(); ?>
