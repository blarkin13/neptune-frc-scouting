<?php
/**
 * Offline / field-mode status endpoint.
 *
 * Public callers get only: ok, mode, server_time_utc and whether the database
 * answers. Nothing about data volume or the host is disclosed.
 *
 * Detail (hostname and counts scoped to ONE organization) requires the same
 * tenant-bound credential offline-sync.php uses:
 *   GET /api/offline-status.php?organization_id=N
 *   X-Neptune-Sync-Key: <NEPTUNE_OFFLINE_SYNC_KEY_ORG_N>
 * A missing or wrong key for a requested organization returns 401 and no data.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function nos_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Same tenant-bound key lookup as offline-sync.php. Returns '' when none is configured. */
function nos_expected_key(int $organizationId): string {
    $env = [];
    foreach (getenv() ?: [] as $k => $v) {
        if (is_string($k) && is_scalar($v)) $env[$k] = (string)$v;
    }
    $file = '/etc/scout/offline-sync.env';
    if (@is_file($file) && @is_readable($file)) {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if ($k === '') continue;
            $env[$k] = trim($v, " \t\n\r\0\x0B\"'");
        }
    }
    $key = trim((string)($env['NEPTUNE_OFFLINE_SYNC_KEY_ORG_' . $organizationId] ?? ''));
    if ($key !== '') return $key;

    // Legacy single-server form: honoured only when bound to this organization.
    $legacyKey = trim((string)($env['NEPTUNE_OFFLINE_SYNC_KEY'] ?? ''));
    $legacyOrg = (int)($env['NEPTUNE_OFFLINE_SYNC_ORGANIZATION_ID'] ?? 0);
    return ($legacyKey !== '' && $legacyOrg === $organizationId) ? $legacyKey : '';
}

$mode = getenv('NEPTUNE_FIELD_MODE') === '1' ? 'field' : 'cloud';

$requestedOrg = isset($_GET['organization_id']) ? (int)$_GET['organization_id'] : 0;
$providedKey  = trim((string)($_SERVER['HTTP_X_NEPTUNE_SYNC_KEY'] ?? ''));
$wantsDetail  = $requestedOrg > 0 || $providedKey !== '';

$authorizedOrg = 0;
if ($wantsDetail) {
    $expected = $requestedOrg > 0 ? nos_expected_key($requestedOrg) : '';
    if ($expected === '' || $providedKey === '' || !hash_equals($expected, $providedKey)) {
        nos_json(['ok' => false, 'error' => 'Unauthorized.'], 401);
    }
    $authorizedOrg = $requestedOrg;
}

$response = [
    'ok' => true,
    'mode' => $mode,
    'server_time_utc' => gmdate('c'),
];

try {
    require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $response['database'] = 'connected';
        if ($authorizedOrg > 0) {
            $response['organization_id'] = $authorizedOrg;
            $response['hostname'] = gethostname() ?: null;

            $s = $pdo->prepare('SELECT COUNT(*) FROM scouting_actions WHERE organization_id = ?');
            $s->execute([$authorizedOrg]);
            $response['scouting_actions'] = (int)$s->fetchColumn();

            $s = $pdo->prepare("SELECT COUNT(*) FROM scout_sessions WHERE organization_id = ? AND status IN ('assigned','connected','scouting')");
            $s->execute([$authorizedOrg]);
            $response['open_scout_sessions'] = (int)$s->fetchColumn();
        }
    } else {
        $response['database'] = 'unavailable';
    }
} catch (Throwable $e) {
    $response['database'] = 'error';
}

nos_json($response);
