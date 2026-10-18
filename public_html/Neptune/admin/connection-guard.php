<?php
declare(strict_types=1);

/*
 * Neptune Connection Guard
 *
 * GET  ?asset=js  -> serves the client-side IndexedDB/retry guard.
 * POST           -> idempotent wrapper around api/record-action.php.
 *
 * Kept in admin/ because that directory is already browser-maintainable on
 * this Neptune host. This endpoint intentionally does not require an admin
 * role: scouts must be able to POST through it. The underlying record-action
 * endpoint remains responsible for normal scouting authentication/validation.
 */

if (isset($_GET['asset']) && $_GET['asset'] === 'js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo <<<'JS'
(() => {
    'use strict';

    if (window.__neptuneConnectionGuardInstalled) return;
    window.__neptuneConnectionGuardInstalled = true;

    const DB_NAME = 'neptune-scout-resilience';
    const DB_VERSION = 1;
    const STORE = 'pending-actions';
    const ORIGINAL_ACTION = /(?:^|\/)api\/record-action\.php(?:[?#]|$)/i;
    const WRAPPER_ACTION = '/admin/connection-guard.php';
    const MATCH_STATE = /(?:^|\/)api\/match-state\.php(?:[?#]|$)/i;
    const nativeFetch = window.fetch.bind(window);
    let flushing = false;
    let badge = null;
    let cachedMatchState = null;
    let cachedMatchStateAt = 0;

    function uuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        const b = new Uint8Array(16);
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            window.crypto.getRandomValues(b);
        } else {
            for (let i = 0; i < b.length; i++) b[i] = Math.floor(Math.random() * 256);
        }
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        const h = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
        return `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`;
    }

    function openDb() {
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = () => {
                const db = req.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    const store = db.createObjectStore(STORE, { keyPath: 'requestUuid' });
                    store.createIndex('createdAt', 'createdAt', { unique: false });
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error || new Error('IndexedDB open failed'));
        });
    }

    async function withStore(mode, fn) {
        const db = await openDb();
        try {
            return await new Promise((resolve, reject) => {
                const tx = db.transaction(STORE, mode);
                const store = tx.objectStore(STORE);
                let result;
                try { result = fn(store); } catch (e) { reject(e); return; }
                tx.oncomplete = () => resolve(result);
                tx.onerror = () => reject(tx.error || new Error('IndexedDB transaction failed'));
                tx.onabort = () => reject(tx.error || new Error('IndexedDB transaction aborted'));
            });
        } finally {
            db.close();
        }
    }

    async function putPending(item) {
        await withStore('readwrite', store => store.put(item));
        await updateBadge();
    }

    async function deletePending(id) {
        await withStore('readwrite', store => store.delete(id));
        await updateBadge();
    }

    async function getPending() {
        const db = await openDb();
        try {
            return await new Promise((resolve, reject) => {
                const tx = db.transaction(STORE, 'readonly');
                const req = tx.objectStore(STORE).getAll();
                req.onsuccess = () => resolve((req.result || []).sort((a,b) => a.createdAt - b.createdAt));
                req.onerror = () => reject(req.error || new Error('Unable to read pending actions'));
            });
        } finally {
            db.close();
        }
    }

    async function pendingCount() {
        const db = await openDb();
        try {
            return await new Promise((resolve, reject) => {
                const tx = db.transaction(STORE, 'readonly');
                const req = tx.objectStore(STORE).count();
                req.onsuccess = () => resolve(req.result || 0);
                req.onerror = () => reject(req.error || new Error('Unable to count pending actions'));
            });
        } finally {
            db.close();
        }
    }

    function ensureBadge() {
        if (badge || !document.body) return;
        badge = document.createElement('div');
        badge.id = 'neptune-connection-guard-status';
        badge.setAttribute('role', 'status');
        badge.setAttribute('aria-live', 'polite');
        Object.assign(badge.style, {
            position: 'fixed',
            zIndex: '2147483647',
            left: '50%',
            bottom: '14px',
            transform: 'translateX(-50%)',
            padding: '8px 12px',
            borderRadius: '999px',
            background: 'rgba(8, 12, 18, .94)',
            color: '#fff',
            border: '1px solid rgba(255,255,255,.24)',
            boxShadow: '0 6px 22px rgba(0,0,0,.28)',
            font: '700 12px/1.2 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            letterSpacing: '.02em',
            pointerEvents: 'none',
            opacity: '0',
            transition: 'opacity .18s ease'
        });
        document.body.appendChild(badge);
    }

    let badgeHideTimer = null;
    function showBadge(text, persistent = false, ms = 1800) {
        ensureBadge();
        if (!badge) return;
        badge.textContent = text;
        badge.style.opacity = '1';
        if (badgeHideTimer) clearTimeout(badgeHideTimer);
        if (!persistent) {
            badgeHideTimer = setTimeout(() => {
                if (badge) badge.style.opacity = '0';
            }, ms);
        }
    }

    let holdBadgeUntil = 0;
    async function updateBadge() {
        try {
            if (Date.now() < holdBadgeUntil) return;
            // Items the server refused are not "waiting"; only genuinely unsynced actions count.
            const items = (await getPending()).filter(i => !i.blocked);
            const waiting = items.length;
            const plural = n => `${n} ACTION${n === 1 ? '' : 'S'}`;
            if (waiting > 0 && items.some(i => i.needsAuth)) {
                showBadge(`SIGN IN AGAIN · ${plural(waiting)} WAITING TO SYNC`, true);
            } else if (waiting > 0) {
                showBadge(`CONNECTION LOST · ${plural(waiting)} SAVED`, true);
            } else if (badge && badge.style.opacity === '1') {
                showBadge('SYNCED', false);
            }
        } catch (_) {}
    }

    function announce(text, ms) {
        holdBadgeUntil = Date.now() + ms;
        showBadge(text, false, ms);
        setTimeout(updateBadge, ms + 100);
    }

    async function waitingCount() {
        return (await getPending()).filter(i => !i.blocked).length;
    }

    // Rehearsal ledger (off by default). Turn on per device with ?guard_ledger=1 or
    // NeptuneConnectionGuard.ledgerOn(); it records every action's request UUID here so
    // admin/connection-guard-rehearsal-cli.php can compare actions sent with rows stored.
    const LEDGER_KEY = 'neptune-guard-ledger-v1';
    const LEDGER_ON = 'neptune-guard-ledger-on';
    function ledgerEnabled() {
        try {
            if (/[?&]guard_ledger=1(?:&|$)/.test(location.search)) localStorage.setItem(LEDGER_ON, '1');
            return localStorage.getItem(LEDGER_ON) === '1';
        } catch (_) { return false; }
    }
    function ledgerRead() {
        try { return JSON.parse(localStorage.getItem(LEDGER_KEY) || '[]'); } catch (_) { return []; }
    }
    function ledgerAdd(item) {
        if (!ledgerEnabled()) return;
        try {
            let sessionId = null;
            try { sessionId = Number(JSON.parse(new TextDecoder().decode(item.body)).session_id) || null; } catch (_) {}
            const rows = ledgerRead();
            rows.push({ request_uuid: item.requestUuid, session_id: sessionId, created_at: new Date(item.createdAt).toISOString(), label: item.label });
            localStorage.setItem(LEDGER_KEY, JSON.stringify(rows.slice(-5000)));
        } catch (_) {}
    }
    async function ledgerExport() {
        const pending = (await getPending()).map(i => ({ request_uuid: i.requestUuid, blocked: !!i.blocked, needs_auth: !!i.needsAuth, last_error: i.lastError || null }));
        const data = { device: navigator.userAgent, exported_at: new Date().toISOString(), sent: ledgerRead(), still_on_device: pending };
        const blob = new Blob([JSON.stringify(data, null, 1)], { type: 'application/json' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `neptune-guard-ledger-${Date.now()}.json`;
        document.body.appendChild(a); a.click(); a.remove();
        return data;
    }

    // Decide what a server answer means for a stored action.
    //   ok       - the server recorded it (or already had it); safe to delete locally
    //   rejected - the server understood and refused it; keep it, flag it, never auto-retry
    //   auth     - login expired / a login page came back; keep it and retry after sign-in
    //   retry    - temporary server trouble; keep it and retry automatically
    function classifyResponse(response, parsed) {
        const hasJson = !!parsed && typeof parsed === 'object';
        if (hasJson && parsed.code === 'receipt_other_session') return 'ok';
        if (response.ok) {
            // A 200 that is not JSON is almost always a login/redirect page, not a saved action.
            if (!hasJson) return 'auth';
            if (parsed.ok === false || parsed.success === false || parsed.status === 'error') return 'rejected';
            if (parsed.error && parsed.ok !== true && parsed.success !== true) return 'rejected';
            return 'ok';
        }
        if (response.status === 401) return 'auth';
        if (response.status === 403) return hasJson ? 'rejected' : 'auth';
        if (response.status === 408 || response.status === 425 || response.status === 429 || response.status >= 500) return 'retry';
        return 'rejected';
    }

    function recordOutcome(item, kind, response, parsed) {
        const msg = (parsed && typeof parsed === 'object' && (parsed.message || parsed.error))
            ? String(parsed.message || parsed.error) : '';
        item.lastError = msg ? `${msg} (HTTP ${response.status})` : `HTTP ${response.status}`;
        item.blocked = kind === 'rejected';
        item.needsAuth = kind === 'auth';
        item.lastAttemptAt = Date.now();
    }

    function queuedResponse(requestUuid, reason) {
        const messages = {
            auth: 'Action saved on this device. Sign in again and it will sync automatically.',
            retry: 'Action saved on this device and will retry automatically.',
            offline: 'Action saved locally and will sync automatically.',
            ok: 'Action was already recorded.'
        };
        return new Response(JSON.stringify({
            ok: true,
            success: true,
            status: 'success',
            queued: reason !== 'ok',
            offline: reason === 'offline',
            queued_reason: reason,
            request_uuid: requestUuid,
            message: messages[reason] || messages.offline
        }), {
            status: 202,
            headers: { 'Content-Type': 'application/json; charset=utf-8', 'X-Neptune-Queued': '1' }
        });
    }

    function describeBody(body) {
        try {
            const j = JSON.parse(new TextDecoder().decode(body));
            const name = j && j.action && (j.action.name || j.action.code) ? String(j.action.name || j.action.code) : 'Action';
            const sec = Number.isFinite(Number(j && j.match_time_sec)) ? ` at ${Number(j.match_time_sec)}s` : '';
            return `${name} · ${j && j.result ? j.result : ''}${sec}`.trim();
        } catch (_) {
            return 'Scouting action';
        }
    }

    async function makeStoredRequest(input, init) {
        const req = new Request(input, init);
        const body = (req.method === 'GET' || req.method === 'HEAD')
            ? null
            : await req.clone().arrayBuffer();
        return {
            url: req.url,
            method: req.method,
            headers: Array.from(req.headers.entries()),
            body,
            credentials: req.credentials || 'same-origin',
            cache: req.cache || 'no-store',
            redirect: req.redirect || 'follow'
        };
    }

    function headersWithUuid(headers, requestUuid) {
        const h = new Headers(headers || []);
        h.set('X-Neptune-Request-UUID', requestUuid);
        h.set('X-Requested-With', 'Neptune-Connection-Guard');
        return h;
    }

    async function sendStored(item, signal) {
        const headers = headersWithUuid(item.headers, item.requestUuid);
        const options = {
            method: item.method,
            headers,
            credentials: item.credentials || 'same-origin',
            cache: 'no-store',
            redirect: item.redirect || 'follow'
        };
        if (item.body && item.method !== 'GET' && item.method !== 'HEAD') {
            options.body = item.body;
        }
        if (signal) options.signal = signal;
        return nativeFetch(WRAPPER_ACTION, options);
    }

    async function protectedActionFetch(input, init) {
        const stored = await makeStoredRequest(input, init);
        const requestUuid = uuid();
        const item = {
            requestUuid,
            url: stored.url,
            method: stored.method,
            headers: stored.headers,
            body: stored.body,
            credentials: stored.credentials,
            redirect: stored.redirect,
            label: describeBody(stored.body),
            createdAt: Date.now(),
            attempts: 0,
            lastAttemptAt: null,
            lastError: null
        };

        try {
            await putPending(item);
        } catch (e) {
            console.error('Neptune could not protect action in IndexedDB:', e);
            return nativeFetch(input, init);
        }
        ledgerAdd(item);

        try {
            item.attempts++;
            item.lastAttemptAt = Date.now();
            const response = await sendStored(item, (input instanceof Request ? input.signal : init && init.signal));
            let parsed = null;
            try { parsed = await response.clone().json(); } catch (_) {}

            const kind = classifyResponse(response, parsed);
            if (kind === 'ok') {
                await deletePending(requestUuid);
                return response.ok ? response : queuedResponse(requestUuid, 'ok');
            }

            // Anything that is not a confirmed save stays on the device. Nothing is
            // deleted unless the server confirms it recorded the action.
            recordOutcome(item, kind, response, parsed);
            await putPending(item);
            updateBadge();
            if (kind === 'rejected') return response; // let the scouting page show the server's reason
            return queuedResponse(requestUuid, kind);
        } catch (e) {
            item.lastError = String(e && e.message ? e.message : e);
            item.lastAttemptAt = Date.now();
            try { await putPending(item); } catch (_) {}
            updateBadge();
            return queuedResponse(requestUuid, 'offline');
        }
    }

    async function flushQueue() {
        if (flushing) return;
        flushing = true;
        try {
            const items = await getPending();
            let refusedNow = 0, refusedWhy = '';
            for (const item of items) {
                if (item.blocked) continue;
                try {
                    item.attempts = (item.attempts || 0) + 1;
                    item.lastAttemptAt = Date.now();
                    const response = await sendStored(item);
                    let parsed = null;
                    try { parsed = await response.clone().json(); } catch (_) {}
                    const kind = classifyResponse(response, parsed);
                    if (kind === 'ok') {
                        await deletePending(item.requestUuid);
                        continue;
                    }
                    recordOutcome(item, kind, response, parsed);
                    await putPending(item);
                    if (kind === 'rejected') { refusedNow++; refusedWhy = item.lastError || ''; }
                    if (kind === 'auth') break; // signed out: no point sending the rest yet
                } catch (e) {
                    item.lastError = String(e && e.message ? e.message : e);
                    try { await putPending(item); } catch (_) {}
                    break;
                }
            }
            if (refusedNow > 0) {
                announce(`${refusedNow} ACTION${refusedNow === 1 ? '' : 'S'} COULD NOT BE SAVED${refusedWhy ? ' · ' + refusedWhy.replace(/ \(HTTP \d+\)$/, '').toUpperCase() : ''}`, 9000);
            }
        } finally {
            flushing = false;
            await updateBadge();
        }
    }

    function advanceCachedMatchState(nowMs = Date.now()) {
        const fallback = JSON.parse(JSON.stringify(cachedMatchState));
        fallback.connection_lost = true;
        fallback.cached_at_ms = cachedMatchStateAt;
        fallback.client_now_ms = nowMs;

        // A ready, paused, or ended match must remain frozen at the last
        // authoritative state. Only a running match advances locally.
        if (String(fallback.state || '') !== 'running') {
            fallback.offline_advance_seconds = 0;
            return fallback;
        }

        const timing = fallback.timing || {};
        const basePhysical = Number(fallback.physical_elapsed_seconds);
        const auton = Number(timing.auton);
        const transition = Number(timing.transition);
        const duration = Number(timing.duration);
        const endgame = Number(timing.endgame);

        if (
            !Number.isFinite(basePhysical) ||
            !Number.isFinite(auton) ||
            !Number.isFinite(transition) ||
            !Number.isFinite(duration) ||
            !Number.isFinite(endgame)
        ) {
            // Never invent timing if an older/invalid response did not contain
            // enough information. Returning the last state is safer.
            fallback.offline_advance_seconds = 0;
            return fallback;
        }

        const localDelta = Math.max(0, (nowMs - cachedMatchStateAt) / 1000);
        const physical = Math.max(0, basePhysical + localDelta);

        let elapsed = 0;
        let stage = 'waiting';
        let transitionRemaining = 0;

        if (physical < auton) {
            elapsed = physical;
            stage = 'auton';
        } else if (physical < (auton + transition)) {
            // The field's Auto -> Teleop pause consumes real time, but the
            // official game clock intentionally stays frozen at the end of Auto.
            elapsed = auton;
            stage = 'transition';
            transitionRemaining = (auton + transition) - physical;
        } else {
            // Once transition ends, remove only the configured transition pause
            // from physical time. This mirrors api/match-state.php exactly.
            elapsed = Math.min(duration, Math.max(0, physical - transition));
            stage = elapsed >= Math.max(0, duration - endgame) ? 'endgame' : 'teleop';
        }

        fallback.physical_elapsed_seconds = physical;
        fallback.elapsed_seconds = elapsed;
        fallback.remaining_seconds = Math.max(0, duration - elapsed);
        fallback.stage = stage;
        fallback.transition_remaining_seconds = Math.max(0, transitionRemaining);
        fallback.offline_advance_seconds = localDelta;

        return fallback;
    }

    async function matchStateFetch(input, init) {
        try {
            const response = await nativeFetch(input, init);
            if (response.ok) {
                try {
                    const data = await response.clone().json();
                    cachedMatchState = data;
                    cachedMatchStateAt = Date.now();
                } catch (_) {}
            }
            return response;
        } catch (e) {
            if (cachedMatchState !== null) {
                showBadge('CONNECTION LOST · SCOUTING LOCALLY', true);
                const fallback = advanceCachedMatchState(Date.now());

                return new Response(JSON.stringify(fallback), {
                    status: 200,
                    headers: {
                        'Content-Type': 'application/json; charset=utf-8',
                        'X-Neptune-Cached-State': '1'
                    }
                });
            }
            throw e;
        }
    }

    window.fetch = function neptuneGuardedFetch(input, init) {
        let url;
        try {
            url = input instanceof Request ? input.url : new URL(String(input), window.location.href).href;
        } catch (_) {
            return nativeFetch(input, init);
        }

        if (ORIGINAL_ACTION.test(url)) {
            return protectedActionFetch(input, init);
        }
        if (MATCH_STATE.test(url)) {
            return matchStateFetch(input, init);
        }
        return nativeFetch(input, init);
    };

    window.NeptuneConnectionGuard = {
        flush: flushQueue,
        pending: getPending,
        count: pendingCount,
        waiting: waitingCount,
        notice: text => announce(String(text), 5000),
        ledgerOn: () => { try { localStorage.setItem(LEDGER_ON, '1'); } catch (_) {} return ledgerEnabled(); },
        ledgerOff: () => { try { localStorage.removeItem(LEDGER_ON); } catch (_) {} },
        ledgerClear: () => { try { localStorage.removeItem(LEDGER_KEY); } catch (_) {} },
        ledgerExport
    };

    function start() {
        ensureBadge();
        updateBadge();
        flushQueue();
        window.addEventListener('online', flushQueue);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) flushQueue();
        });
        setInterval(flushQueue, 3000);
        if (navigator.storage && typeof navigator.storage.persist === 'function') {
            navigator.storage.persist().catch(() => {});
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
JS;
    exit;
}

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Neptune database connection unavailable.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

$requestUuid = trim((string)(
    $_SERVER['HTTP_X_NEPTUNE_REQUEST_UUID']
    ?? $_POST['neptune_request_uuid']
    ?? ''
));

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $requestUuid)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid Neptune request UUID.']);
    exit;
}

try {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS action_request_receipts (
    request_uuid CHAR(36) NOT NULL PRIMARY KEY,
    session_hash CHAR(64) NOT NULL,
    response_code SMALLINT UNSIGNED NOT NULL,
    response_body MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_action_request_receipts_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
} catch (Throwable $e) {
    error_log('Neptune Connection Guard table setup failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Connection Guard database setup failed.']);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$sessionId = session_id();
$sessionHash = hash('sha256', $sessionId !== '' ? $sessionId : 'no-session');

try {
    $stmt = $pdo->prepare(
        'SELECT session_hash, response_code, response_body
           FROM action_request_receipts
          WHERE request_uuid = ?
          LIMIT 1'
    );
    $stmt->execute([$requestUuid]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // A lead may have imported this action by QR/file hand-off; that receipt belongs to
        // the scout's account rather than a browser session. Treat it as delivered.
        $handoffOwner = false;
        if (!hash_equals((string)$existing['session_hash'], $sessionHash)) {
            $guardUser = require_login();
            $handoffOwner = hash_equals((string)$existing['session_hash'], hash('sha256', 'handoff-user:' . (int)($guardUser['id'] ?? 0)));
        }
        if (!$handoffOwner && !hash_equals((string)$existing['session_hash'], $sessionHash)) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'code' => 'receipt_other_session', 'error' => 'Request UUID belongs to another session.']);
            exit;
        }
        http_response_code((int)$existing['response_code']);
        header('X-Neptune-Replayed: 1');
        echo (string)$existing['response_body'];
        exit;
    }
} catch (Throwable $e) {
    error_log('Neptune Connection Guard receipt lookup failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Action receipt lookup failed.']);
    exit;
}

ob_start();

register_shutdown_function(static function () use ($pdo, $requestUuid, $sessionHash): void {
    $status = http_response_code();
    if ($status === false || $status === 0) $status = 200;

    $lastError = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $fatal = $lastError !== null && in_array((int)$lastError['type'], $fatalTypes, true);

    if ($fatal || $status < 200 || $status >= 300) return;

    $body = (string)(ob_get_contents() ?: '');
    try {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO action_request_receipts
                (request_uuid, session_hash, response_code, response_body, created_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$requestUuid, $sessionHash, $status, $body]);

        // Lightweight cleanup so the receipt table cannot grow forever.
        if (random_int(1, 100) === 1) {
            $pdo->exec('DELETE FROM action_request_receipts WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 14 DAY)');
        }
    } catch (Throwable $e) {
        error_log('Neptune Connection Guard receipt save failed: ' . $e->getMessage());
    }
});

// Mark this request as coming through the idempotent, UUID-tagged guard so the
// endpoint can accept actions captured during a match that has since paused/ended.
$GLOBALS['neptune_guarded_request'] = true;

require dirname(__DIR__) . '/api/record-action.php';
