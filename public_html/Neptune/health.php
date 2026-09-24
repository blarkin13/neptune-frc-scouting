<?php
// Minimal AWS load-balancer health check. No secrets or diagnostic details are exposed.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

try {
    require_once dirname(__DIR__, 2) . '/neptune_secure/connection.php';
    $pdo->query('SELECT 1');
    http_response_code(200);
    echo "ok\n";
} catch (Throwable $e) {
    http_response_code(503);
    echo "unavailable\n";
}
