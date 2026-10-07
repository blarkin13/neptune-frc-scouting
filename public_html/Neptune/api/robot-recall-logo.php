<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_robot_recall.php';
try{
    if(!robot_recall_table_exists($pdo,'robot_recall_sessions')){http_response_code(404);exit;}
    $room=robot_recall_clean_room((string)($_GET['room']??''));
    $q=(int)($_GET['q']??0);
    $session=robot_recall_room($pdo,$room);
    if(!$session||$q<=0||(int)$session['current_question_index']!==$q||!in_array((string)$session['status'],['question','reveal','leaderboard'],true)){http_response_code(404);exit;}
    $question=robot_recall_question($pdo,(int)$session['id'],$q);
    if(!$question){http_response_code(404);exit;}
    $path=robot_recall_safe_logo_abs((string)$question['logo_path']);
    if(!$path){http_response_code(404);exit;}
    $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
    $mime=['png'=>'image/png','avif'=>'image/avif','webp'=>'image/webp','jpg'=>'image/jpeg','jpeg'=>'image/jpeg'][$ext]??'application/octet-stream';
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($path));
    header('Cache-Control: no-store, private');
    readfile($path);
}catch(Throwable $e){http_response_code(404);}
