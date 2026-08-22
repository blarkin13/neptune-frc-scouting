<?php
require_once dirname(__DIR__,2).'/neptune_secure/bootstrap.php';
if(current_user()){header('Location: '.base_url('dashboard/index.php'));exit;}
$error='';$done=false;
function unique_org_slug(PDO $pdo,string $name):string{$base=strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/','-',$name),'-'))?:'organization';$slug=$base;$i=1;$s=$pdo->prepare('SELECT COUNT(*) FROM organizations WHERE slug=?');while(true){$s->execute([$slug]);if(!(int)$s->fetchColumn())return $slug;$slug=$base.'-'.$i++;}}
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();$orgName=trim($_POST['organization']??'');$display=trim($_POST['display_name']??'');$username=trim($_POST['username']??'');$email=trim($_POST['email']??'');$password=$_POST['password']??'';$team=(int)($_POST['frc_team_number']??0);
  if($orgName===''||$display===''||$username===''||strlen($password)<10){$error='Complete the required fields. Passwords must be at least 10 characters.';}else{
    $pdo->beginTransaction();try{$slug=unique_org_slug($pdo,$orgName);$pdo->prepare('INSERT INTO organizations(name,slug) VALUES(?,?)')->execute([$orgName,$slug]);$org=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO users(organization_id,username,email,password_hash,display_name,role,must_change_password,password_changed_at) VALUES(?,?,?,?,?,'owner',0,UTC_TIMESTAMP())")->execute([$org,$username,$email?:null,password_hash($password,PASSWORD_DEFAULT),$display]);$uid=(int)$pdo->lastInsertId();if($team>0){$pdo->prepare('INSERT INTO teams(organization_id,frc_team_number,display_name,tba_key) VALUES(?,?,?,?)')->execute([$org,$team,'FRC '.$team,'frc'.$team]);$tid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO user_teams(user_id,team_id,is_primary) VALUES(?,?,1)')->execute([$uid,$tid]);}$pdo->commit();$done=true;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error='Unable to create organization. '.$e->getMessage();}
  }
}
$pageTitle='Organization Signup';include __DIR__.'/partials_header.php';
?>
<div class="card auth-card"><div class="auth-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune" class="auth-logo"></div><h1 class="auth-title">Create a Neptune Organization</h1><p class="muted auth-subtitle">For a new school, club, or robotics organization joining the platform.</p>
<?php if($done):?><div class="notice good">Organization created. <a href="<?=e(base_url('index.php'))?>">Sign in to Neptune</a>.</div><?php else:?><?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Organization name</label><input name="organization" required placeholder="Example Robotics Academy"><label>FRC team number <span class="muted">(optional)</span></label><input type="number" name="frc_team_number"><label>Owner display name</label><input name="display_name" required><label>Owner username</label><input name="username" required autocomplete="username"><label>Email</label><input type="email" name="email"><label>Password</label><input type="password" name="password" minlength="10" required autocomplete="new-password"><div class="toolbar"><button><i class="fa-solid fa-rocket"></i> Create Organization</button></div></form><?php endif;?><div class="public-links"><a href="<?=e(base_url('index.php'))?>">Back to sign in</a></div></div>
<?php include __DIR__.'/partials_footer.php';
