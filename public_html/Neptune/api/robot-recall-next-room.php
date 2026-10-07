<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_robot-recall-rounds.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try{
    $room=robot_recall_rounds_clean_room((string)($_GET['room']??''));
    if(strlen($room)!==6){http_response_code(400);echo json_encode(['ok'=>false,'message'=>'Invalid room code.']);exit;}
    $next=robot_recall_rounds_next_by_room($pdo,$room);
    echo json_encode([
        'ok'=>true,
        'next_room'=>$next?robot_recall_rounds_clean_room((string)$next['to_room_code']):null,
    ],JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Next round is not available yet.']);
}
