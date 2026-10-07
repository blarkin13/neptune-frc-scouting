<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_robot_recall.php';
require_once dirname(__DIR__).'/_event_selection.php';
require_once dirname(__DIR__).'/api/_robot-recall-competition.php';

header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$error='';
$notice='';
$publicOrg=max(1,(int)($config['app']['platform_organization_id']??1));
try{robot_recall_ensure_schema($pdo);rrc_ensure_schema($pdo);}catch(Throwable $e){$error='Robot Recall setup failed: '.$e->getMessage();}

if(session_status()!==PHP_SESSION_ACTIVE) @session_start();
if(empty($_SESSION['rr_public_create_csrf'])) $_SESSION['rr_public_create_csrf']=bin2hex(random_bytes(24));
$createCsrf=(string)$_SESSION['rr_public_create_csrf'];

$key=trim((string)($_GET['key']??''));
$hostRecord=null;
$session=null;
if($key!==''&&$error===''){
    $hostRecord=rrc_public_host_by_token($pdo,$key);
    if(!$hostRecord){$error='This practice host link is invalid or has expired.';}
    else{
        $session=robot_recall_host_session($pdo,(int)$hostRecord['session_id'],(int)$hostRecord['organization_id']);
        if(!$session)$error='This practice room could not be loaded.';
        else{
            $flag=rrc_session_flag($pdo,(int)$session['id'],(int)$hostRecord['organization_id']);
            if((string)($flag['play_mode']??'')!=='practice'){$session=null;$error='This room is no longer a public practice room.';}
        }
    }
}

$events=[];$defaultEventId=0;
if(!$session&&$error===''){
    try{$events=neptune_event_selector_rows($pdo,$publicOrg,0,null);}catch(Throwable $e){$events=[];}
    foreach($events as $ev){if((int)($ev['is_current']??0)===1){$defaultEventId=(int)$ev['id'];break;}}
    if($defaultEventId<=0&&$events)$defaultEventId=(int)$events[0]['id'];
}

$seasonYears=[];$currentYear=(int)date('Y');
if(!$session&&$error===''){
    try{
        if(robot_recall_table_exists($pdo,'augur_epa_team_directory'))$seasonYears=array_map('intval',$pdo->query('SELECT DISTINCT season_year FROM augur_epa_team_directory ORDER BY season_year DESC')->fetchAll(PDO::FETCH_COLUMN));
    }catch(Throwable $e){}
    if(!$seasonYears){try{$seasonYears=array_map('intval',$pdo->query('SELECT DISTINCT season_year FROM games ORDER BY season_year DESC')->fetchAll(PDO::FETCH_COLUMN));}catch(Throwable $e){}}
    if(!in_array($currentYear,$seasonYears,true))array_unshift($seasonYears,$currentYear);
    $seasonYears=array_values(array_unique(array_filter($seasonYears,static fn($y)=>$y>=1992&&$y<=$currentYear+1)));
}

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='create_practice'&&$error===''){
    try{
        if(!hash_equals($createCsrf,(string)($_POST['rr_create_csrf']??'')))throw new RuntimeException('This form expired. Refresh and try again.');
        if(trim((string)($_POST['website']??''))!=='')throw new RuntimeException('Could not create the practice room.');
        $fingerprint=rrc_public_host_fingerprint();
        if(!rrc_public_host_rate_ok($pdo,$fingerprint))throw new RuntimeException('Too many practice rooms were created from this device recently. Try again later.');

        $serviceUserId=rrc_public_service_user_id($pdo,$publicOrg);
        if($serviceUserId<=0)throw new RuntimeException('No Neptune owner/admin/strategy account is available to run public practice rooms.');

        $sourceMode=(string)($_POST['source_mode']??'event');
        if(!in_array($sourceMode,['event','all_cached'],true))$sourceMode='event';
        $eventId=(int)($_POST['event_id']??0);
        if($sourceMode==='event'){
            $valid=false;
            foreach($events as $ev){if((int)$ev['id']===$eventId){$valid=true;break;}}
            if(!$valid)throw new RuntimeException('Choose a valid event roster.');
        }else{$eventId=0;}

        $payload=[
            'title'=>substr(trim((string)($_POST['title']??'Robot Recall Practice')),0,160)?:'Robot Recall Practice',
            'source_mode'=>$sourceMode,
            'event_id'=>$eventId,
            'season_year'=>max(1992,min($currentYear+1,(int)($_POST['season_year']??$currentYear))),
            'question_count'=>max(3,min(30,(int)($_POST['question_count']??10))),
            'question_seconds'=>max(5,min(15,(int)($_POST['question_seconds']??10))),
        ];
        $new=robot_recall_create_session($pdo,$publicOrg,$serviceUserId,$payload);
        $sid=(int)($new['id']??0);
        if($sid<=0)throw new RuntimeException('Robot Recall did not return a new room.');
        rrc_mark_session_mode($pdo,$sid,$publicOrg,(int)($new['event_id']??$eventId),'practice');
        $token=rrc_create_public_host($pdo,$sid,$publicOrg,$serviceUserId,$fingerprint);
        header('Location: '.base_url('play/host.php?key='.rawurlencode($token)),true,303);exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}

$pageTitle=$session?'Host Robot Recall Practice':'Host Robot Recall';
$moduleName='SATURN';$hideChrome=true;$hideBreadcrumbs=true;$bodyClass='robot-recall-public robot-recall-public-host';
$pageStyles=['assets/css/robot-recall.css','assets/css/robot-recall-admin-v41.css','assets/css/robot-recall-live-v44.css','assets/css/robot-recall-public-v47.css'];
include dirname(__DIR__).'/partials_header.php';
?>
<div class="rr-public-host-shell">
  <header class="rr-public-brand"><a href="<?=e(base_url('play/'))?>"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><div><span>NEPTUNE</span><strong>Robot Recall</strong></div></a><span class="rr-practice-chip"><i class="fa-solid fa-gamepad"></i> PRACTICE</span></header>

  <?php if($error):?><div class="rr-public-alert bad"><i class="fa-solid fa-triangle-exclamation"></i><span><?=e($error)?></span></div><?php endif;?>
  <?php if($notice):?><div class="rr-public-alert good"><?=e($notice)?></div><?php endif;?>

  <?php if($session):?>
  <main class="rr-public-host-main" id="rrPublicHost" data-session-id="<?=(int)$session['id']?>" data-endpoint="<?=e(base_url('api/robot-recall-public-host.php'))?>" data-realtime-url="<?=e(base_url('rr-realtime'))?>" data-room="<?=e((string)$session['room_code'])?>" data-key="<?=e($key)?>">
    <section class="rr-public-host-grid">
      <div class="rr-public-control-card">
        <div class="rr-host-head">
          <div><span class="rr-kicker">PRACTICE GAME CONTROL</span><div class="rr-host-title-row"><h1><?=e((string)$session['title'])?></h1><span class="rr-practice-badge"><i class="fa-solid fa-gamepad"></i> PRACTICE</span></div><div class="muted"><?=e((string)($session['event_name']?:((string)$session['season_year'].' · All cached teams')))?></div></div>
          <div class="rr-room"><small>ROOM</small><strong><?=e((string)$session['room_code'])?></strong></div>
        </div>
        <div class="rr-status-grid">
          <div class="rr-status-card"><small>Status</small><strong id="rrStatusPill">Lobby</strong></div>
          <div class="rr-status-card"><small>Players</small><strong><i class="fa-solid fa-users"></i> <b id="rrPlayerCount">0</b></strong></div>
          <div class="rr-status-card"><small>Question</small><strong><i class="fa-solid fa-list-ol"></i> <b id="rrQuestionProgress">0 / <?=e($session['question_count'])?></b></strong></div>
          <div class="rr-status-card"><small>Answers</small><strong><i class="fa-solid fa-bolt"></i> <b id="rrAnswers">0</b></strong></div>
        </div>
        <div class="rr-host-current">
          <div class="rr-host-logo-wrap"><img id="rrHostLogo" alt="Current team logo" hidden></div>
          <div><div class="rr-countdown" id="rrCountdown">Ready</div><div class="muted" id="rrHostMessage">Waiting for players to join.</div></div>
        </div>
        <div class="toolbar rr-host-actions" id="rrActions">
          <button class="btn" type="button" data-action="start"><i class="fa-solid fa-play"></i> Start Game</button>
          <button class="btn" type="button" data-action="reveal"><i class="fa-solid fa-eye"></i> Reveal Answer</button>
          <button class="btn secondary" type="button" data-action="leaderboard"><i class="fa-solid fa-ranking-star"></i> Leaderboard</button>
          <button class="btn" type="button" data-action="next"><i class="fa-solid fa-forward-step"></i> Next Question</button>
          <button class="btn secondary" type="button" data-action="finish"><i class="fa-solid fa-flag-checkered"></i> End Game</button>
        </div>
        <div class="rr-public-links">
          <a class="btn secondary" target="_blank" rel="noopener noreferrer" href="<?=e(base_url('play/screen.php?room='.$session['room_code']))?>"><i class="fa-solid fa-display"></i> Audience Display</a>
          <button class="btn secondary" id="rrCopyJoin" type="button" data-url="<?=e(robot_recall_join_url((string)$session['room_code']))?>"><i class="fa-solid fa-link"></i> Copy Join Link</button>
          <button class="btn secondary" id="rrCopyHost" type="button"><i class="fa-solid fa-key"></i> Copy Host Link</button>
          <a class="btn secondary" href="<?=e(base_url('play/host.php'))?>"><i class="fa-solid fa-plus"></i> New Practice Game</a>
        </div>
        <div class="rr-public-host-secret"><i class="fa-solid fa-lock"></i><span><b>This page is the control password.</b> Anyone with this host link can run this practice room. It expires in 24 hours.</span></div>
      </div>
      <aside class="rr-public-side">
        <section class="rr-public-join-card"><span class="rr-kicker">PLAYER JOIN</span><h2>Scan to join</h2><div class="rr-host-qr-wrap"><canvas id="rrHostQr" aria-label="QR code to join Robot Recall"></canvas></div><div class="rr-host-room-code"><small>ROOM CODE</small><strong><?=e((string)$session['room_code'])?></strong></div><p class="muted">Players never need a Neptune login.</p></section>
        <section class="rr-public-leader-card"><span class="rr-kicker">CURRENT GAME</span><h2>Leaderboard</h2><div id="rrHostLeaderboard" class="rr-mini-board"><div class="muted">No players yet.</div></div></section>
      </aside>
    </section>
  </main>
  <script src="<?=e(base_url('assets/js/qrcode-v4l.js'))?>?v=1"></script>
  <script src="<?=e(base_url('assets/js/robot-recall-realtime-v44.js'))?>"></script>
  <script>
  (()=>{
    const host=document.getElementById('rrPublicHost');if(!host)return;
    const endpoint=host.dataset.endpoint,key=host.dataset.key,room=host.dataset.room,realtimeUrl=host.dataset.realtimeUrl||'', $=id=>document.getElementById(id);
    let state=null,busy=false,autoRevealKey='',autoNextKey='',refreshBusy=false,refreshQueued=false,realtime=null;
    const joinUrl=$('rrCopyJoin')?.dataset.url||'';
    try{if($('rrHostQr')&&joinUrl)NeptuneQR.draw($('rrHostQr'),joinUrl,{scale:6,quiet:4});}catch(e){const w=$('rrHostQr')?.parentElement;if(w)w.textContent='Room '+room;}
    function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
    function setButtons(st){document.querySelectorAll('#rrActions [data-action]').forEach(b=>{const a=b.dataset.action;let show=false;if(a==='start')show=st==='lobby';if(a==='reveal')show=st==='question';if(a==='leaderboard')show=st==='reveal';if(a==='next')show=st==='reveal'||st==='leaderboard';if(a==='finish')show=st!=='finished';b.hidden=!show;});}
    function render(s){state=s;$('rrPlayerCount').textContent=s.player_count||0;$('rrQuestionProgress').textContent=`${s.question_number||0} / ${s.question_count||0}`;$('rrAnswers').textContent=s.answers_received||0;$('rrStatusPill').textContent=(s.status||'lobby').replace(/^./,c=>c.toUpperCase());setButtons(s.status);
      const img=$('rrHostLogo');if(s.logo_url&&['question','reveal','leaderboard'].includes(s.status)){const raw=String(s.logo_url),src=window.NeptuneRobotRecallRealtime?.pixelUrl(raw)||raw;if(img.dataset.logo!==src){img.dataset.logo=src;img.dataset.fallback=raw;img.onerror=()=>{const f=img.dataset.fallback||'';img.onerror=null;if(f&&img.src!==f)img.src=f;};img.src=src;}img.hidden=false;}else{img.hidden=true;img.removeAttribute('src');img.dataset.logo='';}
      let msg='Waiting for players to join.';if(s.status==='question')msg='Answers are open.';if(s.status==='reveal')msg='Answer revealed. Next question starts automatically in 5 seconds.';if(s.status==='leaderboard')msg='Leaderboard is on the Audience Display.';if(s.status==='finished')msg='Practice game complete.';$('rrHostMessage').textContent=msg;
      $('rrHostLeaderboard').innerHTML=s.leaderboard?.length?s.leaderboard.map(p=>`<div><span><b>${p.rank}</b> ${esc(p.nickname)}</span><strong>${Number(p.score).toLocaleString()}</strong></div>`).join(''):'<div class="muted">No players yet.</div>';
      if(s.status==='reveal'){const q=String(s.question_number||0);if(autoNextKey!==q){autoNextKey=q;setTimeout(()=>{if(state?.status==='reveal'&&String(state?.question_number||0)===q)doAction('next',true);},5000);}}
    }
    function tick(){if(!state)return;const out=$('rrCountdown');if(state.status!=='question'||!state.deadline_ms){out.textContent=state.status==='lobby'?'Ready':state.status==='finished'?'Finished':state.status==='reveal'?'Answer Revealed':'Leaderboard';return;}const left=Math.max(0,(state.deadline_ms-Date.now())/1000);out.textContent=left.toFixed(left<10?1:0)+'s';const q=state.question_number+':'+state.deadline_ms;if(left<=0&&autoRevealKey!==q){autoRevealKey=q;doAction('reveal',true);}}
    async function refresh(){if(refreshBusy){refreshQueued=true;return;}refreshBusy=true;try{const r=await fetch(`${endpoint}?action=state&key=${encodeURIComponent(key)}&_=${Date.now()}`,{cache:'no-store'}),d=await r.json();if(d.ok)render(d.state);}catch(e){}finally{refreshBusy=false;if(refreshQueued){refreshQueued=false;setTimeout(refresh,0);}}}
    async function doAction(action,quiet=false){if(busy)return;busy=true;try{const body=new URLSearchParams({action,key}),r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Robot Recall action failed.');render(d.state);realtime?.poke('state',{action});}catch(e){if(!quiet){window.NeptuneUI?.toast?.(e.message||'Robot Recall action failed.','bad',{title:'Robot Recall'});}}finally{busy=false;}}
    document.querySelectorAll('#rrActions [data-action]').forEach(b=>b.addEventListener('click',()=>doAction(b.dataset.action)));
    $('rrCopyJoin')?.addEventListener('click',async e=>{try{await navigator.clipboard.writeText(e.currentTarget.dataset.url);window.NeptuneUI?.toast?.('Join link copied.','good',{title:'Robot Recall'});}catch(_){await window.NeptuneUI?.prompt?.('Copy this join link:',{title:'Copy join link',value:e.currentTarget.dataset.url,confirmText:'Done'});}});
    $('rrCopyHost')?.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(location.href);window.NeptuneUI?.toast?.('Private host link copied.','good',{title:'Robot Recall'});}catch(_){await window.NeptuneUI?.prompt?.('Copy this private host link:',{title:'Copy private host link',value:location.href,confirmText:'Done'});}});
    realtime=window.NeptuneRobotRecallRealtime?.create({url:realtimeUrl,room,onPoke:()=>refresh()})||null;
    refresh();setInterval(()=>{if(!realtime?.isOpen?.())refresh();},180);setInterval(()=>{if(realtime?.isOpen?.()&&document.visibilityState==='visible')refresh();},1500);setInterval(tick,100);window.addEventListener('beforeunload',()=>realtime?.close?.());
  })();
  </script>
  <?php else:?>
  <main class="rr-public-setup">
    <section class="rr-public-setup-intro"><span class="rr-screen-kicker">NO NEPTUNE LOGIN REQUIRED</span><h1>Host a Robot Recall practice game</h1><p>Create a private practice room for your team, classroom, meeting, or watch party. Practice scores never affect official Event Standings.</p><a href="<?=e(base_url('play/'))?>"><i class="fa-solid fa-arrow-left"></i> Back to Robot Recall</a></section>
    <section class="card rr-create-card rr-public-create-card">
      <form method="post" class="rr-create-form rr-setup-grid" id="rrCreateForm" autocomplete="off">
        <input type="hidden" name="action" value="create_practice"><input type="hidden" name="rr_create_csrf" value="<?=e($createCsrf)?>"><label class="rr-honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
        <label class="rr-field rr-field-wide"><span>Game title</span><input name="title" value="Robot Recall Practice" maxlength="160" required></label>
        <fieldset class="rr-field rr-team-pool rr-field-wide"><legend>Team pool</legend><div class="rr-segmented" id="rrSource"><label><input type="radio" name="source_mode" value="event" checked><span><i class="fa-solid fa-calendar-days"></i> Event roster</span></label><label><input type="radio" name="source_mode" value="all_cached"><span><i class="fa-solid fa-layer-group"></i> All cached logos</span></label></div></fieldset>
        <div class="rr-field rr-field-wide rr-event-field" id="rrEventField"><label for="rrEventSelect">Event</label><select id="rrEventSelect" name="event_id" required><?php if(!$events):?><option value="">No events available</option><?php else:?><?=neptune_event_options_html($events,$defaultEventId)?><?php endif;?></select><div class="rr-event-meta"><small>Use an event roster even when you are practicing away from the event.</small><?=neptune_history_toggle_html(neptune_selector_show_history(),'events')?></div></div>
        <label class="rr-field rr-field-wide" id="rrSeasonField" hidden><span>Season for team names</span><select name="season_year"><?php foreach($seasonYears as $y):?><option value="<?=$y?>" <?=$y===$currentYear?'selected':''?>><?=$y?></option><?php endforeach;?></select><small>Uses every locally cached logo.</small></label>
        <label class="rr-field rr-question-count"><span>Questions</span><input type="number" name="question_count" min="3" max="30" value="10" required></label>
        <fieldset class="rr-field rr-time-field"><legend>Time per question</legend><div class="rr-time-slider" id="rrTimeSlider" style="--rr-time-pct:50%"><div class="rr-time-slider-top"><span class="rr-time-hint">Fast</span><output id="rrTimeOutput" for="rrQuestionSeconds">10 seconds</output><span class="rr-time-hint">Relaxed</span></div><input type="range" id="rrQuestionSeconds" name="question_seconds" min="5" max="15" step="1" value="10"><div class="rr-time-ticks" aria-hidden="true"><?php for($sec=5;$sec<=15;$sec++):?><span><?=$sec?></span><?php endfor;?></div></div></fieldset>
        <div class="rr-practice-explain rr-field-wide"><i class="fa-solid fa-shield-halved"></i><span><b>Practice stays separate.</b> This room is always marked PRACTICE. It cannot change production Event Standings or be mistaken for an official event game.</span></div>
        <div class="rr-create-submit rr-field-wide"><button class="btn rr-primary-action" type="submit"><i class="fa-solid fa-gamepad"></i> Create Practice Room</button><span class="muted">The private host-control link lasts 24 hours.</span></div>
      </form>
    </section>
  </main>
  <script>
  (()=>{const range=document.getElementById('rrQuestionSeconds'),out=document.getElementById('rrTimeOutput'),slider=document.getElementById('rrTimeSlider');const syncTime=()=>{if(!range)return;const min=Number(range.min),max=Number(range.max),v=Number(range.value);slider?.style.setProperty('--rr-time-pct',(((v-min)/(max-min))*100)+'%');if(out)out.textContent=v+' seconds';};range?.addEventListener('input',syncTime);syncTime();const inputs=[...document.querySelectorAll('input[name="source_mode"]')],event=document.getElementById('rrEventField'),season=document.getElementById('rrSeasonField'),select=document.getElementById('rrEventSelect');const sync=()=>{const all=inputs.find(i=>i.checked)?.value==='all_cached';if(event)event.hidden=all;if(season)season.hidden=!all;if(select){select.disabled=all;select.required=!all;}};inputs.forEach(i=>i.addEventListener('change',sync));sync();})();
  </script>
  <?php endif;?>
</div>
<?php include dirname(__DIR__).'/partials_footer.php';
