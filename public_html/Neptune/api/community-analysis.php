<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis.php';
header('Cache-Control: no-store');
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis-install.php';
$settings=community_analysis_settings($pdo,$config);
if (empty($settings['enabled'])) json_response(['error'=>'Feature not enabled.'],404);
try {
    $action=(string)($_GET['action']??'');
    if(!in_array($action,['events','event_matches'],true))json_response(['error'=>'Match summaries have been retired. Use community Watch & tag for robot tags and scoring estimates.'],410);
    if(in_array($action,['connect_start','connect_exchange'],true)) {
        if($_SERVER['REQUEST_METHOD']!=='POST')throw new CommunityAnalysisError(405,'Method not allowed.');
        require_once dirname(__DIR__,3).'/neptune_secure/community-pairing.php';
        community_pairing_schema($pdo);
        $raw=file_get_contents('php://input',false,null,0,4097);
        if(strlen($raw)>4096)throw new CommunityAnalysisError(413,'Connection request too large.');
        $p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($p))throw new CommunityAnalysisError(422,'Expected connection details.');
        $pairing=new CommunityPairing($pdo,$settings);
        session_write_close();
        json_response($action==='connect_start'?$pairing->start($p,(string)($_SERVER['REMOTE_ADDR']??'')):$pairing->exchange($p));
    }
    require_once dirname(__DIR__,3).'/neptune_secure/community-catalog.php';
    community_catalog_schema($pdo);
    if($_SERVER['REQUEST_METHOD']==='GET'&&in_array($action,['events','event_matches'],true)){
        session_write_close();$catalog=new CommunityCatalog($pdo);
        json_response($action==='events'?['events'=>$catalog->events((int)($_GET['season']??gmdate('Y')))]:['matches'=>$catalog->matches((string)($_GET['event']??''))]);
    }
    $service=new CommunityAnalysis($pdo,(string)($settings['secret']??''));
    $action=(string)($_GET['action']??'');
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='public') json_response(['matches'=>$service->catalog(max(0,(int)($_GET['after']??0)),true)]);
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='published') json_response($service->published((int)($_GET['match_id']??0)));
    $headers=function_exists('getallheaders')?getallheaders():[];
    $authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??$headers['Authorization']??$headers['authorization']??'');
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$bearer)) throw new CommunityAnalysisError(401,'Helper authentication required.');
    $s=$pdo->prepare('SELECT d.*,u.password_hash,u.active,u.must_change_password,o.platform_status FROM community_devices d JOIN users u ON u.id=d.user_id JOIN organizations o ON o.id=u.organization_id WHERE d.token_hash=? AND d.revoked=0 AND d.expires_at>?');
    $s->execute([hash('sha256',$bearer[1]),time()]); $device=$s->fetch(PDO::FETCH_ASSOC);
    if (!$device || !(int)$device['active'] || (int)$device['must_change_password'] || ($device['platform_status']??'active')!=='active' || !hash_equals($device['credential_revision'],hash('sha256',$device['password_hash']))) throw new CommunityAnalysisError(401,'Helper credentials expired or revoked.');
    $uid=(int)$device['user_id'];
    if (!in_array($uid,array_map('intval',$settings['contributor_user_ids']??[]),true)) throw new CommunityAnalysisError(403,'Contributor access is not enabled for this account.');
    // Helpers cannot review, publish, read private scouting, or provision other devices.
    session_write_close();
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='work') json_response(['matches'=>$service->catalog(max(0,(int)($_GET['after']??0)))]);
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new CommunityAnalysisError(405,'Method not allowed.');
    $raw=file_get_contents('php://input',false,null,0,1048577);
    if (strlen($raw)>1048576) throw new CommunityAnalysisError(413,'Request exceeds 1 MiB.');
    $p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($p)) throw new CommunityAnalysisError(422,'Expected an object.');
    if ($action==='claim') json_response($service->claim((int)($p['match_id']??0),$uid,$device['id'],(string)($p['request_key']??'')));
    $id=(string)($p['attempt_id']??''); $token=(string)($p['lease_token']??'');
    if ($action==='media') json_response($service->recordMedia($id,$uid,$device['id'],$token,(string)($p['source_sha256']??''),(int)($p['duration_ms']??0)));
    if ($action==='heartbeat') json_response($service->heartbeat($id,$uid,$device['id'],$token,(string)($p['stage']??'Processing')));
    if ($action==='release') { $service->release($id,$uid,$device['id'],$token); json_response(['released'=>true]); }
    if ($action==='submit') {
        if (!is_array($p['payload']??null)) throw new CommunityAnalysisError(422,'Payload required.');
        json_response($service->submit($id,$uid,$device['id'],$token,$p['payload']));
    }
    throw new CommunityAnalysisError(404,'Unknown action.');
} catch (CommunityAnalysisError $e) { json_response(['error'=>$e->getMessage()],$e->httpStatus); }
catch (JsonException $e) { json_response(['error'=>'Invalid JSON.'],422); }
catch (Throwable $e) { error_log('Community analysis API: '.get_class($e)); json_response(['error'=>'Analysis service unavailable.'],503); }
