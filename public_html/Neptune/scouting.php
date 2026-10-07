<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/neptune_secure/bootstrap.php';
$u = require_login();
$pageTitle = 'Scouting';
$moduleName = 'TRIDENT';
include __DIR__ . '/partials_header.php';
?>
<section class="module-page neptune-hub">
  <header class="module-page-header neptune-hub-header">
    <div>
      <div class="module-code">TRIDENT</div>
      <h1>Scouting</h1>
      <p>Capture match performance, Tag observations, pit data, and pre-event research.</p>
    </div>
  </header>

  <div class="neptune-hub-grid">
    <a class="neptune-hub-card accent-trident" href="<?=e(base_url('scout/index.php'))?>">
      <div class="neptune-hub-card-top">
        <span class="neptune-hub-icon"><i class="fa-solid fa-stopwatch"></i></span>
        <span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span>
      </div>
      <span class="neptune-hub-card-kicker">Detailed live scouting</span>
      <h3>Match Scouting</h3>
      <p>Record robot actions, success and failure, scoring, and timing during the match.</p>
      <div class="neptune-hub-meta"><span>Actions</span><span>Cycles</span><span>Auton</span><span>Teleop</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>

    <a class="neptune-hub-card accent-trident" href="<?=e(base_url('scout/tag.php'))?>">
      <div class="neptune-hub-card-top">
        <span class="neptune-hub-icon"><i class="fa-solid fa-hashtag"></i></span>
        <span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span>
      </div>
      <span class="neptune-hub-card-kicker">Fast tag-based scouting</span>
      <h3>Tag Scouting</h3>
      <p>Capture Match, Pit, and Team observations with shared tags, notes, scoring contribution, photos, and video.</p>
      <div class="neptune-hub-meta"><span>Match</span><span>Pit</span><span>Team</span><span>Media</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>

    <a class="neptune-hub-card accent-trident" href="<?=e(base_url('pit/index.php'))?>">
      <div class="neptune-hub-card-top">
        <span class="neptune-hub-icon"><i class="fa-solid fa-clipboard-list"></i></span>
        <span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span>
      </div>
      <span class="neptune-hub-card-kicker">Inspect the robot in the pits</span>
      <h3>Pit Scouting</h3>
      <p>Collect robot capabilities, configuration, reliability, photos, and game-specific pit data.</p>
      <div class="neptune-hub-meta"><span>Capabilities</span><span>Photos</span><span>Reliability</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>

    <a class="neptune-hub-card accent-trident" href="<?=e(base_url('prescout/index.php'))?>">
      <div class="neptune-hub-card-top">
        <span class="neptune-hub-icon"><i class="fa-solid fa-magnifying-glass-chart"></i></span>
        <span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span>
      </div>
      <span class="neptune-hub-card-kicker">Prepare before the event</span>
      <h3>Pre-Scouting</h3>
      <p>Research teams and build reusable season knowledge before competition begins.</p>
      <div class="neptune-hub-meta"><span>Research</span><span>Season Data</span><span>Outreach</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>
  </div>
</section>
<?php include __DIR__ . '/partials_footer.php'; ?>
