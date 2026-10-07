<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,2).'/neptune_secure/google-auth.php';
neptune_auth_ensure_schema($pdo);
if(current_user()){header('Location: '.base_url('dashboard/index.php'));exit;}
$error='';
if(!empty($_SESSION['auth_error'])){$error=(string)$_SESSION['auth_error'];unset($_SESSION['auth_error']);}
$pageTitle='Reset Password';include __DIR__.'/partials_header.php';
?>
<style>
.recovery-shell{max-width:620px;margin:44px auto}.recovery-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:20px 0}.recovery-step{padding:10px;border:1px solid var(--line);border-radius:12px;text-align:center;font-size:.82rem;color:var(--muted);background:var(--panel2)}.recovery-step strong{display:block;color:var(--text);font-size:1rem;margin-bottom:2px}.recovery-actions{display:grid;gap:10px;margin-top:16px}.recovery-actions button{width:100%}.recovery-note{margin-top:18px;padding-top:16px;border-top:1px solid var(--line)}@media(max-width:620px){.recovery-shell{margin:20px auto}.recovery-steps{grid-template-columns:1fr}.recovery-step{text-align:left}}
</style>
<div class="recovery-shell"><div class="card auth-card">
  <div class="auth-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune" class="auth-logo"></div>
  <h1 class="auth-title">Reset your password</h1>
  <p class="muted auth-subtitle">Verify the Google identity tied to your Neptune account, then choose a new local password. You do not need to know or select your organization first.</p>
  <div class="recovery-steps"><div class="recovery-step"><strong>1</strong>Verify with Google</div><div class="recovery-step"><strong>2</strong>Choose membership only if needed</div><div class="recovery-step"><strong>3</strong>Set new password</div></div>
  <?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
  <?php if(neptune_google_enabled()):?>
    <form method="post" action="<?=e(base_url('auth/google-start.php'))?>">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="intent" value="reset_password">
      <div class="recovery-actions"><button type="submit"><i class="fa-brands fa-google"></i> Verify identity with Google</button></div>
    </form>
  <?php else:?><div class="notice">Google authentication has not been configured on this Neptune server.</div><?php endif;?>
  <div class="recovery-note muted"><b>No access to the Google account on your Neptune profile?</b> An organization owner or admin can issue a temporary password from Teams &amp; Users.</div>
  <div class="public-links"><a href="<?=e(base_url('index.php'))?>">Back to sign in</a></div>
</div></div>
<?php include __DIR__.'/partials_footer.php';
