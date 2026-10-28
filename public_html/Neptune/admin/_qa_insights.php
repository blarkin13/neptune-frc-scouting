<?php
/** QA Center section 5: turns tester debriefs, exit reasons and bug reports into a ranked "what to fix first" view.
 *  Read-only aggregation scoped to one organization; archived runs (kept across resets) are included. */
declare(strict_types=1);
require_once __DIR__.'/_qa_engine.php';

function nq_device_label(array $ctx): string {
    $ua = (string)($ctx['ua'] ?? '');
    $dev = 'Unknown device';
    if (preg_match('/iPhone|Android.*Mobile/i', $ua)) { $dev = 'Phone'; }
    elseif (preg_match('/iPad|Tablet|Android/i', $ua)) { $dev = 'Tablet'; }
    elseif (preg_match('/CrOS/i', $ua)) { $dev = 'Chromebook'; }
    elseif (preg_match('/Macintosh|Windows|Linux|X11/i', $ua)) { $dev = 'Computer'; }
    $browser = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Chrome\/|CriOS/', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : '')));
    $vp = (string)($ctx['viewport'] ?? '');
    return trim($dev . ($browser !== '' ? ' · ' . $browser : '') . ($vp !== '' ? ' · ' . $vp : ''));
}
function nq_impact_weight(string $impact, string $severity): int {
    $impact = $impact !== '' ? $impact : match ($severity) { 'blocker' => 'blocked', 'high' => 'workaround', 'medium' => 'slowed', default => 'cosmetic' };
    return ['blocked' => 8, 'workaround' => 5, 'slowed' => 2, 'cosmetic' => 1][$impact] ?? 1;
}
function nq_insights_load(PDO $pdo, int $org): array {
    $modules = nq_modules();
    $out = ['runs' => [], 'exit' => [], 'questions' => [], 'texts' => [], 'groups' => [], 'signals' => [], 'reports' => []];
    // --- runs (live + archived) ---
    $archive = nq_table_exists($pdo, 'neptune_qa_run_archive');
    $sql = 'SELECT scenario,status,exit_reason,created_at,completed_at FROM neptune_qa_runs WHERE organization_id=?';
    $params = [$org];
    if ($archive) { $sql .= ' UNION ALL SELECT a.scenario,a.status,a.exit_reason,a.created_at,a.completed_at FROM neptune_qa_run_archive a WHERE a.organization_id=? AND NOT EXISTS (SELECT 1 FROM neptune_qa_runs r WHERE r.id=a.original_run_id)'; $params[] = $org; }
    $q = $pdo->prepare($sql . ' LIMIT 5000'); $q->execute($params);
    $minutes = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $s = (string)$r['scenario'];
        if ((string)$r['status'] === 'abandoned' && (string)($r['exit_reason'] ?? '') === 'reset') { continue; } // closed by a reset, not a tester decision
        $row = &$out['runs'][$s];
        $row = $row ?? ['total' => 0, 'passed' => 0, 'failed' => 0, 'progress' => 0, 'exited' => 0];
        $row['total']++;
        $st = (string)$r['status'];
        if ($st === 'passed') { $row['passed']++; if ($r['completed_at']) { $minutes[$s][] = max(0, (nq_ts((string)$r['completed_at']) - nq_ts((string)$r['created_at'])) / 60); } }
        elseif ($st === 'failed') { $row['failed']++; }
        elseif ($st === 'abandoned') { $row['exited']++; $reason = (string)($r['exit_reason'] ?? 'other'); $out['exit'][$s][$reason] = ($out['exit'][$s][$reason] ?? 0) + 1; }
        else { $row['progress']++; }
        unset($row);
    }
    foreach ($minutes as $s => $list) { sort($list); $n = count($list); $out['runs'][$s]['median_min'] = $n % 2 ? $list[intdiv($n, 2)] : ($list[$n / 2 - 1] + $list[$n / 2]) / 2; }
    // --- debrief answers ---
    $raw = [];
    if (nq_table_exists($pdo, 'neptune_qa_responses')) {
        $q = $pdo->prepare('SELECT scenario,question_key,answer_value,qa_run_id,updated_at FROM neptune_qa_responses WHERE organization_id=? ORDER BY id DESC LIMIT 20000');
        $q->execute([$org]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $raw[(string)$r['scenario']][(string)$r['question_key']][] = $r; }
    }
    foreach ($raw as $s => $byQ) {
        $index = nq_question_index($s);
        $runsAnswered = [];
        foreach ($byQ as $k => $rows) { foreach ($rows as $r) { $runsAnswered[(int)$r['qa_run_id']] = true; } }
        $out['questions'][$s] = ['debriefs' => count($runsAnswered), 'items' => []];
        foreach ($index as $k => $def) {
            $rows = $byQ[$k] ?? [];
            if (!$rows) { continue; }
            $type = (string)($def['type'] ?? 'scale');
            if ($type === 'text') {
                foreach (array_slice($rows, 0, 12) as $r) { $out['texts'][$s][] = ['label' => $def['label'], 'text' => (string)$r['answer_value'], 'run' => (int)$r['qa_run_id'], 'at' => (string)$r['updated_at']]; }
                continue;
            }
            $counts = [];
            foreach ($rows as $r) { $counts[(string)$r['answer_value']] = ($counts[(string)$r['answer_value']] ?? 0) + 1; }
            $item = ['key' => $k, 'label' => (string)$def['label'], 'type' => $type, 'n' => count($rows), 'counts' => $counts];
            if ($type === 'scale') {
                $sum = 0; foreach ($counts as $v => $n) { $sum += (int)$v * $n; }
                $item['avg'] = $sum / max(1, count($rows));
            } elseif ($type === 'choice') { $item['options'] = (array)$def['options']; }
            $out['questions'][$s]['items'][] = $item;
            // Signals that deserve attention.
            if ($type === 'scale' && $item['avg'] < 3.5 && $item['n'] >= 2) { $out['signals'][] = ['scenario' => $s, 'weight' => (int)round((3.5 - $item['avg']) * 6) + 2, 'text' => 'Low rating (' . number_format($item['avg'], 1) . '/5 from ' . $item['n'] . '): ' . $def['label']]; }
            if ($k === 'c_unaided' && $item['n'] >= 2) { $bad = ($counts['help'] ?? 0) + ($counts['no'] ?? 0); if ($bad / $item['n'] >= 0.25) { $out['signals'][] = ['scenario' => $s, 'weight' => 9, 'text' => $bad . ' of ' . $item['n'] . ' testers could not finish without help']; } }
            if ($k === 'c_trust' && $item['n'] >= 2) { $bad = ($counts['changes'] ?? 0) + ($counts['no'] ?? 0); if ($bad / $item['n'] >= 0.4) { $out['signals'][] = ['scenario' => $s, 'weight' => 8, 'text' => $bad . ' of ' . $item['n'] . ' would not trust it in a real match without changes']; } }
            if ($k === 'c_slowest' && $item['n'] >= 2) {
                arsort($counts); $topKey = (string)array_key_first($counts);
                if ($topKey !== 'none' && $counts[$topKey] >= 2) { $out['signals'][] = ['scenario' => $s, 'weight' => 5 + $counts[$topKey], 'text' => $counts[$topKey] . ' of ' . $item['n'] . ' testers named the “' . str_replace('_', ' ', $topKey) . '” step as the slowest or most frustrating']; }
            }
        }
    }
    foreach ($out['runs'] as $s => $r) {
        if ($r['total'] >= 3 && $r['exited'] / $r['total'] >= 0.25) {
            $top = $out['exit'][$s] ?? []; arsort($top); $why = $top ? nq_exit_reasons()[(string)array_key_first($top)] ?? '' : '';
            $out['signals'][] = ['scenario' => $s, 'weight' => 10, 'text' => $r['exited'] . ' of ' . $r['total'] . ' runs were exited' . ($why !== '' ? ' (most common: “' . $why . '”)' : '')];
        }
        if ($r['total'] >= 3 && $r['failed'] / $r['total'] >= 0.3) { $out['signals'][] = ['scenario' => $s, 'weight' => 8, 'text' => $r['failed'] . ' of ' . $r['total'] . ' runs ended FAILED by the database verifier']; }
    }
    // --- bug reports ---
    $q = $pdo->prepare('SELECT f.id,f.qa_run_id,f.step_key,f.category,f.severity,f.impact,f.repeatable,f.trying_text,f.summary,f.expected_text,f.details,f.context_json,f.review_status,f.created_at,f.reporter_user_id,u.display_name reporter_name,q.scenario run_scenario FROM neptune_qa_feedback f JOIN users u ON u.id=f.reporter_user_id AND u.organization_id=f.organization_id LEFT JOIN neptune_qa_runs q ON q.id=f.qa_run_id AND q.organization_id=f.organization_id WHERE f.organization_id=? ORDER BY f.id DESC LIMIT 300');
    $q->execute([$org]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $ctx = json_decode((string)($f['context_json'] ?? ''), true); $ctx = is_array($ctx) ? $ctx : [];
        $f['scenario'] = (string)($ctx['scenario'] ?? $f['run_scenario'] ?? 'unknown');
        $f['device'] = $ctx ? nq_device_label($ctx) : '';
        $out['reports'][] = $f;
        if (!in_array((string)$f['review_status'], ['open', 'reviewing'], true)) { continue; }
        $g = $f['scenario'] . '|' . (string)$f['step_key'];
        $out['groups'][$g] = $out['groups'][$g] ?? ['scenario' => $f['scenario'], 'step' => (string)$f['step_key'], 'reports' => 0, 'people' => [], 'score' => 0, 'top' => $f['summary'], 'topWeight' => 0];
        $w = nq_impact_weight((string)($f['impact'] ?? ''), (string)$f['severity']);
        $out['groups'][$g]['reports']++; $out['groups'][$g]['people'][(int)$f['reporter_user_id']] = true; $out['groups'][$g]['score'] += $w;
        if ($w > $out['groups'][$g]['topWeight']) { $out['groups'][$g]['topWeight'] = $w; $out['groups'][$g]['top'] = (string)$f['summary']; }
    }
    foreach ($out['groups'] as &$g) { $g['score'] += 3 * count($g['people']); } unset($g);
    uasort($out['groups'], static fn($a, $b) => $b['score'] <=> $a['score']);
    usort($out['signals'], static fn($a, $b) => $b['weight'] <=> $a['weight']);
    return $out;
}
function nq_bar(float $pct): string { $w = max(0, min(100, (int)round($pct))); return '<span class="qa-bar"><span style="width:' . $w . '%"></span></span>'; }
function nq_scenario_title(string $s): string { $m = nq_modules(); return (string)($m[$s]['title'] ?? $s); }
/** Echoes section 5. $base is the page URL for filter links. */
function nq_insights_render(array $d, string $base, string $filter, string $csrf, string $selectedKey): void {
    $impactLabels = array_map(static fn($v) => $v['label'], nq_impact_levels());
    $exitLabels = nq_exit_reasons();
    ?>
<p class="qa-muted">Everything testers tell us, ranked by what is most worth fixing. Closing a report never changes a test's database result. Run counts include runs kept after a reset.</p>

<h3 style="margin-top:18px">What to fix first</h3>
<?php if(!$d['signals'] && !$d['groups']):?><p class="qa-muted">Nothing to rank yet. As testers finish runs, answer the debrief and file reports, the biggest problems will appear here.</p><?php else:?>
<ol class="qa-rank">
<?php foreach(array_slice($d['signals'],0,8) as $sig):?><li><b><?=e(nq_scenario_title($sig['scenario']))?></b> — <?=e($sig['text'])?></li><?php endforeach;?>
<?php foreach(array_slice($d['groups'],0,8,true) as $g):?><li><b><?=e(nq_scenario_title($g['scenario']))?></b><?=$g['step']!==''?' · step “'.e(str_replace('_',' ',$g['step'])).'”':''?> — <?=$g['reports']?> open report<?=$g['reports']===1?'':'s'?> from <?=count($g['people'])?> tester<?=count($g['people'])===1?'':'s'?>; worst: “<?=e($g['top'])?>”</li><?php endforeach;?>
</ol><?php endif;?>

<h3 style="margin-top:18px">Results by module</h3>
<?php if(!$d['runs']):?><p class="qa-muted">No QA runs yet.</p><?php else:?>
<div class="qa-scroll"><table class="qa-table"><thead><tr><th>Module</th><th>Runs</th><th>Passed</th><th>Failed</th><th>In progress</th><th>Exited</th><th>Median time to pass</th><th>Debriefs</th></tr></thead><tbody>
<?php foreach($d['runs'] as $s=>$r):?><tr><td><?=e(nq_scenario_title($s))?></td><td><?=$r['total']?></td><td><?=$r['passed']?> <small class="qa-muted">(<?=$r['total']?round($r['passed']/$r['total']*100):0?>%)</small></td><td><?=$r['failed']?></td><td><?=$r['progress']?></td><td><?=$r['exited']?></td><td><?=isset($r['median_min'])?number_format($r['median_min'],1).' min':'—'?></td><td><?=(int)($d['questions'][$s]['debriefs']??0)?></td></tr><?php endforeach;?></tbody></table></div>
<?php if($d['exit']):?><h3 style="margin-top:18px">Why testers exited</h3>
<ul class="qa-rank"><?php foreach($d['exit'] as $s=>$reasons):arsort($reasons);foreach($reasons as $reason=>$n):?><li><b><?=e(nq_scenario_title($s))?></b> — <?=$n?> × <?=e($exitLabels[$reason]??$reason)?></li><?php endforeach;endforeach;?></ul><?php endif;?>
<?php endif;?>

<h3 style="margin-top:18px">Debrief answers</h3>
<?php if(!$d['questions']):?><p class="qa-muted">No debriefs have been submitted yet.</p><?php else:?>
<?php foreach($d['questions'] as $s=>$qq):if(!$qq['items'])continue;?>
<details class="qa-box" style="margin-top:10px"><summary><b><?=e(nq_scenario_title($s))?></b> · <?=$qq['debriefs']?> debrief<?=$qq['debriefs']===1?'':'s'?></summary>
<?php foreach($qq['items'] as $it):?>
<div class="qa-ans"><div><?=e($it['label'])?> <small class="qa-muted">(<?=$it['n']?> answer<?=$it['n']===1?'':'s'?><?=$it['type']==='scale'?' · average '.number_format($it['avg'],1).'/5':''?>)</small></div>
<?php
    $counts=$it['counts'];
    if($it['type']==='scale'){ for($i=5;$i>=1;$i--){ $n=(int)($counts[(string)$i]??0); echo '<div class="qa-ansrow"><span>'.$i.'</span>'.nq_bar($n/$it['n']*100).'<small>'.$n.'</small></div>'; } }
    else { arsort($counts); foreach($counts as $val=>$n){ $lab=$it['type']==='choice'?($it['options'][$val]??$val):str_replace('_',' ',(string)$val); echo '<div class="qa-ansrow"><span style="min-width:12rem">'.e($lab).'</span>'.nq_bar($n/$it['n']*100).'<small>'.$n.'</small></div>'; } }
?></div>
<?php endforeach;?></details>
<?php endforeach;endif;?>
<?php if($d['texts']):?><h3 style="margin-top:18px">In their own words</h3>
<?php foreach($d['texts'] as $s=>$list):?><div class="qa-box" style="margin-top:10px"><b><?=e(nq_scenario_title($s))?></b>
<?php foreach($list as $t):?><p class="qa-muted" style="margin:.5rem 0"><i><?=e($t['label'])?></i><br><?=e($t['text'])?> <small>(run #<?=$t['run']?>)</small></p><?php endforeach;?></div><?php endforeach;?><?php endif;?>

<h3 style="margin-top:18px">Bug reports and suggestions</h3>
<p class="qa-muted">Show:
<a href="<?=e($base)?>?module=<?=rawurlencode($selectedKey)?>&amp;fb=open#qa-feedback"><?=$filter==='open'?'<b>Open</b>':'Open'?></a> ·
<a href="<?=e($base)?>?module=<?=rawurlencode($selectedKey)?>&amp;fb=all#qa-feedback"><?=$filter==='all'?'<b>All</b>':'All'?></a></p>
<?php $rows=array_values(array_filter($d['reports'],static fn($f)=>$filter==='all'||in_array((string)$f['review_status'],['open','reviewing'],true)));?>
<?php if(!$rows):?><p class="qa-muted"><?=$d['reports']?'No open reports. Switch to “All” to see closed ones.':'No student feedback has been submitted.'?></p><?php else:?>
<div class="qa-scroll"><table class="qa-table"><thead><tr><th>Report / run</th><th>Tester</th><th>Feedback</th><th>Impact</th><th>Review</th></tr></thead><tbody>
<?php foreach(array_slice($rows,0,100) as $f):?><tr><td><b>#<?=(int)$f['id']?></b><div><a href="<?=e(base_url('scout/qa-test.php'))?>?run_id=<?=(int)$f['qa_run_id']?>">Run #<?=(int)$f['qa_run_id']?></a></div><small><?=e($f['created_at'])?> UTC</small></td>
<td><?=e($f['reporter_name'])?><?php if($f['device']!==''):?><div class="qa-muted"><small><?=e($f['device'])?></small></div><?php endif;?></td>
<td><b><?=e($f['summary'])?></b><div class="qa-muted"><?=e(nq_scenario_title((string)$f['scenario']).' · '.($f['category']).($f['step_key']?' · step '.$f['step_key']:''))?></div>
<?php if(!empty($f['trying_text'])):?><p class="qa-muted" style="margin:.3rem 0"><b>Trying to:</b> <?=e($f['trying_text'])?></p><?php endif;?>
<?php if(!empty($f['expected_text'])):?><p class="qa-muted" style="margin:.3rem 0"><b>Expected:</b> <?=e($f['expected_text'])?></p><?php endif;?>
<?php if(!empty($f['details'])):?><p class="qa-muted" style="white-space:pre-wrap;margin:.3rem 0"><?=e($f['details'])?></p><?php endif;?></td>
<td><?=e($impactLabels[(string)($f['impact']??'')]??$f['severity'])?><?php if(!empty($f['repeatable'])):?><div class="qa-muted"><small><?=e(nq_repeat_levels()[(string)$f['repeatable']]??$f['repeatable'])?></small></div><?php endif;?></td>
<td><form method="post" action="<?=e($base)?>?module=<?=rawurlencode($selectedKey)?>&amp;fb=<?=e($filter)?>#qa-feedback">
<input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="qa_action" value="review_feedback"><input type="hidden" name="feedback_id" value="<?=(int)$f['id']?>">
<select name="review_status" aria-label="Review report <?=(int)$f['id']?>">
<?php foreach(['open'=>'Open','reviewing'=>'Reviewing','resolved'=>'Resolved','wont_fix'=>'Not reproducible'] as $k=>$v):?><option value="<?=e($k)?>" <?=$f['review_status']===$k?'selected':''?>><?=e($v)?></option><?php endforeach;?></select>
<button class="secondary" type="submit">Save review</button></form></td></tr><?php endforeach;?></tbody></table></div>
<?php endif;
}
