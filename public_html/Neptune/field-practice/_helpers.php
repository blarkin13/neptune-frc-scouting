<?php
declare(strict_types=1);

/**
 * Neptune public field-practice signup helpers.
 *
 * Change the values returned by neptune_field_practice_config() when this
 * page is reused for a future practice night. Existing registrations are
 * separated by practice_key.
 */
function neptune_field_practice_config(): array {
    return [
        'practice_key' => 'ntx-sunday-2026-10-11',
        'title' => 'NTX Sunday Field Practice',
        'date_label' => 'Sunday, October 11, 2026',
        'start_label' => '5:00 PM',
        'end_label' => '8:00 PM',
        'start_at' => '2026-10-11 17:00:00',
        'end_at' => '2026-10-11 20:00:00',
        'location_name' => 'McKinney STEM Academy',
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

function neptune_field_practice_is_open(array $cfg): bool {
    try {
        $end = new DateTimeImmutable((string)$cfg['end_at']);
        return new DateTimeImmutable('now') <= $end;
    } catch (Throwable $e) {
        return true;
    }
}
