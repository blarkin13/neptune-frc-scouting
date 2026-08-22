<?php require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';$u=require_role(['owner','admin','strategy']);$pageTitle='Command';include dirname(__DIR__).'/partials_header.php';?>
<div class="toolbar" style="justify-content:space-between"><div><h1 style="margin-bottom:4px">Neptune Command</h1><div class="muted">Event-day control and platform administration.</div></div></div>
<div class="quick-grid">
<a class="quick-link" href="match-control.php"><span class="quick-icon"><i class="fa-solid fa-tower-broadcast"></i></span><span><b>Match Control</b><span class="muted">Ready, start, pause, end, replay, and monitor official matches.</span></span></a>
<a class="quick-link" href="events.php"><span class="quick-icon"><i class="fa-solid fa-calendar-days"></i></span><span><b>Event Setup</b><span class="muted">Create the current event, load a roster, and open pit scouting before the schedule exists.</span></span></a>
<a class="quick-link" href="tba-sync.php"><span class="quick-icon"><i class="fa-solid fa-cloud-arrow-down"></i></span><span><b>TBA Sync</b><span class="muted">Import or refresh the official roster and add the schedule when it is published.</span></span></a>
<a class="quick-link" href="<?=e(base_url('pit/index.php'))?>"><span class="quick-icon"><i class="fa-solid fa-clipboard-list"></i></span><span><b>Pit Scouting</b><span class="muted">Open the current event roster and scout robots in the pits.</span></span></a>
<?php if(in_array($u['role'],['owner','admin'],true)):?>
<a class="quick-link" href="pit-builder.php"><span class="quick-icon"><i class="fa-solid fa-list-check"></i></span><span><b>Pit Form Builder</b><span class="muted">Add game-specific pit questions without changing PHP.</span></span></a>
<a class="quick-link" href="game-builder.php"><span class="quick-icon"><i class="fa-solid fa-table-cells-large"></i></span><span><b>Game Builder</b><span class="muted">Build and edit the four-column scout action grid and match timing.</span></span></a>
<a class="quick-link" href="teams.php"><span class="quick-icon"><i class="fa-solid fa-users-gear"></i></span><span><b>Teams & Users</b><span class="muted">Manage only your organization’s FRC teams, users, and roles.</span></span></a>
<a class="quick-link" href="import-legacy.php"><span class="quick-icon"><i class="fa-solid fa-clock-rotate-left"></i></span><span><b>Legacy Import</b><span class="muted">Bring Stat Owl scouting history into Neptune without duplicates.</span></span></a>
<?php endif;?>
<a class="quick-link" href="sharing.php"><span class="quick-icon"><i class="fa-solid fa-share-nodes"></i></span><span><b>Data Sharing</b><span class="muted">Control which Neptune partner teams can access scouting data.</span></span></a>
</div>
<?php include dirname(__DIR__).'/partials_footer.php';
