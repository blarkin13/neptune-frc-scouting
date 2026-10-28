<?php
declare(strict_types=1);
require_once __DIR__.'/mobile-domain.php';
require_once __DIR__.'/public-auth-security.php';
require_once __DIR__.'/platform-mfa.php';
require_once dirname(__DIR__).'/public_html/Neptune/pit/_helpers.php';

function mobile_reply(array $data, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}
function mobile_body(): array {
    $type=strtolower(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]);
    if ($type!=='application/json') throw new MobileError(415,'Use application/json.');
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>9*1024*1024) throw new MobileError(413,'Report exceeds the 9 MB upload limit.');
    $raw=file_get_contents('php://input',false,null,0,9*1024*1024+1);
    if ($raw===false || strlen($raw)>9*1024*1024) throw new MobileError(413,'Report too large.');
    try { return mobile_json($raw); } catch (JsonException) { throw new MobileError(422,'Invalid JSON.'); }
}
function mobile_identity(array $user): array {
    return ['userId'=>(int)$user['id'],'organizationId'=>(int)$user['organization_id'],'name'=>(string)$user['display_name'],'organization'=>(string)$user['organization_name']];
}
function mobile_authorize(PDO $pdo): array {
    $header=mobile_authorization_header($_SERVER,function_exists('getallheaders')?(getallheaders()?:[]):[]);
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$header,$match)) throw new MobileError(401,'Sign in to sync.');
    $query=$pdo->prepare('SELECT * FROM neptune_mobile_tokens WHERE token_hash=? AND expires_at>UTC_TIMESTAMP()');
    $query->execute([hash('sha256',$match[1])]);
    $token=$query->fetch(PDO::FETCH_ASSOC);
    if (!$token) throw new MobileError(401,'Your mobile session expired. Sign in again; local reports are retained.');
    $user=neptune_live_user($pdo,['id'=>(int)$token['user_id'],'organization_id'=>(int)$token['organization_id'],'credential_revision'=>$token['credential_revision'],'auth_method'=>$token['auth_method']??'password','google_identity_revision'=>$token['google_identity_revision']??'']);
    if (!$user || !empty($user['must_change_password'])) throw new MobileError(401,'Account access changed. Sign in again after updating your account on Neptune.');
    $policy=$pdo->prepare('SELECT google_signin_mode FROM organizations WHERE id=?');
    $policy->execute([(int)$user['organization_id']]);
    $mode=$policy->fetchColumn();$method=$token['auth_method']??'password';
    if(($mode==='required'&&$method!=='google')||($mode==='disabled'&&$method==='google'))throw new MobileError(403,'Your organization’s sign-in policy changed. Sign in again using an allowed method.');
    $user['_mobile_token_hash']=$token['token_hash'];
    return $user;
}
function mobile_login(PDO $pdo,array $body): array {
    $organization=mobile_text($body['organization']??'','organization',190);
    $username=mobile_text($body['username']??'','username',190);
    // Do not trim passwords.
    $password=$body['password']??'';
    if (!is_string($password)||strlen($password)>4096) throw new MobileError(422,'Invalid credentials.');
    $scopes=['ip:'.neptune_public_client_ip(),'target:'.strtolower($organization).'|'.strtolower($username)];
    foreach ($scopes as $scope) {
        $rate=neptune_public_rate_status($pdo,'mobile_login',$scope,8,900);
        if (!$rate['allowed']) { header('Retry-After: '.(int)$rate['retry_after']); throw new MobileError(429,'Too many sign-in attempts. Try again later.'); }
        // Count every attempt, including MFA challenges, to bound password hashing work.
        neptune_public_rate_hit($pdo,'mobile_login',$scope,8,900,900);
    }
    $org=neptune_public_resolve_organization($pdo,$organization);
    $query=$pdo->prepare('SELECT u.*,o.name organization_name,o.google_signin_mode,o.platform_status FROM users u JOIN organizations o ON o.id=u.organization_id WHERE u.organization_id=? AND u.username=? LIMIT 1');
    $query->execute([(int)($org['id']??0),$username]); $user=$query->fetch(PDO::FETCH_ASSOC);
    $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
    $valid=password_verify($password,(string)($user['password_hash']??$dummy));
    if (!$user||!$valid||(int)$user['active']!==1||strtolower((string)$user['platform_status'])!=='active'||!in_array($user['role'],['owner','admin','strategy','scout'],true)) throw new MobileError(401,'Unable to sign in with those credentials.');
    if ($user['google_signin_mode']==='required') throw new MobileError(403,'This organization requires Google sign-in. Use Continue with Google.');
    if (!empty($user['must_change_password'])) throw new MobileError(403,'Change your temporary password on the Neptune website before signing into mobile.');
    if (neptune_platform_owner_is($user)&&neptune_platform_owner_mode()==='strong') {
        $record=neptune_mfa_record($pdo,(int)$user['id']);
        if (!$record) throw new MobileError(403,'Set up your platform-owner authenticator on the website first.');
        $code=mobile_text($body['otp']??'','authenticator code',12);
        $pdo->beginTransaction();
        try {
            $lock=$pdo->prepare('SELECT * FROM neptune_user_mfa WHERE user_id=? FOR UPDATE'); $lock->execute([(int)$user['id']]); $record=$lock->fetch(PDO::FETCH_ASSOC);
            $step=$record?neptune_totp_verify(neptune_mfa_decrypt($record['totp_secret_ciphertext']),$code,isset($record['last_totp_step'])?(int)$record['last_totp_step']:null):null;
            if ($step===null) throw new MobileError(401,'Enter a valid authenticator code.', ['mfa_required'=>true]);
            $pdo->prepare('UPDATE neptune_user_mfa SET last_totp_step=? WHERE user_id=?')->execute([$step,(int)$user['id']]);
            $pdo->commit();
        } catch (Throwable $error) { if($pdo->inTransaction())$pdo->rollBack(); throw $error; }
    }
    $raw=bin2hex(random_bytes(32));
    $expires=gmdate('Y-m-d H:i:s',time()+14*86400);
    $pdo->prepare('INSERT INTO neptune_mobile_tokens(token_hash,user_id,organization_id,credential_revision,expires_at) VALUES(?,?,?,?,?)')->execute([hash('sha256',$raw),(int)$user['id'],(int)$user['organization_id'],hash('sha256',$user['password_hash']),$expires]);
    foreach ($scopes as $scope) neptune_public_rate_clear($pdo,'mobile_login',$scope);
    return ['ok'=>true,'token'=>$raw,'expiresAt'=>str_replace(' ','T',$expires).'Z','identity'=>mobile_identity($user)];
}
function mobile_event(PDO $pdo,array $user,int $id,bool $lock=false): array {
    $query=$pdo->prepare('SELECT e.*,gr.match_config_json,gr.pit_config_json FROM events e JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id WHERE e.id=? AND e.organization_id=?'.($lock?' FOR UPDATE':''));
    $query->execute([$id,(int)$user['organization_id']]);
    $event=$query->fetch(PDO::FETCH_ASSOC);
    if (!$event) throw new MobileError(404,'This event is not available to your organization.');
    return $event;
}
function mobile_pit(PDO $pdo,array $user,int $event,int $team,bool $lock=false): array {
    $query=$pdo->prepare('SELECT * FROM pit_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=?'.($lock?' FOR UPDATE':''));
    $query->execute([(int)$user['organization_id'],$event,$team]); $pit=$query->fetch(PDO::FETCH_ASSOC)?:null;
    $photos=[];
    if ($pit) {
        $query=$pdo->prepare('SELECT id,category,file_path FROM pit_scouting_photos WHERE organization_id=? AND pit_scouting_id=? ORDER BY id');
        $query->execute([(int)$user['organization_id'],(int)$pit['id']]); $photos=$query->fetchAll(PDO::FETCH_ASSOC);
    }
    $data=$pit?mobile_json($pit['data_json']):[];
    return ['team'=>$team,'version'=>mobile_pit_version($pit,$photos),'answers'=>(object)array_filter($data,fn($v,$k)=>!str_starts_with((string)$k,'_')&&(is_string($v)||is_array($v)),ARRAY_FILTER_USE_BOTH),'notes'=>(string)($pit['notes']??''),'status'=>$pit['status']??'in_progress','photos'=>array_map(fn($photo)=>['category'=>$photo['category'],'url'=>base_url(ltrim($photo['file_path'],'/'))],$photos)];
}
function mobile_bundle(PDO $pdo,array $user,int $id): array {
    $event=mobile_event($pdo,$user,$id);
    $query=$pdo->prepare('SELECT frc_team_number,nickname FROM event_teams WHERE event_id=? ORDER BY frc_team_number');$query->execute([$id]);
    $teams=array_map(fn($team)=>['number'=>(int)$team['frc_team_number'],'name'=>(string)($team['nickname']??'')],$query->fetchAll(PDO::FETCH_ASSOC));
    $query=$pdo->prepare('SELECT * FROM matches WHERE event_id=? AND organization_id=? ORDER BY scheduled_time,field_id,id');$query->execute([$id,(int)$user['organization_id']]);$rows=$query->fetchAll(PDO::FETCH_ASSOC);
    $stationQuery=$pdo->prepare('SELECT mt.* FROM match_teams mt JOIN matches m ON m.id=mt.match_id WHERE m.event_id=? AND m.organization_id=?');$stationQuery->execute([$id,(int)$user['organization_id']]);
    $stations=[];foreach($stationQuery->fetchAll(PDO::FETCH_ASSOC) as $row)$stations[(int)$row['match_id']][]=['alliance'=>$row['alliance'],'station'=>(int)$row['station'],'team'=>(int)$row['frc_team_number']];
    $matches=array_map(fn($row)=>['id'=>(int)$row['id'],'label'=>neptune_match_label($row),'run'=>(int)$row['run_number'],'state'=>$row['state'],'startedAt'=>$row['started_at']?str_replace(' ','T',$row['started_at']).'Z':null,'pausedAt'=>$row['paused_at']?str_replace(' ','T',$row['paused_at']).'Z':null,'endedAt'=>$row['ended_at']?str_replace(' ','T',$row['ended_at']).'Z':null,'totalPauseSeconds'=>(int)$row['total_pause_seconds'],'stations'=>$stations[(int)$row['id']]??[]],$rows);
    $query=$pdo->prepare('SELECT frc_team_number FROM pit_scouting WHERE organization_id=? AND event_id=?');$query->execute([(int)$user['organization_id'],$id]);
    $pits=[];foreach($query->fetchAll(PDO::FETCH_COLUMN) as $team)$pits[]=mobile_pit($pdo,$user,$id,(int)$team);
    return ['ok'=>true,'bundle'=>['event'=>['id'=>$id,'name'=>$event['name'],'current'=>(bool)$event['is_current']],'revision'=>(int)$event['game_revision_id'],'config'=>mobile_json($event['match_config_json']),'questions'=>neptune_pit_all_questions($event['pit_config_json']),'teams'=>$teams,'matches'=>$matches,'pits'=>$pits,'downloadedAt'=>gmdate('c'),'serverTime'=>gmdate('c')]];
}
function mobile_save_match(PDO $pdo,array $user,array $event,array $payload,string $operation): array {
    $matchId=mobile_int($payload['matchId']??null,'match ID');
    $query=$pdo->prepare('SELECT * FROM matches WHERE id=? AND organization_id=? AND event_id=? FOR UPDATE');
    $query->execute([$matchId,(int)$user['organization_id'],(int)$event['id']]); $match=$query->fetch(PDO::FETCH_ASSOC);
    if (!$match) throw new MobileError(404,'Match not found.');
    if ((int)$match['run_number']!==mobile_int($payload['run']??null,'match run')) throw new MobileError(409,'Command restarted this match. This report remains on the phone for review.');
    if (empty($match['started_at'])||!in_array($match['state'],['running','paused','ended'],true)) throw new MobileError(409,'Command has not started this match run. Sync again after Command starts it.');
    if (($payload['finished']??false)!==true) throw new MobileError(422,'Finish the match report before submitting.');
    $alliance=$payload['alliance']??'';
    if (!in_array($alliance,['Red','Blue'],true)) throw new MobileError(422,'Invalid alliance.');
    $station=mobile_int($payload['station']??null,'station',1,3);$team=mobile_int($payload['team']??null,'team');
    $query=$pdo->prepare('SELECT frc_team_number FROM match_teams WHERE match_id=? AND alliance=? AND station=?');$query->execute([$matchId,$alliance,$station]);
    if ((int)$query->fetchColumn()!==$team) throw new MobileError(409,'The station assignment changed. This report was not attached to a different robot.');
    $actions=mobile_validate_actions($payload,mobile_json($event['match_config_json']));
    foreach ($actions as $action) {
        $time=strtotime($action['recorded_at'].' UTC');
        if ($time<strtotime($match['started_at'].' UTC')-30 || (!empty($match['ended_at'])&&$time>strtotime($match['ended_at'].' UTC')+30)) throw new MobileError(409,'Action timestamps do not match the official match window. Check the device clock and match selection.');
    }
    $owner=primary_team_id($pdo,(int)$user['id'],(int)$user['organization_id']);
    $pdo->prepare("INSERT INTO scout_sessions(uuid,organization_id,event_id,match_id,user_id,scout_name,frc_team_number,alliance,station,field_id,match_run_number,status,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'submitted',UTC_TIMESTAMP())")->execute([$operation,(int)$user['organization_id'],(int)$event['id'],$matchId,(int)$user['id'],$user['display_name'],$team,$alliance,$station,(int)$match['field_id'],(int)$match['run_number']]);
    $session=(int)$pdo->lastInsertId();
    $insert=$pdo->prepare("INSERT INTO scouting_actions(uuid,organization_id,owner_team_id,event_id,match_id,scout_session_id,game_id,frc_team_number,alliance,action_code,action_name,action_type,location,result,points,match_time_sec,match_run_number,phase,source,source_ip,created_by,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'scout',?,?,?)");
    foreach($actions as $action) $insert->execute([$action['uuid'],(int)$user['organization_id'],$owner,(int)$event['id'],$matchId,$session,(int)$event['game_id'],$team,$alliance,$action['code'],$action['name'],$action['type'],$action['location'],$action['result'],$action['points'],$action['seconds'],(int)$match['run_number'],$action['phase'],$_SERVER['REMOTE_ADDR']??null,(int)$user['id'],$action['recorded_at']]);
    return ['ok'=>true,'receipt'=>$operation,'actions'=>count($actions)];
}
/** Decode only bounded JPEGs and re-encode with GD to strip metadata/extra content. */
function mobile_prepare_photos(mixed $photos): array {
    if (!is_array($photos)||!array_is_list($photos)||count($photos)>5) throw new MobileError(422,'At most five pit photos are allowed.');
    $result=[];
    foreach($photos as $photo) {
        if (!is_array($photo)||!in_array($photo['category']??'', ['front','back','left','right','mechanism'],true)||isset($result[$photo['category']])) throw new MobileError(422,'Invalid or duplicate photo category.');
        $base64=$photo['base64']??'';
        if (!is_string($base64)||strlen($base64)>2*1024*1024) throw new MobileError(413,'A compressed photo exceeds the 2 MB limit.');
        $bytes=base64_decode($base64,true);
        $info=$bytes!==false?@getimagesizefromstring($bytes):false;
        if (!$info||$info[2]!==IMAGETYPE_JPEG||$info[0]>2000||$info[1]>2000||$info[0]<1||$info[1]<1) throw new MobileError(422,'Photos must be JPEG images no larger than 2000 pixels per side.');
        if (!function_exists('imagecreatefromstring')) throw new MobileError(503,'Server JPEG support is unavailable. Reports remain on the phone.');
        $image=@imagecreatefromstring($bytes);
        if (!$image) throw new MobileError(422,'Unable to read this photo.');
        ob_start();imagejpeg($image,null,85);$safe=ob_get_clean();imagedestroy($image);
        if (!is_string($safe)||$safe==='') throw new MobileError(503,'Unable to prepare this photo.');
        $result[$photo['category']]=$safe;
    }
    return $result;
}
function mobile_save_pit(PDO $pdo,array $user,array $event,array $payload,array $photos,array &$createdFiles): array {
    $team=mobile_int($payload['team']??null,'team');
    $query=$pdo->prepare('SELECT 1 FROM event_teams WHERE event_id=? AND frc_team_number=?');$query->execute([(int)$event['id'],$team]);
    if (!$query->fetchColumn()) throw new MobileError(409,'This robot is no longer on the event roster.');
    $current=mobile_pit($pdo,$user,(int)$event['id'],$team,true);
    if (!array_key_exists('baseVersion',$payload)||$payload['baseVersion']!==$current['version']) throw new MobileError(409,'Another scout changed this pit report. Review both versions before sending.', ['current'=>$current]);
    $status=$payload['status']??'';
    if (!in_array($status,['in_progress','complete'],true)) throw new MobileError(422,'Invalid pit status.');
    $answers=mobile_validate_pit(neptune_pit_all_questions($event['pit_config_json']),$payload['answers']??null,$status==='complete');
    // The native form does not edit autonomous drawings or other reserved fields.
    $query=$pdo->prepare('SELECT data_json FROM pit_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=?');$query->execute([(int)$user['organization_id'],(int)$event['id'],$team]);
    $old=$query->fetchColumn();
    if ($old) foreach(mobile_json($old) as $key=>$value) if(str_starts_with((string)$key,'_')) $answers[$key]=$value;
    $notes=mobile_text($payload['notes']??'','pit notes',20000);
    $owner=primary_team_id($pdo,(int)$user['id'],(int)$user['organization_id']);
    $pdo->prepare("INSERT INTO pit_scouting(organization_id,owner_team_id,event_id,frc_team_number,submitted_by,data_json,notes,status,started_at,completed_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),IF(?='complete',UTC_TIMESTAMP(),NULL)) ON DUPLICATE KEY UPDATE owner_team_id=COALESCE(VALUES(owner_team_id),owner_team_id),submitted_by=VALUES(submitted_by),data_json=VALUES(data_json),notes=VALUES(notes),status=VALUES(status),started_at=COALESCE(started_at,UTC_TIMESTAMP()),completed_at=IF(VALUES(status)='complete',UTC_TIMESTAMP(),completed_at)")->execute([(int)$user['organization_id'],$owner,(int)$event['id'],$team,(int)$user['id'],json_encode((object)$answers,JSON_THROW_ON_ERROR),$notes,$status,$status]);
    $query=$pdo->prepare('SELECT id FROM pit_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=?');$query->execute([(int)$user['organization_id'],(int)$event['id'],$team]);$pitId=(int)$query->fetchColumn();
    foreach($photos as $category=>$bytes) {
        $relative='uploads/pit/'.(int)$user['organization_id'].'/'.(int)$event['id'].'/'.$team;
        $directory=dirname(__DIR__).'/public_html/Neptune/'.$relative;
        if (!is_dir($directory)&&!mkdir($directory,0755,true)&&!is_dir($directory)) throw new MobileError(503,'Unable to create the photo directory.');
        $name='mobile-'.$category.'-'.bin2hex(random_bytes(16)).'.jpg';$path=$directory.'/'.$name;
        if (file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)) throw new MobileError(503,'Unable to store this photo.');
        $createdFiles[]=$path;
        // Keep prior files on disk for recovery; replace only the database slot.
        $pdo->prepare('DELETE FROM pit_scouting_photos WHERE pit_scouting_id=? AND organization_id=? AND category=?')->execute([$pitId,(int)$user['organization_id'],$category]);
        $pdo->prepare('INSERT INTO pit_scouting_photos(pit_scouting_id,organization_id,category,file_path,caption,uploaded_by) VALUES(?,?,?,?,?,?)')->execute([$pitId,(int)$user['organization_id'],$category,$relative.'/'.$name,'Native mobile photo',(int)$user['id']]);
    }
    return ['ok'=>true,'pit'=>mobile_pit($pdo,$user,(int)$event['id'],$team)];
}
function mobile_sync(PDO $pdo,array $user,array $body): array {
    $id=mobile_uuid($body['id']??null);$kind=$body['kind']??'';$payload=$body['payload']??null;
    if (!in_array($kind,['match','pit'],true)||!is_array($payload)||($payload['kind']??'')!==$kind) throw new MobileError(422,'Invalid report type.');
    $hash=hash('sha256',mobile_canonical(['kind'=>$kind,'payload'=>$payload]));
    $query=$pdo->prepare('SELECT payload_hash,response_json FROM neptune_mobile_receipts WHERE organization_id=? AND user_id=? AND operation_id=?');
    $receiptArgs=[(int)$user['organization_id'],(int)$user['id'],$id];$query->execute($receiptArgs);$receipt=$query->fetch(PDO::FETCH_ASSOC);
    if ($receipt) {
        if (!hash_equals($receipt['payload_hash'],$hash)) throw new MobileError(409,'This operation ID was already used for a different report.');
        return (array)json_decode($receipt['response_json'],false,512,JSON_THROW_ON_ERROR);
    }
    $photos=$kind==='pit'?mobile_prepare_photos($payload['photos']??[]):[];
    $createdFiles=[];
    $pdo->beginTransaction();
    try {
        // A concurrent retry blocks on this unique key; all domain writes and the receipt commit together.
        $pdo->prepare('INSERT INTO neptune_mobile_receipts(organization_id,user_id,operation_id,payload_hash,response_json) VALUES(?,?,?,?,?)')->execute([...$receiptArgs,$hash,'{}']);
        $event=mobile_event($pdo,$user,mobile_int($payload['eventId']??null,'event ID'),true);
        if (!(int)$event['active']) throw new MobileError(409,'The event was archived. This report is retained for review.');
        if ((int)$event['game_revision_id']!==mobile_int($payload['revision']??null,'game revision')) throw new MobileError(409,'The event game revision changed. Review the saved report before sending.');
        $result=$kind==='match'?mobile_save_match($pdo,$user,$event,$payload,$id):mobile_save_pit($pdo,$user,$event,$payload,$photos,$createdFiles);
        $result['receipt']=$id;
        $pdo->prepare('UPDATE neptune_mobile_receipts SET response_json=? WHERE organization_id=? AND user_id=? AND operation_id=?')->execute([json_encode($result,JSON_THROW_ON_ERROR),...$receiptArgs]);
        $pdo->commit();
        // Assignment auto-completion is best effort and must not affect a committed receipt.
        if($kind==='pit'&&($payload['status']??'')==='complete') {
            try { require_once dirname(__DIR__).'/public_html/Neptune/_assignment_auto_complete.php'; nac_try_finish($pdo,(int)$user['organization_id'],'pit',(int)$event['id'],(int)$payload['team'],(int)$user['id']); } catch(Throwable $ignored) { error_log('Mobile pit assignment completion needs review.'); }
        }
        return $result;
    } catch (Throwable $error) {
        if($pdo->inTransaction())$pdo->rollBack();
        foreach($createdFiles as $file)if(is_file($file))unlink($file);
        if ($error instanceof PDOException && ($error->errorInfo[1]??0)===1062) {
            $query->execute($receiptArgs);$receipt=$query->fetch(PDO::FETCH_ASSOC);
            if($receipt&&hash_equals($receipt['payload_hash'],$hash))return (array)json_decode($receipt['response_json'],false,512,JSON_THROW_ON_ERROR);
            throw new MobileError(409,'This report or one of its actions was already submitted. Review its sync history.');
        }
        throw $error;
    }
}
function mobile_dispatch(PDO $pdo): never {
    // Native bearer requests do not use browser cookies or permissive CORS.
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    try {
        if (empty($GLOBALS['isHttps'])) throw new MobileError(400,'The mobile API requires HTTPS. Configure trusted proxy handling when TLS terminates at a proxy.');
        $action=$_GET['action']??'';$method=$_SERVER['REQUEST_METHOD']??'GET';
        if(in_array($action,['google-start','google-exchange'],true)){
            if($method!=='POST')throw new MobileError(405,'Use POST for Google sign-in.');
            require_once __DIR__.'/mobile-google.php';
            mobile_reply($action==='google-start'?mobile_google_start($pdo,mobile_body()):mobile_google_exchange($pdo,mobile_body()));
        }
        if($action==='login') {
            if($method!=='POST')throw new MobileError(405,'Use POST for sign-in.');
            mobile_reply(mobile_login($pdo,mobile_body()));
        }
        $user=mobile_authorize($pdo);
        if($action==='logout'&&$method==='POST') {
            $pdo->prepare('DELETE FROM neptune_mobile_tokens WHERE token_hash=?')->execute([$user['_mobile_token_hash']]);mobile_reply(['ok'=>true]);
        }
        if($action==='bootstrap'&&$method==='GET') {
            $query=$pdo->prepare('SELECT id,name,is_current FROM events WHERE organization_id=? AND active=1 ORDER BY is_current DESC,start_date DESC,id DESC');$query->execute([(int)$user['organization_id']]);
            mobile_reply(['ok'=>true,'identity'=>mobile_identity($user),'events'=>array_map(fn($row)=>['id'=>(int)$row['id'],'name'=>$row['name'],'current'=>(bool)$row['is_current']],$query->fetchAll(PDO::FETCH_ASSOC))]);
        }
        if($action==='event'&&$method==='GET')mobile_reply(mobile_bundle($pdo,$user,mobile_int($_GET['event_id']??null,'event ID')));
        if($action==='sync'&&$method==='POST')mobile_reply(mobile_sync($pdo,$user,mobile_body()));
        throw new MobileError(404,'Unknown mobile API route.');
    } catch(MobileError $error) { mobile_reply(['ok'=>false,'message'=>$error->getMessage(),'detail'=>(object)$error->detail],$error->status); }
    catch(Throwable $error) {
        error_log('Neptune mobile API failed: '.get_class($error).' '.$error->getMessage());
        mobile_reply(['ok'=>false,'message'=>'The mobile service is unavailable. Reports remain on your phone. Ask an administrator to check the mobile API installation.'],503);
    }
}
