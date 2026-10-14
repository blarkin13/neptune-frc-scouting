<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_validate-scout-action.php';
$u=require_role(['owner','admin','strategy']);
$d=json_decode(file_get_contents('php://input'),true);
if(!is_array($d)) json_response(['status'=>'error','message'=>'Invalid JSON body.'],422);
$mid=(int)($d['match_id']??0);
$alliance=(string)($d['alliance']??'');$station=$d['station']??null;
if($mid<1 || !in_array($alliance,['Red','Blue'],true) || !in_array($station,[1,2,3,'1','2','3'],true)) json_response(['status'=>'error','message'=>'Invalid match station.'],422);
$station=(int)$station;
$s=$pdo->prepare('SELECT m.*,gr.match_config_json config_json FROM matches m JOIN events e ON e.id=m.event_id AND e.organization_id=m.organization_id JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=m.game_id WHERE m.id=? AND m.organization_id=?');
$s->execute([$mid,$u['organization_id']]);$m=$s->fetch(PDO::FETCH_ASSOC);
if(!$m) json_response(['status'=>'error','message'=>'Match not found.'],404);
$s=$pdo->prepare('SELECT frc_team_number FROM match_teams WHERE match_id=? AND alliance=? AND station=?');
$s->execute([$mid,$alliance,$station]);$robot=(int)$s->fetchColumn();
if(!$robot) json_response(['status'=>'error','message'=>'No robot assigned to that station.'],422);
try {
    $validated=neptune_validated_scout_action(['code'=>$d['action_code']??null],$d['result']??'Neutral',$d['match_time_sec']??null,$m['config_json']);
} catch(InvalidArgumentException $e) {
    json_response(['status'=>'error','message'=>$e->getMessage()],422);
} catch(Throwable $e) {
    error_log('[Admin scouting validation] '.get_class($e));
    json_response(['status'=>'error','message'=>'Game revision could not be validated.'],500);
}
$uuid=uuidv4();$ownerTeamId=primary_team_id($pdo,(int)$u['id'],(int)$u['organization_id']);
$pdo->prepare("INSERT INTO scouting_actions(uuid,organization_id,owner_team_id,event_id,match_id,game_id,frc_team_number,alliance,action_code,action_name,action_type,location,result,points,match_time_sec,match_run_number,phase,source,created_by,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'admin',?,UTC_TIMESTAMP())")
    ->execute([$uuid,$u['organization_id'],$ownerTeamId,$m['event_id'],$mid,$m['game_id'],$robot,$alliance,$validated['code'],$validated['name'],$validated['type'],$validated['location'],$validated['result'],$validated['points'],$validated['sec'],(int)($m['run_number']??1),$validated['phase'],$u['id']]);
json_response(['status'=>'success','robot'=>$robot,'alliance'=>$alliance,'station'=>$station,'run_number'=>(int)$m['run_number'],'points'=>$validated['points']]);
