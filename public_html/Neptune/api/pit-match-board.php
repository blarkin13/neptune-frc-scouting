<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_pit_match_board.php';

$u=require_login();
$org=(int)$u['organization_id'];
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'message'=>'POST required.'],405);
verify_csrf();
if(!neptune_pit_board_ensure_schema($pdo))json_response(['ok'=>false,'message'=>'Pit Match Board storage could not be initialized. Check database CREATE TABLE permissions.'],503);

$eventId=(int)($_POST['event_id']??0);
$team=(int)($_POST['team']??0);
$matchId=(int)($_POST['match_id']??0);
$action=trim((string)($_POST['action']??''));
if($eventId<1||$team<1)json_response(['ok'=>false,'message'=>'Missing event or team.'],422);

$s=$pdo->prepare('SELECT 1 FROM events e JOIN teams t ON t.organization_id=e.organization_id AND t.frc_team_number=? WHERE e.id=? AND e.organization_id=? LIMIT 1');
$s->execute([$team,$eventId,$org]);
if(!$s->fetchColumn())json_response(['ok'=>false,'message'=>'Event/team not found for this organization.'],404);

$allowedStatus=['unset','ready','working','issue','queued'];
$uid=(int)($u['id']??0);

try{
    if($action==='save_team'){
        $status=trim((string)($_POST['robot_status']??'unset'));
        if(!in_array($status,$allowedStatus,true))$status='unset';
        $battery=trim((string)($_POST['battery_label']??''));
        $battery=mb_substr($battery,0,80);
        $bumper=trim((string)($_POST['bumper_color']??''));
        if(!in_array($bumper,['','red','blue'],true))$bumper='';
        $inspection=!empty($_POST['inspection_ready'])?1:0;
        $drive=!empty($_POST['drive_team_ready'])?1:0;
        $note=mb_substr(trim((string)($_POST['pit_note']??'')),0,500);
        $s=$pdo->prepare("INSERT INTO pit_match_board_state
            (organization_id,event_id,frc_team_number,robot_status,battery_label,bumper_color,inspection_ready,drive_team_ready,pit_note,updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE robot_status=VALUES(robot_status),battery_label=VALUES(battery_label),bumper_color=VALUES(bumper_color),inspection_ready=VALUES(inspection_ready),drive_team_ready=VALUES(drive_team_ready),pit_note=VALUES(pit_note),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
        $s->execute([$org,$eventId,$team,$status,$battery,$bumper,$inspection,$drive,$note,$uid?:null]);
        // If the pit crew explicitly marks the robot QUEUED, keep the next-match
        // operations row and checklist in sync with that status.
        if($status==='queued'&&$matchId>0&&neptune_pit_board_match_belongs($pdo,$org,$eventId,$team,$matchId)){
            $ms=neptune_pit_board_match_state($pdo,$org,$eventId,$team,$matchId);
            $list=$ms['checklist'];foreach($list as &$item){if($item['id']==='queue')$item['done']=true;}unset($item);
            $q=$pdo->prepare("INSERT INTO pit_match_board_match_state (organization_id,event_id,frc_team_number,match_id,queue_lead_minutes,queued,checklist_json,match_note,updated_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE queued=1,checklist_json=VALUES(checklist_json),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
            $q->execute([$org,$eventId,$team,$matchId,(int)$ms['queue_lead_minutes'],1,json_encode($list,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$ms['match_note'],$uid?:null]);
        }
        json_response(['ok'=>true,'message'=>'Pit status saved.','state'=>neptune_pit_board_team_state($pdo,$org,$eventId,$team)]);
    }

    if($matchId<1||!neptune_pit_board_match_belongs($pdo,$org,$eventId,$team,$matchId))json_response(['ok'=>false,'message'=>'Match not found for this team/event.'],404);
    $state=neptune_pit_board_match_state($pdo,$org,$eventId,$team,$matchId);

    if($action==='save_match'){
        $lead=max(0,min(60,(int)($_POST['queue_lead_minutes']??$state['queue_lead_minutes'])));
        $note=mb_substr(trim((string)($_POST['match_note']??$state['match_note'])),0,500);
        $s=$pdo->prepare("INSERT INTO pit_match_board_match_state
            (organization_id,event_id,frc_team_number,match_id,queue_lead_minutes,queued,checklist_json,match_note,updated_by)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE queue_lead_minutes=VALUES(queue_lead_minutes),match_note=VALUES(match_note),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
        $s->execute([$org,$eventId,$team,$matchId,$lead,(int)$state['queued'],json_encode($state['checklist'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$note,$uid?:null]);
        json_response(['ok'=>true,'message'=>'Match setup saved.','state'=>neptune_pit_board_match_state($pdo,$org,$eventId,$team,$matchId)]);
    }

    if($action==='toggle_check'){
        $itemId=preg_replace('/[^a-zA-Z0-9_-]/','',trim((string)($_POST['item_id']??'')));
        $done=!empty($_POST['done']);
        $list=$state['checklist'];$found=false;
        foreach($list as &$item){if($item['id']===$itemId){$item['done']=$done;$found=true;break;}}unset($item);
        if(!$found)json_response(['ok'=>false,'message'=>'Checklist item not found.'],404);
        $queued=(int)$state['queued'];
        if($itemId==='queue')$queued=$done?1:0;
        $s=$pdo->prepare("INSERT INTO pit_match_board_match_state
            (organization_id,event_id,frc_team_number,match_id,queue_lead_minutes,queued,checklist_json,match_note,updated_by)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE queued=VALUES(queued),checklist_json=VALUES(checklist_json),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
        $s->execute([$org,$eventId,$team,$matchId,(int)$state['queue_lead_minutes'],$queued,json_encode($list,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$state['match_note'],$uid?:null]);
        if($itemId==='queue'){
            $robotStatus=$done?'queued':'ready';
            $s=$pdo->prepare("INSERT INTO pit_match_board_state (organization_id,event_id,frc_team_number,robot_status,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE robot_status=VALUES(robot_status),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
            $s->execute([$org,$eventId,$team,$robotStatus,$uid?:null]);
        }
        json_response(['ok'=>true,'message'=>'Checklist updated.','state'=>neptune_pit_board_match_state($pdo,$org,$eventId,$team,$matchId),'team_state'=>neptune_pit_board_team_state($pdo,$org,$eventId,$team)]);
    }

    if($action==='add_check'){
        $label=mb_substr(trim((string)($_POST['label']??'')),0,120);
        if($label==='')json_response(['ok'=>false,'message'=>'Enter a checklist item.'],422);
        $list=$state['checklist'];
        if(count($list)>=14)json_response(['ok'=>false,'message'=>'Checklist is limited to 14 items.'],422);
        $id='custom-'.substr(bin2hex(random_bytes(6)),0,12);
        $list[]=['id'=>$id,'label'=>$label,'done'=>false,'custom'=>true];
        $s=$pdo->prepare("INSERT INTO pit_match_board_match_state (organization_id,event_id,frc_team_number,match_id,queue_lead_minutes,queued,checklist_json,match_note,updated_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE checklist_json=VALUES(checklist_json),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
        $s->execute([$org,$eventId,$team,$matchId,(int)$state['queue_lead_minutes'],(int)$state['queued'],json_encode($list,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$state['match_note'],$uid?:null]);
        json_response(['ok'=>true,'message'=>'Checklist item added.','state'=>neptune_pit_board_match_state($pdo,$org,$eventId,$team,$matchId)]);
    }

    if($action==='delete_check'){
        $itemId=preg_replace('/[^a-zA-Z0-9_-]/','',trim((string)($_POST['item_id']??'')));
        $list=[];$removed=false;
        foreach($state['checklist'] as $item){
            if($item['id']===$itemId&&!empty($item['custom'])){$removed=true;continue;}
            $list[]=$item;
        }
        if(!$removed)json_response(['ok'=>false,'message'=>'Only custom checklist items can be removed.'],422);
        $s=$pdo->prepare("INSERT INTO pit_match_board_match_state (organization_id,event_id,frc_team_number,match_id,queue_lead_minutes,queued,checklist_json,match_note,updated_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE checklist_json=VALUES(checklist_json),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
        $s->execute([$org,$eventId,$team,$matchId,(int)$state['queue_lead_minutes'],(int)$state['queued'],json_encode($list,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$state['match_note'],$uid?:null]);
        json_response(['ok'=>true,'message'=>'Checklist item removed.','state'=>neptune_pit_board_match_state($pdo,$org,$eventId,$team,$matchId)]);
    }

    if($action==='mark_queued'){
        $queued=!empty($_POST['queued'])?1:0;
        $list=$state['checklist'];
        foreach($list as &$item){if($item['id']==='queue')$item['done']=(bool)$queued;}unset($item);
        $s=$pdo->prepare("INSERT INTO pit_match_board_match_state (organization_id,event_id,frc_team_number,match_id,queue_lead_minutes,queued,checklist_json,match_note,updated_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE queued=VALUES(queued),checklist_json=VALUES(checklist_json),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
        $s->execute([$org,$eventId,$team,$matchId,(int)$state['queue_lead_minutes'],$queued,json_encode($list,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$state['match_note'],$uid?:null]);
        $robotStatus=$queued?'queued':'ready';
        $s=$pdo->prepare("INSERT INTO pit_match_board_state (organization_id,event_id,frc_team_number,robot_status,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE robot_status=VALUES(robot_status),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
        $s->execute([$org,$eventId,$team,$robotStatus,$uid?:null]);
        json_response(['ok'=>true,'message'=>$queued?'Robot marked queued.':'Queue status cleared.','state'=>neptune_pit_board_match_state($pdo,$org,$eventId,$team,$matchId),'team_state'=>neptune_pit_board_team_state($pdo,$org,$eventId,$team)]);
    }

    json_response(['ok'=>false,'message'=>'Unknown Pit Match Board action.'],422);
}catch(Throwable $e){
    json_response(['ok'=>false,'message'=>$e->getMessage()],500);
}
