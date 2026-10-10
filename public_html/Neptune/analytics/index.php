<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$pageTitle='Analytics & Strategy';
$moduleName='AUGUR';
$canDatabaseLab=in_array(($u['role']??''),['strategy','admin','owner'],true);
include dirname(__DIR__).'/partials_header.php';
?>
<section class="module-page neptune-hub">
  <header class="module-page-header neptune-hub-header">
    <div>
      <h1>Analytics &amp; Strategy</h1>
      <p>Turn scouting data into robot intelligence, match context, and an actionable drive-team plan.</p>
    </div>
  </header>

  <div class="neptune-hub-grid">
    <?php if($canDatabaseLab):?>
    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('dashboard/data.php'))?>">
      <div class="neptune-hub-card-top">
        <span class="neptune-hub-icon"><i class="fa-solid fa-database"></i></span>
        <span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-chart-line"></i> Strategy+</span>
      </div>
      <span class="neptune-hub-card-kicker">Explore the source data</span>
      <h3>Database Lab</h3>
      <p>Inspect Neptune tables and build read-only queries without leaving the scouting system.</p>
      <div class="neptune-hub-meta"><span><i class="fa-solid fa-cubes"></i> Query Builder</span><span><i class="fa-solid fa-code"></i> SQL</span><span><i class="fa-solid fa-file-csv"></i> CSV Export</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>
    <?php endif;?>

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

    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/robots.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-robot"></i></span><span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span></div>
      <span class="neptune-hub-card-kicker">Robot intelligence</span>
      <h3>Robot Intelligence</h3>
      <p>Understand what a robot actually does from match scouting, pit data, notes, and photos.</p>
      <div class="neptune-hub-meta"><span>Scoring</span><span>Cycles</span><span>Auton</span><span>Defense</span><span>Pit</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>

    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/robot-stats-table.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-table-list"></i></span><span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span></div>
      <span class="neptune-hub-card-kicker">Event-wide comparison table</span>
      <h3>Robot Stats Table</h3>
      <p>Compare every event robot's offense, defense, EPA and scouting coverage in one sortable table.</p>
      <div class="neptune-hub-meta"><span>Offense</span><span>Defense</span><span>Nep. EPA</span><span>Nep. D-EPA</span></div>
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

    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/pit-operations.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-screwdriver-wrench"></i></span><span class="neptune-hub-badge access-all"><i class="fa-solid fa-user-group"></i> All users</span></div>
      <span class="neptune-hub-card-kicker">Live pit operations</span>
      <h3>Pit Operations Board</h3>
      <p>Run match-day pit readiness with robot status, queue timing, checklists, alliance context, and the next-match briefing.</p>
      <div class="neptune-hub-meta"><span>Readiness</span><span>Queue</span><span>Checklist</span><span>Next Match</span><span>TV Mode</span></div>
      <i class="fa-solid fa-arrow-right neptune-hub-arrow"></i>
    </a>



    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/stats/'))?>" style="border-color:color-mix(in srgb,var(--accent,#18b8ff) 55%,var(--border,#26344b));background:linear-gradient(135deg,color-mix(in srgb,var(--accent,#18b8ff) 13%,var(--card,#152435)),var(--card,#152435));">
      <div class="neptune-hub-card-top">
        <span class="neptune-hub-icon"><i class="fa-solid fa-globe"></i></span>
        <span class="neptune-hub-badge access-all"><i class="fa-solid fa-arrow-up-right-from-square"></i> Public site</span>
      </div>
      <span class="neptune-hub-card-kicker">Explore FRC statistics without signing in</span>
      <h3>Public FRC Statistics</h3>
      <p>Explore EPA leaderboards, team profiles, season history and event analytics in Neptune's public statistics center.</p>
      <div class="neptune-hub-meta"><span>EPA Rankings</span><span>Team Profiles</span><span>Events</span><span>Public Access</span></div>
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
