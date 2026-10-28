<?php
/**
 * Neptune time standard.
 *
 * The database stores every time as UTC (the connection runs with time_zone = '+00:00').
 * These helpers turn a stored UTC value into a time for display. Nothing here writes to the database.
 *
 *   neptune_time($stored)                 -> 'Y-m-d H:i:s' in the display zone (what pages printed before)
 *   neptune_time($stored, 'M j, g:i A')   -> custom format
 *   neptune_time_ts($stored)              -> Unix timestamp for a stored UTC value (or false)
 *
 * The display zone is app.display_timezone, then app.timezone, then PHP's default zone.
 */
if (!function_exists('neptune_display_zone')) {
    function neptune_display_zone(?string $zone = null): DateTimeZone {
        static $cache = [];
        if ($zone === null || $zone === '') {
            global $config;
            $zone = (string)($config['app']['display_timezone'] ?? $config['app']['timezone'] ?? date_default_timezone_get());
        }
        if (!isset($cache[$zone])) {
            try { $cache[$zone] = new DateTimeZone($zone); }
            catch (Throwable $e) { $cache[$zone] = new DateTimeZone('UTC'); }
        }
        return $cache[$zone];
    }

    function neptune_time_ts($stored) {
        $s = trim((string)$stored);
        if ($s === '' || strncmp($s, '0000-00-00', 10) === 0) return false;
        try { return (new DateTimeImmutable($s, new DateTimeZone('UTC')))->getTimestamp(); }
        catch (Throwable $e) { return false; }
    }

    /**
     * SQL for "today's date in the display zone" - use in place of CURDATE(), which follows the (UTC) connection.
     * Event dates are calendar dates at the venue, so "is it today" must be asked in the venue/display zone.
     */
    function neptune_sql_today(?string $zone = null): string {
        $off = neptune_display_zone($zone)->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        return 'DATE(UTC_TIMESTAMP() + INTERVAL ' . (int)$off . ' SECOND)';
    }

    function neptune_time($stored, string $format = 'Y-m-d H:i:s', ?string $zone = null): string {
        $s = trim((string)$stored);
        if ($s === '') return '';
        try {
            $dt = new DateTimeImmutable($s, new DateTimeZone('UTC'));
            if ($dt->format('Y') < 1) return $s;
            return $dt->setTimezone(neptune_display_zone($zone))->format($format);
        } catch (Throwable $e) {
            return $s;
        }
    }
}
