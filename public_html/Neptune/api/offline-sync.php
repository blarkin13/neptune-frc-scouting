<?php
/**
 * Neptune authenticated field-server synchronization endpoint.
 *
 * Synchronizes the pieces that live scouting changes while a field server is
 * disconnected from AWS: match state, scout sessions and scouting actions.
 * Scouting actions are de-duplicated by scouting_actions.uuid.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function sync_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sync_json(['ok' => false, 'error' => 'POST required.'], 405);
}

require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    sync_json(['ok' => false, 'error' => 'Database is unavailable.'], 500);
}

$expectedKey = getenv('NEPTUNE_OFFLINE_SYNC_KEY') ?: '';
if ($expectedKey === '' && is_file('/etc/scout/offline-sync.env')) {
    $lines = @file('/etc/scout/offline-sync.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k === 'NEPTUNE_OFFLINE_SYNC_KEY') {
            $expectedKey = trim($v, " \t\n\r\0\x0B\"'");
            break;
        }
    }
}

$providedKey = $_SERVER['HTTP_X_NEPTUNE_SYNC_KEY'] ?? '';
if ($expectedKey === '' || $providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    sync_json(['ok' => false, 'error' => 'Unauthorized.'], 401);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    sync_json(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
}

$organizationId = (int)($data['organization_id'] ?? 0);
if ($organizationId < 1) {
    sync_json(['ok' => false, 'error' => 'organization_id is required.'], 422);
}

$matches = is_array($data['matches'] ?? null) ? $data['matches'] : [];
$sessions = is_array($data['sessions'] ?? null) ? $data['sessions'] : [];
$actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];

if (count($matches) > 500 || count($sessions) > 1000 || count($actions) > 500) {
    sync_json(['ok' => false, 'error' => 'Sync batch is too large.'], 413);
}

$uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

$validAlliance = static fn($v) => in_array($v, ['Red', 'Blue'], true) ? $v : null;
$validState = static fn($v) => in_array($v, ['scheduled','ready','running','paused','ended'], true) ? $v : 'scheduled';
$validSessionStatus = static fn($v) => in_array($v, ['assigned','connected','scouting','submitted','closed'], true) ? $v : 'assigned';
$validPhase = static fn($v) => in_array($v, ['pre_match','auton','teleop','endgame','post_match','unknown'], true) ? $v : 'unknown';
$validResult = static fn($v) => in_array($v, ['Success','Failure','Neutral'], true) ? $v : 'Neutral';
$validSource = static fn($v) => in_array($v, ['scout','admin','legacy_import','shared'], true) ? $v : 'scout';
$validWinning = static fn($v) => in_array($v, ['Red','Blue','Tie','Unknown'], true) ? $v : 'Unknown';

$counts = [
    'matches_updated' => 0,
    'sessions_inserted' => 0,
    'sessions_updated' => 0,
    'actions_inserted' => 0,
    'actions_existing' => 0,
    'actions_updated' => 0,
];

try {
    $pdo->beginTransaction();

    // Match IDs come from the production snapshot. We update only matching rows
    // belonging to the requested organization; we never create schedule rows here.
    $matchStmt = $pdo->prepare(
        "UPDATE matches SET
            started_at=?, ended_at=?, paused_at=?, total_pause_seconds=?,
            run_number=?, red_score=?, blue_score=?, winning_alliance=?, state=?
         WHERE id=? AND organization_id=?"
    );

    foreach ($matches as $m) {
        if (!is_array($m)) continue;
        $matchId = (int)($m['id'] ?? 0);
        if ($matchId < 1) continue;

        $matchStmt->execute([
            $m['started_at'] ?? null,
            $m['ended_at'] ?? null,
            $m['paused_at'] ?? null,
            max(0, (int)($m['total_pause_seconds'] ?? 0)),
            max(1, (int)($m['run_number'] ?? 1)),
            isset($m['red_score']) && $m['red_score'] !== '' ? (int)$m['red_score'] : null,
            isset($m['blue_score']) && $m['blue_score'] !== '' ? (int)$m['blue_score'] : null,
            $validWinning((string)($m['winning_alliance'] ?? 'Unknown')),
            $validState((string)($m['state'] ?? 'scheduled')),
            $matchId,
            $organizationId,
        ]);
        $counts['matches_updated'] += $matchStmt->rowCount() > 0 ? 1 : 0;
    }

    $sessionExists = $pdo->prepare('SELECT id FROM scout_sessions WHERE uuid=? LIMIT 1');
    $sessionInsert = $pdo->prepare(
        "INSERT INTO scout_sessions
            (uuid,organization_id,event_id,match_id,user_id,scout_name,frc_team_number,alliance,station,field_id,match_run_number,status,last_seen_at,created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $sessionUpdate = $pdo->prepare(
        "UPDATE scout_sessions SET
            event_id=?, match_id=?, user_id=?, scout_name=?, frc_team_number=?, alliance=?,
            station=?, field_id=?, match_run_number=?, status=?, last_seen_at=?
         WHERE uuid=? AND organization_id=?"
    );

    $userExists = $pdo->prepare('SELECT id FROM users WHERE id=? AND organization_id=? LIMIT 1');

    foreach ($sessions as $s) {
        if (!is_array($s)) continue;
        $uuid = strtolower(trim((string)($s['uuid'] ?? '')));
        if (!preg_match($uuidPattern, $uuid)) continue;
        if ((int)($s['organization_id'] ?? $organizationId) !== $organizationId) continue;
        $alliance = $validAlliance((string)($s['alliance'] ?? ''));
        if ($alliance === null) continue;

        $userId = isset($s['user_id']) ? (int)$s['user_id'] : 0;
        if ($userId > 0) {
            $userExists->execute([$userId, $organizationId]);
            if (!$userExists->fetchColumn()) $userId = 0;
        }

        $sessionExists->execute([$uuid]);
        $existingId = $sessionExists->fetchColumn();

        if ($existingId) {
            $sessionUpdate->execute([
                (int)$s['event_id'], (int)$s['match_id'], $userId ?: null,
                $s['scout_name'] ?? null, (int)$s['frc_team_number'], $alliance,
                isset($s['station']) ? (int)$s['station'] : null,
                max(1, (int)($s['field_id'] ?? 1)),
                max(1, (int)($s['match_run_number'] ?? 1)),
                $validSessionStatus((string)($s['status'] ?? 'assigned')),
                $s['last_seen_at'] ?? null,
                $uuid, $organizationId,
            ]);
            $counts['sessions_updated']++;
        } else {
            $sessionInsert->execute([
                $uuid, $organizationId, (int)$s['event_id'], (int)$s['match_id'], $userId ?: null,
                $s['scout_name'] ?? null, (int)$s['frc_team_number'], $alliance,
                isset($s['station']) ? (int)$s['station'] : null,
                max(1, (int)($s['field_id'] ?? 1)),
                max(1, (int)($s['match_run_number'] ?? 1)),
                $validSessionStatus((string)($s['status'] ?? 'assigned')),
                $s['last_seen_at'] ?? null,
                $s['created_at'] ?? gmdate('Y-m-d H:i:s'),
            ]);
            $counts['sessions_inserted']++;
        }
    }

    $actionExists = $pdo->prepare('SELECT id,deleted_at,deletion_reason FROM scouting_actions WHERE uuid=? LIMIT 1');
    $sessionByUuid = $pdo->prepare('SELECT id FROM scout_sessions WHERE uuid=? AND organization_id=? LIMIT 1');
    $teamExists = $pdo->prepare('SELECT id FROM teams WHERE id=? AND organization_id=? LIMIT 1');

    $actionInsert = $pdo->prepare(
        "INSERT INTO scouting_actions
            (uuid,organization_id,owner_team_id,event_id,match_id,scout_session_id,game_id,
             frc_team_number,alliance,action_code,action_name,action_type,location,result,points,
             match_time_sec,match_run_number,phase,source,source_ip,created_by,recorded_at,
             legacy_source,legacy_id,deleted_at,deleted_by,deletion_reason)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );

    $actionDeleteUpdate = $pdo->prepare(
        "UPDATE scouting_actions SET
            deleted_at=?, deleted_by=?, deletion_reason=?
         WHERE uuid=? AND organization_id=?"
    );

    foreach ($actions as $a) {
        if (!is_array($a)) continue;
        $uuid = strtolower(trim((string)($a['uuid'] ?? '')));
        if (!preg_match($uuidPattern, $uuid)) continue;
        if ((int)($a['organization_id'] ?? $organizationId) !== $organizationId) continue;

        $actionExists->execute([$uuid]);
        $existing = $actionExists->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $counts['actions_existing']++;
            if (!empty($a['deleted_at']) && ($existing['deleted_at'] ?? null) !== $a['deleted_at']) {
                $deletedBy = isset($a['deleted_by']) ? (int)$a['deleted_by'] : 0;
                if ($deletedBy > 0) {
                    $userExists->execute([$deletedBy, $organizationId]);
                    if (!$userExists->fetchColumn()) $deletedBy = 0;
                }
                $actionDeleteUpdate->execute([
                    $a['deleted_at'], $deletedBy ?: null, $a['deletion_reason'] ?? null,
                    $uuid, $organizationId,
                ]);
                $counts['actions_updated']++;
            }
            continue;
        }

        $alliance = $validAlliance((string)($a['alliance'] ?? ''));
        if ($alliance === null || empty($a['action_code'])) continue;

        $ownerTeamId = isset($a['owner_team_id']) ? (int)$a['owner_team_id'] : 0;
        if ($ownerTeamId > 0) {
            $teamExists->execute([$ownerTeamId, $organizationId]);
            if (!$teamExists->fetchColumn()) $ownerTeamId = 0;
        }

        $createdBy = isset($a['created_by']) ? (int)$a['created_by'] : 0;
        if ($createdBy > 0) {
            $userExists->execute([$createdBy, $organizationId]);
            if (!$userExists->fetchColumn()) $createdBy = 0;
        }

        $deletedBy = isset($a['deleted_by']) ? (int)$a['deleted_by'] : 0;
        if ($deletedBy > 0) {
            $userExists->execute([$deletedBy, $organizationId]);
            if (!$userExists->fetchColumn()) $deletedBy = 0;
        }

        $sessionId = null;
        $sessionUuid = strtolower(trim((string)($a['scout_session_uuid'] ?? '')));
        if ($sessionUuid !== '' && preg_match($uuidPattern, $sessionUuid)) {
            $sessionByUuid->execute([$sessionUuid, $organizationId]);
            $sessionId = $sessionByUuid->fetchColumn() ?: null;
        }

        $actionInsert->execute([
            $uuid, $organizationId, $ownerTeamId ?: null,
            (int)$a['event_id'], (int)$a['match_id'], $sessionId, (int)$a['game_id'],
            (int)$a['frc_team_number'], $alliance,
            (string)$a['action_code'], $a['action_name'] ?? null, $a['action_type'] ?? null,
            $a['location'] ?? null, $validResult((string)($a['result'] ?? 'Neutral')),
            (float)($a['points'] ?? 0),
            isset($a['match_time_sec']) && $a['match_time_sec'] !== '' ? (int)$a['match_time_sec'] : null,
            max(1, (int)($a['match_run_number'] ?? 1)),
            $validPhase((string)($a['phase'] ?? 'unknown')),
            $validSource((string)($a['source'] ?? 'scout')),
            $a['source_ip'] ?? null,
            $createdBy ?: null,
            $a['recorded_at'] ?? gmdate('Y-m-d H:i:s'),
            $a['legacy_source'] ?? null,
            isset($a['legacy_id']) && $a['legacy_id'] !== '' ? (int)$a['legacy_id'] : null,
            $a['deleted_at'] ?? null,
            $deletedBy ?: null,
            $a['deletion_reason'] ?? null,
        ]);
        $counts['actions_inserted']++;
    }

    $pdo->commit();
    sync_json([
        'ok' => true,
        'organization_id' => $organizationId,
        'counts' => $counts,
        'server_time_utc' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Neptune offline sync failed: ' . $e->getMessage());
    sync_json(['ok' => false, 'error' => 'Synchronization failed.', 'detail' => $e->getMessage()], 500);
}
