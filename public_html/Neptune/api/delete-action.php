<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);
$d=json_decode(file_get_contents('php://input'),true)?:[];
$actionId=(int)($d['action_id']??0);$matchId=(int)($d['match_id']??0);$csrf=(string)($d['csrf']??'');
if(!$actionId||!$matchId) json_response(['status'=>'error','message'=>'Action and match are required.'],422);
if(!hash_equals($_SESSION['csrf']??'', $csrf)) json_response(['status'=>'error','message'=>'Invalid CSRF token.'],419);
$s=$pdo->prepare('SELECT a.id,a.deleted_at,a.match_run_number,m.run_number FROM scouting_actions a JOIN matches m ON m.id=a.match_id WHERE a.id=? AND a.match_id=? AND a.organization_id=? AND m.organization_id=?');
$s->execute([$actionId,$matchId,$u['organization_id'],$u['organization_id']]);$a=$s->fetch();
if(!$a) json_response(['status'=>'error','message'=>'Action not found.'],404);
if($a['deleted_at']) json_response(['status'=>'success','already_deleted'=>true]);
if((int)$a['match_run_number']!==(int)$a['run_number']) json_response(['status'=>'error','message'=>'That action belongs to a superseded scouting run.'],409);
$pdo->prepare("UPDATE scouting_actions SET deleted_at=UTC_TIMESTAMP(),deleted_by=?,deletion_reason='admin_delete' WHERE id=? AND organization_id=? AND deleted_at IS NULL")
    ->execute([$u['id'],$actionId,$u['organization_id']]);
json_response(['status'=>'success','action_id'=>$actionId]);
