<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$d=json_decode(file_get_contents('php://input'),true)?:[];
$sid=(int)($d['session_id']??0);
if($sid<1) json_response(['status'=>'error','message'=>'Invalid scout session'],422);

$s=$pdo->prepare('SELECT ss.id,ss.match_id,ss.match_run_number,m.run_number,m.state FROM scout_sessions ss JOIN matches m ON m.id=ss.match_id WHERE ss.id=? AND ss.organization_id=? AND ss.user_id=?');
$s->execute([$sid,$u['organization_id'],$u['id']]);
$ss=$s->fetch();
if(!$ss) json_response(['status'=>'error','message'=>'Invalid scout session'],403);
if((int)$ss['match_run_number']!==(int)$ss['run_number']) json_response(['status'=>'error','message'=>'This scout session belongs to an older match run.'],409);
if(!in_array((string)$ss['state'],['running','paused'],true)) json_response(['status'=>'error','message'=>'Actions can only be undone while the match is running or paused.'],409);

$pdo->beginTransaction();
try{
    $s=$pdo->prepare("SELECT id,action_name,action_code,result,points FROM scouting_actions WHERE organization_id=? AND scout_session_id=? AND match_run_number=? AND created_by=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $s->execute([$u['organization_id'],$sid,(int)$ss['run_number'],$u['id']]);
    $last=$s->fetch();
    if(!$last){
        $pdo->rollBack();
        json_response(['status'=>'error','message'=>'There is no action to undo.'],404);
    }

    $pdo->prepare("UPDATE scouting_actions SET deleted_at=UTC_TIMESTAMP(),deleted_by=?,deletion_reason='scout_undo' WHERE id=? AND deleted_at IS NULL")
        ->execute([$u['id'],(int)$last['id']]);

    $s=$pdo->prepare("SELECT COUNT(*) action_count,COALESCE(SUM(points),0) score FROM scouting_actions WHERE organization_id=? AND scout_session_id=? AND match_run_number=? AND deleted_at IS NULL");
    $s->execute([$u['organization_id'],$sid,(int)$ss['run_number']]);
    $sum=$s->fetch()?:['action_count'=>0,'score'=>0];

    $s=$pdo->prepare("SELECT action_name,action_code,result,points FROM scouting_actions WHERE organization_id=? AND scout_session_id=? AND match_run_number=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
    $s->execute([$u['organization_id'],$sid,(int)$ss['run_number']]);
    $newLast=$s->fetch()?:null;

    $pdo->commit();
    json_response([
        'status'=>'success',
        'undone'=>[
            'action_name'=>(string)($last['action_name']?:$last['action_code']),
            'result'=>(string)$last['result'],
            'points'=>(float)$last['points'],
        ],
        'scout_summary'=>[
            'action_count'=>(int)$sum['action_count'],
            'score'=>(float)$sum['score'],
            'last_action'=>$newLast,
        ],
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    json_response(['status'=>'error','message'=>'Unable to undo the last action.'],500);
}
