<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,2).'/neptune_secure/google-auth.php';
neptune_auth_ensure_schema($pdo);
$token=trim((string)($_GET['token']??$_POST['token']??''));
$error='';
if(!empty($_SESSION['auth_error'])){$error=(string)$_SESSION['auth_error'];unset($_SESSION['auth_error']);}
$invite=$token!==''?neptune_auth_invite_load($pdo,$token):null;
$usable=$invite&&neptune_auth_invite_is_usable($invite);
if($invite&&strtolower((string)($invite['google_signin_mode']??'optional'))==='disabled'){$usable=false;if($error==='')$error='Google sign-in is disabled for this organization, so this invitation cannot be accepted.';}
if(!$invite&&$token!==''&&$error==='')$error='This invitation is invalid.';
elseif($invite&&!neptune_auth_invite_is_usable($invite)&&$error==='')$error='This invitation has expired, was revoked, or has already been used.';
$hideChrome=true;$pageTitle='Join Neptune';include __DIR__.'/partials_header.php';
?>
<style>.join-shell{max-width:650px;margin:46px auto}.join-card{padding:28px;text-align:center}.join-logo{width:72px;height:72px;object-fit:contain;margin-bottom:10px}.join-org{margin:18px 0;padding:18px;border:1px solid var(--line);border-radius:18px;background:var(--panel2)}.join-org strong{font-size:1.25rem;display:block}.join-org span{display:block;color:var(--muted);margin-top:4px}.join-actions{display:grid;gap:10px;max-width:420px;margin:18px auto 0}.join-actions button{width:100%}.join-note{margin-top:18px;color:var(--muted);font-size:.86rem;line-height:1.6}</style>
<div class="join-shell"><div class="card join-card">
  <img class="join-logo" src="<?=e(base_url('images/logo.png'))?>" alt="Neptune">
  <div class="module-eyebrow" style="justify-content:center"><span>NEPTUNE</span><small>Organization Invitation</small></div>
  <h1>Join Neptune</h1>
  <?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
  <?php if($usable):?>
    <div class="join-org"><strong><?=e($invite['organization_name'])?></strong><span>This one-time invitation grants a new or existing Google identity <b>Scout</b> access to this organization.<?php if(!empty($invite['invite_email'])):?> It is restricted to the Google email specified by the administrator.<?php endif;?></span></div>
    <form method="post" action="<?=e(base_url('auth/google-start.php'))?>" class="join-actions">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="intent" value="invite"><input type="hidden" name="invite_token" value="<?=e($token)?>">
      <button type="submit"><i class="fa-brands fa-google"></i> Continue with Google</button>
      <a class="btn secondary" href="<?=e(base_url('index.php'))?>">Cancel</a>
    </form>
    <div class="join-note">The invitation cannot be used to choose another organization and cannot grant Admin, Strategy, or Owner access. An organization administrator can promote the account later.</div>
  <?php else:?>
    <p class="muted">Ask the organization administrator for a new invitation link.</p>
    <div class="join-actions"><a class="btn" href="<?=e(base_url('index.php'))?>">Return to Neptune</a></div>
  <?php endif;?>
</div></div>
<?php include __DIR__.'/partials_footer.php';
