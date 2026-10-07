<?php
/**
 * Neptune Pit Match Board operational-state helpers.
 *
 * These tables intentionally live alongside the scouting schema but contain
 * only organization-scoped pit operations state. The helper auto-creates the
 * tables so this update can be installed through Maintenance Console without a
 * separate SQL migration step.
 */

function neptune_pit_board_ensure_schema(PDO $pdo): bool {
    static $ready=null;
    if($ready!==null)return $ready;
    try{
        $pdo->exec("CREATE TABLE IF NOT EXISTS pit_match_board_state (
            organization_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            frc_team_number INT UNSIGNED NOT NULL,
            robot_status VARCHAR(16) NOT NULL DEFAULT 'unset',
            battery_label VARCHAR(80) DEFAULT NULL,
            bumper_color VARCHAR(12) DEFAULT NULL,
            inspection_ready TINYINT(1) NOT NULL DEFAULT 0,
            drive_team_ready TINYINT(1) NOT NULL DEFAULT 0,
            pit_note VARCHAR(500) DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (organization_id,event_id,frc_team_number),
            KEY idx_pit_board_event (organization_id,event_id),
            KEY idx_pit_board_updated_by (updated_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pit_match_board_match_state (
            organization_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            frc_team_number INT UNSIGNED NOT NULL,
            match_id BIGINT UNSIGNED NOT NULL,
            queue_lead_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
            queued TINYINT(1) NOT NULL DEFAULT 0,
            checklist_json LONGTEXT NULL,
            match_note VARCHAR(500) DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (organization_id,event_id,frc_team_number,match_id),
            KEY idx_pit_board_match (organization_id,event_id,match_id),
            KEY idx_pit_board_match_updated_by (updated_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ready=true;
    }catch(Throwable $e){
        $ready=false;
    }
    return $ready;
}

function neptune_pit_board_default_checklist(): array {
    return [
        ['id'=>'battery','label'=>'Fresh battery installed','done'=>false,'custom'=>false],
        ['id'=>'bumpers','label'=>'Correct bumpers installed','done'=>false,'custom'=>false],
        ['id'=>'mechanisms','label'=>'Mechanisms checked','done'=>false,'custom'=>false],
        ['id'=>'strategy','label'=>'Match strategy reviewed','done'=>false,'custom'=>false],
        ['id'=>'drivers','label'=>'Driver team ready','done'=>false,'custom'=>false],
        ['id'=>'queue','label'=>'Leave for queue','done'=>false,'custom'=>false],
    ];
}

function neptune_pit_board_normalize_checklist(mixed $raw): array {
    $defaults=neptune_pit_board_default_checklist();
    $decoded=is_array($raw)?$raw:(is_string($raw)?json_decode($raw,true):null);
    if(!is_array($decoded))return $defaults;
    $byId=[];
    foreach($decoded as $item){
        if(!is_array($item))continue;
        $id=preg_replace('/[^a-zA-Z0-9_-]/','',trim((string)($item['id']??'')));
        $label=trim((string)($item['label']??''));
        if($id===''||$label==='')continue;
        $byId[$id]=[
            'id'=>$id,
            'label'=>mb_substr($label,0,120),
            'done'=>!empty($item['done']),
            'custom'=>!empty($item['custom']),
        ];
    }
    $out=[];
    foreach($defaults as $item){
        if(isset($byId[$item['id']]))$item['done']=$byId[$item['id']]['done'];
        $out[]=$item;
        unset($byId[$item['id']]);
    }
    foreach($byId as $item){
        if(!$item['custom'])continue;
        $out[]=$item;
        if(count($out)>=14)break;
    }
    return $out;
}

function neptune_pit_board_team_state(PDO $pdo,int $org,int $eventId,int $team): array {
    $default=[
        'robot_status'=>'unset','battery_label'=>'','bumper_color'=>'',
        'inspection_ready'=>0,'drive_team_ready'=>0,'pit_note'=>'','updated_at'=>null,
    ];
    if(!neptune_pit_board_ensure_schema($pdo))return $default;
    $s=$pdo->prepare('SELECT robot_status,battery_label,bumper_color,inspection_ready,drive_team_ready,pit_note,updated_at FROM pit_match_board_state WHERE organization_id=? AND event_id=? AND frc_team_number=? LIMIT 1');
    $s->execute([$org,$eventId,$team]);
    $row=$s->fetch();
    if(!$row)return $default;
    $status=(string)($row['robot_status']??'unset');
    if(!in_array($status,['unset','ready','working','issue','queued'],true))$status='unset';
    return [
        'robot_status'=>$status,
        'battery_label'=>(string)($row['battery_label']??''),
        'bumper_color'=>(string)($row['bumper_color']??''),
        'inspection_ready'=>(int)!empty($row['inspection_ready']),
        'drive_team_ready'=>(int)!empty($row['drive_team_ready']),
        'pit_note'=>(string)($row['pit_note']??''),
        'updated_at'=>$row['updated_at']??null,
    ];
}

function neptune_pit_board_match_state(PDO $pdo,int $org,int $eventId,int $team,int $matchId): array {
    $default=[
        'queue_lead_minutes'=>10,'queued'=>0,'checklist'=>neptune_pit_board_default_checklist(),
        'match_note'=>'','updated_at'=>null,
    ];
    if($matchId<1||!neptune_pit_board_ensure_schema($pdo))return $default;
    $s=$pdo->prepare('SELECT queue_lead_minutes,queued,checklist_json,match_note,updated_at FROM pit_match_board_match_state WHERE organization_id=? AND event_id=? AND frc_team_number=? AND match_id=? LIMIT 1');
    $s->execute([$org,$eventId,$team,$matchId]);
    $row=$s->fetch();
    if(!$row)return $default;
    $lead=max(0,min(60,(int)($row['queue_lead_minutes']??10)));
    return [
        'queue_lead_minutes'=>$lead,
        'queued'=>(int)!empty($row['queued']),
        'checklist'=>neptune_pit_board_normalize_checklist((string)($row['checklist_json']??'')),
        'match_note'=>(string)($row['match_note']??''),
        'updated_at'=>$row['updated_at']??null,
    ];
}

function neptune_pit_board_match_belongs(PDO $pdo,int $org,int $eventId,int $team,int $matchId): bool {
    $s=$pdo->prepare('SELECT 1 FROM matches m JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=? WHERE m.id=? AND m.organization_id=? AND m.event_id=? LIMIT 1');
    $s->execute([$team,$matchId,$org,$eventId]);
    return (bool)$s->fetchColumn();
}
