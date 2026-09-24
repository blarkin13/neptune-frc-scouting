<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
$u=require_login();$org=(int)$u['organization_id'];
if(!spot_tables_ready($pdo))exit('Spot Scouting is not installed.');
$format=strtolower(trim((string)($_GET['format']??'csv')));if(!in_array($format,['csv','json'],true))$format='csv';
$eventId=(int)($_GET['event_id']??0);$team=(int)($_GET['team']??0);
$sql="SELECT o.id,o.uuid,o.created_at,o.frc_team_number,o.context,o.entry_mode,o.field_id,o.severity,o.status,o.note,o.resolved_at,o.resolution_note,
  e.name event_name,e.tba_event_key,m.comp_level,m.set_number,m.match_number,u.display_name scout_name,ru.display_name resolved_by_name,
  GROUP_CONCAT(DISTINCT t.label ORDER BY t.category,t.sort_order,t.label SEPARATOR ' | ') tags,
  COUNT(DISTINCT CASE WHEN med.media_type='photo' THEN med.id END) photos,
  COUNT(DISTINCT CASE WHEN med.media_type='video' THEN med.id END) videos
  FROM spot_observations o
  LEFT JOIN events e ON e.id=o.event_id LEFT JOIN matches m ON m.id=o.match_id
  LEFT JOIN users u ON u.id=o.created_by LEFT JOIN users ru ON ru.id=o.resolved_by
  LEFT JOIN spot_observation_tags ot ON ot.observation_id=o.id LEFT JOIN spot_tags t ON t.id=ot.tag_id
  LEFT JOIN spot_observation_media med ON med.observation_id=o.id
  WHERE o.organization_id=?";$args=[$org];
if($eventId>0){$sql.=' AND o.event_id=?';$args[]=$eventId;}if($team>0){$sql.=' AND o.frc_team_number=?';$args[]=$team;}
$sql.=' GROUP BY o.id ORDER BY o.created_at DESC,o.id DESC';
$s=$pdo->prepare($sql);$s->execute($args);$rows=$s->fetchAll();
foreach($rows as &$r){$r['match_label']=!empty($r['match_number'])?neptune_match_label($r):null;}unset($r);
$stamp=date('Ymd-His');
if($format==='json'){
    header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="neptune-spot-scouting-'.$stamp.'.json"');
    echo json_encode(['generated_at'=>date(DATE_ATOM),'organization_id'=>$org,'filters'=>['event_id'=>$eventId?:null,'team'=>$team?:null],'observations'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);exit;
}
header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="neptune-spot-scouting-'.$stamp.'.csv"');
$out=fopen('php://output','wb');fputcsv($out,['ID','Created','Team','Event','TBA Event','Field','Match','Context','Entry Mode','Signal','Status','Tags','Note','Scout','Resolved At','Resolved By','Resolution Note','Photos','Videos']);
foreach($rows as $r)fputcsv($out,[$r['id'],$r['created_at'],$r['frc_team_number'],$r['event_name'],$r['tba_event_key'],$r['field_id'],$r['match_label'],$r['context'],$r['entry_mode'],$r['severity'],$r['status'],$r['tags'],$r['note'],$r['scout_name'],$r['resolved_at'],$r['resolved_by_name'],$r['resolution_note'],$r['photos'],$r['videos']]);
fclose($out);exit;
