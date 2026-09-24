<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/image.php';
$u=require_role(['owner','admin']);
$caps=neptune_image_capabilities();

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
    <div class="muted">Hosting capabilities used by Neptune, including pit-photo optimization.</div>
  </div>
  <a class="btn secondary" href="index.php"><i class="fa-solid fa-arrow-left"></i> Saturn</a>
</div>

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
