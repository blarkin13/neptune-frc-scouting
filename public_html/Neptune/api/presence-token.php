<?php
/** Authenticated per-user WebSocket credential; no client-selectable identity. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_scouting_realtime.php';
$u=require_login();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try {
    $credentials=neptune_presence_realtime_credentials((int)$u['organization_id'],(int)$u['id']);
    json_response(['status'=>'success']+$credentials);
} catch(Throwable $e) {
    json_response(['status'=>'error','error'=>'realtime_unavailable'],503);
}
