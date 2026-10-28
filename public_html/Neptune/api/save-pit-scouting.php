<?php
/** Dedicated answers-only save. Does not accept or process photographs. */
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/pit/_helpers.php';
header('Content-Type: application/json; charset=utf-8');
function neptune_pit_save_reply(int $status, string $message, int $pitId=0): never {
    http_response_code($status);
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>$status===200,'message'=>$message,'pit_id'=>$pitId], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')neptune_pit_save_reply(405,'POST required.');
    if(empty($_POST) && (int)($_SERVER['CONTENT_LENGTH']??0)>0)neptune_pit_save_reply(413,'Form request exceeds the PHP post_max_size limit.');
    $u=require_login();
    verify_csrf();
    $org=(int)$u['organization_id'];
    $eventId=(int)($_POST['event_id']??0);
    $team=(int)($_POST['team']??0);
    if($eventId<1||$team<1)neptune_pit_save_reply(422,'Event and team are required.');
    $s=$pdo->prepare('SELECT e.game_id,gr.pit_config_json FROM events e JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id JOIN event_teams et ON et.event_id=e.id AND et.frc_team_number=? WHERE e.id=? AND e.organization_id=? LIMIT 1');
    $s->execute([$team,$eventId,$org]);
    $event=$s->fetch(PDO::FETCH_ASSOC);
    if(!$event)neptune_pit_save_reply(404,'Team is not on the selected organization event roster.');
    $questions=neptune_pit_all_questions($event['pit_config_json']??null);
    $posted=is_array($_POST['pit']??null)?$_POST['pit']:[];
    $clean=[];$missing=[];
    $mode=(string)($_POST['save_mode']??'complete');
    $status=$mode==='draft'?'in_progress':'complete';
    foreach($questions as $q){
        $code=(string)($q['code']??'');
        if($code==='')continue;
        $type=(string)($q['type']??'text');
        $v=$posted[$code]??($type==='multiselect'?[]:'');
        if($type==='multiselect'){
            $v=is_array($v)?array_values(array_filter(array_map('trim',$v),static fn($x)=>$x!=='')):[];
        }else{
            $v=is_array($v)?'':trim((string)$v);
        }
        if(!empty($q['required'])&&$status==='complete'&&($v===''||$v===[]))$missing[]=(string)($q['label']??$code);
        $clean[$code]=$v;
    }
    if($missing)neptune_pit_save_reply(422,'Complete required fields: '.implode(', ',$missing));
    $existing=$pdo->prepare('SELECT id,data_json FROM pit_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=? LIMIT 1');
    $existing->execute([$org,$eventId,$team]);
    $previous=$existing->fetch(PDO::FETCH_ASSOC)?:null;
    $oldData=$previous?json_decode((string)$previous['data_json'],true):[];
    if(!is_array($oldData))$oldData=[];
    $raw=trim((string)($_POST['auton_path_json']??''));
    if($raw!==''){
        $auton=json_decode($raw,true);
        if(!is_array($auton)||!isset($auton['strokes'])||!is_array($auton['strokes']))neptune_pit_save_reply(422,'Invalid autonomous path data.');
        $clean['_auton_path']=$auton;
    }elseif(isset($oldData['_auton_path'])&&is_array($oldData['_auton_path'])){
        $clean['_auton_path']=$oldData['_auton_path'];
    }
    $json=json_encode($clean,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $notes=trim((string)($_POST['notes']??''));
    $ownerTeam=primary_team_id($pdo,(int)$u['id'],$org);
    $sql="INSERT INTO pit_scouting(organization_id,owner_team_id,event_id,frc_team_number,submitted_by,data_json,notes,status,started_at,completed_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),IF(?='complete',UTC_TIMESTAMP(),NULL)) ON DUPLICATE KEY UPDATE owner_team_id=COALESCE(VALUES(owner_team_id),owner_team_id),submitted_by=VALUES(submitted_by),data_json=VALUES(data_json),notes=VALUES(notes),status=VALUES(status),started_at=COALESCE(started_at,UTC_TIMESTAMP()),completed_at=IF(VALUES(status)='complete',UTC_TIMESTAMP(),completed_at)";
    $pdo->prepare($sql)->execute([$org,$ownerTeam,$eventId,$team,(int)$u['id'],$json,$notes,$status,$status]);
    $s=$pdo->prepare('SELECT id FROM pit_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=? LIMIT 1');
    $s->execute([$org,$eventId,$team]);
    $pitId=(int)$s->fetchColumn();
    if(!$pitId)neptune_pit_save_reply(500,'Unable to verify pit scouting save.');
    if($status==='complete') { require_once dirname(__DIR__).'/_assignment_auto_complete.php'; nac_try_finish($pdo,$org,'pit',$eventId,$team,(int)$u['id']); }
    neptune_pit_save_reply(200,$status==='complete'?'Pit scouting marked complete.':'Draft saved.',$pitId);
}catch(Throwable $e){
    error_log('Neptune pit answer save failed: '.$e->getMessage());
    neptune_pit_save_reply(500,'The server could not save this pit record. Check Neptune server logs.');
}
