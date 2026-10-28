<?php
/** Robot rankings from any scout: GET ?match_id= returns mine; POST JSON saves one ranking. */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
require_once dirname(__DIR__) . '/_json_request_guard.php'; neptune_require_request_header();
require_once dirname(__DIR__) . '/analytics/_robot_ranking.php';
$u = require_login();
$org = (int)$u['organization_id']; $uid = (int)$u['id'];
header('Cache-Control: no-store, private');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $d = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($d)) json_response(['ok' => false, 'message' => 'Invalid ranking.'], 422);
        [$status, $message] = nrr_save($pdo, $org, $uid, $d);
        json_response(['ok' => $status !== 'rejected', 'status' => $status, 'message' => $message], $status === 'rejected' ? 422 : 200);
    }
    json_response(['ok' => true, 'mine' => nrr_mine($pdo, $org, $uid, (int)($_GET['match_id'] ?? 0))]);
} catch (Throwable $e) {
    error_log('[Neptune robot ranking] ' . get_class($e) . ': ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Ranking could not be saved right now; it stays on this device.'], 503);
}
