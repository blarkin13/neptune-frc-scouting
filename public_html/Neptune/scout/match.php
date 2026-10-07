<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_scouting_realtime.php';
$u=require_login();
$org=(int)$u['organization_id'];
$mid=(int)($_GET['match_id']??0);
$alliance=($_GET['alliance']??'Red')==='Blue'?'Blue':'Red';
$station=max(1,min(3,(int)($_GET['station']??1)));

$s=$pdo->prepare('SELECT m.*,e.name event_name,g.name game_name,gr.match_config_json config_json,gr.revision_number game_revision_number FROM matches m JOIN events e ON e.id=m.event_id JOIN games g ON g.id=m.game_id JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=m.game_id WHERE m.id=? AND m.organization_id=?');
$s->execute([$mid,$org]);
$m=$s->fetch();
if(!$m) exit('Match not found');
if(!in_array($m['state'],['ready','running','paused'],true)) exit('This match is not available for scouting.');

$s=$pdo->prepare('SELECT frc_team_number FROM match_teams WHERE match_id=? AND alliance=? AND station=?');
$s->execute([$mid,$alliance,$station]);
$robot=(int)$s->fetchColumn();
if(!$robot) exit('No robot is assigned to this station. Ask Command to check the event schedule.');
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
$actionColorRoles=[
    // Dedicated Game Builder action palette. fallbackVar keeps the page safe until the new palette is first saved.
    'game_primary'=>['cssVar'=>'--game-primary','fallbackVar'=>'--c1-blue'],
    'game_secondary'=>['cssVar'=>'--game-secondary','fallbackVar'=>'--accent'],
    'game_intake'=>['cssVar'=>'--game-intake','fallbackVar'=>'--module-org'],
    'game_defense'=>['cssVar'=>'--game-defense','fallbackVar'=>'--bad'],
    'game_coop'=>['cssVar'=>'--game-coop','fallbackVar'=>'--good'],
    'game_endgame'=>['cssVar'=>'--game-endgame','fallbackVar'=>'--module-augur'],
    'game_special'=>['cssVar'=>'--game-special','fallbackVar'=>'--module-saturn'],
    'game_utility'=>['cssVar'=>'--game-utility','fallbackVar'=>'--module-system'],
    // Backward compatibility for already-published revisions that used Brand, match & status roles.
    'primary'=>['cssVar'=>'--c1-blue','fallbackVar'=>''],
    'focus'=>['cssVar'=>'--accent','fallbackVar'=>''],
    'competition_red'=>['cssVar'=>'--c1-red','fallbackVar'=>''],
    'competition_green'=>['cssVar'=>'--c1-green','fallbackVar'=>''],
    'success'=>['cssVar'=>'--good','fallbackVar'=>''],
    'error'=>['cssVar'=>'--bad','fallbackVar'=>''],
    'warning'=>['cssVar'=>'--warn','fallbackVar'=>''],
    'ready'=>['cssVar'=>'--ready','fallbackVar'=>''],
    'blue_alliance'=>['cssVar'=>'--alliance-blue','fallbackVar'=>''],
    'blue_alliance_deep'=>['cssVar'=>'--alliance-blue-deep','fallbackVar'=>''],
];
$realtimeRoom=neptune_scouting_realtime_room($org);
$pageTitle='Match Scout #'.$robot;
$moduleName='TRIDENT';
$bodyClass=trim(($bodyClass??'').' match-scout-console-page');
$pageStyles=['assets/css/match-scout.css','assets/css/neptune-realtime-v1.css'];
include dirname(__DIR__).'/partials_header.php';

function layout_class(string $layout): string {
    $layout=['rect-1'=>'rect-1x1','rect-2'=>'rect-2x1','circle-1'=>'circle-1x1','circle-2'=>'circle-2x2'][$layout]??$layout;
    $ok=['rect-1x1','rect-1x2','rect-2x1','rect-1x4','rect-4x1','circle-1x1','circle-2x2','circle-4x4'];
    return in_array($layout,$ok,true)?'layout-'.$layout:'layout-rect-1x1';
}
function action_display_color(string $color): string {
    $color=trim($color);
    return preg_match('/^#[0-9a-fA-F]{6}$/',$color)?strtoupper($color):'#0072B2';
}
function action_role_css_vars(string $role): array {
    return match($role){
        'game_primary'=>['--game-primary','--c1-blue'],
        'game_secondary'=>['--game-secondary','--accent'],
        'game_intake'=>['--game-intake','--module-org'],
        'game_defense'=>['--game-defense','--bad'],
        'game_coop'=>['--game-coop','--good'],
        'game_endgame'=>['--game-endgame','--module-augur'],
        'game_special'=>['--game-special','--module-saturn'],
        'game_utility'=>['--game-utility','--module-system'],
        'primary'=>['--c1-blue',''],
        'focus'=>['--accent',''],
        'competition_red'=>['--c1-red',''],
        'competition_green'=>['--c1-green',''],
        'success'=>['--good',''],
        'error'=>['--bad',''],
        'warning'=>['--warn',''],
        'ready'=>['--ready',''],
        'blue_alliance'=>['--alliance-blue',''],
        'blue_alliance_deep'=>['--alliance-blue-deep',''],
        default=>['',''],
    };
}
function action_text_color(string $color): string {
    $hex=ltrim(action_display_color($color),'#');
    $rgb=[hexdec(substr($hex,0,2))/255,hexdec(substr($hex,2,2))/255,hexdec(substr($hex,4,2))/255];
    foreach($rgb as &$channel){
        $channel=$channel<=0.03928?$channel/12.92:(($channel+0.055)/1.055)**2.4;
    }
    unset($channel);
    $lum=0.2126*$rgb[0]+0.7152*$rgb[1]+0.0722*$rgb[2];
    $whiteContrast=1.05/($lum+0.05);
    $darkContrast=($lum+0.05)/0.05;
    return $darkContrast>=$whiteContrast?'#111827':'#FFFFFF';
}
function num_label($value): string {
    return rtrim(rtrim(number_format((float)$value,2,'.',''),'0'),'.');
}
?>

<section class="match-console-hero" id="matchConsole" data-stage="waiting">
  <div class="match-console-topline">
    <div class="match-console-eyebrow"><i class="fa-solid fa-crosshairs"></i><span>TRIDENT · LIVE MATCH SCOUTING</span></div>
    <div class="match-connection syncing" id="connectionPill"><span class="match-connection-dot"></span><span id="connectionText">SYNCING</span></div>
  </div>

  <div class="match-console-main">
    <div class="match-assignment match-assignment-left">
      <span class="match-assignment-label">Match</span>
      <strong><?=e(neptune_match_label($m))?></strong>
      <small><?=e($m['event_name'])?></small>
    </div>

    <div class="match-clock-block">
      <div class="match-clock" id="timer">--</div>
      <div class="match-phase-row">
        <span class="match-phase" id="stage">WAITING</span>
        <span class="match-transition" id="transition">AUTO → TELEOP · <b id="transitionCount">0</b>s</span>
      </div>
      <div class="match-status" id="status">Waiting for Command…</div>
    </div>

    <div class="match-assignment match-assignment-right">
      <span class="match-assignment-label">Robot</span>
      <strong>#<?=e($robot)?></strong>
      <span class="match-station <?=strtolower(e($alliance))?>"><?=e($alliance)?> <?=$station?></span>
    </div>
  </div>

  <div class="match-console-metrics">
    <div class="match-metric">
      <span><i class="fa-solid fa-bolt"></i> Actions</span>
      <b id="actionCount"><?=e($summary['action_count'])?></b>
    </div>
    <div class="match-metric">
      <span><i class="fa-solid fa-chart-simple"></i> Points</span>
      <b id="scoreTotal"><?=e(num_label($summary['score']))?></b>
    </div>
    <div class="match-metric match-last-action">
      <span><i class="fa-solid fa-clock-rotate-left"></i> Last action</span>
      <div class="match-last-action-value">
        <b id="lastActionName"><?=e($summary['last_action']['action_name']??$summary['last_action']['action_code']??'—')?></b>
        <span id="lastActionResult" class="match-result <?=strtolower((string)($summary['last_action']['result']??''))?>"><?=e(strtoupper((string)($summary['last_action']['result']??'NONE')))?></span>
        <small id="lastActionPoints"><?php if($summary['last_action']):?><?=e(num_label($summary['last_action']['points']))?> pts<?php else:?>No action recorded<?php endif;?></small>
      </div>
    </div>
    <button class="match-undo-pill" id="undoLast" type="button" <?=empty($summary['last_action'])?'disabled':''?> title="Undo your most recent recorded action">
      <i class="fa-solid fa-rotate-left"></i><span>Undo last</span>
    </button>
  </div>

  <div class="match-console-guide" aria-label="How to scout this match">
    <span><i class="fa-solid fa-eye"></i><b>1</b> Watch #<?=e($robot)?></span>
    <span><i class="fa-solid fa-hand-pointer"></i><b>2</b> Tap the action</span>
    <span><i class="fa-solid fa-arrows-left-right"></i><b>3</b> Swipe the result</span>
  </div>
</section>

<div class="match-grid-toolbar">
  <div>
    <span class="match-grid-kicker"><i class="fa-solid fa-grid-2"></i> ACTION GRID</span>
    <b id="gridPhaseLabel">Waiting for match</b>
  </div>
  <span class="match-grid-help">Button points automatically follow the current phase.</span>
</div>

<div class="match-action-shell">
  <div class="scout-grid match-action-grid" id="actionGrid">
  <?php foreach(($cfg['buttons']??[]) as $b):
      if(empty($b['name'])||empty($b['code']))continue;
      $colorRole=trim((string)($b['colorRole']??''));
      [$roleVar,$roleFallbackVar]=action_role_css_vars($colorRole);
      $fixedColor=action_display_color((string)($b['bgColor']??'#0072B2'));
      $displayColor=$roleVar!==''?($roleFallbackVar!==''?'var('.$roleVar.', var('.$roleFallbackVar.'))':'var('.$roleVar.')'):$fixedColor;
      $textColor=$roleVar!==''?'var(--solid-text)':action_text_color($fixedColor);
      $auton=(float)($b['autonPoints']??0);
      $teleop=(float)($b['teleopPoints']??0);
  ?>
    <button
      class="action match-action <?=e(layout_class((string)($b['layout']??'rect-1x1')))?>"
      style="--action-bg:<?=e($displayColor)?>;--action-fg:<?=e($textColor)?>;background:var(--action-bg);color:var(--action-fg)"
      data-color-role="<?=e($roleVar!==''?$colorRole:'custom')?>"
      data-fixed-color="<?=e($roleVar===''?$fixedColor:'')?>"
      data-auton="<?=e($auton)?>"
      data-teleop="<?=e($teleop)?>"
      data-json='<?=e(json_encode($b,JSON_HEX_APOS|JSON_HEX_QUOT))?>'
    >
      <span class="match-action-name"><?=e($b['name'])?></span>
      <span class="match-action-points" aria-hidden="true">0</span>
    </button>
  <?php endforeach;?>
  </div>
  <div class="match-grid-lock" id="gridLock" aria-live="polite">
    <div><i class="fa-solid fa-lock"></i><strong id="gridLockTitle">WAITING FOR MATCH START</strong><span id="gridLockText">Actions unlock when Command starts the match.</span></div>
  </div>
</div>

<div class="swipe-overlay match-swipe-overlay" id="overlay" aria-hidden="true">
  <div class="swipe-card match-swipe-card active" id="swipeCard" role="dialog" aria-modal="true" aria-labelledby="selectedName">
    <div class="match-swipe-accent" aria-hidden="true"></div>
    <div class="swipe-card-head match-swipe-head">
      <div>
        <span class="match-swipe-kicker">RECORD RESULT</span>
        <h2 id="selectedName"></h2>
      </div>
      <button class="secondary swipe-close" id="cancel" type="button" aria-label="Close action"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="swipe-zone match-swipe-zone" id="swipeZone" tabindex="0">
      <span class="failure match-swipe-result"><i class="fa-solid fa-arrow-left"></i><b>FAILURE</b><small>Swipe left</small></span>
      <strong class="match-swipe-center">SWIPE</strong>
      <span class="success match-swipe-result"><b>SUCCESS</b><small>Swipe right</small><i class="fa-solid fa-arrow-right"></i></span>
    </div>
    <div class="swipe-repeat-stats match-swipe-stats">
      <div><span>This popup</span><b id="popupTotal">0</b></div>
      <div><span>Success</span><b id="popupSuccess">0</b></div>
      <div><span>Failure</span><b id="popupFailure">0</b></div>
      <div><span>Points</span><b id="popupPoints">0</b></div>
    </div>
    <div class="muted swipe-hint" id="swipeHint">Swipe repeatedly. Close when you are finished with this action.</div>
  </div>
</div>

<script src="/admin/connection-guard.php?asset=js&amp;v=20260920-1"></script>
<script src="<?=e(base_url('assets/js/neptune-realtime-v2.js?v=20261006-2'))?>"></script>
<script>
const MID=<?=$mid?>,SID=<?=$sid?>,RT_URL=<?=json_encode(base_url('rr-realtime'))?>,RT_ROOM=<?=json_encode($realtimeRoom)?>,RT_AUTH_URL=<?=json_encode(base_url('api/realtime-token.php'))?>;
let matchState={},selected=null,startX=0,queue=[],processing=false,popupToken=0,lastStage='waiting',syncInFlight=false,syncQueued=false;
let realtime=null,realtimeStatus='connecting',httpHealthy=true,lastSyncClientMs=0;
let popupStats={total:0,success:0,failure:0,points:0};

const matchConsole=document.querySelector('#matchConsole');
const timer=document.querySelector('#timer'),overlay=document.querySelector('#overlay'),statusEl=document.querySelector('#status'),stageEl=document.querySelector('#stage'),transitionEl=document.querySelector('#transition'),transitionCount=document.querySelector('#transitionCount');
const connectionPill=document.querySelector('#connectionPill'),connectionText=document.querySelector('#connectionText');
const actionCountEl=document.querySelector('#actionCount'),scoreTotalEl=document.querySelector('#scoreTotal'),lastActionName=document.querySelector('#lastActionName'),lastActionResult=document.querySelector('#lastActionResult'),lastActionPoints=document.querySelector('#lastActionPoints'),undoLast=document.querySelector('#undoLast');
const popupTotal=document.querySelector('#popupTotal'),popupSuccess=document.querySelector('#popupSuccess'),popupFailure=document.querySelector('#popupFailure'),popupPoints=document.querySelector('#popupPoints'),swipeHint=document.querySelector('#swipeHint'),swipeZone=document.querySelector('#swipeZone'),swipeCard=document.querySelector('#swipeCard');
const gridLock=document.querySelector('#gridLock'),gridLockTitle=document.querySelector('#gridLockTitle'),gridLockText=document.querySelector('#gridLockText'),gridPhaseLabel=document.querySelector('#gridPhaseLabel');
const actionRoleVars=<?=json_encode($actionColorRoles,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;

function actionColorChannels(value){
  value=String(value||'').trim();
  if(/^#[0-9a-f]{3,8}$/i.test(value)){
    let h=value.slice(1);
    if(h.length===3||h.length===4)h=h.slice(0,3).split('').map(ch=>ch+ch).join('');
    if(h.length>=6)return [parseInt(h.slice(0,2),16),parseInt(h.slice(2,4),16),parseInt(h.slice(4,6),16)];
  }
  const m=value.match(/rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/i);
  return m?[Number(m[1]),Number(m[2]),Number(m[3])]:[0,114,178];
}
function actionForeground(bg){
  const ch=actionColorChannels(bg).map(x=>Math.max(0,Math.min(255,x))/255).map(x=>x<=.03928?x/12.92:Math.pow((x+.055)/1.055,2.4));
  const lum=.2126*ch[0]+.7152*ch[1]+.0722*ch[2],white=1.05/(lum+.05),black=(lum+.05)/.05;
  return black>=white?'#111827':'#FFFFFF';
}
function applyActionThemeColors(){
  const root=getComputedStyle(document.documentElement);
  document.querySelectorAll('.match-action').forEach(button=>{
    const role=button.dataset.colorRole||'custom';
    const spec=actionRoleVars[role]||null;
    const cssVar=spec&&typeof spec==='object'?(spec.cssVar||''):(spec||'');
    const fallbackVar=spec&&typeof spec==='object'?(spec.fallbackVar||''):'';
    const fixed=button.dataset.fixedColor||'#0072B2';
    const bg=(cssVar?root.getPropertyValue(cssVar).trim():'')||(fallbackVar?root.getPropertyValue(fallbackVar).trim():'')||fixed;
    button.style.setProperty('--action-bg',bg);
    button.style.setProperty('--action-fg',actionForeground(bg));
  });
}

function fmt(n){const x=Number(n||0);return Number.isInteger(x)?String(x):x.toFixed(2).replace(/0+$/,'').replace(/\.$/,'')}
function setConnection(mode,label){
  connectionPill.className='match-connection '+mode;
  connectionText.textContent=label;
}
function setRealtimeStatus(status){
  realtimeStatus=status;
  if(!httpHealthy){setConnection('offline','OFFLINE');return;}
  if(status==='socket')setConnection('live','SOCKET');
  else if(status==='connecting')setConnection('syncing','CONNECTING');
  else setConnection('polling','POLLING');
}
function renderSummary(s){
  if(!s)return;
  actionCountEl.textContent=s.action_count??0;
  scoreTotalEl.textContent=fmt(s.score??0);
  const a=s.last_action;
  undoLast.disabled=!a;
  if(!a){
    lastActionName.textContent='—';
    lastActionResult.textContent='NONE';
    lastActionResult.className='match-result';
    lastActionPoints.textContent='No action recorded';
    return;
  }
  lastActionName.textContent=a.action_name||a.action_code||'Action';
  lastActionResult.textContent=String(a.result||'Neutral').toUpperCase();
  lastActionResult.className='match-result '+String(a.result||'neutral').toLowerCase();
  lastActionPoints.textContent=fmt(a.points||0)+' pts';
}
function renderPopupStats(){
  popupTotal.textContent=popupStats.total;
  popupSuccess.textContent=popupStats.success;
  popupFailure.textContent=popupStats.failure;
  popupPoints.textContent=fmt(popupStats.points);
}
function phasePoints(button,stage){
  return Number(stage==='auton'?button.dataset.auton:button.dataset.teleop)||0;
}
function renderActionPoints(stage){
  const activeStage=['auton','teleop','endgame'].includes(stage)?stage:'teleop';
  document.querySelectorAll('.match-action').forEach(button=>{
    const points=phasePoints(button,activeStage);
    const badge=button.querySelector('.match-action-points');
    badge.textContent=(points>0?'+':'')+fmt(points);
    badge.classList.toggle('is-zero',points===0);
  });
}
function renderGridState(d){
  const stage=String(d.stage||d.state||'waiting').toLowerCase();
  lastStage=stage;
  matchConsole.dataset.stage=stage;
  renderActionPoints(stage);
  const locked=(stage==='transition'||d.state==='paused'||d.state==='ready'||d.state==='ended');
  document.querySelectorAll('.match-action').forEach(b=>b.disabled=locked);
  gridLock.classList.toggle('show',locked);

  let title='',text='',phaseLabel='';
  if(d.state==='ready'){
    title='WAITING FOR MATCH START';text='Actions unlock when Command starts the match.';phaseLabel='Waiting for match';
  }else if(d.state==='paused'){
    title='MATCH PAUSED';text='Command paused action recording. The clock is frozen.';phaseLabel='Paused';
  }else if(stage==='transition'){
    title='FIELD TRANSITION';text='Autonomous is complete. Teleop actions unlock after the transition.';phaseLabel='Transition';
  }else if(d.state==='ended'||stage==='ended'){
    title='MATCH COMPLETE';text='Action recording is closed for this run.';phaseLabel='Complete';
  }else if(stage==='auton'){
    phaseLabel='Autonomous · point values shown';
  }else if(stage==='endgame'){
    phaseLabel='Endgame · teleop point values shown';
  }else{
    phaseLabel='Teleop · point values shown';
  }
  gridLockTitle.textContent=title;
  gridLockText.textContent=text;
  gridPhaseLabel.textContent=phaseLabel;
}
function renderClockState(d){
  const rem=d.remaining_seconds;
  timer.textContent=(rem===null||rem===undefined)?'--':Math.max(0,Math.ceil(rem));
  const stage=String(d.stage||d.state||'waiting').toUpperCase();
  stageEl.textContent=stage;
  transitionEl.style.display=d.stage==='transition'?'inline-flex':'none';
  transitionCount.textContent=Math.max(0,Math.ceil(d.transition_remaining_seconds||0));
  if(!httpHealthy){
    statusEl.textContent=d.state==='ready'
      ?'Offline · waiting for match start'
      :d.state==='paused'
        ?'Offline · match remains paused from last sync'
        :d.stage==='transition'
          ?'Offline · field transition pause'
          :d.state==='running'
            ?'Offline · local match clock'
            :'Offline · last known match state';
  }else{
    statusEl.textContent=d.state==='ready'
      ?'Match ready · waiting for Command'
      :d.state==='paused'
        ?'Command paused the match'
        :d.stage==='transition'
          ?'Field transition pause'
          :d.stage==='endgame'
            ?'Endgame · stay with your robot'
            :d.stage==='auton'
              ?'Autonomous · watch your assigned robot'
              :d.state==='running'
                ?'Live · stay with your assigned robot'
                :d.state||'unknown';
  }
  renderGridState(d);
}
function renderState(d){
  matchState=d;
  lastSyncClientMs=Date.now();
  renderClockState(d);
  renderSummary(d.scout_summary);
}
function projectedState(){
  if(!matchState||!matchState.state)return matchState||{};
  const d={...matchState};
  if(d.state!=='running'||d.physical_elapsed_seconds===null||d.physical_elapsed_seconds===undefined||!d.timing)return d;
  const since=Math.max(0,(Date.now()-lastSyncClientMs)/1000);
  const physical=Math.max(0,Number(d.physical_elapsed_seconds||0)+since);
  const duration=Math.max(0,Number(d.timing.duration||0));
  const auton=Math.max(0,Number(d.timing.auton||0));
  const transition=Math.max(0,Number(d.timing.transition||0));
  const endgame=Math.max(0,Number(d.timing.endgame||0));
  let elapsed=0,stage='teleop',transitionRemaining=0;
  if(physical<auton){elapsed=physical;stage='auton';}
  else if(physical<(auton+transition)){elapsed=auton;stage='transition';transitionRemaining=(auton+transition)-physical;}
  else{elapsed=Math.min(duration,Math.max(0,physical-transition));stage=elapsed>=(duration-endgame)?'endgame':'teleop';}
  if(elapsed>=duration){elapsed=duration;stage='endgame';}
  d.physical_elapsed_seconds=physical;
  d.elapsed_seconds=elapsed;
  d.remaining_seconds=Math.max(0,duration-elapsed);
  d.stage=stage;
  d.transition_remaining_seconds=Math.max(0,transitionRemaining);
  return d;
}
function tickLocalClock(){
  if(!matchState.state)return;
  renderClockState(projectedState());
}
async function sync(){
  if(syncInFlight){syncQueued=true;return;}
  syncInFlight=true;
  if(!matchState.state)setConnection('syncing','SYNCING');
  try{
    const r=await fetch('../api/match-state.php?match_id='+MID+'&session_id='+SID,{cache:'no-store',credentials:'same-origin'});
    if(!r.ok)throw new Error('State sync failed');
    const d=await r.json();
    httpHealthy=true;
    renderState(d);
    setRealtimeStatus(realtimeStatus);
    fetch('../api/heartbeat.php',{
      method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:SID})
    }).catch(()=>{});
  }catch(e){
    httpHealthy=false;
    setConnection('offline','OFFLINE');
    statusEl.textContent='Connection problem · retrying';
  }finally{
    syncInFlight=false;
    if(syncQueued){syncQueued=false;setTimeout(sync,0);}
  }
}
function openAction(button){
  if(button.disabled)return;
  button.classList.remove('tap-feedback');void button.offsetWidth;button.classList.add('tap-feedback');
  selected=JSON.parse(button.dataset.json);
  popupToken++;
  document.querySelector('#selectedName').textContent=selected.name;
  swipeCard.style.setProperty('--selected-action-color',getComputedStyle(button).getPropertyValue('--action-bg').trim()||'#0072B2');
  popupStats={total:0,success:0,failure:0,points:0};
  renderPopupStats();
  swipeHint.textContent='Swipe repeatedly. Close when you are finished with this action.';
  overlay.style.display='flex';overlay.setAttribute('aria-hidden','false');
  document.body.classList.add('match-modal-open');
  swipeZone.focus();
}
function closeAction(){
  overlay.style.display='none';overlay.setAttribute('aria-hidden','true');selected=null;
  document.body.classList.remove('match-modal-open');
}
document.querySelectorAll('.match-action').forEach(b=>b.addEventListener('click',()=>openAction(b)));
document.querySelector('#cancel').addEventListener('click',closeAction);
overlay.addEventListener('pointerdown',e=>{if(e.target===overlay)closeAction()});
swipeZone.addEventListener('pointerdown',e=>{startX=e.clientX;swipeZone.setPointerCapture?.(e.pointerId)});
swipeZone.addEventListener('pointerup',e=>{const dx=e.clientX-startX;if(Math.abs(dx)<70)return;enqueue(dx>0?'Success':'Failure')});
swipeZone.addEventListener('keydown',e=>{
  if(e.key==='ArrowRight'){e.preventDefault();enqueue('Success')}
  if(e.key==='ArrowLeft'){e.preventDefault();enqueue('Failure')}
  if(e.key==='Escape'){e.preventDefault();closeAction()}
});
function enqueue(result){
  const local=projectedState();
  if(!selected||local.stage==='transition'||local.state!=='running'){
    swipeHint.textContent='Action not recorded · match is not currently running.';
    return;
  }
  const action={...selected};
  const t=Math.max(0,Math.round(local.elapsed_seconds??0));
  const auto=local.stage==='auton';
  const pts=result==='Success'?Number(auto?action.autonPoints:action.teleopPoints):0;
  queue.push({action,result,points:pts,match_time_sec:t,token:popupToken});
  swipeHint.textContent=queue.length>1?`${queue.length} actions queued…`:'Saving…';
  if(navigator.vibrate)navigator.vibrate(45);
  processQueue();
}
async function processQueue(){
  if(processing||!queue.length)return;
  processing=true;
  undoLast.disabled=true;
  while(queue.length){
    const item=queue[0];
    try{
      const r=await fetch('../api/record-action.php',{
        method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:SID,action:item.action,result:item.result,points:item.points,match_time_sec:item.match_time_sec})
      });
      const d=await r.json();
      if(!r.ok||d.status!=='success')throw new Error(d.message||'Save failed');
      queue.shift();
      if(item.token===popupToken&&overlay.style.display==='flex'){
        popupStats.total++;popupStats.points+=Number(item.points||0);
        if(item.result==='Success')popupStats.success++;else if(item.result==='Failure')popupStats.failure++;
        renderPopupStats();
      }
      renderSummary(d.scout_summary);
      realtime?.poke?.('scout_action',{match_id:MID,session_id:SID});
      swipeHint.textContent=queue.length?`${queue.length} action${queue.length===1?'':'s'} queued…`:`Recorded ${item.result.toLowerCase()}. Swipe again or close.`;
      swipeZone.classList.remove('flash-success','flash-failure');void swipeZone.offsetWidth;swipeZone.classList.add(item.result==='Success'?'flash-success':'flash-failure');
    }catch(err){
      queue.shift();
      swipeHint.textContent=err.message||'Save failed';statusEl.textContent=err.message||'Save failed';
      if(navigator.vibrate)navigator.vibrate([100,60,100]);
    }
  }
  processing=false;
  undoLast.disabled=!(Number(actionCountEl.textContent||0)>0);
}
async function undoLastAction(){
  if(processing||queue.length){statusEl.textContent='Wait for queued actions to finish before undoing.';return;}
  if(undoLast.disabled)return;
  undoLast.disabled=true;
  undoLast.classList.add('working');
  const original=statusEl.textContent;
  statusEl.textContent='Undoing last action…';
  try{
    const r=await fetch('../api/undo-last-action.php',{
      method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:SID})
    });
    const d=await r.json();
    if(!r.ok||d.status!=='success')throw new Error(d.message||'Undo failed');
    renderSummary(d.scout_summary);
    realtime?.poke?.('scout_action',{match_id:MID,session_id:SID,action:'undo'});
    statusEl.textContent=d.undone?.action_name?`Undid ${d.undone.action_name}`:'Last action undone';
    if(navigator.vibrate)navigator.vibrate(35);
    setTimeout(()=>{if(statusEl.textContent.startsWith('Undid')||statusEl.textContent==='Last action undone')statusEl.textContent=original},1300);
  }catch(err){
    statusEl.textContent=err.message||'Undo failed';
    undoLast.disabled=false;
  }finally{
    undoLast.classList.remove('working');
  }
}
undoLast.addEventListener('click',undoLastAction);

applyActionThemeColors();
renderActionPoints('teleop');
new MutationObserver(applyActionThemeColors).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});

realtime=window.NeptuneRealtime?.create({
  url:RT_URL,room:RT_ROOM,authUrl:RT_AUTH_URL,
  onStatus:status=>setRealtimeStatus(status),
  onPoke:payload=>{
    const type=String(payload?.type||'');
    const matchId=Number(payload?.match_id||0);
    // A state change may also end another match on the same field, so every
    // active scout refreshes authoritative state. Action traffic stays scoped.
    if(type==='match_state'){sync();return;}
    if(matchId&&matchId!==MID)return;
    if(['scout_action','admin_action','sync','poke'].includes(type))sync();
  }
})||null;

sync();
setInterval(()=>{if(!realtime?.isOpen?.())sync();},600);
setInterval(()=>{if(realtime?.isOpen?.()&&document.visibilityState==='visible')sync();},2000);
setInterval(tickLocalClock,150);
window.addEventListener('beforeunload',()=>realtime?.close?.());
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
