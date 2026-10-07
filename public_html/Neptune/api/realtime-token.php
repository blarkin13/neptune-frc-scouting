<?php
declare(strict_types=1);

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_scouting_realtime.php';

$u=require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $credentials=neptune_scouting_realtime_credentials((int)$u['organization_id']);
    json_response([
        'status'=>'success',
        'room'=>$credentials['room'],
        'scope'=>$credentials['scope'],
        'expires'=>$credentials['expires'],
        'token'=>$credentials['token'],
    ]);
} catch (Throwable $e) {
    json_response([
        'status'=>'error',
        'error'=>'realtime_unavailable',
        'message'=>'Private realtime is unavailable. Neptune will continue using HTTP polling.',
    ],503);
}
