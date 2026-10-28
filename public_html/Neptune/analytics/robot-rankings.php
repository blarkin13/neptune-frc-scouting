<?php
declare(strict_types=1);
/** Robot Rankings: Driving and Defense ratings built from scouts' after-match rankings. Read-only. */
require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_robot_ranking.php';
$u = require_role(['owner', 'admin', 'strategy']);
$org = (int)$u['organization_id'];
$events = neptune_event_list($pdo, $org);
$eventId = (int)($_GET['event_id'] ?? ($events[0]['id'] ?? 0));
$event = null;
foreach ($events as $ev) if ((int)$ev['id'] === $eventId) { $event = $ev; break; }
if ($eventId > 0 && !$event) { http_response_code(403); exit('Event is not available to your organization.'); }

$tables = []; $names = []; $submissions = 0; $raters = 0;
if ($eventId > 0) {
    foreach (NRR_QUALITIES as $key => $label) $tables[$key] = nrr_ratings($pdo, $org, $eventId, $key);
    $s = $pdo->prepare('SELECT frc_team_number, nickname FROM event_teams WHERE event_id=?');
    $s->execute([$eventId]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $names[(int)$r['frc_team_number']] = (string)$r['nickname'];
    $s = $pdo->prepare('SELECT COUNT(*), COUNT(DISTINCT user_id) FROM robot_rank_submissions WHERE organization_id=? AND event_id=?');
    $s->execute([$org, $eventId]);
    [$submissions, $raters] = array_map('intval', $s->fetch(PDO::FETCH_NUM) ?: [0, 0]);
}
$pageTitle = 'Robot Rankings'; $moduleName = 'AUGUR';
include dirname(__DIR__) . '/partials_header.php';
?>
<style>
.rrk-page .rrk-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px}
.rrk-page table{width:100%;border-collapse:collapse;font-size:.8rem}
.rrk-page th,.rrk-page td{text-align:left;padding:7px 8px;border-bottom:1px solid var(--line)}
.rrk-page th{font-size:.72rem;color:var(--muted);font-weight:800}
.rrk-page .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.rrk-page .rrk-thin td{color:var(--muted)}
.rrk-page .rrk-note{color:var(--muted);font-size:.8rem;line-height:1.5}
.rrk-page .rrk-wrap{overflow-x:auto}
@media(max-width:900px){.rrk-page .rrk-grid{grid-template-columns:1fr}}
</style>
<section class="module-page rrk-page">
 <header class="module-page-header">
  <div><div class="module-code">AUGUR · ROBOT RANKINGS</div>
   <h1>Robot Rankings</h1>
   <p>Driving and Defense ratings from scouts' after-match rankings. Any scout can rank one alliance's robots with the <b>Rank robots</b> button on the scouting pages.</p></div>
  <a class="btn secondary" href="<?=e(base_url('analytics/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Analytics &amp; Strategy</a>
 </header>
 <form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin:12px 0"><label>Event<select name="event_id"><?=neptune_event_options_html($events, $eventId)?></select></label><button class="btn secondary" type="submit">View event</button></form>
 <p class="rrk-note"><?=$submissions?> ranking<?=$submissions === 1 ? '' : 's'?> from <?=$raters?> scout<?=$raters === 1 ? '' : 's'?> at this event.</p>
 <div class="rrk-grid">
 <?php foreach (NRR_QUALITIES as $key => $label): $rows = $tables[$key] ?? []; ?>
  <section class="card">
   <h2 style="margin-top:0"><i class="fa-solid <?=$key === 'driving' ? 'fa-gamepad' : 'fa-shield-halved'?>"></i> <?=e($label)?></h2>
   <?php if (!$rows): ?><p class="rrk-note">No <?=e(strtolower($label))?> rankings yet.</p><?php else: ?>
   <div class="rrk-wrap"><table><thead><tr><th>#</th><th>Team</th><th class="num">Rating</th><th class="num">±</th><th class="num">Comparisons</th><th class="num">Scouts</th></tr></thead><tbody>
   <?php foreach ($rows as $i => $r): $thin = $r['comparisons'] < 4; ?>
    <tr class="<?=$thin ? 'rrk-thin' : ''?>"><td><?=$i + 1?></td><td><b><?=(int)$r['team']?></b> <?=e($names[$r['team']] ?? '')?></td>
     <td class="num"><?=number_format($r['rating'], 0)?></td><td class="num"><?=number_format($r['plus_minus'], 0)?></td>
     <td class="num"><?=(int)$r['comparisons']?></td><td class="num"><?=(int)$r['scouts']?></td></tr>
   <?php endforeach; ?>
   </tbody></table></div>
   <?php endif; ?>
  </section>
 <?php endforeach; ?>
 </div>
 <section class="card" style="margin-top:14px">
  <h2 style="margin-top:0">How to read this</h2>
  <p class="rrk-note">Every ranking becomes head-to-head results: ranking 118 above 6369 on Driving counts as one win for 118; the same place for both counts as a tie. A Bradley-Terry model combines every result at the event into one rating per team. 1500 is average for this event, and a 200-point gap means the higher team is ranked ahead about 3 times in 4. <b>±</b> is one standard error: overlapping ranges are not a real difference. Grey rows have fewer than 4 comparisons. Ratings use all scouts equally and do not feed the score prediction.</p>
 </section>
</section>
<?php include dirname(__DIR__) . '/partials_footer.php';
