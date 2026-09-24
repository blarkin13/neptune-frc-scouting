<?php
require_once dirname(__DIR__,2).'/neptune_secure/bootstrap.php';
$u=require_login();
$pageTitle='Scouting';
$moduleName='TRIDENT';
include __DIR__.'/partials_header.php';
?>
<section class="module-page neptune-hub">
  <header class="module-page-header neptune-hub-header">
    <div>
      <div class="module-code">TRIDENT</div>
      <h1>Scouting</h1>
      <p>Collect live match data, capture quick spot observations, inspect robots in the pit, and research teams before the event.</p>
    </div>
  </header>

  <div class="neptune-hub-grid four">
    <a class="neptune-hub-card accent-trident" href="<?=e(base_url('scout/index.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-crosshairs"></i></span><span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span></div>
      <span class="neptune-hub-card-kicker">Field scouting</span><h3>Match Scouting</h3>
      <p>Select your assigned field station and record robot actions during a live match.</p>
      <div class="neptune-hub-meta"><span>Assigned Robot</span><span>Auton</span><span>Teleop</span><span>Endgame</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>
    <a class="neptune-hub-card accent-trident" href="<?=e(base_url('spot/index.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-eye"></i></span><span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span></div>
      <span class="neptune-hub-card-kicker">Quick observations</span><h3>Spot Scouting</h3>
      <p>Tag robots during matches or in the pits, add notes and media, and follow active matches by field.</p>
      <div class="neptune-hub-meta"><span>Live Tags</span><span>Multi-Field</span><span>Photos</span><span>Video</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>
    <a class="neptune-hub-card accent-trident" href="<?=e(base_url('pit/index.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-clipboard-list"></i></span><span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span></div>
      <span class="neptune-hub-card-kicker">Robot inspection</span><h3>Pit Scouting</h3>
      <p>Capture capabilities, autonomous options, endgame, reliability, notes, and robot photos.</p>
      <div class="neptune-hub-meta"><span>Capabilities</span><span>Auton</span><span>Photos</span><span>Notes</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>
    <a class="neptune-hub-card accent-trident" href="<?=e(base_url('prescout/index.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-binoculars"></i></span><span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span></div>
      <span class="neptune-hub-card-kicker">Event preparation</span><h3>Pre-Scouting</h3>
      <p>Research teams before competition and carry season-level robot knowledge into the event.</p>
      <div class="neptune-hub-meta"><span>Research</span><span>History</span><span>Auton</span><span>Drive Notes</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>
  </div>
</section>
<?php include __DIR__.'/partials_footer.php';
