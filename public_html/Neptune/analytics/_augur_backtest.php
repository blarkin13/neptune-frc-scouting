<?php
/**
 * AUGUR prediction backtesting: how often were AUGUR's pre-match predictions right?
 *
 * For every finished match, re-run the shared AUGUR model exactly as Match Strategy does,
 * with cutoff_match set to that match (only scouting, records and EPA snapshots from before
 * it) and the event's saved prediction settings, then compare with the official result.
 *
 * Compared against an EPA-only baseline (higher pre-match EPA sum wins), so the report shows
 * whether Neptune scouting adds anything beyond public ratings.
 *
 * Results are cached per organization and event. The cache key covers the model version,
 * the settings, and a fingerprint of matches and scouting, so it refreshes on its own.
 */
declare(strict_types=1);
require_once __DIR__ . '/_augur_prediction_model.php';
require_once __DIR__ . '/_alliance_helpers.php';

const AUGUR_BACKTEST_VERSION = 'backtest-3';

function augur_backtest_install(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS augur_prediction_backtests (
        organization_id BIGINT UNSIGNED NOT NULL,
        event_id BIGINT UNSIGNED NOT NULL,
        cache_key CHAR(64) NOT NULL,
        computed_at DATETIME NOT NULL,
        result_json LONGTEXT NOT NULL,
        PRIMARY KEY (organization_id, event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/** Same settings Match Strategy uses: the event's Alliance Selection workspace, else defaults. */
function augur_backtest_settings(PDO $pdo, int $org, int $eventId): array {
    $settings = augur_prediction_settings();
    try {
        if (alliance_schema_ready($pdo)) {
            $ws = alliance_load_workspace($pdo, $org, $eventId, false);
            $settings = augur_prediction_settings($ws['workspace']['settings']['matchup_model'] ?? []);
        }
    } catch (Throwable $e) { /* defaults */ }
    return $settings;
}

/** Finished matches with an official result and robots on both alliances. */
function augur_backtest_matches(PDO $pdo, int $org, int $eventId): array {
    $q = $pdo->prepare("SELECT * FROM matches WHERE organization_id=? AND event_id=? AND red_score IS NOT NULL AND blue_score IS NOT NULL
        ORDER BY FIELD(comp_level,'qm','ef','qf','sf','f'), set_number, match_number, id");
    $q->execute([$org, $eventId]);
    $out = [];
    $t = $pdo->prepare('SELECT frc_team_number, alliance FROM match_teams WHERE match_id=? ORDER BY alliance, station');
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $t->execute([(int)$m['id']]);
        $red = []; $blue = [];
        foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $r) { if ($r['alliance'] === 'Red') $red[] = (int)$r['frc_team_number']; elseif ($r['alliance'] === 'Blue') $blue[] = (int)$r['frc_team_number']; }
        if ($red && $blue) $out[] = $m + ['red_teams' => $red, 'blue_teams' => $blue];
    }
    return $out;
}

function augur_backtest_cache_key(PDO $pdo, int $org, int $eventId, array $settings): string {
    $q = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(red_score+blue_score),0) s, COALESCE(MAX(id),0) m FROM matches WHERE organization_id=? AND event_id=? AND red_score IS NOT NULL AND blue_score IS NOT NULL");
    $q->execute([$org, $eventId]); $mf = $q->fetch(PDO::FETCH_ASSOC);
    $q = $pdo->prepare("SELECT COUNT(*) n, COALESCE(MAX(id),0) m, COALESCE(SUM(deleted_at IS NOT NULL),0) d FROM scouting_actions WHERE organization_id=? AND event_id=?");
    $q->execute([$org, $eventId]); $af = $q->fetch(PDO::FETCH_ASSOC);
    return hash('sha256', json_encode([AUGUR_BACKTEST_VERSION, $settings, $mf, $af]));
}

/** Penalty-free official score per match/alliance when the EPA archive has it (fairer for score error). */
function augur_backtest_modeled_scores(PDO $pdo, array $event): array {
    $key = trim((string)($event['tba_event_key'] ?? ''));
    if ($key === '') return [];
    try {
        $q = $pdo->prepare('SELECT tba_match_key, alliance, modeled_score FROM augur_epa_alliance_samples WHERE tba_event_key=?');
        $q->execute([$key]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['tba_match_key'] . '|' . $r['alliance']] = (float)$r['modeled_score'];
        return $out;
    } catch (Throwable $e) { return []; }
}

/** EPA-only baseline: sum of each alliance's pre-match EPA; probability from the EPA uncertainties. */
function augur_backtest_epa_baseline(PDO $pdo, int $org, array $event, array $match): ?array {
    if (!function_exists('augur_epa_rating_map')) return null;
    $map = augur_epa_rating_map($pdo, $org, $event, array_merge($match['red_teams'], $match['blue_teams']), $match);
    $sum = static function (array $teams) use ($map): ?array {
        $e = 0.0; $v = 0.0; $allSigma = true;
        foreach ($teams as $t) {
            if (!isset($map[$t])) return null;
            $e += (float)$map[$t]['epa'];
            if ($map[$t]['sigma'] === null) $allSigma = false; else $v += (float)$map[$t]['sigma'] ** 2;
        }
        return ['epa' => $e, 'var' => $allSigma ? $v : null];
    };
    $r = $sum($match['red_teams']); $b = $sum($match['blue_teams']);
    if (!$r || !$b) return null;
    $p = null;
    if ($r['var'] !== null && $b['var'] !== null && ($r['var'] + $b['var']) > 0) {
        $p = augur_prediction_normal_cdf(($r['epa'] - $b['epa']) / sqrt($r['var'] + $b['var']));
    }
    return ['red_epa' => round($r['epa'], 1), 'blue_epa' => round($b['epa'], 1), 'p_red' => $p];
}

/** Run the backtest for one event (no cache). */
function augur_backtest_compute(PDO $pdo, int $org, int $eventId): array {
    $event = alliance_event($pdo, $org, $eventId);
    if (!$event) return ['available' => false, 'reason' => 'Event not found for this organization.'];
    $settings = augur_backtest_settings($pdo, $org, $eventId);
    $modeled = augur_backtest_modeled_scores($pdo, $event);
    $rows = []; $failed = 0;
    $matches = augur_backtest_matches($pdo, $org, $eventId);
    $quals = array_values(array_filter($matches, static fn($m) => $m['comp_level'] === 'qm'));
    $qualCount = count($quals);
    $qualIndex = [];
    foreach ($quals as $i => $m) $qualIndex[(int)$m['id']] = $i;
    foreach ($matches as $m) {
        try {
            $p = augur_prediction_run($pdo, $org, $event, $m['red_teams'], $m['blue_teams'], $settings,
                ['cutoff_match' => $m, 'use_tba_rankings' => false, 'use_epa' => true, 'side_a_number' => 'Red', 'side_b_number' => 'Blue']);
        } catch (Throwable $e) { $failed++; continue; }
        $redScore = (int)$m['red_score']; $blueScore = (int)$m['blue_score'];
        $mk = (string)($m['tba_match_key'] ?? '');
        $redCmp = $modeled[$mk . '|Red'] ?? (float)$redScore; $blueCmp = $modeled[$mk . '|Blue'] ?? (float)$blueScore;
        $pRed = (float)$p['a']['win_probability'] / 100;
        $epa = null;
        try { $epa = augur_backtest_epa_baseline($pdo, $org, $event, $m); } catch (Throwable $e) {}
        $stage = 'playoff';
        if ($m['comp_level'] === 'qm') {
            $pos = $qualCount > 0 ? ($qualIndex[(int)$m['id']] ?? 0) / $qualCount : 0;
            $stage = $pos < 1 / 3 ? 'early' : ($pos < 2 / 3 ? 'middle' : 'late');
        }
        $rows[] = [
            'match_id' => (int)$m['id'], 'label' => neptune_match_label($m), 'comp_level' => $m['comp_level'], 'stage' => $stage,
            'red' => $m['red_teams'], 'blue' => $m['blue_teams'],
            'p_red' => round($pRed, 4), 'confidence' => (float)$p['confidence'],
            'pred_red' => round((float)$p['a']['predicted_score'], 1), 'pred_blue' => round((float)$p['b']['predicted_score'], 1),
            'red_low' => round((float)($p['a']['low'] ?? 0), 1), 'red_high' => round((float)($p['a']['high'] ?? 0), 1),
            'blue_low' => round((float)($p['b']['low'] ?? 0), 1), 'blue_high' => round((float)($p['b']['high'] ?? 0), 1),
            'red_score' => $redScore, 'blue_score' => $blueScore, 'red_cmp' => round($redCmp, 1), 'blue_cmp' => round($blueCmp, 1),
            'epa' => $epa,
        ];
    }
    return augur_backtest_summarize($rows, $failed, $settings) + ['event_id' => $eventId, 'event_name' => (string)($event['name'] ?? ''),
        'event_key' => (string)($event['tba_event_key'] ?? ''), 'computed_at' => gmdate(DATE_ATOM)];
}

/** Metrics from per-match rows. Ties are excluded from winner metrics. */
function augur_backtest_summarize(array $rows, int $failed, array $settings): array {
    if (!$rows) return ['available' => false, 'reason' => 'No finished matches with official scores could be predicted for this event yet.', 'failed' => $failed];
    $n = 0; $right = 0; $brier = 0.0; $logLoss = 0.0; $ties = 0;
    $buckets = []; $stages = []; $scoreErr = []; $scoreBias = []; $inRange = 0; $rangeN = 0;
    $epaN = 0; $epaRight = 0; $epaSameAugur = 0; $epaBrier = 0.0; $epaBrierN = 0; $disagree = ['n' => 0, 'augur' => 0, 'epa' => 0];
    foreach ($rows as &$r) {
        foreach (['red', 'blue'] as $s) {
            $err = $r['pred_' . $s] - $r[$s . '_cmp'];
            $scoreErr[] = abs($err); $scoreBias[] = $err;
            $rangeN++; if ($r[$s . '_cmp'] >= $r[$s . '_low'] && $r[$s . '_cmp'] <= $r[$s . '_high']) $inRange++;
        }
        $outcome = $r['red_score'] <=> $r['blue_score'];
        if ($outcome === 0) { $ties++; $r['result'] = 'tie'; continue; }
        $redWon = $outcome > 0 ? 1 : 0;
        $pickRed = $r['p_red'] >= 0.5;
        $correct = $pickRed === ($redWon === 1);
        $r['result'] = $correct ? 'right' : 'wrong';
        $n++; if ($correct) $right++;
        $brier += ($r['p_red'] - $redWon) ** 2;
        $pc = min(max($redWon ? $r['p_red'] : 1 - $r['p_red'], 0.001), 0.999); $logLoss += -log($pc);
        $fav = max($r['p_red'], 1 - $r['p_red']);
        $b = min(4, (int)floor(($fav - 0.5) * 10));
        $buckets[$b]['n'] = ($buckets[$b]['n'] ?? 0) + 1;
        $buckets[$b]['won'] = ($buckets[$b]['won'] ?? 0) + ($correct ? 1 : 0);
        $buckets[$b]['p'] = ($buckets[$b]['p'] ?? 0) + $fav;
        $stages[$r['stage']]['n'] = ($stages[$r['stage']]['n'] ?? 0) + 1;
        $stages[$r['stage']]['right'] = ($stages[$r['stage']]['right'] ?? 0) + ($correct ? 1 : 0);
        if ($r['epa']) {
            $epaPickRed = $r['epa']['red_epa'] >= $r['epa']['blue_epa'];
            $epaCorrect = $epaPickRed === ($redWon === 1);
            $epaN++; if ($epaCorrect) $epaRight++; if ($correct) $epaSameAugur++;
            if ($r['epa']['p_red'] !== null) { $epaBrier += ($r['epa']['p_red'] - $redWon) ** 2; $epaBrierN++; }
            if ($epaPickRed !== $pickRed) { $disagree['n']++; if ($correct) $disagree['augur']++; else $disagree['epa']++; }
        }
    }
    unset($r);
    ksort($buckets);
    $cal = [];
    foreach ($buckets as $b => $v) $cal[] = ['from' => 50 + $b * 10, 'to' => 60 + $b * 10, 'n' => $v['n'], 'avg_predicted' => round($v['p'] / $v['n'] * 100, 1), 'favorite_won' => round($v['won'] / $v['n'] * 100, 1)];
    $stageOut = [];
    foreach (['early', 'middle', 'late', 'playoff'] as $s) if (!empty($stages[$s]['n'])) $stageOut[$s] = ['n' => $stages[$s]['n'], 'accuracy' => round($stages[$s]['right'] / $stages[$s]['n'] * 100, 1)];
    $mean = static fn(array $a) => $a ? array_sum($a) / count($a) : null;
    return [
        'available' => true, 'version' => AUGUR_BACKTEST_VERSION, 'settings' => $settings,
        'matches' => count($rows), 'decided' => $n, 'ties' => $ties, 'failed' => $failed,
        'accuracy' => $n ? round($right / $n * 100, 1) : null,
        'brier' => $n ? round($brier / $n, 4) : null, 'log_loss' => $n ? round($logLoss / $n, 4) : null,
        'score_mae' => $scoreErr ? round($mean($scoreErr), 1) : null, 'score_bias' => $scoreBias ? round($mean($scoreBias), 1) : null,
        'in_range_pct' => $rangeN ? round($inRange / $rangeN * 100, 1) : null,
        'calibration' => $cal, 'by_stage' => $stageOut,
        'epa_baseline' => ['n' => $epaN, 'accuracy' => $epaN ? round($epaRight / $epaN * 100, 1) : null,
            'augur_same_matches' => $epaN ? round($epaSameAugur / $epaN * 100, 1) : null,
            'brier' => $epaBrierN ? round($epaBrier / $epaBrierN, 4) : null, 'disagreements' => $disagree],
        'rows' => $rows,
    ];
}

/** Cached backtest for one event; recomputes when matches, scouting or settings changed. */
function augur_backtest_event(PDO $pdo, int $org, int $eventId, bool $force = false): array {
    $settings = augur_backtest_settings($pdo, $org, $eventId);
    $key = augur_backtest_cache_key($pdo, $org, $eventId, $settings);
    try {
        augur_backtest_install($pdo);
        if (!$force) {
            $q = $pdo->prepare('SELECT cache_key, result_json FROM augur_prediction_backtests WHERE organization_id=? AND event_id=?');
            $q->execute([$org, $eventId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row && hash_equals((string)$row['cache_key'], $key)) {
                $cached = json_decode((string)$row['result_json'], true);
                if (is_array($cached)) return $cached + ['from_cache' => true];
            }
        }
    } catch (Throwable $e) { error_log('[AUGUR backtest cache] ' . $e->getMessage()); }
    $result = augur_backtest_compute($pdo, $org, $eventId);
    try {
        $pdo->prepare('INSERT INTO augur_prediction_backtests (organization_id,event_id,cache_key,computed_at,result_json) VALUES (?,?,?,UTC_TIMESTAMP(),?)
            ON DUPLICATE KEY UPDATE cache_key=VALUES(cache_key),computed_at=VALUES(computed_at),result_json=VALUES(result_json)')
            ->execute([$org, $eventId, $key, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { error_log('[AUGUR backtest cache] ' . $e->getMessage()); }
    return $result + ['from_cache' => false];
}

/** Calibration bucket a favorite probability (0.5–1) falls in, or null. */
function augur_backtest_bucket_for(array $result, float $favoriteProbability): ?array {
    foreach (($result['calibration'] ?? []) as $b) {
        if ($favoriteProbability * 100 >= $b['from'] && ($favoriteProbability * 100 < $b['to'] || $b['to'] >= 100)) return $b;
    }
    return null;
}
