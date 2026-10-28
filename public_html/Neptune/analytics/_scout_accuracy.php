<?php
/**
 * Scout accuracy: compares traditional scouting with official alliance scores.
 *
 * 1. For every alliance where all three robots were scouted, add up the scouted points
 *    and compare them with TBA's official score with penalties removed (modeled_score).
 * 2. Remove the event-wide capture ratio first (e.g. scouts record 82% of official points
 *    because of how actions map to points). That shared gap is not any one scout's fault.
 * 3. Split each alliance's remaining error across the scouts who covered it with a ridge
 *    least-squares fit, so a scout's estimate is separated from their teammates' as scouts
 *    rotate. Ridge pulls scouts with few alliances toward zero instead of flagging them.
 * 4. Grade each scout. A flag needs both a large gap (vs a typical robot's points) and a clear
 *    one (t-statistic), because one event gives each scout only a few dozen alliances.
 *    "Check" is a review flag; only "Unreliable" lowers the scout's weight (0.25–1), which
 *    analytics uses when several scouts covered the same robot in the same match.
 *
 * Actions imported from legacy systems carry no individual scout; they are reported
 * as "imported" and never graded.
 */
declare(strict_types=1);

const NSA_MIN_ALLIANCES = 5;      // below this a scout is "not enough data", never flagged
const NSA_RIDGE = 4.0;            // shrinkage toward "no bias", in alliance-equivalents
const NSA_CHECK = 0.15;           // |bias| / typical robot points that earns "Check"
const NSA_UNRELIABLE = 0.30;      // ... and "Unreliable"
const NSA_WEIGHT_SCALE = 0.25;    // weight = 1 / (1 + (rel/scale)^2), clamped to [0.25, 1]
const NSA_T_CHECK = 2.0;          // and the gap must be statistically clear: |bias| / standard error
const NSA_T_UNRELIABLE = 3.0;

function nsa_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_scout_reliability (
        organization_id BIGINT UNSIGNED NOT NULL,
        event_id BIGINT UNSIGNED NOT NULL,
        scout_key VARCHAR(160) NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        grade VARCHAR(16) NOT NULL,
        weight DECIMAL(5,3) NOT NULL,
        bias_points DECIMAL(9,2) NOT NULL,
        relative_bias DECIMAL(7,3) NOT NULL,
        alliances INT UNSIGNED NOT NULL,
        computed_at DATETIME NOT NULL,
        PRIMARY KEY (organization_id, event_id, scout_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/** Stable identity of whoever entered a row: Neptune user if known, else the session's scout name. */
function nsa_scout_key($userId, $scoutName): string {
    if ($userId !== null && (int)$userId > 0) return 'u:' . (int)$userId;
    return 'n:' . mb_strtolower(trim((string)$scoutName) !== '' ? trim((string)$scoutName) : 'unknown');
}

/** Solve (A + λI)x = b by Gaussian elimination with partial pivoting. */
function nsa_solve(array $a, array $b, float $lambda): array {
    $n = count($b);
    for ($i = 0; $i < $n; $i++) $a[$i][$i] += $lambda;
    for ($c = 0; $c < $n; $c++) {
        $p = $c;
        for ($r = $c + 1; $r < $n; $r++) if (abs($a[$r][$c]) > abs($a[$p][$c])) $p = $r;
        if (abs($a[$p][$c]) < 1e-12) continue;
        [$a[$c], $a[$p]] = [$a[$p], $a[$c]]; [$b[$c], $b[$p]] = [$b[$p], $b[$c]];
        for ($r = $c + 1; $r < $n; $r++) {
            $f = $a[$r][$c] / $a[$c][$c];
            if ($f == 0.0) continue;
            for ($k = $c; $k < $n; $k++) $a[$r][$k] -= $f * $a[$c][$k];
            $b[$r] -= $f * $b[$c];
        }
    }
    $x = array_fill(0, $n, 0.0);
    for ($i = $n - 1; $i >= 0; $i--) {
        if (abs($a[$i][$i]) < 1e-12) continue;
        $s = $b[$i];
        for ($k = $i + 1; $k < $n; $k++) $s -= $a[$i][$k] * $x[$k];
        $x[$i] = $s / $a[$i][$i];
    }
    return $x;
}

/** A scout is flagged only when the gap is both large (relative to a typical robot) and clear (t-statistic). */
function nsa_grade(float $rel, float $t, int $alliances, bool $identified): array {
    if (!$identified) return ['imported', 1.0];
    if ($alliances < NSA_MIN_ALLIANCES) return ['insufficient', 1.0];
    $abs = abs($rel); $at = abs($t);
    $grade = ($abs >= NSA_UNRELIABLE && $at >= NSA_T_UNRELIABLE) ? 'unreliable'
        : (($abs >= NSA_CHECK && $at >= NSA_T_CHECK) ? 'check' : 'ok');
    // "Check" is a review flag only. Analytics down-weights only when the evidence is strong.
    $weight = $grade === 'unreliable' ? max(0.25, min(1.0, 1.0 / (1.0 + ($rel / NSA_WEIGHT_SCALE) ** 2))) : 1.0;
    return [$grade, round($weight, 3)];
}

/** Full accuracy report for one event. Returns ['available'=>false,'reason'=>...] when it cannot be computed. */
function neptune_scout_accuracy(PDO $pdo, int $org, int $eventId): array {
    $ev = $pdo->prepare('SELECT tba_event_key FROM events WHERE id=? AND organization_id=?');
    $ev->execute([$eventId, $org]);
    $eventKey = trim((string)($ev->fetchColumn() ?: ''));
    if ($eventKey === '') return ['available' => false, 'reason' => 'This event is not linked to a TBA event, so there are no official scores to compare with.'];

    $q = $pdo->prepare("SELECT a.match_id, m.tba_match_key, a.alliance, a.frc_team_number,
            COALESCE(a.created_by, ss.user_id) AS uid, ss.scout_name, u.display_name,
            SUM(a.points) AS pts, SUM(IF(a.phase IN ('auton','auto'), a.points, 0)) AS auto_pts,
            SUM(IF(a.phase='endgame', a.points, 0)) AS end_pts, COUNT(*) AS n
        FROM scouting_actions a
        JOIN matches m ON m.id=a.match_id AND m.organization_id=a.organization_id AND a.match_run_number=m.run_number
        LEFT JOIN scout_sessions ss ON ss.id=a.scout_session_id AND ss.organization_id=a.organization_id
        LEFT JOIN users u ON u.id=COALESCE(a.created_by, ss.user_id) AND u.organization_id=a.organization_id
        WHERE a.organization_id=? AND a.event_id=? AND a.deleted_at IS NULL AND m.tba_match_key IS NOT NULL AND m.tba_match_key<>''
        GROUP BY a.match_id, m.tba_match_key, a.alliance, a.frc_team_number, uid, ss.scout_name, u.display_name");
    $q->execute([$org, $eventId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['available' => false, 'reason' => 'No traditional scouting with official match keys exists for this event yet.'];

    $o = $pdo->prepare('SELECT tba_match_key, alliance, modeled_score, auto_score, teleop_score, endgame_score FROM augur_epa_alliance_samples WHERE tba_event_key=?');
    $o->execute([$eventKey]);
    $official = [];
    foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) $official[$r['tba_match_key'] . '|' . $r['alliance']] = $r;
    if (!$official) return ['available' => false, 'reason' => 'Official score breakdowns for ' . $eventKey . ' have not been imported yet (AUGUR Operations → EPA archive).'];

    // alliance key → team → scout key → totals; plus scout labels and robot-match counts
    $alliances = []; $scouts = [];
    foreach ($rows as $r) {
        $sk = nsa_scout_key($r['uid'], $r['scout_name']);
        $identified = $r['uid'] !== null && (int)$r['uid'] > 0;
        $scouts[$sk] ??= ['key' => $sk, 'user_id' => $identified ? (int)$r['uid'] : null, 'identified' => $identified,
            'name' => $identified ? (string)($r['display_name'] ?: ('User #' . (int)$r['uid'])) : ((string)($r['scout_name'] ?: 'Unknown') . ' (imported)'),
            'robot_matches' => 0];
        $scouts[$sk]['robot_matches']++;
        $ak = $r['tba_match_key'] . '|' . $r['alliance'];
        $alliances[$ak]['robots'][(int)$r['frc_team_number']][$sk] = ['pts' => (float)$r['pts'], 'auto' => (float)$r['auto_pts'], 'end' => (float)$r['end_pts']];
    }

    // Complete alliances only: exactly three scouted robots and an official score.
    $complete = [];
    foreach ($alliances as $ak => $a) {
        if (count($a['robots']) !== 3 || !isset($official[$ak])) continue;
        $sum = ['pts' => 0.0, 'auto' => 0.0, 'end' => 0.0];
        foreach ($a['robots'] as $byScout) {
            $n = count($byScout);
            foreach ($byScout as $t) { foreach ($sum as $f => $_) $sum[$f] += $t[$f] / $n; }
        }
        $off = $official[$ak];
        $complete[$ak] = ['robots' => $a['robots'], 'scouted' => $sum,
            'official' => ['pts' => (float)$off['modeled_score'], 'auto' => (float)$off['auto_score'], 'end' => (float)$off['endgame_score'], 'tele' => (float)$off['teleop_score']]];
    }
    $nAll = count($complete);
    if ($nAll === 0) return ['available' => false, 'reason' => 'No alliance has all three robots scouted and an official score yet, so nothing can be compared.'];

    $ratio = static function (string $s, string $o) use ($complete): ?float {
        $a = 0.0; $b = 0.0;
        foreach ($complete as $c) { $a += $s === 'tele' ? $c['scouted']['pts'] - $c['scouted']['auto'] - $c['scouted']['end'] : $c['scouted'][$s]; $b += $c['official'][$o]; }
        return $b > 0 ? $a / $b : null;
    };
    $k = $ratio('pts', 'pts') ?? 1.0;
    $maeRaw = 0.0; $maeCal = 0.0; $scoutedTotal = 0.0;
    foreach ($complete as $ak => &$c) {
        $c['residual'] = $c['scouted']['pts'] - $k * $c['official']['pts'];
        $maeRaw += abs($c['scouted']['pts'] - $c['official']['pts']);
        $maeCal += abs($c['residual']);
        $scoutedTotal += $c['scouted']['pts'];
    }
    unset($c);
    $typical = max(1.0, $scoutedTotal / (3 * $nAll)); // mean scouted points per robot-match

    // Ridge least squares: residual ≈ Σ coef(scout) · bias(scout)
    $index = array_values(array_keys($scouts)); $pos = array_flip($index); $S = count($index);
    $ata = array_fill(0, $S, array_fill(0, $S, 0.0)); $atb = array_fill(0, $S, 0.0);
    $used = array_fill(0, $S, 0); $absRes = array_fill(0, $S, 0.0);
    foreach ($complete as $c) {
        $coef = [];
        foreach ($c['robots'] as $byScout) { $n = count($byScout); foreach ($byScout as $sk => $_) $coef[$pos[$sk]] = ($coef[$pos[$sk]] ?? 0.0) + 1.0 / $n; }
        foreach ($coef as $i => $ci) {
            $atb[$i] += $ci * $c['residual']; $used[$i]++; $absRes[$i] += abs($c['residual']);
            foreach ($coef as $j => $cj) $ata[$i][$j] += $ci * $cj;
        }
    }
    $bias = nsa_solve($ata, $atb, NSA_RIDGE);

    // Residual spread after the fit, for each scout's standard error: sigma / sqrt(information).
    $sse = 0.0;
    foreach ($complete as $c) {
        $fit = 0.0;
        foreach ($c['robots'] as $byScout) { $n = count($byScout); foreach ($byScout as $sk => $_) $fit += $bias[$pos[$sk]] / $n; }
        $sse += ($c['residual'] - $fit) ** 2;
    }
    $sigma = sqrt($sse / max(1, $nAll - min($S, $nAll - 1)));

    $out = [];
    foreach ($index as $i => $sk) {
        $s = $scouts[$sk];
        $rel = $bias[$i] / $typical;
        $se = $ata[$i][$i] > 0 ? $sigma / sqrt($ata[$i][$i]) : INF;
        $t = is_finite($se) && $se > 0 ? $bias[$i] / $se : 0.0;
        [$grade, $weight] = nsa_grade($rel, $t, $used[$i], $s['identified']);
        $out[] = $s + ['alliances' => $used[$i], 'bias_points' => round($bias[$i], 1), 'relative_bias' => round($rel, 3),
            'std_error' => is_finite($se) ? round($se, 1) : null, 't' => round($t, 1),
            'alliance_miss' => $used[$i] ? round($absRes[$i] / $used[$i], 1) : null, 'grade' => $grade, 'weight' => $weight];
    }
    $order = ['unreliable' => 0, 'check' => 1, 'ok' => 2, 'insufficient' => 3, 'imported' => 4];
    usort($out, static fn($a, $b) => [$order[$a['grade']], -abs($a['relative_bias'])] <=> [$order[$b['grade']], -abs($b['relative_bias'])]);

    return ['available' => true, 'event_key' => $eventKey, 'alliances' => $nAll, 'capture' => $k,
        'capture_auto' => $ratio('auto', 'auto'), 'capture_teleop' => $ratio('tele', 'tele'), 'capture_endgame' => $ratio('end', 'end'),
        'mae_raw' => $maeRaw / $nAll, 'mae_calibrated' => $maeCal / $nAll, 'typical_robot_points' => $typical,
        'identified_scouts' => count(array_filter($out, static fn($s) => $s['identified'])), 'scouts' => $out];
}

/** Saves weights so analytics can use them without recomputing. Failures are logged, never fatal. */
function neptune_scout_accuracy_store(PDO $pdo, int $org, int $eventId, array $report): void {
    if (empty($report['available'])) return;
    try {
        nsa_install($pdo);
        $pdo->prepare('DELETE FROM neptune_scout_reliability WHERE organization_id=? AND event_id=?')->execute([$org, $eventId]);
        $ins = $pdo->prepare('INSERT INTO neptune_scout_reliability (organization_id,event_id,scout_key,user_id,grade,weight,bias_points,relative_bias,alliances,computed_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
        foreach ($report['scouts'] as $s) {
            $ins->execute([$org, $eventId, $s['key'], $s['user_id'], $s['grade'], $s['weight'], $s['bias_points'], $s['relative_bias'], $s['alliances']]);
        }
    } catch (Throwable $e) { error_log('[Neptune scout accuracy] ' . $e->getMessage()); }
}

/** scout_key => weight for an event (empty when never computed). */
function neptune_scout_weights(PDO $pdo, int $org, int $eventId): array {
    try {
        $q = $pdo->prepare('SELECT scout_key, weight FROM neptune_scout_reliability WHERE organization_id=? AND event_id=?');
        $q->execute([$org, $eventId]);
        return array_map('floatval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
    } catch (Throwable $e) { return []; } // table appears the first time Data Quality computes accuracy
}
