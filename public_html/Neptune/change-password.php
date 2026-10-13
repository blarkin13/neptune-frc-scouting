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
$bodyClass=trim(($bodyClass??'').' neptune-password-page');
include __DIR__.'/partials_header.php';
?>
<style id="neptune-password-theme">
/* Uses Neptune's active theme tokens; local to this password screen. */
.neptune-password-page main.wrap{width:100%;max-width:1100px;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:0;padding:clamp(14px,2.7vh,24px) 16px!important}
.neptune-password-page .neptune-password-card{width:min(100%,490px);padding:clamp(18px,3vh,28px);border:1px solid var(--line,var(--border,#38536c));border-radius:18px;background:color-mix(in srgb,var(--panel,var(--surface,#13263a)) 68%,transparent);color:var(--text,#edf5ff);box-shadow:0 14px 40px rgba(0,0,0,.12);margin:0 auto}
.neptune-password-page .neptune-password-heading{display:flex;align-items:center;gap:14px;margin-bottom:14px}
.neptune-password-page .neptune-password-logo{width:65px;height:65px;object-fit:contain;flex:none}
.neptune-password-page .neptune-password-heading h1{font-size:clamp(1.3rem,2.6vw,1.75rem);letter-spacing:-.025em;line-height:1.12;margin:0;color:var(--text,#edf5ff)}
.neptune-password-page .neptune-password-subtitle{color:var(--muted,#9eafc5);font-size:.87rem;line-height:1.45;margin:0 0 16px}
.neptune-password-page .neptune-password-form{display:grid;gap:9px}
.neptune-password-page .neptune-password-form label{font-size:.85rem;font-weight:700;margin:0;color:var(--text,#edf5ff)}
.neptune-password-page .neptune-password-form input{width:100%;min-height:42px;padding:9px 12px;border:1px solid var(--line,var(--border,#38536c));border-radius:9px;background:var(--panel2,var(--panel,#192a40));color:var(--text,#edf5ff);font:inherit;margin:0}
.neptune-password-page .neptune-password-form input:focus-visible{outline:2px solid var(--accent,#4bb6e8);outline-offset:2px}
.neptune-password-page .neptune-password-submit{display:block;width:100%;margin-top:6px;border:0;border-radius:10px;padding:11px 15px;cursor:pointer;background:var(--accent,#4bb6e8);color:var(--bg,#081624);font:inherit;font-weight:800}
.neptune-password-page .neptune-password-bottom{margin-top:12px;display:flex;justify-content:center;gap:18px;font-size:.84rem}
.neptune-password-page .neptune-password-bottom a{color:var(--accent,#4bb6e8);text-decoration:none}
.neptune-password-page .neptune-password-bottom a:hover{text-decoration:underline}
.neptune-password-page .notice.bad{margin-bottom:12px}
@media(max-height:720px) and (min-width:650px){.neptune-password-page main.wrap{padding-top:8px!important;padding-bottom:8px!important}.neptune-password-page .neptune-password-card{padding:15px 22px}.neptune-password-page .neptune-password-logo{width:48px;height:48px}.neptune-password-page .neptune-password-subtitle{margin-bottom:10px}.neptune-password-page .neptune-password-form{gap:6px}}
</style>
<section class="neptune-password-card" aria-labelledby="neptune-password-title">
    <div class="neptune-password-heading"><img src="<?=e(base_url('images/logo.png'))?>" alt="" class="neptune-password-logo"><h1 id="neptune-password-title">Set your Neptune password</h1></div>
    <p class="neptune-password-subtitle"><?=!empty($u['must_change_password'])?'Replace your temporary password to continue to Neptune.':'Set a local Neptune password for signing in without Google.'?> Use at least 10 characters.</p>
    <?php if($error):?><div class="notice bad" role="alert"><?=e($error)?></div><?php endif;?>
    <form class="neptune-password-form" method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <label for="password">New password</label>
        <input id="password" type="password" name="password" minlength="10" required autocomplete="new-password" autofocus>
        <label for="confirm_password">Confirm new password</label>
        <input id="confirm_password" type="password" name="confirm_password" minlength="10" required autocomplete="new-password">
        <button class="neptune-password-submit" type="submit"><i class="fa-solid fa-key" aria-hidden="true"></i> Save password</button>
    </form>
    <div class="neptune-password-bottom"><a href="<?=e(base_url('dashboard/index.php'))?>?view=account">My Account</a><a href="<?=e(base_url('logout.php'))?>">Sign out</a></div>
</section>
<?php include __DIR__.'/partials_footer.php';
