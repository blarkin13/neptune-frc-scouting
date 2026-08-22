<?php
require_once dirname(__DIR__,2).'/neptune_secure/bootstrap.php';
$u=require_login();
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $password=(string)($_POST['password']??'');
    $confirm=(string)($_POST['confirm_password']??'');

    if(strlen($password)<10){
        $error='Your new password must be at least 10 characters.';
    } elseif($password!==$confirm){
        $error='The new passwords do not match.';
    } else {
        $s=$pdo->prepare('SELECT password_hash FROM users WHERE id=? AND organization_id=? AND active=1 LIMIT 1');
        $s->execute([(int)$u['id'],(int)$u['organization_id']]);
        $currentHash=(string)$s->fetchColumn();
        if($currentHash!=='' && password_verify($password,$currentHash)){
            $error='Choose a password different from your temporary password.';
        } else {
            $hash=password_hash($password,PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0,password_changed_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=?')
                ->execute([$hash,(int)$u['id'],(int)$u['organization_id']]);
            $_SESSION['user']['must_change_password']=0;
            session_regenerate_id(true);
            header('Location: '.base_url('dashboard/index.php'));
            exit;
        }
    }
}

$pageTitle='Set Your Password';
include __DIR__.'/partials_header.php';
?>
<div class="card auth-card">
    <div class="auth-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune" class="auth-logo"></div>
    <h1 class="auth-title">Choose a new password</h1>
    <p class="muted auth-subtitle">You signed in with a temporary password. Set your own password before continuing to Neptune.</p>
    <?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <label for="password">New password</label>
        <input id="password" type="password" name="password" minlength="10" required autocomplete="new-password" autofocus>
        <label for="confirm_password">Confirm new password</label>
        <input id="confirm_password" type="password" name="confirm_password" minlength="10" required autocomplete="new-password">
        <div class="toolbar"><button type="submit"><i class="fa-solid fa-key"></i> Set Password & Continue</button></div>
    </form>
    <div class="public-links"><a href="<?=e(base_url('logout.php'))?>">Sign out instead</a></div>
</div>
<?php include __DIR__.'/partials_footer.php';
