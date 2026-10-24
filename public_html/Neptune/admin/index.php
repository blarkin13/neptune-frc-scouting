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
// Event-Day Health status for the hub card. A failing probe must never break the hub.
$eventHealthOverall=null;
if($isAdmin){
    try{
        require_once __DIR__.'/_event_health.php';
        $eventHealthOverall=neptune_event_health($pdo,isset($config)&&is_array($config)?$config:[],(int)$u['organization_id'])['overall'];
    }catch(Throwable $e){error_log('[Neptune hub health] '.$e->getMessage());}
}
?>

<!-- Neptune ocean atmosphere matching the dashboard. -->
<style>
.nd-ocean {--nd-sea:var(--accent,#20b9dd);position:fixed;inset:0;z-index:0;overflow:hidden;pointer-events:none;isolation:isolate}
.nd-ocean__wash,.nd-ocean__rays,.nd-ocean__particles {position:absolute;inset:-15%;pointer-events:none}
.nd-ocean__wash {background:radial-gradient(ellipse at 50% -12%,color-mix(in srgb,var(--nd-sea) 20%,transparent),transparent 59%),linear-gradient(180deg,transparent 5%,color-mix(in srgb,var(--nd-sea) 6%,transparent) 55%,transparent);opacity:.8;transform:translate3d(0,calc(var(--nd-scroll,0px)*.06),0)}
.nd-ocean__rays {background:repeating-conic-gradient(from 191deg at 50% -10%, transparent 0deg 8deg,color-mix(in srgb,var(--nd-sea) 8%,transparent) 9deg 11deg,transparent 12deg 24deg);opacity:.5;filter:blur(14px);transform:translate3d(0,calc(var(--nd-scroll,0px)*.14),0);animation:nd-current 20s ease-in-out infinite alternate}
.nd-ocean__particles {transform:translate3d(0,calc(var(--nd-scroll,0px)*-.09),0)}
.nd-ocean__bubble {position:absolute;bottom:-20px;left:var(--x);height:var(--size);width:var(--size);border:1px solid rgba(194,225,241,.58);border-radius:50%;background:radial-gradient(circle at 28% 23%,rgba(248,253,255,.32),transparent 45%);box-shadow:inset -1px -2px 5px rgba(104,158,187,.2),0 0 9px rgba(192,228,248,.14);opacity:0;animation:nd-rise var(--duration) linear var(--delay) infinite;will-change:transform,opacity}
html[data-theme="light"] .nd-ocean__bubble{border-color:rgba(58,104,132,.38);background:radial-gradient(circle at 28% 23%,rgba(255,255,255,.42),transparent 45%);box-shadow:inset -1px -2px 5px rgba(58,103,133,.15),0 0 8px rgba(76,120,150,.12)}
@keyframes nd-rise {0%{opacity:0;transform:translate3d(0,0,0) scale(.65)}12%{opacity:var(--alpha)}86%{opacity:var(--alpha)}100%{opacity:0;transform:translate3d(var(--drift),-130vh,0) scale(1.1)}}
@keyframes nd-current{from{translate:-1.5% 0}to{translate:1.5% 0}}
/* Fixed ocean spans the viewport behind the hero and all subsequent sections. */
/* Neptune page content stays above decorative water, with all controls interactive. */
body:has(.nd-ocean) main,body:has(.nd-ocean) .container {position:relative;z-index:1}
body:has(.nd-ocean) .home-hero,body:has(.nd-ocean) .module-card {box-shadow:0 12px 40px color-mix(in srgb,var(--nd-sea) 9%,transparent)}
@media(max-width:640px){.nd-ocean__rays{opacity:.32}.nd-ocean__bubble:nth-child(n+16){display:none}}
@media(prefers-reduced-motion:reduce){.nd-ocean__rays,.nd-ocean__bubble{animation:none!important}.nd-ocean__bubble{display:none!important}}
</style>
<div class="nd-ocean" aria-hidden="true"><div class="nd-ocean__wash"></div><div class="nd-ocean__rays"></div><div class="nd-ocean__particles" id="ndBubbleField"></div></div>
<script>
(function(){
  const field=document.getElementById('ndBubbleField');
  if(!field||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
  const count=window.matchMedia('(max-width: 640px)').matches?14:32;
  for(let i=0;i<count;i++){
    const b=document.createElement('span');b.className='nd-ocean__bubble';
    b.style.setProperty('--x',(Math.random()*100).toFixed(2)+'%');
    b.style.setProperty('--size',(3+Math.random()*15).toFixed(1)+'px');
    b.style.setProperty('--duration',(12+Math.random()*20).toFixed(2)+'s');
    b.style.setProperty('--delay',(-Math.random()*32).toFixed(2)+'s');
    b.style.setProperty('--drift',((Math.random()-.5)*170).toFixed(1)+'px');
    b.style.setProperty('--alpha',(.28+Math.random()*.35).toFixed(2));
    field.appendChild(b);
  }
  let ticking=false;
  window.addEventListener('scroll',()=>{
    if(ticking)return;ticking=true;
    requestAnimationFrame(()=>{document.querySelector('.nd-ocean')?.style.setProperty('--nd-scroll',Math.min(window.scrollY,3500)+'px');ticking=false});
  },{passive:true});
})();
</script>


<style id="neptune-mission-hub-style">
.neptune-hub .neptune-hub-header.nd-mission-header{position:relative;isolation:isolate;overflow:hidden;margin-bottom:clamp(20px,3vw,34px);padding:clamp(24px,4vw,52px);border:1px solid color-mix(in srgb,var(--nd-accent) 32%,var(--line,#2f4560));border-radius:20px;background:radial-gradient(ellipse at 90% 15%,color-mix(in srgb,var(--nd-accent) 8%,transparent),transparent 60%),linear-gradient(125deg,color-mix(in srgb,var(--card,#152435) 23%,transparent),color-mix(in srgb,var(--card,#152435) 13%,transparent));box-shadow:0 16px 46px rgba(0,0,0,.13);min-height:230px;display:flex;align-items:center}
.neptune-hub .nd-mission-header::before{content:none}
.neptune-hub .nd-mission-content{position:relative;z-index:1;max-width:820px;min-width:0}
.neptune-hub .nd-mission-kicker{display:block;font-size:.77rem;font-weight:850;letter-spacing:.18em;text-transform:uppercase;color:var(--nd-accent);margin-bottom:14px}
.neptune-hub .nd-mission-header h1{margin:0 0 10px!important;font-size:clamp(2.35rem,5vw,4.65rem)!important;line-height:1.04!important;letter-spacing:-.055em!important;color:var(--text,#eef7ff)!important;text-wrap:balance}
.neptune-hub .nd-mission-header h1 em{font-style:italic;color:var(--nd-accent)}
.neptune-hub .nd-mission-header p{max-width:65ch!important;color:var(--muted,#c3d6e8)!important;font-size:clamp(1rem,1.4vw,1.15rem)!important;line-height:1.65!important;margin:0!important}
.neptune-hub .nd-mission-mark{z-index:1;position:absolute;right:clamp(18px,4vw,54px);top:50%;transform:translateY(-50%);font-size:clamp(5.5rem,13vw,12rem);opacity:.1;color:var(--nd-accent);pointer-events:none}
.neptune-hub .neptune-hub-card{transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}
.neptune-hub .neptune-hub-card:hover{transform:translateY(-3px);box-shadow:0 16px 36px rgba(0,0,0,.13)}
@media(max-width:700px){.neptune-hub .neptune-hub-header.nd-mission-header{padding:24px 20px;min-height:0;border-radius:16px}.neptune-hub .nd-mission-mark{font-size:7rem;right:-12px;opacity:.06}.neptune-hub .nd-mission-header h1{font-size:clamp(2.1rem,9vw,3.1rem)!important}}
@media(prefers-reduced-motion:reduce){.neptune-hub .neptune-hub-card{transition:none}.neptune-hub .neptune-hub-card:hover{transform:none}}
</style>
<section class="module-page neptune-hub command-page">
  <header class="module-page-header neptune-hub-header nd-mission-header" style="--nd-accent:var(--accent,#47c6e5)">
    <div class="nd-mission-content">
      <span class="nd-mission-kicker">SATURN / MISSION OPERATIONS</span>
      <h1>Command Center</h1>
      <p><strong style="display:block;color:var(--text,#eff8ff);font-size:1.12em;margin-bottom:8px">Run the event. Control the mission.</strong>Coordinate the field, configure scouting, manage your organization, and keep Neptune ready for competition.</p>
    </div>
    <i class="fa-solid fa-tower-broadcast nd-mission-mark" aria-hidden="true"></i>
  </header>

  <section class="neptune-hub-section" id="event-operations">
    <div class="neptune-hub-section-head"><div><span class="neptune-hub-section-kicker">Operations</span><h2>Event Operations</h2><p>Everything needed to prepare and run live match scouting.</p></div></div>
    <div class="neptune-hub-grid three">
      <a class="neptune-hub-card accent-ops" href="<?=e(base_url('admin/match-control.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-circle-play"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">Run the field workflow</span><h3>Match Control</h3><p>Ready, start, pause, end, and re-scout matches while preserving match-run history.</p><div class="neptune-hub-meta"><span>Ready</span><span>Start</span><span>Pause</span><span>Replay</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <a class="neptune-hub-card accent-ops" href="<?=e(base_url('admin/live.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-chart-line"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">See scouting as it happens</span><h3>Live Monitor</h3><p>Watch connected scouts, robot assignments, recorded actions, and the current match state.</p><div class="neptune-hub-meta"><span>Scouts</span><span>Actions</span><span>Connections</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <a class="neptune-hub-card accent-ops" href="<?=e(base_url('admin/events.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-calendar-days"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">Prepare competition data</span><h3>Event Management</h3><p>Set up events, manage rosters and live synchronization, and review game history.</p><div class="neptune-hub-meta"><span>Events</span><span>Rosters</span><span>History</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <a class="neptune-hub-card accent-ops" href="<?=e(base_url('admin/manual-schedule.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-table-list"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">Run without a TBA listing</span><h3>Manual Match Schedule</h3><p>Enter qualification matches by field and assign the R1–R3 / B1–B3 robots that scouts will follow.</p><div class="neptune-hub-meta"><span>Matches</span><span>Fields</span><span>Robot Stations</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <?php if($isAdmin):?>
      <a class="neptune-hub-card accent-ops" href="<?=e(base_url('admin/scouting-assignments.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-user-check"></i></span><span class="neptune-hub-badge access-admin"><i class="fa-solid fa-user-shield"></i> Admin+</span></div><span class="neptune-hub-card-kicker">Plan who scouts what</span><h3>Assignment Center</h3><p>Assign match positions, distribute pit teams, request photos and answers, and track acknowledgments and completion.</p><div class="neptune-hub-meta"><span>Match Slots</span><span>Pit Tasks</span><span>Requests</span><span>Coverage</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <?php endif;?>
      <?php if($isAdmin):
        [$ehText,$ehIcon,$ehColor]=match($eventHealthOverall){
          'good'=>['All OK','fa-circle-check','var(--c1-green,#45A77D)'],
          'warn'=>['Check','fa-triangle-exclamation','#d9a400'],
          'bad'=>['Action needed','fa-circle-xmark','var(--c1-red,#D03027)'],
          default=>['Status unavailable','fa-circle-question','var(--muted)'],
        };?>
      <a class="neptune-hub-card accent-ops" href="<?=e(base_url('admin/system-check.php'))?>#event-health"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-heart-pulse"></i></span><span class="neptune-hub-badge" style="color:<?=$ehColor?>;border-color:color-mix(in srgb,<?=$ehColor?> 45%,var(--line))"><i class="fa-solid <?=$ehIcon?>"></i> <?=e($ehText)?></span></div><span class="neptune-hub-card-kicker">Check before doors open</span><h3>Event-Day Health</h3><p>Database, last production backup, scheduler heartbeat, disk space, and field data not yet uploaded, on one page.</p><div class="neptune-hub-meta"><span>Backup</span><span>Scheduler</span><span>Disk</span><span>Sync</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <?php endif;?>
      <?php if(in_array($u['role'],['owner','admin','strategy'],true)):?>
      <a class="neptune-hub-card accent-ops" href="<?=e(base_url('admin/handoff-import.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-qrcode"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">When a tablet can't connect</span><h3>Import Hand-off</h3><p>Scan a scout's hand-off QR codes with a USB scanner or camera, or load a hand-off file. Entries are saved under that scout.</p><div class="neptune-hub-meta"><span>Scanner</span><span>Camera</span><span>File</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <?php endif;?>
      <a class="neptune-hub-card accent-ops" href="<?=e(base_url('admin/pit-games.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-dice"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">Interactive pit activities</span><h3>Pit Games</h3><p>Launch Robot Recall, reaction challenges, and hands-free FRC card-magic tricks for the pit monitor.</p><div class="neptune-hub-meta"><span>Memory</span><span>Reaction</span><span>Card Magic</span><span>Auto Cycle</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
    </div>
  </section>

  <section class="neptune-hub-section" id="beta-lab">
    <div class="neptune-hub-section-head"><div><span class="neptune-hub-section-kicker" style="color:#a855f7">BETA LAB</span><h2>Experimental Tools</h2><p>Test new Neptune workflows without replacing the current competition tools.</p></div></div>
    <div class="neptune-hub-grid">
      <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/alliance-selection-beta.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-flask"></i></span><span class="neptune-hub-badge" style="color:#c084fc;border-color:color-mix(in srgb,#a855f7 45%,var(--line))"><i class="fa-solid fa-flask"></i> Beta</span></div><span class="neptune-hub-card-kicker">Multi-team strategy architecture</span><h3>Alliance Selection Beta</h3><p>Scenario-driven alliance planning with projected Captain / First Pick / Second Pick roles, shared live draft facts, and separate private strategy for each FRC team.</p><div class="neptune-hub-meta"><span>Shared Draft</span><span>Per-Team Strategy</span><span>Multi-Team Orgs</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
    </div>
  </section>

  <?php if($isAdmin):?>
  <section class="neptune-hub-section" id="vulcan">
    <div class="neptune-hub-section-head"><div><span class="neptune-hub-section-kicker" style="color:var(--module-vulcan)">VULCAN</span><h2>Builders</h2><p>Define what scouts collect and how each game is represented.</p></div></div>
    <div class="neptune-hub-grid three">
      <a class="neptune-hub-card accent-vulcan" href="<?=e(base_url('admin/game-builder.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-shapes"></i></span><span class="neptune-hub-badge access-admin"><i class="fa-solid fa-user-shield"></i> Admin+</span></div><span class="neptune-hub-card-kicker">Game configuration</span><h3>Game Builder</h3><p>Build scoring actions, field layout, action buttons, and match timing for a season.</p><div class="neptune-hub-meta"><span>Actions</span><span>Field</span><span>Timing</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <a class="neptune-hub-card accent-vulcan" href="<?=e(base_url('admin/pit-builder.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-clipboard-list"></i></span><span class="neptune-hub-badge access-admin"><i class="fa-solid fa-user-shield"></i> Admin+</span></div><span class="neptune-hub-card-kicker">Pit data collection</span><h3>Pit Form Builder</h3><p>Configure the game-specific capability questions scouts answer at each robot.</p><div class="neptune-hub-meta"><span>Questions</span><span>Capabilities</span><span>Photos</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <a class="neptune-hub-card accent-vulcan" href="<?=e(base_url('admin/pre-scout-builder.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-binoculars"></i></span><span class="neptune-hub-badge access-admin"><i class="fa-solid fa-user-shield"></i> Admin+</span></div><span class="neptune-hub-card-kicker">Season research</span><h3>Pre-Scout Form Builder</h3><p>Configure the research fields used before the event and carried into pit scouting.</p><div class="neptune-hub-meta"><span>Research</span><span>Season Data</span><span>Templates</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <a class="neptune-hub-card accent-vulcan" href="<?=e(base_url('admin/tag-manager.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-tags"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">TRIDENT · Observation scouting</span><h3>Tag Editor</h3><p>Manage Match, Pit, and Team tags, icons, descriptions, visibility, and order.</p><div class="neptune-hub-meta"><span>Match</span><span>Pit</span><span>Team</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
    </div>
  </section>
  <?php endif;?>

  <section class="neptune-hub-section" id="organization">
    <div class="neptune-hub-section-head"><div><span class="neptune-hub-section-kicker" style="color:var(--module-org)">Administration</span><h2>Organization</h2><p>Manage who uses Neptune and how scouting data is shared.</p></div></div>
    <div class="neptune-hub-grid">
      <a class="neptune-hub-card accent-org" href="<?=e(base_url('admin/teams.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-users-gear"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">People and access</span><h3>Teams &amp; Users</h3><p>Manage users below your access level and assign roles up to your own level. Admin+ can also add FRC teams.</p><div class="neptune-hub-meta"><span>Accounts</span><span>Roles</span><span>Teams</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <?php if($isAdmin):?><a class="neptune-hub-card accent-org" href="<?=e(base_url('admin/styling.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-palette"></i></span><span class="neptune-hub-badge access-admin"><i class="fa-solid fa-user-shield"></i> Admin+</span></div><span class="neptune-hub-card-kicker">Organization appearance</span><h3>Interface Styling</h3><p>Create, edit, and select reusable themes for your organization.</p><div class="neptune-hub-meta"><span>Themes</span><span>Palette</span><span>Preview</span><span>Organization</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a><?php endif;?>
      <a class="neptune-hub-card accent-org" href="<?=e(base_url('admin/sharing.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-share-nodes"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-shield-halved"></i> Strategy+</span></div><span class="neptune-hub-card-kicker">Partner access</span><h3>Data Sharing</h3><p>Control which partner teams can access match, pit, notes, raw actions, and analytics data.</p><div class="neptune-hub-meta"><span>Partners</span><span>Permissions</span><span>Shared Data</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
    </div>
  </section>

  <section class="neptune-hub-section" id="community-scouting">
    <div class="neptune-hub-section-head"><div><span class="neptune-hub-section-kicker" style="color:var(--module-trident)">Shared robot evidence</span><h2>Community Scouting</h2><p>Watch and tag matches, connect training computers, and use evaluated models.</p></div></div>
    <div class="neptune-hub-grid">
      <a class="neptune-hub-card accent-trident" href="<?=e(base_url('community/?view=catalog'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-tags"></i></span><span class="neptune-hub-badge">Public</span></div><span class="neptune-hub-card-kicker">Contribute observations</span><h3>Watch &amp; Tag</h3><p>Choose a match, see prior contributions for each robot, and add human tags or scoring estimates.</p><div class="neptune-hub-meta"><span>Match history</span><span>19 tags</span><span>Scoring</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a>
      <?php if($isPlatformOwner):?><a class="neptune-hub-card accent-system" href="<?=e(base_url('autoscout/train.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-brain"></i></span><span class="neptune-hub-badge access-owner">Platform owner</span></div><span class="neptune-hub-card-kicker">Train and evaluate</span><h3>Train &amp; Test</h3><p>Download the Mac, Windows or Linux trainer, connect your computer, start training and check the result.</p><div class="neptune-hub-meta"><span>Training computers</span><span>Models</span><span>Reports</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a><?php endif;?>
    </div>
  </section>
  <?php if($isAdmin):?>
  <section class="neptune-hub-section" id="system">
    <div class="neptune-hub-section-head"><div><span class="neptune-hub-section-kicker" style="color:var(--module-system)">System</span><h2>Maintenance</h2><p>Manage updates, view server health, and maintain platform operations.</p></div></div>
    <div class="neptune-hub-grid">

      <?php if($isPlatformOwner):?><a class="neptune-hub-card accent-system" href="<?=e(base_url('admin/platform-administration.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-building-shield"></i></span><span class="neptune-hub-badge access-owner"><i class="fa-solid fa-lock"></i> Platform owner</span></div><span class="neptune-hub-card-kicker">Tenant operations</span><h3>Platform Administration</h3><p>Review every organization, owners, usage, activity and status; suspend access, recover owners, inspect details, and export tenant data.</p><div class="neptune-hub-meta"><span>Organizations</span><span>Owners</span><span>Status</span><span>Export</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a><?php endif;?>
      <?php if($isPlatformOwner):?><a class="neptune-hub-card accent-system" href="<?=e(base_url('admin/file-manager.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-folder-tree"></i></span><span class="neptune-hub-badge access-owner"><i class="fa-solid fa-lock"></i> Platform owner</span></div><span class="neptune-hub-card-kicker">Application files</span><h3>File Manager</h3><p>Browse, upload, edit, rename, move, download, and maintain Neptune files from the browser.</p><div class="neptune-hub-meta"><span>Browse</span><span>Edit</span><span>Upload</span><span>Backups</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a><?php else:?><div class="neptune-hub-card accent-system" aria-disabled="true" style="opacity:.68;cursor:not-allowed"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-folder-tree"></i></span><span class="neptune-hub-badge access-owner"><i class="fa-solid fa-lock"></i> Platform owner</span></div><span class="neptune-hub-card-kicker">Application files</span><h3>File Manager</h3><p>Browse, upload, edit, rename, move, download, and maintain Neptune files from the browser.</p><div class="neptune-hub-meta"><span>Browse</span><span>Edit</span><span>Upload</span><span>Backups</span></div><span class="neptune-hub-arrow"><i class="fa-solid fa-lock"></i></span></div><?php endif;?>
      <?php if($isPlatformOwner):?><a class="neptune-hub-card accent-system" href="<?=e(base_url('admin/augur-operations.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-gears"></i></span><span class="neptune-hub-badge access-owner"><i class="fa-solid fa-lock"></i> Platform owner</span></div><span class="neptune-hub-card-kicker">AUGUR jobs &amp; model upkeep</span><h3>AUGUR Operations</h3><p>Manage AUGUR scheduled jobs, EPA archive work, rebuilds, and phase-map maintenance from one operations page.</p><div class="neptune-hub-meta"><span>Cron</span><span>EPA Archive</span><span>Rebuilds</span><span>Phase Maps</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a><?php else:?><div class="neptune-hub-card accent-system" aria-disabled="true" style="opacity:.68;cursor:not-allowed"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-gears"></i></span><span class="neptune-hub-badge access-owner"><i class="fa-solid fa-lock"></i> Platform owner</span></div><span class="neptune-hub-card-kicker">AUGUR jobs &amp; model upkeep</span><h3>AUGUR Operations</h3><p>Manage AUGUR scheduled jobs, EPA archive work, rebuilds, and phase-map maintenance from one operations page.</p><div class="neptune-hub-meta"><span>Cron</span><span>EPA Archive</span><span>Rebuilds</span><span>Phase Maps</span></div><span class="neptune-hub-arrow"><i class="fa-solid fa-lock"></i></span></div><?php endif;?>
      <?php if($isPlatformOwner):?><a class="neptune-hub-card accent-system" href="<?=e(base_url('admin/maintenance.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-terminal"></i></span><span class="neptune-hub-badge access-owner"><i class="fa-solid fa-lock"></i> Platform owner</span></div><span class="neptune-hub-card-kicker">Server maintenance</span><h3>Maintenance Console</h3><p>Check server health, inspect and install Neptune patch ZIPs, roll back updates, and run restricted diagnostics.</p><div class="neptune-hub-meta"><span>Status</span><span>Patches</span><span>Rollback</span><span>Diagnostics</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow"></i></a><?php else:?><div class="neptune-hub-card accent-system" aria-disabled="true" style="opacity:.68;cursor:not-allowed"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-terminal"></i></span><span class="neptune-hub-badge access-owner"><i class="fa-solid fa-lock"></i> Platform owner</span></div><span class="neptune-hub-card-kicker">Server maintenance</span><h3>Maintenance Console</h3><p>Check server health, inspect and install Neptune patch ZIPs, roll back updates, and run restricted diagnostics.</p><div class="neptune-hub-meta"><span>Status</span><span>Patches</span><span>Rollback</span><span>Diagnostics</span></div><span class="neptune-hub-arrow"><i class="fa-solid fa-lock"></i></span></div><?php endif;?>
    </div>
  </section>
  <?php endif;?>
</section>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
