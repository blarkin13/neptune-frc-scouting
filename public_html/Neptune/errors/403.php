<?php
$pageTitle='Access Denied';
$bodyClass='neptune-error-page';
$u=$accessDeniedUser??current_user();
$message=$accessDeniedMessage??'Your account does not have permission to access this area.';
include dirname(__DIR__).'/partials_header.php';
?>
<section class="neptune-error-shell" aria-labelledby="accessDeniedTitle">
  <div class="neptune-error-code" aria-hidden="true">403</div>
  <div class="neptune-error-panel">
    <div class="neptune-error-icon"><i class="fa-solid fa-shield-halved"></i></div>
    <div>
      <div class="section-kicker"><i class="fa-solid fa-lock"></i> NEPTUNE SECURITY</div>
      <h1 id="accessDeniedTitle">Access Denied</h1>
      <p class="neptune-error-message"><?=e($message)?></p>

      <?php if($u): ?>
        <div class="neptune-error-user">
          <span><i class="fa-solid fa-user"></i> <?=e($u['display_name']??$u['username']??'Neptune user')?></span>
          <?php if(!empty($u['organization_name'])):?><span><i class="fa-solid fa-building"></i> <?=e($u['organization_name'])?></span><?php endif;?>
          <?php if(!empty($u['role'])):?><span class="pill"><i class="fa-solid fa-id-badge"></i> <?=e(ucfirst((string)$u['role']))?></span><?php endif;?>
        </div>
      <?php endif; ?>

      <p class="muted">Your account is still signed in. Use one of the available areas below or return to the Neptune home page.</p>
      <div class="toolbar neptune-error-actions">
        <a class="btn" href="<?=e(base_url($u?'dashboard/index.php':'index.php'))?>"><i class="fa-solid fa-house"></i> Back to Neptune</a>
        <?php if($u): ?>
          <a class="btn secondary" href="<?=e(base_url('scout/index.php'))?>"><i class="fa-solid fa-crosshairs"></i> Scout</a>
          <a class="btn secondary" href="<?=e(base_url('pit/index.php'))?>"><i class="fa-solid fa-clipboard-list"></i> Pit Scouting</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php include dirname(__DIR__).'/partials_footer.php';
