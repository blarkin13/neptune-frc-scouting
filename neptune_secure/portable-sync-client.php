<?php
declare(strict_types=1);

/**
 * Client used by a local Neptune portable server to push event changes back
 * to the cloud installation that created the package.
 */

function neptune_portable_local_settings(array $config): array
{
    $p = is_array($config['portable'] ?? null) ? $config['portable'] : [];
    return [
        'cloud_url' => rtrim((string)($p['cloud_url'] ?? ''), '/'),
        'organization_id' => (int)($p['organization_id'] ?? 0),
        'event_id' => (int)($p['event_id'] ?? 0), // legacy event-scoped packages
        'sync_token' => trim((string)($p['sync_token'] ?? '')),
        'package_created_at' => (string)($p['package_created_at'] ?? ''),
    ];
}

function neptune_portable_http_json(
    string $url,
    string $token,
    array $payload,
    int $timeout = 90
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for cloud synchronization.');
    }

    $body = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($body === false) throw new RuntimeException('Could not encode sync payload.');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Neptune-Portable-Token: ' . $token,
        ],
        CURLOPT_POSTFIELDS => $body,
    ]);
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false || $code < 200 || $code >= 300) {
        throw new RuntimeException(
            'Cloud sync HTTP ' . $code . ($err !== '' ? ': ' . $err : '') .
            ' response=' . substr((string)$response, 0, 800)
        );
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        throw new RuntimeException('Cloud rejected sync: ' . substr((string)$response, 0, 1000));
    }
    return $decoded;
}

function neptune_portable_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function neptune_portable_send_section(
    PDO $pdo,
    array $settings,
    string $section,
    string $sql,
    array $params,
    int $batchSize = 250
): array {
    $rows = neptune_portable_fetch_all($pdo, $sql, $params);
    $summary = ['section' => $section, 'rows' => count($rows), 'batches' => 0, 'responses' => []];

    if (!$rows) {
        // Send an empty section only when it carries semantic value; otherwise
        // there is nothing for the cloud to do.
        return $summary;
    }

    foreach (array_chunk($rows, max(1, $batchSize)) as $batch) {
        $response = neptune_portable_http_json(
            $settings['cloud_url'] . '/api/portable-sync.php',
            $settings['sync_token'],
            [
                'organization_id' => $settings['organization_id'],
                'event_id' => $settings['event_id'],
                'section' => $section,
                'rows' => $batch,
            ]
        );
        $summary['batches']++;
        $summary['responses'][] = $response['counts'] ?? [];
    }

    return $summary;
}

function neptune_portable_upload_media(
    array $settings,
    string $kind,
    string $file,
    array $fields
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for media synchronization.');
    }
    if (!is_file($file)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'missing_local_file'];
    }

    $size = (int)(filesize($file) ?: 0);
    if ($size <= 0) return ['ok' => true, 'skipped' => true, 'reason' => 'empty_local_file'];
    if ($size > 64 * 1024 * 1024) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'file_over_64mb'];
    }

    $mime = function_exists('mime_content_type')
        ? (string)(mime_content_type($file) ?: 'application/octet-stream')
        : 'application/octet-stream';

    $post = array_merge($fields, [
        'organization_id' => (string)$settings['organization_id'],
        'event_id' => (string)$settings['event_id'],
        'kind' => $kind,
        'media' => new CURLFile($file, $mime, basename($file)),
    ]);

    $ch = curl_init($settings['cloud_url'] . '/api/portable-sync-media.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-Neptune-Portable-Token: ' . $settings['sync_token'],
        ],
        CURLOPT_POSTFIELDS => $post,
    ]);
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false || $code < 200 || $code >= 300) {
        throw new RuntimeException(
            'Media sync HTTP ' . $code . ($err !== '' ? ': ' . $err : '') .
            ' response=' . substr((string)$response, 0, 800)
        );
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        throw new RuntimeException('Cloud rejected media sync: ' . substr((string)$response, 0, 1000));
    }
    return $decoded;
}

function neptune_portable_sync_event_to_cloud(PDO $pdo, array $config, int $event): array
{
    $settings = neptune_portable_local_settings($config);
    if ($settings['cloud_url'] === '' || !preg_match('~^https://~i', $settings['cloud_url'])) {
        throw new RuntimeException('This portable server does not have a valid HTTPS cloud destination.');
    }
    if ($settings['organization_id'] <= 0 || $event <= 0 || $settings['sync_token'] === '') {
        throw new RuntimeException('This portable server does not have organization cloud sync enabled.');
    }

    set_time_limit(0);
    $org = $settings['organization_id'];
    $settings['event_id'] = $event;

    $sections = [];

    $sections[] = neptune_portable_send_section(
        $pdo, $settings, 'event',
        'SELECT id,event_status,is_current,roster_synced_at,schedule_synced_at,last_tba_sync_at
         FROM events WHERE id=? AND organization_id=?',
        [$event,$org], 1
    );

    $sections[] = neptune_portable_send_section(
        $pdo, $settings, 'event_teams',
        'SELECT event_id,frc_team_number,nickname,city,state_prov,country,tba_team_key
         FROM event_teams WHERE event_id=? ORDER BY frc_team_number',
        [$event]
    );

    $sections[] = neptune_portable_send_section(
        $pdo, $settings, 'matches',
        'SELECT id,organization_id,event_id,game_id,tba_match_key,comp_level,set_number,match_number,field_id,
                scheduled_time,started_at,ended_at,paused_at,total_pause_seconds,run_number,
                red_score,blue_score,winning_alliance,state
         FROM matches
         WHERE organization_id=? AND event_id=?
         ORDER BY field_id,comp_level,set_number,match_number',
        [$org,$event]
    );

    $sections[] = neptune_portable_send_section(
        $pdo, $settings, 'match_teams',
        "SELECT mt.match_id,mt.frc_team_number,mt.alliance,mt.station,
                m.comp_level AS match_comp_level,m.set_number AS match_set_number,
                m.match_number AS match_number,m.field_id AS match_field_id
         FROM match_teams mt
         JOIN matches m ON m.id=mt.match_id
         WHERE m.organization_id=? AND m.event_id=?
         ORDER BY m.id,mt.alliance,mt.station",
        [$org,$event]
    );

    $sections[] = neptune_portable_send_section(
        $pdo, $settings, 'scout_sessions',
        "SELECT ss.uuid,ss.organization_id,ss.event_id,ss.match_id,ss.user_id,ss.scout_name,
                ss.frc_team_number,ss.alliance,ss.station,ss.field_id,ss.match_run_number,
                ss.status,ss.last_seen_at,ss.created_at,
                m.comp_level AS match_comp_level,m.set_number AS match_set_number,
                m.match_number AS match_number,m.field_id AS match_field_id
         FROM scout_sessions ss
         JOIN matches m ON m.id=ss.match_id
         WHERE ss.organization_id=? AND ss.event_id=?
         ORDER BY ss.id",
        [$org,$event]
    );

    $sections[] = neptune_portable_send_section(
        $pdo, $settings, 'scouting_actions',
        "SELECT a.uuid,a.organization_id,a.owner_team_id,a.event_id,a.match_id,
                ss.uuid AS scout_session_uuid,a.game_id,a.frc_team_number,a.alliance,
                a.action_code,a.action_name,a.action_type,a.location,a.result,a.points,
                a.match_time_sec,a.match_run_number,a.phase,a.source,a.source_ip,a.created_by,
                a.recorded_at,a.legacy_source,a.legacy_id,a.deleted_at,a.deleted_by,a.deletion_reason,
                m.comp_level AS match_comp_level,m.set_number AS match_set_number,
                m.match_number AS match_number,m.field_id AS match_field_id
         FROM scouting_actions a
         JOIN matches m ON m.id=a.match_id
         LEFT JOIN scout_sessions ss ON ss.id=a.scout_session_id
         WHERE a.organization_id=? AND a.event_id=?
         ORDER BY a.id",
        [$org,$event], 300
    );

    $sections[] = neptune_portable_send_section(
        $pdo, $settings, 'pit_scouting',
        'SELECT organization_id,owner_team_id,event_id,frc_team_number,submitted_by,data_json,notes,status,
                started_at,completed_at,created_at,updated_at
         FROM pit_scouting
         WHERE organization_id=? AND event_id=?
         ORDER BY frc_team_number',
        [$org,$event]
    );

    $sections[] = neptune_portable_send_section(
        $pdo, $settings, 'pre_scouting',
        'SELECT organization_id,event_id,game_id,frc_team_number,submitted_by,contact_status,contact_name,
                contact_method,contact_details,contacted_at,data_json,notes,status,inherited_from_event_id,
                completed_at,created_at,updated_at
         FROM pre_scouting
         WHERE organization_id=? AND event_id=?
         ORDER BY frc_team_number',
        [$org,$event]
    );

    if (neptune_portable_table_exists($pdo, 'match_strategies')) {
        $sections[] = neptune_portable_send_section(
            $pdo, $settings, 'match_strategies',
            "SELECT ms.organization_id,ms.event_id,ms.match_id,ms.frc_team_number,ms.strategy_json,
                    ms.created_by,ms.updated_by,ms.created_at,ms.updated_at,
                    m.comp_level AS match_comp_level,m.set_number AS match_set_number,
                    m.match_number AS match_number,m.field_id AS match_field_id
             FROM match_strategies ms
             JOIN matches m ON m.id=ms.match_id
             WHERE ms.organization_id=? AND ms.event_id=?
             ORDER BY ms.id",
            [$org,$event]
        );
    }

    if (neptune_portable_table_exists($pdo, 'robot_season_profiles')) {
        $sections[] = neptune_portable_send_section(
            $pdo, $settings, 'robot_season_profiles',
            "SELECT rsp.organization_id,rsp.game_id,rsp.frc_team_number,rsp.data_json,rsp.notes,rsp.tba_json,
                    rsp.tba_updated_at,rsp.last_event_id,rsp.updated_by,rsp.created_at,rsp.updated_at
             FROM robot_season_profiles rsp
             JOIN events e ON e.game_id=rsp.game_id AND e.id=?
             WHERE rsp.organization_id=?
               AND rsp.frc_team_number IN (SELECT frc_team_number FROM event_teams WHERE event_id=?)
             ORDER BY rsp.frc_team_number",
            [$event,$org,$event]
        );
    }

    if (neptune_portable_table_exists($pdo, 'tag_scouting_tags')) {
        $sections[] = neptune_portable_send_section(
            $pdo, $settings, 'tag_definitions',
            'SELECT organization_id,seed_key,label,slug,category,icon,severity,match_enabled,pit_enabled,
                    team_enabled,active,sort_order,created_by,created_at,updated_at
             FROM tag_scouting_tags
             WHERE organization_id=?
             ORDER BY id',
            [$org]
        );
    }

    if (neptune_portable_table_exists($pdo, 'tag_scouting_observations')) {
        $sections[] = neptune_portable_send_section(
            $pdo, $settings, 'tag_observations',
            "SELECT o.uuid,o.organization_id,o.event_id,o.match_id,o.field_id,o.frc_team_number,o.context,
                    o.entry_mode,o.source_key,o.note,o.severity,o.status,o.created_by,o.created_at,o.updated_at,
                    o.resolved_by,o.resolved_at,o.resolution_note,
                    m.comp_level AS match_comp_level,m.set_number AS match_set_number,
                    m.match_number AS match_number,m.field_id AS match_field_id
             FROM tag_scouting_observations o
             LEFT JOIN matches m ON m.id=o.match_id
             WHERE o.organization_id=? AND (o.event_id=? OR o.event_id IS NULL)
             ORDER BY o.id",
            [$org,$event]
        );

        if (neptune_portable_table_exists($pdo, 'tag_scouting_observation_tags')) {
            $sections[] = neptune_portable_send_section(
                $pdo, $settings, 'tag_observation_tags',
                "SELECT o.uuid AS observation_uuid,ot.weight,t.seed_key,t.slug,t.organization_id AS tag_organization_id
                 FROM tag_scouting_observation_tags ot
                 JOIN tag_scouting_observations o ON o.id=ot.observation_id
                 JOIN tag_scouting_tags t ON t.id=ot.tag_id
                 WHERE o.organization_id=? AND (o.event_id=? OR o.event_id IS NULL)
                 ORDER BY o.id,t.id",
                [$org,$event]
            );
        }
    }

    if (neptune_portable_table_exists($pdo, 'tag_scouting_match_data')) {
        $sections[] = neptune_portable_send_section(
            $pdo, $settings, 'tag_match_data',
            "SELECT o.uuid AS observation_uuid,md.organization_id,md.event_id,md.match_id,md.game_id,md.user_id,
                    md.frc_team_number,md.alliance,md.station,md.scoring_contribution,md.tags_json,
                    md.tag_weights_json,md.note,md.created_at,md.updated_at,
                    m.comp_level AS match_comp_level,m.set_number AS match_set_number,
                    m.match_number AS match_number,m.field_id AS match_field_id
             FROM tag_scouting_match_data md
             LEFT JOIN tag_scouting_observations o ON o.id=md.observation_id
             JOIN matches m ON m.id=md.match_id
             WHERE md.organization_id=? AND md.event_id=?
             ORDER BY md.id",
            [$org,$event]
        );
    }

    foreach ([
        'pit_match_board_state' => 'SELECT * FROM pit_match_board_state WHERE organization_id=? AND event_id=?',
        'pit_match_board_match_state' => "SELECT p.*,m.comp_level AS match_comp_level,m.set_number AS match_set_number,
                    m.match_number AS match_number,m.field_id AS match_field_id
             FROM pit_match_board_match_state p
             JOIN matches m ON m.id=p.match_id
             WHERE p.organization_id=? AND p.event_id=?",
        'alliance_selection_state' => 'SELECT * FROM alliance_selection_state WHERE organization_id=? AND event_id=?',
        'alliance_selection_beta_event_state' => 'SELECT * FROM alliance_selection_beta_event_state WHERE organization_id=? AND event_id=?',
        'alliance_selection_beta_team_state' => 'SELECT * FROM alliance_selection_beta_team_state WHERE organization_id=? AND event_id=?',
    ] as $section => $sql) {
        if (neptune_portable_table_exists($pdo, $section)) {
            $sections[] = neptune_portable_send_section($pdo, $settings, $section, $sql, [$org,$event]);
        }
    }

    $media = ['pit_total'=>0,'pit_uploaded'=>0,'pit_skipped'=>0,'tag_total'=>0,'tag_uploaded'=>0,'tag_skipped'=>0];

    if (neptune_portable_table_exists($pdo, 'pit_scouting_photos')) {
        $rows = neptune_portable_fetch_all(
            $pdo,
            "SELECT p.file_path,p.category,p.caption,ps.frc_team_number
             FROM pit_scouting_photos p
             JOIN pit_scouting ps ON ps.id=p.pit_scouting_id
             WHERE p.organization_id=? AND ps.event_id=?
             ORDER BY p.id",
            [$org,$event]
        );
        $root = dirname(__DIR__) . '/public_html/Neptune/';
        foreach ($rows as $row) {
            $media['pit_total']++;
            $rel = ltrim(str_replace('\\','/',(string)$row['file_path']),'/');
            $result = neptune_portable_upload_media(
                $settings,
                'pit',
                $root . $rel,
                [
                    'frc_team_number' => (string)(int)$row['frc_team_number'],
                    'category' => (string)($row['category'] ?? 'other'),
                    'caption' => (string)($row['caption'] ?? ''),
                ]
            );
            if (!empty($result['skipped'])) $media['pit_skipped']++;
            else $media['pit_uploaded']++;
        }
    }

    if (neptune_portable_table_exists($pdo, 'tag_scouting_media')) {
        $rows = neptune_portable_fetch_all(
            $pdo,
            "SELECT tm.storage_relpath,tm.media_type,tm.original_filename,tm.mime_type,tm.width,tm.height,
                    tm.duration_seconds,o.uuid AS observation_uuid
             FROM tag_scouting_media tm
             JOIN tag_scouting_observations o ON o.id=tm.observation_id
             WHERE tm.organization_id=? AND o.event_id=?
             ORDER BY tm.id",
            [$org,$event]
        );
        $root = dirname(__DIR__) . '/public_html/Neptune/';
        foreach ($rows as $row) {
            $media['tag_total']++;
            $rel = ltrim(str_replace('\\','/',(string)$row['storage_relpath']),'/');
            $result = neptune_portable_upload_media(
                $settings,
                'tag',
                $root . $rel,
                [
                    'observation_uuid' => (string)$row['observation_uuid'],
                    'media_type' => (string)$row['media_type'],
                    'original_filename' => (string)($row['original_filename'] ?? ''),
                    'duration_seconds' => (string)($row['duration_seconds'] ?? ''),
                ]
            );
            if (!empty($result['skipped'])) $media['tag_skipped']++;
            else $media['tag_uploaded']++;
        }
    }

    return [
        'ok' => true,
        'cloud_url' => $settings['cloud_url'],
        'organization_id' => $org,
        'event_id' => $event,
        'sections' => $sections,
        'media' => $media,
        'completed_at_utc' => gmdate('c'),
    ];
}


function neptune_portable_sync_to_cloud(PDO $pdo, array $config): array
{
    $settings = neptune_portable_local_settings($config);
    if ($settings['cloud_url'] === '' || !preg_match('~^https://~i', $settings['cloud_url'])) {
        throw new RuntimeException('This portable server does not have a valid HTTPS cloud destination.');
    }
    if ($settings['organization_id'] <= 0 || $settings['sync_token'] === '') {
        throw new RuntimeException('This portable server was not created as an organization package with cloud sync enabled.');
    }

    $org = $settings['organization_id'];
    $s = $pdo->prepare('SELECT id,name,start_date,end_date FROM events WHERE organization_id=? ORDER BY COALESCE(start_date,"9999-12-31"),id');
    $s->execute([$org]);
    $events = $s->fetchAll(PDO::FETCH_ASSOC);

    $eventResults = [];
    $errors = [];
    foreach ($events as $eventRow) {
        $eventId = (int)$eventRow['id'];
        try {
            $eventResults[] = neptune_portable_sync_event_to_cloud($pdo, $config, $eventId);
        } catch (Throwable $e) {
            $errors[] = [
                'event_id' => $eventId,
                'event_name' => (string)($eventRow['name'] ?? ''),
                'error' => $e->getMessage(),
            ];
        }
    }

    if ($errors) {
        throw new RuntimeException(
            'One or more events could not be synchronized: ' .
            json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    return [
        'ok' => true,
        'cloud_url' => $settings['cloud_url'],
        'organization_id' => $org,
        'events_synced' => count($eventResults),
        'events' => $eventResults,
        'completed_at_utc' => gmdate('c'),
    ];
}
