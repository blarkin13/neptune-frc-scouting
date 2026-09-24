<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();$org=(int)$u['organization_id'];

// Public Neptune GitHub repository.
$githubRepoUrl = 'https://github.com/blarkin13/neptune-frc-scouting';

$s=$pdo->prepare("SELECT e.*,g.name game_name,
 (SELECT COUNT(*) FROM event_teams et WHERE et.event_id=e.id) team_count,
 (SELECT COUNT(*) FROM matches m WHERE m.event_id=e.id) match_count,
 (SELECT COUNT(*) FROM pre_scouting prs WHERE prs.event_id=e.id AND prs.organization_id=e.organization_id AND prs.status='complete') pre_complete,
 (SELECT COUNT(*) FROM pit_scouting ps WHERE ps.event_id=e.id AND ps.organization_id=e.organization_id AND ps.status='complete') pit_complete
 FROM events e JOIN games g ON g.id=e.game_id
 WHERE e.organization_id=? AND e.active=1
 ORDER BY e.is_current DESC,
 CASE e.event_status WHEN 'running' THEN 0 WHEN 'schedule_ready' THEN 1 WHEN 'pit_open' THEN 2 WHEN 'planned' THEN 3 ELSE 4 END,
 CASE WHEN CURDATE() BETWEEN e.start_date AND e.end_date THEN 0 WHEN e.start_date>=CURDATE() THEN 1 ELSE 2 END,
 ABS(DATEDIFF(COALESCE(e.start_date,CURDATE()),CURDATE())),e.id DESC LIMIT 1");
$s->execute([$org]);$event=$s->fetch()?:null;
$nextMatch=null;
if($event&&((int)$event['match_count'])>0){
    $s=$pdo->prepare("SELECT m.* FROM matches m WHERE m.organization_id=? AND m.event_id=? AND m.state IN ('running','paused','ready','scheduled') ORDER BY FIELD(m.state,'running','paused','ready','scheduled'),FIELD(m.comp_level,'qm','ef','qf','sf','f'),m.set_number,m.match_number LIMIT 1");
    $s->execute([$org,$event['id']]);$nextMatch=$s->fetch()?:null;
}
$pitPct=$event&&(int)$event['team_count']?round((int)$event['pit_complete']*100/(int)$event['team_count']):0;
$pageTitle='Home';$moduleName='NEPTUNE';include dirname(__DIR__).'/partials_header.php';
?>
<section class="card home-hero">
  <div><img class="home-hero-logo" src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><h1>Welcome to Neptune</h1><p class="muted">FRC Scouting &amp; Strategy Platform</p><p class="home-hero-detail">One platform connecting scouting, command, intelligence, live synchronization, configuration, and data.</p></div>
  <div class="status-panel">
    <?php if($event):?>
      <div class="muted"><?=$event['is_current']?'Current event':'Current / upcoming event'?></div><h2><?=e($event['name'])?></h2><div class="muted"><?=e($event['game_name'])?> · <?=e(str_replace('_',' ',strtoupper($event['event_status'])))?></div>
      <div class="home-event-metrics"><div><b><?=e($event['team_count'])?></b><span>Teams</span></div><div><b><?=e($event['pre_complete'])?></b><span>Pre-scout</span></div><div><b><?=e($event['pit_complete'])?></b><span>Pit complete</span></div><div><b><?=e($event['match_count'])?></b><span>Matches</span></div></div>
      <?php if((int)$event['team_count']>0):?><div class="progress-track"><span style="width:<?=$pitPct?>%"></span></div><?php endif;?>
      <?php if($nextMatch):?><div style="margin-top:16px" class="muted">Match status</div><div class="match-big"><?=e(neptune_match_label($nextMatch))?></div><span class="pill"><?=e(strtoupper($nextMatch['state']))?></span>
      <?php elseif((int)$event['match_count']===0):?><div class="notice" style="margin-top:14px"><i class="fa-solid fa-clipboard-list"></i> Pit scouting is available now. Match schedule has not been loaded yet.</div>
      <?php else:?><div class="notice" style="margin-top:14px">No upcoming matches remain in the loaded schedule.</div><?php endif;?>
    <?php else:?><h2>No current event</h2><p class="muted">Command can create an event before the schedule exists, add/import its roster, and open pit scouting immediately.</p><?php endif;?>
  </div>
</section>

<section class="platform-modules" aria-labelledby="moduleMapTitle">
  <div class="home-links-heading module-map-heading">
    <div class="section-kicker"><i class="fa-solid fa-diagram-project"></i> NEPTUNE MODULES</div>
    <h2 id="moduleMapTitle">One platform. Six focused systems.</h2>
  </div>
  <div class="module-grid">
    <a class="module-card" href="<?=e(base_url('scouting.php'))?>"><span class="module-card-access"><i class="fa-solid fa-user-group"></i> All users</span><span class="module-card-icon"><i class="fa-solid fa-crosshairs"></i></span><span><b>SCOUTING</b><small>TRIDENT</small><em>Match · Pit · Pre-Scouting</em></span></a>
    <a class="module-card" href="<?=e(base_url('admin/index.php'))?>"><span class="module-card-access"><i class="fa-solid fa-user-group"></i> All users</span><span class="module-card-icon"><i class="fa-solid fa-tower-broadcast"></i></span><span><b>COMMAND CENTER</b><small>SATURN</small><em>Event Operations · Organization · Role-gated tools</em></span></a>
    <a class="module-card" href="<?=e(base_url('analytics/index.php'))?>"><span class="module-card-access"><i class="fa-solid fa-user-group"></i> All users</span><span class="module-card-icon"><i class="fa-solid fa-chart-line"></i></span><span><b>ANALYTICS &amp; STRATEGY</b><small>AUGUR</small><em>Robot Intelligence · Match Board · Match Strategy</em></span></a>
    <a class="module-card" href="<?=e(base_url('admin/index.php#mercury'))?>"><span class="module-card-access"><i class="fa-solid fa-lock"></i> Role-gated</span><span class="module-card-icon"><i class="fa-solid fa-screwdriver-wrench"></i></span><span><b>SYSTEM MAINTENANCE</b><small>MERCURY</small><em>Health · Styling · Files · Updates · Diagnostics</em></span></a>
    <a class="module-card" href="<?=e(base_url('admin/index.php#vulcan'))?>"><span class="module-card-access"><i class="fa-solid fa-user-shield"></i> Admin+</span><span class="module-card-icon"><i class="fa-solid fa-hammer"></i></span><span><b>BUILDERS &amp; CONFIGURATION</b><small>VULCAN</small><em>Game Builder · Pit Form · Pre-Scout Form</em></span></a>
    <a class="module-card" href="<?=e(base_url('dashboard/data.php'))?>"><span class="module-card-access"><i class="fa-solid fa-user-group"></i> All users</span><span class="module-card-icon"><i class="fa-solid fa-database"></i></span><span><b>DATA LAYER</b><small>SALT</small><em>Raw Data · Strategy+ Database Lab</em></span></a>
  </div>
</section>

<section class="dashboard-intro-grid" aria-label="About and setup">
  <article class="card platform-about">
    <div class="section-kicker"><i class="fa-solid fa-water"></i> ABOUT THE PLATFORM</div>
    <h2>Built as a connected FRC operating system</h2>
    <p>Neptune is the platform. TRIDENT handles scouting, SATURN runs event operations, AUGUR turns observations into strategy, MERCURY maintains the platform, VULCAN builds the season configuration, and SALT stores the data. The modules share one organization, event, game, and robot intelligence model.</p>
    <div class="platform-feature-row">
      <span><i class="fa-solid fa-cloud-arrow-down"></i> TBA event data</span>
      <span><i class="fa-solid fa-crosshairs"></i> TRIDENT scouting</span>
      <span><i class="fa-solid fa-tower-broadcast"></i> SATURN command</span>
      <span><i class="fa-solid fa-chart-line"></i> AUGUR intelligence</span>
      <span><i class="fa-solid fa-screwdriver-wrench"></i> MERCURY maintenance</span>
      <span><i class="fa-solid fa-hammer"></i> VULCAN configuration</span>
      <span><i class="fa-solid fa-database"></i> SALT data</span>
    </div>
    <button class="btn" type="button" id="openHowToNeptune"><i class="fa-solid fa-circle-play"></i> How to Use Neptune</button>
  </article>

  <article class="card platform-setup">
    <div class="section-kicker"><i class="fa-solid fa-code"></i> RUN NEPTUNE YOURSELF</div>
    <h2>Hosted or local</h2>
    <p class="muted">Neptune is designed to run on a normal PHP/MySQL server or on a local development machine. The application stays under <code>public_html/Neptune</code> while credentials and API keys stay outside the web root in <code>neptune_secure</code>.</p>
    <div class="setup-methods">
      <div>
        <i class="fa-solid fa-server setup-method-icon"></i>
        <div><b>Hosted</b><span>Upload Neptune to your web server, create the MySQL database, import <code>sql/neptune_schema.sql</code>, configure <code>neptune_secure/config.php</code>, and add your TBA API key.</span></div>
      </div>
      <div>
        <i class="fa-solid fa-laptop-code setup-method-icon"></i>
        <div><b>Local</b><span>Use PHP 8+, MySQL/MariaDB, and a local web server. Keep the same public/private folder structure so local testing matches production.</span></div>
      </div>
    </div>
    <div class="toolbar setup-links">
      <button class="btn secondary" type="button" id="openSetupNeptune"><i class="fa-solid fa-book"></i> Setup Instructions</button>
      <?php if($githubRepoUrl!==''):?>
        <a class="btn secondary" href="<?=e($githubRepoUrl)?>" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> GitHub Repository</a>
      <?php else:?>
        <span class="btn secondary disabled" title="Set $githubRepoUrl in dashboard/index.php when the repository is ready"><i class="fa-brands fa-github"></i> GitHub Repository — coming soon</span>
      <?php endif;?>
    </div>
  </article>
</section>

<div class="home-links-heading">
  <div class="section-kicker"><i class="fa-solid fa-table-cells-large"></i> APPLICATIONS</div>
  <h2>Choose where you want to work</h2>
</div>
<div class="quick-grid">
  <a class="quick-link" href="<?=e(base_url('scouting.php'))?>"><span class="quick-icon"><i class="fa-solid fa-crosshairs"></i></span><span><b>Scouting</b><span class="muted">TRIDENT · Choose Match Scouting, Pit Scouting, or Pre-Scouting.</span></span></a>
  <a class="quick-link" href="<?=e(base_url('analytics/index.php'))?>"><span class="quick-icon"><i class="fa-solid fa-chart-line"></i></span><span><b>Analytics &amp; Strategy</b><span class="muted">AUGUR · Open Robot Intelligence or the Match Board.</span></span></a>
  <a class="quick-link" href="<?=e(base_url('admin/index.php'))?>"><span class="quick-icon"><i class="fa-solid fa-tower-broadcast"></i></span><span><b>Command Center</b><span class="muted">SATURN · Browse all operations, builders, organization tools, and MERCURY maintenance. Access remains role-gated.</span></span></a>
</div>

<dialog class="dashboard-info-dialog" id="howToNeptuneDialog" aria-labelledby="howToNeptuneTitle">
  <div class="dashboard-dialog-shell">
    <div class="dashboard-dialog-toolbar">
      <span>NEPTUNE GUIDE</span>
      <button class="btn secondary dashboard-dialog-close" type="button" data-close-dialog aria-label="Close guide"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="dashboard-dialog-body">
      <div class="dialog-title-icon"><i class="fa-solid fa-compass"></i></div>
      <h2 id="howToNeptuneTitle">How to Use Neptune</h2>
      <p class="muted">A typical competition moves through Neptune in this order.</p>
      <div class="howto-steps">
        <div><span>1</span><div><b>VULCAN · Build the game</b><p>Configure the season's match actions, button layout, scoring values, timing, and pit questions. Save the game definition for reuse at every event that season.</p></div></div>
        <div><span>2</span><div><b>SATURN · Open the event</b><p>Create the event before matches begin. Load the team roster manually or from The Blue Alliance and make it Neptune's Current Event.</p></div></div>
        <div><span>3</span><div><b>TRIDENT · Pre-scout teams</b><p>Before the event, contact teams from the roster. Neptune reuses known answers from earlier events in the same game and adds TBA team information.</p></div></div>
        <div><span>4</span><div><b>TRIDENT · Pit scout</b><p>At the event, scouts confirm robot capabilities, autonomous options, endgame abilities, reliability notes, and photos.</p></div></div>
        <div><span>5</span><div><b>SATURN · Sync the schedule</b><p>When FIRST publishes the schedule, refresh from The Blue Alliance. Neptune fills every match with the official Red and Blue field stations.</p></div></div>
        <div><span>6</span><div><b>SATURN + TRIDENT · Run live scouting</b><p>Command makes the next match ready and starts it. Agents select their assigned field station and record actions in real time while Neptune keeps the match timer synchronized.</p></div></div>
        <div><span>7</span><div><b>AUGUR · Use the intelligence</b><p>Review Robot Cards, the Pit Match Board, individual robot details, past match data, cycle times, success rates, defense, pit information, and shared scouting data.</p></div></div>
      </div>
      <div class="notice"><i class="fa-solid fa-rotate"></i> If an official match is restarted, use <b>Re-scout / Ready Again</b>. Neptune voids the superseded scouting run so only the new run contributes to analytics.</div>
    </div>
  </div>
</dialog>

<dialog class="dashboard-info-dialog" id="setupNeptuneDialog" aria-labelledby="setupNeptuneTitle">
  <div class="dashboard-dialog-shell">
    <div class="dashboard-dialog-toolbar">
      <span>INSTALLATION</span>
      <button class="btn secondary dashboard-dialog-close" type="button" data-close-dialog aria-label="Close setup instructions"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="dashboard-dialog-body">
      <div class="dialog-title-icon"><i class="fa-solid fa-screwdriver-wrench"></i></div>
      <h2 id="setupNeptuneTitle">Set Up Neptune</h2>
      <div class="setup-guide-grid">
        <section>
          <h3><i class="fa-solid fa-server"></i> Hosted server</h3>
          <ol>
            <li>Use a server with PHP 8+, MySQL/MariaDB, HTTPS, and a normal PHP web root.</li>
            <li>Place the public application in <code>public_html/Neptune/</code>.</li>
            <li>Place <code>neptune_secure/</code> beside <code>public_html</code>, never inside it.</li>
            <li>Create the Neptune database and import <code>sql/neptune_schema.sql</code>. New installations do not run separate migration scripts.</li>
            <li>Copy <code>config.example.php</code> to <code>config.php</code> and enter the database credentials and TBA API key.</li>
            <li>Open <code>/Neptune/admin/install.php</code> once to create the first organization/owner, then remove or rename the installer.</li>
          </ol>
        </section>
        <section>
          <h3><i class="fa-solid fa-laptop-code"></i> Local development</h3>
          <ol>
            <li>Install PHP 8+, MySQL/MariaDB, and Apache/Nginx, or use PHP's built-in development server.</li>
            <li>Keep <code>public_html/</code> and <code>neptune_secure/</code> as sibling folders just like production.</li>
            <li>Create a local Neptune database and import <code>sql/neptune_schema.sql</code>.</li>
            <li>Create a local <code>neptune_secure/config.php</code> with local database credentials.</li>
            <li>If using PHP's built-in server from the project root, run <code>php -S localhost:8080 -t public_html</code> and open <code>http://localhost:8080/Neptune/</code>.</li>
          </ol>
        </section>
      </div>
      <div class="notice"><i class="fa-brands fa-github"></i> Source code, installation files, and updates are available from the Neptune GitHub repository.</div>
    </div>
  </div>
</dialog>

<script>
(function(){
  const pairs=[
    [document.getElementById('openHowToNeptune'),document.getElementById('howToNeptuneDialog')],
    [document.getElementById('openSetupNeptune'),document.getElementById('setupNeptuneDialog')]
  ];
  pairs.forEach(([open,dialog])=>{
    if(!open||!dialog)return;
    open.addEventListener('click',()=>{if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','')});
    dialog.querySelectorAll('[data-close-dialog]').forEach(btn=>btn.addEventListener('click',()=>dialog.close()));
    dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close()});
  });
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
