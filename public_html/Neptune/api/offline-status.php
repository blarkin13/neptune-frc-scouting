<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$mode = getenv('NEPTUNE_FIELD_MODE') === '1' ? 'field' : 'cloud';

$response = [
    'ok' => true,
    'mode' => $mode,
    'hostname' => gethostname() ?: null,
    'server_time_utc' => gmdate('c'),
];

try {
    require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $response['database'] = 'connected';
        $response['scouting_actions'] = (int)$pdo->query('SELECT COUNT(*) FROM scouting_actions')->fetchColumn();
        $response['open_scout_sessions'] = (int)$pdo->query("SELECT COUNT(*) FROM scout_sessions WHERE status IN ('assigned','connected','scouting')")->fetchColumn();
    } else {
        $response['database'] = 'unavailable';
    }
} catch (Throwable $e) {
    $response['database'] = 'error';
}

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
