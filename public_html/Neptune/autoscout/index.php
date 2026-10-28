<?php
declare(strict_types=1);
// Auto Scout hub: every autonomous-scouting tool in one place (platform owner only).
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
require_once dirname(__DIR__,3).'/neptune_secure/platform-security.php';
$u=require_role(['owner','admin']);
if((int)$u['organization_id']!==(int)($config['app']['platform_organization_id']??1))access_denied('Auto Scout is for admins of the owner organization.');

header('Cache-Control: private, no-store');
require_once dirname(__DIR__).'/community/layout.php';community_page_start('Auto Scout','Three pillars, in order: Robots, Field, Actions. Each page holds its examples, its training and what it learned. Then test and release.','hub','PLATFORM OWNER · AUTO SCOUT');
?>
<section class="neptune-hub auto-scout-hub"><div class="neptune-hub-grid">
<a class="neptune-hub-card accent-trident" href="<?=e(base_url('autoscout/robot-labels.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-robot"></i></span><span class="neptune-hub-badge access-all">Platform owner</span></div><span class="neptune-hub-card-kicker">Pillar 1</span><h3>Robots</h3><p>What a robot is: box bumpers, train the robot detector, see what it learned.</p><div class="neptune-hub-meta"><span>Auto Scout</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow" aria-hidden="true"></i></a>
<a class="neptune-hub-card accent-trident" href="<?=e(base_url('autoscout/scoring-areas.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-border-all"></i></span><span class="neptune-hub-badge access-all">Platform owner</span></div><span class="neptune-hub-card-kicker">Pillar 2</span><h3>Field</h3><p>Scoring areas: mark targets and corners, train each game’s field detector.</p><div class="neptune-hub-meta"><span>Auto Scout</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow" aria-hidden="true"></i></a>
<a class="neptune-hub-card accent-trident" href="<?=e(base_url('autoscout/train.php'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-brain"></i></span><span class="neptune-hub-badge access-all">Platform owner</span></div><span class="neptune-hub-card-kicker">Pillar 3</span><h3>Actions</h3><p>What robots do: tag or import examples, train action and scoring models.</p><div class="neptune-hub-meta"><span>Auto Scout</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow" aria-hidden="true"></i></a>
<a class="neptune-hub-card accent-trident" href="<?=e(base_url('autoscout/train.php?view=test'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-circle-check"></i></span><span class="neptune-hub-badge access-all">Platform owner</span></div><span class="neptune-hub-card-kicker">Testing</span><h3>Test & release</h3><p>Try the models on a match, evaluate them, and release one.</p><div class="neptune-hub-meta"><span>Auto Scout</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow" aria-hidden="true"></i></a>
<a class="neptune-hub-card accent-trident" href="<?=e(base_url('autoscout/train.php?view=helpers'))?>"><div class="neptune-hub-card-top"><span class="neptune-hub-icon"><i class="fa-solid fa-laptop"></i></span><span class="neptune-hub-badge access-all">Platform owner</span></div><span class="neptune-hub-card-kicker">Setup</span><h3>Helpers & settings</h3><p>Install and connect the helper that does the training.</p><div class="neptune-hub-meta"><span>Auto Scout</span></div><i class="fa-solid fa-arrow-right neptune-hub-arrow" aria-hidden="true"></i></a>
</div></section>
<?php community_page_end(); ?>
