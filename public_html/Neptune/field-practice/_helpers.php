<?php
declare(strict_types=1);

/**
 * Neptune public Sunday field-practice signup helpers.
 *
 * This is a standing 2027-season program. The public QR/URL stays the same
 * all season; each registration is stored under the Sunday selected by the
 * team (practice_key = ntx-sunday-YYYY-MM-DD).
 */
function neptune_field_practice_config(): array {
    return [
        'season' => 2027,
        'practice_key_prefix' => 'ntx-sunday-',
        'title' => '2027 Sunday Field Practice',
        'program_label' => '2027 FRC Season',
        'day_label' => 'Sundays',
        'start_label' => '5:00 PM',
        'end_label' => '8:00 PM',
        'location_name' => 'McKinney STEAM Academy',
        'address' => '192 Industrial Blvd, Suite 109, McKinney, TX 75069',
        'public_url' => 'https://neptune.mckinneysteamacademy.org/field-practice/',
        'qr_image' => 'assets/images/ntx-field-practice-qr.png',
    ];
}

function neptune_field_practice_schema(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS field_practice_signups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            practice_key VARCHAR(96) NOT NULL,
            team_number INT UNSIGNED NOT NULL,
            team_name VARCHAR(160) NOT NULL DEFAULT '',
            contact_name VARCHAR(160) NOT NULL,
            contact_email VARCHAR(190) NOT NULL,
            contact_phone VARCHAR(64) NOT NULL DEFAULT '',
            attendee_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            arrival_window VARCHAR(64) NOT NULL DEFAULT '',
            practice_focus VARCHAR(500) NOT NULL DEFAULT '',
            notes TEXT NULL,
            status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_field_practice_team (practice_key, team_number),
            KEY idx_field_practice_status (practice_key, status),
            KEY idx_field_practice_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ready = true;
}

function neptune_field_practice_focus_options(): array {
    return [
        'Driver practice',
        'Autonomous testing',
        'Cycle testing',
        'Defense / counter-defense',
        'Endgame practice',
        'Full-match reps',
    ];
}

function neptune_field_practice_arrival_options(): array {
    return [
        '5:00 PM',
        '5:30 PM',
        '6:00 PM',
        '6:30 PM',
        '7:00 PM',
        'Not sure yet',
    ];
}

function neptune_field_practice_clean_line(string $value, int $max): string {
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if (function_exists('mb_substr')) return mb_substr($value, 0, $max);
    return substr($value, 0, $max);
}

function neptune_field_practice_parse_date(string $value, ?array $cfg = null): ?DateTimeImmutable {
    $cfg = $cfg ?: neptune_field_practice_config();
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) return null;
    if ((int)$date->format('Y') !== (int)$cfg['season']) return null;
    if ((int)$date->format('N') !== 7) return null; // Sunday only.
    return $date;
}

function neptune_field_practice_key_for_date(DateTimeImmutable $date, ?array $cfg = null): string {
    $cfg = $cfg ?: neptune_field_practice_config();
    return (string)$cfg['practice_key_prefix'].$date->format('Y-m-d');
}

function neptune_field_practice_date_from_key(string $key, ?array $cfg = null): ?DateTimeImmutable {
    $cfg = $cfg ?: neptune_field_practice_config();
    $prefix = (string)$cfg['practice_key_prefix'];
    if (!str_starts_with($key, $prefix)) return null;
    return neptune_field_practice_parse_date(substr($key, strlen($prefix)), $cfg);
}

function neptune_field_practice_date_label(DateTimeImmutable $date): string {
    return $date->format('l, F j, Y');
}

function neptune_field_practice_season_like(?array $cfg = null): string {
    $cfg = $cfg ?: neptune_field_practice_config();
    return (string)$cfg['practice_key_prefix'].(int)$cfg['season'].'-%';
}

function neptune_field_practice_is_future_or_today(DateTimeImmutable $date): bool {
    $today = new DateTimeImmutable('today');
    return $date >= $today;
}
