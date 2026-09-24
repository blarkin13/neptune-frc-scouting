<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$pageTitle='Neptune User Guide';
$moduleName='NEPTUNE';
include dirname(__DIR__).'/partials_header.php';
?>
<style>
.ng{max-width:1500px;margin:0 auto}.ng-hero{padding:18px 0 20px;border-bottom:1px solid var(--line);margin-bottom:18px}
.ng-hero h1{font-size:clamp(2rem,4vw,3.1rem);margin:4px 0 8px}.ng-hero p{max-width:920px;color:var(--muted);line-height:1.65}
.ng-grid{display:grid;grid-template-columns:290px minmax(0,1fr);gap:20px;align-items:start}.ng-nav{position:sticky;top:78px;max-height:calc(100vh - 96px);overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:12px}
.ng-nav input{width:100%;margin-bottom:10px}.ng-nav a{display:block;padding:7px 9px;border-radius:8px;text-decoration:none;color:var(--text);font-size:.88rem}.ng-nav a:hover{background:var(--panel2)}
.ng-sec{scroll-margin-top:90px;background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:20px;margin-bottom:16px}.ng-sec h2{margin:0 0 12px}.ng-sec h3{margin:18px 0 6px;font-size:1rem}.ng-sec p,.ng-sec li{line-height:1.65}.ng-sec ul,.ng-sec ol{padding-left:22px}
.ng-cardgrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ng-card{background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:13px}.ng-card h3{margin:0 0 5px}.ng-card p{margin:5px 0}.ng-card a{font-weight:800}
.ng-role{display:inline-block;border:1px solid var(--line);border-radius:999px;padding:3px 7px;color:var(--muted);font-size:.76rem}.ng-flow{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}.ng-flow div{border:1px solid var(--line);border-radius:10px;padding:11px;background:var(--panel2)}.ng-flow b{display:block;margin-bottom:4px}
.ng-hide{display:none!important}@media(max-width:980px){.ng-grid{grid-template-columns:1fr}.ng-nav{position:relative;top:auto;max-height:none}.ng-flow{grid-template-columns:1fr 1fr}}@media(max-width:650px){.ng-cardgrid,.ng-flow{grid-template-columns:1fr}.ng-sec{padding:15px}}
</style>
<section class="module-page ng">
<header class="ng-hero">
  <div class="module-code">NEPTUNE HELP</div>
  <h1>Complete Neptune Walkthrough</h1>
  <p>End-to-end instructions for configuring Neptune, preparing an event, scouting robots and matches, using AUGUR analytics and strategy, running alliance selection, managing organizations, and maintaining the platform.</p>
  <span class="pill">Updated September 22, 2026</span>
</header>
<div class="ng-grid">
<aside class="ng-nav">
  <input id="ngSearch" type="search" placeholder="Filter guide…" aria-label="Filter guide">
  <nav id="ngNav">
    <a href="#start">1. Start here</a><a href="#roles">2. Roles & access</a><a href="#setup">3. Initial setup</a>
    <a href="#vulcan">4. VULCAN builders</a><a href="#event">5. Event preparation</a><a href="#trident">6. TRIDENT scouting</a>
    <a href="#operations">7. Live event operations</a><a href="#augur">8. AUGUR analytics</a><a href="#strategy">9. Match Strategy</a>
    <a href="#alliance">10. Alliance Selection</a><a href="#org">11. Organization & system</a><a href="#day">12. Competition-day workflow</a>
    <a href="#offline">13. Offline operation</a><a href="#troubleshooting">14. Troubleshooting</a><a href="#glossary">15. Glossary</a>
  </nav>
</aside>
<main>
<section id="start" class="ng-sec"><h2>1. Start here</h2>
<p>Neptune follows the way an FRC scouting group works: define the game, prepare the event, research the robots, scout the pits and matches, then turn those observations into strategy.</p>
<div class="ng-flow">
<div><b>1 · Build</b>Game actions, timing, pit questions, pre-scout questions.</div>
<div><b>2 · Prepare</b>Create the event, roster, schedule, users, and scout devices.</div>
<div><b>3 · Scout</b>Pre-scout, pit scout, Spot Scout, and live-match scout.</div>
<div><b>4 · Decide</b>Robot Intelligence, Match Strategy, AUGUR, and Alliance Selection.</div>
</div>
<h3>Subsystems</h3>
<ul><li><b>TRIDENT</b> — scouting interfaces.</li><li><b>SATURN</b> — Command Center and event operations.</li><li><b>VULCAN</b> — game and form builders.</li><li><b>AUGUR</b> — analytics, EPA, predictions, strategy, and alliance selection.</li></ul>
</section>

<section id="roles" class="ng-sec"><h2>2. Roles and access</h2>
<div class="ng-cardgrid">
<div class="ng-card"><h3>Owner</h3><p>Full organization administration plus Database Lab. The platform-organization owner also receives platform-only maintenance tools.</p></div>
<div class="ng-card"><h3>Admin</h3><p>Configures games/forms, manages teams and users, and uses system-health tools.</p></div>
<div class="ng-card"><h3>Strategy</h3><p>Runs Match Control, Live Monitor, Event Setup, TBA Sync, Data Sharing, scouting, and analytics.</p></div>
<div class="ng-card"><h3>Scouter</h3><p>Uses scouting interfaces and normal analytics without administrative configuration controls.</p></div>
</div>
<p>If a tool is missing, check the access badge on its Command Center card before assuming the page is broken.</p>
</section>

<section id="setup" class="ng-sec"><h2>3. Initial organization setup</h2>
<ol>
<li>Open <b>Teams & Users</b>. Add your FRC team(s), create accounts, and assign roles.</li>
<li>Open <b>Game Builder</b>. Configure the current game, phases, scoring actions, values, button layouts, and timing.</li>
<li>Open <b>Pit Form Builder</b>. Define the questions scouts answer at each robot.</li>
<li>Open <b>Pre-Scout Form Builder</b>. Define the research questions used before the event.</li>
<li>Open <b>Event Setup</b>. Create the event and make it current.</li>
<li>Add a preliminary roster manually or use <b>TBA Sync</b>.</li>
<li>Link the official TBA event when it becomes available, preserving existing pit/pre-scout data.</li>
<li>Test one complete practice match with real scout devices.</li>
</ol></section>

<section id="vulcan" class="ng-sec"><h2>4. VULCAN builders</h2>
<div class="ng-cardgrid">
<div class="ng-card"><h3>Game Builder <span class="ng-role">Admin+</span></h3><p>Defines season/game identity, match duration, Auto/Teleop/endgame timing, transition pause, action buttons, action type, location, point values, size/shape, and grid placement.</p><a href="<?=e(base_url('admin/game-builder.php'))?>">Open Game Builder</a></div>
<div class="ng-card"><h3>Pit Form Builder <span class="ng-role">Admin+</span></h3><p>Build questions about mechanisms, drivetrain, autonomous capability, capacity, endgame, roles, restrictions, and mechanical context.</p><a href="<?=e(base_url('admin/pit-builder.php'))?>">Open Pit Form Builder</a></div>
<div class="ng-card"><h3>Pre-Scout Form Builder <span class="ng-role">Admin+</span></h3><p>Build season research fields using yes/no, select, multi-select, number, text, and long-text questions. Answers can be reused for the same game/season.</p><a href="<?=e(base_url('admin/pre-scout-builder.php'))?>">Open Pre-Scout Builder</a></div>
<div class="ng-card"><h3>Builder rule of thumb</h3><p>Use live actions for things a scout can observe repeatedly during a match. Use pit/pre-scout/Spot for capabilities, context, and qualitative observations.</p></div>
</div></section>

<section id="event" class="ng-sec"><h2>5. Event preparation</h2>
<h3>Event Setup <span class="ng-role">Strategy+</span></h3>
<p>Create an event before a schedule exists. Choose the game, dates, optional TBA key, event status, and current-event flag. Paste team numbers from any text source; Neptune extracts the roster and removes duplicates.</p>
<p><a href="<?=e(base_url('admin/events.php'))?>">Open Event Setup</a></p>
<h3>TBA Sync <span class="ng-role">Strategy+</span></h3>
<p>Use TBA Sync to import/refresh rosters and schedules. If you already created a manual event, use <b>Link & Sync</b> so Neptune keeps the existing event ID and its pre-scout/pit work while adding official TBA information.</p>
<p><a href="<?=e(base_url('admin/tba-sync.php'))?>">Open TBA Sync</a></p>
<h3>Before the event</h3><ul><li>Verify all expected teams are on the roster.</li><li>Load the schedule once published.</li><li>Confirm the correct game/revision is attached.</li><li>Have every scout sign in from the device they will use.</li></ul>
</section>

<section id="trident" class="ng-sec"><h2>6. TRIDENT scouting</h2>
<div class="ng-cardgrid">
<div class="ng-card"><h3>Pre-Scouting</h3><p>Research the current-season robot before competition. Enter prior performance, architecture, auto/endgame history, archetype, drive notes, and the game-specific questions configured by VULCAN.</p><a href="<?=e(base_url('prescout/index.php'))?>">Open Pre-Scouting</a></div>
<div class="ng-card"><h3>Pit Scouting</h3><p>Select the event and team, confirm any pre-filled same-season data, answer the pit form, add photos, save a draft or mark complete, then move to Next Unscouted.</p><a href="<?=e(base_url('pit/index.php'))?>">Open Pit Scouting</a></div>
<div class="ng-card"><h3>Spot Scouting</h3><p>Add qualitative observations that do not belong as repeated match actions: damage, configuration changes, driver behavior, defense quality, reliability issues, and other strategic notes.</p><a href="<?=e(base_url('spot/index.php'))?>">Open Spot Scouting</a></div>
<div class="ng-card"><h3>Live Match Scouting</h3><p>Only Ready/Running/Paused matches appear. Choose your assigned alliance station; Neptune gets the robot directly from the match schedule.</p><a href="<?=e(base_url('scout/index.php'))?>">Open Match Scouting</a></div>
</div>
<h3>How to scout a live action</h3><ol><li>Wait for Command to start the match.</li><li>Tap the observed action.</li><li>Swipe <b>right</b> for Success or <b>left</b> for Failure.</li><li>Leave the popup open and repeat swipes for repeated actions.</li><li>Watch Actions, Score, Last Action, timer, and phase.</li></ol>
<p>Keyboard: Right Arrow = Success, Left Arrow = Failure, Escape = close popup. Actions are blocked while the match is not running and during the configured Auto → Teleop transition pause.</p>
</section>

<section id="operations" class="ng-sec"><h2>7. SATURN live event operations</h2>
<div class="ng-cardgrid">
<div class="ng-card"><h3>Match Control <span class="ng-role">Strategy+</span></h3><p>Ready, start, pause, end, and re-scout matches. Re-scouting creates a newer run so replacement data can be used without silently mixing old and new observations.</p><a href="<?=e(base_url('admin/match-control.php'))?>">Open Match Control</a></div>
<div class="ng-card"><h3>Live Monitor <span class="ng-role">Strategy+</span></h3><p>Watch connected scouts, assigned robots/stations, incoming actions, match state, and missing coverage. Keep this open during qualifications.</p><a href="<?=e(base_url('admin/live.php'))?>">Open Live Monitor</a></div>
</div>
<h3>Recommended match sequence</h3><ol><li>Make the match Ready.</li><li>Confirm six scheduled robots.</li><li>Confirm scout connections in Live Monitor.</li><li>Start Neptune with the field.</li><li>Monitor coverage while scouts record actions.</li><li>End the Neptune match state.</li><li>Check for missing or obviously incorrect coverage before advancing.</li></ol>
</section>

<section id="augur" class="ng-sec"><h2>8. AUGUR analytics</h2>
<div class="ng-cardgrid">
<div class="ng-card"><h3>Robot Lookup</h3><p>Search one team across TBA identity, Public EPA, match data, action breakdown, Pit Scouting, photos, Pre-Scouting, Spot observations, and event history.</p><a href="<?=e(base_url('analytics/robot-lookup.php'))?>">Open Robot Lookup</a></div>
<div class="ng-card"><h3>Robot Intelligence</h3><p>Event-focused robot comparison using scoring, recent output, cycles, success, Auto, defense, pit context, photos, plus AUGUR predictions and Public EPA data.</p><a href="<?=e(base_url('analytics/robots.php'))?>">Open Robot Intelligence</a></div>
<div class="ng-card"><h3>Match Board</h3><p>Six-robot view for reviewing both alliances in a past or upcoming match before opening deeper strategy.</p><a href="<?=e(base_url('analytics/team-display.php'))?>">Open Match Board</a></div>
<div class="ng-card"><h3>Public EPA Ratings</h3><p>Public TBA-derived Overall, Auto, Teleop, and Endgame EPA plus trend, confidence, matches, and record. This archive is independent of private scouting.</p><a href="<?=e(base_url('analytics/augur-ratings.php'))?>">Open Public EPA Ratings</a></div>
<div class="ng-card"><h3>Database Lab <span class="ng-role">Owner</span></h3><p>Read-only table browser, Query Builder, manual SQL, query history, and CSV export. Organization owners remain organization-scoped.</p><a href="<?=e(base_url('dashboard/data.php'))?>">Open Database Lab</a></div>
</div>
<h3>Public EPA vs Neptune EPA</h3><p><b>Public EPA</b> is independent and public-data-only. <b>Neptune EPA</b> is organization-specific and blends Public EPA with Neptune current-event scouting, recent offense, high-end output, and stabilized trend.</p>
<p>The alliance offensive baseline is the sum of the three field robots' Neptune EPA. A matchup prediction then applies opponent defense/suppression, qualification form, volatility, uncertainty, and confidence calibration.</p>
</section>

<section id="strategy" class="ng-sec"><h2>9. AUGUR Match Strategy</h2>
<p><a href="<?=e(base_url('analytics/match-strategy.php'))?>">Open Match Strategy</a></p>
<ol><li>Select Event, Our Team, and Match.</li><li>Load the match.</li><li>Review both alliance cards.</li><li>Review the AUGUR prediction, likely range, confidence, current/pre-match context, and defensive impact.</li><li>Open Model Settings to inspect or update shared weights.</li><li>Review Data Confidence.</li><li>Assign alliance roles.</li><li>Review Autonomous Intelligence.</li><li>Review Opponent Watch and choose a defense target if appropriate.</li><li>Assign endgame responsibilities.</li><li>Add objectives/drive notes and save the plan.</li></ol>
<p>Historical Match Strategy intentionally cuts off later data for backtesting. A historical pre-match Neptune EPA may differ from Current Neptune EPA; the current value should match other current-event pages using the same event and settings.</p>
</section>

<section id="alliance" class="ng-sec"><h2>10. AUGUR Alliance Selection</h2>
<p><a href="<?=e(base_url('analytics/alliance-selection.php'))?>">Open Alliance Selection</a></p>
<ul><li>Use <b>Available</b> as the working team-pool filter.</li><li>Review each robot's visible Neptune EPA plus scouting context.</li><li>Mark favorites and track broken, declined, or unavailable robots immediately.</li><li>The <b>Current Subtotal</b> is the straight sum of the selected three field robots' Neptune EPA. Backup is excluded.</li><li>Open Model Settings to change the shared AUGUR weights.</li><li>Build alternate alliances and run Alliance-vs-Alliance matchup projections.</li><li>Use the live draft tools/history during the actual selection.</li></ul>
<p>Neptune EPA is an offensive estimate, not a complete pick ranking. Autonomous compatibility, defense, reliability, driver quality, role fit, and strategy still matter.</p>
</section>

<section id="org" class="ng-sec"><h2>11. Organization and system tools</h2>
<div class="ng-cardgrid">
<div class="ng-card"><h3>Teams & Users <span class="ng-role">Admin+</span></h3><p>Manage FRC teams, user accounts, roles, and organization access.</p><a href="<?=e(base_url('admin/teams.php'))?>">Open</a></div>
<div class="ng-card"><h3>Data Sharing <span class="ng-role">Strategy+</span></h3><p>Grant partner teams permission-specific access to selected match, pit, notes, raw-action, or analytics data without merging organizations.</p><a href="<?=e(base_url('admin/sharing.php'))?>">Open</a></div>
<div class="ng-card"><h3>System Check <span class="ng-role">Admin+</span></h3><p>Verify PHP, image processing, storage, configuration, and host capabilities after upgrades or before an event.</p><a href="<?=e(base_url('admin/system-check.php'))?>">Open</a></div>
<div class="ng-card"><h3>Interface Styling <span class="ng-role">Platform owner</span></h3><p>Manage global dark/light, subsystem, status, and access colors. Check light-mode icon contrast after palette changes.</p><a href="<?=e(base_url('admin/styling.php'))?>">Open</a></div>
<div class="ng-card"><h3>File Manager <span class="ng-role">Platform owner</span></h3><p>Browse, upload, edit, rename, move, download, and maintain application files. Keep source-control/server backups.</p><a href="<?=e(base_url('admin/file-manager.php'))?>">Open</a></div>
<div class="ng-card"><h3>Maintenance Console <span class="ng-role">Platform owner</span></h3><p>Server health, patch ZIP inspection/install, rollback, and restricted diagnostics.</p><a href="<?=e(base_url('admin/maintenance.php'))?>">Open</a></div>
</div></section>

<section id="day" class="ng-sec"><h2>12. Recommended competition-day workflow</h2>
<h3>Before pits open</h3><ul><li>Verify current event, roster, and schedule.</li><li>Run TBA Sync.</li><li>Run System Check if server/network changed.</li><li>Test one scout device end-to-end.</li></ul>
<h3>Pit period</h3><ul><li>Complete Pit Scouting and photos.</li><li>Confirm/correct pre-scout data.</li><li>Add Spot observations for changes and issues.</li></ul>
<h3>Each qualification match</h3><ol><li>Ready the match.</li><li>Confirm six scouts/stations.</li><li>Start with the field.</li><li>Monitor coverage.</li><li>End the match.</li><li>Repair isolated data issues or re-scout only when replacement is needed.</li></ol>
<h3>Before your next match</h3><p>Use Match Strategy to confirm roles, auton responsibilities, opponent priorities, and endgame; save and communicate the plan.</p>
<h3>Before alliance selection</h3><p>Verify availability/declines, use the Available pool, compare Neptune EPA plus robot detail, build alternate combinations, and run scenario projections.</p>
</section>

<section id="offline" class="ng-sec"><h2>13. Offline and event-network operation</h2>
<ul><li>Give a local Neptune server a predictable LAN address or hostname.</li><li>Load the game, event, roster, and available schedule before leaving reliable internet.</li><li>Bring server power, router/access point, chargers, and required battery backup.</li><li>Test several scout devices on the exact event network configuration.</li><li>Run a practice Match Control → Match Scouting cycle.</li></ul>
<p>Live scouting includes connection protection for action writes. Protected pending requests are stored in browser storage and retried/removed after successful delivery. The client also retries on reconnect and can use a cached match state during a temporary state-fetch failure. Treat this as a safety net, not a reason to ignore a failing local network.</p>
</section>

<section id="troubleshooting" class="ng-sec"><h2>14. Troubleshooting</h2>
<h3>Scout cannot see a match</h3><p>Confirm the match is Ready and the TBA/event schedule contains the assigned robot.</p>
<h3>Wrong robot on a station</h3><p>Check the schedule and alliance/station selection. Fix the source schedule instead of improvising a robot number.</p>
<h3>Actions are disabled</h3><p>The match is probably Ready, Paused, Ended, or in the transition pause. Check Match Control.</p>
<h3>Robot has no analytics</h3><p>Verify active scouting actions, the current match run, and organization/event selection.</p>
<h3>Pit form is missing questions</h3><p>Confirm the event uses the correct game and that the Pit Form Builder was saved for it.</p>
<h3>Manual event now exists on TBA</h3><p>Use Link & Sync rather than creating a duplicate event.</p>
<h3>Neptune EPA values differ between pages</h3><p>Determine whether one view is historical/pre-match. Compare Current Neptune EPA for the same team/event/settings and verify shared Model Settings.</p>
<h3>Connection warning</h3><p>Notify the scouting lead, restore network/server connectivity, and allow the protected queue to flush.</p>
</section>

<section id="glossary" class="ng-sec"><h2>15. Glossary</h2>
<ul><li><b>Public EPA</b> — independent public TBA-derived expected-points rating.</li><li><b>Neptune EPA</b> — organization-specific offensive estimate blending Public EPA with Neptune scouting and trend.</li><li><b>Current Subtotal</b> — selected three field robots' Neptune EPA sum in Alliance Selection; backup excluded.</li><li><b>PPM</b> — scouted points per match.</li><li><b>Cycle Time</b> — average time between consecutive successful positive-point scoring actions.</li><li><b>Opponent Suppression</b> — observational estimate of opponent under-performance while a robot was on the field.</li><li><b>Run / Re-scout</b> — a version of a match scouting attempt; a newer run replaces the prior active observation for analytics.</li><li><b>TBA</b> — The Blue Alliance.</li></ul>
</section>
</main></div>
</section>
<script>
const s=document.getElementById('ngSearch');
s?.addEventListener('input',()=>{const q=s.value.trim().toLowerCase();document.querySelectorAll('.ng-sec').forEach(x=>x.classList.toggle('ng-hide',q&&!x.innerText.toLowerCase().includes(q)));document.querySelectorAll('#ngNav a').forEach(a=>{const x=document.querySelector(a.getAttribute('href'));a.classList.toggle('ng-hide',x?.classList.contains('ng-hide'));});});
</script>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
