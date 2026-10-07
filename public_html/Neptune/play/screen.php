<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_robot_recall.php';
$room=robot_recall_clean_room((string)($_GET['room']??''));
$session=null;
try{if(robot_recall_table_exists($pdo,'robot_recall_sessions'))$session=robot_recall_room($pdo,$room);}catch(Throwable $e){}
$pageTitle='Robot Recall';$moduleName='SATURN';$hideChrome=true;$hideBreadcrumbs=true;$bodyClass='robot-recall-public robot-recall-screen';$pageStyles=['assets/css/robot-recall.css','assets/css/robot-recall-series.css','assets/css/robot-recall-live-v44.css','assets/css/robot-recall-screen-v45.css','assets/css/robot-recall-standings-v46.css','assets/css/robot-recall-realtime-status-v49.css'];
include dirname(__DIR__).'/partials_header.php';
?>
<div class="rr-screen-shell" id="rrScreen" data-room="<?=e($room)?>" data-endpoint="<?=e(base_url('api/robot-recall-public.php'))?>" data-series-endpoint="<?=e(base_url('api/robot-recall-series.php'))?>" data-event-top-endpoint="<?=e(base_url('api/robot-recall-event-top.php'))?>" data-realtime-url="<?=e(base_url('rr-realtime'))?>" data-join-url="<?=e($session?robot_recall_join_url($room):'')?>">
  <?php if($session):?><span class="rr-realtime-status" id="rrRealtimeStatus" data-state="connecting" role="status" aria-live="polite"><i class="rr-realtime-dot" aria-hidden="true"></i><span class="rr-realtime-copy"><small>REALTIME</small><b data-rr-realtime-label>CONNECTING</b></span></span><?php endif;?>
  <header class="rr-screen-top"><div class="rr-screen-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><div><span>NEPTUNE · AUDIENCE DISPLAY</span><strong><?=e($session['title']??'Robot Recall')?></strong></div></div><div class="rr-screen-room"><span>ROOM</span><strong><?=e($room?:'------')?></strong></div></header>
  <?php if(!$session):?>
    <main class="rr-screen-center"><div class="rr-screen-error"><i class="fa-solid fa-circle-question"></i><h1>Game room not found</h1><p>Check the room code and open Robot Recall again.</p></div></main>
  <?php else:?>
    <main class="rr-screen-stage">
      <section class="rr-screen-view rr-mc-intro" id="rrLobby">
        <div class="rr-mc-intro-copy">
          <span class="rr-screen-kicker">ROBOT RECALL · HOW TO PLAY</span>
          <h1>Recognize the robot.<br>Beat the clock.</h1>
          <p class="rr-mc-lede">A pixelated FRC team logo appears. Choose the correct team on your phone before time runs out.</p>
          <div class="rr-mc-rules">
            <article><span class="rr-mc-block rr-mc-grass">1</span><div><b>Study the logo</b><small>Each question shows one deliberately blocky team logo.</small></div></article>
            <article><span class="rr-mc-block rr-mc-diamond">2</span><div><b>Pick the team</b><small>Choose the matching FRC team from the four answers on your phone.</small></div></article>
            <article><span class="rr-mc-block rr-mc-gold">3</span><div><b>Be fast and right</b><small>Correct answers score points. Faster correct answers score more.</small></div></article>
          </div>
          <div class="rr-mc-punchline"><i class="fa-solid fa-cube"></i> The logo is distorted on purpose. Your scouting memory is not allowed to be.</div>
          <div class="rr-mc-lobby-meta"><div class="rr-lobby-code"><small>ROOM CODE</small><strong><?=e($room)?></strong></div><div class="rr-player-count"><i class="fa-solid fa-users"></i> <b id="rrLobbyPlayers">0</b> players joined</div></div>
        </div>
        <div class="rr-qr-card rr-mc-qr-card"><canvas id="rrQr" aria-label="QR code to join Robot Recall"></canvas><strong>Scan to join</strong><span><?=e(parse_url(robot_recall_join_url($room),PHP_URL_HOST)?:'Neptune')?> / play</span><small>Choose a nickname and wait for the host to start.</small></div>
      </section>
      <section class="rr-screen-view" id="rrQuestion" hidden>
        <div class="rr-question-head"><div><span class="rr-screen-kicker">QUESTION <b id="rrQNum">1</b> OF <b id="rrQTotal">10</b></span><h1 id="rrQuestionTitle">Whose logo is this?</h1></div><div class="rr-timer"><strong id="rrTimer">15.0</strong><small>SECONDS</small></div></div>
        <div class="rr-logo-stage"><img id="rrLogo" alt="Mystery FRC team logo" loading="eager" decoding="async" fetchpriority="high"></div>
        <div class="rr-screen-options" id="rrOptions"></div>
        <div class="rr-answer-meter"><div><span id="rrAnswerCount">0</span> answers locked in</div><div class="rr-meter-track"><span id="rrMeterFill"></span></div></div>
      </section>
      <section class="rr-screen-view" id="rrBoard" hidden><div class="rr-board-title"><span class="rr-screen-kicker" id="rrBoardKicker">LEADERBOARD</span><h1 id="rrBoardTitle">Top Players</h1><p id="rrRevealAnswer"></p></div><div class="rr-big-board" id="rrBigBoard"></div></section>
    </main>
  <?php endif;?>
</div>
<?php if($session):?>
<script src="<?=e(base_url('assets/js/qrcode-v4l.js'))?>?v=1"></script>
<script src="<?=e(base_url('assets/js/robot-recall-realtime-v49.js'))?>"></script>
<script>
(()=>{
 const shell=document.getElementById('rrScreen'),room=shell.dataset.room,endpoint=shell.dataset.endpoint,seriesEndpoint=shell.dataset.seriesEndpoint,eventTopEndpoint=shell.dataset.eventTopEndpoint||'',realtimeUrl=shell.dataset.realtimeUrl||'',joinUrl=shell.dataset.joinUrl;
 const $=id=>document.getElementById(id),lobby=$('rrLobby'),question=$('rrQuestion'),board=$('rrBoard');
 let state=null,lastLogo='',eventTopTimer=null,eventTopShownFor='',eventTopVisibleFor='',pollTimer=null,pollBusy=false,stopped=false,refreshQueued=false,realtime=null;
 function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
 function show(which){[lobby,question,board].forEach(x=>x&&(x.hidden=x!==which));}
 function renderBoard(s,title,kicker,answer){board.classList.remove('rr-event-standings-screen');show(board);$('rrBoardTitle').textContent=title;$('rrBoardKicker').textContent=kicker;$('rrRevealAnswer').textContent=answer||'';const rows=s.leaderboard||[];$('rrBigBoard').innerHTML=rows.length?rows.map((p,i)=>`<div class="rr-board-row ${i<3?'podium':''}"><span class="rr-rank">${p.rank}</span><strong>${esc(p.nickname)}</strong><span>${Number(p.score).toLocaleString()}</span></div>`).join(''):'<div class="rr-empty-board">No scores yet.</div>';}
 function renderEventTop(rows,eventName,message='',meta={}){
   board.classList.add('rr-event-standings-screen');show(board);
   $('rrBoardTitle').textContent='Event Standings';
   $('rrBoardKicker').textContent='ROBOT RECALL · TOP 10';
   const games=Number(meta?.completed_ranked_games??meta?.completed_production_games??0),players=Number(meta?.unique_players||0);
   const detail=[eventName||'',games?`${games} completed game${games===1?'':'s'}`:'',players?`${players} ranked player${players===1?'':'s'}`:'','Production + test games ranked'].filter(Boolean).join(' · ');
   $('rrRevealAnswer').textContent=detail||message||'Best completed score per player';
   $('rrBigBoard').innerHTML=rows.length?rows.map((p,i)=>`<div class="rr-board-row rr-event-row ${i<3?'podium':''}"><span class="rr-rank">${p.rank}</span><strong>${esc(p.nickname)}<small>${Number(p.games||0)} game${Number(p.games||0)===1?'':'s'}</small></strong><span>${Number(p.best_score||0).toLocaleString()}<small>pts</small></span></div>`).join(''):`<div class="rr-empty-board rr-event-empty"><strong>No event scores to rank yet</strong><span>${esc(message||'Complete a production or test game to create the standings.')}</span></div>`;
 }
 async function showEventTopAfterFinish(questionKey){
   if(!eventTopEndpoint||eventTopShownFor===questionKey)return;eventTopShownFor=questionKey;if(eventTopTimer)clearTimeout(eventTopTimer);
   eventTopTimer=setTimeout(async()=>{
     try{
       const r=await fetch(`${eventTopEndpoint}?room=${encodeURIComponent(room)}&_=${Date.now()}`,{cache:'no-store'}),d=await r.json();
       if(!d.ok||state?.status!=='finished'){eventTopShownFor='';return;}
       if(d.event_id){renderEventTop(d.rows||[],d.event_name||'',d.message||'',d.meta||{});eventTopVisibleFor=questionKey;return;}
       eventTopShownFor='';
     }catch(e){eventTopShownFor='';}
   },1600);
 }
 function setLogo(url){
   const img=$('rrLogo'),raw=String(url||'');if(!img||!raw)return;
   const next=window.NeptuneRobotRecallRealtime?.pixelUrl(raw)||raw;
   if(lastLogo===next)return;lastLogo=next;
   img.dataset.fallback=raw;
   img.onerror=()=>{const fallback=img.dataset.fallback||'';img.onerror=null;if(fallback&&img.src!==fallback)img.src=fallback;};
   img.src=next;
 }
 function requestRefresh(){clearTimeout(pollTimer);if(pollBusy){refreshQueued=true;return;}refresh();}
 function render(s){
   state=s;$('rrLobbyPlayers').textContent=s.player_count??0;
   if(s.status==='lobby'){show(lobby);return;}
   if(s.status==='question'||s.status==='reveal'){
     show(question);$('rrQNum').textContent=s.question_number;$('rrQTotal').textContent=s.question_count;$('rrAnswerCount').textContent=s.answers_received??0;$('rrQuestionTitle').textContent=s.status==='reveal'?(s.correct_label||'Correct answer'):'Whose logo is this?';
     const pct=s.player_count?Math.min(100,100*(s.answers_received||0)/s.player_count):0;$('rrMeterFill').style.width=pct+'%';setLogo(s.logo_url||'');
     $('rrOptions').innerHTML=(s.options||[]).map((o,i)=>`<div class="rr-screen-option ${s.status==='reveal'&&Number(o.team)===Number(s.correct_team_number)?'correct':''}"><span>${String.fromCharCode(65+i)}</span><strong>${esc(o.label)}</strong></div>`).join('');return;
   }
   if(s.status==='leaderboard'){renderBoard(s,'Top Players','LEADERBOARD','');return;}
   if(s.status==='finished'){const finishKey=String(s.question_number||0)+':finished';if(eventTopVisibleFor!==finishKey){renderBoard(s,'Final Standings','GAME COMPLETE','Event Standings are next.');showEventTopAfterFinish(finishKey);}followNextRoom();return;}
 }
 let followingNext=false;
 async function followNextRoom(){
   if(followingNext||!seriesEndpoint||!room)return;followingNext=true;
   try{const r=await fetch(`${seriesEndpoint}?room=${encodeURIComponent(room)}&_=${Date.now()}`,{cache:'no-store'}),d=await r.json();if(d.ok&&d.next_room&&String(d.next_room)!==String(room)){location.replace(`screen.php?room=${encodeURIComponent(String(d.next_room))}`);return;}}catch(e){}followingNext=false;
 }
 function tick(){if(!state||state.status!=='question'||!state.deadline_ms)return;const left=Math.max(0,(state.deadline_ms-Date.now())/1000);$('rrTimer').textContent=left.toFixed(left<10?1:0);}
 function nextDelay(){const live=!!realtime?.isOpen?.();if(document.hidden)return live?4000:1200;if(live){if(!state||state.status==='lobby')return 2500;if(state.status==='question')return 1400;if(state.status==='reveal')return 1500;return 2200;}if(!state||state.status==='lobby')return 220;if(state.status==='question')return 100;if(state.status==='reveal')return 140;return 280;}
 function schedulePoll(delay=nextDelay()){if(stopped)return;clearTimeout(pollTimer);pollTimer=setTimeout(refresh,delay);}
 async function refresh(){
   if(pollBusy){schedulePoll();return;}pollBusy=true;
   try{const r=await fetch(`${endpoint}?action=state&view=screen&room=${encodeURIComponent(room)}&_=${Date.now()}`,{cache:'no-store'}),d=await r.json();if(d.ok)render(d.state);}catch(e){}finally{pollBusy=false;if(refreshQueued){refreshQueued=false;setTimeout(refresh,0);}else schedulePoll();}
 }
 try{NeptuneQR.draw($('rrQr'),joinUrl,{scale:8,quiet:4});}catch(e){$('rrQr').replaceWith(document.createTextNode('Room '+room));}
 const realtimeView=window.NeptuneRobotRecallRealtime?.bindStatus?.($('rrRealtimeStatus'));
 realtime=window.NeptuneRobotRecallRealtime?.create({url:realtimeUrl,room,onStatus:realtimeView?.set,onTraffic:realtimeView?.traffic,onPoke:()=>requestRefresh()})||null;
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)requestRefresh();});window.addEventListener('beforeunload',()=>{stopped=true;clearTimeout(pollTimer);realtime?.close?.();});
 refresh();setInterval(tick,80);
})();
</script>
<?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php';
