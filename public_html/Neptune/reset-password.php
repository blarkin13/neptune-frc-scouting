<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,2).'/neptune_secure/google-auth.php';
neptune_auth_ensure_schema($pdo);

$pending=$_SESSION['password_reset_google']??null;
if(!is_array($pending) || (int)($pending['expires_at']??0)<time()){
    unset($_SESSION['password_reset_google']);
    $_SESSION['auth_error']='Your password reset verification expired. Please verify with Google again.';
    header('Location: '.base_url('forgot-password.php'));exit;
}
$uid=(int)($pending['user_id']??0);$org=(int)($pending['organization_id']??0);
$s=$pdo->prepare('SELECT u.id,u.organization_id,u.username,u.email,u.display_name,u.role,u.active,o.name organization_name,o.slug organization_slug FROM users u JOIN organizations o ON o.id=u.organization_id WHERE u.id=? AND u.organization_id=? AND u.active=1 LIMIT 1');
$s->execute([$uid,$org]);$user=$s->fetch();
if(!$user){unset($_SESSION['password_reset_google']);$_SESSION['auth_error']='That Neptune account is no longer available.';header('Location: '.base_url('forgot-password.php'));exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $password=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm_password']??'');
    if(strlen($password)<12)$error='Use at least 12 characters for your new password.';
    elseif(strlen($password)>255)$error='Password is too long.';
    elseif(!hash_equals($password,$confirm))$error='The two passwords do not match.';
    else{
        $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0,password_changed_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$uid,$org]);
        neptune_auth_audit($pdo,$org,$uid,'password_reset_self_google','user',(string)$uid);
        unset($_SESSION['password_reset_google']);
        $_SESSION['auth_notice']='Password changed. You can sign in with your new password or continue using Google.';
        header('Location: '.base_url('index.php'));exit;
    }
}
$pageTitle='Choose New Password';include __DIR__.'/partials_header.php';
?>
<style>.reset-shell{max-width:560px;margin:44px auto}.password-meter{font-size:.82rem;color:var(--muted);margin-top:6px}.reset-actions{margin-top:18px}.reset-actions button{width:100%}</style>
<div class="reset-shell"><div class="card auth-card">
<div class="auth-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune" class="auth-logo"></div>
<h1 class="auth-title">Choose a new password</h1>
<p class="muted auth-subtitle">Google verified <b><?=e($user['email']?:$user['username'])?></b> for <?=e($user['organization_name'])?>.</p>
<?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<label for="password">New password</label><input id="password" type="password" name="password" minlength="12" maxlength="255" autocomplete="new-password" required><div class="password-meter">At least 12 characters.</div>
<label for="confirm_password">Confirm new password</label><input id="confirm_password" type="password" name="confirm_password" minlength="12" maxlength="255" autocomplete="new-password" required>
<div class="reset-actions"><button type="submit"><i class="fa-solid fa-key"></i> Save new password</button></div>
</form></div></div>
<?php include __DIR__.'/partials_footer.php';
