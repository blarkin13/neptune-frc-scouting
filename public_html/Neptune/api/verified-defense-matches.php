<?php
declare(strict_types=1);

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try{
    $u=require_login();
    $org=(int)$u['organization_id'];
    $eventId=(int)($_GET['event_id']??0);
    $team=(int)($_GET['team']??0);

    if($eventId<1||$team<1){
        http_response_code(400);
        echo json_encode(['ok'=>false,'message'=>'Event and team are required.']);
        exit;
    }

    $s=$pdo->prepare("SELECT e.name
        FROM events e
        WHERE e.id=? AND e.organization_id=?
        LIMIT 1");
    $s->execute([$eventId,$org]);
    $eventName=$s->fetchColumn();
    if($eventName===false){
        http_response_code(404);
        echo json_encode(['ok'=>false,'message'=>'Event not found.']);
        exit;
    }

    $s=$pdo->prepare("SELECT nickname
        FROM event_teams
        WHERE event_id=? AND frc_team_number=?
        LIMIT 1");
    $s->execute([$eventId,$team]);
    $nickname=(string)($s->fetchColumn()?:'');

    $nepDepa=null;
    $s=$pdo->prepare("SELECT neptune_depa,defense_depa
        FROM augur_depa_event_ratings
        WHERE organization_id=? AND event_id=? AND frc_team_number=?
        LIMIT 1");
    $s->execute([$org,$eventId,$team]);
    $ratingRow=$s->fetch();
    if($ratingRow){
        $v=$ratingRow['neptune_depa']??$ratingRow['defense_depa']??null;
        if($v!==null&&$v!=='')$nepDepa=(float)$v;
    }

    $s=$pdo->prepare("SELECT
            e.match_id,e.tba_match_key,e.alliance,
            e.raw_alliance_suppression AS raw_depa,
            e.expected_opponent_score,e.actual_opponent_score,
            e.defense_actions,e.spot_great_defense,e.spot_weak_defense,
            e.evidence_score,e.model_version,
            m.comp_level,m.set_number,m.match_number,m.run_number,
            m.red_score,m.blue_score,m.state,m.scheduled_time,m.started_at,m.ended_at
        FROM augur_depa_team_match_evidence e
        JOIN matches m
          ON m.id=e.match_id
         AND m.organization_id=e.organization_id
         AND m.event_id=e.event_id
        WHERE e.organization_id=? AND e.event_id=? AND e.frc_team_number=?
          AND e.defense_status='Verified'
        ORDER BY FIELD(m.comp_level,'qm','ef','qf','sf','f','legacy'),
                 m.set_number,m.match_number,m.field_id,m.id");
    $s->execute([$org,$eventId,$team]);

    $matches=[];
    foreach($s->fetchAll() as $row){
        if(function_exists('neptune_match_label')){
            $label=neptune_match_label($row);
        }else{
            $level=strtolower((string)$row['comp_level']);
            $label=match($level){
                'qm'=>'Qual '.(int)$row['match_number'],
                'ef'=>'Eighthfinal '.(int)$row['match_number'],
                'qf'=>'Quarterfinal '.(int)$row['match_number'],
                'sf'=>'Semifinal '.(int)$row['match_number'],
                'f'=>'Final '.(int)$row['match_number'],
                default=>strtoupper($level).' '.(int)$row['match_number'],
            };
        }

        $finalScore='';
        if($row['red_score']!==null&&$row['blue_score']!==null){
            $finalScore=(int)$row['red_score'].'–'.(int)$row['blue_score'];
        }

        $matches[]=[
            'match_id'=>(int)$row['match_id'],
            'match_label'=>$label,
            'tba_match_key'=>(string)($row['tba_match_key']??''),
            'alliance'=>(string)$row['alliance'],
            'raw_depa'=>(float)$row['raw_depa'],
            'expected_opponent_score'=>$row['expected_opponent_score']!==null?(float)$row['expected_opponent_score']:null,
            'actual_opponent_score'=>$row['actual_opponent_score']!==null?(float)$row['actual_opponent_score']:null,
            'defense_actions'=>(int)$row['defense_actions'],
            'spot_great_defense'=>(int)$row['spot_great_defense'],
            'spot_weak_defense'=>(int)$row['spot_weak_defense'],
            'evidence_score'=>(float)$row['evidence_score'],
            'final_score'=>$finalScore,
            'model_version'=>(string)$row['model_version'],
        ];
    }

    echo json_encode([
        'ok'=>true,
        'event_id'=>$eventId,
        'event_name'=>(string)$eventName,
        'team'=>$team,
        'nickname'=>$nickname,
        'nep_depa'=>$nepDepa,
        'matches'=>$matches,
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Could not load Verified defense matches.']);
}
