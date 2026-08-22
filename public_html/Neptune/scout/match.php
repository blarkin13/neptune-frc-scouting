<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$mid=(int)($_GET['match_id']??0);
$alliance=($_GET['alliance']??'Red')==='Blue'?'Blue':'Red';
$station=max(1,min(3,(int)($_GET['station']??1)));

$s=$pdo->prepare('SELECT m.*,e.name event_name,g.name game_name,g.config_json FROM matches m JOIN events e ON e.id=m.event_id JOIN games g ON g.id=m.game_id WHERE m.id=? AND m.organization_id=?');
$s->execute([$mid,$u['organization_id']]);
$m=$s->fetch();
if(!$m) exit('Match not found');
if(!in_array($m['state'],['ready','running','paused'],true)) exit('This match is not available for scouting.');

$s=$pdo->prepare('SELECT frc_team_number FROM match_teams WHERE match_id=? AND alliance=? AND station=?');
$s->execute([$mid,$alliance,$station]);
$robot=(int)$s->fetchColumn();
if(!$robot) exit('No robot is assigned to this station. Refresh the event schedule from The Blue Alliance.');
$run=(int)($m['run_number']??1);

$s=$pdo->prepare("SELECT id,uuid FROM scout_sessions WHERE organization_id=? AND match_id=? AND match_run_number=? AND user_id=? AND alliance=? AND station=? AND status<>'closed' ORDER BY id DESC LIMIT 1");
$s->execute([$u['organization_id'],$mid,$run,$u['id'],$alliance,$station]);
$existing=$s->fetch();
if($existing){
    $sid=(int)$existing['id'];$uuid=$existing['uuid'];
    $pdo->prepare("UPDATE scout_sessions SET frc_team_number=?,scout_name=?,status='connected',last_seen_at=UTC_TIMESTAMP() WHERE id=?")->execute([$robot,$u['display_name'],$sid]);
} else {
    $uuid=uuidv4();
    $pdo->prepare("INSERT INTO scout_sessions(uuid,organization_id,event_id,match_id,user_id,scout_name,frc_team_number,alliance,station,field_id,match_run_number,status,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'connected',UTC_TIMESTAMP())")
        ->execute([$uuid,$u['organization_id'],$m['event_id'],$mid,$u['id'],$u['display_name'],$robot,$alliance,$station,$m['field_id'],$run]);
    $sid=(int)$pdo->lastInsertId();
}

$summary=['action_count'=>0,'score'=>0,'last_action'=>null];
$s=$pdo->prepare("SELECT COUNT(*) action_count,COALESCE(SUM(points),0) score FROM scouting_actions WHERE organization_id=? AND scout_session_id=? AND match_run_number=? AND deleted_at IS NULL");
$s->execute([$u['organization_id'],$sid,$run]);
if($row=$s->fetch()){$summary['action_count']=(int)$row['action_count'];$summary['score']=(float)$row['score'];}
$s=$pdo->prepare("SELECT action_name,action_code,result,points FROM scouting_actions WHERE organization_id=? AND scout_session_id=? AND match_run_number=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
$s->execute([$u['organization_id'],$sid,$run]);
if($row=$s->fetch())$summary['last_action']=$row;

$cfg=json_decode($m['config_json'],true)?:['buttons'=>[]];
$pageTitle='Scout #'.$robot;
include dirname(__DIR__).'/partials_header.php';
function layout_class(string $layout): string {
    $layout=['rect-1'=>'rect-1x1','rect-2'=>'rect-2x1','circle-1'=>'circle-1x1','circle-2'=>'circle-2x2'][$layout]??$layout;
    $ok=['rect-1x1','rect-1x2','rect-2x1','rect-1x4','rect-4x1','circle-1x1','circle-2x2','circle-4x4'];
    return in_array($layout,$ok,true)?'layout-'.$layout:'layout-rect-1x1';
}
?>
<div class="card scout-status-card">
  <div class="toolbar" style="justify-content:space-between">
    <div><b><?=e($m['event_name'])?> · <?=e(neptune_match_label($m))?></b><div class="muted"><?=e($alliance)?> Station <?=$station?> · Scouting run <?=$run?></div></div>
    <span class="pill <?=strtolower($alliance)?>">#<?=e($robot)?></span>
  </div>
  <div class="scout-status-bar">
    <div class="scout-side-stats scout-side-left">
      <div><span>Actions</span><b id="actionCount"><?=e($summary['action_count'])?></b></div>
      <div><span>Score</span><b id="scoreTotal"><?=e(rtrim(rtrim(number_format($summary['score'],2,'.',''),'0'),'.'))?></b></div>
    </div>
    <div class="scout-timer-center">
      <div class="timer" id="timer">--</div>
      <div class="timer-stage" id="stage">WAITING</div>
      <div class="transition-banner" id="transition">AUTO → TELEOP · <span id="transitionCount">0</span>s</div>
      <div class="muted" id="status" style="text-align:center;margin-top:6px">Waiting for Command…</div>
    </div>
    <div class="scout-side-stats scout-side-right">
      <div class="last-action-label">Last Action</div>
      <b id="lastActionName"><?=e($summary['last_action']['action_name']??$summary['last_action']['action_code']??'—')?></b>
      <span id="lastActionResult" class="last-action-result <?=strtolower((string)($summary['last_action']['result']??''))?>"><?=e(strtoupper((string)($summary['last_action']['result']??'NONE')))?></span>
      <small id="lastActionPoints" class="muted"><?php if($summary['last_action']):?><?=e(rtrim(rtrim(number_format((float)$summary['last_action']['points'],2,'.',''),'0'),'.'))?> pts<?php else:?>No action recorded<?php endif;?></small>
    </div>
  </div>
</div>
<div class="scout-grid" style="margin-top:14px" id="actionGrid">
<?php foreach(($cfg['buttons']??[]) as $b): if(empty($b['name'])||empty($b['code']))continue;$color=(string)($b['bgColor']??'#004977');$dark=in_array(strtolower($color),['#f7f3eb','#e3e8f0'],true);?>
  <button class="action <?=e(layout_class((string)($b['layout']??'rect-1x1')))?>" style="background:<?=e($color)?>;color:<?=$dark?'#17212a':'#fff'?>" data-json='<?=e(json_encode($b,JSON_HEX_APOS|JSON_HEX_QUOT))?>'><?=e($b['name'])?></button>
<?php endforeach;?>
</div>
<div class="swipe-overlay" id="overlay" aria-hidden="true">
  <div class="swipe-card active" id="swipeCard" role="dialog" aria-modal="true" aria-labelledby="selectedName">
    <div class="swipe-card-head"><h2 id="selectedName"></h2><button class="secondary swipe-close" id="cancel" type="button" aria-label="Close action"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="swipe-zone" id="swipeZone" tabindex="0">
      <span class="failure"><i class="fa-solid fa-arrow-left"></i> FAILURE</span>
      <strong>SWIPE</strong>
      <span class="success">SUCCESS <i class="fa-solid fa-arrow-right"></i></span>
    </div>
    <div class="swipe-repeat-stats">
      <div><span>This popup</span><b id="popupTotal">0</b></div>
      <div><span>Success</span><b id="popupSuccess">0</b></div>
      <div><span>Failure</span><b id="popupFailure">0</b></div>
      <div><span>Points</span><b id="popupPoints">0</b></div>
    </div>
    <div class="muted swipe-hint" id="swipeHint">Swipe repeatedly. Close when you are finished with this action.</div>
  </div>
</div>
<script>
const MID=<?=$mid?>,SID=<?=$sid?>;
let matchState={},selected=null,startX=0,queue=[],processing=false,popupToken=0;
let popupStats={total:0,success:0,failure:0,points:0};
const timer=document.querySelector('#timer'),overlay=document.querySelector('#overlay'),statusEl=document.querySelector('#status'),stageEl=document.querySelector('#stage'),transitionEl=document.querySelector('#transition'),transitionCount=document.querySelector('#transitionCount');
const actionCountEl=document.querySelector('#actionCount'),scoreTotalEl=document.querySelector('#scoreTotal'),lastActionName=document.querySelector('#lastActionName'),lastActionResult=document.querySelector('#lastActionResult'),lastActionPoints=document.querySelector('#lastActionPoints');
const popupTotal=document.querySelector('#popupTotal'),popupSuccess=document.querySelector('#popupSuccess'),popupFailure=document.querySelector('#popupFailure'),popupPoints=document.querySelector('#popupPoints'),swipeHint=document.querySelector('#swipeHint'),swipeZone=document.querySelector('#swipeZone');
function fmt(n){const x=Number(n||0);return Number.isInteger(x)?String(x):x.toFixed(2).replace(/0+$/,'').replace(/\.$/,'')}
function renderSummary(s){
  if(!s)return;
  actionCountEl.textContent=s.action_count??0;scoreTotalEl.textContent=fmt(s.score??0);
  const a=s.last_action;
  if(!a){lastActionName.textContent='—';lastActionResult.textContent='NONE';lastActionResult.className='last-action-result';lastActionPoints.textContent='No action recorded';return;}
  lastActionName.textContent=a.action_name||a.action_code||'Action';
  lastActionResult.textContent=String(a.result||'Neutral').toUpperCase();
  lastActionResult.className='last-action-result '+String(a.result||'neutral').toLowerCase();
  lastActionPoints.textContent=fmt(a.points||0)+' pts';
}
function renderPopupStats(){popupTotal.textContent=popupStats.total;popupSuccess.textContent=popupStats.success;popupFailure.textContent=popupStats.failure;popupPoints.textContent=fmt(popupStats.points)}
async function sync(){
  try{
    let r=await fetch('../api/match-state.php?match_id='+MID+'&session_id='+SID,{cache:'no-store'}),d=await r.json();
    matchState=d;
    const rem=d.remaining_seconds;
    timer.textContent=(rem===null||rem===undefined)?'--':Math.max(0,Math.ceil(rem));
    const stage=String(d.stage||d.state||'waiting').toUpperCase();stageEl.textContent=stage;
    transitionEl.style.display=d.stage==='transition'?'block':'none';transitionCount.textContent=Math.max(0,Math.ceil(d.transition_remaining_seconds||0));
    statusEl.textContent=d.state==='ready'?'Match ready — waiting for start':d.state==='paused'?'Command paused the match':d.stage==='transition'?'Field transition pause':d.state||'unknown';
    document.querySelectorAll('.action').forEach(b=>b.disabled=(d.stage==='transition'||d.state==='paused'||d.state==='ready'||d.state==='ended'));
    renderSummary(d.scout_summary);
    fetch('../api/heartbeat.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:SID})});
  }catch(e){statusEl.textContent='Connection problem — retrying';}
}
function openAction(button){
  selected=JSON.parse(button.dataset.json);popupToken++;document.querySelector('#selectedName').textContent=selected.name;
  popupStats={total:0,success:0,failure:0,points:0};renderPopupStats();swipeHint.textContent='Swipe repeatedly. Close when you are finished with this action.';
  overlay.style.display='flex';overlay.setAttribute('aria-hidden','false');swipeZone.focus();
}
function closeAction(){overlay.style.display='none';overlay.setAttribute('aria-hidden','true');selected=null}
document.querySelectorAll('.action').forEach(b=>b.addEventListener('click',()=>openAction(b)));
document.querySelector('#cancel').addEventListener('click',closeAction);
swipeZone.addEventListener('pointerdown',e=>{startX=e.clientX;swipeZone.setPointerCapture?.(e.pointerId)});
swipeZone.addEventListener('pointerup',e=>{const dx=e.clientX-startX;if(Math.abs(dx)<70)return;enqueue(dx>0?'Success':'Failure')});
swipeZone.addEventListener('keydown',e=>{if(e.key==='ArrowRight'){e.preventDefault();enqueue('Success')}if(e.key==='ArrowLeft'){e.preventDefault();enqueue('Failure')}if(e.key==='Escape'){e.preventDefault();closeAction()}});
function enqueue(result){
  if(!selected||matchState.stage==='transition'||matchState.state!=='running'){swipeHint.textContent='Action not recorded — match is not currently running.';return;}
  const action={...selected};
  const t=Math.max(0,Math.round(matchState.elapsed_seconds??0));
  const auto=matchState.stage==='auton';
  const pts=result==='Success'?Number(auto?action.autonPoints:action.teleopPoints):0;
  queue.push({action,result,points:pts,match_time_sec:t,token:popupToken});
  swipeHint.textContent=queue.length>1?`${queue.length} actions queued…`:'Saving…';
  if(navigator.vibrate)navigator.vibrate(55);
  processQueue();
}
async function processQueue(){
  if(processing||!queue.length)return;processing=true;
  while(queue.length){
    const item=queue[0];
    try{
      const r=await fetch('../api/record-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:SID,action:item.action,result:item.result,points:item.points,match_time_sec:item.match_time_sec})});
      const d=await r.json();
      if(!r.ok||d.status!=='success')throw new Error(d.message||'Save failed');
      queue.shift();if(item.token===popupToken&&overlay.style.display==='flex'){popupStats.total++;popupStats.points+=Number(item.points||0);if(item.result==='Success')popupStats.success++;else if(item.result==='Failure')popupStats.failure++;renderPopupStats();}renderSummary(d.scout_summary);
      swipeHint.textContent=queue.length?`${queue.length} action${queue.length===1?'':'s'} queued…`:`Recorded ${item.result.toLowerCase()}. Swipe again or close.`;
      swipeZone.classList.remove('flash-success','flash-failure');void swipeZone.offsetWidth;swipeZone.classList.add(item.result==='Success'?'flash-success':'flash-failure');
    }catch(err){queue.shift();swipeHint.textContent=err.message||'Save failed';statusEl.textContent=err.message||'Save failed';if(navigator.vibrate)navigator.vibrate([100,60,100]);}
  }
  processing=false;
}
sync();setInterval(sync,600);
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
