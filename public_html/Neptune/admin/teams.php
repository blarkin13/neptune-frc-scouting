<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin']);$org=(int)$u['organization_id'];$msg='';$error='';
$s=$pdo->prepare('SELECT * FROM teams WHERE organization_id=? AND active=1 ORDER BY frc_team_number');$s->execute([$org]);$teams=$s->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();$kind=$_POST['kind']??'';
  try{
    if($kind==='team'){
      $n=(int)($_POST['frc_team_number']??0);if($n<=0)throw new RuntimeException('Enter a valid FRC team number.');
      $pdo->prepare('INSERT INTO teams(organization_id,frc_team_number,nickname,display_name,tba_key) VALUES(?,?,?,?,?)')->execute([$org,$n,trim($_POST['nickname']??'')?:null,trim($_POST['display_name']??'')?:null,'frc'.$n]);$msg='Team added to '.$u['organization_name'].'.';
    } elseif($kind==='user'){
      $username=trim($_POST['username']??'');$display=trim($_POST['display_name']??'');$password=$_POST['password']??'';
      if($username===''||$display===''||strlen($password)<10)throw new RuntimeException('Username and display name are required. Temporary passwords must be at least 10 characters.');
      $role=$_POST['role']??'scout';if(!in_array($role,['scout','strategy','admin','owner'],true))$role='scout';
      $hash=password_hash($password,PASSWORD_DEFAULT);
      $pdo->prepare('INSERT INTO users(organization_id,username,email,password_hash,display_name,role,must_change_password) VALUES(?,?,?,?,?,?,1)')->execute([$org,$username,trim($_POST['email']??'')?:null,$hash,$display,$role]);$uid=(int)$pdo->lastInsertId();
      $teamId=(int)($_POST['primary_team_id']??0);
      if($teamId){$check=$pdo->prepare('SELECT id FROM teams WHERE id=? AND organization_id=?');$check->execute([$teamId,$org]);if($check->fetchColumn())$pdo->prepare('INSERT INTO user_teams(user_id,team_id,is_primary) VALUES(?,?,1)')->execute([$uid,$teamId]);}
      $msg='User added to '.$u['organization_name'].'.';
    }
  }catch(Throwable $e){$error=$e->getMessage();}
  $s=$pdo->prepare('SELECT * FROM teams WHERE organization_id=? AND active=1 ORDER BY frc_team_number');$s->execute([$org]);$teams=$s->fetchAll();
}
$s=$pdo->prepare("SELECT u.id,u.username,u.email,u.display_name,u.role,u.active,u.must_change_password,t.frc_team_number primary_team FROM users u LEFT JOIN user_teams ut ON ut.user_id=u.id AND ut.is_primary=1 LEFT JOIN teams t ON t.id=ut.team_id WHERE u.organization_id=? ORDER BY u.display_name");$s->execute([$org]);$users=$s->fetchAll();
$pageTitle='Teams & Users';include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between"><div><h1 style="margin-bottom:4px">Teams & Users</h1><div class="muted">You are managing only <b><?=e($u['organization_name'])?></b>. Users created here cannot be placed into another organization.</div></div><a class="btn secondary" href="<?=e(base_url('register.php'))?>"><i class="fa-solid fa-building-circle-arrow-right"></i> New Organization Signup</a></div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<div class="grid">
<div class="card"><h2>Add FRC Team</h2><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="kind" value="team"><label>FRC team number</label><input type="number" name="frc_team_number" required><label>Nickname</label><input name="nickname"><label>Display name</label><input name="display_name"><div class="toolbar"><button><i class="fa-solid fa-plus"></i> Add Team</button></div></form></div>
<div class="card"><h2>Add Organization User</h2><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="kind" value="user"><label>Username</label><input name="username" required><label>Display name</label><input name="display_name" required><label>Email</label><input type="email" name="email"><label>Role</label><select name="role"><option value="scout">Scout</option><option value="strategy">Strategy</option><option value="admin">Admin</option><option value="owner">Owner</option></select><label>Primary FRC team</label><select name="primary_team_id"><option value="">No primary team</option><?php foreach($teams as $t):?><option value="<?=$t['id']?>">#<?=e($t['frc_team_number'].' '.($t['display_name']?:$t['nickname']))?></option><?php endforeach;?></select><label>Temporary password</label><input type="password" name="password" minlength="10" required autocomplete="new-password"><p class="muted" style="margin-top:6px"><i class="fa-solid fa-key"></i> The user will be required to choose a new password immediately after signing in.</p><div class="toolbar"><button><i class="fa-solid fa-user-plus"></i> Add User</button></div></form></div>
</div>
<div class="grid" style="margin-top:16px"><div class="card"><h2>Your Organization Teams</h2><?php if(!$teams):?><p class="muted">No FRC teams added yet.</p><?php endif;?><?php foreach($teams as $t):?><p><b>#<?=e($t['frc_team_number'])?></b> <?=e($t['display_name']?:$t['nickname'])?></p><?php endforeach;?></div><div class="card"><h2>Your Organization Users</h2><div class="table-wrap"><table class="table"><tr><th>Name</th><th>Username</th><th>Role</th><th>Primary team</th><th>Password</th></tr><?php foreach($users as $x):?><tr><td><?=e($x['display_name'])?></td><td><?=e($x['username'])?></td><td><span class="pill"><?=e($x['role'])?></span></td><td><?=$x['primary_team']?'#'.e($x['primary_team']):'—'?></td><td><?php if(!empty($x['must_change_password'])):?><span class="pill warning"><i class="fa-solid fa-key"></i> Change required</span><?php else:?><span class="pill"><i class="fa-solid fa-check"></i> Set</span><?php endif;?></td></tr><?php endforeach;?></table></div></div></div>
<?php include dirname(__DIR__).'/partials_footer.php';
