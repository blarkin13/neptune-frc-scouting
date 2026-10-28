<?php
/**
 * Scout hand-off: moves unsent scouting from a tablet to a lead device by QR code or file
 * when the tablet cannot reach Neptune (cable/adapter failure, or no network at all).
 *
 * Tablet side (assets/js/neptune-handoff.js) bundles three local queues for the signed-in
 * scout: Connection Guard match actions, Match Tag entries and Pit & Alliance entries
 * (photos/videos stay on the tablet). Each item is signed with a per-scout key so the
 * import can credit the original scout and reject edited codes. The key is derived from
 * the scout's password hash, which the Portable Server and AWS share, so a code made from
 * a page loaded on either server imports on either server.
 *
 * Code text: NH1/<bundle id>/<part>/<parts>/<base45 payload>, QR-alphanumeric only so a
 * keyboard-style USB/Bluetooth scanner types it exactly.
 */
declare(strict_types=1);

const NHO_PREFIX = 'NH1';
const NHO_BASE45 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
const NHO_HANDOFF_SESSION_SALT = 'handoff-user:';

/** Hex signing key for a scout, or null when the account has no Neptune password. */
function nho_user_key(PDO $pdo, int $org, int $userId): ?string {
    $s = $pdo->prepare('SELECT password_hash FROM users WHERE id=? AND organization_id=? LIMIT 1');
    $s->execute([$userId, $org]);
    $hash = (string)($s->fetchColumn() ?: '');
    if ($hash === '') return null;
    return hash_hmac('sha256', 'neptune-handoff-v1|' . $org . '|' . $userId, $hash);
}

function nho_item_signature(string $key, int $org, int $userId, string $kind, string $id, string $data): string {
    return substr(hash_hmac('sha256', $org . '|' . $userId . '|' . $kind . '|' . $id . '|' . $data, $key), 0, 32);
}

/** Short code the lead reads back to the scout so the tablet can safely clear what was imported. */
function nho_confirmation_code(string $key, string $bundleId): string {
    return strtoupper(substr(hash_hmac('sha256', 'confirm|' . $bundleId, $key), 0, 6));
}

/** Connection Guard receipt owner for actions imported by hand-off. */
function nho_handoff_session_hash(int $userId): string {
    return hash('sha256', NHO_HANDOFF_SESSION_SALT . $userId);
}

function nho_base45_decode(string $text): string {
    $len = strlen($text);
    if ($len % 3 === 1) throw new InvalidArgumentException('Hand-off code is incomplete.');
    $out = '';
    for ($i = 0; $i < $len; $i += 3) {
        $c = strpos(NHO_BASE45, $text[$i]); $d = strpos(NHO_BASE45, $text[$i + 1] ?? '');
        if ($c === false || $d === false) throw new InvalidArgumentException('Hand-off code has an unexpected character.');
        if ($i + 2 < $len) {
            $e = strpos(NHO_BASE45, $text[$i + 2]);
            if ($e === false) throw new InvalidArgumentException('Hand-off code has an unexpected character.');
            $n = $c + $d * 45 + $e * 2025;
            if ($n > 0xFFFF) throw new InvalidArgumentException('Hand-off code is damaged.');
            $out .= chr($n >> 8) . chr($n & 0xFF);
        } else {
            $n = $c + $d * 45;
            if ($n > 0xFF) throw new InvalidArgumentException('Hand-off code is damaged.');
            $out .= chr($n);
        }
    }
    return $out;
}

/**
 * Parse one scanned/pasted code line. Scanners may add a trailing newline or turn the
 * text lowercase (Caps Lock); both are tolerated.
 */
function nho_parse_chunk(string $line): ?array {
    $line = strtoupper(trim($line));
    if (!preg_match('#^NH1/([0-9A-Z]{8})/([0-9]{1,3})/([0-9]{1,3})/(.+)$#s', $line, $m)) return null;
    $part = (int)$m[2]; $parts = (int)$m[3];
    if ($parts < 1 || $parts > 200 || $part < 1 || $part > $parts) return null;
    return ['bundle' => $m[1], 'part' => $part, 'parts' => $parts, 'payload' => $m[4]];
}

/** Join all parts of one bundle (in any order) and return the decoded bundle array. */
function nho_decode_bundle(array $chunks): array {
    $parts = null; $bundle = null; $byPart = [];
    foreach ($chunks as $c) {
        $bundle ??= $c['bundle']; $parts ??= $c['parts'];
        if ($c['bundle'] !== $bundle || $c['parts'] !== $parts) throw new InvalidArgumentException('Codes from different hand-offs were mixed together.');
        $byPart[$c['part']] = $c['payload'];
    }
    if (!$parts || count($byPart) !== $parts) throw new InvalidArgumentException('Not every part of this hand-off has been scanned yet.');
    ksort($byPart);
    $raw = nho_base45_decode(implode('', $byPart));
    $flag = $raw[0] ?? '';
    $body = substr($raw, 1);
    if ($flag === 'Z') {
        $json = @gzinflate($body, 4 * 1024 * 1024);
        if ($json === false) throw new InvalidArgumentException('Hand-off data could not be unpacked.');
    } elseif ($flag === 'J') {
        $json = $body;
    } else {
        throw new InvalidArgumentException('Unknown hand-off format.');
    }
    $data = json_decode($json, true);
    if (!is_array($data) || (int)($data['v'] ?? 0) !== 1 || ($data['b'] ?? '') !== $bundle) throw new InvalidArgumentException('Hand-off data is not valid.');
    return $data;
}

function nho_ensure_receipts(PDO $pdo): void {
    // Same table Connection Guard creates, so imported actions dedupe against later replays.
    $pdo->exec("CREATE TABLE IF NOT EXISTS action_request_receipts (
        request_uuid CHAR(36) NOT NULL PRIMARY KEY,
        session_hash CHAR(64) NOT NULL,
        response_code SMALLINT UNSIGNED NOT NULL,
        response_body MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_action_request_receipts_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Traditional match action: same checks as api/record-action.php, credited to the scout. */
function nho_import_action(PDO $pdo, array $scout, string $requestUuid, array $d): array {
    require_once dirname(__DIR__) . '/api/_validate-scout-action.php';
    $org = (int)$scout['organization_id']; $uid = (int)$scout['id'];
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $requestUuid)) return ['rejected', 'Invalid action ID.'];
    $r = $pdo->prepare('SELECT 1 FROM action_request_receipts WHERE request_uuid=? LIMIT 1');
    $r->execute([$requestUuid]);
    if ($r->fetchColumn()) return ['already', 'Already received.'];
    $sid = (int)($d['session_id'] ?? 0);
    $s = $pdo->prepare('SELECT ss.*,m.game_id,m.run_number,m.state,gr.match_config_json config_json FROM scout_sessions ss JOIN matches m ON m.id=ss.match_id AND m.organization_id=ss.organization_id AND m.event_id=ss.event_id JOIN events e ON e.id=ss.event_id AND e.organization_id=ss.organization_id AND e.id=m.event_id JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=m.game_id WHERE ss.id=? AND ss.organization_id=? AND ss.user_id=?');
    $s->execute([$sid, $org, $uid]);
    $ss = $s->fetch(PDO::FETCH_ASSOC);
    if (!$ss) return ['rejected', 'This scout session does not belong to this scout.'];
    if ((int)$ss['match_run_number'] !== (int)$ss['run_number']) return ['rejected', 'Recorded during an earlier run of this match (it was restarted).'];
    // Signed hand-off data was captured while the match was live, so a match that has
    // since paused or ended is fine; one that never started is not.
    if (!in_array($ss['state'], ['running', 'paused', 'ended'], true)) return ['rejected', 'This match has not started.'];
    try {
        $v = neptune_validated_scout_action($d['action'] ?? null, $d['result'] ?? 'Neutral', $d['match_time_sec'] ?? null, $ss['config_json']);
    } catch (InvalidArgumentException $e) {
        return ['rejected', $e->getMessage()];
    }
    $team = $pdo->prepare('SELECT frc_team_number FROM match_teams WHERE match_id=? AND alliance=? AND station=?');
    $team->execute([(int)$ss['match_id'], (string)$ss['alliance'], (int)$ss['station']]);
    if ((int)$team->fetchColumn() !== (int)$ss['frc_team_number']) return ['rejected', 'The assigned robot for this station changed.'];
    $pdo->beginTransaction();
    try {
        $uuid = uuidv4();
        $pdo->prepare("INSERT INTO scouting_actions(uuid,organization_id,owner_team_id,event_id,match_id,scout_session_id,game_id,frc_team_number,alliance,action_code,action_name,action_type,location,result,points,match_time_sec,match_run_number,phase,source,source_ip,created_by,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'scout',?,?,UTC_TIMESTAMP())")
            ->execute([$uuid, $org, primary_team_id($pdo, $uid, $org), $ss['event_id'], $ss['match_id'], $sid, $ss['game_id'], $ss['frc_team_number'], $ss['alliance'],
                $v['code'], $v['name'], $v['type'], $v['location'], $v['result'], $v['points'], $v['sec'], (int)$ss['run_number'], $v['phase'], $_SERVER['REMOTE_ADDR'] ?? null, $uid]);
        $actionId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO action_request_receipts (request_uuid,session_hash,response_code,response_body,created_at) VALUES (?,?,200,?,UTC_TIMESTAMP())')
            ->execute([$requestUuid, nho_handoff_session_hash($uid), json_encode(['status' => 'success', 'uuid' => $uuid, 'id' => $actionId, 'handoff' => true])]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $pdo->prepare("UPDATE scout_sessions SET last_seen_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=? AND user_id=?")->execute([$sid, $org, $uid]);
    return ['added', $v['name'] . ' (' . $v['result'] . ') at ' . $v['sec'] . 's'];
}

/** Match Tag keys as resolved by scout/impact.php. */
function nho_match_tag_key(array $row): string {
    $seed = (string)($row['seed_key'] ?? ''); $slug = (string)($row['slug'] ?? '');
    $legacy = ['fast-cycles' => 'fast_cycles', 'slow-cycles' => 'slow_cycles', 'recovered' => 'recovered', 'penalty-risk' => 'penalty_risk'];
    if (isset($legacy[$seed])) return $legacy[$seed];
    if ($seed === 'local:auto_failed' || $slug === 'structured-auto-failed') return 'auto_failed';
    if (str_starts_with($seed, 'match:')) return substr($seed, 6);
    if (($row['organization_id'] ?? null) !== null && $seed === '') return str_replace('-', '_', $slug);
    return '';
}

function nho_match_tag_catalog(PDO $pdo, int $org): array {
    static $cache = [];
    if (isset($cache[$org])) return $cache[$org];
    $defaults = array_fill_keys(['auto_no_attempt','auto_partial','auto_completed','auto_failed','no_scoring','scoring','slow_cycles','fast_cycles','no_defense','attempted_defense','mechanical_issues','disabled','recovered','end_no_attempt','end_partial','end_completed','end_failed','strong_support','penalty_risk'], true);
    $keys = [];
    foreach (tagobs_visible_tags($pdo, $org) as $t) {
        if ((int)$t['match_enabled'] !== 1) continue;
        $key = nho_match_tag_key($t);
        if ($key !== '' && (isset($defaults[$key]) || (($t['organization_id'] ?? null) !== null && (string)($t['seed_key'] ?? '') === ''))) $keys[$key] = true;
    }
    return $cache[$org] = $keys;
}

/** Match Tag entry: same checks and upsert as scout/impact.php, credited to the scout. */
function nho_import_match_tag(PDO $pdo, array $scout, array $d): array {
    $org = (int)$scout['organization_id']; $uid = (int)$scout['id'];
    $eventId = (int)($d['event_id'] ?? 0); $matchId = (int)($d['match_id'] ?? 0); $team = (int)($d['team'] ?? 0);
    $s = $pdo->prepare("SELECT m.id,m.event_id,m.game_id,mt.alliance,mt.station FROM matches m JOIN events e ON e.id=m.event_id AND e.organization_id=? JOIN match_teams mt ON mt.match_id=m.id WHERE m.id=? AND m.event_id=? AND mt.frc_team_number=? LIMIT 1");
    $s->execute([$org, $matchId, $eventId, $team]);
    $target = $s->fetch(PDO::FETCH_ASSOC);
    if (!$target) return ['rejected', 'Robot ' . $team . ' is not in that match.'];
    $raw = $d['scoring_contribution'] ?? null;
    $scoring = ($raw === null || $raw === '' || (int)$raw < 0) ? null : max(0, min(100, (int)$raw));
    $note = mb_substr(trim((string)($d['note'] ?? '')), 0, 500);
    $catalog = nho_match_tag_catalog($pdo, $org);
    $tags = array_values(array_unique(array_filter(array_map('strval', (array)($d['tags'] ?? [])), static fn($t) => isset($catalog[$t]))));
    $binary = ['no_auton' => true, 'no_defense' => true, 'failed_endgame' => true];
    $weights = [];
    $rawWeights = is_array($d['tag_weights'] ?? null) ? $d['tag_weights'] : [];
    foreach ($tags as $t) {
        if (isset($binary[$t])) continue;
        $w = array_key_exists($t, $rawWeights) ? (int)$rawWeights[$t] : -1;
        if ($w >= 1 && $w <= 5) $weights[$t] = $w;
    }
    $tagsJson = json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    $weightsJson = json_encode($weights, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    $q = $pdo->prepare('SELECT scoring_contribution,tags_json,tag_weights_json,note FROM tag_scouting_match_data WHERE organization_id=? AND match_id=? AND user_id=? AND frc_team_number=? LIMIT 1');
    $q->execute([$org, $matchId, $uid, $team]);
    $prior = $q->fetch(PDO::FETCH_ASSOC);
    if ($prior && ($prior['scoring_contribution'] === null ? null : (int)$prior['scoring_contribution']) === $scoring
        && (string)$prior['tags_json'] === $tagsJson && (string)$prior['tag_weights_json'] === $weightsJson && (string)($prior['note'] ?? '') === $note) {
        return ['already', 'Already received.'];
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO tag_scouting_match_data (organization_id,event_id,match_id,game_id,user_id,frc_team_number,alliance,station,scoring_contribution,tags_json,tag_weights_json,note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE event_id=VALUES(event_id),game_id=VALUES(game_id),alliance=VALUES(alliance),station=VALUES(station),
                scoring_contribution=VALUES(scoring_contribution),tags_json=VALUES(tags_json),tag_weights_json=VALUES(tag_weights_json),note=VALUES(note),updated_at=CURRENT_TIMESTAMP")
            ->execute([$org, $eventId, $matchId, (int)$target['game_id'], $uid, $team, $target['alliance'], $target['station'], $scoring, $tagsJson, $weightsJson, $note !== '' ? $note : null]);
        $q2 = $pdo->prepare('SELECT * FROM tag_scouting_match_data WHERE organization_id=? AND match_id=? AND user_id=? AND frc_team_number=? LIMIT 1');
        $q2->execute([$org, $matchId, $uid, $team]);
        $saved = $q2->fetch();
        if (!$saved) throw new RuntimeException('Could not reload the saved Tag entry.');
        tag_unified_sync_match_observation($pdo, $saved, $tags, $weights);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    require_once dirname(__DIR__) . '/_assignment_auto_complete.php';
    nac_try_finish($pdo, $org, 'match', $eventId, $matchId, $uid);
    return [$prior ? 'updated' : 'added', 'Robot ' . $team . ($scoring !== null ? ' · ' . $scoring . '% contribution' : '')];
}

/** Pit & Alliance entry without media: same checks as scout/tag-api.php "save". */
function nho_import_observation(PDO $pdo, array $scout, string $uuid, array $d): array {
    $org = (int)$scout['organization_id']; $uid = (int)$scout['id'];
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $uuid)) return ['rejected', 'Invalid entry ID.'];
    $context = (string)($d['context'] ?? 'team');
    if (!in_array($context, ['pit', 'team'], true)) return ['rejected', 'Unsupported entry type.'];
    $evidence = $context === 'pit' ? (string)($d['evidence_source'] ?? '') : null;
    if ($context === 'pit' && !in_array($evidence, ['observed', 'team_reported'], true)) return ['rejected', 'Pit entry is missing Observed/Team Reported.'];
    $entryMode = ($d['entry_mode'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
    $eventId = (int)($d['event_id'] ?? 0); $team = (int)($d['frc_team_number'] ?? 0);
    $note = mb_substr(trim((string)($d['note'] ?? '')), 0, 8000);
    $tagIds = array_values(array_unique(array_filter(array_map('intval', (array)($d['tag_ids'] ?? [])), static fn($v) => $v > 0)));
    if ($team <= 0) return ['rejected', 'No robot selected.'];
    $existing = $pdo->prepare('SELECT id,frc_team_number,context FROM tag_scouting_observations WHERE uuid=? AND organization_id=? LIMIT 1');
    $existing->execute([$uuid, $org]);
    if ($prior = $existing->fetch(PDO::FETCH_ASSOC)) {
        if ((int)$prior['frc_team_number'] !== $team || (string)$prior['context'] !== $context) return ['rejected', 'Entry ID belongs to a different entry.'];
        return ['already', 'Already received.'];
    }
    if ($eventId > 0) {
        $s = $pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=? LIMIT 1'); $s->execute([$eventId, $org]);
        if (!(int)$s->fetchColumn()) return ['rejected', 'Event not found.'];
        $s = $pdo->prepare('SELECT COUNT(*) FROM event_teams WHERE event_id=? AND frc_team_number=?'); $s->execute([$eventId, $team]);
        if ((int)$s->fetchColumn() === 0) return ['rejected', 'Robot ' . $team . ' is not on that event roster.'];
    }
    $tags = [];
    if ($tagIds) {
        $column = $context === 'pit' ? 'pit_enabled' : 'team_enabled';
        $wanted = array_fill_keys($tagIds, true);
        foreach (tagobs_visible_tags($pdo, $org, false) as $tag) if (isset($wanted[(int)$tag['id']]) && !empty($tag[$column])) $tags[] = $tag;
        if (count($tags) !== count($tagIds)) return ['rejected', 'One or more tags are no longer available.'];
    }
    if (!$tags && $note === '') return ['rejected', 'Entry has no tags or note.'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO tag_scouting_observations (uuid,organization_id,event_id,match_id,field_id,frc_team_number,context,entry_mode,evidence_source,note,severity,status,created_by,created_at) VALUES(?,?,?,NULL,NULL,?,?,?,?,?,?,'open',?,NOW())")
            ->execute([$uuid, $org, $eventId ?: null, $team, $context, $entryMode, $evidence, $note !== '' ? $note : null, tagobs_highest_severity($tags), $uid]);
        $obsId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO tag_scouting_observation_tags (observation_id,tag_id) VALUES(?,?)');
        foreach ($tags as $tag) $ins->execute([$obsId, (int)$tag['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['added', ($context === 'pit' ? 'Pit' : 'Alliance') . ' · robot ' . $team];
}

/** Robot ranking (Driving/Defense order for one alliance): same checks as api/robot-ranking.php. */
function nho_import_ranking(PDO $pdo, array $scout, string $id, array $d): array {
    require_once dirname(__DIR__) . '/analytics/_robot_ranking.php';
    if (strtolower((string)($d['uuid'] ?? '')) !== strtolower($id)) return ['rejected', 'Ranking ID mismatch.'];
    return nrr_save($pdo, (int)$scout['organization_id'], (int)$scout['id'], $d);
}

/**
 * Import a decoded bundle on behalf of its scout. The caller has already checked that the
 * signed-in lead may import for this organization.
 */
function nho_import_bundle(PDO $pdo, int $leadOrg, array $bundle): array {
    require_once __DIR__ . '/_tag_scouting.php';
    $org = (int)($bundle['o'] ?? 0); $uid = (int)($bundle['u'] ?? 0); $bundleId = (string)($bundle['b'] ?? '');
    if ($org !== $leadOrg) throw new InvalidArgumentException('This hand-off belongs to a different organization.');
    $s = $pdo->prepare('SELECT * FROM users WHERE id=? AND organization_id=? AND active=1 LIMIT 1');
    $s->execute([$uid, $org]);
    $scout = $s->fetch(PDO::FETCH_ASSOC);
    if (!$scout) throw new InvalidArgumentException('The scout on this hand-off is not an active member of this organization.');
    $key = nho_user_key($pdo, $org, $uid);
    if ($key === null) throw new InvalidArgumentException('This scout has no Neptune password, so their hand-off cannot be verified. Set a password for them.');
    tag_unified_ensure_schema($pdo);
    nho_ensure_receipts($pdo);
    $results = []; $counts = ['added' => 0, 'updated' => 0, 'already' => 0, 'rejected' => 0];
    foreach (array_slice((array)($bundle['items'] ?? []), 0, 2000) as $item) {
        $kind = (string)($item['k'] ?? ''); $id = (string)($item['id'] ?? ''); $raw = (string)($item['d'] ?? '');
        $label = ['a' => 'Match action', 'm' => 'Match Tag', 'o' => 'Pit & Alliance', 'r' => 'Robot ranking'][$kind] ?? 'Unknown';
        $sig = (string)($item['s'] ?? '');
        if (!hash_equals(nho_item_signature($key, $org, $uid, $kind, $id, $raw), $sig)) {
            $status = ['rejected', 'Signature check failed: the code was changed, or the scout changed their password after making it.'];
        } else {
            $d = json_decode($raw, true);
            try {
                $status = !is_array($d) ? ['rejected', 'Entry data is not valid.']
                    : match ($kind) {
                        'a' => nho_import_action($pdo, $scout, $id, $d),
                        'm' => nho_import_match_tag($pdo, $scout, $d),
                        'o' => nho_import_observation($pdo, $scout, $id, $d),
                        'r' => nho_import_ranking($pdo, $scout, $id, $d),
                        default => ['rejected', 'Unknown entry type.'],
                    };
            } catch (Throwable $e) {
                error_log('[Neptune hand-off import] ' . get_class($e) . ': ' . $e->getMessage());
                $status = ['rejected', 'Server error while saving this entry.'];
            }
        }
        $counts[$status[0]]++;
        $results[] = ['kind' => $label, 'status' => $status[0], 'detail' => $status[1]];
    }
    $scoutName = trim((string)($scout['display_name'] ?? '')) ?: (string)$scout['username'];
    return ['scout' => $scoutName, 'bundle' => $bundleId, 'counts' => $counts, 'results' => $results,
        'media_waiting' => (int)($bundle['media'] ?? 0),
        'confirmation' => nho_confirmation_code($key, $bundleId)];
}

/** Config for the tablet widget; null key disables signing (scout has no password). */
function nho_widget_config(PDO $pdo, array $u): array {
    $org = (int)($u['organization_id'] ?? 0); $uid = (int)($u['id'] ?? 0);
    $name = trim((string)($u['display_name'] ?? '')) ?: (string)($u['username'] ?? ('Scout ' . $uid));
    return ['orgId' => $org, 'userId' => $uid, 'name' => $name, 'key' => nho_user_key($pdo, $org, $uid)];
}
