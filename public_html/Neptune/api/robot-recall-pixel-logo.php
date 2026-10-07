<?php
declare(strict_types=1);

require_once __DIR__ . '/_robot-recall-pixel.php';

$team = rr_pixel_safe_team($_GET['team'] ?? $_GET['team_number'] ?? $_GET['frc_team_number'] ?? 0);
$org  = rr_pixel_safe_org($_GET['org'] ?? $_GET['organization_id'] ?? 0);
$size = isset($_GET['size']) ? (int)$_GET['size'] : 768;
$grid = isset($_GET['grid']) ? (int)$_GET['grid'] : 18;

if ($team < 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid team number.';
    exit;
}

$logo = rr_pixel_cached_logo($team, $org, $size, $grid);
if (!$logo || !is_file($logo['path'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Logo not found in Neptune cache.';
    exit;
}

$path = $logo['path'];
$mtime = (int)@filemtime($path);
$filesize = (int)@filesize($path);
$etag = '"rrpx-' . sha1($path . '|' . $mtime . '|' . $filesize) . '"';

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime ?: time()) . ' GMT');
header('X-Robot-Recall-Pixel: 1');

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

readfile($path);
