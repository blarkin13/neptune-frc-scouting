<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
neptune_auth_ensure_schema($pdo);

function google_fail(string $message,string $intent='login',string $inviteToken=''): never{
    unset($_SESSION['google_oauth']);
    $_SESSION['auth_error']=$message;
    if($intent==='reset_password')$target='forgot-password.php';
    elseif($intent==='invite')$target='join.php'.($inviteToken!==''?'?token='.rawurlencode($inviteToken):'');
    else $target='index.php';
    header('Location: '.base_url($target));exit;
}
function google_no_access(string $email,string $message): never{
    unset($_SESSION['google_oauth']);
    $_SESSION['google_no_access']=['email'=>$email,'message'=>$message,'created_at'=>time()];
    header('Location: '.base_url('auth/no-access.php'));exit;
}

if(!neptune_google_enabled())google_fail('Google sign-in is not configured.');
$pending=$_SESSION['google_oauth']??null;
$intent=is_array($pending)?(string)($pending['intent']??'login'):'login';
$inviteToken=is_array($pending)?trim((string)($pending['invite_token']??'')):'';
if(!is_array($pending))google_fail('Google sign-in session expired. Please try again.',$intent,$inviteToken);
if((time()-(int)($pending['created_at']??0))>600)google_fail('Google sign-in session expired. Please try again.',$intent,$inviteToken);
$state=(string)($_GET['state']??'');
if($state===''||!hash_equals((string)($pending['state']??''),$state))google_fail('Google sign-in could not be verified. Please try again.',$intent,$inviteToken);
if(!empty($_GET['error']))google_fail('Google sign-in was canceled or denied.',$intent,$inviteToken);
$code=(string)($_GET['code']??'');
if($code==='')google_fail('Google did not return a sign-in code.',$intent,$inviteToken);

try{
    $settings=neptune_google_settings();
    $tokens=neptune_google_http('https://oauth2.googleapis.com/token','POST',[
        'code'=>$code,
        'client_id'=>$settings['client_id'],
        'client_secret'=>$settings['client_secret'],
        'redirect_uri'=>neptune_google_expected_redirect_uri(),
        'grant_type'=>'authorization_code',
        'code_verifier'=>(string)($pending['code_verifier']??''),
    ]);
    $accessToken=(string)($tokens['access_token']??'');
    if($accessToken==='')throw new RuntimeException('Google did not issue an access token.');
    $profile=neptune_google_http('https://openidconnect.googleapis.com/v1/userinfo','GET',[],['Authorization: Bearer '.$accessToken]);
    $sub=trim((string)($profile['sub']??''));
    $email=strtolower(trim((string)($profile['email']??'')));
    $verified=filter_var($profile['email_verified']??false,FILTER_VALIDATE_BOOLEAN);
    $hostedDomain=strtolower(trim((string)($profile['hd']??'')));
    $displayName=trim((string)($profile['name']??''));
    if($sub===''||$email===''||!$verified)throw new RuntimeException('Google did not provide a verified email address.');

    if($intent==='invite'){
        if($inviteToken==='')google_fail('The invitation session expired. Open the invitation link again.','invite');
        $row=neptune_google_accept_invite($pdo,$inviteToken,$sub,$email,$displayName);
        unset($_SESSION['google_oauth']);
        header('Location: '.neptune_google_finish_user($pdo,$row,$sub,'login'));exit;
    }

    $rows=[];$seen=[];
    foreach(neptune_google_memberships_by_sub($pdo,$sub) as $row){
        if(strtolower((string)($row['google_email']??''))!==$email){
            $pdo->prepare('UPDATE users SET google_email=? WHERE id=?')->execute([$email,(int)$row['id']]);
            $row['google_email']=$email;
        }
        $rows[]=$row;$seen[(int)$row['id']]=true;
    }
    foreach(neptune_google_memberships_by_verified_email($pdo,$sub,$email) as $row){
        if(!isset($seen[(int)$row['id']])){$rows[]=$row;$seen[(int)$row['id']]=true;}
    }

    if(!$rows && $intent==='login' && $hostedDomain!==''){
        $workspaceOrgs=neptune_google_workspace_orgs($pdo,$hostedDomain);
        if(count($workspaceOrgs)===1){
            $rows[] = neptune_google_create_workspace_user($pdo,$workspaceOrgs[0],$sub,$email,$displayName,$hostedDomain);
        }elseif(count($workspaceOrgs)>1){
            google_no_access($email,'Your verified Workspace domain is configured for more than one Neptune organization. For security, Neptune will not let you choose among them. Ask an organization administrator for a direct invitation.');
        }
    }

    unset($_SESSION['google_oauth']);
    if(!$rows){
        if($intent==='reset_password')google_no_access($email,'No active Neptune account matches this verified Google identity. Ask an organization administrator to confirm the email on your Neptune account or issue a temporary password.');
        google_no_access($email,'No Neptune access is associated with this Google account. Ask your organization administrator to add your account or send you a Neptune invitation.');
    }

    if(count($rows)===1){
        header('Location: '.neptune_google_finish_user($pdo,$rows[0],$sub,$intent));exit;
    }

    usort($rows,function($a,$b){
        $last=(int)($_COOKIE['neptune_last_org']??0);
        $ao=((int)$a['organization_id']===$last)?0:1;$bo=((int)$b['organization_id']===$last)?0:1;
        return $ao<=>$bo ?: strcasecmp((string)$a['organization_name'],(string)$b['organization_name']);
    });
    neptune_google_store_membership_choice($rows,$sub,$email,$intent);
    header('Location: '.base_url('auth/choose-organization.php'));exit;
}catch(Throwable $e){
    error_log('Neptune Google OAuth error: '.$e->getMessage());
    google_fail('Google authentication could not be completed. Please try again.',$intent,$inviteToken);
}
