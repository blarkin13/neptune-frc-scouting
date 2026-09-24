<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);
$pageTitle='Command Center';
$moduleName='SATURN';
include dirname(__DIR__).'/partials_header.php';
$isAdmin=in_array($u['role'],['owner','admin'],true);
$isOwner=$u['role']==='owner';
$platformOrgId=max(1,(int)($config['app']['platform_organization_id']??1));
$isPlatformOwner=$isOwner && (int)($u['organization_id']??0)===$platformOrgId;
?>
<section class="module-page neptune-hub command-page">
  <header class="module-page-header neptune-hub-header">
    <div>
      <div class="module-code">SATURN</div>
      <h1>Command Center</h1>
      <p>Run the event, configure Neptune, manage your organization, and maintain the system.</p>
    </div>
  </header>

  <section class="neptune-hub-section">
    <div class="neptune-hub-section-head"><div><span class="neptune-hub-section-kicker">Operations</span><h2>Event Operations</h2><p>Everything needed to prepare and run live match scouting.</p></div></div>
    <div class="neptune-hub-grid four">
      <a class="neptune-hub-card accent-ops" href="<?=e(base_url('admin/match-control.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-circle-play"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">Run the field workflow</span><h3>Match Control</h3><p>Ready, start, pause, end, and re-scout matches while preserving match-run history.</p><div class="neptune-hub-meta"><span>Ready</span><span>Start</span><span>Pause</span><span>Replay</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <a class="neptune-hub-card accent-ops" href="<?=e(base_url('admin/live.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-chart-line"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">See scouting as it happens</span><h3>Live Monitor</h3><p>Watch connected scouts, robot assignments, recorded actions, and the current match state.</p><div class="neptune-hub-meta"><span>Scouts</span><span>Actions</span><span>Connections</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <a class="neptune-hub-card accent-ops" href="<?=e(base_url('admin/events.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-calendar-days"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">Prepare competition data</span><h3>Event Setup</h3><p>Create events, select games, manage rosters, and track event readiness.</p><div class="neptune-hub-meta"><span>Events</span><span>Rosters</span><span>Current Event</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <a class="neptune-hub-card accent-ops" href="<?=e(base_url('admin/tba-sync.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-arrows-rotate"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">Official FIRST data</span><h3>TBA Sync</h3><p>Import or refresh event rosters, schedules, and official event links from The Blue Alliance.</p><div class="neptune-hub-meta"><span>Teams</span><span>Schedule</span><span>Event Link</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
    </div>
  </section>

  <?php if($isAdmin):?>
  <section class="neptune-hub-section" id="vulcan">
    <div class="neptune-hub-section-head"><div><span class="neptune-hub-section-kicker" style="color:var(--module-vulcan)">VULCAN</span><h2>Builders</h2><p>Define what scouts collect and how each game is represented.</p></div></div>
    <div class="neptune-hub-grid three">
      <a class="neptune-hub-card accent-vulcan" href="<?=e(base_url('admin/game-builder.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-shapes"></i></span><span class="neptune-hub-badge access-admin"><i class="fa-solid fa-user-shield"></i> Admin+</span></div><span class="neptune-hub-card-kicker">Game configuration</span><h3>Game Builder</h3><p>Build scoring actions, field layout, action buttons, and match timing for a season.</p><div class="neptune-hub-meta"><span>Actions</span><span>Field</span><span>Timing</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <a class="neptune-hub-card accent-vulcan" href="<?=e(base_url('admin/pit-builder.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-clipboard-list"></i></span><span class="neptune-hub-badge access-admin"><i class="fa-solid fa-user-shield"></i> Admin+</span></div><span class="neptune-hub-card-kicker">Pit data collection</span><h3>Pit Form Builder</h3><p>Configure the game-specific capability questions scouts answer at each robot.</p><div class="neptune-hub-meta"><span>Questions</span><span>Capabilities</span><span>Photos</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <a class="neptune-hub-card accent-vulcan" href="<?=e(base_url('admin/pre-scout-builder.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-binoculars"></i></span><span class="neptune-hub-badge access-admin"><i class="fa-solid fa-user-shield"></i> Admin+</span></div><span class="neptune-hub-card-kicker">Season research</span><h3>Pre-Scout Form Builder</h3><p>Configure the research fields used before the event and carried into pit scouting.</p><div class="neptune-hub-meta"><span>Research</span><span>Season Data</span><span>Templates</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
    </div>
  </section>
  <?php endif;?>

  <section class="neptune-hub-section">
    <div class="neptune-hub-section-head"><div><span class="neptune-hub-section-kicker" style="color:var(--module-org)">Administration</span><h2>Organization</h2><p>Manage who uses Neptune and how scouting data is shared.</p></div></div>
    <div class="neptune-hub-grid">
      <?php if($isAdmin):?><a class="neptune-hub-card accent-org" href="<?=e(base_url('admin/teams.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-users-gear"></i></span><span class="neptune-hub-badge access-admin"><i class="fa-solid fa-user-shield"></i> Admin+</span></div><span class="neptune-hub-card-kicker">People and teams</span><h3>Teams &amp; Users</h3><p>Manage FRC teams, Neptune accounts, roles, and access inside your organization.</p><div class="neptune-hub-meta"><span>Teams</span><span>Accounts</span><span>Roles</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a><?php endif;?>
      <a class="neptune-hub-card accent-org" href="<?=e(base_url('admin/sharing.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-share-nodes"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">Partner access</span><h3>Data Sharing</h3><p>Control which partner teams can access match, pit, notes, raw actions, and analytics data.</p><div class="neptune-hub-meta"><span>Partners</span><span>Permissions</span><span>Shared Data</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
    </div>
  </section>

  <?php if($isAdmin):?>
  <section class="neptune-hub-section">
    <div class="neptune-hub-section-head"><div><span class="neptune-hub-section-kicker" style="color:var(--module-system)">System</span><h2>Maintenance</h2><p>Verify Neptune's host capabilities and maintain application files and AUGUR data.</p></div></div>
    <div class="neptune-hub-grid<?=$isPlatformOwner?' three':''?>">
      <a class="neptune-hub-card accent-system" href="<?=e(base_url('admin/system-check.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-heart-pulse"></i></span><span class="neptune-hub-badge access-admin"><i class="fa-solid fa-user-shield"></i> Admin+</span></div><span class="neptune-hub-card-kicker">Environment health</span><h3>System Check</h3><p>Verify PHP, image processing, storage, configuration, and application capabilities.</p><div class="neptune-hub-meta"><span>PHP</span><span>Imagick</span><span>Storage</span><span>Health</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <?php if($isPlatformOwner):?><a class="neptune-hub-card accent-system" href="<?=e(base_url('admin/styling.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-palette"></i></span><span class="neptune-hub-badge access-owner"><i class="fa-solid fa-lock"></i> Platform owner</span></div><span class="neptune-hub-card-kicker">Global appearance</span><h3>Interface Styling</h3><p>Adjust Neptune's dark, light, subsystem, status, and access colors from one central palette.</p><div class="neptune-hub-meta"><span>Palette</span><span>Presets</span><span>Preview</span><span>Global</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a><?php endif;?>
      <?php if($isPlatformOwner):?><a class="neptune-hub-card accent-system" href="<?=e(base_url('admin/file-manager.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-folder-tree"></i></span><span class="neptune-hub-badge access-owner"><i class="fa-solid fa-lock"></i> Platform owner</span></div><span class="neptune-hub-card-kicker">Application files</span><h3>File Manager</h3><p>Browse, upload, edit, rename, move, download, and maintain Neptune files from the browser.</p><div class="neptune-hub-meta"><span>Browse</span><span>Edit</span><span>Upload</span><span>Backups</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a><?php endif;?>
      <?php if($isPlatformOwner):?><a class="neptune-hub-card accent-system" href="<?=e(base_url('admin/augur-maintenance.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-database"></i></span><span class="neptune-hub-badge access-owner"><i class="fa-solid fa-lock"></i> Platform owner</span></div><span class="neptune-hub-card-kicker">Ratings &amp; team data</span><h3>AUGUR Data Maintenance</h3><p>Refresh the TBA team directory, backfill Public EPA, rebuild OPR, and verify local AUGUR season data.</p><div class="neptune-hub-meta"><span>Team Directory</span><span>EPA</span><span>OPR</span><span>Backfill</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a><?php endif;?>
      <?php if($isPlatformOwner):?><a class="neptune-hub-card accent-system" href="<?=e(base_url('admin/maintenance.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-terminal"></i></span><span class="neptune-hub-badge access-owner"><i class="fa-solid fa-lock"></i> Platform owner</span></div><span class="neptune-hub-card-kicker">Server maintenance</span><h3>Maintenance Console</h3><p>Check server health, inspect and install Neptune patch ZIPs, roll back updates, and run restricted diagnostics.</p><div class="neptune-hub-meta"><span>Status</span><span>Patches</span><span>Rollback</span><span>Diagnostics</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a><?php endif;?>
    </div>
  </section>
  <?php endif;?>
</section>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
