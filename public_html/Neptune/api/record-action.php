<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_validate-scout-action.php';
$u=require_login();
$d=json_decode(file_get_contents('php://input'),true);
if(!is_array($d)) json_response(['status'=>'error','message'=>'Invalid JSON body.'],422);
$sid=(int)($d['session_id']??0);
if($sid<1) json_response(['status'=>'error','message'=>'Invalid scout session.'],422);
$s=$pdo->prepare('SELECT ss.*,m.game_id,m.run_number,m.state,gr.match_config_json config_json FROM scout_sessions ss JOIN matches m ON m.id=ss.match_id AND m.organization_id=ss.organization_id AND m.event_id=ss.event_id JOIN events e ON e.id=ss.event_id AND e.organization_id=ss.organization_id AND e.id=m.event_id JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=m.game_id WHERE ss.id=? AND ss.organization_id=? AND ss.user_id=?');
$s->execute([$sid,$u['organization_id'],$u['id']]);$ss=$s->fetch(PDO::FETCH_ASSOC);
if(!$ss) json_response(['status'=>'error','message'=>'Invalid scout session'],403);
if((int)$ss['match_run_number']!==(int)$ss['run_number']) json_response(['status'=>'error','message'=>'This scout session belongs to an older match run. Return to Agent and reselect the station.'],409);
if($ss['state']!=='running') json_response(['status'=>'error','message'=>'The match is not currently running.'],409);
try {
    $validated=neptune_validated_scout_action($d['action']??null,$d['result']??'Neutral',$d['match_time_sec']??null,$ss['config_json']);
} catch(InvalidArgumentException $e) {
    json_response(['status'=>'error','message'=>$e->getMessage()],422);
} catch(Throwable $e) {
    error_log('[Scouting validation] '.get_class($e));
    json_response(['status'=>'error','message'=>'Game revision could not be validated.'],500);
}
$station=(int)$ss['station'];
$team=$pdo->prepare('SELECT frc_team_number FROM match_teams WHERE match_id=? AND alliance=? AND station=?');
$team->execute([(int)$ss['match_id'],(string)$ss['alliance'],$station]);
if((int)$team->fetchColumn()!==(int)$ss['frc_team_number']) json_response(['status'=>'error','message'=>'Assigned robot changed. Reopen the match.'],409);
$uuid=uuidv4();$ownerTeamId=primary_team_id($pdo,(int)$u['id'],(int)$u['organization_id']);
$q='INSERT INTO scouting_actions(uuid,organization_id,owner_team_id,event_id,match_id,scout_session_id,game_id,frc_team_number,alliance,action_code,action_name,action_type,location,result,points,match_time_sec,match_run_number,phase,source,source_ip,created_by,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? ,\'scout\',?,?,UTC_TIMESTAMP())';
$pdo->prepare($q)->execute([$uuid,$u['organization_id'],$ownerTeamId,$ss['event_id'],$ss['match_id'],$sid,$ss['game_id'],$ss['frc_team_number'],$ss['alliance'],$validated['code'],$validated['name'],$validated['type'],$validated['location'],$validated['result'],$validated['points'],$validated['sec'],(int)$ss['run_number'],$validated['phase'],$_SERVER['REMOTE_ADDR']??null,$u['id']]);
$actionId=(int)$pdo->lastInsertId();
$pdo->prepare("UPDATE scout_sessions SET last_seen_at=UTC_TIMESTAMP(),status='scouting' WHERE id=? AND organization_id=? AND user_id=?")->execute([$sid,$u['organization_id'],$u['id']]);
$s=$pdo->prepare("SELECT COUNT(*) action_count,COALESCE(SUM(points),0) score FROM scouting_actions WHERE organization_id=? AND scout_session_id=? AND match_run_number=? AND deleted_at IS NULL");
$s->execute([$u['organization_id'],$sid,(int)$ss['run_number']]);$sum=$s->fetch()?:['action_count'=>0,'score'=>0];
json_response(['status'=>'success','uuid'=>$uuid,'id'=>$actionId,'scout_summary'=>[
    'action_count'=>(int)$sum['action_count'],'score'=>(float)$sum['score'],
    'last_action'=>['action_name'=>$validated['name'],'action_code'=>$validated['code'],'result'=>$validated['result'],'points'=>$validated['points']]
]]);
