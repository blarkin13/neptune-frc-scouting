<?php
/**
 * Run on the field laptop after Internet returns.
 * Usage:
 *   sudo php sync-to-cloud.php https://neptune.mckinneysteamacademy.org [organization_id] [event_id]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$cloud = rtrim((string)($argv[1] ?? ''), '/');
$orgId = (int)($argv[2] ?? 1);
$eventId = isset($argv[3]) ? (int)$argv[3] : 0;

if ($cloud === '' || !preg_match('#^https://#i', $cloud)) {
    fwrite(STDERR, "Usage: sudo php sync-to-cloud.php https://YOUR-NEPTUNE-HOST [organization_id] [event_id]\n");
    exit(1);
}

require_once '/var/www/neptune/neptune_secure/bootstrap.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Local Neptune DB is unavailable.\n");
    exit(1);
}

function env_file_value(string $file, string $key): ?string {
    if (!is_file($file)) return null;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k === $key) return trim($v, " \t\n\r\0\x0B\"'");
    }
    return null;
}

$key = getenv('NEPTUNE_OFFLINE_SYNC_KEY') ?: env_file_value('/etc/scout/offline-sync.env', 'NEPTUNE_OFFLINE_SYNC_KEY');
if (!$key) {
    fwrite(STDERR, "Missing /etc/scout/offline-sync.env.\n");
    exit(1);
}

$where = 'organization_id=?';
$params = [$orgId];
if ($eventId > 0) {
    $where .= ' AND event_id=?';
    $params[] = $eventId;
}

$matchesStmt = $pdo->prepare(
    "SELECT id,organization_id,event_id,game_id,started_at,ended_at,paused_at,total_pause_seconds,
            run_number,red_score,blue_score,winning_alliance,state
     FROM matches WHERE $where ORDER BY id"
);
$matchesStmt->execute($params);
$matches = $matchesStmt->fetchAll(PDO::FETCH_ASSOC);

$sessionsStmt = $pdo->prepare(
    "SELECT uuid,organization_id,event_id,match_id,user_id,scout_name,frc_team_number,alliance,station,
            field_id,match_run_number,status,last_seen_at,created_at
     FROM scout_sessions WHERE $where ORDER BY id"
);
$sessionsStmt->execute($params);
$sessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

$runId = null;
try {
    $log = $pdo->prepare(
        "INSERT INTO offline_sync_runs (organization_id,destination_url,started_at,status,matches_sent,sessions_sent,actions_sent)
         VALUES (?,?,UTC_TIMESTAMP(),'running',?,?,0)"
    );
    $log->execute([$orgId, $cloud, count($matches), count($sessions)]);
    $runId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    // Older field bundle without the optional logging table: continue safely.
}

function send_payload(string $url, string $key, array $payload): array {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Neptune-Sync-Key: ' . $key,
        ],
        CURLOPT_POSTFIELDS => $body,
    ]);
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($response === false || $code < 200 || $code >= 300) {
        throw new RuntimeException('HTTP ' . $code . ($err ? ': ' . $err : '') . ' response=' . substr((string)$response, 0, 500));
    }
    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        throw new RuntimeException('Cloud rejected sync: ' . substr((string)$response, 0, 1000));
    }
    return $decoded;
}

$endpoint = $cloud . '/api/offline-sync.php';
$totalActions = 0;
$summary = [];

try {
    // First send match state + session UUID mapping.
    $summary[] = send_payload($endpoint, $key, [
        'organization_id' => $orgId,
        'matches' => $matches,
        'sessions' => $sessions,
        'actions' => [],
    ]);

    $sql =
        "SELECT a.uuid,a.organization_id,a.owner_team_id,a.event_id,a.match_id,
                ss.uuid AS scout_session_uuid,a.game_id,a.frc_team_number,a.alliance,
                a.action_code,a.action_name,a.action_type,a.location,a.result,a.points,
                a.match_time_sec,a.match_run_number,a.phase,a.source,a.source_ip,a.created_by,
                a.recorded_at,a.legacy_source,a.legacy_id,a.deleted_at,a.deleted_by,a.deletion_reason
         FROM scouting_actions a
         LEFT JOIN scout_sessions ss ON ss.id=a.scout_session_id
         WHERE a.organization_id=?";
    $actionParams = [$orgId];
    if ($eventId > 0) {
        $sql .= ' AND a.event_id=?';
        $actionParams[] = $eventId;
    }
    $sql .= ' ORDER BY a.id';

    $actionsStmt = $pdo->prepare($sql);
    $actionsStmt->execute($actionParams);

    $batch = [];
    while ($row = $actionsStmt->fetch(PDO::FETCH_ASSOC)) {
        $batch[] = $row;
        if (count($batch) >= 400) {
            $summary[] = send_payload($endpoint, $key, [
                'organization_id' => $orgId,
                'matches' => [],
                'sessions' => [],
                'actions' => $batch,
            ]);
            $totalActions += count($batch);
            fwrite(STDOUT, "Synced $totalActions actions...\n");
            $batch = [];
        }
    }
    if ($batch) {
        $summary[] = send_payload($endpoint, $key, [
            'organization_id' => $orgId,
            'matches' => [],
            'sessions' => [],
            'actions' => $batch,
        ]);
        $totalActions += count($batch);
    }

    if ($runId) {
        $done = $pdo->prepare(
            "UPDATE offline_sync_runs SET completed_at=UTC_TIMESTAMP(),status='complete',actions_sent=?,response_json=? WHERE id=?"
        );
        $done->execute([$totalActions, json_encode($summary), $runId]);
    }

    fwrite(STDOUT, "\nSYNC COMPLETE\n");
    fwrite(STDOUT, "Matches: " . count($matches) . "\n");
    fwrite(STDOUT, "Sessions: " . count($sessions) . "\n");
    fwrite(STDOUT, "Actions: $totalActions\n");
    fwrite(STDOUT, "Destination: $cloud\n");
} catch (Throwable $e) {
    if ($runId) {
        try {
            $fail = $pdo->prepare(
                "UPDATE offline_sync_runs SET completed_at=UTC_TIMESTAMP(),status='failed',actions_sent=?,response_json=? WHERE id=?"
            );
            $fail->execute([$totalActions, json_encode(['error' => $e->getMessage()]), $runId]);
        } catch (Throwable $ignored) {}
    }
    fwrite(STDERR, "SYNC FAILED: " . $e->getMessage() . "\n");
    fwrite(STDERR, "No local scouting data was deleted. Re-run the command after fixing connectivity.\n");
    exit(2);
}
