<?php
declare(strict_types=1);
/**
 * AUGUR Prediction Track Record: how often AUGUR's pre-match predictions were right,
 * compared with an EPA-only baseline and a coin flip. Read-only except the result cache.
 */
require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_augur_backtest.php';
$u = require_role(['owner', 'admin', 'strategy']);
$org = (int)$u['organization_id'];
$events = neptune_event_list($pdo, $org);
$eventId = (int)($_POST['event_id'] ?? $_GET['event_id'] ?? ($events[0]['id'] ?? 0));
$event = null;
foreach ($events as $ev) if ((int)$ev['id'] === $eventId) { $event = $ev; break; }
if ($eventId > 0 && !$event) { http_response_code(403); exit('Event is not available to your organization.'); }

$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    @set_time_limit(300);
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'recompute' && $eventId > 0) {
        augur_backtest_event($pdo, $org, $eventId, true);
        $notice = 'Backtest recomputed for this event.';
    } elseif ($action === 'all') {
        $q = $pdo->prepare("SELECT DISTINCT event_id FROM matches WHERE organization_id=? AND red_score IS NOT NULL AND blue_score IS NOT NULL");
        $q->execute([$org]);
        $done = 0;
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) { try { augur_backtest_event($pdo, $org, (int)$id); $done++; } catch (Throwable $e) { error_log('[AUGUR backtest] ' . $e->getMessage()); } }
        $notice = 'Backtested ' . $done . ' event(s). Unchanged events reused their cached results.';
    }
}

$result = $eventId > 0 ? augur_backtest_event($pdo, $org, $eventId) : null;

// Season roll-up from cached results (every event that has been backtested).
$rollup = []; $rollupStale = 0;
try {
    augur_backtest_install($pdo);
    $q = $pdo->prepare('SELECT b.event_id, b.computed_at, b.result_json, e.name, e.start_date FROM augur_prediction_backtests b JOIN events e ON e.id=b.event_id AND e.organization_id=b.organization_id WHERE b.organization_id=? ORDER BY e.start_date DESC, e.id DESC');
    $q->execute([$org]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = json_decode((string)$r['result_json'], true);
        if (!is_array($d) || empty($d['available'])) continue;
        // Results from an older backtest version are not comparable; "Backtest all events" refreshes them.
        if (($d['version'] ?? '') !== AUGUR_BACKTEST_VERSION) { $rollupStale++; continue; }
        $rollup[] = ['id' => (int)$r['event_id'], 'name' => $r['name'], 'computed_at' => $r['computed_at'], 'r' => $d];
    }
} catch (Throwable $e) { error_log('[AUGUR backtest rollup] ' . $e->getMessage()); }
$tot = ['n' => 0, 'right' => 0, 'brier' => 0.0, 'epa_n' => 0, 'epa_right' => 0, 'same_right' => 0, 'dis_n' => 0, 'dis_a' => 0, 'dis_e' => 0];
foreach ($rollup as $x) {
    $r = $x['r'];
    $tot['n'] += (int)$r['decided']; $tot['right'] += (int)round(((float)$r['accuracy']) / 100 * (int)$r['decided']);
    $tot['brier'] += (float)$r['brier'] * (int)$r['decided'];
    $eb = $r['epa_baseline'] ?? [];
    $tot['epa_n'] += (int)($eb['n'] ?? 0); $tot['same_right'] += (int)round(((float)($eb['augur_same_matches'] ?? 0)) / 100 * (int)($eb['n'] ?? 0)); $tot['epa_right'] += (int)round(((float)($eb['accuracy'] ?? 0)) / 100 * (int)($eb['n'] ?? 0));
    $tot['dis_n'] += (int)($eb['disagreements']['n'] ?? 0); $tot['dis_a'] += (int)($eb['disagreements']['augur'] ?? 0); $tot['dis_e'] += (int)($eb['disagreements']['epa'] ?? 0);
}

$pct = static fn($v, int $d = 0): string => $v === null ? 'n/a' : number_format((float)$v, $d) . '%';
$pageTitle = 'Prediction Track Record'; $moduleName = 'AUGUR';
include dirname(__DIR__) . '/partials_header.php';
?>
<style>
.ptr-page .ptr-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:14px 0}
.ptr-page .ptr-stat small{display:block;color:var(--muted);font-size:.74rem;font-weight:700}
.ptr-page .ptr-stat strong{display:block;font-size:1.55rem;line-height:1.25;margin-top:4px}
.ptr-page .ptr-stat span{display:block;color:var(--muted);font-size:.76rem;margin-top:3px}
.ptr-page .ptr-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin:12px 0}
.ptr-page table{width:100%;border-collapse:collapse;font-size:.8rem}
.ptr-page th,.ptr-page td{text-align:left;padding:7px 8px;border-bottom:1px solid var(--line);vertical-align:middle}
.ptr-page th{font-size:.72rem;color:var(--muted);font-weight:800}
.ptr-page .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.ptr-page .ptr-wrap{overflow-x:auto}
.ptr-page .ptr-bar{position:relative;height:12px;border-radius:6px;background:color-mix(in srgb,var(--line) 60%,transparent);min-width:120px}
.ptr-page .ptr-bar i{position:absolute;inset:0 auto 0 0;border-radius:6px;background:var(--accent)}
.ptr-page .ptr-bar b{position:absolute;top:-3px;bottom:-3px;width:2px;background:var(--text);opacity:.75}
.ptr-page .ptr-right{color:var(--good,#45A77D);font-weight:800}.ptr-page .ptr-wrong{color:var(--bad,#da5d5d);font-weight:800}
.ptr-page .ptr-note{color:var(--muted);font-size:.8rem;line-height:1.5}
@media(max-width:860px){.ptr-page .ptr-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<section class="module-page ptr-page">
 <header class="module-page-header">
  <div><div class="module-code">AUGUR · PREDICTION TRACK RECORD</div>
   <h1>Prediction Track Record</h1>
   <p>How often AUGUR's pre-match predictions were right, using only data available before each match, compared with EPA alone and a coin flip.</p></div>
  <a class="btn secondary" href="<?=e(base_url('analytics/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Analytics &amp; Strategy</a>
 </header>
 <?php if ($notice): ?><div class="notice good" role="status"><?=e($notice)?></div><?php endif; ?>
 <div class="ptr-toolbar">
  <form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap"><label>Event<select name="event_id"><?=neptune_event_options_html($events, $eventId)?></select></label><button class="btn secondary" type="submit">View event</button></form>
  <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="action" value="recompute"><button class="btn secondary" type="submit"><i class="fa-solid fa-rotate"></i> Recompute this event</button></form>
  <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="action" value="all"><button class="btn secondary" type="submit"><i class="fa-solid fa-layer-group"></i> Backtest all events</button></form>
 </div>

 <?php if (!$result || empty($result['available'])): ?>
  <div class="card"><strong>No track record for this event yet.</strong><p class="ptr-note"><?=e($result['reason'] ?? 'Choose an event with finished matches.')?></p></div>
 <?php else: $eb = $result['epa_baseline']; $dis = $eb['disagreements']; ?>
  <div class="ptr-grid">
   <div class="card ptr-stat"><small>Picked the winner</small><strong><?=$pct($result['accuracy'], 1)?></strong><span><?=(int)$result['decided']?> decided matches<?=$result['ties'] ? ' · ' . (int)$result['ties'] . ' ties excluded' : ''?></span></div>
   <div class="card ptr-stat"><small>EPA alone picked the winner</small><strong><?=$pct($eb['accuracy'], 1)?></strong><span><?php if ((int)$eb['n'] < (int)$result['decided']): ?>Only <?=(int)$eb['n']?> of <?=(int)$result['decided']?> matches had EPA for all six teams; AUGUR on those same matches: <?=$pct($eb['augur_same_matches'] ?? null, 1)?><?php else: ?>Higher pre-match EPA sum wins<?php endif; ?></span></div>
   <div class="card ptr-stat"><small>Brier score (lower is better)</small><strong><?=$result['brier'] !== null ? number_format((float)$result['brier'], 3) : 'n/a'?></strong><span>EPA alone <?=$eb['brier'] !== null ? number_format((float)$eb['brier'], 3) : 'n/a'?> · coin flip 0.250</span></div>
   <div class="card ptr-stat"><small>Real score inside AUGUR's 80% range</small><strong><?=$pct($result['in_range_pct'])?></strong><span>Should be about 80% · error <?=number_format((float)$result['score_mae'], 1)?> pts, bias <?=($result['score_bias'] > 0 ? '+' : '') . number_format((float)$result['score_bias'], 1)?></span></div>
  </div>

  <section class="card" style="margin-bottom:14px">
   <h2 style="margin-top:0"><i class="fa-solid fa-scale-balanced"></i> When AUGUR and EPA disagreed</h2>
   <p class="ptr-note"><?=(int)$dis['n']?> matches had different predicted winners. AUGUR was right in <b><?=(int)$dis['augur']?></b>, EPA alone in <b><?=(int)$dis['epa']?></b>.
   <?php if ($dis['n'] >= 5): ?><?=$dis['augur'] > $dis['epa'] ? 'Here, current-event scouting added value beyond public ratings.' : ($dis['augur'] < $dis['epa'] ? 'Here, blending in current-event scouting made picks slightly worse than public ratings alone.' : 'Neither did better.')?><?php endif; ?></p>
  </section>

  <section class="card" style="margin-bottom:14px">
   <h2 style="margin-top:0"><i class="fa-solid fa-bullseye"></i> Calibration: does "70% likely" win 70% of the time?</h2>
   <div class="ptr-wrap"><table><thead><tr><th>AUGUR's favorite was</th><th class="num">Matches</th><th class="num">Average prediction</th><th class="num">Favorite actually won</th><th>Actual (bar) vs predicted (line)</th></tr></thead><tbody>
   <?php foreach ($result['calibration'] as $b): ?>
    <tr><td><?=$b['from']?>–<?=$b['to']?>% likely</td><td class="num"><?=(int)$b['n']?></td><td class="num"><?=$pct($b['avg_predicted'])?></td><td class="num"><?=$pct($b['favorite_won'])?></td>
     <td><div class="ptr-bar" title="Actual <?=$pct($b['favorite_won'])?> vs predicted <?=$pct($b['avg_predicted'])?>"><i style="width:<?=max(0, min(100, (float)$b['favorite_won']))?>%"></i><b style="left:<?=max(0, min(100, (float)$b['avg_predicted']))?>%"></b></div></td></tr>
   <?php endforeach; ?>
   </tbody></table></div>
   <p class="ptr-note">If the bar ends left of the line, AUGUR was overconfident in that range; right of the line, underconfident. Rows with fewer than about 10 matches are noisy.</p>
  </section>

  <section class="card" style="margin-bottom:14px">
   <h2 style="margin-top:0"><i class="fa-solid fa-timeline"></i> Accuracy through the event</h2>
   <div class="ptr-wrap"><table><thead><tr><th>Stage</th><th class="num">Matches</th><th class="num">Picked the winner</th></tr></thead><tbody>
   <?php foreach (['early' => 'Early qualifications (first third)', 'middle' => 'Middle qualifications', 'late' => 'Late qualifications (last third)', 'playoff' => 'Playoffs'] as $k => $label): if (empty($result['by_stage'][$k])) continue; $s = $result['by_stage'][$k]; ?>
    <tr><td><?=e($label)?></td><td class="num"><?=(int)$s['n']?></td><td class="num"><?=$pct($s['accuracy'], 1)?></td></tr>
   <?php endforeach; ?>
   </tbody></table></div>
  </section>

  <details class="card" style="margin-bottom:14px"><summary><b>Every match: prediction vs result</b> (<?=(int)$result['matches']?>)</summary>
   <div class="ptr-wrap"><table><thead><tr><th>Match</th><th>Red</th><th>Blue</th><th class="num">Red win %</th><th class="num">Predicted</th><th class="num">Actual</th><th class="num">EPA pick</th><th>Result</th></tr></thead><tbody>
   <?php foreach ($result['rows'] as $r): $epaPick = $r['epa'] ? ($r['epa']['red_epa'] >= $r['epa']['blue_epa'] ? 'Red' : 'Blue') : '—'; ?>
    <tr><td><?=e($r['label'])?></td><td><?=e(implode(', ', $r['red']))?></td><td><?=e(implode(', ', $r['blue']))?></td>
     <td class="num"><?=number_format($r['p_red'] * 100, 0)?>%</td><td class="num"><?=number_format($r['pred_red'], 0)?>–<?=number_format($r['pred_blue'], 0)?></td>
     <td class="num"><?=(int)$r['red_score']?>–<?=(int)$r['blue_score']?></td><td class="num"><?=e($epaPick)?></td>
     <td><span class="ptr-<?=e($r['result'] === 'tie' ? 'note' : $r['result'])?>"><?=e(ucfirst($r['result']))?></span></td></tr>
   <?php endforeach; ?>
   </tbody></table></div>
  </details>
  <p class="ptr-note">Computed <?=e(neptune_time(str_replace(['T', '+00:00'], [' ', ''], (string)$result['computed_at'])))?><?=!empty($result['from_cache']) ? ' (cached; refreshes automatically when matches, scouting or prediction settings change)' : ''?>. Predicted scores are compared with official scores with penalties removed when available.</p>
 <?php endif; ?>

 <section class="card" style="margin-top:14px">
  <h2 style="margin-top:0"><i class="fa-solid fa-layer-group"></i> Season roll-up</h2>
  <?php if ($rollupStale): ?><p class="ptr-note"><?=(int)$rollupStale?> event(s) were backtested by an older version and are left out. Use <b>Backtest all events</b> to refresh them.</p><?php endif; ?>
  <?php if (!$rollup): ?><p class="ptr-note">No events have been backtested yet. Use <b>Backtest all events</b>.</p><?php else: ?>
  <p class="ptr-note">Across <?=count($rollup)?> event(s) and <?=(int)$tot['n']?> decided matches: AUGUR picked the winner <b><?=$tot['n'] ? $pct($tot['right'] / $tot['n'] * 100, 1) : 'n/a'?></b>, EPA alone <b><?=$tot['epa_n'] ? $pct($tot['epa_right'] / $tot['epa_n'] * 100, 1) : 'n/a'?></b> on the <?=(int)$tot['epa_n']?> matches where every team had EPA (AUGUR on those same matches: <b><?=$tot['epa_n'] ? $pct($tot['same_right'] / $tot['epa_n'] * 100, 1) : 'n/a'?></b>). Average Brier <?=$tot['n'] ? number_format($tot['brier'] / $tot['n'], 3) : 'n/a'?>. When they disagreed (<?=(int)$tot['dis_n']?>), AUGUR was right <?=(int)$tot['dis_a']?> times and EPA <?=(int)$tot['dis_e']?>.</p>
  <div class="ptr-wrap"><table><thead><tr><th>Event</th><th class="num">Matches</th><th class="num">AUGUR</th><th class="num">EPA alone</th><th class="num">Brier</th><th class="num">In 80% range</th></tr></thead><tbody>
  <?php foreach ($rollup as $x): $r = $x['r']; ?>
   <tr><td><a href="?<?=e(http_build_query(['event_id' => $x['id']]))?>"><?=e($x['name'])?></a></td><td class="num"><?=(int)$r['decided']?></td><td class="num"><?=$pct($r['accuracy'], 1)?></td><td class="num"><?=$pct($r['epa_baseline']['accuracy'] ?? null, 1)?></td><td class="num"><?=number_format((float)$r['brier'], 3)?></td><td class="num"><?=$pct($r['in_range_pct'])?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
 </section>

 <details class="card" style="margin-top:14px"><summary><b>How the track record is calculated</b></summary>
  <p class="ptr-note">For every finished match, AUGUR re-runs the same prediction Match Strategy shows, with the event's saved prediction settings and only data from before that match: earlier scouting, earlier qualification results, and each team's EPA as it stood just before the match. A test that scrambles all later data confirms the earlier predictions do not change. Results are compared with the official score. The <b>Brier score</b> is the average squared difference between the predicted win probability and what happened (0 is perfect, 0.25 is a coin flip). The <b>EPA alone</b> baseline picks the alliance with the higher pre-match EPA sum; when AUGUR and EPA disagree, the tally shows which was right more often, which tells you whether current-event scouting is improving predictions.</p>
 </details>
</section>
<?php include dirname(__DIR__) . '/partials_footer.php'; ?>
