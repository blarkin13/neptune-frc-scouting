<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$pageTitle='Analytics & Strategy';
$moduleName='AUGUR';
$canDatabaseLab=in_array(($u['role']??''),['strategy','admin','owner'],true);
include dirname(__DIR__).'/partials_header.php';
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
<section class="module-page neptune-hub">
  <header class="module-page-header neptune-hub-header nd-mission-header" style="--nd-accent:var(--accent,#47c6e5)">
    <div class="nd-mission-content">
      <span class="nd-mission-kicker">AUGUR / ROBOT INTELLIGENCE</span>
      <h1>Analytics &amp; Strategy</h1>
      <p><strong style="display:block;color:var(--text,#eff8ff);font-size:1.12em;margin-bottom:8px">Turn observations into an advantage.</strong>Explore robot performance, compare teams, investigate scouting data, and turn the evidence into confident match and alliance decisions.</p>
    </div>
    <i class="fa-solid fa-chart-line nd-mission-mark" aria-hidden="true"></i>
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

    <?php if($canDatabaseLab):?>
    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/data-quality.php'))?>">
      <div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-clipboard-check"></i></span><span class="neptune-hub-badge access-strategy"><i class="fa-solid fa-chart-line"></i> Strategy+</span></div>
      <span class="neptune-hub-card-kicker">Review scouting reliability</span>
      <h3>Scouting Data Quality Center</h3>
      <p>Find incomplete sessions, repeated submissions, scoring outliers, and low-confidence teams. Review and audit findings without altering scouting records.</p>
      <div class="neptune-hub-meta"><span>Coverage</span><span>Duplicates</span><span>Outliers</span><span>Reviews</span></div>
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
    <a class="neptune-hub-card accent-augur" href="<?=e(base_url('analytics/stats/'))?>" style="border-color:color-mix(in srgb,var(--accent,#18b8ff) 55%,var(--border,#26344b));background:linear-gradient(135deg,color-mix(in srgb,var(--accent,#18b8ff) 13%,var(--card,#152435)),var(--card,#152435));">
      <div class="neptune-hub-card-top">
        <span class="neptune-hub-icon"><i class="fa-solid fa-globe"></i></span>
        <span class="neptune-hub-badge access-all"><i class="fa-solid fa-arrow-up-right-from-square"></i> Public site</span>
      </div>
      <span class="neptune-hub-card-kicker">Explore FRC statistics without signing in</span>
      <h3>Public FRC Statistics</h3>
      <p>Explore EPA leaderboards, team profiles, season history and event analytics in Neptune's public statistics center.</p>
      <div class="neptune-hub-meta"><span>EPA Rankings</span><span>Team Profiles</span><span>Events</span><span>Developer API</span></div>
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
