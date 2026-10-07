<?php
declare(strict_types=1);

/**
 * Shared wrapper for alternate Robot Recall pixel-art pages.
 * Required variables before include:
 *   $rrPixelTarget = absolute path to the existing Robot Recall page
 *   $rrPixelMode   = player|screen|host
 */

if (!isset($rrPixelTarget, $rrPixelMode) || !is_file((string)$rrPixelTarget)) {
    http_response_code(500);
    echo 'Robot Recall base page not found.';
    exit;
}

ob_start();
require (string)$rrPixelTarget;
$html = (string)ob_get_clean();

$mode = in_array($rrPixelMode, ['player', 'screen', 'host'], true) ? $rrPixelMode : 'player';
$inject = "\n<link rel=\"stylesheet\" href=\"../assets/css/robot-recall-pixel.css?v=32\">\n"
    . '<script>document.documentElement.dataset.rrPixelMode=' . json_encode($mode) . ";</script>\n"
    . "<script defer src=\"../assets/js/robot-recall-pixel.js?v=32\"></script>\n";

if (preg_match('/<\/head>/i', $html)) {
    $html = preg_replace('/<\/head>/i', $inject . '</head>', $html, 1) ?? $html;
} else {
    $html = $inject . $html;
}

echo $html;
