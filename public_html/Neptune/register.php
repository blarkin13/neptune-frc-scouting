<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,2).'/neptune_secure/public-auth-security.php';

try{
    neptune_platform_ensure_schema($pdo);
    neptune_public_auth_ensure_schema($pdo);
}catch(Throwable $e){
    neptune_public_internal_error('registration_schema',$e);
}

if(current_user()){header('Location: '.base_url('dashboard/index.php'));exit;}

$error='';
$done=false;
$createdSlug='';
$orgName=trim((string)($_POST['organization']??''));
$display=trim((string)($_POST['display_name']??''));
$username=trim((string)($_POST['username']??''));
$email=strtolower(trim((string)($_POST['email']??'')));
$team=(int)($_POST['frc_team_number']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $password=(string)($_POST['password']??'');
    $clientScope='ip:'.neptune_public_client_ip();

    try{
        $hour=neptune_public_rate_status($pdo,'registration_ip_hour',$clientScope,5,3600);
        $day=neptune_public_rate_status($pdo,'registration_ip_day',$clientScope,12,86400);

        if(!$hour['allowed'] || !$day['allowed']){
            $retry=max((int)$hour['retry_after'],(int)$day['retry_after']);
            http_response_code(429);
            $error='Too many organization-registration attempts from this network. Try again in about '.neptune_public_retry_minutes($retry).' minute'.(neptune_public_retry_minutes($retry)===1?'':'s').'.';
        }else{
            // Registration attempts count whether they are valid or not. This
            // prevents bots from using validation failures to bypass throttling.
            neptune_public_rate_hit($pdo,'registration_ip_hour',$clientScope,5,3600,3600);
            neptune_public_rate_hit($pdo,'registration_ip_day',$clientScope,12,86400,86400);

            if($orgName===''||$display===''||$username===''||$email===''||strlen($password)<10){
                $error='Complete the required fields. Passwords must be at least 10 characters.';
            }elseif(strlen($orgName)>160){
                $error='Organization names must be 160 characters or fewer.';
            }elseif(strlen($display)>120){
                $error='Owner display names must be 120 characters or fewer.';
            }elseif(!preg_match('/^[A-Za-z0-9._-]{2,80}$/',$username)){
                $error='Usernames must be 2–80 characters and may use letters, numbers, dots, dashes, and underscores.';
            }elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)){
                $error='Enter a valid owner email address.';
            }elseif($team<0 || $team>99999){
                $error='Enter a valid FRC team number.';
            }else{
                $dup=$pdo->prepare('SELECT id FROM organizations WHERE LOWER(name)=LOWER(?) LIMIT 1');
                $dup->execute([$orgName]);
                if($dup->fetchColumn()){
                    $error='An organization with that name already exists. Ask its owner for access, or use a different organization name.';
                }else{
                    $slug=neptune_public_unique_org_slug($pdo,$orgName);
                    $pdo->beginTransaction();
                    try{
                        $pdo->prepare('INSERT INTO organizations(name,slug) VALUES(?,?)')->execute([$orgName,$slug]);
                        $org=(int)$pdo->lastInsertId();

                        $pdo->prepare(
                            "INSERT INTO users
                                (organization_id,username,email,password_hash,display_name,role,must_change_password,password_changed_at)
                             VALUES(?,?,?,?,?,'owner',0,UTC_TIMESTAMP())"
                        )->execute([
                            $org,
                            $username,
                            $email,
                            password_hash($password,PASSWORD_DEFAULT),
                            $display
                        ]);
                        $uid=(int)$pdo->lastInsertId();

                        if($team>0){
                            $pdo->prepare('INSERT INTO teams(organization_id,frc_team_number,display_name,tba_key) VALUES(?,?,?,?)')
                                ->execute([$org,$team,'FRC '.$team,'frc'.$team]);
                            $tid=(int)$pdo->lastInsertId();
                            $pdo->prepare('INSERT INTO user_teams(user_id,team_id,is_primary) VALUES(?,?,1)')
                                ->execute([$uid,$tid]);
                        }

                        $pdo->commit();
                        $createdSlug=$slug;
                        $done=true;
                    }catch(PDOException $e){
                        if($pdo->inTransaction())$pdo->rollBack();

                        // SQLSTATE 23000 covers unique/constraint conflicts. Never
                        // send database exception text to a public browser.
                        if((string)$e->getCode()==='23000'){
                            $error='That organization or owner account conflicts with an existing Neptune record. Try a different organization name or username.';
                        }else{
                            neptune_public_internal_error('registration_database',$e,[
                                'organization_name_length'=>strlen($orgName),
                                'username_length'=>strlen($username),
                            ]);
                        }
                    }catch(Throwable $e){
                        if($pdo->inTransaction())$pdo->rollBack();
                        neptune_public_internal_error('registration_create',$e,[
                            'organization_name_length'=>strlen($orgName),
                            'username_length'=>strlen($username),
                        ]);
                    }
                }
            }
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        neptune_public_internal_error('registration',$e);
    }
}

$pageTitle='Organization Signup';
include __DIR__.'/partials_header.php';
?>
<div class="card auth-card">
  <div class="auth-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune" class="auth-logo"></div>
  <h1 class="auth-title">Create a Neptune Organization</h1>
  <p class="muted auth-subtitle">For a new school, club, or robotics organization joining the platform.</p>

  <?php if($done):?>
    <div class="notice good">
      <b>Organization created.</b>
      <div style="margin-top:6px">Your Neptune organization is <code style="user-select:all"><?=e($createdSlug)?></code>. Use that slug or your full organization name when signing in with a password.</div>
      <div style="margin-top:10px"><a href="<?=e(base_url('index.php'))?>">Sign in to Neptune</a></div>
    </div>
  <?php else:?>
    <?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">

      <label>Organization name</label>
      <input name="organization" maxlength="160" required placeholder="Example Robotics Academy" value="<?=e($orgName)?>">

      <label>FRC team number <span class="muted">(optional)</span></label>
      <input type="number" name="frc_team_number" min="1" max="99999" value="<?=$team>0?(int)$team:''?>">

      <label>Owner display name</label>
      <input name="display_name" maxlength="120" required value="<?=e($display)?>">

      <label>Owner username</label>
      <input name="username" maxlength="80" required autocomplete="username" pattern="[A-Za-z0-9._-]{2,80}" value="<?=e($username)?>">

      <label>Owner email</label>
      <input type="email" name="email" maxlength="190" required autocomplete="email" value="<?=e($email)?>">
      <div class="muted" style="font-size:.82rem;margin-top:-4px;margin-bottom:8px">Required for account recovery and Google account linking.</div>

      <label>Password</label>
      <input type="password" name="password" minlength="10" required autocomplete="new-password">

      <div class="toolbar"><button><i class="fa-solid fa-rocket"></i> Create Organization</button></div>
    </form>
  <?php endif;?>

  <div class="public-links"><a href="<?=e(base_url('index.php'))?>">Back to sign in</a></div>
</div>
<?php include __DIR__.'/partials_footer.php';
