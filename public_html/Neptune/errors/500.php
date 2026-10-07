<?php
$reference=$internalErrorReference??'Unavailable';
$pageTitle='Neptune | Something Went Wrong';
$bodyClass='neptune-error-page';
include dirname(__DIR__).'/partials_header.php';
?>
<section class="neptune-error-shell" aria-labelledby="internalErrorTitle">
  <div class="neptune-error-code" aria-hidden="true">500</div>
  <div class="neptune-error-panel">
    <div class="neptune-error-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div>
      <div class="section-kicker"><i class="fa-solid fa-shield-halved"></i> NEPTUNE</div>
      <h1 id="internalErrorTitle">Something went wrong</h1>
      <p class="neptune-error-message">Neptune could not complete that request. No technical or database details have been exposed.</p>
      <p class="muted">Try again. If the problem continues, give the platform administrator this reference:</p>
      <p><code style="user-select:all"><?=e($reference)?></code></p>
      <div class="toolbar neptune-error-actions">
        <a class="btn" href="<?=e(base_url('index.php'))?>"><i class="fa-solid fa-house"></i> Back to Neptune</a>
      </div>
    </div>
  </div>
</section>
<?php include dirname(__DIR__).'/partials_footer.php';
