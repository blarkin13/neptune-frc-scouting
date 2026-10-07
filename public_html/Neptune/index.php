<?php
require_once dirname(__DIR__, 2) . '/neptune_secure/bootstrap.php';
require_once dirname(__DIR__, 2) . '/neptune_secure/google-auth.php';
require_once dirname(__DIR__, 2) . '/neptune_secure/public-auth-security.php';

try{
    neptune_auth_ensure_schema($pdo);
    neptune_platform_ensure_schema($pdo);
    neptune_public_auth_ensure_schema($pdo);
}catch(Throwable $e){
    neptune_public_internal_error('login_schema',$e);
}

if ($existingUser = current_user()) {
    header('Location: ' . base_url(!empty($existingUser['must_change_password']) ? 'change-password.php' : 'dashboard/index.php'));
    exit;
}

$error = '';
$notice = '';
if (!empty($_SESSION['auth_error'])) {
    $error = (string)$_SESSION['auth_error'];
    unset($_SESSION['auth_error']);
}
if (!empty($_SESSION['auth_notice'])) {
    $notice = (string)$_SESSION['auth_notice'];
    unset($_SESSION['auth_notice']);
}

$googleOAuthEnabled = neptune_google_enabled();
$hasOrganizations = false;
$organizationValue = trim((string)($_POST['organization'] ?? ''));

try{
    $hasOrganizations=(int)$pdo->query("SELECT COUNT(*) FROM organizations WHERE COALESCE(platform_status,'active')='active'")->fetchColumn()>0;

    // Prefill only the previously used organization's slug on this browser.
    // This does not expose the organization directory.
    if($organizationValue==='' && !empty($_COOKIE['neptune_last_org'])){
        $last=(int)$_COOKIE['neptune_last_org'];
        if($last>0){
            $s=$pdo->prepare("SELECT slug FROM organizations WHERE id=? AND COALESCE(platform_status,'active')='active' LIMIT 1");
            $s->execute([$last]);
            $organizationValue=(string)($s->fetchColumn()?:'');
        }
    }
}catch(Throwable $e){
    neptune_public_internal_error('login_bootstrap',$e);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $organizationInput=trim((string)($_POST['organization']??''));
    $username=trim((string)($_POST['username']??''));
    $password=(string)($_POST['password']??'');
    $organizationValue=$organizationInput;

    $clientScope='ip:'.neptune_public_client_ip();
    $targetScope='target:'.strtolower($organizationInput).'|'.strtolower($username);

    try{
        $ipLimit=neptune_public_rate_status($pdo,'password_login_ip',$clientScope,20,900);
        $targetLimit=neptune_public_rate_status($pdo,'password_login_target',$targetScope,8,900);

        if(!$ipLimit['allowed'] || !$targetLimit['allowed']){
            $retry=max((int)$ipLimit['retry_after'],(int)$targetLimit['retry_after']);
            http_response_code(429);
            $error='Too many sign-in attempts. Try again in about '.neptune_public_retry_minutes($retry).' minute'.(neptune_public_retry_minutes($retry)===1?'':'s').'.';
        }elseif($organizationInput==='' || $username==='' || $password===''){
            neptune_public_rate_hit($pdo,'password_login_ip',$clientScope,20,900,900);
            $error='Enter your organization, username, and password.';
        }else{
            $organization=neptune_public_resolve_organization($pdo,$organizationInput);
            $row=null;

            if($organization){
                $stmt=$pdo->prepare(
                    'SELECT
                        u.*,
                        o.name AS organization_name,
                        o.slug AS organization_slug,
                        o.platform_status,
                        o.google_signin_mode
                     FROM users u
                     JOIN organizations o ON o.id=u.organization_id
                     WHERE u.organization_id=?
                       AND u.username=?
                       AND u.active=1
                     LIMIT 1'
                );
                $stmt->execute([(int)$organization['id'],$username]);
                $row=$stmt->fetch()?:null;
            }

            if($row && password_verify($password,(string)$row['password_hash'])){
                neptune_public_rate_clear($pdo,'password_login_target',$targetScope);

                if(strtolower((string)($row['platform_status']??'active'))!=='active'){
                    $error='This Neptune organization is currently suspended. Contact the platform administrator.';
                }elseif(strtolower((string)($row['google_signin_mode']??'optional'))==='required'){
                    $error='This organization requires Google sign-in.';
                }else{
                    neptune_start_user_session($pdo,$row,'password');
                    neptune_auth_audit($pdo,(int)$row['organization_id'],(int)$row['id'],'login_password','user',(string)$row['id']);
                    header('Location: '.base_url(!empty($row['must_change_password'])?'change-password.php':'dashboard/index.php'));
                    exit;
                }
            }else{
                neptune_public_rate_hit($pdo,'password_login_ip',$clientScope,20,900,900);
                neptune_public_rate_hit($pdo,'password_login_target',$targetScope,8,900,900);
                // Do not reveal whether the organization, username, account status,
                // or password was the value that failed.
                $error='Unable to sign in with those credentials.';
            }
        }
    }catch(Throwable $e){
        neptune_public_internal_error('password_login',$e,[
            'organization_input_length'=>strlen($organizationInput),
            'username_length'=>strlen($username),
        ]);
    }
}

$pageTitle = 'Neptune | FRC Scouting Platform';

include __DIR__ . '/partials_header.php';
include __DIR__ . '/landing.php';
include __DIR__ . '/partials_footer.php';
