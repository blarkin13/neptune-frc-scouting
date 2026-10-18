<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/image.php';
require_once __DIR__.'/_event_health.php';
$u=require_role(['owner','admin']);
$health=neptune_event_health($pdo,isset($config)&&is_array($config)?$config:[],(int)$u['organization_id']);
if(($_GET['format']??'')==='json'){
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($health,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
}
$caps=neptune_image_capabilities();

function health_badge(string $state): string {
    [$cls,$icon,$text]=match($state){
        'good'=>['status-good','fa-circle-check','OK'],
        'warn'=>['status-warn','fa-triangle-exclamation','Check'],
        'bad'=>['status-bad','fa-circle-xmark','Action needed'],
        default=>['','fa-circle-info','Info'],
    };
    return '<span class="pill '.$cls.'"><i class="fa-solid '.$icon.'"></i> '.e($text).'</span>';
}

function check_badge(bool $ok,string $yes='Available',string $no='Unavailable'): string {
    return '<span class="pill '.($ok?'status-good':'status-bad').'"><i class="fa-solid '.($ok?'fa-circle-check':'fa-circle-xmark').'"></i> '.e($ok?$yes:$no).'</span>';
}

$pageTitle='System Check';
$moduleName='MERCURY';
include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between;align-items:flex-start">
  <div>
    <div class="module-eyebrow"><span>MERCURY</span><small>System Maintenance</small></div>
    <h1 style="margin:3px 0">System Check</h1>
    <div class="muted">Event-day health and the hosting capabilities Neptune uses, including pit-photo optimization.</div>
  </div>
  <a class="btn secondary" href="index.php"><i class="fa-solid fa-arrow-left"></i> Saturn</a>
</div>

<style>
.pill.status-warn{border-color:color-mix(in srgb,#d9a400 65%,var(--line));background:color-mix(in srgb,#d9a400 16%,var(--panel2))}
.event-health-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.event-health-head h2{margin:0}
.event-health-actions{display:flex;align-items:center;gap:8px}
.event-health-actions .btn{padding:6px 11px;font-size:.82rem;min-height:0}
.event-health-meta{margin:6px 0 4px;font-size:.84rem}
.system-capability-list>.event-health-row{display:grid;grid-template-columns:minmax(150px,210px) minmax(0,1fr) auto;justify-content:normal;align-items:center;gap:16px;text-align:left}
.event-health-row .eh-label{font-weight:800}
.event-health-row .eh-value{display:block}
.event-health-row .eh-detail{display:block;font-size:.8rem;margin-top:2px;overflow-wrap:anywhere}
@media(max-width:640px){.system-capability-list>.event-health-row{grid-template-columns:minmax(0,1fr) auto;gap:4px 12px}.event-health-row .eh-body{grid-column:1/-1;grid-row:2}}
</style>
<section class="card" style="margin-top:18px" id="event-health">
  <div class="event-health-head">
    <h2><i class="fa-solid fa-heart-pulse"></i> Event-Day Health</h2>
    <div class="event-health-actions"><?=health_badge($health['overall'])?><a class="btn secondary" href="system-check.php#event-health" title="Run the checks again"><i class="fa-solid fa-rotate"></i> Refresh</a></div>
  </div>
  <p class="muted event-health-meta">Host: <?=e(ucfirst($health['host_mode']))?> · Checked <?=e(gmdate('H:i:s',strtotime($health['generated_at'])))?> UTC · <a href="system-check.php?format=json">JSON</a></p>
  <div class="system-capability-list">
    <?php foreach($health['checks'] as $c):?>
      <div class="event-health-row">
        <span class="eh-label"><?=e($c['label'])?></span>
        <span class="eh-body"><span class="eh-value"><?=e($c['value'])?></span><?php if($c['detail']!==''):?><span class="eh-detail muted"><?=e($c['detail'])?></span><?php endif;?></span>
        <?=health_badge($c['state'])?>
      </div>
    <?php endforeach;?>
  </div>
</section>

<div class="system-check-grid">
  <section class="card system-check-card">
    <span class="quick-icon"><i class="fa-brands fa-php"></i></span>
    <div><span class="muted">PHP</span><h2><?=e(PHP_VERSION)?></h2><p class="muted">Neptune recommends PHP 8.x.</p></div>
  </section>
  <section class="card system-check-card">
    <span class="quick-icon"><i class="fa-solid fa-image"></i></span>
    <div><span class="muted">Preferred photo output</span><h2><?=e($caps['preferred_label'])?></h2><p class="muted">Neptune prefers AVIF, then WebP, then JPEG.</p></div>
  </section>
  <section class="card system-check-card">
    <span class="quick-icon"><i class="fa-solid fa-upload"></i></span>
    <div><span class="muted">Per-upload PHP limit</span><h2><?=e(neptune_format_bytes((int)$caps['effective_upload_max_bytes']))?></h2><p class="muted">upload_max_filesize: <?=e((string)ini_get('upload_max_filesize'))?> · post_max_size: <?=e((string)ini_get('post_max_size'))?></p></div>
  </section>
</div>

<section class="card" style="margin-top:16px">
  <h2><i class="fa-solid fa-camera-retro"></i> Pit Photo Processing</h2>
  <div class="system-capability-list">
    <div><span>ImageMagick / Imagick</span><?=check_badge((bool)$caps['imagick'])?></div>
    <div><span>PHP GD</span><?=check_badge((bool)$caps['gd'])?></div>
    <div><span>AVIF encoding</span><?=check_badge((bool)$caps['avif'])?></div>
    <div><span>WebP encoding</span><?=check_badge((bool)$caps['webp'])?></div>
    <div><span>JPEG encoding</span><?=check_badge((bool)$caps['jpeg'])?></div>
    <div><span>HEIC / HEIF phone-photo decoding</span><?=check_badge((bool)$caps['heic'],'Supported','Not detected')?></div>
  </div>
</section>

<?php if(!$caps['avif']):?>
<div class="notice" style="margin-top:16px"><b>AVIF is not available on this host.</b> Pit photos will still work: Neptune will automatically use <?=e($caps['preferred_label'])?> instead. To enable AVIF, install/enable an AVIF-capable Imagick/ImageMagick build or PHP GD with <code>imageavif()</code> support.</div>
<?php else:?>
<div class="notice good" style="margin-top:16px"><b>AVIF ready.</b> New pit photos will be resized to a maximum dimension of 1800 px and encoded as AVIF before being stored.</div>
<?php endif;?>

<?php if(!$caps['heic']):?>
<div class="notice" style="margin-top:10px"><b>HEIC note:</b> Most mobile browser camera captures arrive as a browser-compatible image, but an existing HEIC/HEIF file may require ImageMagick HEIC support. If one cannot be decoded, Neptune will tell the scout to use a JPEG/Most Compatible camera format.</div>
<?php endif;?>

<?php include dirname(__DIR__).'/partials_footer.php';
