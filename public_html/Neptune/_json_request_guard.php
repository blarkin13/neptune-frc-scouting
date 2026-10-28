<?php
/**
 * Defense in depth for session-authenticated JSON POST endpoints.
 *
 * These endpoints read php://input, so a cross-site <form enctype="text/plain">
 * could otherwise deliver a JSON body with the user's cookies. A browser will
 * not attach a custom header to a cross-site request without a CORS preflight,
 * which Neptune never approves, so requiring a custom header blocks that.
 * Accepted: X-Neptune-Request: 1 (added to every same-origin fetch by
 * assets/js/neptune-request-header.js) or any X-Requested-With (sent by
 * Connection Guard on every send and replay, and by older page code). Both are
 * fixed values, not session tokens, so queued scouting actions still replay
 * after a scout signs in again, including from pages opened before this check
 * existed. Connection Guard treats a 403 as a permanent rejection, so the
 * fallback header matters: without it those queued actions would be dropped.
 *
 * $config['app']['json_request_header']: 'enforce' (default), 'report' (log only) or 'off'.
 */
function neptune_require_request_header(): void {
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')return;
    $mode=strtolower((string)($GLOBALS['config']['app']['json_request_header']??'enforce'));
    if($mode==='off')return;
    if((string)($_SERVER['HTTP_X_NEPTUNE_REQUEST']??'')==='1')return;
    if(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))!=='')return;
    error_log('[Neptune request header] missing on POST '.(string)($_SERVER['SCRIPT_NAME']??'').($mode==='report'?' (report only)':''));
    if($mode==='report')return;
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>false,'status'=>'error','code'=>'request_header_missing','message'=>'This page is out of date. Reload it and try again.']);
    exit;
}
