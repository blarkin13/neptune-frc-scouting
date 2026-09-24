<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
$u = require_role(['owner']);

$platformOrgId = 1;
if (isset($config) && is_array($config)) {
    $platformOrgId = (int)($config['app']['platform_organization_id'] ?? 1);
}
if ((int)($u['organization_id'] ?? 0) !== $platformOrgId) {
    http_response_code(403);
    exit('Platform owner access required.');
}

$pageTitle = 'AUGUR Data Maintenance';
$moduleName = 'SATURN';

const NAM_JOB_DIR = '/tmp/neptune-augur-maintenance';
const NAM_JOB_FILE = NAM_JOB_DIR . '/job.json';

function nam_e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function nam_cli_php(): string {
    foreach (['/usr/bin/php', '/usr/local/bin/php'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) return $candidate;
    }
    throw new RuntimeException('PHP CLI executable was not found at /usr/bin/php or /usr/local/bin/php.');
}

function nam_exec_available(): bool {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return function_exists('exec') && !in_array('exec', $disabled, true);
}

function nam_job_read(): array {
    if (!is_file(NAM_JOB_FILE)) return [];
    $j = json_decode((string)@file_get_contents(NAM_JOB_FILE), true);
    return is_array($j) ? $j : [];
}

function nam_job_write(array $job): void {
    if (!is_dir(NAM_JOB_DIR) && !mkdir(NAM_JOB_DIR, 0770, true) && !is_dir(NAM_JOB_DIR)) {
        throw new RuntimeException('Could not create AUGUR job directory.');
    }
    if (file_put_contents(NAM_JOB_FILE, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        throw new RuntimeException('Could not save AUGUR job metadata.');
    }
}

function nam_job_running(array $job): bool {
    $pid = (int)($job['pid'] ?? 0);
    if ($pid <= 0) return false;
    $proc = '/proc/' . $pid;
    if (!is_dir($proc)) return false;

    $cmdline = @file_get_contents($proc . '/cmdline');
    if ($cmdline === false) return true;

    $script = basename((string)($job['script'] ?? ''));
    if ($script === '') return true;
    return str_contains(str_replace("\0", ' ', $cmdline), $script);
}

function nam_tail(string $path, int $lines = 120): string {
    if (!is_file($path) || !is_readable($path)) return '';
    $all = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($all)) return '';
    return implode("\n", array_slice($all, -$lines));
}

function nam_result_from_log(string $path): array {
    if (!is_file($path) || !is_readable($path)) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = (string)$lines[$i];
        if (!str_starts_with($line, 'NEPTUNE_RESULT_JSON:')) continue;
        $decoded = json_decode(substr($line, strlen('NEPTUNE_RESULT_JSON:')), true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

function nam_result_summary(array $r): string {
    if (!$r) return '';
    $parts = [];

    if (isset($r['directory']) && is_array($r['directory'])) {
        $d = $r['directory'];
        $parts[] = 'Directory: '
            . (int)($d['added'] ?? 0) . ' added, '
            . (int)($d['updated'] ?? 0) . ' updated, '
            . (int)($d['skipped'] ?? 0) . ' unchanged'
            . ((int)($d['removed'] ?? 0) > 0 ? ', ' . (int)$d['removed'] . ' removed' : '');
    }

    if (isset($r['source']) && is_array($r['source'])) {
        $s = $r['source'];
        $parts[] = 'Event source: '
            . (int)($s['changed'] ?? 0) . ' updated, '
            . (int)($s['cached'] ?? 0) . ' unchanged, '
            . (int)($s['skipped'] ?? 0) . ' skipped'
            . ((int)($s['errors'] ?? 0) > 0 ? ', ' . (int)$s['errors'] . ' errors' : '');
    }

    if (isset($r['epa']) && is_array($r['epa'])) {
        $e = $r['epa'];
        if ((int)($e['events'] ?? 0) || (int)($e['teams'] ?? 0)) {
            $parts[] = 'Public EPA: '
                . (int)($e['events'] ?? 0) . ' events, '
                . (int)($e['teams'] ?? 0) . ' teams, '
                . (int)($e['matches'] ?? 0) . ' qualification matches rebuilt';
        }
    }

    if (isset($r['opr']) && is_array($r['opr'])) {
        $o = $r['opr'];
        if ((int)($o['events'] ?? 0) || (int)($o['team_rows'] ?? 0)) {
            $parts[] = 'OPR: '
                . (int)($o['events'] ?? 0) . ' events, '
                . (int)($o['team_rows'] ?? 0) . ' team/event ratings rebuilt';
        }
    }

    $errors = (int)($r['errors'] ?? 0);
    if ($errors > 0 && !isset($r['source']['errors'])) $parts[] = $errors . ' error(s)';

    return implode(' · ', $parts);
}

function nam_table_exists(PDO $pdo, string $table): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$table]);
    return (int)$s->fetchColumn() > 0;
}

function nam_year_stats(PDO $pdo, int $year): array {
    $out = [
        'epa_teams' => null,
        'epa_events' => null,
        'opr_rows' => null,
        'opr_events' => null,
        'latest_epa' => null,
        'latest_opr' => null,
        'directory_teams' => null,
        'latest_directory' => null,
    ];

    try {
        if (nam_table_exists($pdo, 'augur_epa_season_ratings')) {
            $s = $pdo->prepare('SELECT COUNT(*) AS teams, MAX(calculated_at) AS latest FROM augur_epa_season_ratings WHERE season_year=?');
            $s->execute([$year]);
            $r = $s->fetch() ?: [];
            $out['epa_teams'] = (int)($r['teams'] ?? 0);
            $out['latest_epa'] = $r['latest'] ?? null;
        }
    } catch (Throwable $e) {}

    try {
        if (nam_table_exists($pdo, 'augur_epa_event_ratings')) {
            $s = $pdo->prepare('SELECT COUNT(DISTINCT tba_event_key) FROM augur_epa_event_ratings WHERE season_year=?');
            $s->execute([$year]);
            $out['epa_events'] = (int)$s->fetchColumn();
        }
    } catch (Throwable $e) {}

    try {
        if (nam_table_exists($pdo, 'augur_opr_event_ratings')) {
            $s = $pdo->prepare('SELECT COUNT(*) AS rows_count, COUNT(DISTINCT tba_event_key) AS events_count, MAX(calculated_at) AS latest FROM augur_opr_event_ratings WHERE season_year=?');
            $s->execute([$year]);
            $r = $s->fetch() ?: [];
            $out['opr_rows'] = (int)($r['rows_count'] ?? 0);
            $out['opr_events'] = (int)($r['events_count'] ?? 0);
            $out['latest_opr'] = $r['latest'] ?? null;
        }
    } catch (Throwable $e) {}

    try {
        if (nam_table_exists($pdo, 'augur_epa_team_directory')) {
            $s = $pdo->prepare('SELECT COUNT(*) AS teams, MAX(updated_at) AS latest FROM augur_epa_team_directory WHERE season_year=?');
            $s->execute([$year]);
            $r = $s->fetch() ?: [];
            $out['directory_teams'] = (int)($r['teams'] ?? 0);
            $out['latest_directory'] = $r['latest'] ?? null;
        }
    } catch (Throwable $e) {}

    return $out;
}

function nam_validate_year(mixed $value): int {
    $year = (int)$value;
    $max = (int)date('Y') + 1;
    if ($year < 1992 || $year > $max) {
        throw new RuntimeException("Year must be between 1992 and {$max}.");
    }
    return $year;
}

function nam_launch(string $mode, int $startYear, int $endYear): array {
    if (!nam_exec_available()) {
        throw new RuntimeException('PHP exec() is disabled, so the Maintenance page cannot launch a background CLI job.');
    }

    if ($startYear > $endYear) [$startYear, $endYear] = [$endYear, $startYear];

    $existing = nam_job_read();
    if ($existing && nam_job_running($existing)) {
        throw new RuntimeException('Another AUGUR maintenance job is already running.');
    }

    $php = nam_cli_php();
    $adminDir = __DIR__;

    if ($mode === 'directory') {
        $script = $adminDir . '/augur-team-directory-cli.php';
        $args = [$startYear, $endYear, '--force'];
        $label = 'Team-directory refresh';
    } elseif ($mode === 'opr') {
        $script = $adminDir . '/augur-opr-rebuild-cli.php';
        $args = [$startYear, $endYear];
        $label = 'OPR-only rebuild';
    } else {
        $script = $adminDir . '/augur-epa-backfill-cli.php';
        $args = [$startYear, $endYear];
        if ($mode === 'recalc') $args[] = '--recalc-only';
        elseif ($mode !== 'full') throw new RuntimeException('Unknown AUGUR maintenance mode.');
        $label = $mode === 'recalc'
            ? 'Local EPA + OPR recalculation'
            : 'Full EPA backfill + OPR rebuild';
    }

    if (!is_file($script) || !is_readable($script)) {
        throw new RuntimeException('Required CLI script is missing: ' . basename($script));
    }

    if (!is_dir(NAM_JOB_DIR) && !mkdir(NAM_JOB_DIR, 0770, true) && !is_dir(NAM_JOB_DIR)) {
        throw new RuntimeException('Could not create AUGUR job directory.');
    }

    $stamp = date('Ymd-His');
    $log = NAM_JOB_DIR . '/augur-' . $stamp . '.log';

    $parts = [escapeshellarg($php), escapeshellarg($script)];
    foreach ($args as $arg) $parts[] = escapeshellarg((string)$arg);

    $cmd = 'nohup ' . implode(' ', $parts)
        . ' >> ' . escapeshellarg($log)
        . ' 2>&1 < /dev/null & echo $!';

    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $pid = isset($output[0]) ? (int)trim((string)$output[0]) : 0;

    if ($code !== 0 || $pid <= 0) {
        throw new RuntimeException('The AUGUR background job could not be started.');
    }

    $job = [
        'pid' => $pid,
        'mode' => $mode,
        'label' => $label,
        'start_year' => $startYear,
        'end_year' => $endYear,
        'started_at' => date(DATE_ATOM),
        'script' => $script,
        'log' => $log,
    ];
    nam_job_write($job);
    return $job;
}

if (isset($_GET['status'])) {
    header('Content-Type: application/json; charset=utf-8');
    $job = nam_job_read();
    $running = $job ? nam_job_running($job) : false;
    $logPath = $job ? (string)($job['log'] ?? '') : '';
    $log = $job ? nam_tail($logPath, 140) : '';
    $result = $job ? nam_result_from_log($logPath) : [];
    $statsYear = $job ? (int)($job['end_year'] ?? date('Y')) : (int)date('Y');
    echo json_encode([
        'ok' => true,
        'job' => $job,
        'running' => $running,
        'completed' => (bool)$job && !$running,
        'log' => $log,
        'result' => $result,
        'summary' => nam_result_summary($result),
        'stats_year' => $statsYear,
        'stats' => nam_year_stats($pdo, $statsYear),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action !== 'run_augur') throw new RuntimeException('Unknown AUGUR maintenance action.');

        $startYear = nam_validate_year($_POST['start_year'] ?? date('Y'));
        $endYear = nam_validate_year($_POST['end_year'] ?? $startYear);
        $mode = (string)($_POST['mode'] ?? 'full');

        $job = nam_launch($mode, $startYear, $endYear);
        $flash = ['type' => 'good', 'message' => $job['label'] . ' started.', 'detail' => 'PID ' . $job['pid'] . ' · ' . $job['start_year'] . '–' . $job['end_year']];
    } catch (Throwable $e) {
        $flash = ['type' => 'bad', 'message' => 'AUGUR maintenance action failed.', 'detail' => $e->getMessage()];
    }
}

$job = nam_job_read();
$running = $job ? nam_job_running($job) : false;
$selectedYear = isset($_GET['year']) ? nam_validate_year($_GET['year']) : (int)date('Y');
$stats = nam_year_stats($pdo, $selectedYear);
$logTail = $job ? nam_tail((string)($job['log'] ?? ''), 140) : '';
$initialResult = $job ? nam_result_from_log((string)($job['log'] ?? '')) : [];
$initialSummary = nam_result_summary($initialResult);

include dirname(__DIR__) . '/partials_header.php';
?>
<style>
.augur-maint-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.augur-maint-two{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(310px,.75fr);gap:14px}
.augur-maint-stat b{display:block;font-size:1.15rem}.augur-maint-stat small{color:var(--muted)}
.augur-maint-actions{display:flex;gap:8px;align-items:end;flex-wrap:wrap}
.augur-maint-actions label{min-width:120px;flex:1}
.augur-maint-actions select,.augur-maint-actions input{width:100%}
.augur-maint-terminal{background:#07090c;color:#e8edf2;border:1px solid #222b34;border-radius:8px;padding:12px}
.augur-maint-terminal pre{margin:0;white-space:pre-wrap;overflow-wrap:anywhere;max-height:520px;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:.82rem;line-height:1.45}
.augur-maint-job{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.augur-maint-dot{width:10px;height:10px;border-radius:50%;background:#7f8b99;display:inline-block}
.augur-maint-dot.running{background:#28a66a;box-shadow:0 0 0 4px color-mix(in srgb,#28a66a 15%,transparent)}
.augur-maint-terminal[hidden]{display:none}
.augur-maint-summary{margin-top:12px;padding:11px 12px;border:1px solid var(--line);border-radius:8px;background:var(--panel2)}
.augur-maint-summary[hidden]{display:none}
.augur-maint-log-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.augur-confirm{width:min(560px,calc(100vw - 28px));border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--text);padding:0;box-shadow:0 24px 80px rgba(0,0,0,.45)}
.augur-confirm::backdrop{background:rgba(0,0,0,.64);backdrop-filter:blur(2px)}
.augur-confirm-body{padding:20px}.augur-confirm h3{margin:0 0 8px}.augur-confirm p{margin:0;color:var(--muted)}
.augur-confirm-actions{display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid var(--line)}
.augur-toast-stack{position:fixed;right:18px;bottom:18px;z-index:10050;display:grid;gap:10px;width:min(460px,calc(100vw - 28px));pointer-events:none}
.augur-toast{pointer-events:auto;border:1px solid var(--line);border-radius:10px;background:var(--panel);box-shadow:0 18px 55px rgba(0,0,0,.38);padding:13px 14px;animation:augurToastIn .18s ease-out}
.augur-toast.good{border-left:4px solid #28a66a}.augur-toast.bad{border-left:4px solid #c84848}
.augur-toast b{display:block;margin-bottom:4px}.augur-toast div{color:var(--muted);font-size:.88rem;line-height:1.4}
@keyframes augurToastIn{from{transform:translateY(8px);opacity:0}to{transform:none;opacity:1}}
@media(max-width:900px){.augur-maint-grid{grid-template-columns:1fr 1fr}.augur-maint-two{grid-template-columns:1fr}}
@media(max-width:620px){.augur-maint-grid{grid-template-columns:1fr}}
</style>

<section class="module-page command-page">
  <header class="module-page-header">
    <div>
      <div class="module-code">SATURN · AUGUR</div>
      <h1>AUGUR Data Maintenance</h1>
      <p>Run Public EPA backfill/recalculation and Neptune's local traditional OPR rebuild without SSH.</p>
    </div>
    <a class="btn secondary" href="<?=nam_e(base_url('admin/maintenance.php'))?>"><i class="fa-solid fa-arrow-left"></i> Maintenance</a>
  </header>

  <?php if ($flash): ?>
    <div class="notice <?=$flash['type']==='bad'?'bad':'good'?>" style="margin-bottom:14px">
      <b><?=nam_e($flash['message'])?></b>
      <?php if ($flash['detail']): ?><div style="margin-top:5px"><?=nam_e($flash['detail'])?></div><?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="notice" style="margin-bottom:14px">
    <b>One normal backfill updates both models.</b>
    <span class="muted">The full option first refreshes <code>augur_epa_team_directory</code> from TBA, then updates the event archive, recalculates Public EPA, and rebuilds traditional OPR. Local recalculation uses only data already stored on Neptune. Team-directory refresh can also be run by itself.</span>
  </div>

  <div class="card">
    <div class="module-eyebrow"><span>REBUILD</span><small>Background CLI job</small></div>
    <h2 style="margin-top:4px">Run AUGUR Data Update</h2>
    <form method="post" class="augur-maint-actions" id="augurRunForm">
      <input type="hidden" name="csrf" value="<?=nam_e(csrf_token())?>">
      <input type="hidden" name="action" value="run_augur">
      <label><span>Start year</span><input type="number" name="start_year" min="1992" max="<?=date('Y')+1?>" value="<?=date('Y')?>" required></label>
      <label><span>End year</span><input type="number" name="end_year" min="1992" max="<?=date('Y')+1?>" value="<?=date('Y')?>" required></label>
      <label style="min-width:260px"><span>Operation</span>
        <select name="mode">
          <option value="full">Full EPA backfill + OPR rebuild</option>
          <option value="recalc">Recalculate EPA + OPR from local archive only</option>
          <option value="directory">Refresh TBA team directory only</option>
          <option value="opr">Rebuild OPR only from local archive</option>
        </select>
      </label>
      <button type="submit" id="augurRunButton"><i class="fa-solid fa-play"></i> <span id="augurRunButtonText">Run Update</span></button>
    </form>
    <p class="muted" style="margin-bottom:0;margin-top:10px">
      <b>Full:</b> refreshes team metadata, discovers/fetches TBA event data, then rebuilds EPA and OPR.
      <b>Directory:</b> refreshes team names and locations only.
      <b>Local:</b> makes no TBA requests.
      <b>OPR only:</b> recalculates <code>augur_opr_event_ratings</code> only.
    </p>
  </div>

  <div class="augur-maint-two" style="margin-top:14px">
    <div class="card">
      <div class="module-eyebrow"><span>JOB STATUS</span><small>Auto-refreshes</small></div>
      <div class="augur-maint-job">
        <span id="augurJobDot" class="augur-maint-dot <?=$running?'running':''?>"></span>
        <h2 id="augurJobTitle" style="margin:4px 0"><?=$job?nam_e((string)($job['label']??'AUGUR job')):'No job has been started'?></h2>
      </div>
      <div id="augurJobMeta" class="muted">
        <?php if ($job): ?>
          PID <?=nam_e((string)($job['pid']??''))?> · <?=nam_e((string)($job['start_year']??''))?>–<?=nam_e((string)($job['end_year']??''))?> · <?=$running?'Running':'Not running'?>
        <?php else: ?>
          Start a rebuild above. The page may be closed while the CLI process continues.
        <?php endif; ?>
      </div>
      <div id="augurJobSummary" class="augur-maint-summary" <?=$initialSummary===''?'hidden':''?>>
        <b>Last run summary</b>
        <div class="muted" id="augurJobSummaryText"><?=nam_e($initialSummary)?></div>
      </div>
      <div class="augur-maint-log-actions">
        <button type="button" class="secondary" id="augurShowLog" <?=$job?'':'hidden'?>><i class="fa-solid fa-file-lines"></i> Show Last Log</button>
      </div>
      <div class="augur-maint-terminal" id="augurTerminal" style="margin-top:12px" <?=$running?'':'hidden'?>><pre id="augurJobLog"><?=nam_e($running && $logTail!==''?$logTail:'')?></pre></div>
    </div>

    <div class="card">
      <div class="module-eyebrow"><span>DATABASE</span><small>Stored local ratings</small></div>
      <h2 style="margin-top:4px">Season Status</h2>
      <form method="get" style="display:flex;gap:8px;align-items:end;margin-bottom:12px">
        <label style="flex:1"><span>Season</span><input type="number" name="year" min="1992" max="<?=date('Y')+1?>" value="<?=nam_e((string)$selectedYear)?>"></label>
        <button class="secondary" type="submit"><i class="fa-solid fa-chart-simple"></i> Load Status</button>
      </form>
      <div class="augur-maint-grid">
        <div class="augur-maint-stat"><b id="statEpaTeams"><?=nam_e($stats['epa_teams']===null?'—':(string)$stats['epa_teams'])?></b><small>Public EPA teams</small></div>
        <div class="augur-maint-stat"><b id="statEpaEvents"><?=nam_e($stats['epa_events']===null?'—':(string)$stats['epa_events'])?></b><small>EPA events</small></div>
        <div class="augur-maint-stat"><b id="statOprRows"><?=nam_e($stats['opr_rows']===null?'—':(string)$stats['opr_rows'])?></b><small>OPR team/event rows</small></div>
        <div class="augur-maint-stat"><b id="statOprEvents"><?=nam_e($stats['opr_events']===null?'—':(string)$stats['opr_events'])?></b><small>OPR events</small></div>
        <div class="augur-maint-stat"><b id="statDirectoryTeams"><?=nam_e($stats['directory_teams']===null?'—':(string)$stats['directory_teams'])?></b><small>Team-directory rows</small></div>
      </div>
      <div class="muted" style="margin-top:12px">
        Team directory synced: <span id="statDirectoryLatest"><?=nam_e((string)($stats['latest_directory']??'—'))?></span><br>
        EPA calculated: <span id="statEpaLatest"><?=nam_e((string)($stats['latest_epa']??'—'))?></span><br>
        OPR calculated: <span id="statOprLatest"><?=nam_e((string)($stats['latest_opr']??'—'))?></span>
      </div>
    </div>
  </div>
</section>

<dialog id="augurConfirmDialog" class="augur-confirm">
  <div class="augur-confirm-body">
    <h3>Run AUGUR data update?</h3>
    <p id="augurConfirmText">This job runs in the background. You can leave this page after it starts.</p>
  </div>
  <div class="augur-confirm-actions">
    <button type="button" class="secondary" id="augurCancelRun">Cancel</button>
    <button type="button" id="augurConfirmRun"><i class="fa-solid fa-play"></i> Start Update</button>
  </div>
</dialog>
<div class="augur-toast-stack" id="augurToastStack" aria-live="polite" aria-atomic="true"></div>

<script>
(() => {
  const dot = document.getElementById('augurJobDot');
  const title = document.getElementById('augurJobTitle');
  const meta = document.getElementById('augurJobMeta');
  const log = document.getElementById('augurJobLog');
  const terminal = document.getElementById('augurTerminal');
  const summaryBox = document.getElementById('augurJobSummary');
  const summaryText = document.getElementById('augurJobSummaryText');
  const showLog = document.getElementById('augurShowLog');
  const toastStack = document.getElementById('augurToastStack');

  const form = document.getElementById('augurRunForm');
  const getRunButton = () => document.getElementById('augurRunButton')
    || document.querySelector('#augurRunForm button[type="submit"]');

  function syncRunButton(isRunning) {
    const btn = getRunButton();
    if (!btn) return;

    if (isRunning) {
      btn.disabled = true;
      btn.setAttribute('disabled', 'disabled');
      btn.innerHTML = '<i class="fa-solid fa-play"></i> <span>Job Running</span>';
    } else {
      // Explicitly clear both the DOM property and HTML attribute. This avoids
      // a server-rendered/stale disabled attribute leaving the button stuck.
      btn.disabled = false;
      btn.removeAttribute('disabled');
      btn.removeAttribute('aria-disabled');
      btn.innerHTML = '<i class="fa-solid fa-play"></i> <span>Run Update</span>';
    }
  }
  const confirmDialog = document.getElementById('augurConfirmDialog');
  const confirmText = document.getElementById('augurConfirmText');
  const cancelRun = document.getElementById('augurCancelRun');
  const confirmRun = document.getElementById('augurConfirmRun');

  let allowSubmit = false;
  let lastRunning = <?= $running ? 'true' : 'false' ?>;
  syncRunButton(lastRunning);
  let lastLog = <?= json_encode($logTail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

  function toast(titleText, bodyText, type = 'good') {
    const item = document.createElement('div');
    item.className = `augur-toast ${type}`;
    const b = document.createElement('b');
    b.textContent = titleText;
    const body = document.createElement('div');
    body.textContent = bodyText || '';
    item.append(b, body);
    toastStack.appendChild(item);
    setTimeout(() => item.remove(), 9000);
  }

  function renderSummary(summary) {
    if (!summary) {
      summaryBox.hidden = true;
      summaryText.textContent = '';
      return;
    }
    summaryText.textContent = summary;
    summaryBox.hidden = false;
  }

  function updateStats(stats) {
    if (!stats) return;
    const set = (id, value) => {
      const el = document.getElementById(id);
      if (el) el.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
    };
    set('statEpaTeams', stats.epa_teams);
    set('statEpaEvents', stats.epa_events);
    set('statOprRows', stats.opr_rows);
    set('statOprEvents', stats.opr_events);
    set('statDirectoryTeams', stats.directory_teams);
    set('statDirectoryLatest', stats.latest_directory);
    set('statEpaLatest', stats.latest_epa);
    set('statOprLatest', stats.latest_opr);
  }

  function maybeNotifyCompletion(d) {
    if (!d.job || d.running) return;
    const key = `neptuneAugurNotified:${d.job.started_at || d.job.pid || 'last'}`;
    if (localStorage.getItem(key)) return;

    const failed = !d.result || !Object.keys(d.result).length || d.result.status === 'error';
    const partial = d.result && d.result.status === 'partial';
    const heading = failed ? 'AUGUR job stopped' : (partial ? 'AUGUR update completed with warnings' : 'AUGUR update complete');
    const body = d.summary || (failed ? 'No completion summary was written. Use Show Last Log for details.' : 'The maintenance job completed.');
    toast(heading, body, failed ? 'bad' : 'good');
    localStorage.setItem(key, '1');
  }

  if (form) {
    form.addEventListener('submit', (event) => {
      if (allowSubmit) return;
      event.preventDefault();

      const mode = form.querySelector('[name="mode"]')?.selectedOptions?.[0]?.textContent?.trim() || 'AUGUR update';
      const start = form.querySelector('[name="start_year"]')?.value || '';
      const end = form.querySelector('[name="end_year"]')?.value || start;
      confirmText.textContent = `${mode} for ${start}${end !== start ? `–${end}` : ''}. The job runs in the background and the page does not need to stay open.`;

      if (typeof confirmDialog.showModal === 'function') confirmDialog.showModal();
      else {
        allowSubmit = true;
        form.requestSubmit();
      }
    });
  }

  cancelRun?.addEventListener('click', () => confirmDialog.close());
  confirmRun?.addEventListener('click', () => {
    allowSubmit = true;
    confirmDialog.close();
    form.requestSubmit();
  });

  showLog?.addEventListener('click', () => {
    terminal.hidden = !terminal.hidden;
    if (!terminal.hidden) {
      log.textContent = lastLog || 'No log output is available.';
      log.scrollTop = log.scrollHeight;
      showLog.innerHTML = '<i class="fa-solid fa-xmark"></i> Hide Last Log';
    } else {
      log.textContent = '';
      showLog.innerHTML = '<i class="fa-solid fa-file-lines"></i> Show Last Log';
    }
  });

  async function refreshJob() {
    try {
      const r = await fetch(<?=json_encode(base_url('admin/augur-maintenance.php?status=1'))?>, {
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      });
      if (!r.ok) return;
      const d = await r.json();
      if (!d.job || !Object.keys(d.job).length) return;

      dot.classList.toggle('running', !!d.running);
      title.textContent = d.job.label || 'AUGUR job';
      meta.textContent = `PID ${d.job.pid || '—'} · ${d.job.start_year || '—'}–${d.job.end_year || '—'} · ${d.running ? 'Running' : 'Completed'}`;

      // The original server-rendered disabled attribute reflected the job state
      // only at page load. Keep the Run button synchronized with the polled
      // background-job state so another operation can be started immediately
      // after the previous one completes.
      syncRunButton(!!d.running);

      lastLog = typeof d.log === 'string' ? d.log : '';

      if (d.running) {
        terminal.hidden = false;
        showLog.hidden = true;
        const nearBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 70;
        log.textContent = lastLog;
        if (nearBottom) log.scrollTop = log.scrollHeight;
      } else {
        // Keep the server-side log for diagnostics, but clear and hide the
        // terminal after completion so the page returns to a clean state.
        terminal.hidden = true;
        log.textContent = '';
        showLog.hidden = false;
        showLog.innerHTML = '<i class="fa-solid fa-file-lines"></i> Show Last Log';
        renderSummary(d.summary || '');
        updateStats(d.stats);
      }

      if (lastRunning && !d.running) maybeNotifyCompletion(d);
      if (!lastRunning && !d.running) maybeNotifyCompletion(d);
      lastRunning = !!d.running;
    } catch (e) {}
  }

  refreshJob();
  setInterval(refreshJob, 4000);
})();
</script>
<?php include dirname(__DIR__) . '/partials_footer.php'; ?>
