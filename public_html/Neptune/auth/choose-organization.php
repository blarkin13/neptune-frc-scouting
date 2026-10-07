<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
neptune_auth_ensure_schema($pdo);
if(current_user()){header('Location: '.base_url('dashboard/index.php'));exit;}

$pending=$_SESSION['google_membership_choice']??null;
if(!is_array($pending)||(int)($pending['expires_at']??0)<time()||empty($pending['allowed'])){
    unset($_SESSION['google_membership_choice']);
    $_SESSION['auth_error']='Your Google organization selection expired. Please sign in again.';
    header('Location: '.base_url('index.php'));exit;
}
$error='';
$allowed=[];
foreach((array)$pending['allowed'] as $item){
    $uid=(int)($item['user_id']??0);$oid=(int)($item['organization_id']??0);
    if($uid>0&&$oid>0)$allowed[$uid]=$oid;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$uid=(int)($_POST['user_id']??0);
    if(!isset($allowed[$uid]))$error='That organization is not available for this Google account.';
    else{
        $orgId=$allowed[$uid];$sub=(string)($pending['google_sub']??'');$intent=(string)($pending['intent']??'login');
        $s=$pdo->prepare("SELECT u.*,o.name organization_name,o.slug organization_slug,o.logo_path organization_logo_path,o.google_signin_mode
                         FROM users u JOIN organizations o ON o.id=u.organization_id
                         WHERE u.id=? AND u.organization_id=? AND u.active=1 AND u.google_sub=? AND COALESCE(o.google_signin_mode,'optional')<>'disabled' LIMIT 1");
        $s->execute([$uid,$orgId,$sub]);$row=$s->fetch();
        if(!$row)$error='That Neptune membership is no longer available.';
        else{
            unset($_SESSION['google_membership_choice']);
            header('Location: '.neptune_google_finish_user($pdo,$row,$sub,$intent));exit;
        }
    }
}

$ids=array_keys($allowed);$rows=[];
if($ids){
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $s=$pdo->prepare("SELECT u.id,u.organization_id,u.role,u.display_name,o.name organization_name,o.logo_path organization_logo_path
                     FROM users u JOIN organizations o ON o.id=u.organization_id
                     WHERE u.id IN ($marks) AND u.active=1 ORDER BY o.name");
    $s->execute($ids);$rows=$s->fetchAll();
    $last=(int)($_COOKIE['neptune_last_org']??0);
    usort($rows,fn($a,$b)=>(((int)$a['organization_id']===$last)?0:1)<=>(((int)$b['organization_id']===$last)?0:1) ?: strcasecmp((string)$a['organization_name'],(string)$b['organization_name']));
}
$pageTitle='Choose Organization';include dirname(__DIR__).'/partials_header.php';
?>
<style>
.auth-choice-shell{max-width:720px;margin:42px auto}.auth-choice-card{padding:24px}.auth-choice-brand{display:flex;align-items:center;gap:12px;margin-bottom:18px}.auth-choice-brand img{width:48px;height:48px;object-fit:contain}.auth-choice-grid{display:grid;gap:12px;margin-top:18px}.auth-choice-option{width:100%;display:grid;grid-template-columns:54px 1fr auto;gap:14px;align-items:center;text-align:left;padding:15px;border:1px solid var(--line);border-radius:18px;background:var(--panel2);color:var(--text);cursor:pointer}.auth-choice-option:hover{border-color:var(--accent);transform:translateY(-1px)}.auth-choice-logo{width:54px;height:54px;border-radius:14px;background:#fff;border:1px solid var(--line);display:grid;place-items:center;overflow:hidden}.auth-choice-logo img{width:100%;height:100%;object-fit:contain;padding:6px}.auth-choice-logo i{color:#617089;font-size:1.25rem}.auth-choice-name{font-size:1.05rem;font-weight:900}.auth-choice-meta{margin-top:4px;color:var(--muted);font-size:.86rem}.auth-choice-arrow{color:var(--muted)}@media(max-width:600px){.auth-choice-shell{margin:20px auto}.auth-choice-option{grid-template-columns:46px 1fr auto;padding:13px}.auth-choice-logo{width:46px;height:46px}}
</style>
<div class="auth-choice-shell"><div class="card auth-choice-card">
  <div class="auth-choice-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><div><div class="module-eyebrow"><span>NEPTUNE</span><small>Google Sign-In</small></div><h1 style="margin:0">Choose your organization</h1></div></div>
  <p class="muted">This Google account already has access to more than one Neptune organization. Only organizations tied to your verified account are shown here.</p>
  <?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
  <div class="auth-choice-grid">
  <?php foreach($rows as $row):$logo=trim((string)($row['organization_logo_path']??''));?>
    <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="user_id" value="<?=(int)$row['id']?>">
      <button class="auth-choice-option" type="submit">
        <span class="auth-choice-logo"><?php if($logo!==''):?><img src="<?=e(base_url(ltrim($logo,'/')))?>" alt=""><?php else:?><i class="fa-solid fa-building"></i><?php endif;?></span>
        <span><span class="auth-choice-name"><?=e($row['organization_name'])?></span><span class="auth-choice-meta"><?=e(ucfirst((string)$row['role']))?> access<?php if((int)$row['organization_id']===(int)($_COOKIE['neptune_last_org']??0)):?> · Last used<?php endif;?></span></span>
        <i class="fa-solid fa-chevron-right auth-choice-arrow"></i>
      </button>
    </form>
  <?php endforeach;?>
  </div>
  <div class="public-links" style="margin-top:18px"><a href="<?=e(base_url('index.php'))?>">Cancel and return to sign in</a></div>
</div></div>
<?php include dirname(__DIR__).'/partials_footer.php';
