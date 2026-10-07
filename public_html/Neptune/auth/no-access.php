<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
if(current_user()){header('Location: '.base_url('dashboard/index.php'));exit;}
$data=$_SESSION['google_no_access']??null;unset($_SESSION['google_no_access']);
$email=is_array($data)?trim((string)($data['email']??'')):'';
$message=is_array($data)?trim((string)($data['message']??'')):'';
if($message==='')$message='No Neptune access is associated with this Google account.';
$pageTitle='No Neptune Access';include dirname(__DIR__).'/partials_header.php';
?>
<style>.no-access-shell{max-width:640px;margin:48px auto}.no-access-card{text-align:center;padding:28px}.no-access-logo{width:68px;height:68px;object-fit:contain;margin-bottom:12px}.no-access-icon{width:58px;height:58px;margin:12px auto 18px;border-radius:50%;display:grid;place-items:center;background:var(--panel2);border:1px solid var(--line);font-size:1.4rem;color:var(--muted)}.no-access-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:20px}</style>
<div class="no-access-shell"><div class="card no-access-card">
  <img class="no-access-logo" src="<?=e(base_url('images/logo.png'))?>" alt="Neptune">
  <div class="no-access-icon"><i class="fa-solid fa-lock"></i></div>
  <h1>No Neptune access found</h1>
  <?php if($email!==''):?><p class="muted">Google verified <b><?=e($email)?></b>.</p><?php endif;?>
  <p><?=e($message)?></p>
  <p class="muted">Neptune does not show an organization directory here. Access must come from an existing account, an approved Workspace policy, or a direct organization invitation.</p>
  <div class="no-access-actions"><a class="btn" href="<?=e(base_url('index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a><a class="btn secondary" href="<?=e(base_url('register.php'))?>"><i class="fa-solid fa-building-circle-check"></i> Register an organization</a></div>
</div></div>
<?php include dirname(__DIR__).'/partials_footer.php';
