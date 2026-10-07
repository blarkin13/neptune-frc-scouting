<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_scouting_realtime.php';
$u=require_login();
$org=(int)$u['organization_id'];
$uid=(int)$u['id'];

$s=$pdo->prepare("SELECT m.id,m.match_number,m.set_number,m.comp_level,m.field_id,m.state,m.run_number,e.name event_name,g.name game_name
  FROM matches m JOIN events e ON e.id=m.event_id JOIN games g ON g.id=m.game_id
  WHERE m.organization_id=? AND m.state IN ('ready','running','paused')
  ORDER BY FIELD(m.state,'running','paused','ready'),m.id DESC");
$s->execute([$org]);
$matches=$s->fetchAll();

$teamStmt=$pdo->prepare("SELECT mt.alliance,mt.station,mt.frc_team_number,
    COALESCE(NULLIF(et.nickname,''),NULLIF(t.nickname,''),'') AS nickname
  FROM match_teams mt
  JOIN matches mm ON mm.id=mt.match_id
  LEFT JOIN event_teams et ON et.event_id=mm.event_id AND et.frc_team_number=mt.frc_team_number
  LEFT JOIN teams t ON t.organization_id=mm.organization_id AND t.frc_team_number=mt.frc_team_number
  WHERE mt.match_id=?
  ORDER BY FIELD(mt.alliance,'Red','Blue'),mt.station");
foreach($matches as &$m){
    $teamStmt->execute([(int)$m['id']]);
    $m['teams']=$teamStmt->fetchAll();
}
unset($m);
$realtimeRoom=neptune_scouting_realtime_room($org);
$lobbySnapshot=neptune_scouting_lobby_snapshot($pdo,$org);
$lobbyVersion=(string)$lobbySnapshot['version'];

/*
 * Only show the Scouter CBT callout until this logged-in user has passed
 * at least one Scouter CBT. Completed-but-failed attempts still require
 * the callout so the user can retake the certification.
 */
$showScouterTraining=true;
try{
    $trainingStmt=$pdo->prepare("SELECT id
      FROM training_attempts
      WHERE organization_id=? AND user_id=? AND course_code='scouter' AND status='completed' AND passed=1
      ORDER BY completed_at DESC,id DESC
      LIMIT 1");
    $trainingStmt->execute([$org,$uid]);
    $showScouterTraining=!$trainingStmt->fetchColumn();
}catch(Throwable $trainingError){
    /* If the training table is not available yet, keep the callout visible. */
    $showScouterTraining=true;
}

function scout_match_label(array $m): string {
    $level=['qm'=>'Qual','ef'=>'Eighth','qf'=>'Quarter','sf'=>'Semi','f'=>'Final'][$m['comp_level']]??strtoupper($m['comp_level']);
    return $m['comp_level']==='qm' ? $level.' '.$m['match_number'] : $level.' '.$m['set_number'].'-'.$m['match_number'];
}
function scout_state_label(string $state): string {
    return ['running'=>'Live','paused'=>'Paused','ready'=>'Ready'][$state]??ucfirst($state);
}

$pageTitle='Match Scouting';
$moduleName='TRIDENT';
$pageStyles=['assets/css/neptune-realtime-v1.css'];
include dirname(__DIR__).'/partials_header.php';
?>
<style>
.match-scout-shell{--scout-red:#ff4d67;--scout-blue:#4f8cff;--scout-cyan:#38d7ff;--scout-glow:color-mix(in srgb,var(--module-trident,var(--accent,#0b79b7)) 42%,transparent)}
.match-scout-hero{position:relative;overflow:hidden;padding:24px;border:1px solid var(--line);border-radius:24px;background:linear-gradient(135deg,color-mix(in srgb,var(--panel,#111827) 86%,var(--module-trident,#0b79b7) 14%),var(--panel,#111827));box-shadow:0 18px 55px rgba(0,0,0,.14)}
.match-scout-hero:before,.match-scout-hero:after{content:"";position:absolute;border-radius:50%;pointer-events:none;filter:blur(3px)}
.match-scout-hero:before{width:340px;height:340px;right:-115px;top:-195px;background:radial-gradient(circle,var(--scout-glow),transparent 68%)}
.match-scout-hero:after{width:230px;height:230px;left:-125px;bottom:-170px;background:radial-gradient(circle,color-mix(in srgb,var(--scout-cyan) 25%,transparent),transparent 70%)}
.match-scout-kicker-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.match-scout-kicker{display:flex;align-items:center;gap:8px;font-size:.75rem;letter-spacing:.16em;font-weight:950;text-transform:uppercase;color:var(--muted)}
.match-scout-title{font-size:clamp(2.2rem,7vw,4.9rem);line-height:.9;margin:10px 0 12px;letter-spacing:-.065em}
.match-scout-title span{background:linear-gradient(90deg,var(--module-trident,var(--accent,#7c8cff)),var(--scout-cyan));-webkit-background-clip:text;background-clip:text;color:transparent}
.match-scout-sub{max-width:940px;margin:0;color:var(--muted);font-size:1rem;line-height:1.55}
.match-scout-steps{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}
.match-scout-step{display:inline-flex;align-items:center;gap:8px;min-height:36px;padding:7px 11px;border:1px solid var(--line);border-radius:999px;background:color-mix(in srgb,var(--panel2,var(--panel)) 78%,transparent);font-size:.76rem;font-weight:900;color:var(--muted)}
.match-scout-step i{color:var(--module-trident,var(--accent))}.match-scout-step strong{color:var(--text)}

.scout-training-alert{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:14px;padding:15px 16px;border:1px solid color-mix(in srgb,var(--warn) 45%,var(--line));border-radius:18px;background:linear-gradient(135deg,color-mix(in srgb,var(--warn) 9%,var(--panel)),var(--panel));box-shadow:0 10px 28px rgba(0,0,0,.08)}
.scout-training-copy{display:flex;align-items:center;gap:13px;min-width:0}.scout-training-icon{width:42px;height:42px;flex:0 0 42px;display:grid;place-items:center;border-radius:13px;background:color-mix(in srgb,var(--warn) 18%,var(--panel2));color:var(--warn);font-size:1.05rem}.scout-training-copy b{display:block;margin-bottom:2px}.scout-training-copy .muted{font-size:.88rem;line-height:1.4}.scout-training-actions{display:flex;gap:8px;flex:0 0 auto}

.match-scout-empty{margin-top:16px;text-align:center;padding:34px 20px}.match-scout-empty .empty-icon{width:58px;height:58px;margin:0 auto 12px;display:grid;place-items:center;border-radius:18px;background:color-mix(in srgb,var(--module-trident,var(--accent)) 12%,var(--panel2));color:var(--module-trident,var(--accent));font-size:1.45rem}.match-scout-empty h2{margin:0 0 5px}.match-scout-empty p{margin:0;color:var(--muted)}

.match-scout-match{margin-top:16px;padding:18px;border:1px solid var(--line);border-radius:22px;background:var(--panel,#111827);box-shadow:0 14px 36px rgba(0,0,0,.09)}
.match-scout-match.running{box-shadow:inset 0 3px 0 var(--good),0 14px 36px rgba(0,0,0,.09)}.match-scout-match.paused{box-shadow:inset 0 3px 0 var(--warn),0 14px 36px rgba(0,0,0,.09)}
.match-scout-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:14px}.match-scout-head h2{margin:0;font-size:clamp(1.55rem,3vw,2rem);letter-spacing:-.035em}.match-scout-event{margin-top:2px;color:var(--muted);font-size:.88rem}.match-scout-status{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.match-scout-status .pill.live{color:var(--good);border-color:color-mix(in srgb,var(--good) 45%,var(--line));background:color-mix(in srgb,var(--good) 9%,var(--panel2))}.match-scout-status .pill.paused{color:var(--warn);border-color:color-mix(in srgb,var(--warn) 45%,var(--line));background:color-mix(in srgb,var(--warn) 9%,var(--panel2))}

.match-scout-alliances{display:grid;grid-template-columns:1fr 1fr;gap:14px}.match-scout-alliance{border:1px solid var(--line);border-radius:19px;padding:12px;background:color-mix(in srgb,var(--panel2,var(--panel)) 58%,transparent)}.match-scout-alliance.red{box-shadow:inset 0 4px 0 var(--scout-red)}.match-scout-alliance.blue{box-shadow:inset 0 4px 0 var(--scout-blue)}.match-scout-alliance-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:9px}.match-scout-alliance-head b{font-size:.73rem;letter-spacing:.12em;text-transform:uppercase}.match-scout-alliance-head .alliance-count{font-size:.66rem;color:var(--muted);font-weight:850}
.match-scout-stations{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.match-scout-station{position:relative;overflow:hidden;display:flex;flex-direction:column;justify-content:center;align-items:flex-start;min-width:0;min-height:142px;padding:14px;border:1px solid var(--line);border-radius:16px;color:var(--text);text-decoration:none;background:linear-gradient(180deg,color-mix(in srgb,var(--panel,#111827) 95%,white 5%),var(--panel,#111827));transition:.16s transform,.16s border-color,.16s box-shadow}.match-scout-station:before{content:"";position:absolute;inset:0;opacity:.6;pointer-events:none}.match-scout-alliance.red .match-scout-station:before{background:linear-gradient(145deg,color-mix(in srgb,var(--scout-red) 12%,transparent),transparent 58%)}.match-scout-alliance.blue .match-scout-station:before{background:linear-gradient(145deg,color-mix(in srgb,var(--scout-blue) 12%,transparent),transparent 58%)}.match-scout-station:hover{transform:translateY(-2px);border-color:var(--module-trident,var(--accent));box-shadow:0 12px 28px rgba(0,0,0,.16),0 0 0 2px var(--scout-glow)}.match-scout-station>*{position:relative}.match-scout-station-name{font-size:.68rem;font-weight:950;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}.match-scout-team{display:block;margin:4px 0 1px;font-size:clamp(1.45rem,2.5vw,2.1rem);font-weight:1000;letter-spacing:-.045em}.match-scout-nick{width:100%;min-height:18px;font-size:.72rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.match-scout-go{display:flex;align-items:center;gap:6px;margin-top:auto;padding-top:12px;font-size:.71rem;font-weight:900;color:var(--text)}.match-scout-go i{font-size:.66rem;color:var(--module-trident,var(--accent))}
.match-scout-no-teams{margin:0}

@media(max-width:900px){.match-scout-alliances{grid-template-columns:1fr}.scout-training-alert{align-items:flex-start;flex-direction:column}.scout-training-actions{width:100%}.scout-training-actions .btn{flex:1;justify-content:center}}
@media(max-width:640px){.match-scout-hero{padding:17px;border-radius:19px}.match-scout-title{font-size:clamp(2.15rem,14vw,3.8rem)}.match-scout-steps{gap:6px}.match-scout-step{font-size:.69rem;padding:6px 9px}.match-scout-match{padding:12px;border-radius:18px}.match-scout-head{align-items:flex-start}.match-scout-status{max-width:45%}.match-scout-alliance{padding:9px}.match-scout-stations{gap:7px}.match-scout-station{min-height:132px;padding:10px;border-radius:14px}.match-scout-team{font-size:1.45rem}.match-scout-nick{font-size:.63rem}.match-scout-go{font-size:.64rem}.scout-training-copy{align-items:flex-start}.scout-training-alert{padding:13px}.scout-training-actions{display:grid;grid-template-columns:1fr 1fr}}
@media(max-width:390px){.match-scout-station{min-height:126px;padding:8px}.match-scout-team{font-size:1.28rem}.match-scout-station-name{font-size:.61rem}.match-scout-go span{display:none}}
@media(prefers-reduced-motion:reduce){.match-scout-station{transition:none}}
</style>

<div class="match-scout-shell">
  <section class="match-scout-hero">
    <div class="match-scout-kicker-row"><div class="match-scout-kicker"><i class="fa-solid fa-crosshairs"></i> TRIDENT · FIELD OPERATIONS</div><span class="neptune-realtime-pill" id="scoutLobbyRealtime" data-state="connecting"><span class="neptune-realtime-dot"></span><b data-realtime-label>CONNECTING</b></span></div>
    <h1 class="match-scout-title">Match <span>Scouting</span></h1>
    <p class="match-scout-sub">Find the field station you were assigned, tap that robot, and stay with it for the full match. Neptune pulls each station directly from the verified event schedule.</p>
    <div class="match-scout-steps" aria-label="Match scouting workflow">
      <span class="match-scout-step"><i class="fa-solid fa-location-crosshairs"></i><strong>1</strong> Find your station</span>
      <span class="match-scout-step"><i class="fa-solid fa-robot"></i><strong>2</strong> Confirm the robot</span>
      <span class="match-scout-step"><i class="fa-solid fa-hand-pointer"></i><strong>3</strong> Start scouting</span>
    </div>
  </section>

  <?php if($showScouterTraining):?>
  <section class="scout-training-alert" aria-label="Scouter training required">
    <div class="scout-training-copy">
      <div class="scout-training-icon"><i class="fa-solid fa-graduation-cap"></i></div>
      <div><b>Complete your Scouter Certification CBT</b><div class="muted">This reminder disappears automatically after you submit your first Scouter CBT attempt.</div></div>
    </div>
    <div class="scout-training-actions">
      <a class="btn secondary" href="<?=e(base_url('help/event-training.php'))?>"><i class="fa-solid fa-book-open"></i> Manual</a>
      <a class="btn" href="<?=e(base_url('help/training/cbt.php?course=scouter'))?>"><i class="fa-solid fa-laptop-file"></i> Scouter CBT</a>
    </div>
  </section>
  <?php endif;?>

  <?php if(!$matches):?>
    <section class="card match-scout-empty">
      <div class="empty-icon"><i class="fa-solid fa-hourglass-half"></i></div>
      <h2>Waiting for Command</h2>
      <p>No match has been made ready yet. This page will show the six field stations as soon as Command prepares a match.</p>
    </section>
  <?php endif;?>

  <?php foreach($matches as $m):?>
    <article class="match-scout-match <?=e((string)$m['state'])?>">
      <header class="match-scout-head">
        <div>
          <h2><?=e(scout_match_label($m))?></h2>
          <div class="match-scout-event"><i class="fa-regular fa-calendar"></i> <?=e($m['event_name'])?><?php if(!empty($m['field_id'])):?> · Field <?=e($m['field_id'])?><?php endif;?></div>
        </div>
        <div class="match-scout-status">
          <span class="pill <?=e($m['state']==='running'?'live':($m['state']==='paused'?'paused':''))?>"><?=e(scout_state_label((string)$m['state']))?></span>
          <?php if(($m['run_number']??1)>1):?><span class="pill"><i class="fa-solid fa-rotate-right"></i> Run <?=e($m['run_number'])?></span><?php endif;?>
        </div>
      </header>

      <?php if(!$m['teams']):?>
        <div class="notice match-scout-no-teams">No robots are attached to this match. Ask Command to load or verify the match assignments from TBA or the official manual schedule.</div>
      <?php else:?>
        <div class="match-scout-alliances">
          <?php foreach(['Red','Blue'] as $side):
            $sideTeams=array_values(array_filter($m['teams'],fn($t)=>($t['alliance']??'')===$side));
          ?>
          <section class="match-scout-alliance <?=strtolower($side)?>">
            <div class="match-scout-alliance-head"><b><?=$side?> Alliance</b><span class="alliance-count"><?=count($sideTeams)?> stations</span></div>
            <div class="match-scout-stations">
              <?php foreach($sideTeams as $t):
                $url='match.php?match_id='.(int)$m['id'].'&alliance='.rawurlencode($side).'&station='.(int)$t['station'];
              ?>
              <a class="match-scout-station" href="<?=e($url)?>">
                <span class="match-scout-station-name"><?=e($side.' '.$t['station'])?></span>
                <strong class="match-scout-team">#<?=e($t['frc_team_number'])?></strong>
                <span class="match-scout-nick"><?=e($t['nickname']?:'Robot '.$t['frc_team_number'])?></span>
                <span class="match-scout-go"><span>Scout this robot</span><i class="fa-solid fa-arrow-right"></i></span>
              </a>
              <?php endforeach;?>
            </div>
          </section>
          <?php endforeach;?>
        </div>
      <?php endif;?>
    </article>
  <?php endforeach;?>
</div>
<script src="<?=e(base_url('assets/js/neptune-realtime-v2.js?v=20261006-2'))?>"></script>
<script>
(()=>{
  const RT_URL=<?=json_encode(base_url('rr-realtime'))?>;
  const RT_ROOM=<?=json_encode($realtimeRoom)?>;
  const RT_AUTH_URL=<?=json_encode(base_url('api/realtime-token.php'))?>;
  const LOBBY_URL=<?=json_encode(base_url('api/scout-lobby-state.php'))?>;
  let lobbyVersion=<?=json_encode($lobbyVersion)?>;
  let checkBusy=false,checkQueued=false;
  let realtime=null;
  let currentRealtimeStatus='connecting';
  const rtStatus=window.NeptuneRealtime?.bindStatus('scoutLobbyRealtime')||{set:()=>{},traffic:()=>{}};

  async function checkLobby(){
    if(checkBusy){checkQueued=true;return;}
    checkBusy=true;
    try{
      const r=await fetch(LOBBY_URL+'?_='+Date.now(),{cache:'no-store',credentials:'same-origin'});
      const d=await r.json();
      if(!r.ok||d.status!=='success')throw new Error('Lobby state failed');
      const next=String(d.version||'');
      if(next&&next!==lobbyVersion){location.reload();return;}
      lobbyVersion=next||lobbyVersion;
      if(!realtime?.isOpen?.())rtStatus.set(currentRealtimeStatus);
    }catch(_){
      if(!realtime?.isOpen?.())rtStatus.set('offline');
    }finally{
      checkBusy=false;
      if(checkQueued){checkQueued=false;setTimeout(checkLobby,0);}
    }
  }

  realtime=window.NeptuneRealtime?.create({
    url:RT_URL,room:RT_ROOM,authUrl:RT_AUTH_URL,
    onStatus:(status,diag)=>{currentRealtimeStatus=status;rtStatus.set(status,diag);},
    onTraffic:(payload,diag)=>rtStatus.traffic(payload,diag),
    onPoke:payload=>{
      if(['match_state','match_schedule'].includes(String(payload?.type||'')))checkLobby();
    }
  })||null;

  setInterval(()=>{if(!realtime?.isOpen?.())checkLobby();},1500);
  setInterval(()=>{if(realtime?.isOpen?.()&&document.visibilityState==='visible')checkLobby();},15000);
  window.addEventListener('beforeunload',()=>realtime?.close?.());
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
