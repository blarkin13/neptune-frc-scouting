<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
neptune_auth_ensure_schema($pdo);

if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Location: '.base_url('index.php'));exit;
}
verify_csrf();
$intent=(string)($_POST['intent']??'login');
if(!in_array($intent,['login','reset_password','invite'],true))$intent='login';
$returnPage=match($intent){'reset_password'=>'forgot-password.php','invite'=>'join.php',default=>'index.php'};

if(!neptune_google_enabled()){
    $_SESSION['auth_error']='Google sign-in has not been configured on this Neptune server yet.';
    header('Location: '.base_url($returnPage));exit;
}

$inviteToken='';
if($intent==='invite'){
    $inviteToken=trim((string)($_POST['invite_token']??''));
    $invite=neptune_auth_invite_load($pdo,$inviteToken);
    if(!$invite||!neptune_auth_invite_is_usable($invite)){
        $_SESSION['auth_error']='This invitation is invalid, expired, revoked, or has already been used.';
        header('Location: '.base_url('join.php'.($inviteToken!==''?'?token='.rawurlencode($inviteToken):'')));exit;
    }
    if(strtolower((string)($invite['google_signin_mode']??'optional'))==='disabled'){
        $_SESSION['auth_error']='Google sign-in is disabled for the invited organization.';
        header('Location: '.base_url('join.php?token='.rawurlencode($inviteToken)));exit;
    }
}

$state=bin2hex(random_bytes(24));
$verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
$_SESSION['google_oauth']=[
    'state'=>$state,
    'intent'=>$intent,
    'created_at'=>time(),
    'code_verifier'=>$verifier,
    'invite_token'=>$intent==='invite'?$inviteToken:null,
];

$settings=neptune_google_settings();
$params=[
    'client_id'=>$settings['client_id'],
    'redirect_uri'=>neptune_google_expected_redirect_uri(),
    'response_type'=>'code',
    'scope'=>'openid email profile',
    'state'=>$state,
    'access_type'=>'online',
    'prompt'=>'select_account',
    'code_challenge'=>$challenge,
    'code_challenge_method'=>'S256',
];
header('Location: https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params,'','&',PHP_QUERY_RFC3986));
exit;
