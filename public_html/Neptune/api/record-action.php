<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$d=json_decode(file_get_contents('php://input'),true)?:[];
$sid=(int)($d['session_id']??0);$a=$d['action']??[];$result=$d['result']??'Neutral';
if(!in_array($result,['Success','Failure','Neutral'],true)) json_response(['status'=>'error','message'=>'Bad result'],422);
$s=$pdo->prepare('SELECT ss.*,m.game_id,m.run_number,m.state,g.config_json FROM scout_sessions ss JOIN matches m ON m.id=ss.match_id JOIN games g ON g.id=m.game_id WHERE ss.id=? AND ss.organization_id=? AND ss.user_id=?');
$s->execute([$sid,$u['organization_id'],$u['id']]);$ss=$s->fetch();
if(!$ss) json_response(['status'=>'error','message'=>'Invalid scout session'],403);
if((int)$ss['match_run_number']!==(int)$ss['run_number']) json_response(['status'=>'error','message'=>'This scout session belongs to an older match run. Return to Agent and reselect the station.'],409);
if($ss['state']!=='running') json_response(['status'=>'error','message'=>'The match is not currently running.'],409);
$sec=isset($d['match_time_sec'])?(int)$d['match_time_sec']:null;
$uuid=uuidv4();$ownerTeamId=primary_team_id($pdo,(int)$u['id'],(int)$u['organization_id']);
$q='INSERT INTO scouting_actions(uuid,organization_id,owner_team_id,event_id,match_id,scout_session_id,game_id,frc_team_number,alliance,action_code,action_name,action_type,location,result,points,match_time_sec,match_run_number,phase,source,source_ip,created_by,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? ,\'scout\',?,?,UTC_TIMESTAMP())';
$pdo->prepare($q)->execute([$uuid,$u['organization_id'],$ownerTeamId,$ss['event_id'],$ss['match_id'],$sid,$ss['game_id'],$ss['frc_team_number'],$ss['alliance'],(string)($a['code']??''),(string)($a['name']??''),(string)($a['type']??''),(string)($a['location']??''),$result,(float)($d['points']??0),$sec,(int)$ss['run_number'],phase_for_game_time($sec,$ss['config_json']),$_SERVER['REMOTE_ADDR']??null,$u['id']]);
$actionId=(int)$pdo->lastInsertId();
$pdo->prepare("UPDATE scout_sessions SET last_seen_at=UTC_TIMESTAMP(),status='scouting' WHERE id=?")->execute([$sid]);
$s=$pdo->prepare("SELECT COUNT(*) action_count,COALESCE(SUM(points),0) score FROM scouting_actions WHERE organization_id=? AND scout_session_id=? AND match_run_number=? AND deleted_at IS NULL");
$s->execute([$u['organization_id'],$sid,(int)$ss['run_number']]);$sum=$s->fetch()?:['action_count'=>0,'score'=>0];
json_response(['status'=>'success','uuid'=>$uuid,'id'=>$actionId,'scout_summary'=>[
    'action_count'=>(int)$sum['action_count'],'score'=>(float)$sum['score'],
    'last_action'=>['action_name'=>(string)($a['name']??''),'action_code'=>(string)($a['code']??''),'result'=>$result,'points'=>(float)($d['points']??0)]
]]);
