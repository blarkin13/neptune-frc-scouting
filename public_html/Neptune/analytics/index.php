<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$pageTitle='Analytics & Strategy';
$moduleName='AUGUR';
$canUseDatabaseLab=in_array(($u['role']??''),['owner','admin','strategy'],true);
include dirname(__DIR__).'/partials_header.php';
?>
<section class="module-page neptune-hub">
  <header class="module-page-header neptune-hub-header">
    <div>
      <div class="module-code">AUGUR</div>
      <h1>Analytics &amp; Strategy</h1>
      <p>Turn scouting data into robot intelligence, match context, and an actionable drive-team plan.</p>
    </div>
  </header>

  <div class="neptune-hub-grid">
    <?php if($canUseDatabaseLab):?><a class="neptune-hub-card accent-augur" href="<?=e(base_url('dashboard/data.php'))?>"><?php else:?><div class="neptune-hub-card accent-augur is-locked" aria-disabled="true" title="Requires Strategy+"><?php endif;?>
      <div class="neptune-hub-card-top">
        <span class="neptune-hub-icon"><i class="fa-solid fa-database"></i></span>
        <span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span>
      </div>
      <span class="neptune-hub-card-kicker">Explore the source data</span>
      <h3>Database Lab</h3>
      <p>Inspect Neptune tables and build read-only queries without leaving the scouting system.</p>
      <div class="neptune-hub-meta"><span><i class="fa-solid fa-cubes"></i> Query Builder</span><span><i class="fa-solid fa-code"></i> SQL</span><span><i class="fa-solid fa-file-csv"></i> CSV Export</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    <?php if($canUseDatabaseLab):?></a><?php else:?></div><?php endif;?>

    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/robot-lookup.php'))?>">
      <div class="neptune-hub-card-top">
        <span class="neptune-hub-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
        <span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span>
      </div>
      <span class="neptune-hub-card-kicker">Find any robot fast</span>
      <h3>Robot Lookup</h3>
      <p>Search by team number or name and combine TBA identity, pit scouting, pre-scouting, and event-by-event match data.</p>
      <div class="neptune-hub-meta"><span>TBA</span><span>Pit</span><span>Pre-Scout</span><span>Event Stats</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>

    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/team-display.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-display"></i></span><span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span></div>
      <span class="neptune-hub-card-kicker">Six-robot matchup view</span>
      <h3>Match Board</h3>
      <p>Review past and upcoming matches with scouting context for every robot on both alliances.</p>
      <div class="neptune-hub-meta"><span>Schedule</span><span>Alliance Compare</span><span>Robot Detail</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>

    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/robots.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-robot"></i></span><span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span></div>
      <span class="neptune-hub-card-kicker">Robot intelligence</span>
      <h3>Robot Intelligence</h3>
      <p>Understand what a robot actually does from match scouting, pit data, notes, and photos.</p>
      <div class="neptune-hub-meta"><span>Scoring</span><span>Cycles</span><span>Auton</span><span>Defense</span><span>Pit</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>

    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/augur-ratings.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-chart-line"></i></span><span class="neptune-hub-badge access-all"><i class="fa-solid fa-globe"></i> Public</span></div>
      <span class="neptune-hub-card-kicker">Public TBA-derived ratings</span>
      <h3>EPA Ratings</h3>
      <p>View overall, Auto, Teleop and Endgame EPA from Neptune's shared TBA archive and access the public REST API.</p>
      <div class="neptune-hub-meta"><span>EPA</span><span>Auto</span><span>Teleop</span><span>Endgame</span><span>Archive</span><span>REST API</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>

    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/match-strategy.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-crosshairs"></i></span><span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span></div>
      <span class="neptune-hub-card-kicker">Build the drive-team plan</span>
      <h3>Match Strategy</h3>
      <p>Turn pre-match scouting into roles, autonomous assignments, opponent priorities, and endgame responsibilities.</p>
      <div class="neptune-hub-meta"><span>Roles</span><span>Auton</span><span>Threats</span><span>Endgame</span><span>Saved Plan</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>

    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/alliance-selection.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-people-group"></i></span><span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span></div>
      <span class="neptune-hub-card-kicker">Live pick-list workspace</span>
      <h3>Alliance Selection</h3>
      <p>Build draft scenarios, compare robot intelligence, track declines and broken robots, then run the live alliance selection.</p>
      <div class="neptune-hub-meta"><span>Pick List</span><span>Compare</span><span>Live Draft</span><span>History</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>

  </div>
</section>
<?php include dirname(__DIR__).'/partials_footer.php';
