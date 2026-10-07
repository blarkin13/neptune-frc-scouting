<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_tba_scheduler.php';

$u=require_role(['owner','admin','strategy']);
if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['ok'=>false,'message'=>'POST required.'],405);
verify_csrf();
if(!neptune_tba_scheduler_tables_ready($pdo)){
    json_response(['ok'=>false,'message'=>'TBA scheduler database migration is not installed.'],503);
}

$org=(int)$u['organization_id'];
$enabled=filter_var($_POST['enabled']??false,FILTER_VALIDATE_BOOLEAN)?1:0;
$uid=(int)($u['id']??0);$uid=$uid>0?$uid:null;

$event=$pdo->prepare("SELECT id,name,tba_event_key FROM events WHERE organization_id=? AND active=1 AND is_current=1 ORDER BY COALESCE(start_date,end_date,'1900-01-01') DESC,id DESC LIMIT 1");
$event->execute([$org]);$current=$event->fetch()?:null;
if($enabled){
    if(!$current)json_response(['ok'=>false,'message'=>'Select a current event before enabling At Event Live.'],409);
    if(trim((string)($current['tba_event_key']??''))==='')json_response(['ok'=>false,'message'=>'The current event must be linked to TBA before enabling At Event Live.'],409);
}

$s=$pdo->prepare("INSERT INTO neptune_tba_live_state(organization_id,enabled,updated_by)
    VALUES(?,?,?)
    ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
$s->execute([$org,$enabled,$uid]);

$settings=neptune_tba_schedule_settings($pdo);

json_response([
    'ok'=>true,
    'enabled'=>(bool)$enabled,
    'interval_minutes'=>(int)$settings['live_interval_minutes'],
    'event'=>$current?['id'=>(int)$current['id'],'name'=>(string)$current['name'],'linked_to_tba'=>trim((string)($current['tba_event_key']??''))!=='']:null,
]);
