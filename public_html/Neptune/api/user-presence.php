<?php
/** Authenticated Neptune presence heartbeat. A heartbeat is not a scouting session. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_json_request_guard.php';neptune_require_request_header();
$u=require_login();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input)||!hash_equals(csrf_token(),(string)($input['csrf']??''))){
    http_response_code(403);
    exit;
}
try{
    // Page category is a client-reported navigation indicator, not security/audit evidence.
    $pageKind=(string)($input['page_kind']??'other');
    if(!in_array($pageKind,['tag-match','tag-pit','tag-team','traditional','other'],true))$pageKind='other';
    $org=(int)$u['organization_id'];
    $uid=(int)$u['id'];
    $pdo->beginTransaction();
    try{
        $statement=$pdo->prepare('INSERT INTO neptune_user_presence (organization_id,user_id,last_seen_at) VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_seen_at=UTC_TIMESTAMP()');
        $statement->execute([$org,$uid]);
        $statement=$pdo->prepare('INSERT INTO neptune_user_page_presence (organization_id,user_id,page_kind,last_seen_at) VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_seen_at=UTC_TIMESTAMP()');
        $statement->execute([$org,$uid,$pageKind]);
        $pdo->commit();
    }catch(Throwable $writeError){if($pdo->inTransaction())$pdo->rollBack();throw $writeError;}
    http_response_code(204);
}catch(Throwable $e){
    error_log('[Neptune presence heartbeat] '.get_class($e));
    http_response_code(503);
}
