<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=current_user();if($u)$u=require_login();
header('Cache-Control: no-store');header('Referrer-Policy: same-origin');
$pageTitle='Explore the Deep';$moduleName='NEPTUNE';$bodyClass='neptune-display-page discover-neptune-page has-ocean';
include dirname(__DIR__).'/partials_header.php';
$chapters=[
 ['scout','fa-clipboard-check','Scout','TRIDENT · scouting that keeps up with the match',[
  ['fa-stopwatch','Live match scouting','Each scouter follows an assigned robot and records every success and miss as it happens.'],
  ['fa-tags','Match Tag Scouting','Several observers add quick tags per robot, building the qualitative picture numbers miss.'],
  ['fa-screwdriver-wrench','Pit Scouting','Custom pit forms, robot photos, autonomous paths and team contacts in one record.'],
  ['fa-binoculars','Pre-Scouting','Research teams before you arrive, with public EPA and history at your side.'],
  ['fa-mobile-screen','Any phone or tablet','Built for the stands: big targets, fast entry, works on the devices your team already has.'],
  ['fa-shield-heart','Interruption-proof','Dropped Wi-Fi or a closed tab does not lose data; entries are kept and sent when the connection returns.'],
 ]],
 ['analyze','fa-chart-line','Analyze','AUGUR · turn observations into answers',[
  ['fa-robot','Robot Intelligence','One card per robot combining your scouting, tags, photos and official results.'],
  ['fa-scale-balanced','Side-by-side comparison','Compare robots on the measures that matter for this season’s game.'],
  ['fa-water','SA-EPA ratings','Scouting-augmented Expected Points Added, plus public EPA pages and season archives.'],
  ['fa-shield-halved','Defense analysis','SV-Points Denied shows which robots actually take points away from opponents.'],
  ['fa-bullseye','Predictions you can check','Match predictions with a public track record of how often they were right.'],
  ['fa-magnifying-glass-chart','Data Quality Center','Spot missing coverage and questionable entries before they mislead your strategy.'],
 ]],
 ['strategize','fa-chess-knight','Strategize','Plan every match, then build the alliance',[
  ['fa-chess-board','Match Strategy','Pre-match briefing: both alliances, predicted scores, partner roles and defense priorities.'],
  ['fa-people-arrows','Drive-team plans','Turn the evidence into a clear plan your drive team can use in the queue.'],
  ['fa-ranking-star','Robot Rankings','Scouts rank each alliance after a match; Neptune builds Driving and Defense ratings.'],
  ['fa-list-ol','Pick lists','Keep and reorder pick lists as the event unfolds.'],
  ['fa-handshake','Alliance Selection','Compare alliance combinations live during selection.'],
  ['fa-clock-rotate-left','Event history','Review statistics and activity after the event and carry lessons to the next one.'],
 ]],
 ['operate','fa-tower-broadcast','Run the event','SATURN · command your scouting operation',[
  ['fa-satellite-dish','Command Center','One place to run the event, watch progress and reach every tool.'],
  ['fa-play','Match Control','Set each match ready, map R1–R3 and B1–B3, start and end runs with one click.'],
  ['fa-signal','Live Monitor','See which robots are covered and which scouts are connected, in real time.'],
  ['fa-calendar-days','Rosters and schedules','Load teams and matches from The Blue Alliance or FIRST, or enter them by hand.'],
  ['fa-server','Portable offline server','Run Neptune on your own laptop and router at events with no internet, then sync later.'],
  ['fa-qrcode','QR hand-off','If the network fails, scouts hand off data by QR code so nothing is lost.'],
 ]],
 ['build','fa-hammer','Make it yours','VULCAN · configured for your team and any season',[
  ['fa-gears','Game Builder','Model each season’s game and publish revisions without code changes.'],
  ['fa-file-pen','Form builders','Design your own Pit and Pre-Scout questions.'],
  ['fa-tag','Tag Editor','Choose the tag categories your observers use.'],
  ['fa-user-shield','Roles and accounts','Owner, admin, strategy and scouter roles with secure sign-in.'],
  ['fa-building-user','Multi-organization','Each organization gets its own private workspace and data.'],
  ['fa-graduation-cap','Training and QA','Built-in practice, training material and a QA center to get new scouts ready.'],
 ]],
];
$steps=['In the pits','Every match','Strategy','Alliance selection'];
?>
<link rel="stylesheet" href="<?=e(base_url('assets/css/neptune-ocean.css?v=1'))?>">
<link rel="stylesheet" href="<?=e(base_url('assets/css/event-hub.css?v=6'))?>">
<section class="deep" aria-labelledby="deep-title">
  <div class="deep-hero">
    <div class="deep-story">
      <span class="ocean-pill ocean-pill--kicker"><i class="fa-solid fa-water" aria-hidden="true"></i> Neptune · FRC scouting &amp; strategy</span>
      <h1 id="deep-title">Explore <span>the deep.</span></h1>
      <p class="deep-lede">Every robot, every match, every decision. Neptune brings your team’s scouting and the official results together, so you walk into each match knowing more than anyone else in the queue.</p>
      <div class="deep-pills">
        <span class="ocean-pill"><i class="fa-solid fa-users" aria-hidden="true"></i> Multi-team</span>
        <span class="ocean-pill"><i class="fa-solid fa-wifi" aria-hidden="true"></i> Works offline</span>
        <span class="ocean-pill"><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i> Phone &amp; tablet friendly</span>
        <span class="ocean-pill"><i class="fa-solid fa-code-branch" aria-hidden="true"></i> Self-hostable</span>
        <span class="ocean-pill"><i class="fa-solid fa-database" aria-hidden="true"></i> Your scouting data</span>
      </div>
    </div>
    <aside class="deep-join ocean-glass" aria-label="Scan to visit Neptune">
      <span class="ocean-pill ocean-pill--kicker">Scan to dive in</span>
      <div class="display-qr-frame"><canvas id="neptuneJoinQr" role="img" aria-label="Scan to open neptunefrc.com" data-url="<?=e(base_url(''))?>"></canvas></div>
      <a class="deep-site" href="<?=e(base_url(''))?>">NEPTUNEFRC.COM</a>
      <p>Set up your team in minutes.</p>
    </aside>
  </div>
  <div class="deep-chapters" data-deep-chapters>
    <div class="deep-tabs" role="tablist" aria-label="What Neptune does">
      <?php foreach($chapters as $n=>[$id,$icon,$name]):?>
      <button type="button" role="tab" class="deep-tab" id="deep-tab-<?=$id?>" aria-controls="deep-panel-<?=$id?>" aria-selected="<?=$n===0?'true':'false'?>"><i class="fa-solid <?=$icon?>" aria-hidden="true"></i> <?=$name?><span class="deep-tab-progress" aria-hidden="true"></span></button>
      <?php endforeach ?>
    </div>
    <?php foreach($chapters as [$id,$icon,$name,$tagline,$items]):?>
    <section class="deep-panel" role="tabpanel" id="deep-panel-<?=$id?>" aria-labelledby="deep-tab-<?=$id?>">
      <h2 class="deep-panel-title"><i class="fa-solid <?=$icon?>" aria-hidden="true"></i> <?=$tagline?></h2>
      <div class="deep-features">
        <?php foreach($items as [$ficon,$title,$text]):?>
        <article class="deep-feature ocean-glass"><span class="deep-icon"><i class="fa-solid <?=$ficon?>" aria-hidden="true"></i></span><div><h3><?=$title?></h3><p><?=$text?></p></div></article>
        <?php endforeach ?>
      </div>
    </section>
    <?php endforeach ?>
  </div>
  <div class="deep-bottom"><ol class="deep-steps" aria-label="Neptune through an event"><?php foreach($steps as $k=>$step):?><li><span><?=$k+1?></span><?=$step?></li><?php endforeach ?></ol><a href="<?=e(base_url($u?'dashboard/index.php':'index.php'))?>"><?=$u?'Open Neptune':'Already on Neptune? Sign in'?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></div>
</section>
<script src="<?=e(base_url('assets/js/neptune-ocean.js?v=1'))?>" defer></script>
<script src="<?=e(base_url('assets/js/event-deep.js?v=1'))?>" defer></script>
<script src="<?=e(base_url('assets/js/qrcodegen.js'))?>" defer></script>
<script src="<?=e(base_url('assets/js/event-wifi.js?v=4'))?>" defer></script>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
