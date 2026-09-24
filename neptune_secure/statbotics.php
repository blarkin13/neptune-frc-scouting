<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * Cached Statbotics REST client.
 *
 * - Returns fresh cached data immediately.
 * - Retries temporary API/network failures once.
 * - Falls back to the last successful cached response for the exact endpoint.
 * - Briefly caches failures only when no successful fallback exists.
 */
function statbotics_get(string $path, int $ttl = 1800): array
{
    global $pdo, $config;

    $key = 'statbotics:' . sha1($path);
    $now = gmdate('Y-m-d H:i:s');

    $s = $pdo->prepare(
        'SELECT response_json, fetched_at, expires_at
         FROM tba_cache
         WHERE cache_key=?
         LIMIT 1'
    );
    $s->execute([$key]);

    $staleSuccess = null;

    if ($row = $s->fetch()) {
        $cached = json_decode((string)$row['response_json'], true);
        $cached = is_array($cached) ? $cached : [];

        $isError = !empty($cached['__neptune_error']);
        $isFresh = !empty($row['expires_at']) && (string)$row['expires_at'] > $now;

        if ($isFresh) {
            if ($isError) {
                throw new RuntimeException(
                    (string)($cached['message'] ?? 'Statbotics is temporarily unavailable.')
                );
            }

            return $cached;
        }

        if (!$isError && $cached !== []) {
            $staleSuccess = $cached;
        }
    }

    $base = $config['statbotics']['base_url'] ?? 'https://api.statbotics.io/v3';
    $url = rtrim((string)$base, '/') . '/' . ltrim($path, '/');

    $lastMessage = 'Statbotics request failed.';
    $lastCode = 0;
    $lastErr = '';

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Neptune-FRC-Scouting/1.1',
            ],
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_ENCODING => '',
        ]);

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $lastCode = $code;
        $lastErr = $err;

        if ($body !== false && $code >= 200 && $code < 300) {
            $decoded = json_decode($body, true);

            if (!is_array($decoded)) {
                $lastMessage = 'Statbotics returned an invalid JSON response.';
            } else {
                $exp = gmdate('Y-m-d H:i:s', time() + max(60, $ttl));

                $cache = $pdo->prepare(
                    'INSERT INTO tba_cache
                        (cache_key,response_json,fetched_at,expires_at)
                     VALUES(?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        response_json=VALUES(response_json),
                        fetched_at=VALUES(fetched_at),
                        expires_at=VALUES(expires_at)'
                );

                $cache->execute([$key, $body, $now, $exp]);
                return $decoded;
            }
        } else {
            $lastMessage =
                'Statbotics request failed' .
                ($code ? ' (HTTP ' . $code . ')' : '') .
                ($err ? ': ' . $err : '.');
        }

        $retryable =
            $code === 0 ||
            $code === 408 ||
            $code === 425 ||
            $code === 429 ||
            $code >= 500;

        if (!$retryable || $attempt >= 2) {
            break;
        }

        usleep(300000);
    }

    if (is_array($staleSuccess)) {
        $fallbackExp = gmdate('Y-m-d H:i:s', time() + 300);

        $touch = $pdo->prepare(
            'UPDATE tba_cache SET expires_at=? WHERE cache_key=?'
        );
        $touch->execute([$fallbackExp, $key]);

        error_log(
            'Neptune Statbotics fallback for ' . $path . ': ' . $lastMessage
        );

        $staleSuccess['__neptune_stale'] = true;
        $staleSuccess['__neptune_stale_reason'] = $lastMessage;

        return $staleSuccess;
    }

    $failureBody = json_encode(
        [
            '__neptune_error' => true,
            'message' => $lastMessage,
            'http_code' => $lastCode,
            'curl_error' => $lastErr,
        ],
        JSON_UNESCAPED_SLASHES
    );

    $failureExp = gmdate('Y-m-d H:i:s', time() + 120);

    $cache = $pdo->prepare(
        'INSERT INTO tba_cache
            (cache_key,response_json,fetched_at,expires_at)
         VALUES(?,?,?,?)
         ON DUPLICATE KEY UPDATE
            response_json=VALUES(response_json),
            fetched_at=VALUES(fetched_at),
            expires_at=VALUES(expires_at)'
    );

    $cache->execute([$key, $failureBody, $now, $failureExp]);

    throw new RuntimeException($lastMessage);
}
