<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
require_once dirname(__DIR__) . '/analytics/_augur_team_directory.php';

@set_time_limit(0);

$current = (int)date('Y');
$start = isset($argv[1]) ? (int)$argv[1] : $current;
$end = isset($argv[2]) ? (int)$argv[2] : $start;
$force = in_array('--force', $argv, true);

if ($start > $end) [$start, $end] = [$end, $start];

$lockPath = sys_get_temp_dir() . '/neptune_augur_team_directory.lock';
$lock = fopen($lockPath, 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another AUGUR team-directory sync appears to already be running.\n");
    exit(3);
}

ftruncate($lock, 0);
fwrite($lock, (string)getmypid());
fflush($lock);

echo "Neptune AUGUR team-directory sync {$start}-{$end}\n";
echo "Mode: " . ($force ? "force fresh TBA fetch" : "use Neptune/TBA cache when valid") . "\n";
echo "PID: " . getmypid() . "\n\n";

$exit = 0;
$summary = [
    'type' => 'directory',
    'status' => 'success',
    'start_year' => $start,
    'end_year' => $end,
    'directory' => ['added'=>0,'updated'=>0,'skipped'=>0,'removed'=>0,'teams'=>0,'pages'=>0],
    'errors' => 0,
];
for ($year = $start; $year <= $end; $year++) {
    try {
        echo "[{$year}] Updating augur_epa_team_directory...\n";
        $r = augur_team_directory_sync_year(
            $pdo,
            $year,
            $force,
            static function(string $msg) use ($year): void {
                echo "[{$year}] {$msg}\n";
            }
        );
        $summary['directory']['added'] += (int)($r['added'] ?? 0);
        $summary['directory']['updated'] += (int)($r['updated'] ?? 0);
        $summary['directory']['skipped'] += (int)($r['unchanged'] ?? 0);
        $summary['directory']['removed'] += (int)($r['removed'] ?? 0);
        $summary['directory']['teams'] += (int)($r['teams'] ?? 0);
        $summary['directory']['pages'] += (int)($r['pages'] ?? 0);
        echo "[{$year}] Complete: {$r['teams']} teams · "
            .(int)($r['added'] ?? 0)." added · "
            .(int)($r['updated'] ?? 0)." updated · "
            .(int)($r['unchanged'] ?? 0)." unchanged · "
            .(int)($r['removed'] ?? 0)." removed · "
            .$r['pages']." TBA page(s).\n\n";
    } catch (Throwable $e) {
        $exit = 1;
        $summary['errors']++;
        $summary['status'] = 'partial';
        fwrite(STDERR, "[{$year}] ERROR: {$e->getMessage()}\n\n");
    }
}

echo "NEPTUNE_RESULT_JSON:".json_encode($summary, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
flock($lock, LOCK_UN);
fclose($lock);
exit($exit);
