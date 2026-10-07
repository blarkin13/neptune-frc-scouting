<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$room=preg_replace('/\D+/','',(string)($_GET['room']??''))??'';
$pageTitle='Play Robot Recall';$moduleName='SATURN';$hideChrome=true;$hideBreadcrumbs=true;$bodyClass='robot-recall-public robot-recall-player';$pageStyles=['assets/css/robot-recall.css','assets/css/robot-recall-series.css','assets/css/robot-recall-live-v44.css','assets/css/robot-recall-public-v47.css','assets/css/robot-recall-realtime-status-v49.css'];
include dirname(__DIR__).'/partials_header.php';
?>
<div class="rr-player-shell" id="rrPlayerApp" data-endpoint="<?=e(base_url('api/robot-recall-public.php'))?>" data-series-endpoint="<?=e(base_url('api/robot-recall-series.php'))?>" data-realtime-url="<?=e(base_url('rr-realtime'))?>" data-room="<?=e($room)?>">
  <header class="rr-player-top"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><div><span>NEPTUNE</span><strong>Robot Recall</strong></div><span class="rr-realtime-status" id="rrRealtimeStatus" data-state="connecting" role="status" hidden aria-live="polite"><i class="rr-realtime-dot" aria-hidden="true"></i><span class="rr-realtime-copy"><small>REALTIME</small><b data-rr-realtime-label>CONNECTING</b></span></span></header>
  <main class="rr-player-main">
    <div class="rr-public-actions" id="rrPublicActions">
      <section class="rr-player-card rr-public-join-choice" id="rrJoinCard">
        <span class="rr-screen-kicker">PLAY WITH A ROOM CODE</span><h1>Join Robot Recall</h1><p>Enter the room code from the Audience Display and pick a nickname.</p>
        <form id="rrJoinForm">
          <label><span>Room code</span><input id="rrRoom" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?=e($room)?>" placeholder="123456" required autocomplete="one-time-code"></label>
          <label><span>Nickname</span><input id="rrNickname" maxlength="24" placeholder="Your nickname" required autocomplete="nickname"></label>
          <button class="rr-primary-button" type="submit"><i class="fa-solid fa-bolt"></i> Join Game</button>
        </form><div class="rr-player-error" id="rrJoinError" hidden></div>
      </section>
      <a class="rr-player-card rr-public-host-choice" href="<?=e(base_url('play/host.php'))?>">
        <span class="rr-screen-kicker">NO NEPTUNE LOGIN REQUIRED</span>
        <div class="rr-public-host-icon"><i class="fa-solid fa-gamepad"></i></div>
        <h2>Host a Practice Game</h2>
        <p>Create a room for your team, classroom, meeting, or watch party. Practice scores stay completely separate from official Event Standings.</p>
        <span class="rr-public-host-cta">Create Practice Room <i class="fa-solid fa-arrow-right"></i></span>
      </a>
    </div>

    <section class="rr-player-card rr-game-card" id="rrGameCard" hidden>
      <div class="rr-player-status"><span id="rrPlayerName">Player</span><strong id="rrPlayerScore">0 pts</strong></div>
      <div id="rrPlayerLobby" class="rr-mc-phone-lobby">
        <span class="rr-screen-kicker">YOU'RE IN</span>
        <h1>Waiting for the host</h1>
        <p><b id="rrLobbyCount">0</b> players are in the room.</p>
        <div class="rr-mc-phone-rules">
          <div><b>1</b><span>Recognize the pixelated robot logo.</span></div>
          <div><b>2</b><span>Choose the matching FRC team.</span></div>
          <div><b>3</b><span>Faster correct answers score more points.</span></div>
        </div>
        <div class="rr-mc-phone-note">No searching. No asking the person next to you. We are pretending there are officials.</div>
      </div>
      <div id="rrPlayerQuestion" hidden><div class="rr-phone-qhead"><span>QUESTION <b id="rrPhoneQNum">1</b>/<b id="rrPhoneQTotal">10</b></span><strong id="rrPhoneTimer">15.0</strong></div><h2>Whose logo is this?</h2><div class="rr-player-logo-slot rr-direct-player-logo" id="rrPhoneLogoSlot"><img id="rrPhoneLogo" alt="Mystery FRC team logo" loading="eager" decoding="async" fetchpriority="high" hidden></div><div class="rr-phone-options" id="rrPhoneOptions"></div><div class="rr-locked" id="rrLocked" hidden><i class="fa-solid fa-lock"></i> Answer locked in</div></div>
      <div id="rrPlayerReveal" hidden><span class="rr-screen-kicker">ANSWER</span><h1 id="rrRevealResult">Correct!</h1><p id="rrCorrectLabel"></p><div class="rr-points-earned" id="rrPointsEarned">+0</div><p class="muted" id="rrRankLine"></p></div>
      <div id="rrPlayerBoard" hidden><span class="rr-screen-kicker" id="rrPhoneBoardKicker">LEADERBOARD</span><h1 id="rrPhoneBoardTitle">Top Players</h1><div class="rr-phone-board" id="rrPhoneBoard"></div><p class="rr-your-rank" id="rrYourRank"></p></div>
    </section>
  </main>
</div>
<script src="<?=e(base_url('assets/js/robot-recall-realtime-v49.js'))?>"></script>
<script>
(()=>{
 const app=document.getElementById('rrPlayerApp'),endpoint=app.dataset.endpoint,seriesEndpoint=app.dataset.seriesEndpoint,realtimeUrl=app.dataset.realtimeUrl||'', $=id=>document.getElementById(id);
 let room=(app.dataset.room||'').replace(/\D/g,''),token='',state=null,answering=false,lastQuestion=0,switchingRoom=false,lastLogo='',pollTimer=null,pollBusy=false,stopped=false,refreshQueued=false,realtime=null;
 const storageKey=r=>'neptune-robot-recall:'+r;
 function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
 function loadSaved(){if(room.length!==6)return false;try{const x=JSON.parse(localStorage.getItem(storageKey(room))||'null');if(x?.token){token=x.token;$('rrNickname').value=x.nickname||'';return true;}}catch(e){}return false;}
 function save(nickname){try{localStorage.setItem(storageKey(room),JSON.stringify({token,nickname}));}catch(e){}}
 function showOnly(id){['rrPlayerLobby','rrPlayerQuestion','rrPlayerReveal','rrPlayerBoard'].forEach(x=>$(x).hidden=x!==id);}
 function boardHtml(rows){return (rows||[]).map(p=>`<div><span><b>${p.rank}</b> ${esc(p.nickname)}</span><strong>${Number(p.score).toLocaleString()}</strong></div>`).join('')||'<div class="muted">No scores yet.</div>';}
 function setQuestionLogo(url){
   const logo=$('rrPhoneLogo');if(!logo)return;
   const raw=String(url||'');
   if(!raw){logo.hidden=true;logo.removeAttribute('src');lastLogo='';return;}
   const nextSrc=window.NeptuneRobotRecallRealtime?.pixelUrl(raw)||raw;
   if(lastLogo!==nextSrc){
     lastLogo=nextSrc;
     logo.dataset.fallback=raw;
     logo.onerror=()=>{const fallback=logo.dataset.fallback||'';logo.onerror=null;if(fallback&&logo.src!==fallback)logo.src=fallback;};
     logo.hidden=false;
     logo.src=nextSrc;
   }else{logo.hidden=false;}
 }
 function startRealtime(){
   if(!room)return;
   const statusEl=$('rrRealtimeStatus');if(statusEl)statusEl.hidden=false;
   if(realtime){realtime.setRoom(room);return;}
   const realtimeView=window.NeptuneRobotRecallRealtime?.bindStatus?.($('rrRealtimeStatus'));
   realtime=window.NeptuneRobotRecallRealtime?.create({url:realtimeUrl,room,onStatus:realtimeView?.set,onTraffic:realtimeView?.traffic,onPoke:()=>requestState()})||null;
 }
 function requestState(){
   if(!room||!token)return;
   clearTimeout(pollTimer);
   if(pollBusy){refreshQueued=true;return;}
   fetchState();
 }
 async function screenState(){
   try{const r=await fetch(`${endpoint}?action=state&view=screen&room=${encodeURIComponent(room)}&_=${Date.now()}`,{cache:'no-store'}),d=await r.json();return d.ok&&d.state?d.state:null;}catch(e){return null;}
 }
 async function reconcilePlayerState(playerState){
   if(!playerState)return playerState;
   const suspicious=playerState.status==='finished' || (playerState.status==='question'&&!playerState.logo_url);
   if(!suspicious)return playerState;
   const screen=await screenState();
   if(!screen)return playerState;
   const active=['lobby','question','reveal','leaderboard'];
   if(playerState.status==='finished'&&active.includes(screen.status)){
     return {...screen,player:playerState.player,player_rank:playerState.player_rank,answer:playerState.answer??null,leaderboard:screen.leaderboard??playerState.leaderboard??[]};
   }
   if(playerState.status==='question'&&screen.status==='question'&&!playerState.logo_url){
     return {...playerState,logo_url:screen.logo_url||'',options:playerState.options?.length?playerState.options:(screen.options||[]),deadline_ms:playerState.deadline_ms||screen.deadline_ms};
   }
   return playerState;
 }
 function render(s){
   state=s;$('rrPublicActions').hidden=true;$('rrGameCard').hidden=false;$('rrPlayerName').textContent=s.player?.nickname||'Player';$('rrPlayerScore').textContent=Number(s.player?.score||0).toLocaleString()+' pts';
   if(s.status==='lobby'){showOnly('rrPlayerLobby');$('rrLobbyCount').textContent=s.player_count||0;return;}
   if(s.status==='question'){
      showOnly('rrPlayerQuestion');$('rrPhoneQNum').textContent=s.question_number;$('rrPhoneQTotal').textContent=s.question_count;if(lastQuestion!==s.question_number){lastQuestion=s.question_number;answering=false;$('rrLocked').textContent='Answer locked in';}
      setQuestionLogo(s.logo_url||'');
      const answered=!!s.answer;$('rrLocked').hidden=!answered;$('rrPhoneOptions').innerHTML=(s.options||[]).map((o,i)=>`<button type="button" data-team="${Number(o.team)}" ${answered?'disabled':''} class="${answered&&Number(s.answer.selected_team_number)===Number(o.team)?'selected':''}"><span>${String.fromCharCode(65+i)}</span><strong>${esc(o.label)}</strong></button>`).join('');$('rrPhoneOptions').querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>submitAnswer(Number(b.dataset.team))));return;
   }
   if(s.status==='reveal'){showOnly('rrPlayerReveal');const a=s.answer,correct=!!a?.is_correct;$('rrRevealResult').textContent=a?(correct?'Correct!':'Not this time'):'Time ran out';$('rrRevealResult').className=correct?'good-text':'bad-text';$('rrCorrectLabel').textContent=s.correct_label||'';$('rrPointsEarned').textContent=a&&a.points_awarded?('+'+Number(a.points_awarded).toLocaleString()):'+0';$('rrRankLine').textContent=s.player_rank?`You're #${s.player_rank} with ${Number(s.player?.score||0).toLocaleString()} points.`:'';return;}
   if(s.status==='leaderboard'||s.status==='finished'){showOnly('rrPlayerBoard');$('rrPhoneBoardKicker').textContent=s.status==='finished'?'GAME COMPLETE':'LEADERBOARD';$('rrPhoneBoardTitle').textContent=s.status==='finished'?'Final Standings':'Top Players';$('rrPhoneBoard').innerHTML=boardHtml(s.leaderboard);$('rrYourRank').textContent=s.player_rank?`You are #${s.player_rank} · ${Number(s.player?.score||0).toLocaleString()} points`:'';if(s.status==='finished')followNextRoom();return;}
 }
 async function followNextRoom(){
   if(switchingRoom||!seriesEndpoint||!room||!state?.player?.nickname)return;
   switchingRoom=true;
   try{
     const r=await fetch(`${seriesEndpoint}?room=${encodeURIComponent(room)}&_=${Date.now()}`,{cache:'no-store'}),d=await r.json();
     if(!d.ok||!d.next_room){switchingRoom=false;return;}
     const nextRoom=String(d.next_room).replace(/\D/g,'');if(nextRoom.length!==6||nextRoom===room){switchingRoom=false;return;}
     const nickname=state.player.nickname,body=new URLSearchParams({action:'join',room:nextRoom,nickname});
     const jr=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body}),jd=await jr.json();
     if(!jr.ok||!jd.ok)throw new Error(jd.message||'Could not join the next match.');
     room=nextRoom;token=jd.token;lastQuestion=0;lastLogo='';answering=false;save(jd.nickname||nickname);app.dataset.room=room;$('rrRoom').value=room;history.replaceState(null,'',`?room=${room}`);realtime?.setRoom(room);realtime?.poke('join');switchingRoom=false;await fetchState();
   }catch(e){switchingRoom=false;}
 }
 function tick(){if(!state||state.status!=='question'||!state.deadline_ms)return;const left=Math.max(0,(state.deadline_ms-Date.now())/1000);$('rrPhoneTimer').textContent=left.toFixed(left<10?1:0);if(left<=0)$('rrPhoneOptions').querySelectorAll('button').forEach(b=>b.disabled=true);}
 function nextDelay(){const live=!!realtime?.isOpen?.();if(document.hidden)return live?4000:1200;if(live){if(!state||state.status==='lobby')return 2500;if(state.status==='question')return 1500;if(state.status==='reveal')return 1600;return 2200;}if(!state||state.status==='lobby')return 250;if(state.status==='question')return 120;if(state.status==='reveal')return 160;return 300;}
 function schedulePoll(delay=nextDelay()){if(stopped)return;clearTimeout(pollTimer);pollTimer=setTimeout(fetchState,delay);}
 async function fetchState(){
   if(!room||!token)return;if(pollBusy){schedulePoll();return;}pollBusy=true;
   try{
     const r=await fetch(`${endpoint}?action=state&room=${room}&token=${encodeURIComponent(token)}&_=${Date.now()}`,{cache:'no-store'}),d=await r.json();
     if(r.status===401){token='';$('rrGameCard').hidden=true;$('rrPublicActions').hidden=false;return;}
     if(d.ok){const fixed=await reconcilePlayerState(d.state);if(fixed)render(fixed);}
   }catch(e){}finally{pollBusy=false;if(refreshQueued){refreshQueued=false;setTimeout(fetchState,0);}else schedulePoll();}
 }
 async function submitAnswer(team){if(answering||!token)return;answering=true;$('rrPhoneOptions').querySelectorAll('button').forEach(b=>b.disabled=true);try{const body=new URLSearchParams({action:'answer',room,token,team:String(team)}),r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Could not submit answer.');$('rrLocked').hidden=false;realtime?.poke('answer');await fetchState();}catch(e){$('rrLocked').hidden=false;$('rrLocked').textContent=e.message||'Answer closed.';}finally{answering=false;}}
 $('rrJoinForm').addEventListener('submit',async e=>{e.preventDefault();room=$('rrRoom').value.replace(/\D/g,'');const nickname=$('rrNickname').value.trim();$('rrJoinError').hidden=true;if(room.length!==6){$('rrJoinError').textContent='Enter the 6-digit room code.';$('rrJoinError').hidden=false;return;}try{const body=new URLSearchParams({action:'join',room,nickname}),r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Could not join the game.');token=d.token;save(d.nickname);history.replaceState(null,'',`?room=${room}`);startRealtime();realtime?.poke('join');await fetchState();}catch(e){$('rrJoinError').textContent=e.message||'Could not join the game.';$('rrJoinError').hidden=false;}});
 document.addEventListener('visibilitychange',()=>{if(!document.hidden&&room&&token){clearTimeout(pollTimer);fetchState();}});
 window.addEventListener('beforeunload',()=>{stopped=true;clearTimeout(pollTimer);realtime?.close?.();});
 if(loadSaved()){startRealtime();fetchState();}setInterval(tick,80);
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
