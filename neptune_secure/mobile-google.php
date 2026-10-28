<?php
declare(strict_types=1);
require_once __DIR__.'/mobile-api.php';
require_once __DIR__.'/google-auth.php';

function mobile_google_rate(PDO $pdo,string $action,int $limit,int $seconds=600):void {
    $scope='ip:'.neptune_public_client_ip();
    $rate=neptune_public_rate_status($pdo,$action,$scope,$limit,$seconds);
    if(!$rate['allowed']){header('Retry-After: '.(int)$rate['retry_after']);throw new MobileError(429,'Too many sign-in requests. Try again shortly.');}
    neptune_public_rate_hit($pdo,$action,$scope,$limit,$seconds,$seconds);
}
function mobile_google_id(mixed $id):string {
    if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id))throw new MobileError(422,'Invalid Google sign-in request.');
    return $id;
}
function mobile_google_challenge(string $verifier):string {
    return rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
}
function mobile_google_start(PDO $pdo,array $body):array {
    mobile_google_rate($pdo,'mobile_google_start',20);
    if(!neptune_google_enabled())throw new MobileError(503,'Google sign-in is not configured on this Neptune server.');
    $challenge=$body['challenge']??'';
    if(!is_string($challenge)||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$challenge))throw new MobileError(422,'Invalid Google sign-in challenge.');
    $id=bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO neptune_mobile_google_attempts(id,organization_id,code_challenge,expires_at) VALUES(?,NULL,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))')->execute([$id,$challenge]);
    return ['ok'=>true,'attempt'=>$id,'browserPath'=>'api/mobile/google.php?attempt='.$id,'expiresIn'=>300];
}
function mobile_google_attempt(PDO $pdo,string $id,bool $lock=false):array {
    $query=$pdo->prepare('SELECT * FROM neptune_mobile_google_attempts WHERE id=? AND expires_at>UTC_TIMESTAMP()'.($lock?' FOR UPDATE':''));$query->execute([$id]);$attempt=$query->fetch(PDO::FETCH_ASSOC);
    if(!$attempt)throw new MobileError(410,'Google sign-in expired. Start again from the app.');
    return $attempt;
}
function mobile_google_exchange(PDO $pdo,array $body):array {
    mobile_google_rate($pdo,'mobile_google_poll',360);
    $id=mobile_google_id($body['attempt']??null);$verifier=$body['verifier']??'';
    if(!is_string($verifier)||!preg_match('/^[a-f0-9]{64}$/D',$verifier))throw new MobileError(422,'Invalid Google sign-in verifier.');
    $pdo->beginTransaction();
    try {
        $attempt=mobile_google_attempt($pdo,$id,true);
        if(!hash_equals($attempt['code_challenge'],mobile_google_challenge($verifier)))throw new MobileError(403,'Google sign-in could not be verified.');
        if($attempt['status']==='pending'){$pdo->commit();return ['ok'=>true,'pending'=>true];}
        if($attempt['status']!=='authorized')throw new MobileError(401,$attempt['status']==='consumed'?'This sign-in was already completed. Start again if the app did not receive it.':'Google sign-in was canceled or access was denied.');
        $query=$pdo->prepare(neptune_google_membership_select_sql().' AND u.id=? AND u.organization_id=? FOR UPDATE');$query->execute([(int)$attempt['user_id'],(int)$attempt['organization_id']]);$user=$query->fetch(PDO::FETCH_ASSOC);
        if(!$user||$user['google_signin_mode']==='disabled'||!in_array($user['role'],['owner','admin','strategy','scout'],true)||!hash_equals($attempt['credential_revision'],hash('sha256',$user['password_hash']))||!hash_equals($attempt['google_identity_revision'],hash('sha256',(string)($user['google_sub']??''))))throw new MobileError(403,'Account access changed during sign-in. Start again.');
        $token=bin2hex(random_bytes(32));$expiry=gmdate('Y-m-d H:i:s',time()+14*86400);
        $pdo->prepare("INSERT INTO neptune_mobile_tokens(token_hash,user_id,organization_id,credential_revision,auth_method,google_identity_revision,expires_at) VALUES(?,?,?,?,'google',?,?)")->execute([hash('sha256',$token),(int)$user['id'],(int)$user['organization_id'],$attempt['credential_revision'],$attempt['google_identity_revision'],$expiry]);
        $pdo->prepare("UPDATE neptune_mobile_google_attempts SET status='consumed' WHERE id=?")->execute([$id]);
        $pdo->commit();
        return ['ok'=>true,'pending'=>false,'token'=>$token,'expiresAt'=>str_replace(' ','T',$expiry).'Z','identity'=>mobile_identity($user)];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
/** Same membership discovery as the live website, after Google verifies identity. */
function mobile_google_memberships(PDO $pdo,array $profile):array {
    $sub=trim((string)($profile['sub']??''));$email=strtolower(trim((string)($profile['email']??'')));
    if($sub===''||$email===''||!filter_var($profile['email_verified']??false,FILTER_VALIDATE_BOOLEAN))throw new MobileError(403,'Google did not provide a verified email address.');
    $rows=[];$seen=[];
    foreach(neptune_google_memberships_by_sub($pdo,$sub) as $row){
        if(strtolower((string)($row['google_email']??''))!==$email){$pdo->prepare('UPDATE users SET google_email=? WHERE id=?')->execute([$email,(int)$row['id']]);$row['google_email']=$email;}
        $rows[]=$row;$seen[(int)$row['id']]=true;
    }
    foreach(neptune_google_memberships_by_verified_email($pdo,$sub,$email) as $row)if(!isset($seen[(int)$row['id']])){$rows[]=$row;$seen[(int)$row['id']]=true;}
    if(!$rows){
        $domain=strtolower(trim((string)($profile['hd']??'')));
        $orgs=$domain!==''?neptune_google_workspace_orgs($pdo,$domain):[];
        if(count($orgs)>1)throw new MobileError(403,'Your Workspace domain matches more than one organization. Ask an administrator for a direct invitation.');
        if(count($orgs)===1)$rows[]=neptune_google_create_workspace_user($pdo,$orgs[0],$sub,$email,trim((string)($profile['name']??'')),$domain);
    }
    $rows=array_values(array_filter($rows,fn($row)=>in_array($row['role'],['owner','admin','strategy','scout'],true)));
    if(!$rows)throw new MobileError(403,'No Neptune access is associated with this Google account. Ask your organization administrator for access or an invitation.');
    usort($rows,fn($a,$b)=>strcasecmp((string)$a['organization_name'],(string)$b['organization_name']));
    return $rows;
}
function mobile_google_choice_user(PDO $pdo,array $attempt,array $choice,int $userId):array {
    if(($choice['attempt']??'')!==$attempt['id']||(int)($choice['expires_at']??0)<time())throw new MobileError(410,'Your organization selection expired. Start Google sign-in again.');
    $orgId=0;foreach(($choice['allowed']??[]) as $allowed)if((int)$allowed['user_id']===$userId)$orgId=(int)$allowed['organization_id'];
    if($orgId<1)throw new MobileError(403,'That membership is not available for this Google account.');
    $query=$pdo->prepare(neptune_google_membership_select_sql()." AND u.id=? AND u.organization_id=? AND u.google_sub=? AND COALESCE(o.google_signin_mode,'optional')<>'disabled' LIMIT 1");
    $query->execute([$userId,$orgId,(string)($choice['sub']??'')]);$user=$query->fetch(PDO::FETCH_ASSOC);
    if(!$user||!in_array($user['role'],['owner','admin','strategy','scout'],true))throw new MobileError(403,'This Neptune membership is no longer available.');
    return $user;
}
function mobile_google_authorize_attempt(PDO $pdo,array $attempt,array $user,string $sub):void {
    if($attempt['status']!=='pending')throw new MobileError(409,'This sign-in request is no longer pending.');
    $pdo->prepare("UPDATE neptune_mobile_google_attempts SET status='authorized',organization_id=?,user_id=?,credential_revision=?,google_identity_revision=? WHERE id=? AND status='pending'")->execute([(int)$user['organization_id'],(int)$user['id'],hash('sha256',$user['password_hash']),hash('sha256',$sub),$attempt['id']]);
}
function mobile_google_complete(PDO $pdo,array $pending,array $profile):never {
    $id=mobile_google_id($pending['mobile_attempt']??null);
    $client=($pending['mobile_client']??'')==='expo-go'?'expo-go':'native';
    $pdo->beginTransaction();
    try{
        $attempt=mobile_google_attempt($pdo,$id,true);
        if($attempt['status']!=='pending')throw new MobileError(409,'This sign-in request is no longer pending.');
        $rows=mobile_google_memberships($pdo,$profile);
        $sub=trim((string)$profile['sub']);
        if(count($rows)===1)mobile_google_authorize_attempt($pdo,$attempt,$rows[0],$sub);
        $pdo->commit();unset($_SESSION['google_oauth']);
        if(count($rows)>1){
            $choice=['attempt'=>$id,'client'=>$client,'sub'=>$sub,'expires_at'=>time()+300,'allowed'=>array_map(fn($row)=>['user_id'=>(int)$row['id'],'organization_id'=>(int)$row['organization_id']],$rows)];
            $_SESSION['neptune_mobile_google_choice']=$choice;
            mobile_google_choice_page($pdo,$attempt,$choice);
        }
        unset($_SESSION['neptune_mobile_google_choice']);
        neptune_auth_audit($pdo,(int)$rows[0]['organization_id'],(int)$rows[0]['id'],'login_google_mobile','user',(string)$rows[0]['id']);
        mobile_google_page('You’re signed in.','Close this browser window and return to the Neptune app. Your account will connect automatically.',null,true,$client);
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();mobile_google_fail($pdo,$error instanceof MobileError?$error->getMessage():'Google sign-in could not be completed. Start again from the app.',$id,$client);}
}
function mobile_google_choice_page(PDO $pdo,array $attempt,array $choice):never {
    $options=[];
    foreach(($choice['allowed']??[]) as $allowed){
        try{$user=mobile_google_choice_user($pdo,$attempt,$choice,(int)$allowed['user_id']);$options[]=['id'=>(int)$user['id'],'name'=>(string)$user['organization_name'],'role'=>(string)$user['role']];}
        catch(MobileError $ignored){}
    }
    if(!$options)throw new MobileError(403,'None of these Neptune memberships is still available. Start Google sign-in again.');
    mobile_google_page('Choose your organization','Your Google account has access to more than one Neptune organization. Choose which one to use in the app.',['csrf'=>csrf_token(),'attempt'=>$attempt['id'],'choices'=>$options]);
}
function mobile_google_fail(PDO $pdo,string $message,?string $attemptId=null,?string $client=null):never {
    $client??=$_SESSION['google_oauth']['mobile_client']??$_SESSION['neptune_mobile_google_choice']['client']??'native';
    $id=$attemptId??$_SESSION['google_oauth']['mobile_attempt']??$_SESSION['neptune_mobile_google_choice']['attempt']??'';
    if(is_string($id)&&preg_match('/^[a-f0-9]{64}$/D',$id))$pdo->prepare("UPDATE neptune_mobile_google_attempts SET status='denied' WHERE id=? AND status='pending'")->execute([$id]);
    unset($_SESSION['google_oauth'],$_SESSION['neptune_mobile_google_choice']);
    mobile_google_page('Sign-in was not completed.',$message,null,true,$client);
}
function mobile_google_return_html(string $client):string {
    if($client==='expo-go')return '<p>Tap Done or the X at the top of this browser, or use Android Back, to return to Expo Go. Neptune will finish connecting there.</p>';
    return '<a href="neptune://auth-complete">Return to Neptune</a><p>You can also close this browser and return to Neptune.</p>';
}
function mobile_google_page(string $title,string $message,?array $form=null,bool $return=false,string $client='native'):never {
    header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self' https://accounts.google.com; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Neptune mobile sign-in</title><style>body{margin:0;background:#080f17;color:#edf8ff;font:18px system-ui;display:grid;min-height:100vh;place-items:center}main{max-width:480px;margin:24px;padding:32px;background:#122130;border-radius:20px}h1{font-size:30px}p{line-height:1.6;color:#afc2d1}button,a{display:block;background:#67dfc7;color:#081d22;border:0;border-radius:10px;padding:16px;text-decoration:none;font:700 17px system-ui;margin-top:20px;text-align:center}</style><main><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>';
    if($form){
        $action=htmlspecialchars(base_url('api/mobile/google.php'),ENT_QUOTES,'UTF-8');
        $hidden='<input type="hidden" name="csrf" value="'.htmlspecialchars($form['csrf'],ENT_QUOTES,'UTF-8').'"><input type="hidden" name="attempt" value="'.htmlspecialchars($form['attempt'],ENT_QUOTES,'UTF-8').'">';
        foreach(($form['choices']??[]) as $option)echo '<form method="post" action="'.$action.'">'.$hidden.'<button type="submit" name="user_id" value="'.(int)$option['id'].'">'.htmlspecialchars($option['name'],ENT_QUOTES,'UTF-8').' · '.htmlspecialchars(ucfirst($option['role']),ENT_QUOTES,'UTF-8').' access</button></form>';
    }
    if($return)echo mobile_google_return_html($client);
    echo '</main></html>';exit;
}
function mobile_google_browser(PDO $pdo):never {
    $client='native';
    try{
        if(empty($GLOBALS['isHttps']))throw new MobileError(400,'Use the HTTPS Neptune address.');
        $id=mobile_google_id($_POST['attempt']??$_GET['attempt']??null);$attempt=mobile_google_attempt($pdo,$id);
        $choice=$_SESSION['neptune_mobile_google_choice']??null;
        $client=is_array($choice)&&($choice['attempt']??'')===$id ? ($choice['client']??'native') : (($_GET['client']??'')==='expo-go'?'expo-go':'native');
        if($attempt['status']!=='pending')mobile_google_page('Return to Neptune.','This sign-in request is already complete. Return to the app or start again.',null,true,$client);
        if(!neptune_google_enabled())throw new MobileError(503,'Google sign-in is unavailable on this server.');
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&isset($_POST['user_id'])){
            verify_csrf();
            if(!is_array($choice))throw new MobileError(410,'Your organization selection expired. Start again from the app.');
            $pdo->beginTransaction();
            try{
                $attempt=mobile_google_attempt($pdo,$id,true);
                $user=mobile_google_choice_user($pdo,$attempt,$choice,mobile_int($_POST['user_id'],'membership'));
                mobile_google_authorize_attempt($pdo,$attempt,$user,(string)$choice['sub']);$pdo->commit();
            }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
            unset($_SESSION['neptune_mobile_google_choice'],$_SESSION['google_oauth']);
            neptune_auth_audit($pdo,(int)$user['organization_id'],(int)$user['id'],'login_google_mobile','user',(string)$user['id']);
            mobile_google_page('You’re signed in.','Close this browser window and return to the Neptune app. Your account will connect automatically.',null,true,$client);
        }
        if(is_array($choice)&&($choice['attempt']??'')===$id)mobile_google_choice_page($pdo,$attempt,$choice);
        // The app button already initiated this proof-bound request. Go straight to
        // Google, like the live site's Google button, without a second form/redirect.
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')verify_csrf();
        $state=bin2hex(random_bytes(24));$verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
        $_SESSION['google_oauth']=['state'=>$state,'intent'=>'mobile','created_at'=>time(),'code_verifier'=>$verifier,'mobile_attempt'=>$id,'mobile_client'=>$client];
        $settings=neptune_google_settings();
        $params=['client_id'=>$settings['client_id'],'redirect_uri'=>neptune_google_expected_redirect_uri(),'response_type'=>'code','scope'=>'openid email profile','state'=>$state,'access_type'=>'online','prompt'=>'select_account','code_challenge'=>mobile_google_challenge($verifier),'code_challenge_method'=>'S256'];
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params,'','&',PHP_QUERY_RFC3986));exit;
    }catch(Throwable $error){mobile_google_page('Unable to connect.',$error instanceof MobileError?$error->getMessage():'Start Google sign-in again from the Neptune app.',null,true,$client);}
}
