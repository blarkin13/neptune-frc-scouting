<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);
$pageTitle='Pit Games';
$moduleName='SATURN';
$bodyClass=trim(($bodyClass??'').' pit-games-page');
$pageStyles=['assets/css/pit-games.css'];
include dirname(__DIR__).'/partials_header.php';
?>
<section class="module-page pit-games">
  <header class="module-page-header pit-games-hero">
    <div>
      <div class="module-code">SATURN · PIT GAMES</div>
      <h1>Pit Games</h1>
      <p>Quick FRC games built for a pit monitor, tablet, or walk-up station.</p>
    </div>
    <div class="toolbar">
      <a class="btn secondary" href="<?=e(base_url('admin/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Command Center</a>
    </div>
  </header>

  <section class="pit-game-category">
    <div class="pit-game-category-head">
      <span class="pit-game-category-icon"><i class="fa-solid fa-brain"></i></span>
      <div><span class="pit-game-eyebrow">MEMORY &amp; RECOGNITION</span><h2>Know the robots</h2><p>Fast visual recognition games using real FRC teams and logos.</p></div>
    </div>
    <div class="pit-game-grid">
      <a class="pit-game-card featured" href="<?=e(base_url('admin/robot-recall.php'))?>">
        <span class="pit-game-card-icon"><i class="fa-solid fa-robot"></i></span>
        <span class="pit-game-status live">LIVE</span>
        <h3>Robot Recall</h3>
        <p>Name the robot from its team logo. Host competitive rooms with player phones, QR join, standings, and an audience display.</p>
        <div class="pit-game-tags"><span>Event roster</span><span>Leaderboard</span><span>Big screen</span></div>
        <strong class="pit-game-open">Open Robot Recall <i class="fa-solid fa-arrow-right"></i></strong>
      </a>
    </div>
  </section>

  <section class="pit-game-category" id="time-reaction">
    <div class="pit-game-category-head">
      <span class="pit-game-category-icon"><i class="fa-solid fa-stopwatch"></i></span>
      <div><span class="pit-game-eyebrow">TIME &amp; REACTION</span><h2>Beat the clock</h2><p>Choose a game. Each one opens by itself for a clean walk-up experience.</p></div>
    </div>
    <div class="pit-game-grid">
      <a class="pit-game-card" href="<?=e(base_url('admin/time-games.php?game=reaction'))?>">
        <span class="pit-game-card-icon"><i class="fa-solid fa-bolt"></i></span><span class="pit-game-status live">LIVE</span>
        <h3>Reaction Light</h3>
        <p>Wait for GO, then hit the target as fast as possible. Tapping early counts as a false start.</p>
        <div class="pit-game-tags"><span>Milliseconds</span><span>Touchscreen</span><span>Instant reset</span></div>
        <strong class="pit-game-open">Play Reaction Light <i class="fa-solid fa-arrow-right"></i></strong>
      </a>

      <a class="pit-game-card" href="<?=e(base_url('admin/time-games.php?game=clock'))?>">
        <span class="pit-game-card-icon"><i class="fa-solid fa-clock"></i></span><span class="pit-game-status live">LIVE</span>
        <h3>Stop the Clock</h3>
        <p>Start the timer, let the display disappear, and try to stop it exactly at 5.000 seconds.</p>
        <div class="pit-game-tags"><span>5-second target</span><span>Blind timing</span><span>Closest wins</span></div>
        <strong class="pit-game-open">Play Stop the Clock <i class="fa-solid fa-arrow-right"></i></strong>
      </a>
    </div>
  </section>

  <section class="pit-game-category" id="card-magic">
    <div class="pit-game-category-head">
      <span class="pit-game-category-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
      <div><span class="pit-game-eyebrow">CARD MAGIC</span><h2>Neptune Magic</h2><p>Choose a trick directly, or start a continuous hands-free loop of every magic trick.</p></div>
    </div>
    <div class="pit-game-grid">
      <a class="pit-game-card featured" href="<?=e(base_url('admin/card-magic.php?run=1&cycle=1&tricks=vanish,force,1089,37'))?>">
        <span class="pit-game-card-icon"><i class="fa-solid fa-repeat"></i></span><span class="pit-game-status live">CONTINUOUS</span>
        <h3>Cycle All Magic</h3>
        <p>Run every Neptune magic trick automatically, one after another, then loop back to the beginning. Built for an unattended pit monitor.</p>
        <div class="pit-game-tags"><span>4 tricks</span><span>Auto play</span><span>Loops forever</span><span>Fullscreen</span></div>
        <strong class="pit-game-open">Start continuous cycle <i class="fa-solid fa-play"></i></strong>
      </a>

      <a class="pit-game-card" href="<?=e(base_url('admin/card-magic.php?run=1&cycle=0&tricks=vanish'))?>">
        <span class="pit-game-card-icon"><i class="fa-solid fa-clone"></i></span><span class="pit-game-status live">MAGIC</span>
        <h3>Vanishing Team</h3>
        <p>Think of one FRC team on the screen. Without touching anything or entering an answer, Neptune makes your chosen team disappear.</p>
        <div class="pit-game-tags"><span>No input</span><span>Team logos</span><span>Visual illusion</span></div>
        <strong class="pit-game-open">Play trick <i class="fa-solid fa-arrow-right"></i></strong>
      </a>

      <a class="pit-game-card" href="<?=e(base_url('admin/card-magic.php?run=1&cycle=0&tricks=force'))?>">
        <span class="pit-game-card-icon"><i class="fa-solid fa-hashtag"></i></span><span class="pit-game-status live">MAGIC</span>
        <h3>Neptune Number Force</h3>
        <p>Choose any number from 1–9 and follow a short mental calculation. Neptune's locked prediction identifies the team you land on.</p>
        <div class="pit-game-tags"><span>Mental math</span><span>9 teams</span><span>Prediction</span></div>
        <strong class="pit-game-open">Play trick <i class="fa-solid fa-arrow-right"></i></strong>
      </a>

      <a class="pit-game-card" href="<?=e(base_url('admin/card-magic.php?run=1&cycle=0&tricks=1089'))?>">
        <span class="pit-game-card-icon"><i class="fa-solid fa-calculator"></i></span><span class="pit-game-status live">MAGIC</span>
        <h3>1089 Prediction</h3>
        <p>Create your own three-digit number and follow Neptune's instructions. The math drives everyone to the same predicted team card.</p>
        <div class="pit-game-tags"><span>3-digit choice</span><span>1089</span><span>Prediction</span></div>
        <strong class="pit-game-open">Play trick <i class="fa-solid fa-arrow-right"></i></strong>
      </a>

      <a class="pit-game-card" href="<?=e(base_url('admin/card-magic.php?run=1&cycle=0&tricks=37'))?>">
        <span class="pit-game-card-icon"><i class="fa-solid fa-divide"></i></span><span class="pit-game-status live">MAGIC</span>
        <h3>The 37 Engine</h3>
        <p>Pick any digit, turn it into a repeated three-digit number, and follow the calculation. Every path forces the same final team.</p>
        <div class="pit-game-tags"><span>Any digit</span><span>37</span><span>12 teams</span></div>
        <strong class="pit-game-open">Play trick <i class="fa-solid fa-arrow-right"></i></strong>
      </a>
    </div>
  </section>
</section>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
