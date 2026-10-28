<?php
/**
 * Robot rankings: after a match, any scout orders the robots of one alliance on Driving and
 * Defense (1st/2nd/3rd, equal numbers = too close to call, blank = not seen). Each ranking
 * becomes head-to-head results, and a Bradley-Terry model turns all of an event's results
 * into one rating per team, shown on an Elo-like scale (1500 = average).
 *
 * Bradley-Terry instead of sequential Elo: it uses every comparison at once, so the result
 * does not depend on the order rankings were submitted.
 */
declare(strict_types=1);

const NRR_QUALITIES = ['driving' => 'Driving', 'defense' => 'Defense'];

function nrr_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS robot_rank_submissions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        uuid CHAR(36) NOT NULL,
        organization_id BIGINT UNSIGNED NOT NULL,
        event_id BIGINT UNSIGNED NOT NULL,
        match_id BIGINT UNSIGNED NOT NULL,
        alliance ENUM('Red','Blue') NOT NULL,
        quality ENUM('driving','defense') NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        ranking_json VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rrs_uuid (uuid),
        UNIQUE KEY uq_rrs_one_per_scout (organization_id, match_id, alliance, quality, user_id),
        KEY idx_rrs_event (organization_id, event_id, quality)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/**
 * Normalize a ranking to groups of team numbers, best first: [[118],[6369,3310]].
 * Accepts {team: rank} or a list of groups. Returns null when fewer than 2 robots are ranked.
 */
function nrr_groups($raw, array $allianceTeams): ?array {
    $byRank = [];
    if (is_array($raw) && array_is_list($raw)) {
        foreach ($raw as $i => $group) foreach ((array)$group as $team) $byRank[$i + 1][] = (int)$team;
    } elseif (is_array($raw)) {
        foreach ($raw as $team => $rank) if ((int)$rank >= 1 && (int)$rank <= 3) $byRank[(int)$rank][] = (int)$team;
    }
    ksort($byRank);
    $seen = []; $groups = [];
    foreach ($byRank as $teams) {
        $g = [];
        foreach ($teams as $t) {
            if (!in_array($t, $allianceTeams, true) || isset($seen[$t])) return null;
            $seen[$t] = true; $g[] = $t;
        }
        sort($g);
        if ($g) $groups[] = $g;
    }
    return count($seen) >= 2 ? $groups : null;
}

/** Save (or replace) one scout's ranking. Returns [status, message]; status added|updated|already|rejected. */
function nrr_save(PDO $pdo, int $org, int $userId, array $d): array {
    nrr_install($pdo);
    $uuid = strtolower(trim((string)($d['uuid'] ?? '')));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) return ['rejected', 'Invalid ranking ID.'];
    $quality = (string)($d['quality'] ?? '');
    if (!isset(NRR_QUALITIES[$quality])) return ['rejected', 'Unknown quality.'];
    $alliance = ($d['alliance'] ?? '') === 'Blue' ? 'Blue' : (($d['alliance'] ?? '') === 'Red' ? 'Red' : '');
    if ($alliance === '') return ['rejected', 'Choose Red or Blue.'];
    $matchId = (int)($d['match_id'] ?? 0);
    $s = $pdo->prepare('SELECT m.event_id FROM matches m JOIN events e ON e.id=m.event_id AND e.organization_id=? WHERE m.id=? LIMIT 1');
    $s->execute([$org, $matchId]);
    $eventId = (int)$s->fetchColumn();
    if (!$eventId) return ['rejected', 'Match not found.'];
    $s = $pdo->prepare('SELECT frc_team_number FROM match_teams WHERE match_id=? AND alliance=?');
    $s->execute([$matchId, $alliance]);
    $teams = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    $groups = nrr_groups($d['groups'] ?? ($d['ranks'] ?? null), $teams);
    if ($groups === null) return ['rejected', 'Rank at least two robots from that alliance.'];
    $json = json_encode($groups);
    $s = $pdo->prepare('SELECT uuid, ranking_json FROM robot_rank_submissions WHERE uuid=? OR (organization_id=? AND match_id=? AND alliance=? AND quality=? AND user_id=?) LIMIT 2');
    $s->execute([$uuid, $org, $matchId, $alliance, $quality, $userId]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) if ($r['uuid'] === $uuid) return ['already', 'Already received.'];
    foreach ($rows as $r) if ($r['ranking_json'] === $json) return ['already', 'Already received.'];
    $pdo->prepare('INSERT INTO robot_rank_submissions (uuid,organization_id,event_id,match_id,alliance,quality,user_id,ranking_json) VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE uuid=VALUES(uuid), ranking_json=VALUES(ranking_json), event_id=VALUES(event_id)')
        ->execute([$uuid, $org, $eventId, $matchId, $alliance, $quality, $userId, $json]);
    $label = NRR_QUALITIES[$quality] . ' · ' . $alliance . ' · ' . implode(' > ', array_map(static fn($g) => implode(' = ', $g), $groups));
    return [$rows ? 'updated' : 'added', $label];
}

/** This scout's saved rankings for a match: [alliance][quality] => groups. */
function nrr_mine(PDO $pdo, int $org, int $userId, int $matchId): array {
    nrr_install($pdo);
    $s = $pdo->prepare('SELECT alliance, quality, ranking_json FROM robot_rank_submissions WHERE organization_id=? AND match_id=? AND user_id=?');
    $s->execute([$org, $matchId, $userId]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['alliance']][$r['quality']] = json_decode((string)$r['ranking_json'], true) ?: [];
    return $out;
}

/**
 * Bradley-Terry ratings for one event and quality. Ties count half a win each way.
 * A weak prior (one win and one loss against an average team) keeps ratings finite for
 * teams that always won or always lost, and pulls thin evidence toward 1500.
 */
function nrr_ratings(PDO $pdo, int $org, int $eventId, string $quality): array {
    nrr_install($pdo);
    $s = $pdo->prepare('SELECT match_id, user_id, ranking_json FROM robot_rank_submissions WHERE organization_id=? AND event_id=? AND quality=?');
    $s->execute([$org, $eventId, $quality]);
    $wins = []; $games = []; $teams = []; $scouts = []; $comparisons = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $groups = json_decode((string)$row['ranking_json'], true);
        if (!is_array($groups)) continue;
        $flat = [];
        foreach ($groups as $gi => $g) foreach ((array)$g as $t) $flat[] = [(int)$t, $gi];
        foreach ($flat as $a) { $teams[$a[0]] = true; $scouts[$a[0]][(int)$row['user_id']] = true; }
        for ($i = 0; $i < count($flat); $i++) for ($j = $i + 1; $j < count($flat); $j++) {
            [$ta, $ga] = $flat[$i]; [$tb, $gb] = $flat[$j];
            $games[$ta][$tb] = ($games[$ta][$tb] ?? 0) + 1; $games[$tb][$ta] = ($games[$tb][$ta] ?? 0) + 1;
            $wa = $ga < $gb ? 1.0 : ($ga === $gb ? 0.5 : 0.0);
            $wins[$ta] = ($wins[$ta] ?? 0) + $wa; $wins[$tb] = ($wins[$tb] ?? 0) + (1 - $wa);
            $comparisons[$ta] = ($comparisons[$ta] ?? 0) + 1; $comparisons[$tb] = ($comparisons[$tb] ?? 0) + 1;
        }
    }
    if (!$teams) return [];
    $p = array_fill_keys(array_keys($teams), 1.0);
    for ($iter = 0; $iter < 300; $iter++) {
        $next = []; $maxChange = 0.0;
        foreach ($p as $t => $pt) {
            $denom = 2.0 / ($pt + 1.0); // prior: two games against an average (p = 1) team
            foreach ($games[$t] ?? [] as $o => $n) $denom += $n / ($pt + $p[$o]);
            $next[$t] = (($wins[$t] ?? 0) + 1.0) / $denom;
        }
        // Keep the geometric mean at 1 so 1500 stays "average for this event".
        $logMean = array_sum(array_map('log', $next)) / count($next);
        foreach ($next as $t => $v) { $v = exp(log($v) - $logMean); $maxChange = max($maxChange, abs($v - $p[$t])); $next[$t] = $v; }
        $p = $next;
        if ($maxChange < 1e-7) break;
    }
    $scale = 400 / log(10);
    $out = [];
    foreach ($p as $t => $pt) {
        $info = 2.0 * $pt / (($pt + 1.0) ** 2);
        foreach ($games[$t] ?? [] as $o => $n) $info += $n * $pt * $p[$o] / (($pt + $p[$o]) ** 2);
        $out[] = ['team' => (int)$t, 'rating' => 1500 + $scale * log($pt), 'plus_minus' => $scale / sqrt(max($info, 1e-9)),
            'comparisons' => (int)($comparisons[$t] ?? 0), 'scouts' => count($scouts[$t] ?? []),
            'win_share' => ($comparisons[$t] ?? 0) ? ($wins[$t] ?? 0) / $comparisons[$t] : null];
    }
    usort($out, static fn($a, $b) => $b['rating'] <=> $a['rating']);
    return $out;
}
