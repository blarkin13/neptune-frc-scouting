<?php
declare(strict_types=1);

/** Lightweight bridge between completed Robot Recall rooms and the next round. */
function robot_recall_rounds_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS robot_recall_round_links (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organization_id BIGINT UNSIGNED NOT NULL,
        from_session_id BIGINT UNSIGNED NOT NULL,
        from_room_code VARCHAR(12) NOT NULL,
        to_session_id BIGINT UNSIGNED NOT NULL,
        to_room_code VARCHAR(12) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_robot_recall_round_from_session (from_session_id),
        UNIQUE KEY uq_robot_recall_round_from_room (from_room_code),
        KEY idx_robot_recall_round_to_session (to_session_id),
        KEY idx_robot_recall_round_org (organization_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function robot_recall_rounds_clean_room(string $room): string
{
    return substr(preg_replace('/\D+/', '', $room) ?? '', 0, 12);
}

function robot_recall_rounds_session_row(PDO $pdo, int $org, int $sessionId): ?array
{
    $q=$pdo->prepare('SELECT * FROM robot_recall_sessions WHERE id=? AND organization_id=? LIMIT 1');
    $q->execute([$sessionId,$org]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function robot_recall_rounds_existing_next(PDO $pdo, int $org, int $fromSessionId): ?array
{
    robot_recall_rounds_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT * FROM robot_recall_round_links WHERE organization_id=? AND from_session_id=? LIMIT 1');
    $q->execute([$org,$fromSessionId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function robot_recall_rounds_link(PDO $pdo, int $org, int $fromSessionId, string $fromRoom, int $toSessionId, string $toRoom): void
{
    robot_recall_rounds_ensure_schema($pdo);
    $fromRoom=robot_recall_rounds_clean_room($fromRoom);
    $toRoom=robot_recall_rounds_clean_room($toRoom);
    if($fromSessionId<1||$toSessionId<1||$fromRoom===''||$toRoom==='') throw new RuntimeException('Robot Recall round link is incomplete.');
    $q=$pdo->prepare("INSERT INTO robot_recall_round_links
        (organization_id,from_session_id,from_room_code,to_session_id,to_room_code)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE to_session_id=VALUES(to_session_id),to_room_code=VALUES(to_room_code),created_at=CURRENT_TIMESTAMP");
    $q->execute([$org,$fromSessionId,$fromRoom,$toSessionId,$toRoom]);
}

function robot_recall_rounds_next_by_room(PDO $pdo, string $fromRoom): ?array
{
    robot_recall_rounds_ensure_schema($pdo);
    $room=robot_recall_rounds_clean_room($fromRoom);
    if($room==='') return null;
    $q=$pdo->prepare('SELECT to_room_code,to_session_id,created_at FROM robot_recall_round_links WHERE from_room_code=? LIMIT 1');
    $q->execute([$room]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}
