<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);
$game=(string)($_GET['game']??'reaction');
if(!in_array($game,['reaction','clock'],true))$game='reaction';
$isReaction=$game==='reaction';
$pageTitle=$isReaction?'Reaction Light':'Stop the Clock';
$moduleName='SATURN';
$bodyClass=trim(($bodyClass??'').' pit-games-page');
$pageStyles=['assets/css/pit-games.css'];
include dirname(__DIR__).'/partials_header.php';
?>
<section class="module-page pit-games">
  <header class="module-page-header">
    <div>
      <div class="module-code">SATURN · PIT GAMES · TIME &amp; REACTION</div>
      <h1><?=$isReaction?'Reaction Light':'Stop the Clock'?></h1>
      <p><?=$isReaction?'Test pure reaction speed. Wait for GO, then tap as quickly as possible.':'Try to stop the hidden timer exactly at 5.000 seconds.'?></p>
    </div>
    <div class="toolbar"><a class="btn secondary" href="<?=e(base_url('admin/pit-games.php#time-reaction'))?>"><i class="fa-solid fa-arrow-left"></i> Pit Games</a></div>
  </header>

  <div class="time-game-grid" style="grid-template-columns:minmax(0,720px);justify-content:center">
    <?php if($isReaction):?>
    <section class="card time-game" id="reaction">
      <div class="time-game-head"><span class="pit-game-card-icon"><i class="fa-solid fa-bolt"></i></span><div><span class="pit-game-eyebrow">REACTION</span><h2>Reaction Light</h2></div></div>
      <p class="muted">Press Start. Wait for the target to turn green, then hit it. Tapping early is a false start.</p>
      <button class="reaction-pad" id="reactionPad" type="button" aria-label="Reaction target"><span id="reactionText">READY</span><small id="reactionSub">Press Start</small></button>
      <div class="time-score" id="reactionScore">—</div>
      <div class="toolbar"><button type="button" id="reactionStart"><i class="fa-solid fa-play"></i> Start</button><button type="button" class="secondary" id="reactionReset">Reset</button></div>
    </section>
    <?php else:?>
    <section class="card time-game" id="stop-clock">
      <div class="time-game-head"><span class="pit-game-card-icon"><i class="fa-solid fa-clock"></i></span><div><span class="pit-game-eyebrow">TIMING</span><h2>Stop the Clock</h2></div></div>
      <p class="muted">Press Start. The timer disappears after one second. Press Stop when you think exactly 5.000 seconds have passed.</p>
      <button class="stop-clock-pad" id="clockPad" type="button"><span id="clockText">5.000</span><small id="clockSub">Target</small></button>
      <div class="time-score" id="clockScore">—</div>
      <div class="toolbar"><button type="button" id="clockStart"><i class="fa-solid fa-play"></i> Start</button><button type="button" class="secondary" id="clockReset">Reset</button></div>
    </section>
    <?php endif;?>
  </div>
</section>
<?php if($isReaction):?>
<script>
(()=>{
  let state='idle', timer=0, goAt=0;
  const pad=document.getElementById('reactionPad'), txt=document.getElementById('reactionText'), sub=document.getElementById('reactionSub'), score=document.getElementById('reactionScore');
  function reset(){clearTimeout(timer);state='idle';pad.classList.remove('waiting','go','false');txt.textContent='READY';sub.textContent='Press Start';score.textContent='—';}
  document.getElementById('reactionStart').onclick=()=>{reset();state='waiting';pad.classList.add('waiting');txt.textContent='WAIT';sub.textContent='Do not tap';timer=setTimeout(()=>{state='go';pad.classList.remove('waiting');pad.classList.add('go');goAt=performance.now();txt.textContent='GO!';sub.textContent='TAP NOW';},1500+Math.random()*3500);};
  pad.onclick=()=>{if(state==='waiting'){clearTimeout(timer);state='false';pad.classList.remove('waiting');pad.classList.add('false');txt.textContent='FALSE START';sub.textContent='Too soon';score.textContent='—';return;}if(state==='go'){const ms=Math.round(performance.now()-goAt);state='done';pad.classList.remove('go');txt.textContent=ms+' ms';sub.textContent=ms<200?'Lightning fast':ms<275?'Fast reaction':ms<350?'Good reaction':'Try again';score.textContent=ms+' ms';}};
  document.getElementById('reactionReset').onclick=reset;
})();
</script>
<?php else:?>
<script>
(()=>{
  let startAt=0, running=false, raf=0;
  const pad=document.getElementById('clockPad'), txt=document.getElementById('clockText'), sub=document.getElementById('clockSub'), score=document.getElementById('clockScore');
  function tick(){if(!running)return;const t=(performance.now()-startAt)/1000;if(t<1)txt.textContent=t.toFixed(3);else txt.textContent='?.???';raf=requestAnimationFrame(tick);}
  function reset(){running=false;cancelAnimationFrame(raf);pad.classList.remove('running','done');txt.textContent='5.000';sub.textContent='Target';score.textContent='—';}
  document.getElementById('clockStart').onclick=()=>{reset();running=true;startAt=performance.now();pad.classList.add('running');sub.textContent='Tap the big target to stop';tick();};
  pad.onclick=()=>{if(!running)return;running=false;cancelAnimationFrame(raf);const t=(performance.now()-startAt)/1000;const diff=Math.abs(t-5);pad.classList.remove('running');pad.classList.add('done');txt.textContent=t.toFixed(3);sub.textContent=(t<5?'-':'+')+diff.toFixed(3)+' sec from target';score.textContent=t.toFixed(3)+' s';};
  document.getElementById('clockReset').onclick=reset;
})();
</script>
<?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
