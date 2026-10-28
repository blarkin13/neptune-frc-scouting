<?php
/** Import one complete scout hand-off (all QR parts) on behalf of the scout who made it. */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
require_once dirname(__DIR__) . '/scout/_handoff.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$u = require_role(['owner', 'admin', 'strategy']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Allow: POST'); json_response(['status' => 'error', 'message' => 'POST only'], 405); }
verify_csrf();
$text = (string)($_POST['codes'] ?? '');
if ($text === '' || strlen($text) > 400000) json_response(['status' => 'error', 'message' => 'No hand-off codes received.'], 422);
$chunks = [];
foreach (preg_split('/\R/', $text) as $line) {
    $pos = stripos($line, 'NH1/');
    if ($pos === false) continue;
    if ($c = nho_parse_chunk(substr($line, $pos))) $chunks[] = $c;
}
if (!$chunks) json_response(['status' => 'error', 'message' => 'These are not Neptune hand-off codes.'], 422);
try {
    $bundle = nho_decode_bundle($chunks);
    $result = nho_import_bundle($pdo, (int)$u['organization_id'], $bundle);
} catch (InvalidArgumentException $e) {
    json_response(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[Neptune hand-off import] ' . get_class($e) . ': ' . $e->getMessage());
    json_response(['status' => 'error', 'message' => 'The hand-off could not be imported. Nothing partial is lost: scan it again.'], 500);
}
if (function_exists('neptune_auth_audit')) {
    try { neptune_auth_audit($pdo, (int)$u['organization_id'], (int)$u['id'], 'scout_handoff_import', 'user', (string)(int)$bundle['u']); } catch (Throwable $e) { /* audit is best effort */ }
}
json_response(['status' => 'success'] + $result);
