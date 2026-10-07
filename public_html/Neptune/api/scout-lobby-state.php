<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_scouting_realtime.php';
$u=require_login();
$snapshot=neptune_scouting_lobby_snapshot($pdo,(int)$u['organization_id']);
json_response([
    'status'=>'success',
    'version'=>$snapshot['version'],
    'active_count'=>count($snapshot['matches']),
]);
