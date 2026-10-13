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

    // Public rooms must progress without an administrator keeping a host tab open.
    // The deadline is anchored to question_started_at, and updates are guarded
    // by both status and question index to avoid duplicate advances.
    if($action==='state' && in_array((string)$session['status'],['question','reveal'],true)){
        $startAt=robot_recall_parse_utc_micro($session['question_started_at']??null);
        if($startAt!==null){
            $elapsed=microtime(true)-$startAt;
            $duration=max(5,(int)$session['question_seconds']);
            $sid=(int)$session['id'];$idx=(int)$session['current_question_index'];
            if($session['status']==='question' && $elapsed >= $duration){
                $pdo->prepare("UPDATE robot_recall_sessions SET status='reveal' WHERE id=? AND status='question' AND current_question_index=?")->execute([$sid,$idx]);
            }elseif($session['status']==='reveal' && $elapsed >= $duration+5){
                if($idx >= (int)$session['question_count']){
                    $pdo->prepare("UPDATE robot_recall_sessions SET status='finished',finished_at=UTC_TIMESTAMP(),question_started_at=NULL WHERE id=? AND status='reveal' AND current_question_index=?")->execute([$sid,$idx]);
                }else{
                    $pdo->prepare("UPDATE robot_recall_sessions SET status='question',current_question_index=current_question_index+1,question_started_at=UTC_TIMESTAMP(6) WHERE id=? AND status='reveal' AND current_question_index=?")->execute([$sid,$idx]);
                }
            }
            $session=robot_recall_room($pdo,$room)?:$session;
        }
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

    // Any joined player may start a lobby. Their per-room player token is required;
    // an atomic conditional update makes simultaneous Let's go taps safe.
    if($action==='start'){
        if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'message'=>'POST required.'],405);
        $token=(string)($_POST['token']??'');
        $player=robot_recall_player_from_token($pdo,(int)$session['id'],$token);
        if(!$player)json_response(['ok'=>false,'message'=>'Join the room before starting the game.'],401);
        $count=$pdo->prepare('SELECT COUNT(*) FROM robot_recall_players WHERE session_id=?');
        $count->execute([(int)$session['id']]);
        if((int)$count->fetchColumn()<1)throw new RuntimeException('Waiting for players to join.');
        $start=$pdo->prepare("UPDATE robot_recall_sessions SET status='question', current_question_index=1, question_started_at=UTC_TIMESTAMP(6), finished_at=NULL WHERE id=? AND status='lobby'");
        $start->execute([(int)$session['id']]);
        if($start->rowCount()!==1){
            $fresh=robot_recall_room($pdo,$room);
            if(!$fresh || (string)$fresh['status']==='lobby')throw new RuntimeException('Could not start the game. Try again.');
            json_response(['ok'=>true,'already_started'=>true]);
        }
        json_response(['ok'=>true,'started'=>true]);
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
