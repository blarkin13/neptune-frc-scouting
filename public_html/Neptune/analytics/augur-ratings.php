<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_augur_epa.php';

$viewer=current_user();
$canManage=$viewer && in_array((string)($viewer['role']??''),['owner','admin','strategy'],true);
$ready=augur_epa_tables_ready($pdo);

function ar_table_exists(PDO $pdo,string $table): bool {
    static $cache=[];
    if(array_key_exists($table,$cache)) return $cache[$table];
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->execute([$table]);
    return $cache[$table]=((int)$s->fetchColumn()>0);
}
function ar_num($v,int $d=1): string {
    return $v===null||$v===''?'—':number_format((float)$v,$d);
}
function ar_abs_url(string $path): string {
    $base='https://neptune.mckinneysteamacademy.org';
    if($path==='') return $base.'/';
    if(preg_match('~^https?://~i',$path)) return $path;
    return $base.'/'.ltrim($path,'/');
}

$years=[];
if($ready){
    $years=array_map('intval',$pdo->query("SELECT DISTINCT season_year FROM augur_epa_archive_events ORDER BY season_year DESC")->fetchAll(PDO::FETCH_COLUMN));
}
$year=(int)($_GET['year']??($years[0]??date('Y')));
$eventKey=trim((string)($_GET['event']??''));

$events=[];
if($ready&&$year){
    $s=$pdo->prepare("SELECT e.*,
        (SELECT COUNT(*) FROM augur_epa_event_ratings r WHERE r.tba_event_key=e.tba_event_key) rating_count
        FROM augur_epa_archive_events e
        WHERE e.season_year=?
        ORDER BY COALESCE(e.start_date,e.end_date),e.name");
    $s->execute([$year]);
    $events=$s->fetchAll();
    if($eventKey!==''&&!in_array($eventKey,array_map(static fn($e)=>(string)$e['tba_event_key'],$events),true)){
        $eventKey='';
    }
}

$rows=[];$viewEvent=null;
if($ready){
    if($eventKey!==''){
        $s=$pdo->prepare("SELECT r.*,e.name event_name,e.start_date,e.end_date
            FROM augur_epa_event_ratings r
            JOIN augur_epa_archive_events e ON e.tba_event_key=r.tba_event_key
            WHERE r.tba_event_key=?
            ORDER BY r.rating DESC,r.frc_team_number");
        $s->execute([$eventKey]);
        $rows=$s->fetchAll();

        $s=$pdo->prepare('SELECT * FROM augur_epa_archive_events WHERE tba_event_key=? LIMIT 1');
        $s->execute([$eventKey]);
        $viewEvent=$s->fetch()?:null;
    }elseif($year){
        $s=$pdo->prepare("SELECT * FROM augur_epa_season_ratings WHERE season_year=? ORDER BY rating DESC,frc_team_number");
        $s->execute([$year]);
        $rows=$s->fetchAll();
    }
}

/*
|--------------------------------------------------------------------------
| Team directory metadata
|--------------------------------------------------------------------------
| Public ratings never fetch TBA during a page request. Team names and
| locations are read from Neptune's locally cached public team directory.
| Existing Neptune event rosters are used as a fallback where possible.
*/
$teamMeta=[];
$directoryReady=ar_table_exists($pdo,'augur_epa_team_directory');
if($year>0){
    if($directoryReady){
        $s=$pdo->prepare("SELECT frc_team_number,nickname,name,city,state_prov,country
            FROM augur_epa_team_directory
            WHERE season_year=?");
        $s->execute([$year]);
        foreach($s->fetchAll() as $m){
            $teamMeta[(int)$m['frc_team_number']]=$m;
        }
    }

    // Local roster fallback without any external request.
    $s=$pdo->prepare("SELECT et.frc_team_number,
            MAX(NULLIF(et.nickname,'')) nickname,
            MAX(NULLIF(et.nickname,'')) name,
            MAX(NULLIF(et.city,'')) city,
            MAX(NULLIF(et.state_prov,'')) state_prov,
            MAX(NULLIF(et.country,'')) country
        FROM event_teams et
        JOIN events e ON e.id=et.event_id
        JOIN games g ON g.id=e.game_id
        WHERE g.season_year=?
        GROUP BY et.frc_team_number");
    $s->execute([$year]);
    foreach($s->fetchAll() as $m){
        $n=(int)$m['frc_team_number'];
        if(!isset($teamMeta[$n])) $teamMeta[$n]=$m;
        else{
            foreach(['nickname','city','state_prov','country'] as $k){
                if(empty($teamMeta[$n][$k])&&!empty($m[$k])) $teamMeta[$n][$k]=$m[$k];
            }
        }
    }
}

$api=base_url('api/augur-ratings.php');
$publicHome=base_url('index.php');
$canonicalPath='analytics/augur-ratings.php?year='.rawurlencode((string)$year);
if($eventKey!=='') $canonicalPath.='&event='.rawurlencode($eventKey);
$canonical=ar_abs_url($canonicalPath);

if($viewEvent){
    $seoTitle=$viewEvent['name'].' '.$year.' FRC Public EPA Ratings | Neptune AUGUR';
    $seoDescription='FRC EPA ratings for '.$viewEvent['name'].' ('.$year.'): overall, autonomous, teleop and endgame EPA calculated by Neptune AUGUR from archived public The Blue Alliance match data.';
}else{
    $seoTitle=$year.' FRC Public EPA Ratings — Auto, Teleop & Endgame | Neptune AUGUR';
    $seoDescription='Browse '.$year.' FIRST Robotics Competition EPA ratings from Neptune AUGUR, including overall, autonomous, teleop and endgame EPA calculated from archived public The Blue Alliance match results.';
}
$ogImage=ar_abs_url('images/neptune-og.png');

$lastUpdated='';
foreach($rows as $r){
    $u=(string)($r['updated_at']??'');
    if($u>$lastUpdated) $lastUpdated=$u;
}
$datasetName=$viewEvent
    ? $viewEvent['name'].' '.$year.' FRC Public EPA Ratings'
    : $year.' FRC Public EPA Ratings';

$structuredData=[
    '@context'=>'https://schema.org',
    '@graph'=>[
        [
            '@type'=>'WebPage',
            '@id'=>$canonical.'#webpage',
            'url'=>$canonical,
            'name'=>$seoTitle,
            'description'=>$seoDescription,
            'isPartOf'=>[
                '@type'=>'WebSite',
                'name'=>'Neptune FRC Scouting Platform',
                'url'=>ar_abs_url(''),
            ],
            'mainEntity'=>['@id'=>$canonical.'#dataset'],
        ],
        [
            '@type'=>'Dataset',
            '@id'=>$canonical.'#dataset',
            'name'=>$datasetName,
            'description'=>$seoDescription,
            'url'=>$canonical,
            'creator'=>[
                '@type'=>'Organization',
                'name'=>'Neptune',
                'url'=>ar_abs_url(''),
            ],
            'temporalCoverage'=>(string)$year,
            'isAccessibleForFree'=>true,
            'keywords'=>[
                'FIRST Robotics Competition',
                'FRC',
                'EPA',
                'Expected Points Added',
                'robotics scouting',
                'autonomous EPA',
                'teleop EPA',
                'endgame EPA',
            ],
            'variableMeasured'=>[
                'Overall EPA',
                'Autonomous EPA',
                'Teleop EPA',
                'Endgame EPA',
            ],
            'distribution'=>[
                [
                    '@type'=>'DataDownload',
                    'encodingFormat'=>'application/json',
                    'contentUrl'=>ar_abs_url('api/augur-ratings.php?year='.rawurlencode((string)$year)),
                ],
            ],
        ],
        [
            '@type'=>'BreadcrumbList',
            '@id'=>$canonical.'#breadcrumbs',
            'itemListElement'=>[
                [
                    '@type'=>'ListItem',
                    'position'=>1,
                    'name'=>'Neptune',
                    'item'=>ar_abs_url('index.php'),
                ],
                [
                    '@type'=>'ListItem',
                    'position'=>2,
                    'name'=>'Public EPA Ratings',
                    'item'=>$canonical,
                ],
            ],
        ],
    ],
];

if($viewer){
    $pageTitle='Public EPA Ratings';
    $moduleName='AUGUR';
    include dirname(__DIR__).'/partials_header.php';
}else{
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#05080b">
<title><?=e($seoTitle)?></title>
<meta name="description" content="<?=e($seoDescription)?>">
<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">
<link rel="canonical" href="<?=e($canonical)?>">
<link rel="alternate" type="application/json" title="Neptune Public EPA Ratings API" href="<?=e(ar_abs_url('api/augur-ratings.php?year='.rawurlencode((string)$year)))?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="Neptune">
<meta property="og:locale" content="en_US">
<meta property="og:title" content="<?=e($seoTitle)?>">
<meta property="og:description" content="<?=e($seoDescription)?>">
<meta property="og:url" content="<?=e($canonical)?>">
<meta property="og:image" content="<?=e($ogImage)?>">
<meta property="og:image:secure_url" content="<?=e($ogImage)?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Neptune AUGUR FRC Public EPA Ratings">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?=e($seoTitle)?>">
<meta name="twitter:description" content="<?=e($seoDescription)?>">
<meta name="twitter:image" content="<?=e($ogImage)?>">

<script type="application/ld+json"><?=json_encode(
    $structuredData,
    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT
)?></script>

<script>try{document.documentElement.dataset.theme=localStorage.getItem('neptune-theme')||'dark'}catch(e){}</script>
<link rel="icon" type="image/png" sizes="64x64" href="/images/favicon.png">
<link rel="apple-touch-icon" href="/images/app-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<?php $fontAwesomeCssVersion=@filemtime(dirname(__DIR__).'/assets/vendor/fontawesome/css/all.min.css')?:1; ?>
<link rel="stylesheet" href="<?=e(base_url('assets/vendor/fontawesome/css/all.min.css'))?>?v=<?=$fontAwesomeCssVersion?>">
<?php $fontAwesomeCompatVersion=@filemtime(dirname(__DIR__).'/assets/css/fontawesome-v7-compat.css')?:1; ?>
<link rel="stylesheet" href="<?=e(base_url('assets/css/fontawesome-v7-compat.css'))?>?v=<?=$fontAwesomeCompatVersion?>">
<link rel="stylesheet" href="<?=e(base_url('assets/css/app.css'))?>">
<link rel="stylesheet" href="<?=e(base_url('assets/css/neptune-ui.css'))?>">
</head>
<body>
<main class="ar-public-wrap">
<?php } ?>

<style>
.ar-public-wrap{max-width:1440px;margin:auto;padding:22px}
.ar-page{width:100%}
.ar-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:18px}
.ar-brand{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);font-weight:900}
.ar-head h1{font-size:clamp(2rem,4vw,3.25rem);margin:4px 0}
.ar-head p{max-width:850px;color:var(--muted);margin:0;line-height:1.55}
.ar-actions{display:flex;gap:8px;flex-wrap:wrap}
.ar-public-nav{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:0 0 18px;border-bottom:1px solid var(--line);margin-bottom:22px}
.ar-logo{display:inline-flex;align-items:center;gap:9px;color:var(--text);text-decoration:none;font-weight:900;font-size:1.05rem}
.ar-logo i{color:var(--accent)}
.ar-filter{display:grid;grid-template-columns:180px minmax(280px,1fr) auto;gap:10px;align-items:end}
.ar-table-wrap{overflow:visible;width:100%}
.ar-table{width:100%;border-collapse:collapse;table-layout:fixed;min-width:0}
.ar-table th,.ar-table td{padding:10px 7px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle;overflow-wrap:anywhere}
.ar-table th{font-size:clamp(.58rem,.62vw,.68rem);text-transform:uppercase;letter-spacing:.045em;color:var(--muted);background:var(--panel2);position:sticky;top:0;z-index:1;white-space:normal;line-height:1.15}
.ar-table td{font-size:clamp(.76rem,.78vw,.95rem);line-height:1.2}
.ar-table th:nth-child(1),.ar-table td:nth-child(1){width:5.5%}
.ar-table th:nth-child(2),.ar-table td:nth-child(2){width:6%}
.ar-table th:nth-child(3),.ar-table td:nth-child(3){width:14.5%}
.ar-table th:nth-child(4),.ar-table td:nth-child(4){width:10.5%}
.ar-table th:nth-child(5),.ar-table td:nth-child(5){width:10%}
.ar-table th:nth-child(6),.ar-table td:nth-child(6){width:5%}
.ar-table th:nth-child(7),.ar-table td:nth-child(7){width:4.5%}
.ar-table th:nth-child(8),.ar-table td:nth-child(8){width:5%}
.ar-table th:nth-child(9),.ar-table td:nth-child(9){width:6%}
.ar-table th:nth-child(10),.ar-table td:nth-child(10){width:6%}
.ar-table th:nth-child(11),.ar-table td:nth-child(11){width:7.5%}
.ar-table th:nth-child(12),.ar-table td:nth-child(12){width:6%}
.ar-table th:nth-child(13),.ar-table td:nth-child(13){width:8.5%}
.ar-rank{font-weight:900;color:var(--accent);white-space:nowrap}
.ar-team b{font-size:clamp(.82rem,.9vw,1rem);white-space:nowrap}
.ar-team-name{font-weight:750}
.ar-location{color:var(--muted)}
.ar-table td:nth-child(n+6){white-space:nowrap}
.ar-trend.up{color:var(--good)}.ar-trend.down{color:var(--bad)}
.ar-api{margin-top:18px;padding:16px}
.ar-api code{display:block;white-space:normal;word-break:break-all;margin-top:7px}
.ar-note{font-size:.75rem;color:var(--muted);line-height:1.5}
.ar-footer{text-align:center;color:var(--muted);font-size:.72rem;margin:24px 0}
.ar-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.ar-meta span{font-size:.7rem;padding:4px 7px;border:1px solid var(--line);border-radius:999px;color:var(--muted)}
.ar-api-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.ar-manager-note{margin-top:12px}
@media(max-width:1180px){
  .ar-table th,.ar-table td{padding:9px 5px}
  .ar-table th{font-size:.56rem;letter-spacing:.025em}
  .ar-table td{font-size:.72rem}
  .ar-table th:nth-child(3),.ar-table td:nth-child(3){width:13.5%}
  .ar-table th:nth-child(4),.ar-table td:nth-child(4){width:9.5%}
  .ar-table th:nth-child(5),.ar-table td:nth-child(5){width:9.5%}
  .ar-table th:nth-child(11),.ar-table td:nth-child(11){width:8.5%}
  .ar-table th:nth-child(13),.ar-table td:nth-child(13){width:9.5%}
}
@media(max-width:820px){
  .ar-public-wrap{padding:14px}
  .ar-head{display:block}
  .ar-actions{margin-top:12px}
  .ar-filter{grid-template-columns:1fr}
  .ar-filter button{width:100%}
  .ar-public-nav{align-items:flex-start}

  /* Keep every field on phones without horizontal scrolling. */
  .ar-table,.ar-table tbody,.ar-table tr,.ar-table td{display:block;width:100%}
  .ar-table thead{display:none}
  .ar-table tr{padding:10px 12px;border-bottom:1px solid var(--line)}
  .ar-table td{display:grid;grid-template-columns:110px minmax(0,1fr);gap:10px;padding:5px 0;border:0;font-size:.82rem;white-space:normal!important}
  .ar-table td::before{content:attr(data-label);font-size:.65rem;font-weight:900;letter-spacing:.05em;text-transform:uppercase;color:var(--muted)}
  .ar-table td:nth-child(n){width:100%}
}
</style>

<div class="ar-page">
<?php if(!$viewer):?>
<nav class="ar-public-nav" aria-label="Public navigation">
  <a class="ar-logo" href="<?=e($publicHome)?>" aria-label="Neptune home"><i class="fa-solid fa-water"></i> Neptune</a>
  <div class="ar-actions">
    <a class="btn secondary" href="<?=e($publicHome)?>"><i class="fa-solid fa-house"></i> Home</a>
    <a class="btn" href="<?=e($publicHome)?>"><i class="fa-solid fa-right-to-bracket"></i> Log In</a>
  </div>
</nav>
<?php endif;?>

<header class="ar-head">
  <div>
    <div class="ar-brand">Neptune · Public Ratings</div>
    <h1>Public EPA Ratings</h1>
    <p>Overall, autonomous, teleop, and endgame EPA calculated from public TBA match results and stored in Neptune's shared historical archive. Private scouting data is never exposed here.</p>
  </div>
  <div class="ar-actions">
    <a class="btn secondary" href="#developer-api"><i class="fa-solid fa-code"></i> Developer API</a>
    <?php if($canManage):?>
      <a class="btn secondary" href="<?=e(base_url('admin/augur-epa-archive.php'))?>"><i class="fa-solid fa-box-archive"></i> Archive Manager</a>
    <?php endif;?>
  </div>
</header>

<?php if(!$ready):?>
<div class="notice bad"><b>Public EPA Archive is not installed.</b> Import <code>sql/2026-09-21_augur-epa-archive-v3.sql</code>.</div>
<?php else:?>

<section class="card" style="padding:14px">
  <form method="get" class="ar-filter">
    <div>
      <label>Season</label>
      <select name="year">
        <?php foreach($years as $y):?><option value="<?=$y?>" <?=$year===$y?'selected':''?>><?=$y?></option><?php endforeach;?>
      </select>
    </div>
    <div>
      <label>View</label>
      <select name="event">
        <option value="">Season ratings</option>
        <?php foreach($events as $ev):?>
          <option value="<?=e($ev['tba_event_key'])?>" <?=$eventKey===(string)$ev['tba_event_key']?'selected':''?>>
            <?=e($ev['name'])?><?=((int)$ev['rating_count']>0)?'':' · not rated'?>
          </option>
        <?php endforeach;?>
      </select>
    </div>
    <button type="submit"><i class="fa-solid fa-filter"></i> Load</button>
  </form>

  <?php if($viewEvent):?>
    <div class="ar-meta">
      <span><?=e($viewEvent['tba_event_key'])?></span>
      <span><?=e($viewEvent['qual_match_count'])?> qualification matches</span>
      <span>Auto samples <?=e($viewEvent['auto_sample_count'])?></span>
      <span>Teleop <?=e($viewEvent['teleop_sample_count'])?></span>
      <span>Endgame <?=e($viewEvent['endgame_sample_count'])?></span>
      <span><?=e($viewEvent['model_version']?:'not calculated')?></span>
    </div>
  <?php endif;?>

  <?php if($canManage&&!$directoryReady):?>
    <div class="notice ar-manager-note">
      <b>Team-name directory is not installed yet.</b>
      Run <code>sql/2026-09-22_augur-epa-team-directory.sql</code>, then sync team metadata with the CLI included in the update.
    </div>
  <?php endif;?>
</section>

<section class="card" style="margin-top:14px;overflow:hidden">
  <div class="ar-table-wrap">
    <table class="ar-table">
      <caption class="sr-only"><?=e($datasetName)?></caption>
      <thead>
        <tr>
          <th>Rank</th>
          <th>Team</th>
          <th>Team Name</th>
          <th>City</th>
          <th>Country</th>
          <th>Public EPA</th>
          <th>Auto</th>
          <th>Teleop</th>
          <th>Endgame</th>
          <th>Trend</th>
          <th>Confidence</th>
          <th>Matches</th>
          <th>Record</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $i=>$r):
        $teamNo=(int)$r['frc_team_number'];
        $meta=$teamMeta[$teamNo]??[];
        $trend=(float)($r['trend']??0);
      ?>
        <tr>
          <td class="ar-rank" data-label="Rank">#<?=$i+1?></td>
          <td class="ar-team" data-label="Team"><b>#<?=e($teamNo)?></b></td>
          <td class="ar-team-name" data-label="Team Name"><?=e(($meta['nickname']??'')!==''?($meta['nickname']??''):(($meta['name']??'')!==''?($meta['name']??''):'—'))?></td>
          <td class="ar-location" data-label="City"><?=e($meta['city']??'—')?></td>
          <td class="ar-location" data-label="Country"><?=e($meta['country']??'—')?></td>
          <td data-label="Public EPA"><b><?=ar_num($r['rating'])?></b></td>
          <td data-label="Auto"><?=ar_num($r['auto_rating'])?></td>
          <td data-label="Teleop"><?=ar_num($r['teleop_rating'])?></td>
          <td data-label="Endgame"><?=ar_num($r['endgame_rating'])?></td>
          <td data-label="Trend" class="ar-trend <?=$trend>0.05?'up':($trend<-0.05?'down':'')?>"><?=$trend>0.05?'↑ ':($trend<-0.05?'↓ ':'→ ')?><?=ar_num(abs($trend),2)?></td>
          <td data-label="Confidence"><?=ar_num($r['confidence'],0)?>%</td>
          <td data-label="Matches"><?=e($r['matches_played'])?></td>
          <td data-label="Record"><?=e((int)$r['wins'].'-'.(int)$r['losses'].'-'.(int)$r['ties'])?></td>
        </tr>
      <?php endforeach;?>
      <?php if(!$rows):?>
        <tr><td colspan="13"><div class="notice">No Public EPA ratings are archived for this view yet.</div></td></tr>
      <?php endif;?>
      </tbody>
    </table>
  </div>
</section>

<section class="card ar-api" id="developer-api">
  <h2 style="margin-top:0"><i class="fa-solid fa-code"></i> Developer API</h2>
  <p class="ar-note">The API exists so other FRC teams, dashboards, scouting apps, spreadsheets, and websites can use Neptune's public EPA data without scraping this page. It returns read-only JSON from Neptune's local archive, never exposes private scouting data, and public requests never trigger a TBA download.</p>

  <div class="ar-api-actions">
    <a class="btn secondary" href="<?=e($api.'?year='.$year)?>" target="_blank" rel="noopener"><i class="fa-solid fa-brackets-curly"></i> View <?=$year?> JSON</a>
    <?php if($eventKey):?><a class="btn secondary" href="<?=e($api.'?event='.rawurlencode($eventKey))?>" target="_blank" rel="noopener"><i class="fa-solid fa-calendar-days"></i> View Event JSON</a><?php endif;?>
  </div>

  <code><?=e($api.'?events=1&year='.$year)?></code>
  <code><?=e($api.'?year='.$year)?></code>
  <?php if($eventKey):?><code><?=e($api.'?event='.rawurlencode($eventKey))?></code><?php endif;?>
  <code><?=e($api.'?year='.$year.'&team=6369')?></code>
</section>

<p class="ar-note" style="margin-top:14px">
  Model: <?=e(AUGUR_EPA_MODEL_VERSION)?>. EPA is public-data-only. Authenticated Neptune can blend this baseline with organization-scoped scouting to produce Neptune EPA, which AUGUR then uses in analytics and match predictions.
  <?php if($lastUpdated!==''):?> Ratings updated <?=e($lastUpdated)?>.<?php endif;?>
</p>
<?php endif;?>

<footer class="ar-footer">EPA by Neptune · AUGUR strategy analytics</footer>
</div>

<?php
if($viewer){
    include dirname(__DIR__).'/partials_footer.php';
}else{
?>
</main>
<script>
(function(){
  const btn=document.getElementById('themeToggle');
})();
</script>
</body></html>
<?php } ?>
