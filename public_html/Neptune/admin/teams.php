<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
$teamLogoHelper=dirname(__DIR__).'/analytics/_team_logo_cache.php';
if(is_file($teamLogoHelper)) require_once $teamLogoHelper;

$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];
$msg='';$error='';$issuedPassword='';$issuedInviteUrl='';
neptune_auth_ensure_schema($pdo);

function ntu_load_teams(PDO $pdo,int $org):array{
    $s=$pdo->prepare('SELECT * FROM teams WHERE organization_id=? AND active=1 ORDER BY frc_team_number');$s->execute([$org]);return $s->fetchAll();
}
function ntu_team(PDO $pdo,int $org,int $id):?array{
    $s=$pdo->prepare('SELECT * FROM teams WHERE organization_id=? AND id=? LIMIT 1');$s->execute([$org,$id]);return $s->fetch()?:null;
}
function ntu_user(PDO $pdo,int $org,int $id):?array{
    $s=$pdo->prepare('SELECT * FROM users WHERE organization_id=? AND id=? LIMIT 1');$s->execute([$org,$id]);return $s->fetch()?:null;
}
function ntu_active_owner_count(PDO $pdo,int $org):int{
    $s=$pdo->prepare("SELECT COUNT(*) FROM users WHERE organization_id=? AND role='owner' AND active=1");$s->execute([$org]);return (int)$s->fetchColumn();
}
function ntu_role_rank(string $role):int{
    return match($role){
        'scout'=>1,
        'strategy'=>2,
        'admin'=>3,
        'owner'=>4,
        default=>0,
    };
}
function ntu_assert_assignable_role(array $actor,string $role):void{
    if(ntu_role_rank($role)<=0)throw new RuntimeException('Invalid role.');
    if(ntu_role_rank($role)>ntu_role_rank((string)($actor['role']??''))){
        throw new RuntimeException('You cannot assign a role higher than your own.');
    }
}
function ntu_assert_manage(array $actor,array $target,bool $allowSelfProfile=false):void{
    $actorId=(int)($actor['id']??0);
    $targetId=(int)($target['id']??0);
    if($actorId>0 && $actorId===$targetId){
        if($allowSelfProfile)return;
        throw new RuntimeException('Use your own account settings for this action.');
    }
    if(ntu_role_rank((string)($target['role']??''))>=ntu_role_rank((string)($actor['role']??''))){
        throw new RuntimeException('You can only manage users below your own access level.');
    }
}
function ntu_set_primary_team(PDO $pdo,int $org,int $uid,int $teamId):void{
    $pdo->prepare('UPDATE user_teams SET is_primary=0 WHERE user_id=?')->execute([$uid]);
    if($teamId<=0)return;
    $s=$pdo->prepare('SELECT id FROM teams WHERE id=? AND organization_id=? AND active=1');$s->execute([$teamId,$org]);
    if(!$s->fetchColumn())throw new RuntimeException('Primary team does not belong to this organization.');
    $pdo->prepare('INSERT INTO user_teams(user_id,team_id,is_primary) VALUES(?,?,1) ON DUPLICATE KEY UPDATE is_primary=1')->execute([$uid,$teamId]);
}
function ntu_absolute_url(string $path):string{
    $redirect=neptune_google_expected_redirect_uri();$parts=parse_url($redirect);
    if(is_array($parts)&&!empty($parts['scheme'])&&!empty($parts['host'])){
        $origin=$parts['scheme'].'://'.$parts['host'].(!empty($parts['port'])?':'.(int)$parts['port']:'');
        return $origin.base_url($path);
    }
    return base_url($path);
}

$teams=ntu_load_teams($pdo,$org);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$kind=(string)($_POST['kind']??'');
    try{
        if($kind==='workspace_settings'){
            if($u['role']!=='owner')throw new RuntimeException('Only an organization owner can change authentication settings.');
            $mode=(string)($_POST['google_signin_mode']??'optional');
            if(!in_array($mode,['disabled','optional','required'],true))throw new RuntimeException('Invalid Google sign-in mode.');
            $domain=neptune_google_normalize_domain((string)($_POST['google_workspace_domain']??''));
            $auto=!empty($_POST['google_auto_provision'])?1:0;
            if($auto && $domain==='')throw new RuntimeException('Enter a Workspace domain before enabling automatic user creation.');
            if($mode==='required'&&!neptune_google_enabled())throw new RuntimeException('Google OAuth must be configured before Google sign-in can be required.');
            $pdo->prepare('UPDATE organizations SET google_signin_mode=?,google_workspace_domain=?,google_auto_provision=? WHERE id=?')->execute([$mode,$domain?:null,$auto,$org]);
            $msg='Google authentication settings updated.';
            neptune_auth_audit($pdo,$org,(int)$u['id'],'google_policy_updated','organization',(string)$org,['mode'=>$mode,'workspace_domain'=>$domain,'auto_provision'=>$auto]);
        } elseif($kind==='create_invite'){
            if(!neptune_google_enabled())throw new RuntimeException('Google OAuth must be configured before creating invitations.');
            $policyNow=neptune_google_org_policy($pdo,$org);
            if($policyNow['mode']==='disabled')throw new RuntimeException('Enable Google sign-in for this organization before creating invitations.');
            $hours=(int)($_POST['expires_hours']??48);if(!in_array($hours,[24,48,72,168],true))$hours=48;
            $label=trim((string)($_POST['invite_label']??''));if(strlen($label)>120)$label=substr($label,0,120);
            $inviteEmail=strtolower(trim((string)($_POST['invite_email']??'')));
            if($inviteEmail!==''&&!filter_var($inviteEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid Google email address for the invitation restriction.');
            $token=neptune_auth_invite_generate_token();$hash=neptune_auth_invite_token_hash($token);$expiresAt=gmdate('Y-m-d H:i:s',time()+($hours*3600));
            $pdo->prepare("INSERT INTO neptune_auth_invites(organization_id,token_hash,label,invite_email,role,created_by_user_id,expires_at) VALUES(?,?,?,?,'scout',?,?)")
                ->execute([$org,$hash,$label?:null,$inviteEmail?:null,(int)$u['id'],$expiresAt]);
            $inviteId=(int)$pdo->lastInsertId();$issuedInviteUrl=ntu_absolute_url('join.php?token='.rawurlencode($token));
            $msg='One-time Scout invitation created. Copy the link below now; Neptune stores only a hash and cannot show this same link again.';
            neptune_auth_audit($pdo,$org,(int)$u['id'],'organization_invite_created','invite',(string)$inviteId,['expires_hours'=>$hours,'label'=>$label?:null,'invite_email'=>$inviteEmail?:null,'role'=>'scout']);
        } elseif($kind==='revoke_invite'){
            $inviteId=(int)($_POST['invite_id']??0);if($inviteId<=0)throw new RuntimeException('Invitation not found.');
            $st=$pdo->prepare('UPDATE neptune_auth_invites SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=? AND used_at IS NULL AND revoked_at IS NULL');$st->execute([$inviteId,$org]);
            if($st->rowCount()!==1)throw new RuntimeException('That invitation is already used, revoked, or unavailable.');
            $msg='Invitation revoked.';neptune_auth_audit($pdo,$org,(int)$u['id'],'organization_invite_revoked','invite',(string)$inviteId);
        } elseif($kind==='team'){
            if(!in_array((string)$u['role'],['owner','admin'],true))throw new RuntimeException('Only an Admin or Owner can add FRC teams.');
            $n=(int)($_POST['frc_team_number']??0);if($n<=0)throw new RuntimeException('Enter a valid FRC team number.');
            $dupe=$pdo->prepare('SELECT id FROM teams WHERE organization_id=? AND frc_team_number=? LIMIT 1');$dupe->execute([$org,$n]);
            if($dupe->fetchColumn())throw new RuntimeException('FRC team #'.$n.' is already in this organization.');
            $nickname=trim((string)($_POST['nickname']??''));$displayName=trim((string)($_POST['display_name']??''));
            $pdo->prepare('INSERT INTO teams(organization_id,frc_team_number,nickname,display_name,tba_key) VALUES(?,?,?,?,?)')->execute([$org,$n,$nickname?:null,$displayName?:null,'frc'.$n]);
            $teamId=(int)$pdo->lastInsertId();
            $msg='Team added to '.$u['organization_name'].'.';
            neptune_auth_audit($pdo,$org,(int)$u['id'],'team_created','team',(string)$teamId,['frc_team_number'=>$n,'nickname'=>$nickname?:null,'display_name'=>$displayName?:null]);
        } elseif($kind==='update_team'){
            if(!in_array((string)$u['role'],['owner','admin'],true))throw new RuntimeException('Only an Admin or Owner can edit FRC teams.');
            $teamId=(int)($_POST['team_id']??0);$team=ntu_team($pdo,$org,$teamId);
            if(!$team)throw new RuntimeException('Team not found.');
            $n=(int)($_POST['frc_team_number']??0);if($n<=0)throw new RuntimeException('Enter a valid FRC team number.');
            $dupe=$pdo->prepare('SELECT id FROM teams WHERE organization_id=? AND frc_team_number=? AND id<>? LIMIT 1');$dupe->execute([$org,$n,$teamId]);
            if($dupe->fetchColumn())throw new RuntimeException('FRC team #'.$n.' is already in this organization.');
            $nickname=trim((string)($_POST['nickname']??''));$displayName=trim((string)($_POST['display_name']??''));
            $pdo->prepare('UPDATE teams SET frc_team_number=?,nickname=?,display_name=?,tba_key=? WHERE id=? AND organization_id=?')
                ->execute([$n,$nickname?:null,$displayName?:null,'frc'.$n,$teamId,$org]);
            $msg='Team #'.$n.' updated.';
            neptune_auth_audit($pdo,$org,(int)$u['id'],'team_updated','team',(string)$teamId,[
                'old'=>[
                    'frc_team_number'=>(int)$team['frc_team_number'],
                    'nickname'=>$team['nickname']??null,
                    'display_name'=>$team['display_name']??null,
                ],
                'new'=>[
                    'frc_team_number'=>$n,
                    'nickname'=>$nickname?:null,
                    'display_name'=>$displayName?:null,
                ],
            ]);
        } elseif($kind==='user'){
            $username=trim((string)($_POST['username']??''));$display=trim((string)($_POST['display_name']??''));$email=strtolower(trim((string)($_POST['email']??'')));
            if($username===''||$display==='')throw new RuntimeException('Username and display name are required.');
            if(!preg_match('/^[A-Za-z0-9._-]{2,80}$/',$username))throw new RuntimeException('Username may use letters, numbers, dots, dashes, and underscores.');
            if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
            $role=(string)($_POST['role']??'scout');
            ntu_assert_assignable_role($u,$role);
            $password=trim((string)($_POST['password']??''));if($password==='')$password=neptune_random_temp_password();
            if(strlen($password)<10)throw new RuntimeException('Temporary passwords must be at least 10 characters.');
            $pdo->prepare('INSERT INTO users(organization_id,username,email,password_hash,display_name,role,must_change_password) VALUES(?,?,?,?,?,?,1)')->execute([$org,$username,$email?:null,password_hash($password,PASSWORD_DEFAULT),$display,$role]);
            $uid=(int)$pdo->lastInsertId();ntu_set_primary_team($pdo,$org,$uid,(int)($_POST['primary_team_id']??0));
            $issuedPassword=$password;$msg='User added. Give the temporary password below to '.$display.'.';
            neptune_auth_audit($pdo,$org,(int)$u['id'],'user_created','user',(string)$uid,['role'=>$role]);
        } elseif(in_array($kind,['update_user','reset_password','unlink_google'],true)){
            $uid=(int)($_POST['user_id']??0);$target=ntu_user($pdo,$org,$uid);if(!$target)throw new RuntimeException('User not found.');
            if($kind==='update_user'){
                ntu_assert_manage($u,$target,true);
                $username=trim((string)($_POST['username']??''));$display=trim((string)($_POST['display_name']??''));$email=strtolower(trim((string)($_POST['email']??'')));
                $isSelf=(int)$target['id']===(int)$u['id'];
                $role=$isSelf?(string)$target['role']:(string)($_POST['role']??$target['role']);
                $active=$isSelf?(int)$target['active']:(!empty($_POST['active'])?1:0);
                if($username===''||$display==='')throw new RuntimeException('Username and display name are required.');
                if(!preg_match('/^[A-Za-z0-9._-]{2,80}$/',$username))throw new RuntimeException('Username may use letters, numbers, dots, dashes, and underscores.');
                if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
                ntu_assert_assignable_role($u,$role);
                if(!$isSelf && ntu_role_rank((string)$target['role'])>=ntu_role_rank((string)$u['role']))throw new RuntimeException('You can only manage users below your own access level.');
                if($target['role']==='owner' && (int)$target['active']===1 && ($role!=='owner'||$active!==1) && ntu_active_owner_count($pdo,$org)<=1)throw new RuntimeException('You cannot remove or deactivate the last active owner.');
                $pdo->prepare('UPDATE users SET username=?,email=?,display_name=?,role=?,active=? WHERE id=? AND organization_id=?')->execute([$username,$email?:null,$display,$role,$active,$uid,$org]);
                ntu_set_primary_team($pdo,$org,$uid,(int)($_POST['primary_team_id']??0));
                $msg='User updated.';
                neptune_auth_audit($pdo,$org,(int)$u['id'],'user_updated','user',(string)$uid,['role'=>$role,'active'=>$active]);
            } elseif($kind==='reset_password'){
                ntu_assert_manage($u,$target,false);
                $password=neptune_random_temp_password();
                $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=1,password_changed_at=NULL WHERE id=? AND organization_id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$uid,$org]);
                $issuedPassword=$password;$msg='Temporary password reset for '.$target['display_name'].'. Their Google sign-in, if linked, still works.';
                neptune_auth_audit($pdo,$org,(int)$u['id'],'password_reset_by_admin','user',(string)$uid);
            } elseif($kind==='unlink_google'){
                ntu_assert_manage($u,$target,false);
                $pdo->prepare('UPDATE users SET google_sub=NULL,google_email=NULL,google_linked_at=NULL WHERE id=? AND organization_id=?')->execute([$uid,$org]);
                $msg='Google account unlinked from '.$target['display_name'].'.';
                neptune_auth_audit($pdo,$org,(int)$u['id'],'google_account_unlinked','user',(string)$uid);
            }
        }
    }catch(Throwable $e){
        $m=$e->getMessage();if(str_contains(strtolower($m),'duplicate'))$m='That username or email is already in use in this organization.';$error=$m;
    }
    $teams=ntu_load_teams($pdo,$org);
}

$policy=neptune_google_org_policy($pdo,$org);
$s=$pdo->prepare("SELECT u.id,u.username,u.email,u.display_name,u.role,u.active,u.must_change_password,u.last_login_at,u.google_sub,u.google_email,u.google_linked_at,t.id primary_team_id,t.frc_team_number primary_team,
EXISTS(SELECT 1 FROM audit_log al WHERE al.organization_id=u.organization_id AND al.user_id=u.id AND al.action='google_user_auto_provisioned') AS google_auto_provisioned,
EXISTS(SELECT 1 FROM audit_log al WHERE al.organization_id=u.organization_id AND al.user_id=u.id AND al.action='google_user_invited') AS google_invited
FROM users u
LEFT JOIN user_teams ut ON ut.user_id=u.id AND ut.is_primary=1
LEFT JOIN teams t ON t.id=ut.team_id
WHERE u.organization_id=?
ORDER BY u.active DESC,u.display_name");$s->execute([$org]);$users=$s->fetchAll();
$googleEnabled=neptune_google_enabled();$googleRedirect=neptune_google_expected_redirect_uri();
$iv=$pdo->prepare("SELECT i.*,cu.display_name created_by_name,uu.display_name used_by_name
                   FROM neptune_auth_invites i
                   LEFT JOIN users cu ON cu.id=i.created_by_user_id
                   LEFT JOIN users uu ON uu.id=i.used_by_user_id
                   WHERE i.organization_id=? ORDER BY i.id DESC LIMIT 20");$iv->execute([$org]);$invites=$iv->fetchAll();
$activeUsers=count(array_filter($users,fn($x)=>!empty($x['active'])));$googleUsers=count(array_filter($users,fn($x)=>!empty($x['google_sub'])));
$teamNumbers=array_map(fn($t)=>(int)($t['frc_team_number']??0),$teams);
$teamLogoMap=function_exists('neptune_team_logo_map')?neptune_team_logo_map($org,(int)date('Y'),$teamNumbers):[];
$pageTitle='Teams & Users';$moduleName='SATURN';include dirname(__DIR__).'/partials_header.php';
?>
<style>
.ntu-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap}
.ntu-actions{display:flex;gap:8px;flex-wrap:wrap}
.ntu-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}
.ntu-stat{padding:16px;border:1px solid var(--line);border-radius:16px;background:linear-gradient(180deg,color-mix(in srgb,var(--panel) 88%,white 12%),var(--panel));box-shadow:0 10px 24px rgba(0,0,0,.08)}
.ntu-stat b{font-size:1.6rem;display:block}.ntu-stat span{color:var(--muted);font-size:.82rem}
.ntu-auth-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(300px,.7fr);gap:16px;margin-top:16px}
.ntu-policy{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.ntu-user-table td{vertical-align:middle}
.ntu-user-main{display:flex;gap:10px;align-items:center}
.ntu-avatar{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;background:linear-gradient(180deg,color-mix(in srgb,var(--accent) 18%,white 82%),color-mix(in srgb,var(--accent) 12%,var(--panel) 88%));border:1px solid color-mix(in srgb,var(--accent) 28%,var(--line));font-weight:900;box-shadow:inset 0 1px 0 rgba(255,255,255,.55)}
.ntu-sub{font-size:.82rem;color:var(--muted);margin-top:2px}.ntu-signins{display:flex;gap:6px;flex-wrap:wrap}
.ntu-google-status{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.ntu-help{font-size:.83rem;color:var(--muted);line-height:1.5}
.ntu-card-title{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.ntu-brand-inline{display:flex;align-items:center;gap:10px}.ntu-brand-inline img{width:34px;height:34px;object-fit:contain;border-radius:10px;padding:6px;background:#fff;border:1px solid var(--line)}
.ntu-tech-box{margin-top:16px;padding:12px 14px;border:1px dashed var(--line);border-radius:14px;background:color-mix(in srgb,var(--panel) 82%,white 18%)}
.ntu-tech-box code{display:block;white-space:normal;word-break:break-word;font-size:.86rem;margin-top:4px}
.ntu-team-panel{container-type:inline-size}
.ntu-team-grid{display:grid;grid-template-columns:1fr;gap:14px;margin-top:10px}
.ntu-team-card{position:relative;display:grid;grid-template-columns:62px minmax(0,1fr) auto;gap:12px;align-items:flex-start;padding:16px;border:1px solid var(--line);border-radius:18px;background:linear-gradient(180deg,color-mix(in srgb,var(--panel) 92%,white 8%),color-mix(in srgb,var(--panel) 70%,var(--panel) 30%));box-shadow:0 12px 26px rgba(0,0,0,.08);overflow:hidden}
.ntu-team-card::after{content:"";position:absolute;inset:auto -20px -26px auto;width:90px;height:90px;background:radial-gradient(circle,color-mix(in srgb,var(--accent) 14%,transparent 86%) 0%,transparent 68%)}
.ntu-team-logo{width:62px;height:62px;display:grid;place-items:center;border-radius:16px;background:#fff;border:1px solid var(--line);overflow:hidden;box-shadow:0 8px 16px rgba(0,0,0,.08)}
.ntu-team-logo img{width:100%;height:100%;object-fit:contain;padding:6px}.ntu-team-logo i{font-size:1.5rem;color:var(--muted)}
.ntu-team-meta{position:relative;z-index:1;min-width:0}.ntu-team-meta strong{display:block;font-size:1.35rem;line-height:1}.ntu-team-meta .ntu-team-name{font-size:1.05rem;font-weight:850;margin-top:4px;overflow-wrap:anywhere}.ntu-team-meta .ntu-team-copy{font-size:.82rem;color:var(--muted);margin-top:8px;line-height:1.45}.ntu-team-badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.ntu-team-actions{position:relative;z-index:2;display:flex;align-items:flex-start;justify-self:end}.ntu-team-actions .secondary{white-space:nowrap}
@container (min-width:760px){.ntu-team-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@container (max-width:420px){.ntu-team-card{grid-template-columns:54px minmax(0,1fr);padding:14px}.ntu-team-logo{width:54px;height:54px}.ntu-team-actions{grid-column:2;justify-self:start;margin-top:2px}}
.ntu-empty{padding:18px;border:1px dashed var(--line);border-radius:16px;background:var(--panel);color:var(--muted)}

.ntu-modal{width:min(780px,calc(100vw - 28px));max-height:92vh;border:1px solid var(--line);border-radius:24px;background:var(--panel)!important;color:var(--text);padding:0;box-shadow:0 28px 90px rgba(0,0,0,.55);overflow:hidden;opacity:1!important}
.ntu-modal[open]{display:flex;flex-direction:column}
.ntu-modal::backdrop{background:rgba(7,12,20,.62);backdrop-filter:blur(7px)}
.ntu-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:20px 22px;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:2;background:var(--panel)!important;overflow:hidden;opacity:1!important}
.ntu-modal-head::after{content:"";position:absolute;inset:auto -35px -35px auto;width:120px;height:120px;background:radial-gradient(circle,color-mix(in srgb,var(--accent) 20%,transparent 80%) 0%,transparent 70%);pointer-events:none}
.ntu-modal-head h2{margin:0;line-height:1.05}
.ntu-modal-head small{display:block;color:var(--muted);font-size:.78rem;letter-spacing:.11em;text-transform:uppercase;font-weight:800}
.ntu-modal-hero{display:flex;gap:12px;align-items:flex-start;min-width:0;position:relative;z-index:1}
.ntu-modal-brand{width:52px;height:52px;border-radius:16px;background:#fff;border:1px solid color-mix(in srgb,var(--accent) 18%,var(--line));display:grid;place-items:center;box-shadow:0 8px 18px rgba(0,0,0,.08);overflow:hidden;flex:0 0 52px}
.ntu-modal-brand img{width:100%;height:100%;object-fit:contain;padding:7px}
.ntu-modal-sub{color:var(--muted);margin-top:4px;font-size:.92rem}
.ntu-modal-badges{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.ntu-modal-body{padding:22px;overflow-y:auto;overflow-x:hidden;min-height:0;background:var(--panel)!important;opacity:1!important}
.ntu-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.ntu-form-grid .full{grid-column:1/-1}
.ntu-modal-section{padding:16px;border:1px solid var(--line);border-radius:18px;background:var(--panel2)!important;box-shadow:0 10px 22px rgba(0,0,0,.08);opacity:1!important}
.ntu-modal-section + .ntu-modal-section{margin-top:14px}
.ntu-section-title{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 14px}.ntu-section-title h3{margin:0;font-size:1rem}.ntu-section-title .muted{font-size:.82rem}
.ntu-modal-foot{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:20px;padding-top:18px;border-top:1px solid var(--line)}
.ntu-modal-foot.ntu-save-bar{position:sticky;bottom:-22px;z-index:4;margin:20px -22px -22px;padding:14px 22px;background:var(--panel)!important;box-shadow:0 -12px 26px rgba(0,0,0,.18)}
.ntu-icon-btn{border:0;background:color-mix(in srgb,var(--panel) 60%,transparent 40%);color:var(--muted);font-size:1.2rem;padding:8px 10px;border-radius:12px;cursor:pointer;backdrop-filter:blur(2px)}
.ntu-icon-btn:hover{background:color-mix(in srgb,var(--panel) 85%,white 15%);color:var(--text)}
.ntu-status-card{display:flex;gap:12px;align-items:flex-start;padding:14px;border-radius:16px;border:1px solid var(--line);background:linear-gradient(180deg,color-mix(in srgb,var(--panel) 72%,white 28%),color-mix(in srgb,var(--panel) 80%,white 20%))}
.ntu-status-card i{font-size:1.15rem;margin-top:2px}.ntu-status-card strong{display:block}.ntu-status-card .muted{display:block;margin-top:2px}
.ntu-status-card.good{border-color:color-mix(in srgb,var(--good, #22a06b) 35%,var(--line));box-shadow:inset 4px 0 0 color-mix(in srgb,var(--good, #22a06b) 75%,transparent 25%)}
.ntu-status-card.neutral{box-shadow:inset 4px 0 0 color-mix(in srgb,var(--accent) 65%,transparent 35%)}
.ntu-status-action{width:100%;color:var(--text);text-align:left;font:inherit;cursor:pointer;appearance:none}
.ntu-status-action:hover{border-color:color-mix(in srgb,var(--accent) 58%,var(--line));transform:translateY(-1px);box-shadow:inset 4px 0 0 var(--accent),0 8px 18px rgba(0,0,0,.12)}
.ntu-status-action:focus-visible{outline:3px solid color-mix(in srgb,var(--accent) 40%,transparent 60%);outline-offset:2px}
.ntu-status-action .ntu-status-arrow{margin-left:auto;color:var(--muted);align-self:center}
.ntu-status-row{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center}
.ntu-danger{display:flex;gap:8px;flex-wrap:wrap}.ntu-modal form{margin:0}
.ntu-add-copy{margin-top:6px;color:var(--muted);max-width:60ch}
.ntu-mini-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.ntu-invite-card{overflow:hidden}.ntu-invite-table td{vertical-align:middle}.ntu-invite-table form{margin:0}.ntu-copy-flash{font-size:.8rem;color:var(--good,#22a06b);font-weight:800}
.ntu-org-login-card{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap;margin-top:16px}
.ntu-org-login-copy h2{margin:0 0 4px}.ntu-org-login-copy p{margin:0;color:var(--muted);max-width:68ch}
.ntu-org-slug{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 12px;border:1px solid var(--line);border-radius:14px;background:var(--panel2)}
.ntu-org-slug code{font-size:.95rem;font-weight:850;user-select:all}

html[data-theme="light"] .ntu-modal,html[data-theme="light"] .ntu-modal-head,html[data-theme="light"] .ntu-modal-body{background:#ffffff!important}
html:not([data-theme="light"]) .ntu-modal,html:not([data-theme="light"]) .ntu-modal-head,html:not([data-theme="light"]) .ntu-modal-body{background:var(--panel)!important}
html[data-theme="light"] .ntu-modal-section{background:var(--panel2)!important}
html:not([data-theme="light"]) .ntu-modal-section{background:var(--panel2)!important}

@media(max-width:980px){.ntu-auth-grid{grid-template-columns:1fr}.ntu-policy{grid-template-columns:1fr}}
@media(max-width:850px){.ntu-stats{grid-template-columns:repeat(2,1fr)}.ntu-form-grid,.ntu-mini-grid{grid-template-columns:1fr}.ntu-form-grid .full{grid-column:auto}}
@media(max-width:640px){.ntu-modal{width:min(100vw - 16px,780px);border-radius:18px}.ntu-modal-body{padding:16px}.ntu-modal-head{padding:16px}.ntu-modal-brand{width:44px;height:44px;border-radius:14px}.ntu-modal-badges{margin-top:8px}.ntu-team-card{padding:14px}.ntu-team-logo{width:54px;height:54px;flex-basis:54px}.ntu-modal-foot.ntu-save-bar{bottom:-16px;margin:20px -16px -16px;padding:12px 16px}}
@media(max-width:540px){.ntu-stats{grid-template-columns:1fr 1fr}}
</style>
<section class="neptune-page-hero ntu-head"><div><div class="neptune-hero-kicker"><i class="fa-solid fa-users-gear"></i> SATURN · ORGANIZATION ACCESS</div><h1>Teams &amp; Users</h1><p>Manage people, teams, sign-in, and access for <b><?=e($u['organization_name'])?></b>. You can assign roles up to your own level, while account-control actions stay limited to users below your level.</p><div class="neptune-hero-pills"><span class="neptune-hero-pill"><i class="fa-solid fa-shield-halved"></i> <strong><?=e(ucfirst((string)$u['role']))?></strong> access</span><span class="neptune-hero-pill"><i class="fa-solid fa-users"></i> <?=$activeUsers?> active users</span><span class="neptune-hero-pill"><i class="fa-solid fa-robot"></i> <?=count($teams)?> FRC teams</span><span class="neptune-hero-pill"><i class="fa-solid fa-at"></i> Sign-in slug: <strong><?=e($policy['slug'])?></strong></span></div></div><div class="ntu-actions"><?php if(in_array($u['role'],['owner','admin'],true)):?><button type="button" class="btn secondary" data-open="teamDialog"><i class="fa-solid fa-plus"></i> Add Team</button><?php endif;?><button type="button" class="btn" data-open="userDialog"><i class="fa-solid fa-user-plus"></i> Add User</button></div></section>
<?php if($msg):?><div class="notice good" style="margin-top:14px"><?=e($msg)?></div><?php endif;?><?php if($issuedPassword):?><div class="notice warning"><b>Temporary password — shown once:</b> <code style="user-select:all"><?=e($issuedPassword)?></code></div><?php endif;?><?php if($issuedInviteUrl):?><div class="notice good"><b>Invitation link — shown once:</b><br><code style="user-select:all;word-break:break-all"><?=e($issuedInviteUrl)?></code><div class="toolbar" style="margin-top:10px"><button type="button" class="secondary" data-copy-invite="<?=e($issuedInviteUrl)?>"><i class="fa-solid fa-copy"></i> Copy Invite Link</button></div></div><?php endif;?><?php if($error):?><div class="notice bad" style="margin-top:14px"><?=e($error)?></div><?php endif;?>

<div class="ntu-stats"><div class="ntu-stat"><b><?=count($users)?></b><span>Total users</span></div><div class="ntu-stat"><b><?=$activeUsers?></b><span>Active users</span></div><div class="ntu-stat"><b><?=$googleUsers?></b><span>Google linked</span></div><div class="ntu-stat"><b><?=count($teams)?></b><span>FRC teams</span></div></div>

<div class="card ntu-org-login-card">
  <div class="ntu-org-login-copy">
    <h2><i class="fa-solid fa-right-to-bracket"></i> Organization Sign-In</h2>
    <p>Password users can sign in with the full organization name or the Neptune slug. The slug is the simplest value to give scouts and mentors.</p>
  </div>
  <div class="ntu-org-slug">
    <span class="muted">Slug</span>
    <code><?=e($policy['slug'])?></code>
    <button type="button" class="secondary" data-copy-org-slug="<?=e($policy['slug'])?>"><i class="fa-solid fa-copy"></i> Copy</button>
  </div>
</div>

<div class="ntu-auth-grid">
  <div class="card">
    <div class="ntu-card-title">
      <div class="ntu-brand-inline"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><div><h2 style="margin:0">Google Sign-In</h2><div class="muted">Google works for existing matching accounts even without Google Workspace.</div></div></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><span class="pill"><?=$googleEnabled?'OAuth configured':'OAuth not configured'?></span><span class="pill"><?=e(ucfirst($policy['mode']))?></span></div>
    </div>
    <?php if($u['role']==='owner'):?>
    <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="kind" value="workspace_settings">
      <div class="ntu-policy">
        <div>
          <label>Google sign-in</label>
          <select name="google_signin_mode"><option value="disabled" <?=$policy['mode']==='disabled'?'selected':''?>>Disabled</option><option value="optional" <?=$policy['mode']==='optional'?'selected':''?>>Optional</option><option value="required" <?=$policy['mode']==='required'?'selected':''?>>Required</option></select>
          <div class="ntu-help">Optional keeps password sign-in available. Required makes Google the sign-in method for this organization.</div>
        </div>
        <div>
          <label>Workspace domain <span class="muted">(optional)</span></label>
          <input name="google_workspace_domain" value="<?=e($policy['workspace_domain'])?>" placeholder="example.org" autocapitalize="none" spellcheck="false">
          <div class="ntu-help">Only needed for automatic account creation. Existing Neptune users can sign in with any matching Google account. For Workspace auto-creation, Neptune verifies Google's hosted-domain value.</div>
        </div>
        <div class="full">
          <label style="display:flex;gap:9px;align-items:flex-start"><input type="checkbox" name="google_auto_provision" value="1" <?=$policy['auto_provision']?'checked':''?> style="width:auto;margin-top:3px"><span><b>Automatically create approved Workspace users</b><br><span class="ntu-help">A first-time user from the approved Workspace domain is created as <b>Scout</b>. Admin, Strategy, and Owner roles must still be assigned manually.</span></span></label>
        </div>
      </div>
      <div class="ntu-tech-box">
        <b>Technical details</b>
        <div class="ntu-help">Use this exact callback in Google Cloud.</div>
        <code><?=e($googleRedirect?:'Google OAuth not configured')?></code>
      </div>
      <div class="toolbar" style="margin-top:14px"><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Authentication Settings</button></div>
    </form>
    <?php else:?><p class="muted">Only an organization owner can change authentication policy.</p><?php endif;?>
  </div>
  <div class="card ntu-team-panel">
    <div class="ntu-card-title">
      <div class="ntu-brand-inline"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><div><h2 style="margin:0">Organization Teams</h2><div class="muted">Brand-aware team cards using your existing team logos. Admins and Owners can edit team identity from each card.</div></div></div>
    </div>
    <?php if(!$teams):?>
      <div class="ntu-empty">No FRC teams added yet. Add a team to start assigning users and showing team identity across Neptune.</div>
    <?php else:?>
      <div class="ntu-team-grid">
        <?php foreach($teams as $t):
          $num=(int)($t['frc_team_number']??0);
          $logo=$teamLogoMap[$num]??['exists'=>false,'path'=>'','version'=>''];
          $logoUrl=!empty($logo['exists'])?base_url((string)$logo['path']).(!empty($logo['version'])?'?v='.rawurlencode((string)$logo['version']):''):'';
          $name=trim((string)($t['display_name']?:$t['nickname']?:('Team '.$num)));
        ?>
        <article class="ntu-team-card">
          <div class="ntu-team-logo"><?php if($logoUrl!==''):?><img src="<?=e($logoUrl)?>" alt="Team <?=e((string)$num)?> logo"><?php else:?><i class="fa-solid fa-robot"></i><?php endif;?></div>
          <div class="ntu-team-meta">
            <strong>#<?=e((string)$num)?></strong>
            <div class="ntu-team-name"><?=e($name)?></div>
            <div class="ntu-team-badges"><span class="pill">Organization team</span><?php if(!empty($t['nickname']) && $t['display_name'] && $t['display_name']!==$t['nickname']):?><span class="pill"><?=e($t['nickname'])?></span><?php endif;?></div>
            <div class="ntu-team-copy">This team is available for user assignment, scouting, and organization-wide Neptune features.</div>
          </div>
          <?php if(in_array((string)$u['role'],['owner','admin'],true)):?>
          <div class="ntu-team-actions">
            <button
              type="button"
              class="secondary ntu-edit-team"
              data-id="<?=(int)$t['id']?>"
              data-number="<?=e((string)$num)?>"
              data-nickname="<?=e((string)($t['nickname']??''))?>"
              data-display="<?=e((string)($t['display_name']??''))?>"
            ><i class="fa-solid fa-pen"></i> Edit</button>
          </div>
          <?php endif;?>
        </article>
        <?php endforeach;?>
      </div>
    <?php endif;?>
  </div>
</div>

<div class="card" style="margin-top:16px"><div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap"><div><h2 style="margin:0">Users</h2><div class="muted">Changes to role or active status take effect on the user's next Neptune request. To recover another user's password, open <b>Manage</b> and click the <b>Password sign-in</b> card to issue a temporary password. <b>Workspace Auto</b> identifies accounts Neptune created automatically on first approved Google Workspace sign-in.</div></div></div><div class="table-wrap" style="margin-top:14px"><table class="table ntu-user-table"><thead><tr><th>User</th><th>Role</th><th>Team</th><th>Sign-in</th><th>Last login</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($users as $x):$isSelf=(int)$x['id']===(int)$u['id'];$canManage=$isSelf||ntu_role_rank((string)$x['role'])<ntu_role_rank((string)$u['role']);$initials='';foreach(preg_split('/\s+/',trim((string)$x['display_name'])) as $part){if($part!=='')$initials.=strtoupper(substr($part,0,1));}$initials=substr($initials,0,2);?>
<tr><td><div class="ntu-user-main"><div class="ntu-avatar"><?=e($initials?:'?')?></div><div><b><?=e($x['display_name'])?></b><div class="ntu-sub">@<?=e($x['username'])?><?php if($x['email']):?> · <?=e($x['email'])?><?php endif;?></div></div></div></td><td><span class="pill"><?=e(ucfirst($x['role']))?></span></td><td><?=$x['primary_team']?'#'.e($x['primary_team']):'—'?></td><td><div class="ntu-signins"><?php if($x['google_sub']):?><?php if(!empty($x['google_auto_provisioned'])):?><span class="pill" title="Created automatically the first time this user signed in with the organization's approved Google Workspace account."><i class="fa-brands fa-google"></i> Workspace Auto</span><?php elseif(!empty($x['google_invited'])):?><span class="pill" title="Created through a one-time organization invitation and verified with Google."><i class="fa-brands fa-google"></i> Google Invite</span><?php else:?><span class="pill" title="Existing Neptune account linked to a verified Google identity."><i class="fa-brands fa-google"></i> Google Linked</span><?php endif;?><?php else:?><span class="muted">Password</span><?php endif;?><?php if(!empty($x['must_change_password'])):?><span class="pill warning"><i class="fa-solid fa-key"></i> Temporary</span><?php endif;?></div></td><td><?=e($x['last_login_at']?:'Never')?></td><td><?=$x['active']?'<span class="pill"><i class="fa-solid fa-check"></i> Active</span>':'<span class="pill warning"><i class="fa-solid fa-ban"></i> Inactive</span>'?></td><td><?php if($canManage):?><button type="button" class="secondary ntu-manage" data-id="<?=(int)$x['id']?>" data-self="<?=$isSelf?'1':'0'?>" data-display="<?=e($x['display_name'])?>" data-username="<?=e($x['username'])?>" data-email="<?=e($x['email']??'')?>" data-role="<?=e($x['role'])?>" data-team="<?=(int)($x['primary_team_id']??0)?>" data-active="<?=$x['active']?'1':'0'?>" data-google-email="<?=e($x['google_email']??'')?>" data-google-linked="<?=$x['google_sub']?'1':'0'?>"><i class="fa-solid fa-pen"></i> <?=$isSelf?'Profile':'Manage'?></button><?php else:?><span class="muted">Protected level</span><?php endif;?></td></tr>
<?php endforeach;?></tbody></table></div></div>


<div class="card ntu-invite-card" style="margin-top:16px">
  <div class="ntu-card-title">
    <div class="ntu-brand-inline"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><div><h2 style="margin:0">Invite by Link <span class="muted" style="font-size:.78rem;font-weight:700">(optional)</span></h2><div class="muted">Use this when you want someone to create their own Scout account with Google instead of creating the user and temporary password yourself.</div></div></div>
    <button type="button" class="secondary" data-open="inviteDialog"><i class="fa-solid fa-link"></i> Create One-Time Invite</button>
  </div>
  <div class="ntu-help">This is an alternative to <b>Add User</b>, not a requirement. The recipient verifies Google, joins only <b><?=e($u['organization_name'])?></b> as <b>Scout</b>, and the link expires after one use. Use <b>Add User</b> when you want to create and configure the account yourself.</div>
  <?php if(!$invites):?><div class="ntu-empty" style="margin-top:14px">No invitations have been created yet.</div><?php else:?><div class="table-wrap" style="margin-top:14px"><table class="table ntu-invite-table"><thead><tr><th>Invite</th><th>Created</th><th>Expires</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach($invites as $inv):$now=time();$exp=strtotime((string)$inv['expires_at']);$status=!empty($inv['used_at'])?'Used':(!empty($inv['revoked_at'])?'Revoked':(($exp!==false&&$exp<=$now)?'Expired':'Active'));?>
    <tr><td><b><?=e($inv['label']?:'Scout invitation')?></b><div class="ntu-sub">Created by <?=e($inv['created_by_name']?:'administrator')?> · One use<?php if(!empty($inv['invite_email'])):?> · <?=e($inv['invite_email'])?><?php endif;?></div></td><td><?=e($inv['created_at'])?></td><td><?=e($inv['expires_at'])?></td><td><span class="pill <?=$status==='Active'?'':'warning'?>"><?=e($status)?></span><?php if($status==='Used'&&!empty($inv['used_by_name'])):?><div class="ntu-sub"><?=e($inv['used_by_name'])?></div><?php endif;?></td><td><?php if($status==='Active'):?><form method="post" data-confirm="Revoke this invitation?" data-confirm-title="Revoke invitation" data-confirm-button="Revoke" data-confirm-danger="1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="kind" value="revoke_invite"><input type="hidden" name="invite_id" value="<?=(int)$inv['id']?>"><button class="secondary" type="submit"><i class="fa-solid fa-ban"></i> Revoke</button></form><?php endif;?></td></tr>
  <?php endforeach;?></tbody></table></div><?php endif;?>
</div>

<dialog class="ntu-modal" id="inviteDialog">
  <div class="ntu-modal-head">
    <div class="ntu-modal-hero"><div class="ntu-modal-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"></div><div><small>Secure onboarding</small><h2>Create Organization Invitation</h2><div class="ntu-add-copy">Creates a one-time Google-verified link for <b><?=e($u['organization_name'])?></b>. The new account starts as Scout.</div></div></div>
    <button type="button" class="ntu-icon-btn" data-close="inviteDialog"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="ntu-modal-body"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="kind" value="create_invite">
    <div class="ntu-modal-section"><div class="ntu-section-title"><h3>Invitation details</h3><span class="muted">One use · Scout access</span></div><div class="ntu-form-grid">
      <div class="full"><label>Label <span class="muted">(optional)</span></label><input name="invite_label" maxlength="120" placeholder="Example: New student — Alex"><div class="ntu-help">Visible only to organization administrators so you can identify the invitation later.</div></div>
      <div class="full"><label>Expected Google email <span class="muted">(recommended)</span></label><input type="email" name="invite_email" placeholder="student@example.org" autocomplete="off"><div class="ntu-help">If entered, only this exact Google account can use the link. Leave blank only when you intentionally want a transferable one-time invitation.</div></div>
      <div><label>Expires after</label><select name="expires_hours"><option value="24">24 hours</option><option value="48" selected>48 hours</option><option value="72">72 hours</option><option value="168">7 days</option></select></div>
      <div><label>Role granted</label><input value="Scout" disabled><div class="ntu-help">Promote the user later if they need Strategy, Admin, or Owner access.</div></div>
    </div></div>
    <div class="ntu-modal-foot"><button type="button" class="secondary" data-close="inviteDialog">Cancel</button><button type="submit"><i class="fa-solid fa-link"></i> Create One-Time Invite</button></div>
  </form></div>
</dialog>

<dialog class="ntu-modal" id="editUserDialog">
  <div class="ntu-modal-head">
    <div class="ntu-modal-hero">
      <div class="ntu-modal-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"></div>
      <div>
        <small>User account</small>
        <h2 id="editUserTitle">Manage user</h2>
        <div class="ntu-modal-sub" id="editUserSubtitle">Account settings and access.</div>
        <div class="ntu-modal-badges"><span class="pill" id="editUserRoleBadge">User</span><span class="pill" id="editUserStatusBadge">Active</span></div>
      </div>
    </div>
    <button type="button" class="ntu-icon-btn" data-close="editUserDialog" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="ntu-modal-body">
    <form method="post" id="editUserForm">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="kind" value="update_user"><input type="hidden" name="user_id" id="edit_user_id">
      <div class="notice" id="edit_self_note" style="display:none;margin-bottom:14px"><b>Your account</b><div>You can update your profile and primary team here, but you cannot change your own role/status or use administrator recovery actions on yourself.</div></div>
      <div class="ntu-modal-section">
        <div class="ntu-section-title"><h3>Profile</h3><span class="muted">Core account details</span></div>
        <div class="ntu-form-grid">
          <div><label>Display name</label><input name="display_name" id="edit_display_name" required></div>
          <div><label>Username</label><input name="username" id="edit_username" required pattern="[A-Za-z0-9._-]{2,80}"></div>
          <div class="full"><label>Email</label><input type="email" name="email" id="edit_email"><div class="ntu-help">Used for Google account linking and self-service password recovery.</div></div>
          <div><label>Role</label><select name="role" id="edit_role"><option value="scout">Scout</option><?php if(ntu_role_rank((string)$u['role'])>=2):?><option value="strategy">Strategy</option><?php endif;?><?php if(ntu_role_rank((string)$u['role'])>=3):?><option value="admin">Admin</option><?php endif;?><?php if(ntu_role_rank((string)$u['role'])>=4):?><option value="owner">Owner</option><?php endif;?></select><div class="ntu-help" id="edit_role_help">You can assign roles up to your own level.</div></div>
          <div><label>Primary FRC team</label><select name="primary_team_id" id="edit_team"><option value="0">No primary team</option><?php foreach($teams as $t):?><option value="<?=(int)$t['id']?>">#<?=e($t['frc_team_number'].' '.($t['display_name']?:$t['nickname']))?></option><?php endforeach;?></select></div>
          <div class="full"><label style="display:flex;align-items:center;gap:9px"><input type="checkbox" name="active" id="edit_active" value="1" style="width:auto"> Active account</label></div>
        </div>
      </div>
      <div class="ntu-modal-section">
        <div class="ntu-section-title"><h3>Sign-in status</h3><span class="muted">Google and password access</span></div>
        <div class="ntu-mini-grid">
          <button type="button" class="ntu-status-card neutral ntu-status-action" id="edit_password_box" title="Issue a new temporary password"><i class="fa-solid fa-key"></i><div><strong>Password sign-in</strong><span class="muted">Click to issue a new temporary password.</span></div><i class="fa-solid fa-chevron-right ntu-status-arrow"></i></button>
          <div class="ntu-status-card good" id="edit_google_box" style="display:none"><i class="fa-brands fa-google"></i><div><strong>Google linked</strong><span class="muted">Connected to <b id="edit_google_email"></b></span></div></div>
        </div>
      </div>
      <div class="ntu-modal-foot ntu-save-bar"><button type="button" class="secondary" data-close="editUserDialog">Cancel</button><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Save User</button></div>
    </form>
    <div class="ntu-modal-section" style="margin-top:14px">
      <div class="ntu-section-title"><h3>Account actions</h3><span class="muted">Recovery and provider controls</span></div>
      <div class="ntu-status-row">
        <div class="ntu-help">Password recovery is available from the clickable Password sign-in card above. Google can be unlinked here when needed.</div>
        <div class="ntu-danger">
          <form method="post" id="unlink_google_form" style="display:none" data-confirm="Unlink this Google account? Password sign-in will still work." data-confirm-title="Unlink Google" data-confirm-button="Unlink" data-confirm-danger="1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="kind" value="unlink_google"><input type="hidden" name="user_id" id="unlink_user_id"><button class="secondary" type="submit"><i class="fa-brands fa-google"></i> Unlink Google</button></form>
        </div>
      </div>
      <form method="post" id="reset_password_form" style="display:none"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="kind" value="reset_password"><input type="hidden" name="user_id" id="reset_user_id"></form>
    </div>
  </div>
</dialog>

<dialog class="ntu-modal" id="userDialog">
  <div class="ntu-modal-head">
    <div class="ntu-modal-hero">
      <div class="ntu-modal-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"></div>
      <div>
        <small>Create account</small>
        <h2>Add Organization User</h2>
        <div class="ntu-add-copy">Create a user inside <b><?=e($u['organization_name'])?></b>. Recommended: include an email so Google sign-in and password recovery are available.</div>
      </div>
    </div>
    <button type="button" class="ntu-icon-btn" data-close="userDialog"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="ntu-modal-body">
    <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="kind" value="user">
      <div class="ntu-modal-section">
        <div class="ntu-section-title"><h3>User details</h3><span class="muted">Profile and access defaults</span></div>
        <div class="ntu-form-grid">
          <div><label>Display name</label><input name="display_name" required></div>
          <div><label>Username</label><input name="username" required pattern="[A-Za-z0-9._-]{2,80}"></div>
          <div class="full"><label>Email</label><input type="email" name="email"><div class="ntu-help">Recommended. It enables Google linking and self-service password recovery.</div></div>
          <div><label>Role</label><select name="role"><option value="scout">Scout</option><?php if(ntu_role_rank((string)$u['role'])>=2):?><option value="strategy">Strategy</option><?php endif;?><?php if(ntu_role_rank((string)$u['role'])>=3):?><option value="admin">Admin</option><?php endif;?><?php if(ntu_role_rank((string)$u['role'])>=4):?><option value="owner">Owner</option><?php endif;?></select><div class="ntu-help">You cannot create a user above your own access level.</div></div>
          <div><label>Primary FRC team</label><select name="primary_team_id"><option value="0">No primary team</option><?php foreach($teams as $t):?><option value="<?=(int)$t['id']?>">#<?=e($t['frc_team_number'].' '.($t['display_name']?:$t['nickname']))?></option><?php endforeach;?></select></div>
          <div class="full"><label>Temporary password <span class="muted">(optional)</span></label><input type="password" name="password" minlength="10" autocomplete="new-password"><div class="ntu-help">Leave blank and Neptune will generate one. The user must change it after password sign-in.</div></div>
        </div>
      </div>
      <div class="ntu-modal-foot"><button type="button" class="secondary" data-close="userDialog">Cancel</button><button type="submit"><i class="fa-solid fa-user-plus"></i> Add User</button></div>
    </form>
  </div>
</dialog>

<dialog class="ntu-modal" id="teamDialog">
  <div class="ntu-modal-head">
    <div class="ntu-modal-hero">
      <div class="ntu-modal-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"></div>
      <div>
        <small>Organization setup</small>
        <h2>Add FRC Team</h2>
        <div class="ntu-add-copy">Add an organization team so it can receive assignments, show branding, and appear across Neptune.</div>
      </div>
    </div>
    <button type="button" class="ntu-icon-btn" data-close="teamDialog"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="ntu-modal-body">
    <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="kind" value="team">
      <div class="ntu-modal-section">
        <div class="ntu-section-title"><h3>Team details</h3><span class="muted">Display and identity information</span></div>
        <div class="ntu-form-grid">
          <div><label>FRC team number</label><input type="number" name="frc_team_number" min="1" required></div>
          <div><label>Nickname</label><input name="nickname"></div>
          <div class="full"><label>Display name</label><input name="display_name"><div class="ntu-help">If blank, Neptune will use the nickname or team number where needed.</div></div>
        </div>
      </div>
      <div class="ntu-modal-foot"><button type="button" class="secondary" data-close="teamDialog">Cancel</button><button type="submit"><i class="fa-solid fa-plus"></i> Add Team</button></div>
    </form>
  </div>
</dialog>

<dialog class="ntu-modal" id="editTeamDialog">
  <div class="ntu-modal-head">
    <div class="ntu-modal-hero">
      <div class="ntu-modal-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"></div>
      <div>
        <small>Organization setup</small>
        <h2 id="editTeamTitle">Edit FRC Team</h2>
        <div class="ntu-add-copy">Update this organization team's identity without replacing its Neptune team record.</div>
      </div>
    </div>
    <button type="button" class="ntu-icon-btn" data-close="editTeamDialog"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="ntu-modal-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="kind" value="update_team">
      <input type="hidden" name="team_id" id="edit_team_id">
      <div class="ntu-modal-section">
        <div class="ntu-section-title"><h3>Team details</h3><span class="muted">Admin / Owner</span></div>
        <div class="ntu-form-grid">
          <div>
            <label for="edit_team_number">FRC team number</label>
            <input type="number" id="edit_team_number" name="frc_team_number" min="1" required>
            <div class="ntu-help">Changing the number also updates this organization's TBA team key and team-branding identity.</div>
          </div>
          <div><label for="edit_team_nickname">Nickname</label><input id="edit_team_nickname" name="nickname"></div>
          <div class="full"><label for="edit_team_display">Display name</label><input id="edit_team_display" name="display_name"><div class="ntu-help">If blank, Neptune will use the nickname or team number where needed.</div></div>
        </div>
      </div>
      <div class="ntu-modal-foot">
        <button type="button" class="secondary" data-close="editTeamDialog">Cancel</button>
        <button type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Team</button>
      </div>
    </form>
  </div>
</dialog>

<script>
(function(){
 const cap=s=>{s=(s||'').toString();return s? s.charAt(0).toUpperCase()+s.slice(1):'User';};
 document.querySelectorAll('[data-copy-invite]').forEach(b=>b.addEventListener('click',async()=>{const v=b.dataset.copyInvite||'';try{await navigator.clipboard.writeText(v);b.innerHTML='<i class=\"fa-solid fa-check\"></i> Copied';setTimeout(()=>{b.innerHTML='<i class=\"fa-solid fa-copy\"></i> Copy Invite Link'},1800);}catch(e){await window.NeptuneUI?.prompt?.('Copy this invitation link:',{title:'Copy invitation link',value:v,confirmText:'Done'});}}));
 document.querySelectorAll('[data-copy-org-slug]').forEach(b=>b.addEventListener('click',async()=>{const v=b.dataset.copyOrgSlug||'';const original=b.innerHTML;try{await navigator.clipboard.writeText(v);b.innerHTML='<i class=\"fa-solid fa-check\"></i> Copied';setTimeout(()=>{b.innerHTML=original},1600);}catch(e){await window.NeptuneUI?.prompt?.('Copy this organization slug:',{title:'Organization sign-in slug',value:v,confirmText:'Done'});}}));
 document.querySelectorAll('[data-open]').forEach(b=>b.addEventListener('click',()=>document.getElementById(b.dataset.open)?.showModal()));
 document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>document.getElementById(b.dataset.close)?.close()));
 document.querySelectorAll('.ntu-modal').forEach(d=>d.addEventListener('click',e=>{if(e.target===d)d.close()}));
 document.querySelectorAll('.ntu-edit-team').forEach(b=>b.addEventListener('click',()=>{
   const d=document.getElementById('editTeamDialog');
   document.getElementById('editTeamTitle').textContent='Edit Team #'+(b.dataset.number||'');
   document.getElementById('edit_team_id').value=b.dataset.id||'';
   document.getElementById('edit_team_number').value=b.dataset.number||'';
   document.getElementById('edit_team_nickname').value=b.dataset.nickname||'';
   document.getElementById('edit_team_display').value=b.dataset.display||'';
   d?.showModal();
 }));
 const passwordCard=document.getElementById('edit_password_box');
 if(passwordCard) passwordCard.addEventListener('click',async()=>{
   const name=document.getElementById('editUserTitle')?.textContent||'this user';
   const ok=await window.NeptuneUI.confirm('Issue a new temporary password for '+name+'? The current Neptune password will stop working.',{title:'Issue temporary password',confirmText:'Issue password',danger:true});
   if(ok)document.getElementById('reset_password_form')?.requestSubmit();
 });
 document.querySelectorAll('.ntu-manage').forEach(b=>b.addEventListener('click',()=>{
   const d=document.getElementById('editUserDialog');
   document.getElementById('editUserTitle').textContent=b.dataset.display||'Manage user';
   document.getElementById('editUserSubtitle').textContent='@'+(b.dataset.username||'user') + ((b.dataset.email||'') ? ' · '+(b.dataset.email||'') : '');
   document.getElementById('editUserRoleBadge').textContent=cap(b.dataset.role||'user');
   document.getElementById('editUserStatusBadge').textContent=(b.dataset.active==='1'?'Active':'Inactive');
   document.getElementById('edit_user_id').value=b.dataset.id||'';
   document.getElementById('reset_user_id').value=b.dataset.id||'';
   document.getElementById('unlink_user_id').value=b.dataset.id||'';
   document.getElementById('edit_display_name').value=b.dataset.display||'';
   document.getElementById('edit_username').value=b.dataset.username||'';
   document.getElementById('edit_email').value=b.dataset.email||'';
   const isSelf=b.dataset.self==='1';
   const roleField=document.getElementById('edit_role');
   roleField.value=b.dataset.role||'scout';
   roleField.disabled=isSelf;
   document.getElementById('edit_role_help').textContent=isSelf?'Your own role can only be changed by a higher-level user.':'You can assign roles up to your own level.';
   document.getElementById('edit_team').value=b.dataset.team||'0';
   const activeField=document.getElementById('edit_active');
   activeField.checked=b.dataset.active==='1';
   activeField.disabled=isSelf;
   document.getElementById('edit_self_note').style.display=isSelf?'block':'none';
   const linked=b.dataset.googleLinked==='1';
   document.getElementById('edit_google_box').style.display=linked?'flex':'none';
   document.getElementById('unlink_google_form').style.display=linked&&!isSelf?'block':'none';
   document.getElementById('edit_password_box').style.display=isSelf?'none':'flex';
   document.getElementById('edit_google_email').textContent=b.dataset.googleEmail||'Linked';
   d.showModal();
 }));
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
