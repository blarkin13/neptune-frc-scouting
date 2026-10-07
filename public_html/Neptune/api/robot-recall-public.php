<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_robot_recall.php';
header('Cache-Control: no-store, no-cache, must-revalidate');

try{
    if(!robot_recall_table_exists($pdo,'robot_recall_sessions'))robot_recall_ensure_schema($pdo);
    $action=(string)($_REQUEST['action']??'state');
    $room=robot_recall_clean_room((string)($_REQUEST['room']??''));
    if(strlen($room)!==6)throw new InvalidArgumentException('Enter the 6-digit room code.');
    $session=robot_recall_room($pdo,$room);
    if(!$session)throw new RuntimeException('That Robot Recall room was not found.');

    if($action==='join'){
        if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'message'=>'POST required.'],405);
        $joined=robot_recall_join($pdo,$session,(string)($_POST['nickname']??''));
        json_response(['ok'=>true,'room'=>$room]+$joined);
    }

    if($action==='state'){
        $view=(string)($_REQUEST['view']??'player');
        if($view==='screen'){
            json_response(['ok'=>true,'state'=>robot_recall_state($pdo,$session,null,true)]);
        }
        $token=(string)($_REQUEST['token']??'');
        $player=robot_recall_player_from_token($pdo,(int)$session['id'],$token);
        if(!$player)json_response(['ok'=>false,'message'=>'Join this room before playing.','error'=>'player_not_found'],401);
        // Refresh player row after prior scoring updates.
        $player=robot_recall_player_from_token($pdo,(int)$session['id'],$token)?:$player;
        json_response(['ok'=>true,'state'=>robot_recall_state($pdo,$session,$player,false)]);
    }

    if($action==='answer'){
        if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'message'=>'POST required.'],405);
        $token=(string)($_POST['token']??'');
        $player=robot_recall_player_from_token($pdo,(int)$session['id'],$token);
        if(!$player)json_response(['ok'=>false,'message'=>'Your player session could not be found. Rejoin the room.'],401);
        $team=(int)($_POST['team']??0);
        $result=robot_recall_submit_answer($pdo,$session,$player,$team);
        json_response(['ok'=>true,'answer'=>$result]);
    }

    json_response(['ok'=>false,'message'=>'Unknown Robot Recall action.'],400);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(RuntimeException $e){json_response(['ok'=>false,'message'=>$e->getMessage()],400);
}catch(Throwable $e){json_response(['ok'=>false,'message'=>'Robot Recall could not complete that request.'],500);}
