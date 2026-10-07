<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$pageTitle='Neptune Event Training Manual';
$moduleName='NEPTUNE';
$bodyClass='help-training-page';
include dirname(__DIR__).'/partials_header.php';
$trainingActive='manual';
require_once __DIR__.'/_training_media.php';
?>
<style>
.help-training-page .app-main{max-width:1500px}
.training-hero{position:relative;overflow:hidden;padding:28px;border:1px solid var(--line);border-radius:18px;background:linear-gradient(135deg,var(--panel),var(--panel2));box-shadow:0 18px 50px var(--shadow);margin-bottom:18px}
.training-hero:after{content:"";position:absolute;right:-100px;top:-130px;width:330px;height:330px;border-radius:50%;background:var(--c1-blue);opacity:.12;pointer-events:none}
.training-hero .module-eyebrow{margin-bottom:10px} .training-hero h1{margin:0;font-size:clamp(2rem,4vw,3.4rem);line-height:1.02;max-width:900px} .training-hero p{max-width:940px;font-size:1.05rem;color:var(--muted);margin:14px 0 0}
.training-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px} .training-actions .btn{text-decoration:none}
.training-layout{display:grid;grid-template-columns:270px minmax(0,1fr);gap:18px;align-items:start}
.training-toc{position:sticky;top:90px;max-height:calc(100vh - 110px);overflow:auto;padding:14px} .training-toc h2{font-size:1rem;margin:0 0 10px} .training-toc a{display:block;padding:8px 9px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:.88rem;line-height:1.25} .training-toc a:hover,.training-toc a:focus{background:var(--panel2);color:var(--text)}
.training-content{min-width:0} .manual-section{scroll-margin-top:90px;margin-bottom:22px;padding:24px;border:1px solid var(--line);background:var(--panel);border-radius:16px} .manual-section>h2{font-size:clamp(1.55rem,2.6vw,2.15rem);margin:0 0 8px} .manual-section h3{font-size:1.25rem;margin:25px 0 8px} .manual-section h4{font-size:1.05rem;margin:20px 0 7px} .manual-section p{line-height:1.62}
.manual-eyebrow{font-size:.74rem;letter-spacing:.13em;text-transform:uppercase;font-weight:900;color:var(--accent);margin:18px 0 5px} .training-content>.manual-eyebrow{margin-left:8px}
.manual-lead{font-size:1.04rem;color:var(--muted);margin-top:2px}
.manual-note,.manual-callout{border:1px solid var(--line);border-left:4px solid var(--accent);background:var(--panel2);border-radius:10px;padding:13px 15px;margin:14px 0;line-height:1.5} .manual-note{display:flex;gap:10px;align-items:flex-start} .manual-note i{margin-top:3px;color:var(--accent)} .manual-callout-title{font-weight:900;margin-bottom:5px} .manual-callout p{margin:5px 0}
.manual-list,.manual-steps{padding-left:1.35rem;line-height:1.58} .manual-list li,.manual-steps li{margin:6px 0} .manual-checklist{list-style:none;padding:0;margin:12px 0} .manual-checklist li{display:grid;grid-template-columns:20px 1fr;gap:8px;margin:7px 0;line-height:1.48} .manual-checklist i{color:var(--muted);margin-top:3px}
.manual-table-wrap{overflow:auto;border:1px solid var(--line);border-radius:11px;margin:15px 0} .manual-table{width:100%;border-collapse:collapse;min-width:650px} .manual-table th,.manual-table td{padding:10px 11px;border-bottom:1px solid var(--line);border-right:1px solid var(--line);text-align:left;vertical-align:top;line-height:1.42} .manual-table th{background:var(--panel2);font-size:.84rem;text-transform:uppercase;letter-spacing:.035em} .manual-table tr:last-child td{border-bottom:0} .manual-table th:last-child,.manual-table td:last-child{border-right:0} .manual-table p{margin:0 0 4px} .manual-table p:last-child{margin-bottom:0}
.training-search{margin:0 0 12px} .training-search input{width:100%} .training-zero{display:none;padding:18px;text-align:center;color:var(--muted)}
@media(max-width:900px){.training-layout{grid-template-columns:1fr} .training-toc{position:relative;top:auto;max-height:none} .training-toc details>div{columns:2}}
@media(max-width:600px){.training-hero{padding:20px} .manual-section{padding:17px} .training-toc details>div{columns:1} .manual-table{min-width:560px}}
@media print{.site-header,.app-header,.topbar,.nav,.training-actions,.training-toc,.site-footer{display:none!important} body{background:#fff!important;color:#111!important} .training-layout{display:block} .neptune-page-hero,.manual-section{box-shadow:none!important;background:#fff!important;border-color:#bbb!important;break-inside:auto} .manual-section{page-break-before:auto} .manual-table-wrap{overflow:visible} .manual-table{min-width:0;font-size:9pt} a{color:#111!important;text-decoration:none!important}}
</style>

<section class="neptune-page-hero">
  <div class="neptune-hero-kicker"><i class="fa-solid fa-clipboard-list"></i> NEPTUNE · COMPETITION TRAINING</div>
  <h1>Event Operations, Scouting &amp; Strategy Training Manual</h1>
  <p>Admin setup, event-day command, Traditional Match Scouting, Tag Scouting, AUGUR strategy, alliance selection, failure scenarios, and the verified manual-schedule fallback when TBA is late.</p>
  <div class="neptune-hero-pills" aria-label="Manual workflow">
    <span class="neptune-hero-pill"><i class="fa-solid fa-calendar-check"></i><strong>1</strong> Prepare</span>
    <span class="neptune-hero-pill"><i class="fa-solid fa-binoculars"></i><strong>2</strong> Scout</span>
    <span class="neptune-hero-pill"><i class="fa-solid fa-chess"></i><strong>3</strong> Decide</span>
  </div>
  <div class="training-actions">
    <a class="btn secondary" href="<?=e(base_url('help/training/'))?>"><i class="fa-solid fa-graduation-cap"></i> Training Home</a>
    <a class="btn secondary" href="<?=e(base_url('help/training/#cbt-courses'))?>"><i class="fa-solid fa-laptop-file"></i> CBT Training</a>
    <button class="btn secondary" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save PDF</button>
    <a class="btn secondary" href="#admin-event-day-command"><i class="fa-solid fa-tower-broadcast"></i> Event-Day Admin</a>
    <a class="btn secondary" href="#traditional-match-scouting"><i class="fa-solid fa-mobile-screen-button"></i> Scout Training</a>
  </div>
</section>
<?php include __DIR__.'/_training_nav.php'; ?>

<div class="training-layout">
  <aside class="card training-toc">
    <h2><i class="fa-solid fa-list"></i> Manual contents</h2>
    <div class="training-search"><input id="manualSearch" type="search" placeholder="Search this manual…" aria-label="Search this manual"></div>
    <details open><summary>Sections</summary><div><a href="#how-to-use-this-manual">How to use this manual</a>
<a href="#neptune-operating-model">SECTION 1 · Neptune operating model</a>
<a href="#pre-event-admin-setup">SECTION 2 · Pre-event admin setup</a>
<a href="#create-and-prepare-an-event">SECTION 3 · Create and prepare an event</a>
<a href="#tba-sync-and-late-schedule-operations">SECTION 4 · TBA Sync and late-schedule operations</a>
<a href="#admin-event-day-command">SECTION 5 · Admin event-day command</a>
<a href="#event-day-scenario-playbook">SECTION 6 · Event-day scenario playbook</a>
<a href="#traditional-match-scouting-training">SECTION 7 · Traditional Match Scouting training</a>
<a href="#tag-scouting-training">SECTION 8 · Tag Scouting training</a>
<a href="#tag-contexts-vs-traditional-pit-pre">SECTION 9 · Tag contexts vs Traditional / Pit / Pre-Scouting</a>
<a href="#augur-analytics-literacy">SECTION 10 · AUGUR analytics literacy</a>
<a href="#how-strategy-should-use-match-strategy">SECTION 11 · How Strategy should use Match Strategy</a>
<a href="#alliance-selection-training">SECTION 12 · Alliance Selection training</a>
<a href="#end-of-day-and-post-event-operations">SECTION 13 · End-of-day and post-event operations</a>
<a href="#event-day-quick-reference-cards">SECTION 14 · Event-day quick-reference cards</a>
<a href="#practice-scenarios-and-crew-certification">SECTION 15 · Practice scenarios and crew certification</a>
<a href="#decision-rules-for-common-data-conflicts">Decision rules for common data conflicts</a>
<a href="#software-behavior-basis">Software-behavior basis</a></div></details>
  </aside>
  <main class="training-content" id="trainingContent">
    <div class="manual-eyebrow">READ FIRST</div>
<section class="manual-section" id="how-to-use-this-manual">
<h2>How to use this manual</h2>
<p class="manual-lead">This is an operations manual and a training curriculum. Use the full sections for training; use the quick-reference pages during competition.</p>
<aside class="manual-callout"><div class="manual-callout-title">THE THREE-JOB MODEL</div><p>SATURN runs the event. TRIDENT captures what actually happened. AUGUR turns the data into a match plan and pick-list decisions. Keeping those jobs separate prevents bad data and rushed decisions.</p></aside>
<div class="manual-note"><i class="fa-solid fa-circle-info"></i><div>Revision 1.1 corrects the late-TBA workflow: the official paper schedule is entered manually so match scouting can continue without waiting for TBA.</div></div>
<h3>Training map</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Audience</strong></p></th>
<th><p><strong>Train on these sections</strong></p></th>
<th><p><strong>Event-day responsibility</strong></p></th>
</tr>
<tr>
<td><p>Owner / Admin</p></td>
<td><p>1–6, 10, 13–15</p></td>
<td><p>Event configuration, TBA sync, Match Control, Live Monitor, incident response</p></td>
</tr>
<tr>
<td><p>Strategy</p></td>
<td><p>1, 5–6, 10–13, 15</p></td>
<td><p>Data quality, match preparation, AUGUR, alliance selection, drive-team handoff</p></td>
</tr>
<tr>
<td><p>Traditional Scout</p></td>
<td><p>1, 7, 9, 15</p></td>
<td><p>One assigned robot; action-by-action scouting</p></td>
</tr>
<tr>
<td><p>Tag Scout</p></td>
<td><p>1, 8–9, 15</p></td>
<td><p>Structured robot observations, scoring-share estimate, tag consensus</p></td>
</tr>
<tr>
<td><p>Pit / Pre-Scout</p></td>
<td><p>3, 9, 10</p></td>
<td><p>Robot capability and context that feed Robot Intelligence and Match Strategy</p></td>
</tr>
</table></div>
<h3>Golden rules</h3>
<ul class="manual-list">
<li>Never guess a team, station, match, or result. If TBA is late, manually entering robots and matches from the verified official paper schedule is the approved fallback; guessing from memory is not.</li>
<li>Admins start Neptune’s match clock when the real match starts; phase timing is only useful when the clocks are aligned.</li>
<li>If The Blue Alliance has the roster but not the schedule, switch to the manual schedule process as soon as the event provides the official paper schedule: add any missing robots, build the matches and stations from the paper copy, verify them, and continue normal scouting.</li>
<li>Traditional scouts record observed actions; Tag scouts record observed traits and relative contribution. Neither should “fix” data by guessing after the match.</li>
<li>Strategy checks confidence and source quality before trusting any projection or rank.</li>
<li>A field replay is a new scouting run. Use Re-scout / Ready Again so the original run remains auditable but stops affecting normal analytics.</li>
<li>Do not deploy untested Neptune updates during active competition unless an operational failure requires a reviewed emergency patch.</li>
</ul>
<h3>Current software behavior covered by this manual</h3>
<p>This guide reflects the current Neptune project behavior as of 28 September 2026: event setup can be created before a TBA schedule exists; roster and schedule sync are separate; a verified official paper schedule can be entered manually so Match Control and Traditional Match Scouting can continue; Traditional Match Scouting supports queued requests during temporary connection loss; Match Strategy and Alliance Selection share the AUGUR prediction model; and Tag Scouting is the unified quick-observation system with Match, Pit, and Team contexts. Tag data is currently collected separately and does not populate AUGUR analytics, EPA, predictions, or Traditional action metrics.</p>
<div class="manual-eyebrow">SECTION 1</div>
</section>
<section class="manual-section" id="neptune-operating-model">
<h2>Neptune operating model</h2>
<p class="manual-lead">Know which module owns each competition task before training the team.</p>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Module</strong></p></th>
<th><p><strong>Primary job</strong></p></th>
<th><p><strong>What competition users do there</strong></p></th>
</tr>
<tr>
<td><p>TRIDENT · Scouting</p></td>
<td><p>Collect observations</p></td>
<td><p>Traditional Match Scouting, Tag Scouting (Match / Pit / Team), Pit Scouting, Pre-Scouting</p></td>
</tr>
<tr>
<td><p>SATURN · Command Center</p></td>
<td><p>Run the event workflow</p></td>
<td><p>Event Setup, TBA Sync, Match Control, Live Monitor, users/teams/data sharing</p></td>
</tr>
<tr>
<td><p>AUGUR · Analytics &amp; Strategy</p></td>
<td><p>Turn data into decisions</p></td>
<td><p>Robot Lookup, Match Board, Robot Intelligence, EPA, Match Strategy, Alliance Selection</p></td>
</tr>
<tr>
<td><p>VULCAN · Builders</p></td>
<td><p>Define the season/game forms</p></td>
<td><p>Game Builder, Pit Form Builder, Pre-Scout Form Builder</p></td>
</tr>
<tr>
<td><p>MERCURY · System Maintenance</p></td>
<td><p>Keep the installation healthy</p></td>
<td><p>System Check plus platform-owner Platform Administration, File Manager, interface styling, Maintenance Console, update history, and DB migration history</p></td>
</tr>
<tr>
<td><p>SALT · Data Layer</p></td>
<td><p>Controlled access to underlying data</p></td>
<td><p>Raw data and read-only Database Lab for permitted strategy users</p></td>
</tr>
</table></div>
<h3>Roles</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Role</strong></p></th>
<th><p><strong>Expected competition access</strong></p></th>
<th><p><strong>Training standard</strong></p></th>
</tr>
<tr>
<td><p>Owner</p></td>
<td><p>Full organization administration and high-level operations. Owners in the platform organization also receive installation-wide platform tools.</p></td>
<td><p>Must be able to recover organization access, recover event configuration, and make final data-quality decisions.</p></td>
</tr>
<tr>
<td><p>Admin</p></td>
<td><p>Event operations and configuration</p></td>
<td><p>Must be able to create/sync events, run Match Control, monitor scouts, and manage incidents.</p></td>
</tr>
<tr>
<td><p>Strategy</p></td>
<td><p>Event operations where permitted plus AUGUR planning</p></td>
<td><p>Must understand prediction confidence, scouting quality, and drive-team handoff.</p></td>
</tr>
<tr>
<td><p>Scouter</p></td>
<td><p>Scouting workflows and permitted views</p></td>
<td><p>Must correctly identify assigned robot, record only observed data, and escalate errors quickly.</p></td>
</tr>
</table></div>
<aside class="manual-callout"><div class="manual-callout-title">Recommended staffing</div><p>For a standard six-robot Traditional setup, designate one Match Control operator, one data/Live Monitor lead, six active scouts plus at least one backup, and at least one strategy lead. One person may cover more than one admin role, but Match Control should not be left unattended during a match.</p></aside>
<div class="manual-eyebrow">SECTION 2</div>
</section>
<section class="manual-section" id="pre-event-admin-setup">
<h2>Pre-event admin setup</h2>
<p class="manual-lead">Complete this before travel whenever possible so event-day work is about operations, not configuration.</p>
<?php neptune_training_gallery([
 ['game-builder','Use the live Scout Grid preview to verify what scouts will actually see before the event.'],
 ['pit-form-builder','Confirm pit questions are attached to the correct game revision.'],
 ['pre-scout-builder','Confirm the pre-event research form before assigning pre-scout work.']
],'ntp-shot-grid-3'); ?>
<h3>A. Verify the season game in VULCAN</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Open Game Builder and confirm the correct season/game is active for the event.</span></li>
<li><i class="fa-regular fa-square"></i><span>Confirm Autonomous, transition, Teleop, and Endgame timing.</span></li>
<li><i class="fa-regular fa-square"></i><span>Confirm every action button has the correct action name, point value, category/type, location, phase, size, and placement.</span></li>
<li><i class="fa-regular fa-square"></i><span>Preview the layout on the same phone/tablet size scouts will use.</span></li>
<li><i class="fa-regular fa-square"></i><span>Run at least one controlled practice match from Ready → Start → Pause/Resume → End.</span></li>
</ul>
<aside class="manual-callout"><div class="manual-callout-title">Freeze the game definition</div><p>Once competition data collection starts, do not casually change action meanings or point values. A changed button definition can make data from early and late matches incomparable.</p></aside>
<h3>B. Verify organization, teams, and users</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Confirm the correct FRC team(s) are active in the organization.</span></li>
<li><i class="fa-regular fa-square"></i><span>In <b>Teams & Users</b>, create/verify owner, admin, strategy, and scouter accounts; confirm role and primary-team assignments.</span></li>
<li><i class="fa-regular fa-square"></i><span>Deactivate accounts that should no longer have access instead of deleting historical ownership/audit context.</span></li>
<li><i class="fa-regular fa-square"></i><span>For password users, test the owner/admin temporary-password workflow and require the first-login password change before competition.</span></li>
<li><i class="fa-regular fa-square"></i><span>For Google users, verify the account is linked to the intended Neptune membership. Google sign-in does not expose or browse the global organization list.</span></li>
<li><i class="fa-regular fa-square"></i><span>Know the recovery path: <b>Forgot Password</b> uses Google identity verification; owners/admins can issue temporary passwords without editing MySQL.</span></li>
<li><i class="fa-regular fa-square"></i><span>Confirm each competition device can sign in and reach the Neptune home page.</span></li>
<li><i class="fa-regular fa-square"></i><span>Identify backup scouts and a backup admin before the first match.</span></li>
</ul>
<aside class="manual-callout"><div class="manual-callout-title">Platform-owner handoff</div><p>The platform organization owner should know how to use <b>Platform Administration</b> to inspect organizations, reset an owner, suspend/reactivate a tenant, and export tenant data. Use <b>Maintenance Console</b> for patch history and tracked DB migrations; file rollback does not roll back schema changes.</p></aside>
<h3>C. Run system checks</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Open MERCURY System Check and resolve obvious PHP/storage/configuration failures before travel.</span></li>
<li><i class="fa-regular fa-square"></i><span>Confirm the event server, database, power supply, charging plan, and local network are stable.</span></li>
<li><i class="fa-regular fa-square"></i><span>Open Traditional Match Scouting on representative phones/tablets and verify controls fit without accidental browser zoom.</span></li>
<li><i class="fa-regular fa-square"></i><span>If relying on an offline/local deployment, test that devices can reach the local Neptune address without internet access.</span></li>
<li><i class="fa-regular fa-square"></i><span>Keep a paper or simple spreadsheet backup procedure available for a full server failure.</span></li>
</ul>
<div class="manual-eyebrow">SECTION 3</div>
</section>
<section class="manual-section" id="create-and-prepare-an-event">
<h2>Create and prepare an event</h2>
<p class="manual-lead">Neptune can be fully prepared and operated even when The Blue Alliance has not yet published the qualification schedule. TBA is preferred for automatic setup, but the official paper schedule is the manual fallback.</p>
<?php neptune_training_gallery([
 ['event-setup','Create the event early, choose the correct game, and make it current.'],
 ['event-roster','Use roster and status counts to verify that pit and schedule preparation are attached to the intended event.']
]); ?>
<h3>Normal event setup</h3>
<ol class="manual-steps">
<li>Open SATURN → Event Setup.</li>
<li>Create the event as soon as you know you are attending. Enter the event name, correct game, and dates. Add a TBA event key only if you are certain it is correct.</li>
<li>Keep “Open pit scouting” enabled when you want the pit team to begin immediately.</li>
<li>Make the event the Current Event so scouting and analytics default to the correct competition.</li>
<li>If TBA already lists the event, use TBA Sync to import/refresh the official event and roster.</li>
<li>If TBA is not ready, add/paste the event roster from an official event list or the paper schedule. Add any missing robot numbers before building matches.</li>
<li>Open Pit Scouting and Pre-Scouting immediately. If the official paper match schedule is available, begin the manual schedule process at the same time.</li>
<li>If TBA still has no schedule, manually create the matches from the official paper copy and enter Red 1–3 and Blue 1–3 exactly as printed. Verify the first matches and your team’s assignments, then use Match Control and Traditional Match Scouting normally. When TBA later publishes the event schedule, link/sync the same Neptune event and reconcile it with the paper schedule rather than creating a duplicate event.</li>
</ol>
<h3>Event stage meanings</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Stage</strong></p></th>
<th><p><strong>Use it when</strong></p></th>
<th><p><strong>Operational meaning</strong></p></th>
</tr>
<tr>
<td><p>Planned</p></td>
<td><p>Event exists but field work has not started</p></td>
<td><p>Preparation only.</p></td>
</tr>
<tr>
<td><p>Pit Scouting Open</p></td>
<td><p>Roster is available and pit work is underway</p></td>
<td><p>Schedule may still be missing.</p></td>
</tr>
<tr>
<td><p>Schedule Ready</p></td>
<td><p>Qualification/playoff matches are loaded</p></td>
<td><p>Match Control can prepare matches once teams are present.</p></td>
</tr>
<tr>
<td><p>Matches Running</p></td>
<td><p>Competition scouting is active</p></td>
<td><p>SATURN is controlling current match state.</p></td>
</tr>
<tr>
<td><p>Complete</p></td>
<td><p>Event work is finished</p></td>
<td><p>Stops the event from looking active/current operationally.</p></td>
</tr>
</table></div>
<h3>Verify before declaring “Schedule Ready”</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Roster count is plausible for the event.</span></li>
<li><i class="fa-regular fa-square"></i><span>Match count is non-zero, whether loaded from TBA or built from the verified official paper schedule.</span></li>
<li><i class="fa-regular fa-square"></i><span>Red/Blue team assignments and stations are present and spot-checked against the current official schedule.</span></li>
<li><i class="fa-regular fa-square"></i><span>Your team’s own first two matches match the official schedule.</span></li>
<li><i class="fa-regular fa-square"></i><span>The event is Current.</span></li>
</ul>
<div class="manual-eyebrow">SECTION 4</div>
</section>
<section class="manual-section" id="tba-sync-and-late-schedule-operations">
<h2>TBA Sync and late-schedule operations</h2>
<p class="manual-lead">The Blue Alliance is an external source. A late TBA schedule does not stop Neptune: the admin switches to verified manual schedule entry from the event’s official paper copy.</p>
<?php neptune_training_gallery([
 ['tba-sync','Use the same manual Neptune event while waiting for TBA; link it when an official match becomes available.'],
 ['tba-events','The imported-event cards make roster, schedule, pre-scout, and pit readiness visible at a glance.']
]); ?>
<h3>What TBA Sync provides</h3>
<p>Neptune uses The Blue Alliance for automatic event identity, roster, qualification/playoff matches, Red/Blue alliances and stations, scores/results, and other public context. When TBA is late or unavailable, admins can enter the roster and schedule manually from the official event paperwork and continue operating the same event.</p>
<h3>Scenario: TBA event exists, roster loads, schedule is not published</h3>
<aside class="manual-callout"><div class="manual-callout-title">DO THIS</div><p>Keep the existing event. Keep it Current. Begin Pit Scouting and Pre-Scouting from the roster. Retry TBA Sync later. When matches appear, sync them into this same event and verify assignments.</p></aside>
<h4>Manual schedule takeover</h4>
<ul class="manual-list">
<li>Keep the existing Neptune event. Do not create a temporary duplicate event.</li>
<li>Use the official paper schedule from the event as the source of truth. Add any robots that are missing from the event roster.</li>
<li>Build the qualification schedule manually in Neptune: create the matches in order and enter every Red 1, Red 2, Red 3, Blue 1, Blue 2, and Blue 3 assignment exactly as printed. Do not guess unreadable or uncertain entries—verify them with event staff or a second official copy.</li>
<li>Have a second person cross-check the full manually entered schedule against the paper copy, including every match number and all six station assignments; pay special attention to your own team’s matches. Once the manual schedule is correct, treat it as Schedule Ready and run Match Control, Traditional Scouting, Tag Scouting, Match Board, and Match Strategy normally. When TBA catches up, sync/link the same event and compare for revisions.</li>
</ul>
<h3>Scenario: TBA does not list the event yet</h3>
<ol class="manual-steps">
<li>Create the event manually in Event Setup and make it Current.</li>
<li>Add/paste the verified roster from official event paperwork and begin pit/pre-scouting.</li>
<li>When the paper match schedule is released, add any missing robots and build the complete schedule manually, including every alliance station assignment.</li>
<li>Verify the manual schedule against the paper copy, then operate Match Control and scouting normally. Do not wait for TBA if the official schedule is already in hand.</li>
<li>Later use TBA Sync to link the same manual Neptune event to the exact official TBA event. Compare the TBA schedule with the manually entered schedule and resolve any legitimate event revisions without creating a second Neptune event.</li>
</ol>
<h3>Scenario: TBA API is temporarily unavailable</h3>
<ul class="manual-list">
<li>Continue with already-loaded Neptune data and local scouting. If the match schedule was never loaded but the event has issued a paper schedule, switch to manual schedule entry.</li>
<li>Do not delete/recreate the event to fix an external sync outage. Keep all manual roster, schedule, pit, scouting, and strategy data attached to the same event.</li>
<li>Use the official event paper schedule as the source for manual robot and match entry; field announcements supersede an older paper copy when the event issues a revision.</li>
<li>Retry TBA Sync when external access returns. Link/sync the same event, compare the schedules, and update only genuine official changes before the next Match Control action.</li>
</ul>
<aside class="manual-callout"><div class="manual-callout-title">IMPORTANT LATE-TBA FALLBACK<br>A late TBA schedule does not stop match scouting. The manual process takes over: use the official paper schedule issued at the event, add any missing robots to the event roster, manually build the match schedule, and enter every Red/Blue station assignment exactly as printed. After verification, Match Control and Traditional Match Scouting operate normally. When TBA later publishes the schedule, sync/link the same event and reconcile any official revisions.</div></aside>
<div class="manual-eyebrow">SECTION 5</div>
</section>
<section class="manual-section" id="admin-event-day-command">
<h2>Admin event-day command</h2>
<p class="manual-lead">The Match Control operator is the clock and state coordinator for the scouting system.</p>
<?php neptune_training_gallery([
 ['match-control','Match Control coordinates Ready/Running/Paused/Ended state and shows the next match that can be made ready.'],
 ['live-monitor','Keep Live Monitor open to watch station coverage and incoming activity while the match is running.']
]); ?>
<h3>Morning startup checklist</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Open the correct Current Event.</span></li>
<li><i class="fa-regular fa-square"></i><span>Attempt TBA Sync. If the schedule is still missing, confirm the verified manual schedule is complete and current.</span></li>
<li><i class="fa-regular fa-square"></i><span>Spot-check your team’s schedule and several alliance assignments against the latest official paper/electronic event schedule.</span></li>
<li><i class="fa-regular fa-square"></i><span>Open Match Control and confirm the first match is Scheduled with all six robot/station assignments, whether entered manually or synced from TBA.</span></li>
<li><i class="fa-regular fa-square"></i><span>Open Live Monitor in a second tab/device if staffing allows.</span></li>
<li><i class="fa-regular fa-square"></i><span>Have all scout devices connected and signed in before the first qualification match.</span></li>
<li><i class="fa-regular fa-square"></i><span>Have Strategy open the Match Board / Match Strategy for the upcoming match.</span></li>
</ul>
<h3>The repeatable match loop</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>When</strong></p></th>
<th><p><strong>Admin / SATURN</strong></p></th>
<th><p><strong>Scouts / TRIDENT</strong></p></th>
<th><p><strong>Strategy / AUGUR</strong></p></th>
</tr>
<tr>
<td><p>2–3 matches ahead</p></td>
<td><p>Watch for schedule changes; keep next matches visible.</p></td>
<td><p>Rotate/break without losing coverage.</p></td>
<td><p>Review upcoming partners/opponents; inspect Robot Intelligence.</p></td>
</tr>
<tr>
<td><p>1 match ahead</p></td>
<td><p>Make the correct match Ready. Open Monitor.</p></td>
<td><p>Confirm assigned robot/station and remain on the ready screen.</p></td>
<td><p>Finalize roles, auto conflicts, defense target, endgame.</p></td>
</tr>
<tr>
<td><p>At field start</p></td>
<td><p>Click Start Match as the real match starts.</p></td>
<td><p>Begin recording observed actions/Tag observations.</p></td>
<td><p>Watch only if useful; do not distract scouts.</p></td>
</tr>
<tr>
<td><p>During match</p></td>
<td><p>Watch Live Monitor; use Pause only when the scouting clock truly needs to stop.</p></td>
<td><p>Stay on assigned robot; record only what happened.</p></td>
<td><p>Capture exceptional context as Tag Team/Pit observations or strategy notes if needed.</p></td>
</tr>
<tr>
<td><p>At match end</p></td>
<td><p>Click End. Verify sessions/actions arrived.</p></td>
<td><p>Finish/save and report any error immediately.</p></td>
<td><p>Review result/data confidence; prepare next match.</p></td>
</tr>
</table></div>
<h3>Make Ready</h3>
<p>“Make Ready” should be used on the correct upcoming match after all robot/station assignments are present. Those assignments may come from TBA or from the verified manual schedule built from the event’s paper copy. If a match has no teams assigned, complete/correct the manual schedule or TBA sync before making it Ready.</p>
<h3>Start / Pause / Resume / End</h3>
<ul class="manual-list">
<li>Start: click when the real match starts. Starting too early or late shifts action timestamps and derived cycle-time context.</li>
<li>Pause: use only when the real match/scouting operation is paused or when the crew must intentionally stop the Neptune clock.</li>
<li>Resume: resumes the same run and accounts for pause time.</li>
<li>End: use when the match is over. The run is preserved for analytics and audit history.</li>
</ul>
<h3>Live Monitor operator duties</h3>
<ul class="manual-list">
<li>Confirm expected scouts are connected and on the correct robots.</li>
<li>Watch incoming action counts instead of assuming every phone is writing.</li>
<li>Identify a zero-action station that should be active and contact the scout quickly.</li>
<li>Correct isolated erroneous actions using the supported administrative action-history/soft-delete workflow rather than re-scouting an entire match.</li>
<li>Escalate a wrong-robot or completely corrupted run to the lead admin/strategy person.</li>
</ul>
<div class="manual-eyebrow">SECTION 6</div>
</section>
<section class="manual-section" id="event-day-scenario-playbook">
<h2>Event-day scenario playbook</h2>
<p class="manual-lead">Use these procedures under pressure. The goal is to preserve data quality, not merely make the UI look green.</p>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Scenario</strong></p></th>
<th><p><strong>Signal</strong></p></th>
<th><p><strong>Correct response</strong></p></th>
<th><p><strong>Do not</strong></p></th>
</tr>
<tr>
<td><p>TBA schedule is late</p></td>
<td><p>Roster exists; TBA has 0 matches; event has issued an official paper schedule</p></td>
<td><p>Manual takeover: keep the same event; add missing robots; build matches from the paper schedule; enter Red 1–3 / Blue 1–3 exactly; verify; then run Match Control and scouting normally. Sync/link TBA later.</p></td>
<td><p>Waiting for TBA when the official paper schedule is already available; creating a duplicate event; guessing assignments.</p></td>
</tr>
<tr>
<td><p>Schedule is posted at the last minute</p></td>
<td><p>Official paper/electronic schedule becomes available shortly before matches</p></td>
<td><p>Use whichever official source is available first. If TBA is late, manually enter the schedule immediately; verify your first matches and stations; begin scouting. Sync TBA later when available.</p></td>
<td><p>Delaying scouting just because TBA has not updated.</p></td>
</tr>
<tr>
<td><p>Schedule changes after first sync</p></td>
<td><p>Field announces revised schedule</p></td>
<td><p>Use the newest official event schedule. Update the affected manual/TBA-loaded matches, re-sync when available, spot-check changed assignments, and Ready only the revised correct match.</p></td>
<td><p>Continuing from a stale paper copy, screenshot, or earlier TBA version.</p></td>
</tr>
<tr>
<td><p>TBA score/results are delayed</p></td>
<td><p>Match ended but official score missing</p></td>
<td><p>Keep scouting data; continue next match. Re-sync later to fill official result/context.</p></td>
<td><p>Editing scouting data to make it equal the field score.</p></td>
</tr>
<tr>
<td><p>TBA is down</p></td>
<td><p>External sync fails</p></td>
<td><p>Use already-loaded data. If no schedule is loaded, build it manually from the official paper copy and continue local Neptune operations. Sync TBA later.</p></td>
<td><p>Rebuilding the event, waiting unnecessarily for TBA, or guessing teams/stations.</p></td>
</tr>
<tr>
<td><p>Scout phone briefly loses connection</p></td>
<td><p>Scout app still open</p></td>
<td><p>Continue scouting; queued Traditional requests can be protected locally during temporary network interruption. Restore connection and verify data arrived.</p></td>
<td><p>Refreshing repeatedly or clearing browser/site data during the outage.</p></td>
</tr>
<tr>
<td><p>A scout is missing at Ready</p></td>
<td><p>Live Monitor shows missing coverage</p></td>
<td><p>Assign backup immediately. If no backup, choose which robot is most important and document the coverage gap.</p></td>
<td><p>Fabricating action data after the match.</p></td>
</tr>
<tr>
<td><p>Scout realizes wrong robot before start</p></td>
<td><p>Assignment mismatch</p></td>
<td><p>Stop and correct assignment before Start. Recheck alliance/station/team.</p></td>
<td><p>Scouting the wrong robot “just for this one.”</p></td>
</tr>
<tr>
<td><p>Wrong robot was scouted for most/all match</p></td>
<td><p>Data belongs to wrong team</p></td>
<td><p>Flag the run. Use admin correction/soft-delete for isolated actions; for a fully bad run, use an approved clean re-scout from reliable video if available.</p></td>
<td><p>Leaving known bad data in analytics.</p></td>
</tr>
<tr>
<td><p>Admin clicked Start late</p></td>
<td><p>Neptune timer behind field</p></td>
<td><p>Start immediately; tell Strategy that time-derived metrics may be shifted. If the shift makes the run unusable, plan a clean re-entry from reliable video.</p></td>
<td><p>Pretending cycle timing is accurate.</p></td>
</tr>
<tr>
<td><p>Field match is replayed/restarted</p></td>
<td><p>Original result/run is superseded</p></td>
<td><p>After the original run is ended, use Re-scout / Ready Again. Neptune increments the run, voids prior run actions from normal analytics, and preserves history.</p></td>
<td><p>Deleting history manually or mixing both runs.</p></td>
</tr>
<tr>
<td><p>A single action was entered wrong</p></td>
<td><p>One obvious data error</p></td>
<td><p>Use action-history/admin correction to remove the bad action while keeping the rest of the run.</p></td>
<td><p>Re-scouting the whole match for one tap.</p></td>
</tr>
<tr>
<td><p>Server becomes unreachable</p></td>
<td><p>No device can load Neptune</p></td>
<td><p>Switch to the team’s documented paper/backup method, preserve robot/match identity and timestamps as possible, restore service, then re-enter only data you can defend.</p></td>
<td><p>Guessing missing details.</p></td>
</tr>
<tr>
<td><p>Public EPA is missing/stale</p></td>
<td><p>AUGUR shows fallback/low confidence</p></td>
<td><p>Use scouting, prior-event data, Robot Intelligence, and direct observation. Treat projection as lower confidence.</p></td>
<td><p>Assuming “no EPA” means “bad robot.”</p></td>
</tr>
<tr>
<td><p>Pit data conflicts with match observation</p></td>
<td><p>Claimed capability is not seen</p></td>
<td><p>Keep both: pit claim is context; observed match performance is evidence. Strategy should use claimed-vs-observed reliability.</p></td>
<td><p>Overwriting observation to match the pit claim.</p></td>
</tr>
</table></div>
<div class="manual-eyebrow">SECTION 7</div>
</section>
<section class="manual-section" id="traditional-match-scouting-training">
<h2>Traditional Match Scouting training</h2>
<?php neptune_training_gallery([
 ['live-match-scouting','The scout should confirm the assigned robot and match before recording actions.','phone'],
 ['action-swipe','Tap an action, then swipe left for Failure or right for Success.','phone']
]); ?>
<p class="manual-lead">Traditional scouting produces action-by-action data: points, success/failure, timing, cycle context, offense, defense, and phase behavior.</p>
<h3>Scout objective</h3>
<aside class="manual-callout"><div class="manual-callout-title">ONE ROBOT, FULL MATCH</div><p>Your job is not to watch the whole alliance. Stay with the assigned robot from the start of Autonomous through the end of the match. Accuracy is more valuable than speed.</p></aside>
<h3>Before the match</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Sign in to Neptune and open TRIDENT → Match Scouting.</span></li>
<li><i class="fa-regular fa-square"></i><span>Wait for the admin to make the match Ready.</span></li>
<li><i class="fa-regular fa-square"></i><span>Confirm match number, Red/Blue alliance, field station, and assigned team number.</span></li>
<li><i class="fa-regular fa-square"></i><span>Physically locate the robot before the match starts.</span></li>
<li><i class="fa-regular fa-square"></i><span>If anything is wrong, tell the admin before Start.</span></li>
</ul>
<h3>During the match</h3>
<ol class="manual-steps">
<li>When the real match starts, the admin starts Neptune. The interface follows the configured Autonomous, transition, Teleop, and Endgame phases.</li>
<li>Tap the game-defined action that matches what your robot just attempted/did.</li>
<li>Swipe RIGHT for Success and LEFT for Failure when an action asks for an outcome.</li>
<li>For repeated occurrences of the same action, continue entering outcomes without unnecessarily reopening the action selector when the interface keeps it active.</li>
<li>Record defense only when the configured defense action actually applies. Do not turn “robot near opponent” into successful defense.</li>
<li>Keep eyes on the robot more than on the screen. Use button familiarity and short glances.</li>
<li>If you miss an action, do not invent it. Continue from the next thing you clearly observe.</li>
</ol>
<h3>What “Success” and “Failure” mean</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Situation</strong></p></th>
<th><p><strong>Record</strong></p></th>
<th><p><strong>Reason</strong></p></th>
</tr>
<tr>
<td><p>Robot completes the configured scoring/action outcome</p></td>
<td><p>Success</p></td>
<td><p>It achieved the outcome represented by the button.</p></td>
</tr>
<tr>
<td><p>Robot clearly attempts that configured action but does not achieve it</p></td>
<td><p>Failure</p></td>
<td><p>Captures unsuccessful attempts and success rate.</p></td>
</tr>
<tr>
<td><p>You did not see the attempt clearly</p></td>
<td><p>Nothing / no guess</p></td>
<td><p>Unknown is better than false precision.</p></td>
</tr>
<tr>
<td><p>Robot does something not represented by the game layout</p></td>
<td><p>Use the closest approved configured action only if it truly means the same thing; otherwise escalate after the match</p></td>
<td><p>Changing meanings scout-by-scout destroys comparability.</p></td>
</tr>
</table></div>
<h3>After the match</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Confirm your match/session ended normally.</span></li>
<li><i class="fa-regular fa-square"></i><span>Check the visible action count/last-action confirmation if available and make sure nothing appears obviously missing.</span></li>
<li><i class="fa-regular fa-square"></i><span>If the device had a connection interruption, reconnect and verify queued entries have synchronized before closing/clearing anything.</span></li>
<li><i class="fa-regular fa-square"></i><span>Report wrong-team scouting, a missed large portion of the match, or other major quality issue immediately.</span></li>
<li><i class="fa-regular fa-square"></i><span>Do not edit or reinterpret data because the official score “looks different.” Traditional data measures the robot actions Neptune was configured to record, not necessarily every component of the FMS score.</span></li>
</ul>
<h3>Traditional scout quality standard</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Good scout behavior</strong></p></th>
<th><p><strong>Bad scout behavior</strong></p></th>
</tr>
<tr>
<td><p>Tracks one robot consistently</p></td>
<td><p>Watches the ball/game and loses the assigned robot</p></td>
</tr>
<tr>
<td><p>Uses Success/Failure consistently</p></td>
<td><p>Uses Success as “I saw something happen”</p></td>
</tr>
<tr>
<td><p>Leaves unknown events unrecorded</p></td>
<td><p>Guesses missed cycles</p></td>
</tr>
<tr>
<td><p>Reports errors immediately</p></td>
<td><p>Hides mistakes so the match “looks complete”</p></td>
</tr>
<tr>
<td><p>Keeps browser/app state intact during temporary outage</p></td>
<td><p>Refreshes/clears data repeatedly when the network flickers</p></td>
</tr>
</table></div>
<div class="manual-eyebrow">SECTION 8</div>
</section>
<section class="manual-section" id="tag-scouting-training">
<h2>Tag Scouting training</h2>
<?php neptune_training_gallery([
 ['tag-match','Choose the event, match, and robot before starting the observation.','phone'],
 ['tag-observations','Select only traits clearly demonstrated in the current observation.','phone']
]); ?>
<p class="manual-lead">Tag Scouting is Neptune’s unified quick-observation system. The Match context captures scoring share, tags/weights, and notes; Pit and Team contexts capture quick contextual observations and media. Tag data is currently kept separate from AUGUR analytics.</p>
<h3>Tag Scouting is not Traditional Scouting</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Traditional</strong></p></th>
<th><p><strong>Tag Scouting</strong></p></th>
</tr>
<tr>
<td><p>Action-by-action event recording</p></td>
<td><p>Structured observation of robot performance in a match</p></td>
</tr>
<tr>
<td><p>Produces points, attempts, success rate, cycle timing, defense actions</p></td>
<td><p>Produces scoring-share estimate, selected tags, optional 1–5 tag strength/weight, and notes</p></td>
</tr>
<tr>
<td><p>Best when you have dedicated robot coverage</p></td>
<td><p>Best for fast qualitative coverage, redundancy, drive quality, defense, reliability, and traits that are hard to capture as button counts</p></td>
</tr>
<tr>
<td><p>One scout typically follows one assigned robot</p></td>
<td><p>Multiple observers can independently describe the same robot/match; keep their observations independent and review disagreement directly</p></td>
</tr>
</table></div>
<h3>Core Tag Scouting workflow</h3>
<ol class="manual-steps">
<li>Open Tag Scouting → Match and confirm the event and match context.</li>
<li>Choose/confirm the robot you are observing and verify alliance/station.</li>
<li>Watch the robot through the entire match. Do not apply tags from reputation, pit claims, or previous matches.</li>
<li>Estimate scoring share: the approximate percentage of the alliance’s scoring contribution attributable to this robot in this match. Use 0–100 as a relative estimate, not raw robot points.</li>
<li>Select only tags that were clearly demonstrated in this match.</li>
<li>If the interface asks for a tag strength/weight, use the team’s 1–5 rubric below.</li>
<li>Add a short note only when it explains context the tags cannot capture, such as “disabled after collision at 0:42” or “defense caused by partner request after mid-match.”</li>
<li>Save one defensible observation. Another observer may disagree; preserve both records rather than forcing agreement or rewriting one to match the other.</li>
</ol>
<h3>Recommended 1–5 tag-strength rubric</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Weight</strong></p></th>
<th><p><strong>Training meaning</strong></p></th>
<th><p><strong>Example</strong></p></th>
</tr>
<tr>
<td><p>1</p></td>
<td><p>Weak / barely demonstrated</p></td>
<td><p>One brief instance; may not be repeatable.</p></td>
</tr>
<tr>
<td><p>2</p></td>
<td><p>Some evidence</p></td>
<td><p>More than incidental, but not a defining trait.</p></td>
</tr>
<tr>
<td><p>3</p></td>
<td><p>Clear / meaningful</p></td>
<td><p>Repeated or strategically relevant behavior.</p></td>
</tr>
<tr>
<td><p>4</p></td>
<td><p>Strong</p></td>
<td><p>Consistently important throughout the match.</p></td>
</tr>
<tr>
<td><p>5</p></td>
<td><p>Dominant / unmistakable</p></td>
<td><p>One of the defining features of the robot’s match performance.</p></td>
</tr>
</table></div>
<aside class="manual-callout"><div class="manual-callout-title">Consistency rule</div><p>The 1–5 rubric is a team training convention for consistent use. Store the weight that matches the evidence you actually observed; the human meaning must be trained consistently by your team.</p></aside>
<h3>Current structured tag vocabulary</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Category</strong></p></th>
<th><p><strong>Tags</strong></p></th>
</tr>
<tr>
<td><p>Autonomous</p></td>
<td><p>Impressive Auton; Consistent Auton; Weak Auton; No Auton</p></td>
</tr>
<tr>
<td><p>Scoring / cycling</p></td>
<td><p>Scoring a Lot; Fast Cycles; Hard to Defend; Struggles Under Defense</p></td>
</tr>
<tr>
<td><p>Defense</p></td>
<td><p>Good Defense; Elite Defense; Good Counter-Defense; No Defense</p></td>
</tr>
<tr>
<td><p>Driver / partner</p></td>
<td><p>Smart Driver; Smooth Driver; Great Alliance Partner; Strong Feeder / Support</p></td>
</tr>
<tr>
<td><p>Endgame</p></td>
<td><p>Strong Endgame; Reliable Endgame; Slow Endgame; Failed Endgame</p></td>
</tr>
<tr>
<td><p>Overall / risk</p></td>
<td><p>Clutch; Consistent; Versatile; Inconsistent; Penalty Risk; Mechanical Issues; Disabled / Dead</p></td>
</tr>
</table></div>
<h3>How to use tags correctly</h3>
<ul class="manual-list">
<li>Use “Elite Defense” only when the defense materially changes opponent performance; use “Good Defense” for effective but less dominant work.</li>
<li>Use “Hard to Defend” for a robot that keeps producing despite active pressure; “Struggles Under Defense” when pressure substantially degrades it.</li>
<li>Use “Consistent” only for repeatable execution inside the observed match context; do not turn it into a season-long claim from one match.</li>
<li>Use “Mechanical Issues” when an actual mechanical problem affects performance; “Disabled / Dead” when the robot is nonfunctional for meaningful match time.</li>
<li>Use both positive and negative tags when both are true. A robot can have a strong autonomous and mechanical issues in the same match.</li>
</ul>
<h3>Current analytics boundary</h3>
<p>Tag observations are collected and retained as a separate evidence layer. In the current production workflow, Tag data does <strong>not</strong> populate AUGUR analytics, Public/Neptune EPA, matchup predictions, or Traditional action metrics. Strategy may review Tag observations directly, but should not treat them as automatically merged into AUGUR until that analytics path is deliberately re-enabled and validated.</p>
<div class="manual-eyebrow">SECTION 9</div>
</section>
<section class="manual-section" id="tag-contexts-vs-traditional-pit-pre">
<h2>Tag contexts vs Traditional / Pit / Pre-Scouting</h2>
<p class="manual-lead">Spot Scouting was consolidated into Tag Scouting. Use the correct Tag context—or the formal Traditional, Pit, or Pre-Scout workflow—so the evidence keeps the right meaning.</p>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Tool</strong></p></th>
<th><p><strong>Use it for</strong></p></th>
<th><p><strong>Example</strong></p></th>
</tr>
<tr>
<td><p>Traditional Match Scouting</p></td>
<td><p>Precise match actions and outcomes</p></td>
<td><p>Scored action, failed attempt, defense action, timestamp/phase.</p></td>
</tr>
<tr>
<td><p>Tag · Match</p></td>
<td><p>Match-level relative contribution, observed traits, and concise context</p></td>
<td><p>Fast Cycles (4/5), 45% scoring share, Mechanical Issues, short match note.</p></td>
</tr>
<tr>
<td><p>Tag · Pit</p></td>
<td><p>Quick pit-context observation, tags/notes, severity, and media without replacing the formal Pit form</p></td>
<td><p>“New intake damaged after Q28,” pit photo/video, repair status.</p></td>
</tr>
<tr>
<td><p>Tag · Team</p></td>
<td><p>General team/season observation not tied to a specific live match or pit visit</p></td>
<td><p>Drive-team behavior, recurring issue, general note, supporting photo/video.</p></td>
</tr>
<tr>
<td><p>Pit Scouting</p></td>
<td><p>Robot capability/configuration and team-reported information</p></td>
<td><p>Drivetrain, mechanisms, auto start positions, endgame claim, photos.</p></td>
</tr>
<tr>
<td><p>Pre-Scouting</p></td>
<td><p>Before-event research and reusable season knowledge</p></td>
<td><p>Prior performance, robot architecture, drive notes, scouting outreach response.</p></td>
</tr>
</table></div>
<aside class="manual-callout"><div class="manual-callout-title">Do not collapse evidence types</div><p>A pit claim (“we can climb every match”) is not the same evidence as repeated observed climbs. Match Strategy intentionally compares claimed and observed endgame behavior. Preserve both instead of rewriting one to match the other.</p></aside>
<h3>Recommended hybrid coverage models</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Staffing</strong></p></th>
<th><p><strong>Coverage plan</strong></p></th>
<th><p><strong>Tradeoff</strong></p></th>
</tr>
<tr>
<td><p>6+ dedicated match scouts</p></td>
<td><p>Traditional on all six robots; optional Tag Match observers</p></td>
<td><p>Best quantitative completeness.</p></td>
</tr>
<tr>
<td><p>3–5 match scouts</p></td>
<td><p>Traditional on highest-priority robots; Tag coverage on others</p></td>
<td><p>Balanced detail and broad human context.</p></td>
</tr>
<tr>
<td><p>1–2 observers</p></td>
<td><p>Tag Scouting + targeted Traditional for your partners/opponents</p></td>
<td><p>Lower quantitative depth; focus on decision-critical robots.</p></td>
</tr>
<tr>
<td><p>Very limited crew</p></td>
<td><p>Tag Match/Pit/Team observations + formal pit/pre + public data; be explicit about low confidence</p></td>
<td><p>Useful for strategy, but predictions should be treated cautiously.</p></td>
</tr>
</table></div>
<div class="manual-eyebrow">SECTION 10</div>
</section>
<section class="manual-section" id="augur-analytics-literacy">
<h2>AUGUR analytics literacy</h2>
<p class="manual-lead">Strategy’s job is not to find the biggest number. It is to decide whether the number is trustworthy and relevant to the next decision.</p>
<h3>Core analytics pages</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Page</strong></p></th>
<th><p><strong>Question it answers</strong></p></th>
<th><p><strong>How to use it</strong></p></th>
</tr>
<tr>
<td><p>Robot Lookup</p></td>
<td><p>What do we know about one team?</p></td>
<td><p>Start here for a specific team. Combine identity, pit/pre, match data, EPA, notes, photos, and history.</p></td>
</tr>
<tr>
<td><p>Robot Intelligence / Robot Cards</p></td>
<td><p>What is this robot actually doing and how reliable is it?</p></td>
<td><p>Inspect scouting-derived performance, pit/pre notes and photos, Public EPA, and Neptune EPA. Review Tag observations separately when they are relevant.</p></td>
</tr>
<tr>
<td><p>Match Board / Pit Match Board</p></td>
<td><p>What do the six robots in this matchup look like?</p></td>
<td><p>Compare both alliances, then open robot details. Switch Traditional vs Tag view when applicable.</p></td>
</tr>
<tr>
<td><p>EPA Ratings</p></td>
<td><p>What does the public-results model say?</p></td>
<td><p>Use overall and phase-specific Auto/Teleop/Endgame EPA as a baseline—not as a substitute for local observation.</p></td>
</tr>
<tr>
<td><p>Match Strategy</p></td>
<td><p>What is our executable plan for this match?</p></td>
<td><p>Use projection, roles, autonomous intelligence, opponent watch, phase plan, and endgame reliability; save the drive-team plan.</p></td>
</tr>
<tr>
<td><p>Alliance Selection</p></td>
<td><p>Who should be on our pick list / scenario board?</p></td>
<td><p>Combine robot intelligence, EPA, scouting, tags, reliability, history, and live availability/declines.</p></td>
</tr>
<tr>
<td><p>Database Lab</p></td>
<td><p>What custom question can Strategy answer from raw organization data?</p></td>
<td><p>Use read-only queries for deeper analysis; keep findings tied to a real strategic question.</p></td>
</tr>
</table></div>
<h3>Public EPA vs Neptune EPA vs prediction</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Metric</strong></p></th>
<th><p><strong>What it is</strong></p></th>
<th><p><strong>Best use</strong></p></th>
<th><p><strong>Caution</strong></p></th>
</tr>
<tr>
<td><p>Public EPA</p></td>
<td><p>AUGUR’s locally archived public-data strength baseline, including phase-specific ratings.</p></td>
<td><p>Broad baseline, early event, cross-event comparison.</p></td>
<td><p>Can lag a repaired, changed, or role-shifted robot.</p></td>
</tr>
<tr>
<td><p>Neptune EPA</p></td>
<td><p>Organization-private offensive strength estimate that blends public baseline with your observed scouting.</p></td>
<td><p>Current-event offensive robot strength reference.</p></td>
<td><p>Still an estimate; depends on observation quality and model settings.</p></td>
</tr>
<tr>
<td><p>AUGUR matchup prediction</p></td>
<td><p>Alliance comparison using shared model settings, offense/defense context, score ranges, win probability, confidence, and fallbacks.</p></td>
<td><p>Frame risk and identify where strategy could change the matchup.</p></td>
<td><p>Never treat one probability as a guaranteed outcome.</p></td>
</tr>
</table></div>
<h3>Data-confidence habit</h3>
<ol class="manual-steps">
<li>Check how many prior matches are actually scouted for each robot.</li>
<li>Check whether pit scouting is complete or missing.</li>
<li>Review recent Tag observations directly for repeated themes or disagreement, and check recent mechanical issues.</li>
<li>Compare current-event observation to public/prior-event baseline.</li>
<li>Only then interpret the predicted score/win probability.</li>
</ol>
<aside class="manual-callout"><div class="manual-callout-title">Strategy rule</div><p>A 70% win prediction with weak coverage is less actionable than a lower-confidence plan built from direct observations you can explain. Always be able to answer: “What evidence would make us change this plan?”</p></aside>
<div class="manual-eyebrow">SECTION 11</div>
</section>
<section class="manual-section" id="how-strategy-should-use-match-strategy">
<h2>How Strategy should use Match Strategy</h2>
<p class="manual-lead">Turn six robots and many metrics into one short plan the drive team can execute.</p>
<h3>Recommended workflow: 10–15 minutes before your match</h3>
<ol class="manual-steps">
<li>Select the correct event, your team, and upcoming match.</li>
<li>Read Data Confidence first. Identify robots with no/limited prior scouting or missing pit information.</li>
<li>Read the AUGUR prediction: predicted scores, win probability, score range, model confidence, Public EPA/AUGUR EPA context, trend/slope, and defense impact. Treat it as context, not the plan.</li>
<li>Open important Robot Intelligence cards. Resolve obvious contradictions: e.g., public rating says strong but a recent Tag observation or pit update reports mechanical problems today.</li>
<li>Review Alliance Roles. AUGUR may suggest Primary scorer, Secondary scorer, Defense, or Feeder/support from observed behavior; Strategy can override it.</li>
<li>Review Autonomous Intelligence. Confirm start positions, observed auto performance, partner restrictions, and collision risks.</li>
<li>Review Opponent Watch. Choose a defense target only when the expected value of defense beats what the defender would score otherwise.</li>
<li>Fill the phase-by-phase plan for all three partners: Autonomous, Teleop, Endgame.</li>
<li>Compare claimed vs observed endgame reliability. Do not allocate a scarce endgame position solely from pit claims.</li>
<li>Write the final Key Objectives, Autonomous Notes, and Drive Team Notes. Keep them short enough to brief verbally.</li>
<li>Confirm: Reviewed with drive coach; Autonomous paths confirmed; Endgame responsibilities confirmed. Save the Match Strategy.</li>
</ol>
<h3>Drive-team handoff format</h3>
<aside class="manual-callout"><div class="manual-callout-title">Use 5 lines or fewer</div><p>1) Win condition / primary objective.  2) Autonomous starts and collision avoidance.  3) Teleop roles and priority scoring lanes.  4) Opponent/defense target and trigger.  5) Endgame responsibility and backup.</p></aside>
<h3>Example decision logic</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Evidence</strong></p></th>
<th><p><strong>Interpretation</strong></p></th>
<th><p><strong>Possible strategy response</strong></p></th>
</tr>
<tr>
<td><p>Opponent has highest observed PPM + fast cycles + struggles under defense</p></td>
<td><p>High offensive threat with exploitable weakness</p></td>
<td><p>Assign defense if your third robot gives up less offense than the expected suppression.</p></td>
</tr>
<tr>
<td><p>Partner claims strong endgame, observed 1/3 successes</p></td>
<td><p>Capability exists but reliability is low</p></td>
<td><p>Give earlier endgame start or assign backup position.</p></td>
</tr>
<tr>
<td><p>Robot has high Public EPA but Tag shows disabled/mechanical issues in latest match</p></td>
<td><p>Baseline may be stale relative to event condition</p></td>
<td><p>Inspect the latest Tag/Pit note; discount until repaired/verified.</p></td>
</tr>
<tr>
<td><p>Prediction favors you heavily but low model confidence</p></td>
<td><p>Outcome is uncertain despite favorable estimate</p></td>
<td><p>Use conservative, low-conflict plan and validate partner capabilities directly.</p></td>
</tr>
</table></div>
<div class="manual-eyebrow">SECTION 12</div>
</section>
<section class="manual-section" id="alliance-selection-training">
<h2>Alliance Selection training</h2>
<p class="manual-lead">Build a live decision workspace, not a single sorted spreadsheet.</p>
<h3>Before alliance selection</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Open Alliance Selection well before the ceremony.</span></li>
<li><i class="fa-regular fa-square"></i><span>Confirm official qualification ranking/captain context has refreshed when available.</span></li>
<li><i class="fa-regular fa-square"></i><span>Create at least a primary pick list and one alternate scenario.</span></li>
<li><i class="fa-regular fa-square"></i><span>Inspect top candidates in Robot Intelligence: Event Stats, EPA, Offense, Defense, Game Data, Pit Scouting, Robot Photos, and Alliance History as available; review Tag observations separately when needed.</span></li>
<li><i class="fa-regular fa-square"></i><span>Use shared AUGUR model settings consistently with Match Strategy so tools are not arguing from different assumptions.</span></li>
<li><i class="fa-regular fa-square"></i><span>Mark favorites and track broken/unavailable robots based on current evidence.</span></li>
</ul>
<h3>What should drive a pick</h3>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Dimension</strong></p></th>
<th><p><strong>Questions</strong></p></th>
</tr>
<tr>
<td><p>Scoring ceiling</p></td>
<td><p>Can the robot create points in the role we need, not just in its usual role?</p></td>
</tr>
<tr>
<td><p>Reliability</p></td>
<td><p>How often does it finish matches functional? Are endgame and autonomous claims actually observed?</p></td>
</tr>
<tr>
<td><p>Complementarity</p></td>
<td><p>Does its auto path, scoring lane, intake geometry, endgame space, or defense role fit our robot?</p></td>
</tr>
<tr>
<td><p>Defense / counter-defense</p></td>
<td><p>Can it suppress the right opponents? Can it keep scoring under pressure?</p></td>
</tr>
<tr>
<td><p>Driver / partnership</p></td>
<td><p>Is the drive team smooth, smart, communicative, and adaptable?</p></td>
</tr>
<tr>
<td><p>Data confidence</p></td>
<td><p>How many matches/observers support the conclusion?</p></td>
</tr>
<tr>
<td><p>Availability</p></td>
<td><p>Captain status, prior selections, declines, broken/unavailable state.</p></td>
</tr>
</table></div>
<h3>Do not build a pick list from one column</h3>
<p>Use Public EPA and Neptune EPA as useful summary measures, but test them against Traditional actions, separately reviewed Tag observations, pit information, direct strategy observation, reliability, and alliance fit. A lower-rated robot may be the better pick if its autonomous route, defense, feeding, or endgame role unlocks more total alliance value.</p>
<div class="manual-eyebrow">SECTION 13</div>
</section>
<section class="manual-section" id="end-of-day-and-post-event-operations">
<h2>End-of-day and post-event operations</h2>
<p class="manual-lead">Protect the data you collected and make the next day easier.</p>
<h3>End of competition day</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Re-sync TBA when available so completed scores/results and official schedule revisions are captured. Compare against any manually entered schedule before accepting changes.</span></li>
<li><i class="fa-regular fa-square"></i><span>Confirm no match remains accidentally Running or Paused.</span></li>
<li><i class="fa-regular fa-square"></i><span>Review coverage gaps and major scouting incidents while people still remember them.</span></li>
<li><i class="fa-regular fa-square"></i><span>Confirm pit scouting completion status and outstanding robot issues.</span></li>
<li><i class="fa-regular fa-square"></i><span>Update Tag observations for repaired/broken robots only with new observations; do not erase prior history.</span></li>
<li><i class="fa-regular fa-square"></i><span>Charge all scouting devices and admin laptops.</span></li>
<li><i class="fa-regular fa-square"></i><span>If your deployment has a backup/export procedure, run it after competition operations are finished—not in the middle of a match block.</span></li>
</ul>
<h3>After the event</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Mark the event Complete when competition work is finished.</span></li>
<li><i class="fa-regular fa-square"></i><span>Preserve the event’s scouting and strategy records for season-level learning.</span></li>
<li><i class="fa-regular fa-square"></i><span>Write down problems that require software/configuration changes before the next event.</span></li>
<li><i class="fa-regular fa-square"></i><span>Test any patch or Game Builder change away from production competition use before deployment.</span></li>
<li><i class="fa-regular fa-square"></i><span>Review which Tag definitions and Traditional actions produced useful strategy signals and retrain before the next event.</span></li>
</ul>
<div class="manual-eyebrow">SECTION 14</div>
</section>
<section class="manual-section" id="event-day-quick-reference-cards">
<h2>Event-day quick-reference cards</h2>
<p class="manual-lead">Print these pages or keep them open on the admin/strategy device.</p>
<h3>ADMIN QUICK CARD</h3>
<aside class="manual-callout"><div class="manual-callout-title">Before first match</div><p>Current Event ✓  |  TBA sync ✓  |  schedule spot-check ✓  |  scouts logged in ✓  |  Live Monitor open ✓  |  backup scout identified ✓</p></aside>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Step</strong></p></th>
<th><p><strong>Admin action</strong></p></th>
</tr>
<tr>
<td><p>1</p></td>
<td><p>Make the correct next match Ready.</p></td>
</tr>
<tr>
<td><p>2</p></td>
<td><p>Monitor scout assignments/connections.</p></td>
</tr>
<tr>
<td><p>3</p></td>
<td><p>At real field start: Start Match.</p></td>
</tr>
<tr>
<td><p>4</p></td>
<td><p>During match: monitor; Pause only if actually needed.</p></td>
</tr>
<tr>
<td><p>5</p></td>
<td><p>At real field end: End.</p></td>
</tr>
<tr>
<td><p>6</p></td>
<td><p>Verify coverage/actions; fix isolated errors.</p></td>
</tr>
<tr>
<td><p>7</p></td>
<td><p>If official replay: Re-scout / Ready Again.</p></td>
</tr>
<tr>
<td><p>8</p></td>
<td><p>Move immediately to next match.</p></td>
</tr>
</table></div>
<h4>If TBA is late</h4>
<p>Keep the same event. Use the official paper schedule: add missing robots, manually build matches and all Red/Blue station assignments, verify against the paper copy, then run Match Control and scouting normally. When TBA catches up, sync/link the same event and reconcile revisions.</p>
<h4>If network flickers</h4>
<p>Keep scouts on their current page; restore connection; verify queued data arrives. Do not tell everyone to clear/reload browsers.</p>
<h3>TRADITIONAL SCOUT QUICK CARD</h3>
<aside class="manual-callout"><div class="manual-callout-title">Check before Start</div><p>MATCH #  •  RED/BLUE  •  STATION  •  TEAM #  •  PHYSICALLY LOCATE ROBOT</p></aside>
<ul class="manual-list">
<li>One robot all match.</li>
<li>Tap the exact configured action.</li>
<li>Swipe RIGHT = Success; LEFT = Failure.</li>
<li>Missed it? Do not guess.</li>
<li>Network flicker? Keep scouting; report/sync after reconnect.</li>
<li>Major mistake? Tell admin immediately.</li>
</ul>
<h3>TAG SCOUT QUICK CARD</h3>
<ul class="manual-list">
<li>Watch the robot for the full match.</li>
<li>Estimate scoring share (0–100% relative contribution), not raw points.</li>
<li>Select only tags observed in this match.</li>
<li>Use 1–5 strength consistently when requested.</li>
<li>Short context note only if tags are insufficient.</li>
<li>Consensus beats a single opinion.</li>
</ul>
<h3>STRATEGY QUICK CARD</h3>
<ul class="manual-list">
<li>Check Data Confidence first.</li>
<li>Prediction = context, not command.</li>
<li>Open Robot Intelligence for decision-critical robots.</li>
<li>Resolve auto path conflicts.</li>
<li>Set roles + defense target only from explainable evidence.</li>
<li>Plan Autonomous / Teleop / Endgame for each partner.</li>
<li>Compare claimed vs observed endgame.</li>
<li>Brief drive team in ≤5 lines and confirm responsibilities.</li>
</ul>
<div class="manual-eyebrow">SECTION 15</div>
</section>
<section class="manual-section" id="practice-scenarios-and-crew-certification">
<h2>Practice scenarios and crew certification</h2>
<p class="manual-lead">Do not make the first real qualification match the team’s first full Neptune rehearsal.</p>
<h3>Admin practical test</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Create a training event and make it Current.</span></li>
<li><i class="fa-regular fa-square"></i><span>Add/paste a roster.</span></li>
<li><i class="fa-regular fa-square"></i><span>Demonstrate the late-TBA fallback: add robots manually and build at least two matches from a sample official paper schedule.</span></li>
<li><i class="fa-regular fa-square"></i><span>Verify the manual Red/Blue station assignments, make the manual schedule operational, and explain how the same event is later linked/synced to TBA.</span></li>
<li><i class="fa-regular fa-square"></i><span>Make a match Ready; Start; Pause; Resume; End.</span></li>
<li><i class="fa-regular fa-square"></i><span>Demonstrate Live Monitor coverage checking.</span></li>
<li><i class="fa-regular fa-square"></i><span>Explain when to soft-delete one action vs re-scout an entire match.</span></li>
<li><i class="fa-regular fa-square"></i><span>Demonstrate Re-scout / Ready Again and explain why the previous run no longer counts in normal analytics.</span></li>
</ul>
<h3>Traditional scout practical test</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Correctly identify match/team/station before Start.</span></li>
<li><i class="fa-regular fa-square"></i><span>Record at least five configured actions with correct Success/Failure direction.</span></li>
<li><i class="fa-regular fa-square"></i><span>Correctly choose to record nothing when an action is not observed clearly.</span></li>
<li><i class="fa-regular fa-square"></i><span>Remain focused on assigned robot for a full practice match.</span></li>
<li><i class="fa-regular fa-square"></i><span>Explain what to do during a temporary network interruption.</span></li>
<li><i class="fa-regular fa-square"></i><span>Report a deliberate wrong-team scenario rather than continuing silently.</span></li>
</ul>
<h3>Tag scout practical test</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Explain scoring share vs raw points.</span></li>
<li><i class="fa-regular fa-square"></i><span>Apply tags only from the current observed match.</span></li>
<li><i class="fa-regular fa-square"></i><span>Use the 1–5 rubric consistently.</span></li>
<li><i class="fa-regular fa-square"></i><span>Distinguish Good Defense from Elite Defense and Hard to Defend from Struggles Under Defense.</span></li>
<li><i class="fa-regular fa-square"></i><span>Write a short note that adds context without duplicating tags.</span></li>
<li><i class="fa-regular fa-square"></i><span>Explain how to compare multiple independent Tag observations—including disagreement—without assuming AUGUR has already merged them.</span></li>
</ul>
<h3>Strategy practical test</h3>
<ul class="manual-checklist">
<li><i class="fa-regular fa-square"></i><span>Find a robot in Robot Intelligence and explain which data sources are current vs prior/public.</span></li>
<li><i class="fa-regular fa-square"></i><span>Explain that current AUGUR/Match Board analytics should be interpreted from their supported data sources and that Tag observations are reviewed separately.</span></li>
<li><i class="fa-regular fa-square"></i><span>Explain Public EPA vs Neptune EPA vs matchup prediction.</span></li>
<li><i class="fa-regular fa-square"></i><span>Check Data Confidence before interpreting the projection.</span></li>
<li><i class="fa-regular fa-square"></i><span>Build and save one complete Match Strategy with roles, auto, opponent watch, phase plan, endgame, and drive notes.</span></li>
<li><i class="fa-regular fa-square"></i><span>Build an Alliance Selection short list without sorting on a single metric.</span></li>
</ul>
<aside class="manual-callout"><div class="manual-callout-title">Certification standard</div><p>A person is event-ready when they can complete the workflow without prompting and can explain what they will do when the data is missing, late, contradictory, or wrong.</p></aside>
<div class="manual-eyebrow">APPENDIX A</div>
</section>
<section class="manual-section" id="decision-rules-for-common-data-conflicts">
<h2>Decision rules for common data conflicts</h2>
<p class="manual-lead">Use this when Strategy sees two sources that disagree.</p>
<div class="manual-table-wrap"><table class="manual-table">
<tr>
<th><p><strong>Conflict</strong></p></th>
<th><p><strong>Prefer / investigate</strong></p></th>
<th><p><strong>Reason</strong></p></th>
</tr>
<tr>
<td><p>Pit claim vs repeated Traditional failures</p></td>
<td><p>Treat observed reliability as stronger for current strategy; retain pit claim as capability context.</p></td>
<td><p>Capability is not the same as match execution.</p></td>
</tr>
<tr>
<td><p>High Public EPA vs poor current-event scouting</p></td>
<td><p>Inspect recent matches, mechanical/Tag notes, and sample size before discounting either.</p></td>
<td><p>Robot may have changed or current sample may be too small.</p></td>
</tr>
<tr>
<td><p>One Tag observer vs several opposite Tags</p></td>
<td><p>Review all independent Tag observations and open the underlying robot context; do not choose one opinion solely by weight.</p></td>
<td><p>Tag evidence can disagree, and current AUGUR analytics do not automatically resolve that disagreement for Strategy.</p></td>
</tr>
<tr>
<td><p>Traditional points vs official FMS score</p></td>
<td><p>Do not force equality. Check configured scoring scope, penalties/bonuses, and whether all robots were covered.</p></td>
<td><p>Neptune scouting can measure a different decomposition than the official score.</p></td>
</tr>
<tr>
<td><p>Model prediction vs drive-team direct information</p></td>
<td><p>Investigate the new information; update plan when the new information is credible and material.</p></td>
<td><p>A model cannot know an unrecorded last-minute repair, strategy agreement, or disabled mechanism.</p></td>
</tr>
<tr>
<td><p>Old schedule vs newly announced field schedule</p></td>
<td><p>Use the newest official event schedule immediately; update manual entries and re-sync TBA when available before Match Control.</p></td>
<td><p>The paper/manual fallback is operationally valid, but it must track official revisions just like TBA data.</p></td>
</tr>
</table></div>
<h3>Final principle</h3>
<p>Neptune is most valuable when the crew treats it as a shared evidence system. Admins protect event state, scouts protect observation quality, and Strategy protects interpretation quality. A clean “unknown” is more useful than a confident number built from guessed data.</p>
<div class="manual-eyebrow">APPENDIX B</div>
</section>
<section class="manual-section" id="software-behavior-basis">
<h2>Software-behavior basis</h2>
<p class="manual-lead">This manual was written against the current Neptune project documentation and application behavior available in the project on 28 September 2026.</p>
<ul class="manual-list">
<li>Event Setup supports creating events early, opening pit scouting, setting Current Event, manually adding/pasting a roster, and later linking/syncing TBA.</li>
<li>If TBA has no schedule, the admin can use the event’s verified official paper copy to add any missing robots and manually build matches/station assignments so competition scouting can continue on the same Neptune event.</li>
<li>Match Control requires imported match teams before Make Ready and supports Ready, Start, Pause, Resume, End, and clean re-scout runs.</li>
<li>Traditional Match Scouting uses configured game actions, Success/Failure outcomes, synchronized phases, real-time writes, and temporary connection queue protection.</li>
<li>Tag Scouting is the unified quick-observation workflow with Match, Pit, and Team contexts. Match observations can store scoring-share estimates, tags, optional 1–5 tag weights, and notes; Pit/Team observations can store contextual notes/tags/media. Tag data is currently kept separate from AUGUR analytics and Traditional action metrics.</li>
<li>AUGUR exposes Robot Intelligence, Match Board, Public EPA, Neptune EPA, shared prediction settings, Match Strategy, and Alliance Selection.</li>
<li>Match Strategy intentionally uses pre-match-only current-event data for historical predictions and shows confidence, roles, autonomous intelligence, opponent watch, phase plans, endgame reliability, and drive-team notes.</li>
</ul>
<p>Because Neptune is under active development, retrain the crew when event operations, scouting flows, Tag definitions, or AUGUR models materially change. Update this manual at the same time as the software workflow.</p>
</section>
    <div class="training-zero" id="trainingZero">No sections match that search.</div>
  </main>
</div>

<script>
(()=>{
  const q=document.getElementById('manualSearch');
  const sections=[...document.querySelectorAll('.manual-section')];
  const zero=document.getElementById('trainingZero');
  if(!q) return;
  q.addEventListener('input',()=>{
    const needle=q.value.trim().toLowerCase(); let shown=0;
    sections.forEach(s=>{const ok=!needle||s.innerText.toLowerCase().includes(needle);s.style.display=ok?'':'none';if(ok)shown++;});
    zero.style.display=shown?'none':'block';
  });
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
