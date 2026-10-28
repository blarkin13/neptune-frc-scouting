<?php
/** Guided tester workspace; normal Neptune authentication and role boundaries. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/admin/_qa_engine.php';
require_once dirname(__DIR__).'/admin/_qa_fixtures.php';
$u=require_login();$org=(int)$u['organization_id'];
nq_install($pdo); // Additive QA-only metadata; never alters live scouting/event records.
$runId=(int)($_GET['run_id']??0);
try{$run=nq_load($pdo,$org,$runId,$u);}catch(Throwable $e){error_log('[Neptune QA page] '.$e->getMessage());$run=null;}
if(!$run){http_response_code(404);exit('QA run not found or access denied.');}
$controlError='';$controlSuccess='';
$qaAjax=(($_POST['qa_ajax']??'')==='1');
$qaJson=static function(int $code,array $body):void{http_response_code($code);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($body);exit;};
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    verify_csrf();
    $qaIsAdmin=in_array((string)($u['role']??''),['owner','admin'],true);
    $qaIsTester=(int)$run['tester_user_id']===(int)$u['id'];
    if((string)($_POST['qa_action']??'')==='exit'){
        // The assigned tester or any QA manager may leave a test; nothing the tester recorded is deleted.
        try{
            nq_exit($pdo,$org,$run,(int)$u['id'],(string)($_POST['reason']??''),(string)($_POST['note']??''));
            $qaJson(200,['status'=>'success','op'=>'exit']);
        }catch(InvalidArgumentException|RuntimeException $ex){$qaJson(422,['status'=>'error','message'=>$ex->getMessage()]);}
        catch(Throwable $ex){error_log('[Neptune QA exit] '.$ex->getMessage());$qaJson(500,['status'=>'error','message'=>'Could not exit the test. See the server log.']);}
    }
    if((string)($_POST['qa_action']??'')==='finish'){
        // The tester closes the test out once it has a final result (passed, failed or exited).
        if(!$qaIsTester&&!nq_manager($u))$qaJson(403,['status'=>'error','message'=>'Only the assigned tester can finish this test.']);
        if(!in_array((string)$run['status'],['passed','failed','abandoned'],true))$qaJson(422,['status'=>'error','message'=>'The tasks are not finished yet. Complete the remaining steps, or choose Exit this test if you cannot continue.']);
        try{
            $pdo->prepare('UPDATE neptune_qa_runs SET tester_finished_at=COALESCE(tester_finished_at,UTC_TIMESTAMP()) WHERE id=? AND organization_id=?')->execute([$runId,$org]);
            $qaJson(200,['status'=>'success','op'=>'finish']);
        }catch(Throwable $ex){error_log('[Neptune QA finish] '.$ex->getMessage());$qaJson(500,['status'=>'error','message'=>'Could not finish the test. See the server log.']);}
    }
    // Admin-level QA steps are automatic: the assigned tester may start their own isolated QA match.
    // Ending is done by the system once the other steps pass (or by an owner/admin).
    if(!$qaIsAdmin&&!($qaIsTester&&(string)($_POST['qa_match_op']??'')==='start')){
        http_response_code(403);exit('This QA match action requires the assigned tester (start) or an owner/admin.');
    }
    try{
        $operation=(string)($_POST['qa_match_op']??'');
        nq_control_match($pdo,$org,(int)$run['match_id'],(int)$run['baseline_run'],$operation);
        if($qaAjax)$qaJson(200,['status'=>'success','op'=>$operation]);
        header('Location: '.base_url('scout/qa-test.php').'?run_id='.$runId.'&qa_control='.rawurlencode($operation),true,303);exit;
    }catch(InvalidArgumentException|RuntimeException $ex){$controlError=$ex->getMessage();}
    catch(Throwable $ex){error_log('[QA isolated match control] '.$ex->getMessage());$controlError='QA match control failed; consult the server logs.';}
    if($qaAjax&&$controlError!=='')$qaJson(422,['status'=>'error','message'=>$controlError]);
}
if(isset($_GET['qa_control']) && in_array((string)$_GET['qa_control'],['start','end'],true))$controlSuccess='QA match operation recorded; evidence checks will update below.';

$scenario=(string)$run['scenario'];
$module=nq_module_for_run($scenario);
$tradStyle=nq_uses_traditional_scouting($scenario);
$isTester=(int)$run['tester_user_id']===(int)$u['id'];
$exited=(string)$run['status']==='abandoned';
$resetSince=(int)$run['current_run']!==(int)$run['baseline_run'];
$finished=in_array((string)$run['status'],['passed','abandoned'],true)||($resetSince&&(string)$run['status']==='failed');
$closedOut=!empty($run['tester_finished_at']);
$resultLabel=['passed'=>'PASSED','failed'=>'did not pass','abandoned'=>'exited'][(string)$run['status']]??'in progress';
$scoutPath=$tradStyle
 ? 'scout/match.php?match_id='.(int)$run['match_id'].'&alliance='.rawurlencode((string)$run['alliance']).'&station='.(int)$run['station'].'&qa_run='.$runId
 : 'scout/impact.php?event_id='.(int)$run['event_id'].'&match_id='.(int)$run['match_id'].'&robot='.(int)$run['frc_team_number'].'&qa_run='.$runId;
$statsPath='analytics/robot-stats-table.php?event_id='.(int)$run['event_id'];
$dqKey=substr(hash('sha256','coverage:'.(int)$run['match_id']),0,48);
$dqPath='analytics/data-quality.php?'.http_build_query(['event_id'=>(int)$run['event_id'],'type'=>'coverage','state'=>'all','inspect'=>$dqKey]).'#finding-'.$dqKey;
$accountPath='dashboard/index.php?view=account#account-assignments';
// The primary window each module opens, and any extra helper windows.
$primaryUrl=match($scenario){'analytics'=>base_url($statsPath),'data_quality'=>base_url($dqPath),default=>base_url($scoutPath)};
$primaryLabel=match($scenario){'analytics'=>'Open Robot Stats Table','data_quality'=>'Open Data Quality finding','tag'=>'Open Match Tag Scouting window',default=>'Open Match Scouting window'};
$instructions=match($scenario){
 'traditional'=>'Press <b>Start QA match &amp; open scouting</b>. Join '.e($run['alliance'].' '.$run['station']).', record a successful offense attempt, a failed offense attempt, a defense action if configured, then press Undo on one. Neptune ends the QA match for you when everything else passes.',
 'tag'=>'Open the Tag Scouting form for Team #'.(int)$run['frc_team_number'].'. Change an observation, set a scoring-share percentage, select <b>Scoring</b> and <b>Attempted Defense</b>, and let autosave finish.',
 'connection_guard'=>'Use the device you want to test, and keep <b>this page open</b> in another tab or window of the same browser: it reports the device\'s Connection Guard ledger. 1) Press <b>Start QA match &amp; open scouting</b> and join '.e($run['alliance'].' '.$run['station']).'. 2) Record at least <b>3 actions</b> normally. 3) Turn <b>Wi-Fi off</b> (or airplane mode) and keep scouting: record at least <b>3 more</b>. The badge should say CONNECTION LOST. 4) Turn Wi-Fi back <b>on</b> and wait for SYNCED. Neptune checks every action was stored exactly once, then ends the QA match. Do not use a private window.',
 'assignment'=>'Neptune has already assigned you '.e($run['alliance'].' '.$run['station']).'. 1) Open <b>My Account → Assignments</b> and press <b>Acknowledge</b>. 2) Press <b>Start QA match &amp; open scouting</b> and save at least one action. 3) Neptune ends the match and the assignment should close by itself.',
 'analytics'=>'Open the <b>Robot Stats Table</b> for this QA event and find Team #'.(int)$run['frc_team_number'].'. Look at its <b>Pts/Match</b> value and whether a <b>*</b> (Tag estimate) appears, then answer the Check below. Neptune compares its real analytics code with the raw database records for you.',
 'data_quality'=>'Open the Data Quality finding (button above). It is an <b>Incomplete match</b> finding. First save <b>Needs follow-up</b> with a specific note. Then come back to the same finding and save <b>Reviewed</b> or <b>Dismissed</b> with a reason. Neptune verifies the audit trail and that scouting data did not change.',
 default=>'Follow the steps below.',
};
$questions=nq_question_set($scenario);
$checks=nq_checks($scenario);
$saved=nq_check_answers($pdo,$runId);
$exitReasons=nq_exit_reasons();$exitChoices=nq_exit_reasons_choosable();$bugCats=nq_bug_categories();$impacts=nq_impact_levels();$repeats=nq_repeat_levels();
$pageTitle='QA Run #'.$runId;$moduleName='TRIDENT';$navigationSection='scouting';
include dirname(__DIR__).'/partials_header.php';
?>
<style>
.qa-status{display:inline-block;padding:6px 10px;border-radius:10px;border:1px solid var(--line);font-size:.8rem;font-weight:800}.qa-status.passed{border-color:var(--good);color:var(--good)}.qa-status.failed{border-color:var(--bad);color:var(--bad)}.qa-status.exited{border-color:var(--muted);color:var(--muted)}.qa-grid{display:grid;grid-template-columns:minmax(260px,1.1fr) minmax(260px,.9fr);gap:16px}.qa-test-step{display:grid;grid-template-columns:40px 1fr;gap:10px;align-items:start;padding:13px 0;border-bottom:1px solid var(--line)}.qa-test-step:last-child{border:0}.qa-step-mark{display:grid;place-items:center;width:32px;height:32px;border-radius:50%;background:var(--panel);border:1px solid var(--line);font-weight:800}.qa-test-step.passed .qa-step-mark{color:var(--good);border-color:var(--good)}.qa-test-step.failed .qa-step-mark{color:var(--bad);border-color:var(--bad)}.qa-test-step strong{display:block}.qa-test-step small{display:block;margin-top:5px;color:var(--muted)}.qa-live{display:flex;flex-wrap:wrap;align-items:center;gap:10px}.qa-live span{font-weight:800}.qa-evidence{overflow:auto;max-height:450px;white-space:pre-wrap;word-break:break-word;padding:12px;background:var(--panel);border-radius:12px;border:1px solid var(--line);font-size:.77rem}.qa-run-actions{display:flex;flex-wrap:wrap;gap:10px;margin:14px 0}.qa-steps-list{list-style:none;margin:0;padding:0}@media(max-width:860px){.qa-grid{grid-template-columns:1fr}}
.qa-q{padding:14px 0;border-top:1px solid var(--line)}.qa-q:first-of-type{border-top:0}.qa-q-label{font-weight:700;display:block;margin-bottom:8px}
.qa-scale{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.qa-scale label,.qa-opts label{display:inline-flex;align-items:center;gap:6px;padding:9px 12px;min-height:42px;border:1px solid var(--line);border-radius:10px;cursor:pointer;background:var(--panel)}.qa-scale input,.qa-opts input{margin:0!important;width:18px!important;height:18px!important;min-width:18px!important;max-width:18px!important;flex:0 0 18px!important;padding:0!important;accent-color:var(--accent,#39bee7)}.qa-scale label,.qa-opts label{width:auto!important;max-width:100%;white-space:normal;line-height:1.3;box-sizing:border-box}.qa-opts label{flex:0 1 auto}.qa-scale-ends{display:flex;justify-content:space-between;gap:12px;max-width:300px;font-size:.76rem;color:var(--muted);margin-top:4px}.qa-opts{display:flex;flex-wrap:wrap;gap:8px}
.qa-q textarea,.qa-q select{width:100%;max-width:640px}.qa-fb-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.qa-fb-grid label,.qa-fb-form label{display:grid;gap:6px;font-weight:600}.qa-fb-form{display:grid;gap:14px;max-width:900px}.qa-fb-form textarea,.qa-fb-form input,.qa-fb-form select{width:100%}
.qa-feedback-entry{padding:12px 0;border-top:1px solid var(--line);overflow-wrap:anywhere}.qa-done-cta{border:1px solid var(--accent,#39bee7);border-radius:12px;padding:12px 14px;margin-bottom:14px}.qa-exit-panel{border:1px solid var(--line);border-radius:12px;padding:14px;margin:0 0 14px;display:none;gap:12px;max-width:640px}.qa-exit-panel.open{display:grid}.qa-exit-panel select,.qa-exit-panel textarea{width:100%}
.qa-progress{margin-bottom:16px}.qa-stages{list-style:none;margin:10px 0 12px;padding:0;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.qa-stages li{border:1px solid var(--line);border-radius:12px;padding:10px 12px;display:grid;gap:3px;opacity:.65}.qa-stages li b{font-size:.92rem}.qa-stages li span{font-size:.8rem;color:var(--muted)}.qa-stages li.active{opacity:1;border-color:var(--accent,#39bee7)}.qa-stages li.done{opacity:1;border-color:#3fb67a}.qa-stages li.done b::after{content:" ✓";color:#3fb67a}.qa-stages li.bad{opacity:1;border-color:#e0564f}.qa-next{border-left:4px solid var(--accent,#39bee7);padding:8px 12px;margin:0 0 12px;background:rgba(57,190,231,.07);border-radius:0 10px 10px 0;line-height:1.45}.qa-finish-card{border:1px solid #3fb67a;border-radius:12px;padding:14px 16px;margin-bottom:16px}.qa-finish-card h2{margin:0 0 6px}
@media(max-width:760px){.qa-stages{grid-template-columns:1fr}}
@media(max-width:760px){.qa-fb-grid{grid-template-columns:1fr}}
</style>
<section class="module-page" id="neptuneQaRun">
<header class="module-page-header"><div><div class="module-eyebrow"><span>NEPTUNE QA · <?=e(strtoupper($scenario))?></span><small>Run #<?=$runId?></small></div><h1><?=e($module['title']??$scenario)?> QA</h1><p><?=e($run['event_name'])?> · <?=e(strtoupper((string)$run['comp_level']).' '.(int)$run['match_number'])?> · Team #<?=(int)$run['frc_team_number']?> · <?=e($run['alliance'].' '.$run['station'])?></p><p class="muted">Assigned tester: <?=e($run['tester_name'])?> · Match scouting run <?=e($run['baseline_run'])?></p></div><div><span class="qa-status <?=$exited?'exited':''?>" id="qaResult"><?=$exited?'EXITED':'Waiting for verification'?></span></div></header>
<?php if($exited):?><div class="notice" role="status"><b>This test was exited</b> (<?=e($exitReasons[(string)$run['exit_reason']]??(string)$run['exit_reason'])?>)<?=!empty($run['exit_note'])?' — '.e($run['exit_note']):''?>. Everything recorded was kept. Your debrief below is still welcome. To try again, ask a QA manager to assign a new run (and reset the match if needed).</div>
<?php else:?><div class="notice" style="margin-bottom:16px">Keep this page open for instructions and use the actual Neptune screens in the second window. Do the steps in order; the verifier checks what Neptune really saved, not a simulation. <strong>Only the dedicated QA event is used.</strong> You can leave at any time with <b>Exit this test</b>.</div><?php endif;?>
<?php if($module):?><div class="card" style="margin-bottom:14px"><h2>Required access · <?=e($module['title'])?></h2>
  <p><strong>Tester roles:</strong> <?=e(implode(' / ',$module['roles']))?></p>
  <p><strong>Additional access:</strong> <?=e($module['access'])?></p>
  <p class="muted"><strong>Prerequisites:</strong> <?=e($module['requires'])?></p>
  <p class="muted">Assigned QA Event <?=(int)$module['slot']?> · <?=e($module['scope'])?></p></div><?php endif;?>
<?php if($scenario==='connection_guard' && !$exited):?><div class="notice" role="status" style="margin-bottom:14px"><b>Connection Guard ledger:</b> <span id="qaGuardStatus">Starting…</span></div><?php endif;?>
<?php if($controlError):?><div class="notice bad" role="alert"><?=e($controlError)?></div><?php endif;?>
<?php if($controlSuccess):?><div class="notice" role="status"><?=e($controlSuccess)?></div><?php endif;?>
<?php if(!$exited):?>
<div class="qa-run-actions">
<?php if($tradStyle && nq_qa_slot($run)!==null && $run['match_state']==='ready' && $isTester && !$finished):?>
<button type="button" id="qaStartAndGo" class="good"><i class="fa-solid fa-play"></i> Start QA match &amp; open scouting</button>
<?php endif;?>
<button type="button" class="qa-open" id="qaOpenScout" data-url="<?=e($primaryUrl)?>" data-name="NeptuneQaScout<?=$runId?>"><i class="fa-solid fa-up-right-from-square"></i> <?=e($primaryLabel)?></button>
<?php if($scenario==='assignment'):?><button type="button" class="qa-open secondary" data-url="<?=e(base_url($accountPath))?>" data-name="NeptuneQaAccount<?=$runId?>"><i class="fa-solid fa-user-check"></i> Open My Account (acknowledge)</button><?php endif;?>
<?php if($tradStyle && in_array((string)($u['role']??''),['owner','admin'],true) && nq_qa_slot($run)!==null):?>
<?php if($run['match_state']==='ready'):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button type="submit" name="qa_match_op" value="start" class="good"><i class="fa-solid fa-play"></i> Start QA match only</button></form><?php elseif($run['match_state']==='running'):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button type="submit" name="qa_match_op" value="end" class="secondary"><i class="fa-solid fa-stop"></i> End QA match only</button></form><?php endif;?>
<?php endif;?>
<?php if(nq_manager($u)):?><a class="btn secondary" href="<?=e(base_url('admin/qa-center.php'))?>">QA Center</a><?php endif;?>
<button type="button" class="secondary" id="qaRefresh">Check database now</button>
<?php if(!$finished):?><button type="button" class="secondary" id="qaExitOpen"><i class="fa-solid fa-arrow-right-from-bracket"></i> Exit this test</button><?php endif;?>
</div>
<?php if(!$finished):?>
<div class="qa-exit-panel" id="qaExitPanel" role="group" aria-label="Exit this test">
 <strong>Leave this test?</strong>
 <p class="muted" style="margin:0">Nothing you recorded is deleted. Telling us why helps the team fix the problem. Any QA match this test started is ended<?=$scenario==='assignment'?' and your QA assignment is cancelled':''?>.</p>
 <label>Why are you leaving?<select id="qaExitReason"><option value="">Choose a reason…</option><?php foreach($exitChoices as $k=>$v):?><option value="<?=e($k)?>"><?=e($v)?></option><?php endforeach;?></select></label>
 <label>Anything we should know? (optional, required for “Another reason”)<textarea id="qaExitNote" rows="3" maxlength="500"></textarea></label>
 <div class="qa-run-actions" style="margin:0"><button type="button" id="qaExitConfirm" class="secondary">Exit test</button><button type="button" id="qaExitCancel" class="secondary">Keep testing</button><span id="qaExitStatus" class="muted" role="status" aria-live="polite"></span></div>
</div>
<?php endif;?>
<?php endif;?>
<?php if($isTester):?>
<?php if($closedOut):?>
<div class="qa-finish-card" id="qaFinished" role="status"><h2>✓ You finished this test. Thank you!</h2>
<p>Result: <b><?=e($resultLabel)?></b>. The QA team can see your result, answers and reports. You can still change your answers or add a report below.</p>
<div class="qa-run-actions" style="margin:0"><a class="btn" href="<?=e(base_url('dashboard/index.php'))?>">Back to Neptune</a><?php if(nq_manager($u)):?><a class="btn secondary" href="<?=e(base_url('admin/qa-center.php'))?>">QA Center</a><?php endif;?></div>
<p class="muted" style="margin:10px 0 0;font-size:.85rem">Done with the scouting window? You can close it now. If you want to test again, ask a QA manager to assign you a new run.</p></div>
<?php else:?>
<div class="card qa-progress" id="qaProgress"><h2>Your progress</h2>
<ol class="qa-stages">
 <li id="qaStage1" class="active"><b>1 · Do the tasks</b><span id="qaStage1Text">Checking…</span></li>
 <li id="qaStage2"><b>2 · Answer the questions</b><span id="qaStage2Text">About 2 minutes, further down this page</span></li>
 <li id="qaStage3"><b>3 · Finish</b><span id="qaStage3Text">Unlocks when the tasks have a result</span></li>
</ol>
<div class="qa-next" id="qaNext" aria-live="polite">Checking the database for your first task…</div>
<div class="qa-run-actions" style="margin:0"><button type="button" id="qaFinish" class="good" disabled><i class="fa-solid fa-flag-checkered"></i> Finish test</button><span id="qaFinishStatus" class="muted" role="status" aria-live="polite"></span></div>
<p class="muted" style="font-size:.8rem;margin:8px 0 0">Can’t continue? Use <b>Exit this test</b> above and tell us why. You can still press <b>Finish test</b> afterwards.</p>
</div>
<?php endif;?>
<?php endif;?>
<div class="qa-grid">
 <div class="card"><h2>Guided tasks &amp; live assertions</h2>
 <p class="muted" style="font-size:.86rem"><?=$instructions?></p>
 <ol id="qaSteps" class="qa-steps-list"><li class="qa-test-step"><span class="qa-step-mark">…</span><div>Checking the database…</div></li></ol>
 <div id="qaWarnings" class="muted" style="font-size:.8rem;margin-top:10px"></div></div>
 <div class="card"><h2>Live verifier</h2>
 <div class="qa-live"><span id="qaSocket">MERCURY connecting</span><span>·</span><span id="qaPage">Tester page: awaiting presence</span></div>
 <p class="muted" id="qaLast">The database is checked every five seconds, with additional checks when Neptune sends real-time activity.</p>
 <p class="muted" id="qaWindow">Open the window from the button above to track its exact page locally.</p>
 <h3>Database evidence</h3><pre class="qa-evidence" id="qaEvidence">Awaiting query results…</pre>
 <h3>QA checkpoint history</h3><div id="qaHistory" class="muted" style="font-size:.79rem">No checkpoints yet</div>
 <p class="muted" style="font-size:.78rem">Evidence is read from Neptune's own tables (and its own analytics code for the Analytics module). Page presence is advisory; only stored database values determine the outcome. WebSocket failure falls back to HTTP polling.</p>
 </div>
</div>
<?php if($checks && !$finished && $isTester):?>
<div class="card" style="margin-top:16px" id="qaChecks"><h2>Checks · what do you see?</h2>
<p class="muted">Look at the real Neptune page, then answer. Neptune compares your answer with the database.</p>
<?php foreach($checks as $c):?><div class="qa-q"><span class="qa-q-label"><?=e($c['label'])?></span><div class="qa-opts"><?php foreach($c['options'] as $ov=>$ol):?><label><input type="radio" name="check_<?=e($c['key'])?>" value="<?=e($ov)?>" data-check="<?=e($c['key'])?>" <?=($saved[$c['key']]??'')===$ov?'checked':''?>> <?=e($ol)?></label><?php endforeach;?></div></div><?php endforeach;?>
<div class="qa-run-actions"><button type="button" id="qaSaveChecks">Submit my answer</button><span id="qaChecksStatus" class="muted" role="status" aria-live="polite"></span></div></div>
<?php endif;?>

<?php if($isTester):?>
<div class="card" style="margin-top:16px" id="qaDebrief">
<h2>Tester debrief · help us decide what to fix</h2>
<div id="qaDoneCta" class="qa-done-cta" style="display:<?=$finished?'block':'none'?>"><b><?=$exited?'Thanks for trying.':'Nice work.'?></b> Two minutes on these questions tells the team exactly what to improve. Skip any you can't answer.</div>
<p class="muted">Short, multiple-choice questions. You can answer now, or after you finish, and change answers later.</p>
<form id="qaDebriefForm">
<?php foreach($questions as $q):$k=$q['key'];$val=$saved[$k]??'';?>
<div class="qa-q" data-q="<?=e($k)?>"><span class="qa-q-label"><?=e($q['label'])?></span>
<?php if($q['type']==='scale'):?>
 <div class="qa-scale"><?php for($i=1;$i<=5;$i++):?><label><input type="radio" name="<?=e($k)?>" value="<?=$i?>" <?=$val===(string)$i?'checked':''?>> <?=$i?></label><?php endfor;?></div>
 <div class="qa-scale-ends"><span>1 · <?=e($q['low'])?></span><span>5 · <?=e($q['high'])?></span></div>
<?php elseif($q['type']==='choice'):?>
 <div class="qa-opts"><?php foreach($q['options'] as $ov=>$ol):?><label><input type="radio" name="<?=e($k)?>" value="<?=e($ov)?>" <?=$val===(string)$ov?'checked':''?>> <?=e($ol)?></label><?php endforeach;?></div>
<?php elseif($q['type']==='step'):?>
 <select name="<?=e($k)?>" data-step-select="1" data-saved="<?=e($val)?>"><option value="">Choose…</option><option value="none">No step was a problem</option></select>
<?php else:?>
 <textarea name="<?=e($k)?>" rows="3" maxlength="600" placeholder="Optional"><?=e($val)?></textarea>
<?php endif;?></div>
<?php endforeach;?>
<div class="qa-run-actions"><button type="submit">Save my answers</button><span id="qaDebriefStatus" class="muted" role="status" aria-live="polite"></span></div>
</form></div>
<?php elseif($saved):?>
<div class="card" style="margin-top:16px"><h2>Tester debrief answers</h2><div class="muted">
<?php $idx=nq_question_index($scenario);foreach($saved as $k=>$v):if(!isset($idx[$k]))continue;$qq=$idx[$k];$shown=($qq['type']??'')==='choice'?($qq['options'][$v]??$v):$v;?><p><b><?=e($qq['label'])?></b><br><?=e($shown)?></p><?php endforeach;?></div></div>
<?php endif;?>

<div class="card" style="margin-top:16px" id="qaStudentFeedback">
<h2>Report a problem or suggest an improvement</h2>
<p class="muted">Use this when something is broken, confusing, slow or missing. Specific reports get fixed first. It never changes your pass/fail result. Your device details are attached automatically.</p>
<form id="qaFeedbackForm" class="qa-fb-form">
<div class="qa-fb-grid">
<label>What kind of problem is it?<select name="category" required><option value="">Choose…</option><?php foreach($bugCats as $k=>$v):?><option value="<?=e($k)?>"><?=e($v)?></option><?php endforeach;?></select></label>
<label>Which step was it at?<select name="step_key" id="qaFeedbackStep"><option value="">Overall / no specific step</option></select></label>
</div>
<label>What were you trying to do?<input name="trying_text" maxlength="400" placeholder="e.g. Save my second offense attempt"></label>
<label>What happened instead? <span class="muted">(short summary)</span><input name="summary" required minlength="5" maxlength="190" placeholder="e.g. The action stayed grey and nothing was saved"></label>
<label>What did you expect to happen?<textarea name="expected_text" rows="2" maxlength="600"></textarea></label>
<div class="qa-fb-grid">
<label>How much did it affect you?<select name="impact" required><option value="">Choose…</option><?php foreach($impacts as $k=>$v):?><option value="<?=e($k)?>"><?=e($v['label'])?></option><?php endforeach;?></select></label>
<label>Does it happen every time?<select name="repeatable"><?php foreach($repeats as $k=>$v):?><option value="<?=e($k)?>" <?=$k==='unknown'?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></label>
</div>
<label>Anything else? <span class="muted">(exact error text, what you tapped, ideas)</span><textarea name="details" rows="3" maxlength="3000"></textarea></label>
<div class="qa-run-actions"><button type="submit">Send report</button><span id="qaFeedbackStatus" role="status" class="muted" aria-live="polite"></span></div>
</form>
<h3>Reports for this run</h3><div id="qaFeedbackList" class="muted">No reports yet.</div>
</div>
</section>
<script src="<?=e(base_url('assets/js/neptune-realtime-v2.js'))?>"></script>
<script>
window.NeptuneQaConfig={
 runId:<?=$runId?>,
 matchId:<?=(int)$run['match_id']?>,
 scenario:<?=json_encode($scenario)?>,
 exited:<?=$exited?'true':'false'?>,
 finished:<?=$finished?'true':'false'?>,
 closedOut:<?=$closedOut?'true':'false'?>,
 totalQuestions:<?=count($questions)?>,
 isTester:<?=$isTester?'true':'false'?>,
 csrf:<?=json_encode(csrf_token(),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,
 stateUrl:<?=json_encode(base_url('api/qa-state.php').'?run_id='.$runId,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,
 feedbackUrl:<?=json_encode(base_url('api/qa-feedback.php'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,
 scoutUrl:<?=json_encode($primaryUrl,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,
 pageUrl:<?=json_encode(base_url('scout/qa-test.php').'?run_id='.$runId,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,
 realtimeTokenUrl:<?=json_encode(base_url('api/realtime-token.php'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,
 realtimeUrl:<?=json_encode(base_url('rr-realtime'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,
};
</script>
<script defer src="<?=e(base_url('assets/js/neptune-qa-run.js'))?>?v=6"></script>
<?php if($scenario==='connection_guard' && $isTester && !$exited && !$finished):?>
<script>window.NeptuneQaGuardConfig={runId:<?=$runId?>,csrf:<?=json_encode(csrf_token(),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,reportUrl:<?=json_encode(base_url('api/qa-guard-report.php'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,runCreatedUtc:<?=json_encode(gmdate(DATE_ATOM,(int)strtotime((string)$run['created_at'].' UTC')))?>};</script>
<script defer src="<?=e(base_url('assets/js/neptune-qa-guard.js'))?>?v=1"></script>
<?php endif;?>
<?php include dirname(__DIR__).'/admin/_qa_nav_fallback.php'; ?>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
