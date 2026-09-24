<?php
declare(strict_types=1);

require_once __DIR__ . '/_augur_epa.php';

/**
 * Local, season-scoped TBA team directory used by AUGUR, Pre-Scout, and
 * offline/event workflows. This is derived public metadata, not org-private data.
 */
function augur_team_directory_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS augur_epa_team_directory (
        season_year SMALLINT UNSIGNED NOT NULL,
        frc_team_number INT UNSIGNED NOT NULL,
        tba_team_key VARCHAR(40) DEFAULT NULL,
        nickname VARCHAR(160) DEFAULT NULL,
        name VARCHAR(255) DEFAULT NULL,
        city VARCHAR(120) DEFAULT NULL,
        state_prov VARCHAR(120) DEFAULT NULL,
        country VARCHAR(120) DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (season_year, frc_team_number),
        KEY idx_augur_team_directory_team (frc_team_number),
        KEY idx_augur_team_directory_name (season_year, nickname)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function augur_team_directory_columns(PDO $pdo): array {
    static $cache = [];
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if (isset($cache[$db])) return $cache[$db];

    $s = $pdo->prepare("SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='augur_epa_team_directory'");
    $s->execute();
    $cols = array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    return $cache[$db] = array_fill_keys($cols, true);
}

function augur_team_directory_sync_year(
    PDO $pdo,
    int $year,
    bool $force = false,
    ?callable $progress = null
): array {
    if ($year < 1992 || $year > (int)date('Y') + 1) {
        throw new RuntimeException('Invalid FRC season year.');
    }

    augur_team_directory_ensure_table($pdo);
    $cols = augur_team_directory_columns($pdo);

    foreach (['season_year','frc_team_number','nickname','name','city','state_prov','country'] as $required) {
        if (!isset($cols[$required])) {
            throw new RuntimeException("augur_epa_team_directory is missing required column: {$required}");
        }
    }

    $rows = [];
    $seen = [];
    $pages = 0;
    $pagesWithTeams = 0;
    $historical = $year < (int)date('Y');
    $ttl = $historical ? 2592000 : 3600;

    /*
     * TBA team pages are NUMBER-RANGE pages, not "500 returned rows" pages.
     * A page can legitimately contain fewer than 500 teams and later pages can
     * still contain valid teams. Use /status.max_team_page as the authoritative
     * upper bound and scan every page through that value.
     */
    $status = augur_epa_tba_get($pdo, 'status', 300, $force);
    $maxPage = is_array($status) ? (int)($status['max_team_page'] ?? -1) : -1;
    if ($maxPage < 0 || $maxPage > 200) {
        // Defensive fallback if TBA status is temporarily incomplete.
        $maxPage = 40;
    }

    for ($page = 0; $page <= $maxPage; $page++) {
        if ($progress) $progress("Fetching TBA team directory page {$page} of {$maxPage}...");
        $pageRows = augur_epa_tba_get($pdo, "teams/{$year}/{$page}/simple", $ttl, $force);

        if (!is_array($pageRows)) {
            throw new RuntimeException("Unexpected TBA response for team directory page {$page}.");
        }

        $pages++;
        if ($pageRows) $pagesWithTeams++;

        foreach ($pageRows as $team) {
            if (!is_array($team)) continue;
            $num = (int)($team['team_number'] ?? 0);
            if ($num <= 0 || isset($seen[$num])) continue;
            $seen[$num] = true;

            $rows[] = [
                'season_year' => $year,
                'frc_team_number' => $num,
                'tba_team_key' => trim((string)($team['key'] ?? '')) ?: ('frc' . $num),
                'nickname' => trim((string)($team['nickname'] ?? '')) ?: null,
                'name' => trim((string)($team['name'] ?? '')) ?: null,
                'city' => trim((string)($team['city'] ?? '')) ?: null,
                'state_prov' => trim((string)($team['state_prov'] ?? '')) ?: null,
                'country' => trim((string)($team['country'] ?? '')) ?: null,
            ];
        }
    }

    if (!$rows) {
        throw new RuntimeException("TBA returned no teams for {$year}; the existing local directory was left untouched.");
    }

    /*
     * Safety check: if Neptune already has season EPA ratings, every rated team
     * must be represented in the fetched TBA season directory before we replace
     * the local snapshot. This prevents a partial API response from wiping team
     * names/locations again.
     */
    try {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='augur_epa_season_ratings'");
        $exists->execute();
        if ((int)$exists->fetchColumn() > 0) {
            $rated = $pdo->prepare('SELECT frc_team_number FROM augur_epa_season_ratings WHERE season_year=?');
            $rated->execute([$year]);
            $missingRated = [];
            foreach ($rated->fetchAll(PDO::FETCH_COLUMN) as $teamNo) {
                $teamNo = (int)$teamNo;
                if ($teamNo > 0 && !isset($seen[$teamNo])) $missingRated[] = $teamNo;
            }
            if ($missingRated) {
                $sample = implode(', ', array_slice($missingRated, 0, 12));
                throw new RuntimeException(
                    "TBA team-directory refresh is incomplete: " . count($missingRated)
                    . " rated team(s) are missing from the fetched directory"
                    . ($sample !== '' ? " (for example {$sample})" : '')
                    . ". Existing directory data was left untouched."
                );
            }
        }
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Throwable $e) {
        // A metadata safety-check failure should never make us erase good data.
        throw new RuntimeException('Could not verify team-directory completeness; existing directory data was left untouched. ' . $e->getMessage());
    }

    $insertCols = ['season_year','frc_team_number'];
    foreach (['tba_team_key','nickname','name','city','state_prov','country'] as $c) {
        if (isset($cols[$c])) $insertCols[] = $c;
    }

    $quoted = implode(',', array_map(static fn($c) => "`{$c}`", $insertCols));
    $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
    $updates = array_values(array_filter($insertCols, static fn($c) => !in_array($c, ['season_year','frc_team_number'], true)));
    $updateSql = $updates
        ? implode(',', array_map(static fn($c) => "`{$c}`=VALUES(`{$c}`)", $updates))
        : '`frc_team_number`=VALUES(`frc_team_number`)';

    if (isset($cols['updated_at'])) {
        $updateSql .= ',`updated_at`=CURRENT_TIMESTAMP';
    }

    $stmt = $pdo->prepare("INSERT INTO augur_epa_team_directory ({$quoted})
        VALUES ({$placeholders})
        ON DUPLICATE KEY UPDATE {$updateSql}");

    // Snapshot the existing season so the caller gets a useful maintenance
    // summary instead of just "N rows now exist".
    $existingStmt = $pdo->prepare("SELECT frc_team_number,tba_team_key,nickname,name,city,state_prov,country
        FROM augur_epa_team_directory WHERE season_year=?");
    $existingStmt->execute([$year]);
    $existing = [];
    foreach ($existingStmt->fetchAll() as $oldRow) {
        $existing[(int)$oldRow['frc_team_number']] = $oldRow;
    }

    $same = static function(mixed $a, mixed $b): bool {
        $na = $a === null ? '' : trim((string)$a);
        $nb = $b === null ? '' : trim((string)$b);
        return $na === $nb;
    };

    $added = 0;
    $updated = 0;
    $unchanged = 0;
    $removed = 0;

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $num = (int)$row['frc_team_number'];
            $oldRow = $existing[$num] ?? null;

            if ($oldRow !== null) {
                $changed = false;
                foreach (['tba_team_key','nickname','name','city','state_prov','country'] as $field) {
                    if (!$same($oldRow[$field] ?? null, $row[$field] ?? null)) {
                        $changed = true;
                        break;
                    }
                }
                if (!$changed) {
                    $unchanged++;
                    unset($existing[$num]);
                    continue;
                }
                $updated++;
            } else {
                $added++;
            }

            $values = [];
            foreach ($insertCols as $c) $values[] = $row[$c] ?? null;
            $stmt->execute($values);
            unset($existing[$num]);
        }

        // Any rows left in $existing are no longer present in TBA's complete
        // season directory. Remove only after the full fetch + completeness
        // safety check succeeded.
        if ($existing) {
            $del = $pdo->prepare('DELETE FROM augur_epa_team_directory WHERE season_year=? AND frc_team_number=?');
            foreach (array_keys($existing) as $teamNo) {
                $del->execute([$year, (int)$teamNo]);
                $removed += $del->rowCount();
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return [
        'year' => $year,
        'teams' => count($rows),
        'added' => $added,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'removed' => $removed,
        'pages' => $pages,
        'pages_with_teams' => $pagesWithTeams,
        'max_team_page' => $maxPage,
        'forced' => $force,
    ];
}
