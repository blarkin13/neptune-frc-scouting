<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_robot_recall.php';
$u=require_role(['owner','admin','strategy']);
header('Cache-Control: no-store, no-cache, must-revalidate');
try{
    robot_recall_ensure_schema($pdo);
    $sessionId=(int)($_REQUEST['session_id']??0);
    $session=robot_recall_host_session($pdo,$sessionId,(int)$u['organization_id']);
    if(!$session)json_response(['ok'=>false,'message'=>'Robot Recall session not found.'],404);
    $action=(string)($_REQUEST['action']??'state');
    if($action==='state')json_response(['ok'=>true,'state'=>robot_recall_host_state($pdo,$session)]);
    if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'message'=>'POST required.'],405);
    verify_csrf();
    $session=robot_recall_host_transition($pdo,$session,$action);
    json_response(['ok'=>true,'state'=>robot_recall_host_state($pdo,$session)]);
}catch(RuntimeException $e){json_response(['ok'=>false,'message'=>$e->getMessage()],400);
}catch(Throwable $e){json_response(['ok'=>false,'message'=>'Robot Recall host control failed.'],500);}
