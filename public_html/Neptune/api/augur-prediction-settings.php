<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_alliance_helpers.php';

$u=require_login();
$org=(int)$u['organization_id'];
$userId=(int)($u['id']??0);
$canEdit=in_array((string)$u['role'],['owner','admin','strategy'],true);
$eventId=(int)($_REQUEST['event_id']??0);

if($eventId<1) json_response(['ok'=>false,'message'=>'Missing event.'],400);
$event=alliance_event($pdo,$org,$eventId);
if(!$event) json_response(['ok'=>false,'message'=>'Event not found for this organization.'],404);
if(!alliance_schema_ready($pdo)) json_response(['ok'=>false,'message'=>'Alliance Selection state storage is required before shared AUGUR model settings can be saved.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $loaded=alliance_load_workspace($pdo,$org,$eventId,false);
    json_response([
        'ok'=>true,
        'can_edit'=>$canEdit,
        'settings'=>augur_prediction_settings($loaded['workspace']['settings']['matchup_model']??[]),
    ]);
}

if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
verify_csrf();
if(!$canEdit) access_denied('AUGUR prediction settings require Strategy, Admin, or Owner access.');

$raw=json_decode((string)($_POST['settings_json']??''),true);
if(!is_array($raw)) json_response(['ok'=>false,'message'=>'Invalid prediction settings.'],400);
$settings=augur_prediction_settings($raw);

try{
    $pdo->beginTransaction();
    $loaded=alliance_load_workspace($pdo,$org,$eventId,true);
    $workspace=$loaded['workspace'];
    $workspace['settings']??=[];
    $workspace['settings']['matchup_model']=$settings;
    $version=alliance_save_workspace($pdo,$org,$eventId,$workspace,$userId);
    $pdo->commit();
    json_response([
        'ok'=>true,
        'settings'=>$settings,
        'version'=>$version,
        'message'=>'AUGUR prediction model settings saved.',
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    json_response(['ok'=>false,'message'=>$e->getMessage()],400);
}
