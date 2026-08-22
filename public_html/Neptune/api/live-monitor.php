<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);$id=(int)($_GET['match_id']??0);$all=!empty($_GET['all']);
$s=$pdo->prepare('SELECT m.*,e.name event_name FROM matches m JOIN events e ON e.id=m.event_id WHERE m.id=? AND m.organization_id=?');$s->execute([$id,$u['organization_id']]);$match=$s->fetch()?:[];
if(!$match) json_response(['error'=>'match not found'],404);$run=(int)($match['run_number']??1);
$s=$pdo->prepare('SELECT alliance,station,frc_team_number FROM match_teams WHERE match_id=? ORDER BY FIELD(alliance,\'Red\',\'Blue\'),station');$s->execute([$id]);$matchTeams=$s->fetchAll();
$s=$pdo->prepare("SELECT ss.id,ss.scout_name,ss.frc_team_number,ss.alliance,ss.station,ss.status,ss.last_seen_at,ss.match_run_number FROM scout_sessions ss INNER JOIN (SELECT alliance,station,MAX(id) id FROM scout_sessions WHERE match_id=? AND organization_id=? AND match_run_number=? GROUP BY alliance,station) latest ON latest.id=ss.id ORDER BY FIELD(ss.alliance,'Red','Blue'),ss.station");$s->execute([$id,$u['organization_id'],$run]);$scouts=$s->fetchAll();
$sql='SELECT a.id,a.match_time_sec,a.frc_team_number,a.alliance,a.action_name,a.action_code,a.result,a.points,a.source,a.recorded_at,ss.scout_name FROM scouting_actions a LEFT JOIN scout_sessions ss ON ss.id=a.scout_session_id WHERE a.match_id=? AND a.organization_id=? AND a.match_run_number=? AND a.deleted_at IS NULL ORDER BY a.id DESC'.($all?'':' LIMIT 50');
$s=$pdo->prepare($sql);$s->execute([$id,$u['organization_id'],$run]);$actions=$s->fetchAll();
json_response(['match'=>$match,'match_teams'=>$matchTeams,'scouts'=>$scouts,'actions'=>$actions,'showing_all'=>$all]);
