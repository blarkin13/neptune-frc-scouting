<?php
/** SATURN QA: dedicated event fixtures, modular access manifest and assigned evidence-backed tests. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_qa_engine.php';
require_once __DIR__.'/_qa_fixtures.php';
require_once __DIR__.'/_qa_reset.php';
require_once __DIR__.'/_qa_insights.php';
$u=require_role(['owner','admin','strategy']);$org=(int)$u['organization_id'];
nq_install($pdo);
nq_reset_install($pdo);nq_restore_archived_runs($pdo); // also creates the run archive that keeps statistics across resets
$modules=nq_modules();$error='';$success='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['qa_action']??'create_run');
        if($action==='provision_events'){
            if(!in_array((string)$u['role'],['owner','admin'],true))throw new InvalidArgumentException('Only an organization owner or admin may provision QA events.');
            $sourceId=filter_var($_POST['source_event_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$sourceId)throw new InvalidArgumentException('Select a source event.');
            $created=nq_seed_events($pdo,$org,(int)$sourceId);
            $success='QA Test Event 1 (#'.$created[1]['event_id'].') and QA Test Event 2 (#'.$created[2]['event_id'].') are ready. Existing fixtures were not reset.';
        }elseif($action==='reset_qa'){
            if(!in_array((string)$u['role'],['owner','admin'],true))throw new InvalidArgumentException('Only an organization owner or admin may reset QA data.');
            if(trim((string)($_POST['confirm_text']??''))!=='RESET')throw new InvalidArgumentException('Type RESET exactly to confirm. Nothing was changed.');
            $done=nq_reset_execute($pdo,$org,(int)$u['id'],(string)($_POST['reset_scope']??''));
            $total=0;foreach($done['counts'] as $k=>$n){if($k!=='matches_returned_to_ready')$total+=$n;}
            $success='Reset complete: '.$done['matches'].' QA match'.($done['matches']===1?'':'es').' returned to READY and '.$total.' test rows removed. Student feedback was kept.';
        }elseif($action==='review_feedback'){
            if(!nq_manager($u))throw new InvalidArgumentException('QA manager access required.');
            $feedbackId=(int)($_POST['feedback_id']??0);
            $reviewStatus=(string)($_POST['review_status']??'');
            if($feedbackId<=0||!in_array($reviewStatus,['open','reviewing','resolved','wont_fix'],true))throw new InvalidArgumentException('Invalid feedback review.');
            $q=$pdo->prepare('UPDATE neptune_qa_feedback SET review_status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=?');
            $q->execute([$reviewStatus,(int)$u['id'],$feedbackId,$org]);
            if($q->rowCount()!==1)throw new InvalidArgumentException('Feedback not found in your organization.');
            $success='Feedback #'.$feedbackId.' updated.';
        }elseif($action==='create_run'){
            $parts=explode(':',(string)($_POST['qa_target']??''));
            if(count($parts)!==2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]))throw new InvalidArgumentException('Select a QA event match and robot.');
            $runId=nq_create($pdo,$org,(int)$u['id'],(int)($_POST['tester_id']??0),(int)$parts[0],(int)$parts[1],(string)($_POST['scenario']??''));
            header('Location: '.base_url('scout/qa-test.php').'?run_id='.$runId,true,303);exit;
        }else throw new InvalidArgumentException('Unsupported QA action.');
    }catch(InvalidArgumentException|RuntimeException $ex){$error=$ex->getMessage();}
    catch(Throwable $ex){error_log('[Neptune QA manager] '.$ex->getMessage());$error='QA operation failed. Review server logs; no existing match or scout data was reset.';}
}
$qaExisting=nq_qa_events($pdo,$org);
$sources=nq_source_events($pdo,$org);
$q=$pdo->prepare('SELECT id,display_name,role FROM users WHERE organization_id=? AND active=1 ORDER BY display_name,id');$q->execute([$org]);$testers=$q->fetchAll(PDO::FETCH_ASSOC);
$q=$pdo->prepare("SELECT m.id,m.state,m.match_number,m.comp_level,e.name event_name,e.event_code,e.tba_event_key,
 mt.frc_team_number,mt.alliance,mt.station FROM matches m
 JOIN events e ON e.id=m.event_id AND e.organization_id=m.organization_id
 JOIN match_teams mt ON mt.match_id=m.id WHERE m.organization_id=? AND e.name IN ('QA Test Event 1','QA Test Event 2')
 ORDER BY e.name,m.match_number,mt.alliance,mt.station LIMIT 1000");
$q->execute([$org]);$targets=array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),static fn($v)=>nq_qa_slot($v)!==null));
$q=$pdo->prepare('SELECT q.id,q.scenario,q.status,q.exit_reason,q.frc_team_number,q.created_at,q.completed_at,q.tester_finished_at,(SELECT COUNT(*) FROM neptune_qa_responses r WHERE r.qa_run_id=q.id) answers,(SELECT COUNT(*) FROM neptune_qa_feedback f WHERE f.qa_run_id=q.id) reports,e.name event_name,m.comp_level,m.match_number,t.display_name tester FROM neptune_qa_runs q JOIN events e ON e.id=q.event_id AND e.organization_id=q.organization_id JOIN matches m ON m.id=q.match_id AND m.organization_id=q.organization_id JOIN users t ON t.id=q.tester_user_id AND t.organization_id=q.organization_id WHERE q.organization_id=? ORDER BY q.id DESC LIMIT 75');
$q->execute([$org]);$runs=$q->fetchAll(PDO::FETCH_ASSOC);
$fbFilter=(($_GET['fb']??'open')==='all')?'all':'open';
$insights=nq_insights_load($pdo,$org);
$resetMatches=[];$resetPreview=[];
if(in_array((string)$u['role'],['owner','admin'],true)){
    try{$resetMatches=nq_reset_matches($pdo,$org);
        foreach($resetMatches as $i=>$rm){$resetMatches[$i]['counts']=nq_reset_counts($pdo,$org,[$rm]);}
    }catch(Throwable $ex){error_log('[Neptune QA reset preview] '.$ex->getMessage());$resetMatches=[];}
}
$requestedModule=trim((string)($_GET['module']??'traditional'));
$selectedKey=array_key_exists($requestedModule,$modules)?$requestedModule:'traditional';
$selectedModule=$modules[$selectedKey];
$canRun=$selectedModule['mode']==='automated';
$eligibleTesters=array_values(array_filter($testers,static fn($t)=>in_array((string)$t['role'],$selectedModule['roles'],true)));
$slotTargets=array_values(array_filter($targets,static fn($t)=>nq_qa_slot($t)===(int)$selectedModule['slot']));
$qaEventIds=array_map(static fn($ev)=>(int)$ev['id'],$qaExisting);
$eligibleTargets=nq_filter_targets($selectedKey,$slotTargets,nq_evidence_map($pdo,$org,$qaEventIds));
$selectedTitle=$selectedModule['title'];
$pageTitle='QA Center';$moduleName='SATURN';$navigationSection='command';
include dirname(__DIR__).'/partials_header.php';
?>

<?php
/** Renders one page section in isolation: a failure in it shows a clear error card instead of cutting off the rest of the page. */
function nq_safe_section(string $id, callable $render): void {
    $level = ob_get_level(); ob_start();
    try { $render(); echo ob_get_clean(); }
    catch (Throwable $ex) {
        while (ob_get_level() > $level) { ob_end_clean(); }
        error_log('[Neptune QA section ' . $id . '] ' . get_class($ex) . ': ' . $ex->getMessage() . ' @' . basename($ex->getFile()) . ':' . $ex->getLine());
        echo '<section class="qa-section" id="' . e($id) . '" style="margin-top:22px"><div class="card"><div class="notice bad" role="alert"><b>This section could not be displayed.</b> '
            . e(get_class($ex) . ': ' . $ex->getMessage()) . ' (' . e(basename($ex->getFile()) . ':' . $ex->getLine()) . ')</div></div></section>';
    }
}
?>
<style>
.qa-wrap{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:12px}
.qa-columns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.qa-box{border:1px solid var(--line);background:var(--panel);border-radius:12px;padding:14px}
.qa-box h3{margin:0 0 8px}.qa-muted{color:var(--muted);font-size:.86rem;line-height:1.5}
.qa-fields{display:grid;gap:14px}.qa-fields label{display:grid;gap:7px}.qa-fields select{display:block;width:100%;max-width:100%;min-height:43px}
.qa-pill{display:inline-flex;padding:5px 9px;border:1px solid var(--line);border-radius:99px;font-size:.72rem;font-weight:800}
.qa-pill.automated{color:var(--good)}.qa-access{margin-top:10px;padding:10px;border:1px solid var(--line);border-radius:8px;font-size:.85rem;line-height:1.65}
.qa-scroll{overflow:auto}.qa-table{min-width:690px;width:100%;border-collapse:collapse}.qa-table td,.qa-table th{padding:10px;border-bottom:1px solid var(--line);text-align:left}
.qa-module-link{display:block;text-decoration:none!important;color:inherit!important;cursor:pointer;min-height:175px;transition:border-color .15s ease,box-shadow .15s ease}
.qa-module-link:hover,.qa-module-link:focus-visible{border-color:var(--accent,#39bee7);outline:2px solid var(--accent,#39bee7);outline-offset:2px}
.qa-module-link.qa-selected{border-color:var(--accent,#39bee7);box-shadow:0 0 0 1px var(--accent,#39bee7)}
.qa-module-link .qa-module-cta{display:block;margin-top:12px;font-weight:800;color:var(--accent,#39bee7)}
.qa-section{scroll-margin-top:100px}.qa-note{border:1px solid var(--line);padding:12px;border-radius:10px}
.qa-form-action{min-height:46px;touch-action:manipulation;cursor:pointer}
.qa-return{display:flex;flex-wrap:wrap;gap:10px;margin:24px 0 12px}
.qa-tabs{display:flex;flex-wrap:wrap;gap:6px;padding:10px 0;margin:0 0 16px;background:var(--bg,var(--panel));border-bottom:1px solid var(--line)}
.qa-tabs a{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border:1px solid var(--line);border-radius:10px 10px 0 0;text-decoration:none;font-weight:700;font-size:.86rem;color:inherit;background:var(--panel);min-height:42px;box-sizing:border-box}
.qa-tabs a small{color:var(--muted);font-weight:600}
.qa-tabs a:hover{border-color:var(--accent,#39bee7)}
.qa-tabs a[aria-selected="true"]{border-color:var(--accent,#39bee7);box-shadow:inset 0 -3px 0 var(--accent,#39bee7);background:color-mix(in srgb,var(--accent,#39bee7) 10%,var(--panel))}
.qa-tabs a:focus-visible{outline:2px solid var(--accent,#39bee7);outline-offset:2px}
.qa-section[hidden]{display:none!important}
@media(max-width:700px){.qa-tabs{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch}.qa-tabs a{flex:0 0 auto}}
.qa-bar{display:inline-block;flex:1;min-width:90px;max-width:260px;height:10px;border-radius:6px;background:var(--line);overflow:hidden}.qa-bar>span{display:block;height:100%;background:var(--accent,#39bee7)}
.qa-rank{margin:8px 0 0 1.2rem;padding:0;line-height:1.6}.qa-rank li{margin:.35rem 0}
.qa-ans{padding:10px 0;border-top:1px solid var(--line)}.qa-ansrow{display:flex;align-items:center;gap:10px;margin:3px 0}.qa-ansrow>span:first-child{min-width:1.4rem}
details.qa-box summary{cursor:pointer}
@media(max-width:900px){.qa-columns{grid-template-columns:1fr}}
</style>
<section class="module-page" id="neptuneQaCenter">
<header class="module-page-header"><div>
<div class="module-eyebrow"><span>SATURN · QUALITY ENGINEERING</span><small>Database-backed QA events and modular tests</small></div>
<h1>Neptune QA Center</h1>
<p>Choose a test module, see the required access, and assign a real Neptune user. Module selection uses normal links and does not depend on JavaScript.</p>
</div></header>
<?php $isOwnerAdmin=in_array((string)$u['role'],['owner','admin'],true);?>
<nav class="qa-tabs" id="qaTabs" role="tablist" aria-label="QA Center sections">
<a href="#qa-modules" data-tab="qa-modules">1 · Modules</a><a href="#qa-details" data-tab="qa-details">2 · Assign</a><a href="#qa-events" data-tab="qa-events">3 · QA events</a>
<a href="#qa-runs" data-tab="qa-runs">4 · Runs <small>(<?=count($runs)?>)</small></a>
<a href="#qa-feedback" data-tab="qa-feedback">5 · Feedback <small>(<?=count(array_filter($insights['reports'],static fn($f)=>in_array((string)$f['review_status'],['open','reviewing'],true)))?> open)</small></a>
<?php if($isOwnerAdmin):?><a href="#qa-reset" data-tab="qa-reset">6 · Reset</a><?php endif;?>
</nav>
<p class="qa-muted" style="margin-top:-6px">Signed in as <b><?=e($u['display_name']??'you')?></b> (<?=e($u['role'])?>). <?=$isOwnerAdmin?'All six tabs are available to you.':'Tabs 1–5 are shown for your role; tab 6 (Reset) is owner/admin only.'?></p>
<?php if($error):?><div class="notice bad" role="alert"><?=e($error)?></div><?php endif;?>
<?php if($success):?><div class="notice" role="status"><?=e($success)?></div><?php endif;?>
<section class="qa-section" id="qa-modules" aria-labelledby="qa-modules-title">
<h2 id="qa-modules-title">1 · Choose a QA module</h2>
<p class="qa-muted">Select a card to open its access requirements and test assignment. Only modules marked <b>Executable</b> have automated database assertions.</p>
<div class="qa-wrap">
<?php foreach($modules as $key=>$mod):?>
<a class="qa-box qa-module-link <?=($selectedKey===$key)?'qa-selected':''?>" href="<?=e(base_url('admin/qa-center.php'))?>?module=<?=rawurlencode($key)?>#qa-details" <?=($selectedKey===$key)?'aria-current="true"':''?>>
  <span class="qa-pill <?=e($mod['mode'])?>"><?=e($mod['mode']==='automated'?'Executable':'Verifier pending')?></span>
  <span class="qa-pill">QA Event <?=(int)$mod['slot']?></span>
  <h3 style="margin-top:11px"><?=e($mod['title'])?></h3>
  <p class="qa-muted"><?=e($mod['description'])?></p>
  <span class="qa-module-cta"><?=e($mod['mode']==='automated'?'Select and assign module →':'View required access →')?></span>
</a>
<?php endforeach;?>
</div>
</section>
<section class="qa-section" id="qa-details" aria-labelledby="qa-details-title" style="margin-top:24px">
<div class="card">
<h2 id="qa-details-title">2 · <?=e($selectedTitle)?></h2>
<p class="qa-muted"><?=e($selectedModule['description'])?></p>
<div class="qa-access"><div><b>Required tester role:</b> <?=e(implode(' / ',$selectedModule['roles']))?></div>
<div><b>Other required access:</b> <?=e($selectedModule['access'])?></div>
<div><b>Prerequisites:</b> <?=e($selectedModule['requires'])?></div>
<div><b>Target:</b> QA Test Event <?=(int)$selectedModule['slot']?></div>
<div><b>Verifier:</b> <?=e($canRun?'Executable · database assertions available':'Not implemented · cannot run yet')?></div></div>
<?php if(!$canRun):?>
<div class="notice" style="margin-top:14px" role="status">This module is in the QA catalog, but its automated verification has not been implemented. It cannot be assigned as a passing test yet. Choose Traditional Scouting or Match Tag Scouting to run a test now.</div>
<?php else:?>
<div class="qa-columns" style="margin-top:16px">
<div class="qa-box"><h3>Assign this QA module</h3>
<?php if(!$eligibleTargets):?><div class="notice">No QA match exists for Event <?=(int)$selectedModule['slot']?> yet. Create the QA events below before assigning this test.</div><?php endif;?>
<?php if(!$eligibleTesters):?><div class="notice">There are no active tester accounts with the required role. Your organization administrator must provide an eligible account.</div><?php endif;?>
<form id="qaAssign" method="post" action="<?=e(base_url('admin/qa-center.php'))?>?module=<?=rawurlencode($selectedKey)?>#qa-details" class="qa-fields">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input type="hidden" name="qa_action" value="create_run">
<input type="hidden" name="scenario" value="<?=e($selectedKey)?>">
<label>Tester account
<select name="tester_id" required <?=!$eligibleTesters?'disabled':''?>>
<option value="">Choose a tester</option>
<?php foreach($eligibleTesters as $person):?><option value="<?=(int)$person['id']?>" <?=(int)$person['id']===(int)$u['id']?'selected':''?>><?=e($person['display_name'].' · '.$person['role'])?></option><?php endforeach;?>
</select></label>
<label>QA match and robot
<select name="qa_target" required <?=!$eligibleTargets?'disabled':''?>>
<option value="">Choose a QA match and assigned robot</option>
<?php foreach($eligibleTargets as $target):?><option value="<?=(int)$target['id'].':'.(int)$target['frc_team_number']?>"><?=e($target['event_name'].' · '.strtoupper($target['comp_level']).' '.$target['match_number'].' · #'.$target['frc_team_number'].' · '.$target['alliance'].' '.$target['station'].' · '.$target['state'])?></option><?php endforeach;?>
</select></label>
<button class="qa-form-action" type="submit" <?=(!$eligibleTesters||!$eligibleTargets)?'disabled':''?>>Create assigned QA run</button>
</form>
</div>
<div class="qa-box"><h3>What happens next</h3>
<?php $next=[
 'traditional'=>'The assigned tester presses Start, joins their station, records a successful offense, a failed offense, defense if available, and an undo. Neptune ends the QA match automatically, then checks the real session and scouting-action rows.',
 'tag'=>'The assigned tester opens Match Tag Scouting, changes their scoring contribution, selects Scoring and Attempted Defense, and saves. Neptune checks the tester-owned Tag report and linked observation.',
 'connection_guard'=>'The tester uses the device being tested and keeps the QA page open (it reports the device\'s Connection Guard ledger). They press Start, scout 3 actions online, turn Wi-Fi off and scout 3 more, then turn Wi-Fi back on. Neptune checks every action the device sent was stored exactly once (none lost, duplicated or sent around the guard), then ends the QA match. Pick a READY match.',
 'assignment'=>'Neptune creates the station assignment and notification for the tester automatically. The tester acknowledges it in My Account, scouts the match, and Neptune ends the match and checks the assignment closed by itself, plus the audit trail and live-monitor evidence. Pick a READY match.',
 'analytics'=>'Neptune sets a QA alliance score if a Tag estimate needs one, runs its real analytics code, and compares it to the raw records (totals, Tag fill-in only where Traditional is missing, no double counting, points per match). The tester then reports what the Robot Stats Table shows. Pick a match that already has scouting.',
 'data_quality'=>'The tester opens the “Incomplete match” finding created by a real finished QA match, saves Needs follow-up with a note, then resolves it. Neptune verifies the audit trail and that scouting data was not changed by the review. Pick an ENDED match where only some robots were scouted.',
][$selectedKey]??'';?>
<p class="qa-muted"><?=e($next)?></p>
<p class="qa-muted">Creating a run opens the step-by-step tester page. This does not change anyone's Neptune permissions.</p>
</div>
</div>
<?php endif;?>
</div></section>
<section class="qa-section" id="qa-events" style="margin-top:22px"><div class="card">
<h2>3 · QA Test Events 1 and 2</h2>
<p class="qa-muted">Both events are separate from official FIRST/TBA events. They reuse a published game and real team assignments, but start with no copied scoring or scouting reports.</p>
<div class="qa-wrap" style="margin-top:12px">
<?php foreach([1,2] as $slot):$found=null;foreach($qaExisting as $ex){if($ex['name']==='QA Test Event '.$slot && $ex['event_code']==='NEPTUNE_QA_'.$slot && empty($ex['tba_event_key'])){$found=$ex;break;}}?>
<div class="qa-box"><h3>QA Test Event <?=$slot?></h3>
<?php if($found):?><span class="qa-pill automated">Created · #<?=(int)$found['id']?></span><p class="qa-muted"><?=(int)$found['match_count']?> qualification matches</p>
<?php else:?><span class="qa-pill">Not provisioned</span><?php endif;?></div>
<?php endforeach;?></div>
<?php if(in_array((string)$u['role'],['owner','admin'],true)):?>
<form method="post" action="<?=e(base_url('admin/qa-center.php'))?>?module=<?=rawurlencode($selectedKey)?>#qa-events" class="qa-fields" style="margin-top:15px">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input type="hidden" name="qa_action" value="provision_events">
<label>Published-game fixture source
<select name="source_event_id" required <?=!$sources?'disabled':''?>>
<option value="">Choose a source event</option>
<?php foreach($sources as $source):?><option value="<?=(int)$source['id']?>" <?=($source['name']==='Test Event')?'selected':''?>><?=e($source['name'].' · '.$source['game_name'].' '.$source['season_year'].' · '.$source['complete_quals'].' complete matches')?></option><?php endforeach;?>
</select></label>
<button class="qa-form-action" type="submit" <?=!$sources?'disabled':''?>>Create / verify QA Test Event 1 and 2</button>
</form>
<?php if(!$sources):?><div class="notice">No source event with a published game revision and complete six-robot qualification match is available. Provisioning is disabled until a suitable source exists.</div><?php endif;?>
<?php else:?><p class="qa-muted">Only an organization owner or admin can create the QA events.</p><?php endif;?>
<p class="qa-muted">Provisioning is additive: existing QA matches and test data aren't reset. QA match controls use virtual fields and do not switch Neptune's active competition event.</p>
</div></section>
<?php nq_safe_section('qa-runs',function() use($runs,$modules){?>
<section class="qa-section" id="qa-runs" style="margin-top:22px"><div class="card">
<h2>4 · Assigned QA runs</h2>
<?php if(!$runs):?><p class="qa-muted">No QA runs have been created. Choose an executable module above to assign the first one.</p><?php else:?>
<?php $statusLabel=['open'=>'Not started','in_progress'=>'In progress','passed'=>'PASSED','failed'=>'FAILED','abandoned'=>'Exited'];$exitLabels=nq_exit_reasons();?>
<div class="qa-scroll"><table class="qa-table"><thead><tr><th>Run</th><th>Module</th><th>Tester</th><th>Target</th><th>Result</th><th>Debrief / reports</th><th>Created UTC</th></tr></thead><tbody>
<?php foreach($runs as $r):?><tr><td><a href="<?=e(base_url('scout/qa-test.php'))?>?run_id=<?=(int)$r['id']?>">#<?=(int)$r['id']?></a></td>
<td><?=e($modules[$r['scenario']]['title']??$r['scenario'])?></td><td><?=e($r['tester'])?></td>
<td><?=e($r['event_name'].' · '.$r['comp_level'].' '.$r['match_number'].' · #'.$r['frc_team_number'])?></td>
<td><?=e($statusLabel[$r['status']]??$r['status'])?><?php if(!empty($r['tester_finished_at'])):?> <small class="qa-muted">· tester finished</small><?php endif;?><?php if($r['status']==='abandoned'&&$r['exit_reason']):?><div class="qa-muted"><small><?=e($exitLabels[$r['exit_reason']]??$r['exit_reason'])?></small></div><?php endif;?></td>
<td><?=(int)$r['answers']?> answer<?=(int)$r['answers']===1?'':'s'?> · <?=(int)$r['reports']?> report<?=(int)$r['reports']===1?'':'s'?></td><td><?=e($r['created_at'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php endif;?></div></section>
<?php });?>
<?php nq_safe_section('qa-feedback',function() use($insights,$fbFilter,$selectedKey){?>
<section class="qa-section" id="qa-feedback" style="margin-top:22px"><div class="card">
<h2>5 · Tester feedback &amp; what to fix first</h2>
<?php nq_insights_render($insights,base_url('admin/qa-center.php'),$fbFilter,csrf_token(),$selectedKey);?>
</div></section>
<?php });?>
<?php if(in_array((string)$u['role'],['owner','admin'],true)):?>
<?php nq_safe_section('qa-reset',function() use($resetMatches,$selectedKey){?>
<section class="qa-section" id="qa-reset" style="margin-top:22px"><div class="card">
<h2>6 · Reset QA test data</h2>
<p class="qa-muted">Removes the scouting actions, scout sessions, Tag reports, assignments and derived ratings for the dedicated QA matches and returns them to READY so they can be tested again. <b>Test runs, their results and evidence, debrief answers and problem reports are all kept.</b> A run that had not finished yet is closed as “reset”. <b>It can never touch a real event:</b> only matches in QA Test Event 1 and 2 on their isolated QA fields are accepted. Every reset is logged.</p>
<?php if(!$resetMatches):?><p class="qa-muted">No QA matches exist yet. Provision the QA events first.</p><?php else:?>
<div class="qa-scroll"><table class="qa-table"><thead><tr><th>QA match</th><th>State</th><th>Run</th><th>Data that would be removed</th></tr></thead><tbody>
<?php foreach($resetMatches as $rm):$parts=[];foreach($rm['counts'] as $t=>$n){$parts[]=$t.' '.$n;}?>
<tr><td><?=e($rm['event_name'].' · match '.$rm['match_number'])?></td><td><?=e($rm['state'])?></td><td><?=(int)$rm['run_number']?></td><td><?=$parts?e(implode(' · ',$parts)):'<span class="qa-muted">nothing yet</span>'?></td></tr>
<?php endforeach;?></tbody></table></div>
<form method="post" action="<?=e(base_url('admin/qa-center.php'))?>?module=<?=rawurlencode($selectedKey)?>#qa-reset" class="qa-fields" style="margin-top:15px">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="qa_action" value="reset_qa">
<label>What to reset
<select name="reset_scope" required>
<option value="">Choose…</option>
<option value="all">All QA matches (QA Test Event 1 and 2)</option>
<option value="event:1">All matches in QA Test Event 1</option>
<option value="event:2">All matches in QA Test Event 2</option>
<?php foreach($resetMatches as $rm):?><option value="match:<?=(int)$rm['id']?>"><?=e($rm['event_name'].' · match '.$rm['match_number'].' ('.$rm['state'].')')?></option><?php endforeach;?>
</select></label>
<label>Type <b>RESET</b> to confirm
<input type="text" name="confirm_text" autocomplete="off" required pattern="RESET" placeholder="RESET"></label>
<button class="qa-form-action" type="submit">Reset selected QA data</button>
</form>
<p class="qa-muted">A match that is currently running is stopped and reset. Testers with an unfinished run on a reset match will need a new run; finished runs are unaffected. Uploaded Tag photos are not deleted from disk.</p>
<?php endif;?></div></section>
<?php });?>
<?php endif;?>
<div class="qa-return"><a class="btn secondary" href="<?=e(base_url('admin/index.php'))?>">Command Center</a><a class="btn secondary" href="<?=e(base_url('admin/maintenance.php'))?>">Maintenance Console</a></div>
<div class="qa-muted" id="qaPageComplete">QA page output reached the footer include. If the Neptune footer is missing below, this is a rendering issue, not a disabled test module.</div>
</section>
<script>
(function(){
  var root=document.getElementById('neptuneQaCenter'),bar=document.getElementById('qaTabs');
  if(!root||!bar)return;
  var tabs=[].slice.call(bar.querySelectorAll('a[data-tab]')),panels={};
  tabs.forEach(function(t){var p=document.getElementById(t.dataset.tab);if(p){panels[t.dataset.tab]=p;t.id='tab-'+t.dataset.tab;t.setAttribute('role','tab');t.setAttribute('aria-controls',t.dataset.tab);p.setAttribute('role','tabpanel');p.setAttribute('aria-labelledby',t.id);}});
  tabs=tabs.filter(function(t){return panels[t.dataset.tab];});
  if(!tabs.length)return;
  var params=new URLSearchParams(location.search);
  function show(id,focus,scroll){
    if(!panels[id])id=tabs[0].dataset.tab;
    tabs.forEach(function(t){var on=t.dataset.tab===id;t.setAttribute('aria-selected',on?'true':'false');t.tabIndex=on?0:-1;if(on&&focus)t.focus();});
    Object.keys(panels).forEach(function(k){panels[k].hidden=(k!==id);});
    if(scroll){var r=bar.getBoundingClientRect();if(r.top<0||r.top>window.innerHeight*0.6)window.scrollTo({top:Math.max(0,window.scrollY+r.top-8),behavior:'auto'});}
    return id;
  }
  function fromLocation(){
    var h=(location.hash||'').replace('#','');
    if(panels[h])return h;
    // links that point at something inside a hidden tab (e.g. #finding-…) open the tab that contains it
    if(h){var el=document.getElementById(h);if(el){for(var k in panels){if(panels[k].contains(el))return k;}}}
    if(params.has('fb')&&panels['qa-feedback'])return 'qa-feedback';
    return tabs[0].dataset.tab;
  }
  root.classList.add('qa-tabs-on');
  show(fromLocation(),false,false);
  tabs.forEach(function(t){t.addEventListener('click',function(e){
    e.preventDefault();var id=show(t.dataset.tab,false,true);
    try{history.replaceState(null,'',location.pathname+location.search+'#'+id);}catch(_){location.hash=id;}
  });});
  bar.addEventListener('keydown',function(e){
    var i=tabs.indexOf(document.activeElement);if(i<0)return;
    var n=e.key==='ArrowRight'?(i+1)%tabs.length:e.key==='ArrowLeft'?(i-1+tabs.length)%tabs.length:e.key==='Home'?0:e.key==='End'?tabs.length-1:-1;
    if(n<0)return;e.preventDefault();var id=show(tabs[n].dataset.tab,true,false);
    try{history.replaceState(null,'',location.pathname+location.search+'#'+id);}catch(_){}
  });
  window.addEventListener('hashchange',function(){show(fromLocation(),false,true);});
})();
</script>
<?php include __DIR__.'/_qa_nav_fallback.php'; ?>
<?php
// Capture footer output to detect a PHP render failure without modifying the shared footer.
$qaObLevel=ob_get_level(); ob_start(); $qaFooter=''; $qaFooterError=null;
try {
  include dirname(__DIR__).'/partials_footer.php';
  $qaFooter=(string)ob_get_clean();
} catch (Throwable $ex) {
  if(ob_get_level()>$qaObLevel)ob_end_clean();
  error_log('[Neptune QA footer render] '.$ex->getMessage());
  $qaFooterError=$ex;
}
if(!str_contains($qaFooter,'neptune-site-footer')){
  error_log('[Neptune QA footer] Expected shared site footer markup was not produced');
  echo '<footer class="neptune-site-footer" style="position:relative;margin:16px auto;padding:16px;max-width:1100px;border-top:1px solid var(--line)"><div><strong>Neptune QA Center</strong> · Shared footer unavailable. <a href="'.e(base_url('dashboard/index.php')).'">Home</a> · <a href="'.e(base_url('admin/index.php')).'">Command Center</a></div></footer>';
}
echo $qaFooter;
?>
