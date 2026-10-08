<?php
declare(strict_types=1);

/**
 * Neptune portable deployment helpers.
 *
 * Production side:
 *  - builds clean / organization portable-server packages from the running installation
 *  - creates short-lived organization-bound sync tokens (hash stored server-side)
 *
 * Local field-server side:
 *  - identifies portable mode from config.php
 */

function neptune_portable_is_local(array $config): bool
{
    return !empty($config['app']['portable_mode']);
}

function neptune_portable_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    $s = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $s->execute([$table]);
    return $cache[$table] = ((int)$s->fetchColumn() > 0);
}

function neptune_portable_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $s = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $s->execute([$table, $column]);
    return $cache[$key] = ((int)$s->fetchColumn() > 0);
}

function neptune_portable_ensure_schema(PDO $pdo): void
{
    neptune_migration_apply(
        $pdo,
        'portable.deployment.v1',
        'Neptune_Portable_Organization_Server_v17_Maintenance_Console.zip',
        function () use ($pdo): void {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS portable_sync_tokens (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    organization_id BIGINT UNSIGNED NOT NULL,
                    event_id BIGINT UNSIGNED NULL,
                    token_hash CHAR(64) NOT NULL,
                    label VARCHAR(190) NULL,
                    created_by BIGINT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL,
                    last_used_at DATETIME NULL,
                    revoked_at DATETIME NULL,
                    UNIQUE KEY uq_portable_sync_token_hash (token_hash),
                    KEY idx_portable_sync_org_event (organization_id,event_id,expires_at),
                    KEY idx_portable_sync_created_by (created_by),
                    CONSTRAINT fk_portable_sync_org FOREIGN KEY (organization_id)
                        REFERENCES organizations(id) ON DELETE CASCADE,
                    CONSTRAINT fk_portable_sync_event FOREIGN KEY (event_id)
                        REFERENCES events(id) ON DELETE CASCADE,
                    CONSTRAINT fk_portable_sync_user FOREIGN KEY (created_by)
                        REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            // v2: organization-wide portable servers are not bound to a single
            // event. Existing event-scoped tokens remain valid for backward
            // compatibility; new tokens store NULL event_id.
            if (neptune_portable_column_exists($pdo, 'portable_sync_tokens', 'event_id')) {
                try {
                    $pdo->exec('ALTER TABLE portable_sync_tokens MODIFY event_id BIGINT UNSIGNED NULL');
                } catch (Throwable $ignored) {}
            }
        }
    );

    neptune_migration_apply(
        $pdo,
        'portable.organization-scope.v2',
        'Neptune_Portable_Organization_Server_v17_Maintenance_Console.zip',
        function () use ($pdo): void {
            if (neptune_portable_table_exists($pdo, 'portable_sync_tokens')
                && neptune_portable_column_exists($pdo, 'portable_sync_tokens', 'event_id')) {
                $pdo->exec('ALTER TABLE portable_sync_tokens MODIFY event_id BIGINT UNSIGNED NULL');
            }
        }
    );
}

function neptune_portable_absolute_base_url(array $config): string
{
    $configured = trim((string)($config['app']['public_url'] ?? ''));
    if ($configured !== '') return rtrim($configured, '/');

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return '';

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
    if (!empty($config['app']['trust_proxy']) && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($proto === 'https') $https = true;
    }

    $basePath = trim((string)($config['app']['base_url'] ?? '/Neptune'));
    $basePath = '/' . trim($basePath, '/');
    if ($basePath === '/') $basePath = '';

    return ($https ? 'https://' : 'http://') . $host . $basePath;
}

function neptune_portable_create_token(
    PDO $pdo,
    int $organizationId,
    ?int $eventId,
    int $createdBy,
    string $label = 'Portable organization server'
): array {
    neptune_portable_ensure_schema($pdo);

    $s = $pdo->prepare("SELECT id FROM organizations WHERE id=? AND COALESCE(platform_status,'active')='active' LIMIT 1");
    $s->execute([$organizationId]);
    if (!$s->fetchColumn()) {
        throw new RuntimeException('The selected organization is unavailable.');
    }

    $eventId = (int)($eventId ?? 0);
    if ($eventId > 0) {
        $s = $pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=? LIMIT 1');
        $s->execute([$eventId, $organizationId]);
        if (!$s->fetchColumn()) {
            throw new RuntimeException('The selected event does not belong to the selected organization.');
        }
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = gmdate('Y-m-d H:i:s', time() + (30 * 86400));

    $s = $pdo->prepare(
        'INSERT INTO portable_sync_tokens
         (organization_id,event_id,token_hash,label,created_by,expires_at)
         VALUES(?,?,?,?,?,?)'
    );
    $s->execute([
        $organizationId,
        $eventId > 0 ? $eventId : null,
        $hash,
        substr($label, 0, 190),
        $createdBy > 0 ? $createdBy : null,
        $expires,
    ]);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'token' => $token,
        'expires_at' => $expires,
        'organization_id' => $organizationId,
        'event_id' => $eventId > 0 ? $eventId : null,
    ];
}

function neptune_portable_authenticate_token(
    PDO $pdo,
    string $providedToken,
    int $organizationId,
    int $eventId = 0
): array {
    neptune_portable_ensure_schema($pdo);
    $providedToken = trim($providedToken);
    if ($providedToken === '' || $organizationId <= 0) {
        throw new RuntimeException('Portable sync authentication is missing.');
    }

    $hash = hash('sha256', $providedToken);
    $s = $pdo->prepare(
        "SELECT *
         FROM portable_sync_tokens
         WHERE token_hash=?
           AND organization_id=?
           AND revoked_at IS NULL
           AND expires_at>=UTC_TIMESTAMP()
           AND (event_id IS NULL OR event_id=0 OR event_id=?)
         LIMIT 1"
    );
    $s->execute([$hash, $organizationId, max(0, $eventId)]);
    $row = $s->fetch();
    if (!$row) {
        throw new RuntimeException('Portable sync token is invalid, expired, revoked, or bound to another organization/event.');
    }

    $pdo->prepare('UPDATE portable_sync_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([(int)$row['id']]);

    return $row;
}

function neptune_portable_safe_relpath(string $path): string
{
    $path = ltrim(str_replace('\\', '/', trim($path)), '/');
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '../')) return '';
    if (!preg_match('~^(?:uploads|games)/~', $path)) return '';
    return $path;
}

function neptune_portable_query_rows(PDO $pdo, string $sql, array $params = []): array
{
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Build a memory-safe, selected-event snapshot directly to disk.
 *
 * The original v1 exporter loaded every organization-scoped row from every
 * table into one PHP array before json_encode(). On a production organization
 * with accumulated scouting/AUGUR history that could exhaust PHP memory and
 * terminate the request before the normal error panel could render.
 *
 * This writer:
 *  - scopes event-owned tables to the selected event
 *  - scopes game/season tables to the selected event's game/season
 *  - keeps organization-level configuration/accounts needed by the field server
 *  - streams rows directly into event.json rather than building one giant array
 */
function neptune_portable_write_snapshot_file(
    PDO $pdo,
    int $organizationId,
    int $eventId,
    string $targetFile
): array {
    if ($organizationId <= 0 || $eventId <= 0) {
        throw new RuntimeException('Organization and event are required for an event snapshot.');
    }

    $eventStmt = $pdo->prepare(
        'SELECT e.*,g.season_year
         FROM events e
         JOIN games g ON g.id=e.game_id AND g.organization_id=e.organization_id
         WHERE e.id=? AND e.organization_id=?
         LIMIT 1'
    );
    $eventStmt->execute([$eventId, $organizationId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) throw new RuntimeException('Event not found for that organization.');

    $gameId = (int)($event['game_id'] ?? 0);
    $seasonYear = (int)($event['season_year'] ?? 0);
    if ($gameId <= 0) throw new RuntimeException('Selected event has no valid game.');

    $orgStmt = $pdo->prepare('SELECT id FROM organizations WHERE id=? LIMIT 1');
    $orgStmt->execute([$organizationId]);
    if (!$orgStmt->fetchColumn()) throw new RuntimeException('Organization not found.');

    $excludedOrgTables = [
        'audit_log',
        'action_request_receipts',
        'neptune_auth_invites',
        'neptune_auth_rate_limits',
        'neptune_user_mfa',
        'offline_sync_runs',
        'portable_sync_tokens',
        'sharing_relationships',
        'game_config_shares',
        'training_attempts',
        'neptune_tba_live_state',
    ];

    // Read all organization-scoped table/column metadata up front so no
    // information_schema queries are needed while an unbuffered result is open.
    $metaStmt = $pdo->query(
        "SELECT TABLE_NAME,COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
         ORDER BY TABLE_NAME,ORDINAL_POSITION"
    );
    $columnsByTable = [];
    foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $table = (string)$col['TABLE_NAME'];
        $columnsByTable[$table][] = (string)$col['COLUMN_NAME'];
    }

    $plans = [];

    $plans['organizations'] = [
        'SELECT * FROM organizations WHERE id=?',
        [$organizationId],
    ];

    foreach ($columnsByTable as $table => $columns) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) continue;
        if (!in_array('organization_id', $columns, true)) continue;
        if ($table === 'organizations' || in_array($table, $excludedOrgTables, true)) continue;

        if ($table === 'events') {
            $plans[$table] = [
                'SELECT * FROM events WHERE id=? AND organization_id=?',
                [$eventId, $organizationId],
            ];
            continue;
        }

        if ($table === 'games') {
            $plans[$table] = [
                'SELECT * FROM games WHERE id=? AND organization_id=?',
                [$gameId, $organizationId],
            ];
            continue;
        }

        if ($table === 'pit_scouting_photos' && isset($columnsByTable['pit_scouting'])) {
            $plans[$table] = [
                'SELECT psp.*
                 FROM pit_scouting_photos psp
                 JOIN pit_scouting ps ON ps.id=psp.pit_scouting_id
                 WHERE psp.organization_id=? AND ps.organization_id=? AND ps.event_id=?',
                [$organizationId, $organizationId, $eventId],
            ];
            continue;
        }

        if ($table === 'tag_scouting_media' && isset($columnsByTable['tag_scouting_observations'])) {
            $plans[$table] = [
                'SELECT m.*
                 FROM tag_scouting_media m
                 JOIN tag_scouting_observations o ON o.id=m.observation_id
                 WHERE m.organization_id=? AND o.organization_id=? AND o.event_id=?',
                [$organizationId, $organizationId, $eventId],
            ];
            continue;
        }

        // Seeded global tags plus this organization's custom tags are both
        // required for local Tag/Spot scouting.
        if ($table === 'tag_scouting_tags') {
            $plans[$table] = [
                'SELECT * FROM tag_scouting_tags
                 WHERE organization_id IS NULL OR organization_id=?
                 ORDER BY id',
                [$organizationId],
            ];
            continue;
        }

        if (in_array('event_id', $columns, true)) {
            $plans[$table] = [
                "SELECT * FROM `{$table}` WHERE organization_id=? AND event_id=?",
                [$organizationId, $eventId],
            ];
        } elseif (in_array('game_id', $columns, true)) {
            $plans[$table] = [
                "SELECT * FROM `{$table}` WHERE organization_id=? AND game_id=?",
                [$organizationId, $gameId],
            ];
        } elseif ($seasonYear > 0 && in_array('season_year', $columns, true)) {
            $plans[$table] = [
                "SELECT * FROM `{$table}` WHERE organization_id=? AND season_year=?",
                [$organizationId, $seasonYear],
            ];
        } else {
            $plans[$table] = [
                "SELECT * FROM `{$table}` WHERE organization_id=?",
                [$organizationId],
            ];
        }
    }

    // Dependency tables without their own organization_id.
    if (isset($columnsByTable['event_teams'])) {
        $plans['event_teams'] = [
            'SELECT * FROM event_teams WHERE event_id=?',
            [$eventId],
        ];
    }
    if (isset($columnsByTable['match_teams'])) {
        $plans['match_teams'] = [
            'SELECT mt.*
             FROM match_teams mt
             JOIN matches m ON m.id=mt.match_id
             WHERE m.organization_id=? AND m.event_id=?',
            [$organizationId, $eventId],
        ];
    }
    if (isset($columnsByTable['user_teams'])) {
        $plans['user_teams'] = [
            'SELECT ut.*
             FROM user_teams ut
             JOIN users u ON u.id=ut.user_id
             WHERE u.organization_id=?',
            [$organizationId],
        ];
    }
    if (isset($columnsByTable['tag_scouting_observation_tags'])) {
        $plans['tag_scouting_observation_tags'] = [
            'SELECT ot.*
             FROM tag_scouting_observation_tags ot
             JOIN tag_scouting_observations o ON o.id=ot.observation_id
             WHERE o.organization_id=? AND o.event_id=?',
            [$organizationId, $eventId],
        ];
    }

    $fh = @fopen($targetFile, 'wb');
    if (!$fh) throw new RuntimeException('Could not create temporary portable event snapshot.');

    $assets = [];
    $counts = [];
    $assetColumns = ['file_path','storage_relpath','logo_path','field_image_path','json_filename'];
    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    // Disable PDO buffering where supported. This prevents a large table from
    // being materialized by mysqlnd before we can stream it to disk.
    $bufferedQueryChanged = false;
    $oldBuffered = true;
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        try {
            $oldBuffered = (bool)$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $bufferedQueryChanged = true;
        } catch (Throwable $ignored) {
            $bufferedQueryChanged = false;
        }
    }

    try {
        $header = [
            'format' => 'neptune-portable-snapshot-v1',
            'created_at_utc' => gmdate('c'),
            'organization_id' => $organizationId,
            'event_id' => $eventId,
        ];
        $prefix = json_encode($header, $jsonFlags);
        if ($prefix === false) throw new RuntimeException('Could not encode portable event snapshot header.');
        $prefix = rtrim($prefix, '}') . ',"tables":{';
        if (fwrite($fh, $prefix) === false) throw new RuntimeException('Could not write portable event snapshot.');

        $firstTable = true;
        foreach ($plans as $table => [$sql, $params]) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) continue;

            if (!$firstTable && fwrite($fh, ',') === false) {
                throw new RuntimeException('Could not write portable event snapshot.');
            }
            $firstTable = false;

            $tableNameJson = json_encode((string)$table, $jsonFlags);
            if ($tableNameJson === false || fwrite($fh, $tableNameJson . ':[') === false) {
                throw new RuntimeException('Could not write portable event snapshot table header.');
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rowCount = 0;
            $firstRow = true;

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!$firstRow && fwrite($fh, ',') === false) {
                    throw new RuntimeException('Could not write portable event snapshot.');
                }
                $firstRow = false;

                foreach ($assetColumns as $col) {
                    if (!isset($row[$col]) || !is_string($row[$col])) continue;
                    $safe = neptune_portable_safe_relpath($row[$col]);
                    if ($safe !== '') $assets[$safe] = true;
                }

                $rowJson = json_encode($row, $jsonFlags);
                if ($rowJson === false || fwrite($fh, $rowJson) === false) {
                    throw new RuntimeException('Could not encode/write row for portable table: ' . $table);
                }
                $rowCount++;
            }
            $stmt->closeCursor();
            if (fwrite($fh, ']') === false) throw new RuntimeException('Could not finish portable event snapshot table.');
            $counts[$table] = $rowCount;
        }

        $assetList = array_values(array_keys($assets));
        sort($assetList, SORT_STRING);
        $assetsJson = json_encode($assetList, $jsonFlags);
        if ($assetsJson === false || fwrite($fh, '},"assets":' . $assetsJson . '}') === false) {
            throw new RuntimeException('Could not finish portable event snapshot.');
        }
    } finally {
        fclose($fh);
        if ($bufferedQueryChanged) {
            try {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $oldBuffered);
            } catch (Throwable $ignored) {}
        }
    }

    clearstatcache(true, $targetFile);
    if (!is_file($targetFile) || (int)filesize($targetFile) <= 0) {
        throw new RuntimeException('Portable event snapshot was empty.');
    }

    return [
        'format' => 'neptune-portable-snapshot-v1',
        'organization_id' => $organizationId,
        'event_id' => $eventId,
        'game_id' => $gameId,
        'season_year' => $seasonYear,
        'assets' => array_values(array_keys($assets)),
        'table_counts' => $counts,
        'bytes' => (int)filesize($targetFile),
    ];
}

/**
 * Stream a complete single-organization snapshot to disk.
 *
 * Authentication secrets/MFA/rate-limit/audit tables are deliberately excluded.
 * All events for the organization are included; no event picker is required.
 */

/**
 * Return accounts that production can prove were created as Google-only users.
 * Existing password accounts that later linked Google are deliberately excluded.
 */
function neptune_portable_google_only_users(PDO $pdo, int $organizationId): array
{
    if ($organizationId <= 0) return [];

    foreach ([
        ['users','google_sub'],
        ['users','password_changed_at'],
        ['audit_log','organization_id'],
        ['audit_log','user_id'],
        ['audit_log','action'],
    ] as [$table,$column]) {
        if (!neptune_portable_table_exists($pdo, $table)
            || !neptune_portable_column_exists($pdo, $table, $column)) {
            return [];
        }
    }

    $displayExpr = neptune_portable_column_exists($pdo,'users','display_name')
        ? 'u.display_name'
        : "''";
    if (neptune_portable_column_exists($pdo,'users','google_email')) {
        $emailExpr = neptune_portable_column_exists($pdo,'users','email')
            ? "COALESCE(NULLIF(u.google_email,''),u.email)"
            : "u.google_email";
    } else {
        $emailExpr = neptune_portable_column_exists($pdo,'users','email') ? 'u.email' : "''";
    }

    $sql = "
        SELECT
            u.id,
            u.username,
            {$displayExpr} AS display_name,
            {$emailExpr} AS google_email
        FROM users u
        WHERE u.organization_id=?
          AND u.active=1
          AND u.google_sub IS NOT NULL
          AND u.google_sub<>''
          AND u.password_changed_at IS NULL
          AND EXISTS (
              SELECT 1
              FROM audit_log a
              WHERE a.organization_id=u.organization_id
                AND a.user_id=u.id
                AND a.action IN ('google_user_auto_provisioned','google_user_invited')
          )
        ORDER BY u.id
    ";
    $s=$pdo->prepare($sql);
    $s->execute([$organizationId]);

    $rows=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){
        $rows[]=[
            'id'=>(int)$row['id'],
            'username'=>(string)($row['username']??''),
            'display_name'=>(string)($row['display_name']??''),
            'google_email'=>(string)($row['google_email']??''),
        ];
    }
    return $rows;
}

function neptune_portable_write_organization_snapshot_file(
    PDO $pdo,
    int $organizationId,
    string $targetFile
): array {
    if ($organizationId <= 0) throw new RuntimeException('Organization is required.');

    $orgStmt = $pdo->prepare("SELECT * FROM organizations WHERE id=? AND COALESCE(platform_status,'active')='active' LIMIT 1");
    $orgStmt->execute([$organizationId]);
    $orgRow = $orgStmt->fetch(PDO::FETCH_ASSOC);
    if (!$orgRow) throw new RuntimeException('Organization not found or suspended.');

    $meta = $pdo->query(
        "SELECT TABLE_NAME,COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
         ORDER BY TABLE_NAME,ORDINAL_POSITION"
    )->fetchAll(PDO::FETCH_ASSOC);
    $columnsByTable = [];
    foreach ($meta as $col) {
        $columnsByTable[(string)$col['TABLE_NAME']][] = (string)$col['COLUMN_NAME'];
    }

    $excluded = [
        'audit_log','action_request_receipts','neptune_auth_invites',
        'neptune_auth_rate_limits','neptune_user_mfa','offline_sync_runs',
        'portable_sync_tokens','sharing_relationships'
    ];

    $plans = [
        'organizations' => ['SELECT * FROM organizations WHERE id=?', [$organizationId]],
    ];

    foreach ($columnsByTable as $table => $columns) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) continue;
        if ($table === 'organizations' || in_array($table, $excluded, true)) continue;
        if (in_array('organization_id', $columns, true)) {
            if ($table === 'tag_scouting_tags') {
                $plans[$table] = [
                    'SELECT * FROM tag_scouting_tags WHERE organization_id IS NULL OR organization_id=? ORDER BY id',
                    [$organizationId]
                ];
            } else {
                $plans[$table] = ["SELECT * FROM `{$table}` WHERE organization_id=?", [$organizationId]];
            }
        }
    }

    if (isset($columnsByTable['event_teams'])) {
        $plans['event_teams'] = [
            'SELECT et.* FROM event_teams et JOIN events e ON e.id=et.event_id WHERE e.organization_id=?',
            [$organizationId]
        ];
    }
    if (isset($columnsByTable['match_teams'])) {
        $plans['match_teams'] = [
            'SELECT mt.* FROM match_teams mt JOIN matches m ON m.id=mt.match_id WHERE m.organization_id=?',
            [$organizationId]
        ];
    }
    if (isset($columnsByTable['user_teams'])) {
        $plans['user_teams'] = [
            'SELECT ut.* FROM user_teams ut JOIN users u ON u.id=ut.user_id WHERE u.organization_id=?',
            [$organizationId]
        ];
    }
    if (isset($columnsByTable['tag_scouting_observation_tags'])) {
        $plans['tag_scouting_observation_tags'] = [
            'SELECT ot.* FROM tag_scouting_observation_tags ot
             JOIN tag_scouting_observations o ON o.id=ot.observation_id
             WHERE o.organization_id=?',
            [$organizationId]
        ];
    }

    $fh = @fopen($targetFile, 'wb');
    if (!$fh) throw new RuntimeException('Could not create temporary organization snapshot.');

    $assets = [];
    $counts = [];
    $assetColumns = ['file_path','storage_relpath','logo_path','field_image_path','json_filename'];
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    $changedBuffering = false;
    $oldBuffered = true;
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        try {
            $oldBuffered = (bool)$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $changedBuffering = true;
        } catch (Throwable $ignored) {}
    }

    try {
        $header = [
            'format' => 'neptune-portable-organization-v1',
            'created_at_utc' => gmdate('c'),
            'organization_id' => $organizationId,
            'organization_name' => (string)($orgRow['name'] ?? ''),
        ];
        $j = json_encode($header, $flags);
        if ($j === false) throw new RuntimeException('Could not encode snapshot header.');
        fwrite($fh, rtrim($j, '}') . ',"tables":{');

        $firstTable = true;
        foreach ($plans as $table => [$sql, $params]) {
            if (!$firstTable) fwrite($fh, ',');
            $firstTable = false;
            fwrite($fh, json_encode((string)$table, $flags) . ':[');

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $firstRow = true;
            $count = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!$firstRow) fwrite($fh, ',');
                $firstRow = false;

                foreach ($assetColumns as $col) {
                    if (!isset($row[$col]) || !is_string($row[$col])) continue;
                    $safe = neptune_portable_safe_relpath($row[$col]);
                    if ($safe !== '') $assets[$safe] = true;
                }

                $rowJson = json_encode($row, $flags);
                if ($rowJson === false) {
                    throw new RuntimeException('Could not encode row from ' . $table);
                }
                if (fwrite($fh, $rowJson) === false) {
                    throw new RuntimeException('Could not write organization snapshot.');
                }
                $count++;
            }
            $stmt->closeCursor();
            fwrite($fh, ']');
            $counts[$table] = $count;
        }

        $assetList = array_keys($assets);
        sort($assetList, SORT_STRING);
        fwrite($fh, '},"assets":' . json_encode($assetList, $flags) . '}');
    } finally {
        fclose($fh);
        if ($changedBuffering) {
            try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $oldBuffered); } catch (Throwable $ignored) {}
        }
    }

    clearstatcache(true, $targetFile);
    if (!is_file($targetFile) || (int)filesize($targetFile) <= 0) {
        throw new RuntimeException('Organization snapshot was empty.');
    }

    return [
        'organization_id' => $organizationId,
        'assets' => array_keys($assets),
        'table_counts' => $counts,
        'bytes' => (int)filesize($targetFile),
    ];
}

/**
 * Write the running Neptune database structure to SQL.
 *
 * The portable package must use the live production structure rather than a
 * potentially stale repository schema because several Neptune modules create
 * or extend tables through application migrations.
 */
function neptune_portable_write_live_schema(PDO $pdo, string $targetFile): array
{
    $fh = @fopen($targetFile, 'wb');
    if (!$fh) throw new RuntimeException('Could not create temporary live schema file.');

    $count = 0;
    try {
        fwrite($fh, "-- Neptune portable live schema\n");
        fwrite($fh, "-- Generated: " . gmdate('c') . "\n");
        fwrite($fh, "SET NAMES utf8mb4;\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = $pdo->query(
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_TYPE='BASE TABLE'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $table = (string)$table;
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) continue;

            $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
            $row = $stmt->fetch(PDO::FETCH_NUM);
            if (!$row || empty($row[1])) {
                throw new RuntimeException('Could not read live schema for table: ' . $table);
            }

            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($fh, (string)$row[1] . ";\n\n");
            $count++;
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($fh);
    }

    if ($count <= 0) {
        @unlink($targetFile);
        throw new RuntimeException('No live database tables were available for the portable schema.');
    }

    return [
        'tables' => $count,
        'bytes' => (int)(filesize($targetFile) ?: 0),
    ];
}

function neptune_portable_add_file(ZipArchive $zip, string $source, string $zipName, int $mode = 0644): void
{
    if (!is_file($source)) return;
    if (!$zip->addFile($source, $zipName)) {
        throw new RuntimeException('Could not add file to portable ZIP: ' . $zipName);
    }
    if (method_exists($zip, 'setExternalAttributesName')) {
        $zip->setExternalAttributesName(
            $zipName,
            ZipArchive::OPSYS_UNIX,
            (($mode & 0777) | 0100000) << 16
        );
    }
}

function neptune_portable_add_string(ZipArchive $zip, string $zipName, string $contents, int $mode = 0644): void
{
    if (!$zip->addFromString($zipName, $contents)) {
        throw new RuntimeException('Could not add generated file to portable ZIP: ' . $zipName);
    }
    if (method_exists($zip, 'setExternalAttributesName')) {
        $zip->setExternalAttributesName(
            $zipName,
            ZipArchive::OPSYS_UNIX,
            (($mode & 0777) | 0100000) << 16
        );
    }
}

function neptune_portable_add_tree(
    ZipArchive $zip,
    string $sourceRoot,
    string $zipRoot,
    callable $exclude
): void {
    if (!is_dir($sourceRoot)) {
        throw new RuntimeException('Portable package source directory is missing: ' . $sourceRoot);
    }

    $sourceRoot = rtrim($sourceRoot, DIRECTORY_SEPARATOR);

    /*
     * Do not use RecursiveDirectoryIterator here. A production web root can
     * contain old deployment/package directories that Apache's PHP user cannot
     * traverse. RecursiveDirectoryIterator throws before our file-level exclude
     * callback ever sees those entries, which made package creation fail on
     * harmless leftovers such as Neptune_MultiOrg_DatabaseLab.
     *
     * This walker filters directories before descending and skips only known
     * deployment-artifact directories or unreadable directories that match that
     * artifact pattern. Any other unreadable directory still fails closed so we
     * do not silently build an incomplete Neptune package.
     */
    $walk = function (string $dir, string $prefix = '') use (&$walk, $zip, $zipRoot, $exclude): void {
        $entries = @scandir($dir);
        if ($entries === false) {
            throw new RuntimeException('Could not read portable source directory: ' . $dir);
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') continue;

            $abs = $dir . DIRECTORY_SEPARATOR . $name;
            $rel = ltrim($prefix . $name, '/');

            // Never follow symlinks while constructing a distributable package.
            if (is_link($abs)) continue;

            if (is_dir($abs)) {
                $dirRel = $rel . '/';
                if ($exclude($dirRel, $abs)) continue;

                $isTopLevelArtifact = $prefix === ''
                    && preg_match('/^Neptune_[A-Za-z0-9._-]+$/', $name) === 1;

                if (!is_readable($abs)) {
                    if ($isTopLevelArtifact) continue;
                    throw new RuntimeException('Portable source directory is not readable: ' . $abs);
                }

                // Top-level Neptune_* directories are unpacked patch/build
                // artifacts, not runtime application directories. Exclude them
                // even when readable so they are never redistributed.
                if ($isTopLevelArtifact) continue;

                $walk($abs, $dirRel);
                continue;
            }

            if (!is_file($abs)) continue;
            if ($exclude($rel, $abs)) continue;
            if (!is_readable($abs)) {
                throw new RuntimeException('Portable source file is not readable: ' . $abs);
            }

            $mode = str_ends_with($rel, '.command') || str_ends_with($rel, '.sh') ? 0755 : 0644;
            neptune_portable_add_file($zip, $abs, rtrim($zipRoot, '/') . '/' . $rel, $mode);
        }
    };

    $walk($sourceRoot);
}

function neptune_portable_build_package(
    PDO $pdo,
    array $config,
    string $type,
    int $createdBy,
    ?int $organizationId = null,
    ?int $eventId = null
): array {
    @set_time_limit(0);
    @ignore_user_abort(true);
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive is required to build portable packages.');
    }
    if (!in_array($type, ['clean','organization'], true)) {
        throw new RuntimeException('Unknown portable package type.');
    }

    $root = '/var/www/neptune';
    $appRoot = $root . '/public_html/Neptune';
    $secureRoot = $root . '/neptune_secure';
    $scriptRoot = $appRoot . '/assets/portable-server';
    foreach ([$appRoot,$secureRoot,$scriptRoot] as $required) {
        if (!is_dir($required)) throw new RuntimeException('Required portable source is missing: ' . $required);
    }

    $stamp = gmdate('Ymd-His');
    $label = $type === 'clean' ? 'Clean' : 'Organization';
    $filename = "Neptune_Portable_{$label}_{$stamp}.zip";
    $tmp = tempnam(sys_get_temp_dir(), 'neptune-portable-');
    if ($tmp === false) throw new RuntimeException('Could not allocate temporary package file.');
    @unlink($tmp);
    $tmp .= '.zip';

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create portable ZIP.');
    }

    $bundleRoot = "Neptune_Portable_{$label}_{$stamp}";
    $snapshot = null;
    $snapshotTemp = null;
    $schemaTemp = null;
    $schemaInfo = null;
    $googleOnlyUsers = [];
    $token = null;
    $cloudUrl = neptune_portable_absolute_base_url($config);

    try {
        neptune_portable_add_tree(
            $zip,
            $appRoot,
            $bundleRoot . '/payload/public_html/Neptune',
            static function (string $rel): bool {
                $rel = ltrim($rel, '/');
                if (str_starts_with($rel, 'uploads/')) return true;
                if (str_starts_with($rel, 'assets/portable-server/')) return true;
                if (str_starts_with($rel, 'assets/portable-mac/')) return true;
                if (preg_match('~^Neptune_[^/]+(?:/|$)~', $rel)) return true;
                if (preg_match('~(^|/)(?:\.git|__MACOSX)(/|$)~', $rel)) return true;
                if (preg_match('~(?:\.log|\.bak|\.old|\.orig|\.zip|\.sql|\.env|\.ini|~)$~i', $rel)) return true;
                return false;
            }
        );

        neptune_portable_add_tree(
            $zip,
            $secureRoot,
            $bundleRoot . '/payload/neptune_secure',
            static function (string $rel): bool {
                $base = basename($rel);
                if ($base === 'config.php' || $base === 'offline_sync.php') return true;
                if (str_starts_with($base, '.')) return true;
                if (preg_match('~\.(?:env|pem|key|p12|pfx|crt|sqlite|db)$~i', $base)) return true;
                if (preg_match('~(?:secret|credential|token|key)\.(?:php|txt|json)$~i', $base)) return true;
                return false;
            }
        );

        $schemaTemp = tempnam(sys_get_temp_dir(), 'neptune-live-schema-');
        if ($schemaTemp === false) throw new RuntimeException('Could not allocate temporary live schema file.');
        $schemaInfo = neptune_portable_write_live_schema($pdo, $schemaTemp);
        neptune_portable_add_file(
            $zip,
            $schemaTemp,
            $bundleRoot . '/payload/sql/neptune_schema.sql',
            0644
        );

        $scriptMap = [
            'Install Neptune.command',
            'Install Neptune.sh',
            'Start Neptune.command',
            'Start Neptune.sh',
            'Repair Neptune.command',
            'Repair Neptune.sh',
            'Stop Neptune.command',
            'Stop Neptune.sh',
            'Backup Neptune.command',
            'Backup Neptune.sh',
            'Sync to AWS.command',
            'Sync to AWS.sh',
            'Destroy Neptune.command',
            'Destroy Neptune.sh',
            'Clean Old Neptune.command',
            'Clean Old Neptune.sh',
            'README.txt',
            'MAC FIRST RUN.txt',
            'portable-common.sh',
            'import-organization.php',
            'prepare-offline-accounts.php',
        ];
        foreach ($scriptMap as $name) {
            $src = $scriptRoot . '/' . $name;
            $dest = in_array($name, ['import-organization.php','prepare-offline-accounts.php'], true)
                ? $bundleRoot . '/portable-tools/' . $name
                : $bundleRoot . '/' . $name;
            neptune_portable_add_file(
                $zip, $src, $dest,
                (str_ends_with($name, '.command') || str_ends_with($name, '.sh')) ? 0755 : 0644
            );
        }

        // Bundle Windows WSL2 launchers in every freshly generated portable ZIP.
        // No live credentials or organization exports are embedded here.
        $windowsTools = [
            'Install Neptune on Windows.ps1' => <<<'NEPTUNE_WINDOWS_INSTALL'
# Neptune Portable: Windows WSL2 launcher (beta)
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not (Test-Path (Join-Path $here 'Install Neptune.sh'))) {
    throw 'Extract the complete Neptune portable ZIP first. Keep this installer beside Install Neptune.sh.'
}
Write-Host 'Neptune Portable - Windows (WSL2/Ubuntu beta)' -ForegroundColor Cyan
Write-Host 'Requires initialized Ubuntu under WSL2 with systemd enabled.'
Write-Host 'See WINDOWS-SETUP.txt. Tablet LAN networking is not configured automatically.' -ForegroundColor Yellow
$distributions = @(wsl.exe --list --quiet 2>$null | ForEach-Object { ($_ -replace "`0", '').Trim() })
$ubuntu = $distributions | Where-Object { $_ -match '^Ubuntu(?:[-.]\S+)?$' } | Select-Object -First 1
if (-not $ubuntu) {
    throw 'No Ubuntu WSL distro found. Run wsl --install -d Ubuntu, restart if required, and launch Ubuntu once to create a user.'
}
$linuxPath = ((wsl.exe -d $ubuntu -- wslpath -a $here) -join '').Trim()
if (-not $linuxPath.StartsWith('/')) { throw 'Unable to convert Windows folder to a WSL path.' }
& wsl.exe -d $ubuntu -- sh -lc 'test "$(ps -p 1 -o comm=)" = systemd'
if ($LASTEXITCODE -ne 0) {
    throw 'WSL systemd is not running. Enable systemd in /etc/wsl.conf and restart WSL before installing. See WINDOWS-SETUP.txt.'
}
Write-Host "Using WSL distribution $ubuntu. Ubuntu may request your sudo password."
& wsl.exe -d $ubuntu -- bash -lc 'cd "$1" && sudo bash "Install Neptune.sh"' bash $linuxPath
if ($LASTEXITCODE -ne 0) { throw "Neptune installer exited with code $LASTEXITCODE." }
Write-Host 'Installation command finished. Run Start Neptune on Windows.ps1 to launch.' -ForegroundColor Green
NEPTUNE_WINDOWS_INSTALL
,
            'Start Neptune on Windows.ps1' => <<<'NEPTUNE_WINDOWS_START'
# Neptune Portable: Windows WSL2 launcher (beta)
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not (Test-Path (Join-Path $here 'Start Neptune.sh'))) {
    throw 'Start this from the complete, extracted Neptune portable server folder.'
}
$distributions = @(wsl.exe --list --quiet 2>$null | ForEach-Object { ($_ -replace "`0", '').Trim() })
$ubuntu = $distributions | Where-Object { $_ -match '^Ubuntu(?:[-.]\S+)?$' } | Select-Object -First 1
if (-not $ubuntu) { throw 'Ubuntu WSL distro not found.' }
$linuxPath = ((wsl.exe -d $ubuntu -- wslpath -a $here) -join '').Trim()
if (-not $linuxPath.StartsWith('/')) { throw 'Unable to convert Windows folder to a WSL path.' }
& wsl.exe -d $ubuntu -- bash -lc 'cd "$1" && sudo bash "Start Neptune.sh"' bash $linuxPath
if ($LASTEXITCODE -ne 0) { throw "Unable to start Neptune (exit $LASTEXITCODE)." }
Start-Process 'http://localhost/Neptune/'
Write-Host 'For scout tablets, confirm LAN reachability separately. WSL NAT is not automatically exposed.' -ForegroundColor Yellow
NEPTUNE_WINDOWS_START
,
            'WINDOWS-SETUP.txt' => <<<'NEPTUNE_WINDOWS_SETUP'
NEPTUNE PORTABLE - WINDOWS WSL2 (BETA)

The portable application runs inside Ubuntu/WSL2 on Windows, not as a
native Windows server. This is an unverified integration path; first test
with a disposable organization and verify LAN tablet access before an event.

Requirements:
- Windows 10 (2004+) or Windows 11; virtualization enabled
- WSL2 with Ubuntu installed and initialized; systemd enabled
- A complete, extracted Neptune portable ZIP (not this Maintenance Console patch)
- Internet during installation for Ubuntu packages

Install:
1. Open Administrator PowerShell, run: wsl --install -d Ubuntu
2. Restart Windows if requested; launch Ubuntu to create its Linux username.
3. Confirm Ubuntu is on WSL2: wsl --list --verbose
4. Make sure WSL systemd works: in Ubuntu run "ps -p 1 -o comm=".
   It should say "systemd". If it does not, in Ubuntu add:
       [boot]
       systemd=true
   to /etc/wsl.conf, then in Windows PowerShell run "wsl --shutdown"
   and restart Ubuntu. (Use your preferred text editor with sudo.)
5. Extract your *organization-specific* portable server ZIP to a local
   Windows folder. Do not distribute this private ZIP to other teams.
6. Open PowerShell in that extracted folder and run:
   powershell -ExecutionPolicy Bypass -File ".\Install Neptune on Windows.ps1"
7. Once installed, run:
   powershell -ExecutionPolicy Bypass -File ".\Start Neptune on Windows.ps1"
8. Browse to http://localhost/Neptune/ on this Windows computer.
9. When Internet returns, manually sync in Command Center -> Portable
   Organization Server. The sync-status panel polls AWS every 60 seconds.

LAN/tablet networking warning:
WSL2 commonly uses NAT and localhost forwarding. Windows localhost access
is not proof that tablets on the physical LAN can reach it. Windows 11
22H2+ may support mirrored WSL networking in %UserProfile%\.wslconfig:
       [wsl2]
       networkingMode=mirrored
Restart WSL with "wsl --shutdown". Windows Firewall and router client
isolation may still block tablets. Other configurations (especially Windows
10) may require explicit port forwarding and firewall changes. This package
does NOT automatically configure those, so test on an isolated scouting LAN.

The new sync-status UI shows AWS reachability and last successful upload,
not a guaranteed count of unsynced local edits. Uploads remain manual.
NEPTUNE_WINDOWS_SETUP
,
        ];
        foreach ($windowsTools as $name => $content) {
            neptune_portable_add_string($zip, $bundleRoot . '/' . $name, $content . "\n", 0644);
        }

        $packageInfo = [
            'format' => 'neptune-portable-v2',
            'type' => $type,
            'created_at_utc' => gmdate('c'),
            'source' => $cloudUrl,
            'application_root' => 'payload/public_html/Neptune',
            'secure_root' => 'payload/neptune_secure',
            'schema' => 'payload/sql/neptune_schema.sql',
            'schema_source' => 'live production database structure',
            'schema_table_count' => (int)($schemaInfo['tables'] ?? 0),
        ];

        if ($type === 'organization') {
            $organizationId = (int)$organizationId;
            if ($organizationId <= 0) throw new RuntimeException('Organization is required.');

            $snapshotTemp = tempnam(sys_get_temp_dir(), 'neptune-org-snapshot-');
            if ($snapshotTemp === false) throw new RuntimeException('Could not allocate organization snapshot.');

            $snapshot = neptune_portable_write_organization_snapshot_file($pdo, $organizationId, $snapshotTemp);
            $googleOnlyUsers = neptune_portable_google_only_users($pdo, $organizationId);

            neptune_portable_add_string(
                $zip,
                $bundleRoot . '/portable/google-only-users.json',
                json_encode(
                    [
                        'format'=>'neptune-portable-google-only-users-v1',
                        'organization_id'=>$organizationId,
                        'generated_at_utc'=>gmdate('c'),
                        'users'=>$googleOnlyUsers,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ) . "\n",
                0600
            );

            $token = neptune_portable_create_token(
                $pdo, $organizationId, null, $createdBy, 'Portable organization server ' . $stamp
            );

            neptune_portable_add_file(
                $zip, $snapshotTemp, $bundleRoot . '/portable/organization.json', 0600
            );

            $shellQuote = static function (string $value): string {
                return "'" . str_replace("'", "'\\''", $value) . "'";
            };
            $env = implode("\n", [
                'PACKAGE_TYPE=' . $shellQuote('organization'),
                'CLOUD_URL=' . $shellQuote($cloudUrl),
                'ORGANIZATION_ID=' . $shellQuote((string)$organizationId),
                'SYNC_TOKEN=' . $shellQuote((string)$token['token']),
                'TOKEN_EXPIRES_AT=' . $shellQuote((string)$token['expires_at']),
                'PACKAGE_CREATED_AT=' . $shellQuote(gmdate('c')),
                '',
            ]);
            neptune_portable_add_string($zip, $bundleRoot . '/portable/organization.env', $env, 0600);

            foreach ($snapshot['assets'] as $rel) {
                $safe = neptune_portable_safe_relpath((string)$rel);
                if ($safe === '') continue;
                neptune_portable_add_file(
                    $zip,
                    $appRoot . '/' . $safe,
                    $bundleRoot . '/payload/public_html/Neptune/' . $safe,
                    0644
                );
            }

            $packageInfo['organization_id'] = $organizationId;
            $packageInfo['sync_token_expires_at'] = $token['expires_at'];
            $packageInfo['snapshot_bytes'] = (int)($snapshot['bytes'] ?? 0);
            $packageInfo['snapshot_table_counts'] = $snapshot['table_counts'] ?? [];
            $packageInfo['google_only_offline_accounts'] = count($googleOnlyUsers);
            $packageInfo['warning'] = 'Private organization package: contains organization account hashes, all organization event/scouting data and a temporary cloud sync credential.';
        } else {
            neptune_portable_add_string(
                $zip,
                $bundleRoot . '/portable/organization.env',
                "PACKAGE_TYPE=clean\nPACKAGE_CREATED_AT=" . gmdate('c') . "\n",
                0600
            );
        }

        neptune_portable_add_string(
            $zip,
            $bundleRoot . '/PACKAGE_INFO.json',
            json_encode($packageInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            0644
        );

        $zip->close();
        if ($snapshotTemp !== null) { @unlink($snapshotTemp); $snapshotTemp = null; }
        if ($schemaTemp !== null) { @unlink($schemaTemp); $schemaTemp = null; }
    } catch (Throwable $e) {
        try { $zip->close(); } catch (Throwable $ignored) {}
        if ($snapshotTemp !== null) @unlink($snapshotTemp);
        if ($schemaTemp !== null) @unlink($schemaTemp);
        @unlink($tmp);
        if (is_array($token) && !empty($token['id'])) {
            try {
                $pdo->prepare('UPDATE portable_sync_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=?')
                    ->execute([(int)$token['id']]);
            } catch (Throwable $ignored) {}
        }
        throw $e;
    }

    return [
        'path' => $tmp,
        'filename' => $filename,
        'sha256' => hash_file('sha256', $tmp) ?: '',
        'bytes' => (int)(filesize($tmp) ?: 0),
        'type' => $type,
        'token_expires_at' => $token['expires_at'] ?? null,
    ];
}
