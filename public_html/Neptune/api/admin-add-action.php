<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);
$d=json_decode(file_get_contents('php://input'),true)?:[];
$mid=(int)($d['match_id']??0);$alliance=($d['alliance']??'Red')==='Blue'?'Blue':'Red';$station=max(1,min(3,(int)($d['station']??1)));
$s=$pdo->prepare('SELECT m.*,gr.match_config_json config_json FROM matches m JOIN events e ON e.id=m.event_id JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=m.game_id WHERE m.id=? AND m.organization_id=?');
$s->execute([$mid,$u['organization_id']]);$m=$s->fetch();
if(!$m) json_response(['error'=>'match not found'],404);
$s=$pdo->prepare('SELECT frc_team_number FROM match_teams WHERE match_id=? AND alliance=? AND station=?');$s->execute([$mid,$alliance,$station]);$robot=(int)$s->fetchColumn();
if(!$robot) json_response(['error'=>'No robot is assigned to that match station. Refresh the TBA schedule.'],422);
$actionCode=trim((string)($d['action_code']??''));if($actionCode==='') json_response(['error'=>'Action is required'],422);
$t=game_timing($m['config_json']);$sec=max(0,min($t['duration'],(int)($d['match_time_sec']??$t['duration'])));
$result=$d['result']??'Neutral';if(!in_array($result,['Success','Failure','Neutral'],true))$result='Neutral';
$uuid=uuidv4();$ownerTeamId=primary_team_id($pdo,(int)$u['id'],(int)$u['organization_id']);
$pdo->prepare("INSERT INTO scouting_actions(uuid,organization_id,owner_team_id,event_id,match_id,game_id,frc_team_number,alliance,action_code,action_name,action_type,location,result,points,match_time_sec,match_run_number,phase,source,created_by,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'admin',?,UTC_TIMESTAMP())")
    ->execute([$uuid,$u['organization_id'],$ownerTeamId,$m['event_id'],$mid,$m['game_id'],$robot,$alliance,$actionCode,trim((string)($d['action_name']??$actionCode)),trim((string)($d['action_type']??'')),trim((string)($d['location']??'')),$result,(float)($d['points']??0),$sec,(int)($m['run_number']??1),phase_for_game_time($sec,$m['config_json']),$u['id']]);
json_response(['status'=>'success','robot'=>$robot,'alliance'=>$alliance,'station'=>$station,'run_number'=>(int)$m['run_number']]);
