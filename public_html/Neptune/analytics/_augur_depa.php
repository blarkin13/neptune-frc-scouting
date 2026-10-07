<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/scout/_tag_schema.php';

/**
 * AUGUR Neptune D-EPA network v6.
 *
 * Raw match D-EPA = projected opponent score from pre-match Public EPA
 *                    - actual modeled opponent score.
 *
 * Neptune D-EPA = arithmetic mean of Raw match D-EPA for canonical Verified
 * defense observations. Scouting from every Neptune organization is eligible.
 *
 * Cross-organization deduplication is by TBA match key + FRC team number.
 * Each organization first contributes one observation for that robot/match.
 * When multiple organizations scout the same robot/match, their observations
 * are merged into one canonical record. Defense-action counts use the maximum
 * independently observed per-organization count, never the sum, so overlapping
 * scouting cannot multiply evidence. Organization identities are never copied
 * into the public canonical tables.
 */
const AUGUR_DEPA_MODEL_VERSION = 'neptune-depa-v6.2-network-attempts';
const AUGUR_DEPA_RIDGE_LAMBDA = 2.5; // legacy helper retained, not used by v5 ratings
const AUGUR_DEPA_MIN_VERIFIED_ACTIONS = 2;
const AUGUR_DEPA_MIN_RATED_DEFENSE_MATCHES = 2;

require_once __DIR__ . '/_augur_opr.php';

function augur_depa_install_tables(PDO $pdo): void {
    tag_unified_ensure_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS augur_depa_match_samples (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tba_event_key VARCHAR(40) NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        tba_match_key VARCHAR(64) NOT NULL,
        comp_level VARCHAR(8) NOT NULL DEFAULT 'qm',
        match_number SMALLINT UNSIGNED NOT NULL,
        defending_alliance ENUM('Red','Blue') NOT NULL,
        team1 INT UNSIGNED NOT NULL,
        team2 INT UNSIGNED NOT NULL,
        team3 INT UNSIGNED NOT NULL,
        opponent_team1 INT UNSIGNED NOT NULL,
        opponent_team2 INT UNSIGNED NOT NULL,
        opponent_team3 INT UNSIGNED NOT NULL,
        expected_defending_score DECIMAL(12,4) NOT NULL DEFAULT 0,
        expected_defending_source VARCHAR(32) NOT NULL DEFAULT 'unknown',
        expected_opponent_score DECIMAL(12,4) NOT NULL,
        expected_opponent_source VARCHAR(32) NOT NULL DEFAULT 'unknown',
        actual_opponent_score DECIMAL(12,4) NOT NULL,
        raw_suppression DECIMAL(12,4) NOT NULL,
        model_version VARCHAR(80) NOT NULL,
        calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_augur_depa_match_alliance (tba_match_key,defending_alliance),
        KEY idx_augur_depa_sample_year_event (season_year,tba_event_key),
        KEY idx_augur_depa_sample_event_match (tba_event_key,match_number,defending_alliance),
        CONSTRAINT fk_augur_depa_sample_event FOREIGN KEY (tba_event_key)
          REFERENCES augur_epa_archive_events(tba_event_key) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS augur_depa_public_event_ratings (
        tba_event_key VARCHAR(40) NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        frc_team_number INT UNSIGNED NOT NULL,
        macro_depa DECIMAL(12,6) NOT NULL DEFAULT 0,
        matches_played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        alliance_rows SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        baseline_source VARCHAR(40) NOT NULL DEFAULT 'public_epa',
        neptune_depa DECIMAL(12,6) NULL,
        verified_defense_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        possible_defense_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        observed_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        defense_actions INT UNSIGNED NOT NULL DEFAULT 0,
        defense_failures INT UNSIGNED NOT NULL DEFAULT 0,
        defense_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        spot_great_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        spot_weak_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        contributing_orgs SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        confidence_score DECIMAL(6,2) NOT NULL DEFAULT 0,
        confidence_label ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low',
        validation_label VARCHAR(32) NOT NULL DEFAULT 'No defense observed',
        source_label VARCHAR(100) NOT NULL DEFAULT 'public baseline only',
        model_version VARCHAR(80) NOT NULL,
        calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (tba_event_key,frc_team_number),
        KEY idx_augur_depa_public_year_team (season_year,frc_team_number),
        KEY idx_augur_depa_public_year_rating (season_year,macro_depa),
        KEY idx_augur_depa_public_neptune (season_year,neptune_depa),
        CONSTRAINT fk_augur_depa_public_event FOREIGN KEY (tba_event_key)
          REFERENCES augur_epa_archive_events(tba_event_key) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS augur_depa_network_match_evidence (
        tba_match_key VARCHAR(64) NOT NULL,
        tba_event_key VARCHAR(40) NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        match_number SMALLINT UNSIGNED NOT NULL,
        frc_team_number INT UNSIGNED NOT NULL,
        alliance ENUM('Red','Blue') NOT NULL,
        raw_depa DECIMAL(12,4) NOT NULL,
        expected_alliance_score DECIMAL(12,4) NULL,
        expected_opponent_score DECIMAL(12,4) NULL,
        actual_opponent_score DECIMAL(12,4) NULL,
        defense_status VARCHAR(16) NOT NULL DEFAULT 'None',
        defense_actions SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        defense_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        defense_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        spot_great_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        spot_weak_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        observing_orgs SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        verified_orgs SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        possible_orgs SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        model_version VARCHAR(80) NOT NULL,
        calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (tba_match_key,frc_team_number),
        KEY idx_augur_depa_network_event_team (tba_event_key,frc_team_number),
        KEY idx_augur_depa_network_year_team (season_year,frc_team_number),
        KEY idx_augur_depa_network_status (season_year,defense_status),
        CONSTRAINT fk_augur_depa_network_event FOREIGN KEY (tba_event_key)
          REFERENCES augur_epa_archive_events(tba_event_key) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS augur_depa_public_season_ratings (
        season_year SMALLINT UNSIGNED NOT NULL,
        frc_team_number INT UNSIGNED NOT NULL,
        public_depa DECIMAL(12,6) NULL,
        public_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        neptune_depa DECIMAL(12,6) NULL,
        verified_defense_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        possible_defense_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        observed_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        defense_actions INT UNSIGNED NOT NULL DEFAULT 0,
        defense_failures INT UNSIGNED NOT NULL DEFAULT 0,
        defense_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        spot_great_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        spot_weak_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        contributing_orgs SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        events_observed SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        rated_events SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        confidence_score DECIMAL(6,2) NOT NULL DEFAULT 0,
        confidence_label ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low',
        validation_label VARCHAR(32) NOT NULL DEFAULT 'No defense observed',
        source_label VARCHAR(100) NOT NULL DEFAULT 'network scouting',
        last_event_key VARCHAR(40) NULL,
        model_version VARCHAR(80) NOT NULL,
        calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (season_year,frc_team_number),
        KEY idx_augur_depa_season_rating (season_year,neptune_depa),
        CONSTRAINT fk_augur_depa_season_last_event FOREIGN KEY (last_event_key)
          REFERENCES augur_epa_archive_events(tba_event_key) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS augur_depa_team_match_evidence (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organization_id BIGINT UNSIGNED NOT NULL,
        event_id BIGINT UNSIGNED NOT NULL,
        match_id BIGINT UNSIGNED NOT NULL,
        tba_event_key VARCHAR(40) NOT NULL,
        tba_match_key VARCHAR(64) NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        frc_team_number INT UNSIGNED NOT NULL,
        alliance ENUM('Red','Blue') NOT NULL,
        raw_alliance_suppression DECIMAL(12,4) NOT NULL DEFAULT 0,
        expected_alliance_score DECIMAL(12,4) NULL,
        expected_opponent_score DECIMAL(12,4) NULL,
        actual_opponent_score DECIMAL(12,4) NULL,
        defense_actions SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        spot_great_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        spot_weak_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        evidence_score DECIMAL(10,4) NOT NULL DEFAULT 0,
        defense_status VARCHAR(16) NOT NULL DEFAULT 'None',
        baseline_suppression DECIMAL(12,4) NULL,
        adjusted_suppression DECIMAL(12,4) NULL,
        context_control_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        context_source VARCHAR(32) NOT NULL DEFAULT 'none',
        context_distance_avg DECIMAL(12,4) NULL,
        attribution_weight DECIMAL(10,6) NOT NULL DEFAULT 0,
        credited_suppression DECIMAL(12,4) NULL,
        model_version VARCHAR(80) NOT NULL,
        calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_augur_depa_org_match_team (organization_id,match_id,frc_team_number),
        KEY idx_augur_depa_evidence_org_event_team (organization_id,event_id,frc_team_number),
        KEY idx_augur_depa_evidence_tba (tba_match_key,frc_team_number),
        CONSTRAINT fk_augur_depa_evidence_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_augur_depa_evidence_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
        CONSTRAINT fk_augur_depa_evidence_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS augur_depa_context_controls (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organization_id BIGINT UNSIGNED NOT NULL,
        event_id BIGINT UNSIGNED NOT NULL,
        target_match_id BIGINT UNSIGNED NOT NULL,
        target_tba_match_key VARCHAR(64) NOT NULL,
        target_match_number SMALLINT UNSIGNED NOT NULL,
        target_frc_team_number INT UNSIGNED NOT NULL,
        control_match_id BIGINT UNSIGNED NOT NULL,
        control_tba_match_key VARCHAR(64) NOT NULL,
        control_match_number SMALLINT UNSIGNED NOT NULL,
        control_alliance ENUM('Red','Blue') NOT NULL,
        control_contains_target_team TINYINT(1) NOT NULL DEFAULT 0,
        target_expected_alliance_score DECIMAL(12,4) NOT NULL,
        target_expected_opponent_score DECIMAL(12,4) NOT NULL,
        control_expected_alliance_score DECIMAL(12,4) NOT NULL,
        control_expected_opponent_score DECIMAL(12,4) NOT NULL,
        control_raw_suppression DECIMAL(12,4) NOT NULL,
        distance_score DECIMAL(12,4) NOT NULL,
        rank_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        model_version VARCHAR(80) NOT NULL,
        calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_augur_depa_context_control (organization_id,event_id,target_match_id,target_frc_team_number,control_match_id,control_alliance),
        KEY idx_augur_depa_context_target (organization_id,event_id,target_frc_team_number,target_match_id),
        KEY idx_augur_depa_context_control_match (organization_id,event_id,control_match_id),
        CONSTRAINT fk_augur_depa_context_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_augur_depa_context_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
        CONSTRAINT fk_augur_depa_context_target_match FOREIGN KEY (target_match_id) REFERENCES matches(id) ON DELETE CASCADE,
        CONSTRAINT fk_augur_depa_context_control_match FOREIGN KEY (control_match_id) REFERENCES matches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS augur_depa_event_ratings (
        organization_id BIGINT UNSIGNED NOT NULL,
        event_id BIGINT UNSIGNED NOT NULL,
        tba_event_key VARCHAR(40) NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        frc_team_number INT UNSIGNED NOT NULL,
        public_depa DECIMAL(12,6) NULL,
        defense_depa DECIMAL(12,6) NULL,
        nondefense_suppression DECIMAL(12,6) NULL,
        possible_defense_suppression DECIMAL(12,6) NULL,
        event_has_scouting TINYINT(1) NOT NULL DEFAULT 0,
        nondefense_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        macro_depa DECIMAL(12,6) NULL,
        scouted_depa DECIMAL(12,6) NULL,
        neptune_depa DECIMAL(12,6) NULL,
        matches_played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        verified_defense_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        possible_defense_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        baseline_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        baseline_suppression DECIMAL(12,6) NULL,
        defense_match_suppression DECIMAL(12,6) NULL,
        baseline_source VARCHAR(32) NOT NULL DEFAULT 'none',
        context_control_count INT UNSIGNED NOT NULL DEFAULT 0,
        blend_pre_shrink DECIMAL(12,6) NULL,
        reliability_factor DECIMAL(8,6) NOT NULL DEFAULT 0,
        defense_actions INT UNSIGNED NOT NULL DEFAULT 0,
        spot_great_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        spot_weak_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        role_confidence_score DECIMAL(6,2) NOT NULL DEFAULT 0,
        role_confidence_label ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low',
        estimate_confidence_score DECIMAL(6,2) NOT NULL DEFAULT 0,
        estimate_confidence_label ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low',
        validation_label VARCHAR(32) NOT NULL DEFAULT 'No defense observed',
        confidence_score DECIMAL(6,2) NOT NULL DEFAULT 0,
        confidence_label ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low',
        agreement_score DECIMAL(6,2) NULL,
        source_label VARCHAR(80) NOT NULL DEFAULT 'macro only',
        model_version VARCHAR(80) NOT NULL,
        calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (organization_id,event_id,frc_team_number),
        KEY idx_augur_depa_org_event_rating (organization_id,event_id,neptune_depa),
        KEY idx_augur_depa_org_year_team (organization_id,season_year,frc_team_number),
        CONSTRAINT fk_augur_depa_rating_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_augur_depa_rating_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // In-place schema upgrades. Check information_schema explicitly for
    // MySQL/MariaDB portability and fail loudly if an ALTER cannot be applied.
    $columnCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS " .
        "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $addColumns = static function(string $table, array $columns) use ($pdo, $columnCheck): void {
        foreach ($columns as $column => $definition) {
            $columnCheck->execute([$table, $column]);
            if ((int)$columnCheck->fetchColumn() > 0) continue;
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    };

    $addColumns('augur_depa_match_samples', [
        'expected_defending_score' => "DECIMAL(12,4) NOT NULL DEFAULT 0 AFTER opponent_team3",
        'expected_defending_source' => "VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER expected_defending_score",
        'expected_opponent_source' => "VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER expected_opponent_score",
    ]);
    $addColumns('augur_depa_public_event_ratings', [
        'neptune_depa' => "DECIMAL(12,6) NULL AFTER baseline_source",
        'verified_defense_matches' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER neptune_depa",
        'possible_defense_matches' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER verified_defense_matches",
        'observed_matches' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER possible_defense_matches",
        'defense_actions' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER observed_matches",
        'defense_failures' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER defense_actions",
        'defense_attempts' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER defense_failures",
        'spot_great_defense' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER defense_actions",
        'spot_weak_defense' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER spot_great_defense",
        'contributing_orgs' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER spot_weak_defense",
        'confidence_score' => "DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER contributing_orgs",
        'confidence_label' => "ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low' AFTER confidence_score",
        'validation_label' => "VARCHAR(32) NOT NULL DEFAULT 'No defense observed' AFTER confidence_label",
        'source_label' => "VARCHAR(100) NOT NULL DEFAULT 'public baseline only' AFTER validation_label",
    ]);
    $addColumns('augur_depa_public_season_ratings', [
        'public_depa' => "DECIMAL(12,6) NULL AFTER frc_team_number",
        'public_matches' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER public_depa",
        'defense_failures' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER defense_actions",
        'defense_attempts' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER defense_failures",
    ]);
    $addColumns('augur_depa_network_match_evidence', [
        'defense_failures' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER defense_actions",
        'defense_attempts' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER defense_failures",
    ]);

$addColumns('augur_depa_event_ratings', [
        'public_depa' => "DECIMAL(12,6) NULL AFTER frc_team_number",
        'defense_depa' => "DECIMAL(12,6) NULL AFTER public_depa",
        'nondefense_suppression' => "DECIMAL(12,6) NULL AFTER defense_depa",
        'possible_defense_suppression' => "DECIMAL(12,6) NULL AFTER nondefense_suppression",
        'event_has_scouting' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER possible_defense_suppression",
        'nondefense_matches' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER event_has_scouting",
        'role_confidence_score' => "DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER spot_weak_defense",
        'role_confidence_label' => "ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low' AFTER role_confidence_score",
        'estimate_confidence_score' => "DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER role_confidence_label",
        'estimate_confidence_label' => "ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low' AFTER estimate_confidence_score",
        'validation_label' => "VARCHAR(32) NOT NULL DEFAULT 'No defense observed' AFTER estimate_confidence_label",
        'possible_defense_matches' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER verified_defense_matches",
        'baseline_matches' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER possible_defense_matches",
        'baseline_suppression' => "DECIMAL(12,6) NULL AFTER baseline_matches",
        'defense_match_suppression' => "DECIMAL(12,6) NULL AFTER baseline_suppression",
        'baseline_source' => "VARCHAR(32) NOT NULL DEFAULT 'none' AFTER defense_match_suppression",
        'context_control_count' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER baseline_source",
        'blend_pre_shrink' => "DECIMAL(12,6) NULL AFTER context_control_count",
        'reliability_factor' => "DECIMAL(8,6) NOT NULL DEFAULT 0 AFTER blend_pre_shrink",
    ]);
    $addColumns('augur_depa_team_match_evidence', [
        'defense_status' => "VARCHAR(16) NOT NULL DEFAULT 'None' AFTER evidence_score",
        'baseline_suppression' => "DECIMAL(12,4) NULL AFTER defense_status",
        'adjusted_suppression' => "DECIMAL(12,4) NULL AFTER baseline_suppression",
        'expected_alliance_score' => "DECIMAL(12,4) NULL AFTER raw_alliance_suppression",
        'expected_opponent_score' => "DECIMAL(12,4) NULL AFTER expected_alliance_score",
        'actual_opponent_score' => "DECIMAL(12,4) NULL AFTER expected_opponent_score",
        'context_control_count' => "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER adjusted_suppression",
        'context_source' => "VARCHAR(32) NOT NULL DEFAULT 'none' AFTER context_control_count",
        'context_distance_avg' => "DECIMAL(12,4) NULL AFTER context_source",
    ]);

}

function augur_depa_tables_ready(PDO $pdo): bool {
    $names = [
        'augur_depa_match_samples',
        'augur_depa_public_event_ratings',
        'augur_depa_network_match_evidence',
        'augur_depa_public_season_ratings',
    ];
    $ph = implode(',', array_fill(0, count($names), '?'));
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($ph)");
    $s->execute($names);
    return ((int)$s->fetchColumn() === count($names));
}

function augur_depa_clamp(float $v, float $lo, float $hi): float {
    return max($lo, min($hi, $v));
}

function augur_depa_label(float $score): string {
    if ($score >= 70.0) return 'High';
    if ($score >= 45.0) return 'Medium';
    return 'Low';
}

/** Robust center for small event samples. Median deliberately resists one wild match. */
function augur_depa_median(array $values): ?float {
    $x = [];
    foreach ($values as $v) if (is_numeric($v)) $x[] = (float)$v;
    if (!$x) return null;
    sort($x, SORT_NUMERIC);
    $n = count($x);
    $m = intdiv($n, 2);
    return ($n % 2) ? $x[$m] : (($x[$m-1] + $x[$m]) / 2.0);
}

/** Arithmetic mean. v5 uses this as the event D-EPA because it is literally
 * expected opponent points minus actual opponent points per match. */
function augur_depa_mean(array $values): ?float {
    $x = [];
    foreach ($values as $v) if (is_numeric($v)) $x[] = (float)$v;
    if (!$x) return null;
    return array_sum($x) / count($x);
}


/** Build one alliance's pre-match expected score with safe historical fallbacks. */
function augur_depa_expected_alliance_score(
    string $eventKey,
    string $matchKey,
    array $teams,
    array $preRatings,
    array $fallback,
    array $fallbackSource
): array {
    $score = 0.0;
    $sources = [];
    foreach ($teams as $team) {
        $team = (int)$team;
        if (isset($preRatings[$matchKey][$team])) {
            $score += (float)$preRatings[$matchKey][$team];
            $sources[] = 'pre_match_epa';
            continue;
        }
        if (isset($fallback[$eventKey][$team])) {
            $score += (float)$fallback[$eventKey][$team];
            $sources[] = (string)($fallbackSource[$eventKey][$team] ?? 'event_epa');
            continue;
        }
        return ['score'=>null,'source'=>'missing'];
    }
    $unique = array_values(array_unique($sources));
    if ($unique === ['pre_match_epa']) $src = 'pre_match_epa';
    elseif (count($unique) === 1) $src = (string)$unique[0];
    elseif (in_array('pre_match_epa',$unique,true)) $src = 'mixed_pre_match';
    else $src = 'mixed_fallback';
    return ['score'=>$score,'source'=>$src];
}

/**
 * Defense status is intentionally conservative.
 * - 0 actions/tags: None
 * - exactly 1 defense attempt (Success or Failure) with no Tag confirmation: Possible
 * - 2+ defense attempts OR an independent Tag defense tag: Verified
 */
function augur_depa_defense_status(int $successes, int $spotGreat, int $spotWeak, int $failures=0): string {
    $attempts=max(0,$successes)+max(0,$failures);
    // Role evidence and effectiveness are intentionally separate. A failed
    // defense attempt still proves the robot was trying to defend; Raw D-EPA
    // determines whether that defense actually suppressed opponent scoring.
    if ($spotGreat > 0 || $spotWeak > 0 || $attempts >= AUGUR_DEPA_MIN_VERIFIED_ACTIONS) return 'Verified';
    if ($attempts === 1) return 'Possible';
    return 'None';
}

/**
 * Confidence is descriptive only in v5. It does not alter the point estimate.
 * Repeated independent defense matches matter more than repeated button presses
 * inside one match.
 */
function augur_depa_role_confidence(int $verified, int $possible, int $actions, int $spotGreat, int $spotWeak): float {
    if ($verified <= 0) return $possible > 0 ? min(20.0, 5.0 * $possible) : 0.0;
    $matchFactor = min(1.0, $verified / 5.0);
    $verifiedActions = max(0, $actions - $possible);
    $actionFactor = min(1.0, $verifiedActions / 15.0);
    $spotFactor = min(1.0, ($spotGreat + $spotWeak) / 3.0);
    return augur_depa_clamp(100.0 * (0.70*$matchFactor + 0.20*$actionFactor + 0.10*$spotFactor), 0.0, 100.0);
}

function augur_depa_estimate_confidence(int $verified, int $actions, int $spotGreat, int $spotWeak): float {
    if ($verified <= 0) return 0.0;
    $sampleFactor = min(1.0, $verified / 5.0);
    $actionFactor = min(1.0, $actions / 15.0);
    $spotFactor = min(1.0, ($spotGreat + $spotWeak) / 3.0);
    return augur_depa_clamp(100.0 * (0.75*$sampleFactor + 0.20*$actionFactor + 0.05*$spotFactor), 0.0, 100.0);
}

function augur_depa_validation_label(int $verified, int $possible, ?float $defenseDepa): string {
    if ($verified <= 0) return $possible > 0 ? 'Possible defense' : 'No defense observed';
    if ($verified < AUGUR_DEPA_MIN_RATED_DEFENSE_MATCHES) return 'Provisional';
    if ($defenseDepa === null) return 'No defense score';
    if ($defenseDepa >= 5.0) return 'Effective';
    if ($defenseDepa <= -5.0) return 'Ineffective';
    return 'Mixed';
}

/** Ridge-regularized least squares for the macro residual model. */
function augur_depa_solve_ridge(array $rows, float $ridge = AUGUR_DEPA_RIDGE_LAMBDA): array {
    $clean=[];$teamSet=[];$teamMatches=[];
    foreach($rows as $row){
        $teams=array_values(array_unique(array_filter(array_map('intval',(array)($row['teams']??[])),static fn($n)=>$n>0)));
        $score=$row['score']??null;
        if(count($teams)!==3 || !is_numeric($score)) continue;
        $clean[]=['teams'=>$teams,'score'=>(float)$score];
        foreach($teams as $team){$teamSet[$team]=true;$teamMatches[$team]=($teamMatches[$team]??0)+1;}
    }
    if(!$clean || count($teamSet)<3) return ['ratings'=>[],'matches'=>[],'alliance_rows'=>0,'ridge'=>$ridge];
    $teams=array_map('intval',array_keys($teamSet));sort($teams,SORT_NUMERIC);
    $index=[];foreach($teams as $i=>$team)$index[$team]=$i;
    $n=count($teams);$ata=array_fill(0,$n,array_fill(0,$n,0.0));$atb=array_fill(0,$n,0.0);
    foreach($clean as $row){
        $idx=[];foreach($row['teams'] as $team)$idx[]=$index[$team];
        foreach($idx as $i){$atb[$i]+=$row['score'];foreach($idx as $j)$ata[$i][$j]+=1.0;}
    }
    for($i=0;$i<$n;$i++)$ata[$i][$i]+=$ridge;
    for($col=0;$col<$n;$col++){
        $pivot=$col;$pivotAbs=abs((float)$ata[$col][$col]);
        for($r=$col+1;$r<$n;$r++){ $v=abs((float)$ata[$r][$col]); if($v>$pivotAbs){$pivot=$r;$pivotAbs=$v;} }
        if($pivotAbs<1e-10) return ['ratings'=>[],'matches'=>$teamMatches,'alliance_rows'=>count($clean),'ridge'=>$ridge];
        if($pivot!==$col){[$ata[$col],$ata[$pivot]]=[$ata[$pivot],$ata[$col]];[$atb[$col],$atb[$pivot]]=[$atb[$pivot],$atb[$col]];}
        $pv=(float)$ata[$col][$col];
        for($r=$col+1;$r<$n;$r++){
            $factor=(float)$ata[$r][$col]/$pv;if(abs($factor)<1e-16)continue;
            $ata[$r][$col]=0.0;for($c=$col+1;$c<$n;$c++)$ata[$r][$c]-=$factor*(float)$ata[$col][$c];$atb[$r]-=$factor*(float)$atb[$col];
        }
    }
    $x=array_fill(0,$n,0.0);
    for($i=$n-1;$i>=0;$i--){$sum=(float)$atb[$i];for($j=$i+1;$j<$n;$j++)$sum-=(float)$ata[$i][$j]*$x[$j];$d=(float)$ata[$i][$i];if(abs($d)<1e-10)return ['ratings'=>[],'matches'=>$teamMatches,'alliance_rows'=>count($clean),'ridge'=>$ridge];$x[$i]=$sum/$d;}
    $ratings=[];foreach($teams as $i=>$team)$ratings[$team]=(float)$x[$i];
    return ['ratings'=>$ratings,'matches'=>$teamMatches,'alliance_rows'=>count($clean),'ridge'=>$ridge];
}

function augur_depa_team_list(array $row): array {
    $teams = [(int)($row['team1'] ?? 0), (int)($row['team2'] ?? 0), (int)($row['team3'] ?? 0)];
    $teams = array_values(array_unique(array_filter($teams, static fn($n) => $n > 0)));
    return count($teams) === 3 ? $teams : [];
}

/**
 * Rebuild match-level Raw D-EPA samples plus direct all-match Public D-EPA for one season.
 * Expected opponent scoring uses Public EPA event ratings when available and
 * qualification OPR as a fallback. The observed score is modeled_score, which
 * already removes foul/adjustment points from the official total.
 */
function augur_depa_rebuild_public_year(PDO $pdo, int $year): array {
    augur_depa_install_tables($pdo);

    // Event-level values are fallbacks only. v5 prefers the rating that existed
    // immediately before each qualification match.
    $fallback = [];
    $fallbackSource = [];
    try {
        $q = $pdo->prepare('SELECT tba_event_key,frc_team_number,rating FROM augur_epa_event_ratings WHERE season_year=?');
        $q->execute([$year]);
        foreach ($q->fetchAll() as $r) {
            $eventKey=(string)$r['tba_event_key'];$team=(int)$r['frc_team_number'];
            $fallback[$eventKey][$team]=(float)$r['rating'];
            $fallbackSource[$eventKey][$team]='event_epa';
        }
    } catch (Throwable $e) {}
    try {
        $q = $pdo->prepare('SELECT tba_event_key,frc_team_number,event_opr FROM augur_opr_event_ratings WHERE season_year=?');
        $q->execute([$year]);
        foreach ($q->fetchAll() as $r) {
            $eventKey=(string)$r['tba_event_key'];$team=(int)$r['frc_team_number'];
            if(!isset($fallback[$eventKey][$team])){
                $fallback[$eventKey][$team]=(float)$r['event_opr'];
                $fallbackSource[$eventKey][$team]='opr';
            }
        }
    } catch (Throwable $e) {}

    $preRatings=[];
    try {
        $q=$pdo->prepare('SELECT tba_match_key,frc_team_number,pre_rating FROM augur_epa_match_ratings WHERE season_year=?');
        $q->execute([$year]);
        foreach($q->fetchAll() as $r)$preRatings[(string)$r['tba_match_key']][(int)$r['frc_team_number']]=(float)$r['pre_rating'];
    } catch(Throwable $e) {}

    $q=$pdo->prepare("SELECT tba_event_key,season_year,tba_match_key,comp_level,match_number,alliance,
            team1,team2,team3,modeled_score
        FROM augur_epa_alliance_samples
        WHERE season_year=? AND comp_level='qm'
        ORDER BY tba_event_key,match_number,tba_match_key,alliance");
    $q->execute([$year]);

    $matches=[];
    foreach($q->fetchAll() as $r){
        $eventKey=(string)$r['tba_event_key'];$matchKey=(string)$r['tba_match_key'];$color=strtolower((string)$r['alliance']);
        if(!in_array($color,['red','blue'],true))continue;
        $teams=augur_depa_team_list($r);if(count($teams)!==3)continue;
        $matches[$eventKey][$matchKey][$color]=['row'=>$r,'teams'=>$teams,'actual'=>(float)$r['modeled_score']];
    }

    $publicRows=[];$sampleRows=[];$eventSourceCounts=[];
    foreach($matches as $eventKey=>$eventMatches){
        foreach($eventMatches as $matchKey=>$pair){
            if(empty($pair['red'])||empty($pair['blue']))continue;
            foreach(['red','blue'] as $defColor){
                $oppColor=$defColor==='red'?'blue':'red';$def=$pair[$defColor];$opp=$pair[$oppColor];
                $defExpected=augur_depa_expected_alliance_score($eventKey,$matchKey,$def['teams'],$preRatings,$fallback,$fallbackSource);
                $oppExpected=augur_depa_expected_alliance_score($eventKey,$matchKey,$opp['teams'],$preRatings,$fallback,$fallbackSource);
                if($defExpected['score']===null||$oppExpected['score']===null)continue;
                $raw=(float)$oppExpected['score']-(float)$opp['actual'];
                $r=$def['row'];
                $sampleRows[]=[
                    'tba_event_key'=>$eventKey,'season_year'=>$year,'tba_match_key'=>$matchKey,
                    'comp_level'=>(string)$r['comp_level'],'match_number'=>(int)$r['match_number'],
                    'defending_alliance'=>ucfirst($defColor),'teams'=>$def['teams'],'opponent_teams'=>$opp['teams'],
                    'expected_defending'=>(float)$defExpected['score'],'expected_defending_source'=>(string)$defExpected['source'],
                    'expected_opponent'=>(float)$oppExpected['score'],'expected_opponent_source'=>(string)$oppExpected['source'],
                    'actual'=>(float)$opp['actual'],'raw'=>$raw,
                ];
                $publicRows[$eventKey][]=['teams'=>$def['teams'],'score'=>$raw];
                foreach([(string)$defExpected['source'],(string)$oppExpected['source']] as $src)$eventSourceCounts[$eventKey][$src]=($eventSourceCounts[$eventKey][$src]??0)+1;
            }
        }
    }

    $pdo->beginTransaction();
    try{
        $pdo->prepare('DELETE FROM augur_depa_match_samples WHERE season_year=?')->execute([$year]);
        $pdo->prepare('DELETE FROM augur_depa_public_event_ratings WHERE season_year=?')->execute([$year]);
        $insSample=$pdo->prepare("INSERT INTO augur_depa_match_samples
            (tba_event_key,season_year,tba_match_key,comp_level,match_number,defending_alliance,
             team1,team2,team3,opponent_team1,opponent_team2,opponent_team3,
             expected_defending_score,expected_defending_source,expected_opponent_score,expected_opponent_source,
             actual_opponent_score,raw_suppression,model_version,calculated_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
        foreach($sampleRows as $r){$t=$r['teams'];$o=$r['opponent_teams'];$insSample->execute([
            $r['tba_event_key'],$year,$r['tba_match_key'],$r['comp_level'],$r['match_number'],$r['defending_alliance'],
            $t[0],$t[1],$t[2],$o[0],$o[1],$o[2],$r['expected_defending'],$r['expected_defending_source'],
            $r['expected_opponent'],$r['expected_opponent_source'],$r['actual'],$r['raw'],AUGUR_DEPA_MODEL_VERSION,
        ]);}

        $insRating=$pdo->prepare("INSERT INTO augur_depa_public_event_ratings
            (tba_event_key,season_year,frc_team_number,macro_depa,matches_played,alliance_rows,baseline_source,model_version,calculated_at)
            VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
        $ratedEvents=0;$teamRows=0;
        foreach($publicRows as $eventKey=>$rows){
            $byTeam=[];
            foreach($rows as $row){
                foreach((array)$row['teams'] as $team){
                    $team=(int)$team;
                    if($team<=0)continue;
                    $byTeam[$team][]=(float)$row['score'];
                }
            }
            if(!$byTeam)continue;
            $ratedEvents++;
            $counts=$eventSourceCounts[$eventKey]??[];$preCount=(int)(($counts['pre_match_epa']??0)+($counts['mixed_pre_match']??0));
            $otherCount=array_sum($counts)-$preCount;$baselineSource=$preCount>=$otherCount?'pre_match_epa':'fallback';
            $allianceRows=count($rows);
            foreach($byTeam as $team=>$scores){
                $publicDepa=augur_depa_mean($scores);
                if($publicDepa===null)continue;
                $insRating->execute([
                    $eventKey,$year,(int)$team,(float)$publicDepa,count($scores),$allianceRows,$baselineSource,AUGUR_DEPA_MODEL_VERSION,
                ]);$teamRows++;
            }
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    return ['year'=>$year,'events'=>$ratedEvents,'team_rows'=>$teamRows,'match_samples'=>count($sampleRows)];
}

function augur_depa_sample_alliance_for_team(array $eventSamples, string $matchKey, int $team): ?string {
    foreach (['Red','Blue'] as $color) {
        $r=$eventSamples[$matchKey][$color]??null;
        if(!$r) continue;
        if(in_array($team,[(int)$r['team1'],(int)$r['team2'],(int)$r['team3']],true)) return $color;
    }
    return null;
}

/**
 * Build the public Neptune-wide scouting layer.
 *
 * Organization IDs exist only while calculating contributor counts. They are
 * never persisted in augur_depa_network_match_evidence or public ratings.
 */
function augur_depa_rebuild_network_year(PDO $pdo, int $year): array {
    augur_depa_install_tables($pdo);

    $sampleRows=$pdo->prepare("SELECT * FROM augur_depa_match_samples WHERE season_year=? ORDER BY tba_event_key,match_number,defending_alliance");
    $sampleRows->execute([$year]);
    $samples=[];
    foreach($sampleRows->fetchAll() as $r){
        $samples[(string)$r['tba_event_key']][(string)$r['tba_match_key']][(string)$r['defending_alliance']]=$r;
    }

    // One per-organization observation for each TBA match/team. Any nondeleted
    // scouting action means that organization actually observed that robot.
    // Defense successes and failures are counted inside that organization observation only.
    $obs=[];
    $a=$pdo->prepare("SELECT e.organization_id,e.tba_event_key,m.tba_match_key,m.match_number,sa.frc_team_number,
            COUNT(*) observed_actions,
            SUM(CASE WHEN (sa.action_type='defense' OR sa.action_code IN ('defense','plays_defense','block','block_intake'))
                      AND LOWER(TRIM(sa.result))='success' THEN 1 ELSE 0 END) defense_actions,
            SUM(CASE WHEN (sa.action_type='defense' OR sa.action_code IN ('defense','plays_defense','block','block_intake'))
                      AND LOWER(TRIM(sa.result))='failure' THEN 1 ELSE 0 END) defense_failures
        FROM scouting_actions sa
        JOIN events e ON e.id=sa.event_id AND e.organization_id=sa.organization_id
        JOIN games g ON g.id=e.game_id
        JOIN matches m ON m.id=sa.match_id AND m.event_id=e.id AND m.organization_id=e.organization_id
        WHERE g.season_year=? AND e.tba_event_key IS NOT NULL AND e.tba_event_key<>''
          AND m.comp_level='qm' AND m.tba_match_key IS NOT NULL AND m.tba_match_key<>''
          AND sa.deleted_at IS NULL AND sa.match_run_number=m.run_number
        GROUP BY e.organization_id,e.tba_event_key,m.tba_match_key,m.match_number,sa.frc_team_number");
    $a->execute([$year]);
    foreach($a->fetchAll() as $r){
        $eventKey=(string)$r['tba_event_key'];$matchKey=(string)$r['tba_match_key'];$team=(int)$r['frc_team_number'];$org=(int)$r['organization_id'];
        if($team<=0||empty($samples[$eventKey][$matchKey])) continue;
        $key=$eventKey.'|'.$matchKey.'|'.$team.'|'.$org;
        $obs[$key]=[
            'event'=>$eventKey,'match'=>$matchKey,'match_number'=>(int)$r['match_number'],'team'=>$team,'org'=>$org,
            'actions'=>(int)$r['defense_actions'],'failures'=>(int)$r['defense_failures'],'attempts'=>(int)$r['defense_actions']+(int)$r['defense_failures'],'great'=>0,'weak'=>0,'observed'=>((int)$r['observed_actions']>0),
        ];
    }

    // Spot rows also count as an actual observation. A defense tag can verify a
    // match independently, but contributor identity never leaves this function.
    try{
        $sp=$pdo->prepare("SELECT so.organization_id,e.tba_event_key,m.tba_match_key,m.match_number,so.frc_team_number,
                SUM(CASE WHEN st.slug='great-defense' THEN 1 ELSE 0 END) great_count,
                SUM(CASE WHEN st.slug='weak-defense' THEN 1 ELSE 0 END) weak_count,
                COUNT(DISTINCT so.id) spot_rows
            FROM tag_scouting_observations so
            JOIN events e ON e.id=so.event_id AND e.organization_id=so.organization_id
            JOIN games g ON g.id=e.game_id
            JOIN matches m ON m.id=so.match_id AND m.event_id=e.id AND m.organization_id=e.organization_id
            LEFT JOIN tag_scouting_observation_tags sot ON sot.observation_id=so.id
            LEFT JOIN tag_scouting_tags st ON st.id=sot.tag_id
            WHERE g.season_year=? AND e.tba_event_key IS NOT NULL AND e.tba_event_key<>''
              AND m.comp_level='qm' AND m.tba_match_key IS NOT NULL AND m.tba_match_key<>''
            GROUP BY so.organization_id,e.tba_event_key,m.tba_match_key,m.match_number,so.frc_team_number");
        $sp->execute([$year]);
        foreach($sp->fetchAll() as $r){
            $eventKey=(string)$r['tba_event_key'];$matchKey=(string)$r['tba_match_key'];$team=(int)$r['frc_team_number'];$org=(int)$r['organization_id'];
            if($team<=0||empty($samples[$eventKey][$matchKey])) continue;
            $key=$eventKey.'|'.$matchKey.'|'.$team.'|'.$org;
            if(!isset($obs[$key]))$obs[$key]=['event'=>$eventKey,'match'=>$matchKey,'match_number'=>(int)$r['match_number'],'team'=>$team,'org'=>$org,'actions'=>0,'failures'=>0,'attempts'=>0,'great'=>0,'weak'=>0,'observed'=>false];
            $obs[$key]['great']=max((int)$obs[$key]['great'],(int)$r['great_count']);
            $obs[$key]['weak']=max((int)$obs[$key]['weak'],(int)$r['weak_count']);
            if((int)$r['spot_rows']>0)$obs[$key]['observed']=true;
        }
    }catch(Throwable $e){}

    // Canonicalize across organizations by TBA match + FRC team. Counts are
    // merged without summing duplicate cross-organization button presses.
    $canonical=[];
    foreach($obs as $o){
        if(empty($o['observed'])) continue;
        $eventKey=$o['event'];$matchKey=$o['match'];$team=(int)$o['team'];
        $alliance=augur_depa_sample_alliance_for_team($samples[$eventKey],$matchKey,$team);
        if($alliance===null) continue;
        $sample=$samples[$eventKey][$matchKey][$alliance]??null;if(!$sample)continue;
        $status=augur_depa_defense_status((int)$o['actions'],(int)$o['great'],(int)$o['weak'],(int)($o['failures']??0));
        $key=$matchKey.'|'.$team;
        if(!isset($canonical[$key])){
            $canonical[$key]=[
                'event'=>$eventKey,'match'=>$matchKey,'match_number'=>(int)$sample['match_number'],'team'=>$team,'alliance'=>$alliance,
                'raw'=>(float)$sample['raw_suppression'],'own_expected'=>(float)$sample['expected_defending_score'],
                'opp_expected'=>(float)$sample['expected_opponent_score'],'opp_actual'=>(float)$sample['actual_opponent_score'],
                'actions'=>0,'failures'=>0,'attempts'=>0,'great'=>0,'weak'=>0,'orgs'=>[],'verified_orgs'=>0,'possible_orgs'=>0,'status'=>'None',
            ];
        }
        $c=&$canonical[$key];
        $oAttempts=(int)($o['attempts']??((int)$o['actions']+(int)($o['failures']??0)));
        if($oAttempts>(int)$c['attempts'] || ($oAttempts===(int)$c['attempts'] && (int)$o['actions']>(int)$c['actions'])){
            $c['actions']=(int)$o['actions'];
            $c['failures']=(int)($o['failures']??0);
            $c['attempts']=$oAttempts;
        }
        $c['great']=max((int)$c['great'],(int)$o['great']);
        $c['weak']=max((int)$c['weak'],(int)$o['weak']);
        $c['orgs'][(int)$o['org']]=true;
        if($status==='Verified')$c['verified_orgs']++;
        elseif($status==='Possible')$c['possible_orgs']++;
        if($status==='Verified'||($status==='Possible'&&$c['status']==='None'))$c['status']=$status;
        unset($c);
    }

    // Build an all-match D-EPA season baseline from the public event rows.
    // This exists even when no Neptune organization recorded defense actions.
    $publicSeason=[];
    try{
        $q=$pdo->prepare("SELECT tba_event_key,frc_team_number,macro_depa,matches_played
            FROM augur_depa_public_event_ratings
            WHERE season_year=?");
        $q->execute([$year]);
        foreach($q->fetchAll() as $r){
            $team=(int)$r['frc_team_number'];
            $matches=max(0,(int)$r['matches_played']);
            if($team<=0||$matches<=0)continue;
            if(!isset($publicSeason[$team]))$publicSeason[$team]=['weighted_sum'=>0.0,'matches'=>0,'events'=>[]];
            $publicSeason[$team]['weighted_sum']+=(float)$r['macro_depa']*$matches;
            $publicSeason[$team]['matches']+=$matches;
            $publicSeason[$team]['events'][(string)$r['tba_event_key']]=true;
        }
    }catch(Throwable $e){}

    $eventAgg=[];$seasonAgg=[];
    foreach($canonical as $c){
        $event=$c['event'];$team=(int)$c['team'];$status=$c['status'];
        if(!isset($eventAgg[$event][$team]))$eventAgg[$event][$team]=['verified'=>[],'possible'=>0,'observed'=>0,'actions'=>0,'failures'=>0,'attempts'=>0,'great'=>0,'weak'=>0,'orgs'=>[]];
        $x=&$eventAgg[$event][$team];$x['observed']++;$x['actions']+=(int)$c['actions'];$x['failures']+=(int)$c['failures'];$x['attempts']+=(int)$c['attempts'];$x['great']+=(int)$c['great'];$x['weak']+=(int)$c['weak'];
        foreach($c['orgs'] as $oid=>$yes)$x['orgs'][$oid]=true;
        if($status==='Verified')$x['verified'][]=(float)$c['raw'];elseif($status==='Possible')$x['possible']++;
        unset($x);

        if(!isset($seasonAgg[$team]))$seasonAgg[$team]=['verified'=>[],'possible'=>0,'observed'=>0,'actions'=>0,'failures'=>0,'attempts'=>0,'great'=>0,'weak'=>0,'orgs'=>[],'events'=>[],'rated_events'=>[],'last_event'=>null,'last_date'=>''];
        $s=&$seasonAgg[$team];$s['observed']++;$s['actions']+=(int)$c['actions'];$s['failures']+=(int)$c['failures'];$s['attempts']+=(int)$c['attempts'];$s['great']+=(int)$c['great'];$s['weak']+=(int)$c['weak'];$s['events'][$event]=true;
        foreach($c['orgs'] as $oid=>$yes)$s['orgs'][$oid]=true;
        if($status==='Verified'){$s['verified'][]=(float)$c['raw'];$s['rated_events'][$event]=true;}elseif($status==='Possible')$s['possible']++;
        unset($s);
    }

    // Ensure every team with an all-match public D-EPA gets a season row,
    // even if Neptune has no defense observations for that team.
    foreach($publicSeason as $team=>$p){
        if(!isset($seasonAgg[$team]))$seasonAgg[$team]=[
            'verified'=>[],'possible'=>0,'observed'=>0,'actions'=>0,'failures'=>0,'attempts'=>0,'great'=>0,'weak'=>0,
            'orgs'=>[],'events'=>[],'rated_events'=>[],'last_event'=>null,'last_date'=>''
        ];
    }

    // Latest event metadata for season rows.
    $dates=[];
    try{
        $q=$pdo->prepare("SELECT tba_event_key,COALESCE(start_date,end_date,'1900-01-01') d FROM augur_epa_archive_events WHERE season_year=?");$q->execute([$year]);
        foreach($q->fetchAll() as $r)$dates[(string)$r['tba_event_key']]=(string)$r['d'];
    }catch(Throwable $e){}
    foreach($seasonAgg as $team=>&$s){
        $allEvents=$s['events'];
        foreach(($publicSeason[$team]['events']??[]) as $event=>$yes)$allEvents[$event]=true;
        foreach($allEvents as $event=>$yes){
            $d=$dates[$event]??'';
            if($d>=$s['last_date']){$s['last_date']=$d;$s['last_event']=$event;}
        }
    }unset($s);

    $pdo->beginTransaction();
    try{
        $pdo->prepare('DELETE FROM augur_depa_network_match_evidence WHERE season_year=?')->execute([$year]);
        $pdo->prepare('DELETE FROM augur_depa_public_season_ratings WHERE season_year=?')->execute([$year]);
        $pdo->prepare("UPDATE augur_depa_public_event_ratings SET neptune_depa=NULL,verified_defense_matches=0,possible_defense_matches=0,observed_matches=0,defense_actions=0,defense_failures=0,defense_attempts=0,spot_great_defense=0,spot_weak_defense=0,contributing_orgs=0,confidence_score=0,confidence_label='Low',validation_label='No defense observed',source_label='public baseline only',model_version=?,calculated_at=UTC_TIMESTAMP() WHERE season_year=?")->execute([AUGUR_DEPA_MODEL_VERSION,$year]);

        $ins=$pdo->prepare("INSERT INTO augur_depa_network_match_evidence
            (tba_match_key,tba_event_key,season_year,match_number,frc_team_number,alliance,raw_depa,expected_alliance_score,expected_opponent_score,actual_opponent_score,defense_status,defense_actions,defense_failures,defense_attempts,spot_great_defense,spot_weak_defense,observing_orgs,verified_orgs,possible_orgs,model_version,calculated_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
        foreach($canonical as $c)$ins->execute([$c['match'],$c['event'],$year,$c['match_number'],$c['team'],$c['alliance'],$c['raw'],$c['own_expected'],$c['opp_expected'],$c['opp_actual'],$c['status'],$c['actions'],$c['failures'],$c['attempts'],$c['great'],$c['weak'],count($c['orgs']),$c['verified_orgs'],$c['possible_orgs'],AUGUR_DEPA_MODEL_VERSION]);

        $upd=$pdo->prepare("UPDATE augur_depa_public_event_ratings SET neptune_depa=?,verified_defense_matches=?,possible_defense_matches=?,observed_matches=?,defense_actions=?,defense_failures=?,defense_attempts=?,spot_great_defense=?,spot_weak_defense=?,contributing_orgs=?,confidence_score=?,confidence_label=?,validation_label=?,source_label=?,model_version=?,calculated_at=UTC_TIMESTAMP() WHERE tba_event_key=? AND frc_team_number=?");
        $eventRatingRows=0;
        foreach($eventAgg as $event=>$teams){foreach($teams as $team=>$x){
            $verified=count($x['verified']);$possible=(int)$x['possible'];$neptune=augur_depa_mean($x['verified']);
            $conf=augur_depa_estimate_confidence($verified,(int)$x['attempts'],(int)$x['great'],(int)$x['weak']);
            $label=augur_depa_label($conf);$validation=augur_depa_validation_label($verified,$possible,$neptune);
            $source=$verified>0?'Neptune network scouting; deduplicated':'Network scouting observed; no verified defense';
            $upd->execute([$neptune,$verified,$possible,(int)$x['observed'],(int)$x['actions'],(int)$x['failures'],(int)$x['attempts'],(int)$x['great'],(int)$x['weak'],count($x['orgs']),$conf,$label,$validation,$source,AUGUR_DEPA_MODEL_VERSION,$event,(int)$team]);
            $eventRatingRows++;
        }}

        $insSeason=$pdo->prepare("INSERT INTO augur_depa_public_season_ratings
            (season_year,frc_team_number,public_depa,public_matches,neptune_depa,verified_defense_matches,possible_defense_matches,observed_matches,defense_actions,defense_failures,defense_attempts,spot_great_defense,spot_weak_defense,contributing_orgs,events_observed,rated_events,confidence_score,confidence_label,validation_label,source_label,last_event_key,model_version,calculated_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
        foreach($seasonAgg as $team=>$x){
            $verified=count($x['verified']);$possible=(int)$x['possible'];$neptune=augur_depa_mean($x['verified']);
            $publicMatches=(int)($publicSeason[$team]['matches']??0);
            $publicDepa=$publicMatches>0?((float)$publicSeason[$team]['weighted_sum']/$publicMatches):null;
            $conf=augur_depa_estimate_confidence($verified,(int)$x['attempts'],(int)$x['great'],(int)$x['weak']);
            $source=$verified>0?'Neptune network scouting; deduplicated':'All-match D-EPA only; no verified defense';
            $insSeason->execute([$year,(int)$team,$publicDepa,$publicMatches,$neptune,$verified,$possible,(int)$x['observed'],(int)$x['actions'],(int)$x['failures'],(int)$x['attempts'],(int)$x['great'],(int)$x['weak'],count($x['orgs']),count($x['events']),count($x['rated_events']),$conf,augur_depa_label($conf),augur_depa_validation_label($verified,$possible,$neptune),$source,$x['last_event'],AUGUR_DEPA_MODEL_VERSION]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    $allOrgIds=[];foreach($canonical as $c)foreach($c['orgs'] as $oid=>$yes)$allOrgIds[$oid]=true;
    return ['year'=>$year,'canonical_evidence_rows'=>count($canonical),'event_rating_rows'=>$eventRatingRows??0,'season_rating_rows'=>count($seasonAgg),'contributing_orgs'=>count($allOrgIds)];
}

function augur_depa_rebuild_year(PDO $pdo, int $year, ?int $onlyOrganizationId = null): array {
    // Public Neptune D-EPA is intentionally organization-agnostic. The legacy
    // parameter is retained for call compatibility but is ignored.
    $public=augur_depa_rebuild_public_year($pdo,$year);
    $network=augur_depa_rebuild_network_year($pdo,$year);
    return ['year'=>$year,'public'=>$public,'network'=>$network,'model_version'=>AUGUR_DEPA_MODEL_VERSION];
}
