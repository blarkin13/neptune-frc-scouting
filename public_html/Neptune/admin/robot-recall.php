<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_robot_recall.php';
require_once dirname(__DIR__).'/_event_selection.php';
require_once dirname(__DIR__).'/api/_robot-recall-competition.php';
$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];
$canManageTestData=in_array((string)$u['role'],['owner','admin'],true);
$error='';$notice='';
try{robot_recall_ensure_schema($pdo);rrc_ensure_schema($pdo);}catch(Throwable $e){$error='Robot Recall database setup failed: '.$e->getMessage();}
$legacyTestBackfill=0;
try{if($error==='')$legacyTestBackfill=rrc_mark_existing_sessions_test_once($pdo,$org);}catch(Throwable $e){$error='Could not mark previous Robot Recall games as test: '.$e->getMessage();}
if($legacyTestBackfill>0)$notice='Marked '.$legacyTestBackfill.' previous Robot Recall game'.($legacyTestBackfill===1?'':'s').' as TEST. Future games use the Testing Mode toggle.';

if(!function_exists('rr_series_ensure_schema')){
    function rr_series_ensure_schema(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS robot_recall_series_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            organization_id BIGINT UNSIGNED NOT NULL,
            previous_session_id BIGINT UNSIGNED NOT NULL,
            previous_room_code VARCHAR(16) NOT NULL,
            next_session_id BIGINT UNSIGNED NOT NULL,
            next_room_code VARCHAR(16) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_rr_series_previous_session (previous_session_id),
            KEY idx_rr_series_previous_room (previous_room_code),
            KEY idx_rr_series_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
try{if($error==='')rr_series_ensure_schema($pdo);}catch(Throwable $e){$error='Robot Recall continuation setup failed: '.$e->getMessage();}
if(isset($_GET['continued']))$notice='Next match created. Phones and the Audience Display will follow the new room automatically.';
if(isset($_GET['mode_updated']))$notice='Robot Recall room mode updated.';


if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='set_test_mode' && $error===''){
    try{
        verify_csrf();
        if(!$canManageTestData)throw new RuntimeException('Admin access is required to change Robot Recall test mode.');
        $targetSessionId=(int)($_POST['session_id']??0);
        $target=robot_recall_host_session($pdo,$targetSessionId,$org);
        if(!$target)throw new RuntimeException('Robot Recall room not found.');
        $flag=rrc_session_flag($pdo,$targetSessionId,$org);
        if((string)($flag['play_mode']??'production')==='practice')throw new RuntimeException('Public practice rooms stay PRACTICE and cannot be converted from this control.');
        $newTest=!empty($_POST['test_mode']);
        rrc_mark_session($pdo,$targetSessionId,$org,(int)($target['event_id']??0),$newTest);
        header('Location: '.base_url('admin/robot-recall.php?session_id='.$targetSessionId.'&mode_updated=1'));exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete_test_data' && $error===''){
    try{
        verify_csrf();
        if(!$canManageTestData)throw new RuntimeException('Admin access is required to delete Robot Recall test data.');
        $deleted=rrc_delete_test_data($pdo,$org);
        $notice=$deleted>0
            ? ('Deleted '.$deleted.' Robot Recall test game'.($deleted===1?'':'s').' and related test data.')
            : 'No Robot Recall test data was found.';
    }catch(Throwable $e){$error='Could not delete Robot Recall test data: '.$e->getMessage();}
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='next_match' && $error===''){
    try{
        verify_csrf();
        $previousId=(int)($_POST['session_id']??0);
        $previous=robot_recall_host_session($pdo,$previousId,$org);
        if(!$previous)throw new RuntimeException('The current Robot Recall room could not be loaded.');

        $sourceMode=(string)($previous['source_mode']??'');
        if(!in_array($sourceMode,['event','all_cached'],true)){
            $sourceMode=((int)($previous['event_id']??0)>0)?'event':'all_cached';
        }
        $previousFlag=rrc_session_flag($pdo,$previousId,$org);
        $previousMode=(string)($previousFlag['play_mode']??(((int)($previousFlag['is_test']??0)===1)?'test':'production'));
        $payload=[
            'title'=>(string)($previous['title']??'Robot Recall'),
            'source_mode'=>$sourceMode,
            'event_id'=>(int)($previous['event_id']??0),
            'season_year'=>(int)($previous['season_year']??date('Y')),
            'question_count'=>(int)($previous['question_count']??10),
            'question_seconds'=>(int)($previous['question_seconds']??15),
        ];
        $next=robot_recall_create_session($pdo,$org,(int)$u['id'],$payload);
        rrc_mark_session_mode($pdo,(int)$next['id'],$org,(int)($next['event_id']??$payload['event_id']),$previousMode);
        $stmt=$pdo->prepare("INSERT INTO robot_recall_series_links
            (organization_id,previous_session_id,previous_room_code,next_session_id,next_room_code)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              next_session_id=VALUES(next_session_id),
              next_room_code=VALUES(next_room_code),
              created_at=CURRENT_TIMESTAMP");
        $stmt->execute([
            $org,
            $previousId,
            (string)$previous['room_code'],
            (int)$next['id'],
            (string)$next['room_code'],
        ]);
        header('Location: '.base_url('admin/robot-recall.php?session_id='.(int)$next['id'].'&continued=1'));exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='create_game' && $error===''){
    try{
        verify_csrf();
        $session=robot_recall_create_session($pdo,$org,(int)$u['id'],$_POST);
        $isTest=!empty($_POST['test_mode']);
        rrc_mark_session($pdo,(int)$session['id'],$org,(int)($session['event_id']??($_POST['event_id']??0)),$isTest);
        header('Location: '.base_url('admin/robot-recall.php?session_id='.(int)$session['id']));exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}

$sessionId=(int)($_GET['session_id']??0);
$session=$sessionId>0&&$error===''?robot_recall_host_session($pdo,$sessionId,$org):null;
$events=$error===''?neptune_event_selector_rows($pdo,$org,0,null):[];
$defaultEventId=0;
foreach($events as $ev){if((int)($ev['is_current']??0)===1){$defaultEventId=(int)$ev['id'];break;}}
if($defaultEventId<=0 && $events)$defaultEventId=(int)$events[0]['id'];
$recent=$error===''?robot_recall_recent_sessions($pdo,$org,5):[];
$recentFlags=$error===''?rrc_flags_for_sessions($pdo,array_column($recent,'id'),$org):[];
$recentModes=$error===''?rrc_modes_for_sessions($pdo,array_column($recent,'id'),$org):[];
$sessionFlag=$session&&$error===''?rrc_session_flag($pdo,(int)$session['id'],$org):['is_test'=>0,'event_id'=>null,'play_mode'=>'production'];
$playMode=(string)($sessionFlag['play_mode']??(((int)($sessionFlag['is_test']??0)===1)?'test':'production'));
$isTestSession=$playMode==='test';
$isPracticeSession=$playMode==='practice';
$standingsEventId=$session?(int)($session['event_id']??0):0;
if($standingsEventId<=0)$standingsEventId=(int)($sessionFlag['event_id']??0);
$eventTopData=['rows'=>[],'meta'=>['completed_production_games'=>0,'completed_ranked_games'=>0,'unique_players'=>0,'scored_player_rows'=>0],'message'=>''];
if($session && $standingsEventId>0 && $error==='')$eventTopData=rrc_event_top10_details($pdo,$org,$standingsEventId);
elseif($session && $standingsEventId<=0)$eventTopData['message']='Event Standings require an event-linked room.';
if($isPracticeSession){
    $suffix=(string)($eventTopData['message']??'');
    $eventTopData['message']='Current room is PRACTICE and excluded from Event Standings.'.($suffix!==''?' '.$suffix:'');
}elseif($isTestSession){
    $suffix=(string)($eventTopData['message']??'');
    $eventTopData['message']='Current room is TEST. Test scores are included in Event Standings.'.($suffix!==''?' '.$suffix:'');
}
$eventTop10=$eventTopData['rows']??[];
$eventTopMeta=$eventTopData['meta']??[];
$eventTopMessage=(string)($eventTopData['message']??'');
$testSessionCount=$error===''?rrc_test_session_count($pdo,$org):0;
$seasonYears=[];
try{
    if(robot_recall_table_exists($pdo,'augur_epa_team_directory'))$seasonYears=array_map('intval',$pdo->query('SELECT DISTINCT season_year FROM augur_epa_team_directory ORDER BY season_year DESC')->fetchAll(PDO::FETCH_COLUMN));
}catch(Throwable $e){}
if(!$seasonYears){try{$seasonYears=array_map('intval',$pdo->query('SELECT DISTINCT season_year FROM games ORDER BY season_year DESC')->fetchAll(PDO::FETCH_COLUMN));}catch(Throwable $e){}}
$currentYear=(int)date('Y');if(!in_array($currentYear,$seasonYears,true))array_unshift($seasonYears,$currentYear);
$seasonYears=array_values(array_unique(array_filter($seasonYears,static fn($y)=>$y>=1992&&$y<=$currentYear+1)));

$pageTitle='Robot Recall';$moduleName='SATURN';$bodyClass=trim(($bodyClass??'').' robot-recall-page');$pageStyles=['assets/css/robot-recall-admin-v41.css','assets/css/robot-recall-live-v44.css','assets/css/robot-recall-standings-v46.css','assets/css/robot-recall-public-v47.css'];
include dirname(__DIR__).'/partials_header.php';
?>
<section class="module-page robot-recall-admin">
  <header class="module-page-header">
    <div>
      <div class="module-code">SATURN · AUDIENCE GAME</div>
      <h1>Robot Recall</h1>
      <p>Fast team-logo trivia with one clean setup and one clean live-control screen.</p>
    </div>
    <div class="toolbar">
      <a class="btn secondary" target="_blank" rel="noopener" href="<?=e(base_url('play/host.php'))?>"><i class="fa-solid fa-globe"></i> Public Practice Host</a>
      <?php if($session):?><a class="btn secondary" href="<?=e(base_url('admin/robot-recall.php'))?>"><i class="fa-solid fa-plus"></i> New Game Setup</a><?php endif;?>
    </div>
  </header>

  <?php if($error):?><div class="notice bad"><i class="fa-solid fa-triangle-exclamation"></i> <?=e($error)?></div><?php endif;?>
  <?php if($notice):?><div class="notice good"><?=e($notice)?></div><?php endif;?>

  <?php if($session):?>
  <section class="rr-host-grid<?=$isTestSession?' rr-test-session':''?><?=$isPracticeSession?' rr-practice-session':''?>" id="rrHost" data-session-id="<?=(int)$session['id']?>" data-endpoint="<?=e(base_url('api/robot-recall-host.php'))?>" data-series-endpoint="<?=e(base_url('api/robot-recall-series.php'))?>" data-event-top-endpoint="<?=e(base_url('api/robot-recall-event-top.php'))?>" data-realtime-url="<?=e(base_url('rr-realtime'))?>" data-room="<?=e((string)$session['room_code'])?>" data-test-mode="<?=$isTestSession?'1':'0'?>" data-play-mode="<?=e($playMode)?>" data-csrf="<?=e(csrf_token())?>">
    <div class="card rr-host-main">
      <div class="rr-host-head">
        <div><span class="rr-kicker">LIVE GAME CONTROL</span><div class="rr-host-title-row"><h2><?=e($session['title'])?></h2><?php if($isTestSession):?><span class="rr-test-badge"><i class="fa-solid fa-flask"></i> TEST MODE</span><?php elseif($isPracticeSession):?><span class="rr-practice-badge"><i class="fa-solid fa-gamepad"></i> PRACTICE</span><?php endif;?></div><div class="muted"><?=e($session['event_name']?:((string)$session['season_year'].' · All cached teams'))?></div></div>
        <div class="rr-room"><small>ROOM</small><strong><?=e($session['room_code'])?></strong></div>
      </div>
      <div class="rr-status-grid">
        <div class="rr-status-card"><small>Status</small><strong id="rrStatusPill">Lobby</strong></div>
        <div class="rr-status-card"><small>Players</small><strong><i class="fa-solid fa-users"></i> <b id="rrPlayerCount">0</b></strong></div>
        <div class="rr-status-card"><small>Question</small><strong><i class="fa-solid fa-list-ol"></i> <b id="rrQuestionProgress">0 / <?=e($session['question_count'])?></b></strong></div>
        <div class="rr-status-card"><small>Answers</small><strong><i class="fa-solid fa-bolt"></i> <b id="rrAnswers">0</b></strong></div>
      </div>
      <div class="rr-host-current" id="rrHostCurrent">
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
      <div class="rr-series-panel">
        <div class="rr-series-copy">
          <strong><i class="fa-solid fa-rotate-right"></i> Match flow</strong>
          <span>Start a clean match with the same event and settings. Player phones and the Audience Display automatically follow the new room.</span>
        </div>
        <div class="rr-series-actions">
          <button class="btn" id="rrNextMatch" type="button"><i class="fa-solid fa-forward-fast"></i> Start Next Match</button>
          <label class="rr-series-toggle" for="rrContinuous">
            <input type="checkbox" id="rrContinuous">
            <span><b>Continuous matches</b><small>When a match ends, build the next one automatically and start after players reconnect.</small></span>
          </label>
        </div>
        <div class="rr-series-countdown" id="rrSeriesCountdown" hidden></div>
      </div>
      <form method="post" id="rrNextMatchForm" hidden>
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="next_match">
        <input type="hidden" name="session_id" value="<?=(int)$session['id']?>">
      </form>
      <div class="toolbar rr-host-links">
        <a class="btn secondary" target="_blank" rel="noopener" href="<?=e(base_url('play/screen.php?room='.$session['room_code']))?>"><i class="fa-solid fa-display"></i> Open Audience Display</a>
        <button class="btn secondary" id="rrCopyJoin" type="button" data-url="<?=e(robot_recall_join_url((string)$session['room_code']))?>"><i class="fa-solid fa-link"></i> Copy Join Link</button>
        <a class="btn secondary" href="<?=e(base_url('admin/robot-recall.php'))?>"><i class="fa-solid fa-sliders"></i> New Setup</a>
        <?php if($canManageTestData && !$isPracticeSession):?><form method="post" class="rr-session-mode-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="set_test_mode"><input type="hidden" name="session_id" value="<?=(int)$session['id']?>"><input type="hidden" name="test_mode" value="<?=$isTestSession?'0':'1'?>"><button class="btn secondary" type="submit"><i class="fa-solid <?=$isTestSession?'fa-circle-check':'fa-flask'?>"></i> <?=$isTestSession?'Make Production':'Mark as Test'?></button></form><?php endif;?>
      </div>
    </div>
    <aside class="rr-host-side-stack">
      <section class="card rr-host-join-card">
        <span class="rr-kicker">PLAYER JOIN</span>
        <h3>Scan to join</h3>
        <div class="rr-host-qr-wrap"><canvas id="rrHostQr" aria-label="QR code to join Robot Recall"></canvas></div>
        <div class="rr-host-room-code"><small>ROOM CODE</small><strong><?=e($session['room_code'])?></strong></div>
        <p class="muted">No Neptune login required.</p>
      </section>
      <section class="card rr-host-side"><span class="rr-kicker">LEADERBOARD</span><h3>Current Game</h3><div id="rrHostLeaderboard" class="rr-mini-board"><div class="muted">No players yet.</div></div></section>
    </aside>
  </section>

  <section class="card rr-event-standings-feature<?=$isTestSession?' is-test':''?><?=$isPracticeSession?' is-practice':''?>" id="rrEventStandingsCard">
    <div class="rr-event-standings-head">
      <div>
        <span class="rr-kicker"><i class="fa-solid fa-trophy"></i> EVENT STANDINGS</span>
        <h2>Top 10<?=!empty($session['event_name'])?' · '.e((string)$session['event_name']):''?></h2>
        <p class="muted" id="rrEventTopMessage"><?=e($eventTopMessage!==''?$eventTopMessage:'Best completed score per player. Production and test games are ranked; practice games are excluded.')?></p>
      </div>
      <div class="rr-event-standings-stats">
        <span><b id="rrEventCompletedGames"><?=(int)($eventTopMeta['completed_ranked_games']??$eventTopMeta['completed_production_games']??0)?></b><small>ranked games</small></span>
        <span><b id="rrEventUniquePlayers"><?=(int)($eventTopMeta['unique_players']??0)?></b><small>players ranked</small></span>
        <em class="<?=$isTestSession?'test':($isPracticeSession?'practice':'production')?>" id="rrEventModeBadge"><?=$isTestSession?'TEST ROOM · RANKED':($isPracticeSession?'PRACTICE ROOM · EXCLUDED':'PRODUCTION + TEST')?></em>
      </div>
    </div>
    <div id="rrEventTop10" class="rr-event-standings-board">
      <?php if($eventTop10):?>
        <?php foreach($eventTop10 as $row):?>
          <div class="rr-event-standing-row rank-<?=(int)$row['rank']?>">
            <span class="rr-event-rank"><?=(int)$row['rank']?></span>
            <span class="rr-event-player"><b><?=e($row['nickname'])?></b><small><?=((int)($row['games']??0))?> completed game<?=((int)($row['games']??0)===1?'':'s')?></small></span>
            <strong><?=number_format((int)$row['best_score'])?><small>pts</small></strong>
          </div>
        <?php endforeach;?>
      <?php else:?>
        <div class="rr-event-standings-empty"><i class="fa-solid fa-ranking-star"></i><div><b>No event scores to rank yet</b><span><?=e($eventTopMessage!==''?$eventTopMessage:'Complete a production or test game to create the event standings.')?></span></div></div>
      <?php endif;?>
    </div>
  </section>
  <?php else:?>
  <section class="card rr-create-card">
    <div class="rr-section-head"><div><span class="rr-kicker">CREATE A ROOM</span><h2>Start a Robot Recall game</h2><p class="muted">Questions use logos already stored in Neptune. No live TBA request is made while the game is running.</p></div></div>
    <form method="post" class="rr-create-form rr-setup-grid" id="rrCreateForm">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create_game">
      <label class="rr-field rr-field-wide"><span>Game title</span><input name="title" value="Robot Recall" maxlength="160" required></label>

      <fieldset class="rr-field rr-team-pool rr-field-wide">
        <legend>Team pool</legend>
        <div class="rr-segmented" id="rrSource">
          <label><input type="radio" name="source_mode" value="event" checked><span><i class="fa-solid fa-calendar-days"></i> Event roster</span></label>
          <label><input type="radio" name="source_mode" value="all_cached"><span><i class="fa-solid fa-layer-group"></i> All cached logos</span></label>
        </div>
      </fieldset>

      <label class="rr-test-toggle rr-field-wide">
        <input type="checkbox" name="test_mode" value="1">
        <span class="rr-switch" aria-hidden="true"></span>
        <span><b><i class="fa-solid fa-flask"></i> Testing mode</b><small>Test rooms are marked TEST, never count toward Event Top 10, and can be deleted together from this page.</small></span>
      </label>

      <div class="rr-field rr-field-wide rr-event-field" id="rrEventField">
        <label for="rrEventSelect">Event</label>
        <select id="rrEventSelect" name="event_id" required>
          <?php if(!$events):?><option value="">No events available</option><?php else:?><?=neptune_event_options_html($events,$defaultEventId)?><?php endif;?>
        </select>
        <div class="rr-event-meta">
          <small>Past 18 months are shown by default.</small>
          <?=neptune_history_toggle_html(neptune_selector_show_history(),'events')?>
        </div>
      </div>
      <label class="rr-field rr-field-wide" id="rrSeasonField" hidden><span>Season for team names</span><select name="season_year"><?php foreach($seasonYears as $y):?><option value="<?=$y?>" <?=$y===$currentYear?'selected':''?>><?=$y?></option><?php endforeach;?></select><small>Uses every locally cached logo; the season selects the nickname directory.</small></label>

      <label class="rr-field rr-question-count"><span>Questions</span><input type="number" name="question_count" min="3" max="50" value="10" required></label>
      <fieldset class="rr-field rr-time-field">
        <legend>Time per question</legend>
        <div class="rr-time-slider" id="rrTimeSlider" style="--rr-time-pct:50%">
          <div class="rr-time-slider-top">
            <span class="rr-time-hint">Fast</span>
            <output id="rrTimeOutput" for="rrQuestionSeconds">10 seconds</output>
            <span class="rr-time-hint">Relaxed</span>
          </div>
          <input type="range" id="rrQuestionSeconds" name="question_seconds" min="5" max="15" step="1" value="10" aria-label="Time per question in seconds">
          <div class="rr-time-ticks" aria-hidden="true">
            <?php for($sec=5;$sec<=15;$sec++):?><span><?=$sec?></span><?php endfor;?>
          </div>
        </div>
      </fieldset>

      <div class="rr-create-submit rr-field-wide"><button class="btn rr-primary-action" type="submit"><i class="fa-solid fa-gamepad"></i> Create Game Room</button><span class="muted">Correct answers score 250–1,000 points based on speed.</span></div>
    </form>
  </section>
  <?php endif;?>

  <?php if(!$session && $recent):?><section class="rr-recent">
    <div class="rr-recent-head"><div><span class="rr-kicker">RECENT ROOMS</span><h2>Resume or review</h2></div><small>Newest 5</small></div>
    <div class="rr-session-list"><?php foreach($recent as $r):?>
      <a href="<?=e(base_url('admin/robot-recall.php?session_id='.(int)$r['id']))?>">
        <span class="rr-session-name"><b><?=e($r['title'])?> <?php $recentMode=(string)($recentModes[(int)$r['id']]??(!empty($recentFlags[(int)$r['id']])?'test':'production')); if($recentMode==='test'):?><em class="rr-test-inline">TEST</em><?php elseif($recentMode==='practice'):?><em class="rr-practice-inline">PRACTICE</em><?php endif;?></b><small><?=e($r['event_name']?:((string)$r['season_year'].' · All cached teams'))?></small></span>
        <strong class="rr-session-room"><?=e($r['room_code'])?></strong>
        <span class="rr-session-state"><?=e(ucfirst((string)$r['status']))?> · <?=e($r['player_count'])?> player<?=((int)$r['player_count']===1?'':'s')?></span>
        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
      </a>
    <?php endforeach;?></div>
  </section><?php endif;?>

  <?php if(!$session):?><section class="card rr-test-data-card">
    <div class="rr-test-data-copy"><span class="rr-kicker">TEST DATA</span><h2>Testing cleanup</h2><p class="muted">Test rooms are included in Event Standings so you can verify ranking before an event. Delete all Robot Recall test sessions and their related players, questions, answers, and series links when testing is finished; their scores will disappear from the standings.</p></div>
    <div class="rr-test-data-actions"><strong><?=$testSessionCount?> test game<?=$testSessionCount===1?'':'s'?></strong><?php if($testSessionCount>0 && $canManageTestData):?><form method="post" id="rrDeleteTestsForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_test_data"><button class="btn danger" type="button" id="rrDeleteTests"><i class="fa-solid fa-trash-can"></i> Delete All Test Data</button></form><?php elseif($testSessionCount>0):?><small class="muted">Admin+ required to delete test data.</small><?php endif;?></div>
  </section><?php endif;?>
</section>

<?php if($session):?><script src="<?=e(base_url('assets/js/qrcode-v4l.js'))?>?v=1"></script><script src="<?=e(base_url('assets/js/robot-recall-realtime-v44.js'))?>"></script><?php endif;?>
<script>
(()=>{
 // Robot Recall is a fixed-width app page. Safari may restore a previous horizontal scroll offset after CSS updates.
 try{document.documentElement.scrollLeft=0;document.body.scrollLeft=0;window.scrollTo(0,window.scrollY||0);}catch(e){}
 const timeRange=document.getElementById('rrQuestionSeconds');
 const timeOutput=document.getElementById('rrTimeOutput');
 const timeSlider=document.getElementById('rrTimeSlider');
 const syncTimeSlider=()=>{
   if(!timeRange)return;
   const min=Number(timeRange.min||5),max=Number(timeRange.max||15),value=Number(timeRange.value||10);
   const pct=max>min?((value-min)/(max-min))*100:0;
   if(timeSlider)timeSlider.style.setProperty('--rr-time-pct',pct+'%');
   if(timeOutput)timeOutput.textContent=value+' second'+(value===1?'':'s');
 };
 if(timeRange){timeRange.addEventListener('input',syncTimeSlider);timeRange.addEventListener('change',syncTimeSlider);syncTimeSlider();}

 const source=document.getElementById('rrSource'), eventField=document.getElementById('rrEventField'), seasonField=document.getElementById('rrSeasonField');
 const eventSelect=document.getElementById('rrEventSelect');
 const createForm=document.getElementById('rrCreateForm');
 const sourceInputs=source?[...source.querySelectorAll('input[name=\"source_mode\"]')]:[];
 const sync=()=>{
   const selected=sourceInputs.find(input=>input.checked);
   const all=selected?.value==='all_cached';
   if(eventField)eventField.hidden=!!all;
   if(seasonField)seasonField.hidden=!all;
   if(eventSelect){eventSelect.disabled=!!all;eventSelect.required=!all;}
 };
 sourceInputs.forEach(input=>input.addEventListener('change',sync));sync();
 createForm?.addEventListener('submit',e=>{
   const mode=sourceInputs.find(input=>input.checked)?.value||'event';
   if(mode==='event' && (!eventSelect || !eventSelect.value)){
     e.preventDefault();
     eventSelect?.focus();
     window.NeptuneUI?.toast('Choose an event for the Event roster team pool.','bad',{title:'Robot Recall'});
   }
 });
 const deleteTestsBtn=document.getElementById('rrDeleteTests');
 if(deleteTestsBtn){let armed=false;deleteTestsBtn.addEventListener('click',()=>{if(!armed){armed=true;deleteTestsBtn.innerHTML='<i class="fa-solid fa-triangle-exclamation"></i> Confirm Delete All Test Data';deleteTestsBtn.classList.add('is-armed');setTimeout(()=>{armed=false;deleteTestsBtn.innerHTML='<i class="fa-solid fa-trash-can"></i> Delete All Test Data';deleteTestsBtn.classList.remove('is-armed');},5000);return;}document.getElementById('rrDeleteTestsForm')?.submit();});}
 const host=document.getElementById('rrHost');if(!host)return;
 const endpoint=host.dataset.endpoint, sessionId=host.dataset.sessionId, csrf=host.dataset.csrf;
 const eventTopEndpoint=host.dataset.eventTopEndpoint||'',realtimeUrl=host.dataset.realtimeUrl||'',roomCode=host.dataset.room||'',isTestMode=host.dataset.testMode==='1';
 const $=id=>document.getElementById(id);let state=null,busy=false,autoRevealKey='',autoNextKey='',seriesTimer=null,seriesSubmitting=false,autoStartBegan=0,refreshBusy=false,refreshQueued=false,realtime=null;
 const continuousKey='neptune-robot-recall-continuous';
 const autoStartKey='neptune-robot-recall-autostart-next';
 const targetPlayersKey='neptune-robot-recall-target-players';
 const continuous=$('rrContinuous');
 if(continuous){
   try{continuous.checked=localStorage.getItem(continuousKey)==='1';}catch(_){}
   continuous.addEventListener('change',()=>{try{localStorage.setItem(continuousKey,continuous.checked?'1':'0');}catch(_){};if(!continuous.checked)cancelSeriesCountdown();else if(state?.status==='finished')scheduleNextMatch();});
 }

 const joinUrl=$('rrCopyJoin')?.dataset.url||'';
 try{if($('rrHostQr')&&joinUrl)NeptuneQR.draw($('rrHostQr'),joinUrl,{scale:6,quiet:4});}catch(e){const wrap=$('rrHostQr')?.parentElement;if(wrap)wrap.textContent='Room '+<?=json_encode((string)$session['room_code'])?>;}
 function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
 function cancelSeriesCountdown(){
   if(seriesTimer){clearInterval(seriesTimer);seriesTimer=null;}
   const box=$('rrSeriesCountdown');if(box){box.hidden=true;box.textContent='';}
 }
 function submitNextMatch(auto=false){
   if(seriesSubmitting)return;
   seriesSubmitting=true;cancelSeriesCountdown();
   try{
     sessionStorage.setItem(targetPlayersKey,String(Number(state?.player_count||0)));
     if(auto)sessionStorage.setItem(autoStartKey,'1');else sessionStorage.removeItem(autoStartKey);
   }catch(_){}
   $('rrNextMatchForm')?.submit();
 }
 function scheduleNextMatch(){
   if(!continuous?.checked||seriesSubmitting||state?.status!=='finished'||seriesTimer)return;
   let left=8;
   const box=$('rrSeriesCountdown');
   const draw=()=>{if(box){box.hidden=false;box.innerHTML=`Next match in <b>${left}</b>s <button type="button" id="rrCancelSeries">Cancel</button>`;$('rrCancelSeries')?.addEventListener('click',()=>{if(continuous)continuous.checked=false;try{localStorage.setItem(continuousKey,'0');}catch(_){}cancelSeriesCountdown();},{once:true});}};
   draw();
   seriesTimer=setInterval(()=>{left--;if(left<=0){cancelSeriesCountdown();submitNextMatch(true);return;}draw();},1000);
 }
 function maybeAutoStartNext(){
   let armed=false,target=0;
   try{armed=sessionStorage.getItem(autoStartKey)==='1';target=Number(sessionStorage.getItem(targetPlayersKey)||0);}catch(_){}
   if(!armed||state?.status!=='lobby')return;
   if(!autoStartBegan)autoStartBegan=Date.now();
   const enoughPlayers=target>0&&Number(state?.player_count||0)>=target;
   const waited=Date.now()-autoStartBegan>=8000;
   if(enoughPlayers||waited){
     try{sessionStorage.removeItem(autoStartKey);sessionStorage.removeItem(targetPlayersKey);}catch(_){}
     autoStartBegan=0;doAction('start',true);
   }
 }
 function setButtons(st){document.querySelectorAll('#rrActions [data-action]').forEach(b=>{const a=b.dataset.action;let show=false;if(a==='start')show=st==='lobby';if(a==='reveal')show=st==='question';if(a==='leaderboard')show=st==='reveal';if(a==='next')show=st==='reveal'||st==='leaderboard';if(a==='finish')show=st!=='finished';b.hidden=!show;});}
 function drawEventTop(rows,message='',meta={}){
   const board=$('rrEventTop10');if(!board)return;
   const msg=$('rrEventTopMessage'),games=$('rrEventCompletedGames'),players=$('rrEventUniquePlayers');
   if(msg)msg.textContent=message||'Best completed score per player. Production and test games are ranked; practice games are excluded.';
   if(games)games.textContent=Number(meta?.completed_ranked_games??meta?.completed_production_games??0).toLocaleString();
   if(players)players.textContent=Number(meta?.unique_players||0).toLocaleString();
   if(rows?.length){
     board.innerHTML=rows.map(p=>`<div class="rr-event-standing-row rank-${Number(p.rank||0)}"><span class="rr-event-rank">${Number(p.rank||0)}</span><span class="rr-event-player"><b>${esc(p.nickname)}</b><small>${Number(p.games||0)} completed game${Number(p.games||0)===1?'':'s'}</small></span><strong>${Number(p.best_score||0).toLocaleString()}<small>pts</small></strong></div>`).join('');
     return;
   }
   board.innerHTML=`<div class="rr-event-standings-empty"><i class="fa-solid fa-ranking-star"></i><div><b>No event scores to rank yet</b><span>${esc(message||'Complete a production or test game to create the event standings.')}</span></div></div>`;
 }
 async function refreshEventTop(){
   if(!eventTopEndpoint||!roomCode){return;}
   try{
     const r=await fetch(`${eventTopEndpoint}?room=${encodeURIComponent(roomCode)}&_=${Date.now()}`,{cache:'no-store'}),d=await r.json();
     if(d.ok)drawEventTop(d.rows||[],d.message||(d.event_id?'No completed production or test games for this event yet.':'This room is not linked to an event.'),d.meta||{});
     else drawEventTop([],d.message||'Event standings could not be loaded.',{});
   }catch(e){drawEventTop([], 'Event standings could not be loaded. Retrying automatically.',{});}
 }
 function render(s){state=s;$('rrPlayerCount').textContent=s.player_count;$('rrQuestionProgress').textContent=`${s.question_number} / ${s.question_count}`;$('rrAnswers').textContent=s.answers_received||0;$('rrStatusPill').textContent=(s.status||'').replace(/^./,c=>c.toUpperCase());setButtons(s.status);
   const img=$('rrHostLogo');if(s.logo_url&&['question','reveal','leaderboard'].includes(s.status)){const raw=String(s.logo_url),src=window.NeptuneRobotRecallRealtime?.pixelUrl(raw)||raw;if(img.dataset.logo!==src){img.dataset.logo=src;img.dataset.fallback=raw;img.onerror=()=>{const fallback=img.dataset.fallback||'';img.onerror=null;if(fallback&&img.src!==fallback)img.src=fallback;};img.src=src;}img.hidden=false;}else{img.hidden=true;img.removeAttribute('src');img.dataset.logo='';}
   let msg='Waiting for players to join.';if(s.status==='question')msg='Answers are open. Faster correct answers score more.';if(s.status==='reveal')msg='Answer revealed. Next question starts automatically in 5 seconds.';if(s.status==='leaderboard')msg='Leaderboard is on the Audience Display.';if(s.status==='finished')msg='Game complete.';$('rrHostMessage').textContent=msg;
   const board=$('rrHostLeaderboard');board.innerHTML=s.leaderboard?.length?s.leaderboard.map(p=>`<div><span><b>${p.rank}</b> ${esc(p.nickname)}</span><strong>${Number(p.score).toLocaleString()}</strong></div>`).join(''):'<div class="muted">No players yet.</div>';
   if(s.status==='reveal'){
     const key=String(s.question_number||0);
     if(autoNextKey!==key){
       autoNextKey=key;
       setTimeout(()=>{if(state?.status==='reveal'&&String(state?.question_number||0)===key)doAction('next',true);},5000);
     }
   }
   if(s.status==='finished'){scheduleNextMatch();refreshEventTop();}else cancelSeriesCountdown();
   maybeAutoStartNext();
 }
 function tick(){if(!state)return;const out=$('rrCountdown');if(state.status!=='question'||!state.deadline_ms){out.textContent=state.status==='lobby'?'Ready':state.status==='finished'?'Finished':state.status==='reveal'?'Answer Revealed':'Leaderboard';return;}const left=Math.max(0,(state.deadline_ms-Date.now())/1000);out.textContent=left.toFixed(left<10?1:0)+'s';const key=state.question_number+':'+state.deadline_ms;if(left<=0&&autoRevealKey!==key){autoRevealKey=key;doAction('reveal',true);}}
 async function refresh(){if(refreshBusy){refreshQueued=true;return;}refreshBusy=true;try{const r=await fetch(`${endpoint}?action=state&session_id=${encodeURIComponent(sessionId)}`,{credentials:'same-origin',cache:'no-store'});const d=await r.json();if(d.ok)render(d.state);}catch(e){}finally{refreshBusy=false;if(refreshQueued){refreshQueued=false;setTimeout(refresh,0);}}}
 async function doAction(action,quiet=false){if(busy)return;busy=true;try{const body=new URLSearchParams({action,session_id:sessionId,csrf});const r=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Robot Recall action failed.');if(d.state?.logo_url){const src=window.NeptuneRobotRecallRealtime?.pixelUrl(d.state.logo_url)||d.state.logo_url;const warm=new Image();warm.decoding='async';try{warm.fetchPriority='high';}catch(_){}warm.src=src;}render(d.state);realtime?.poke('state',{action});}catch(e){if(!quiet)window.NeptuneUI?.toast(e.message||'Robot Recall action failed.','bad',{title:'Robot Recall'});}finally{busy=false;}}
 document.querySelectorAll('#rrActions [data-action]').forEach(b=>b.addEventListener('click',()=>doAction(b.dataset.action)));
 $('rrNextMatch')?.addEventListener('click',async()=>{
   if(state?.status!=='finished')await doAction('finish',true);
   submitNextMatch(true);
 });
 $('rrCopyJoin')?.addEventListener('click',async e=>{try{await navigator.clipboard.writeText(e.currentTarget.dataset.url);window.NeptuneUI?.toast('Join link copied.','good',{title:'Robot Recall'});}catch(_){await window.NeptuneUI?.prompt?.('Copy this join link:',{title:'Copy join link',value:e.currentTarget.dataset.url,confirmText:'Done'});}});
 realtime=window.NeptuneRobotRecallRealtime?.create({url:realtimeUrl,room:roomCode,onPoke:()=>{refresh();refreshEventTop();}})||null;
 refresh();refreshEventTop();setInterval(()=>{if(!realtime?.isOpen?.())refresh();},180);setInterval(()=>{if(realtime?.isOpen?.()&&document.visibilityState==='visible')refresh();},1500);setInterval(refreshEventTop,2500);setInterval(tick,100);
 window.addEventListener('beforeunload',()=>realtime?.close?.());
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
