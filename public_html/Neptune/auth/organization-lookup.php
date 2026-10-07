<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
require_once dirname(__DIR__, 3) . '/neptune_secure/public-auth-security.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function neptune_org_lookup_json(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    neptune_public_auth_ensure_schema($pdo);
    if (function_exists('neptune_platform_ensure_schema')) {
        neptune_platform_ensure_schema($pdo);
    }

    $scope = 'ip:' . neptune_public_client_ip();
    $limit = neptune_public_rate_status($pdo, 'org_lookup_ip', $scope, 30, 600);
    if (!$limit['allowed']) {
        neptune_org_lookup_json([
            'ok' => false,
            'error' => 'Too many organization searches. Try again in a few minutes.',
        ], 429);
    }
    neptune_public_rate_hit($pdo, 'org_lookup_ip', $scope, 30, 600, 600);

    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        neptune_org_lookup_json([
            'ok' => true,
            'results' => [],
            'message' => 'Enter at least 2 characters.',
        ]);
    }
    $q = mb_substr($q, 0, 100);
    $like = '%' . $q . '%';
    $prefix = $q . '%';

    $stmt = $pdo->prepare(
        "SELECT name, slug
         FROM organizations
         WHERE COALESCE(platform_status,'active')='active'
           AND (name LIKE ? OR slug LIKE ?)
         ORDER BY
           CASE
             WHEN slug = ? THEN 0
             WHEN name = ? THEN 1
             WHEN slug LIKE ? THEN 2
             WHEN name LIKE ? THEN 3
             ELSE 4
           END,
           name
         LIMIT 10"
    );
    $stmt->execute([$like, $like, $q, $q, $prefix, $prefix]);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'name' => (string)$row['name'],
            'slug' => (string)$row['slug'],
        ];
    }

    neptune_org_lookup_json(['ok' => true, 'results' => $results]);
} catch (Throwable $e) {
    error_log('[Neptune org lookup] ' . get_class($e) . ': ' . $e->getMessage());
    neptune_org_lookup_json([
        'ok' => false,
        'error' => 'Organization lookup is temporarily unavailable.',
    ], 500);
}
