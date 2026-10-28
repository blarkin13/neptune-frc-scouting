<?php
/**
 * Capture an authenticated Google profile image for Neptune user profiles.
 * Optional/best-effort. Never let a missing table or image server block login.
 * Existing member-uploaded photos take precedence (INSERT IGNORE).
 */
declare(strict_types=1);

function sac_capture_google_photo(PDO $pdo, array $memberships, string $picture): void {
    try {
        if ($picture === '' || strlen($picture) > 2048 || !function_exists('curl_init')) return;
        $url = parse_url($picture);
        if (!is_array($url) || strtolower((string)($url['scheme'] ?? '')) !== 'https') return;
        $host = strtolower((string)($url['host'] ?? ''));
        if ($host !== 'googleusercontent.com' && !str_ends_with($host, '.googleusercontent.com')) return;
        if (isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['fragment'])) return;

        $needed = [];
        foreach ($memberships as $member) {
            $uid = (int)($member['id'] ?? 0);
            $org = (int)($member['organization_id'] ?? 0);
            if ($uid < 1 || $org < 1 || (isset($member['active']) && (int)$member['active'] !== 1)) continue;
            $check = $pdo->prepare('SELECT 1 FROM scouting_assignment_user_photos WHERE organization_id=? AND user_id=? LIMIT 1');
            $check->execute([$org, $uid]);
            if (!$check->fetchColumn()) $needed[$org . ':' . $uid] = [$org, $uid];
        }
        if (!$needed) return;

        $image = '';
        $ch = curl_init($picture);
        if ($ch === false) return;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Neptune-Scouting-Profile/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$image): int {
                if (strlen($image) + strlen($chunk) > 1024 * 1024) return 0;
                $image .= $chunk;
                return strlen($chunk);
            },
        ]);
        // Restrict the actual transfer to HTTPS, not only the parsed URL.
        if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
        else curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok !== true || $code !== 200 || $image === '') return;

        $info = @getimagesizefromstring($image);
        if (!is_array($info)) return;
        $mime = (string)($info['mime'] ?? '');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return;
        if (($info[0] ?? 0) > 2048 || ($info[1] ?? 0) > 2048) return;
        $insert = $pdo->prepare('INSERT IGNORE INTO scouting_assignment_user_photos (organization_id,user_id,mime_type,image_data,updated_by,updated_at) VALUES (?,?,?,?,NULL,UTC_TIMESTAMP())');
        foreach ($needed as [$org,$uid]) {
            $insert->bindValue(1, $org, PDO::PARAM_INT);
            $insert->bindValue(2, $uid, PDO::PARAM_INT);
            $insert->bindValue(3, $mime);
            $insert->bindValue(4, $image, PDO::PARAM_LOB);
            $insert->execute();
        }
    } catch (Throwable $e) {
        // Profile photos must never break a sign-in or the Assignment Center.
        error_log('[Neptune Assignment Photo] Capture unavailable: '.get_class($e));
    }
}
