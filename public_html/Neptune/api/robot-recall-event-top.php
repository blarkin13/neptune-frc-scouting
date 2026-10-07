<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_robot_recall.php';
require_once __DIR__.'/_robot-recall-competition.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try {
    robot_recall_ensure_schema($pdo);
    rrc_ensure_schema($pdo);
    $room = robot_recall_clean_room((string)($_GET['room'] ?? ''));
    if ($room === '') throw new RuntimeException('Room code required.');
    $session = robot_recall_room($pdo,$room);
    if (!$session) throw new RuntimeException('Robot Recall room not found.');

    $orgId = (int)($session['organization_id'] ?? 0);
    $flag = rrc_session_flag($pdo,(int)$session['id'],$orgId);
    $eventId = (int)($session['event_id'] ?? 0);
    if ($eventId <= 0) $eventId=(int)($flag['event_id'] ?? 0);
    $playMode=(string)($flag['play_mode']??(((int)($flag['is_test']??0)===1)?'test':'production'));
    $isTest = $playMode==='test';
    $isPractice = $playMode==='practice';

    $details = $eventId > 0
        ? rrc_event_top10_details($pdo,$orgId,$eventId)
        : [
            'rows'=>[],
            'meta'=>[
                'event_rooms'=>0,
                'test_rooms'=>0,
                'practice_rooms'=>0,
                'production_rooms'=>0,
                'ranked_rooms'=>0,
                'completed_production_games'=>0,
                'completed_ranked_games'=>0,
                'scored_player_rows'=>0,
                'unique_players'=>0,
                'player_source'=>'',
            ],
            'message'=>'This room is not linked to an event.',
        ];
    if ($isPractice) {
        $suffix=(string)($details['message'] ?? '');
        $details['message']='Current room is PRACTICE and excluded from Event Standings.'.($suffix!==''?' '.$suffix:'');
    } elseif ($isTest) {
        $suffix=(string)($details['message'] ?? '');
        $details['message']='Current room is TEST. Test scores are included in Event Standings.'.($suffix!==''?' '.$suffix:'');
    }

    $eventName=(string)($session['event_name'] ?? '');
    if ($eventName==='' && $eventId>0) {
        try {
            $stmt=$pdo->prepare('SELECT name FROM events WHERE id=? AND organization_id=? LIMIT 1');
            $stmt->execute([$eventId,$orgId]);
            $eventName=(string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $ignored) {}
    }

    echo json_encode([
        'ok'=>true,
        'test_mode'=>$isTest,
        'practice_mode'=>$isPractice,
        'play_mode'=>$playMode,
        'event_id'=>$eventId,
        'event_name'=>$eventName,
        'rows'=>$details['rows'] ?? [],
        'meta'=>$details['meta'] ?? [],
        'message'=>(string)($details['message'] ?? ''),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
}
