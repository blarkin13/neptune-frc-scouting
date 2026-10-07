<?php
declare(strict_types=1);
require_once dirname(__DIR__,4).'/neptune_secure/bootstrap.php';
$u=require_login();
require_once __DIR__.'/_training.php';
$schemaError='';
try{neptune_training_ensure_schema($pdo);}catch(Throwable $e){$schemaError=$e->getMessage();}
$org=(int)$u['organization_id'];$uid=(int)$u['id'];
$courses=neptune_training_courses();
$stats=[];$recent=[];
if($schemaError===''){
  $s=$pdo->prepare("SELECT course_code,COUNT(*) attempts,MAX(score_percent) best_score,MAX(CASE WHEN passed=1 THEN 1 ELSE 0 END) ever_passed,MAX(completed_at) last_completed FROM training_attempts WHERE organization_id=? AND user_id=? AND status='completed' GROUP BY course_code");
  $s->execute([$org,$uid]);foreach($s->fetchAll() as $r)$stats[$r['course_code']]=$r;
  $s=$pdo->prepare("SELECT id,course_code,attempt_number,score_percent,passed,completed_at,duration_seconds FROM training_attempts WHERE organization_id=? AND user_id=? AND status='completed' ORDER BY completed_at DESC,id DESC LIMIT 8");
  $s->execute([$org,$uid]);$recent=$s->fetchAll();
}
$pageTitle='Neptune Training';$moduleName='NEPTUNE';$bodyClass='training-center-page';include dirname(__DIR__,2).'/partials_header.php';
$trainingActive='home';
require_once dirname(__DIR__).'/_training_media.php';
?>
<style>
.training-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}.training-card{display:flex;flex-direction:column;gap:10px}.training-card .big-icon{font-size:1.45rem}.training-status{display:flex;gap:7px;flex-wrap:wrap}.training-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:auto}.training-table td,.training-table th{vertical-align:middle}.training-pass{color:var(--good);font-weight:900}.training-fail{color:var(--bad);font-weight:900}
</style>
<div class="ntp-hub">
<section class="neptune-page-hero">
  <div class="neptune-hero-kicker"><i class="fa-solid fa-graduation-cap"></i> NEPTUNE · TRAINING</div>
  <h1>Learn Neptune your way</h1>
  <p>Start with the quick walkthrough, use the full User Guide when you need a complete feature reference, study the event manual for competition procedures, and finish with role-based CBT certification.</p>
  <div class="neptune-hero-pills" aria-label="Training workflow"><span class="neptune-hero-pill"><i class="fa-solid fa-book-open"></i><strong>1</strong> Learn</span><span class="neptune-hero-pill"><i class="fa-solid fa-dumbbell"></i><strong>2</strong> Practice</span><span class="neptune-hero-pill"><i class="fa-solid fa-certificate"></i><strong>3</strong> Certify</span></div>
</section>
<?php include dirname(__DIR__).'/_training_nav.php'; ?>

<div class="ntp-path-grid">
  <section class="ntp-path"><div class="ntp-path-icon"><i class="fa-solid fa-circle-play"></i></div><h2>Quick Walkthrough</h2><p>A short end-to-end view of how VULCAN, SATURN, TRIDENT, and AUGUR fit together during an FRC event.</p><div class="ntp-path-actions"><button type="button" class="btn" data-neptune-walkthrough-open><i class="fa-solid fa-play"></i> Start Walkthrough</button></div></section>
  <section class="ntp-path"><div class="ntp-path-icon"><i class="fa-solid fa-book-open"></i></div><h2>Full User Guide</h2><p>Use this when you need to understand a feature, permission, setup task, analytics page, troubleshooting step, or full competition-day workflow.</p><div class="ntp-path-actions"><a class="btn" href="<?=e(base_url('help/'))?>"><i class="fa-solid fa-book-open-reader"></i> Open User Guide</a></div></section>
  <section class="ntp-path"><div class="ntp-path-icon"><i class="fa-solid fa-clipboard-list"></i></div><h2>Event Training Manual</h2><p>Role-oriented event operations, scouting standards, strategy procedures, fallback scenarios, quick-reference material, and crew practice guidance.</p><div class="ntp-path-actions"><a class="btn" href="<?=e(base_url('help/event-training.php'))?>"><i class="fa-solid fa-arrow-right"></i> Open Manual</a></div></section>
</div>

<section class="card ntp-section">
  <div class="ntp-section-head"><div><div class="module-eyebrow"><span>RECOMMENDED</span><small>Learning path</small></div><h2>From first login to event-ready</h2></div></div>
  <div class="ntp-flow">
    <div><span>STEP 1</span><b>Understand the system</b><p>Run the Walkthrough to learn the four Neptune subsystems and the competition flow.</p></div>
    <div><span>STEP 2</span><b>Learn your role</b><p>Use the User Guide for features and the Event Manual for procedures that apply to your job.</p></div>
    <div><span>STEP 3</span><b>Practice the workflow</b><p>Run a practice event or match using the same sequence your crew will use at competition.</p></div>
    <div><span>STEP 4</span><b>Complete CBT</b><p>Take the role-based assessment. Neptune records every completed attempt and certification result.</p></div>
  </div>
</section>

<section class="card ntp-section" id="visual-training">
  <div class="ntp-section-head"><div><div class="module-eyebrow"><span>VISUAL TRAINING</span><small>Real Neptune screens</small></div><h2>Learn the workflow by seeing it</h2><p>These examples use the current Neptune interface. Click or tap any screenshot to enlarge it. More screenshots can be added later without changing the training structure.</p></div><a class="btn secondary" href="<?=e(base_url('help/'))?>"><i class="fa-solid fa-book-open"></i> Open Visual User Guide</a></div>
  <?php neptune_training_gallery([
    ['command-center','Start at Command Center to understand where event operations, builders, organization tools, and system tools live.'],
    ['game-builder','Admins use VULCAN to define timing and the action grid scouts will see.'],
    ['event-setup','Create the event and make the correct competition current before scouting begins.'],
    ['pit-auton-path','Pit Scouting can capture structured capabilities, autonomous routes, and robot photos.','phone'],
    ['live-match-scouting','Traditional scouts work from the mobile action grid while following one assigned robot.','phone'],
    ['match-control','SATURN Match Control coordinates the match state used by every live scout.']
  ],'ntp-shot-grid-3'); ?>
</section>

<section class="ntp-section" id="cbt-courses">
  <div class="ntp-section-head"><div><div class="module-eyebrow"><span>CBT</span><small>Role-based certification</small></div><h2>Computer-Based Training</h2><p>Scenario-based assessments use randomized questions and answer order. Retakes are allowed and completed attempts are retained.</p></div><?php if(in_array($u['role'],['owner','admin'],true)):?><a class="btn secondary" href="<?=e(base_url('help/training/records.php'))?>"><i class="fa-solid fa-users"></i> Training Records</a><?php endif;?></div>
  <?php if($schemaError!==''):?><div class="notice bad"><b>Training database could not be initialized.</b><br><?=e($schemaError)?><br><span class="muted">An administrator can run the included sql/Neptune_Training_CBT.sql migration if this database account cannot create tables automatically.</span></div><?php endif;?>
  <div class="training-grid">
  <?php foreach($courses as $code=>$course): if(!neptune_training_role_allowed($u,$course))continue;$st=$stats[$code]??null;?>
   <section class="card training-card">
    <div class="toolbar" style="justify-content:space-between;margin:0"><span class="big-icon"><i class="fa-solid <?=$code==='admin'?'fa-user-shield':'fa-binoculars'?>"></i></span><span class="pill"><?=e($course['question_count'])?> questions · <?=e($course['passing_score'])?>% pass</span></div>
    <div><div class="muted"><?=e($course['module'])?></div><h2 style="margin:4px 0"><?=e($course['title'])?></h2><p class="muted"><?=e($course['description'])?></p></div>
    <div class="training-status">
     <?php if($st):?><span class="pill"><i class="fa-solid fa-rotate"></i> <?=e($st['attempts'])?> attempt<?=((int)$st['attempts']===1?'':'s')?></span><span class="pill"><i class="fa-solid fa-chart-simple"></i> Best <?=e(neptune_training_score_label($st['best_score']))?></span><span class="pill <?=((int)$st['ever_passed']===1?'status-good':'status-bad')?>"><?=((int)$st['ever_passed']===1?'<i class="fa-solid fa-circle-check"></i> Certified':'<i class="fa-solid fa-circle-xmark"></i> Not passed')?></span><?php else:?><span class="pill">Not taken</span><?php endif;?>
    </div>
    <div class="training-actions"><a class="btn" href="<?=e(base_url('help/training/cbt.php?course='.$code))?>"><i class="fa-solid fa-laptop-file"></i> Open CBT</a><a class="btn secondary" href="<?=e(base_url('help/event-training.php'))?>"><i class="fa-solid fa-book-open"></i> Study Manual</a></div>
   </section>
  <?php endforeach;?>
  </div>
</section>

<?php if($recent):?>
<section class="card ntp-section"><div class="ntp-section-head"><div><h2>My recent CBT attempts</h2><p>Your most recent submitted assessments.</p></div></div><div class="table-wrap"><table class="table training-table"><thead><tr><th>Course</th><th>Attempt</th><th>Grade</th><th>Result</th><th>Time</th><th>Completed</th><th></th></tr></thead><tbody><?php foreach($recent as $r):$c=$courses[$r['course_code']]??null;?><tr><td><?=e($c['short_title']??$r['course_code'])?></td><td>#<?=e($r['attempt_number'])?></td><td><b><?=e(neptune_training_score_label($r['score_percent']))?></b></td><td class="<?=((int)$r['passed']===1?'training-pass':'training-fail')?>"><?=((int)$r['passed']===1?'PASS':'NOT PASS')?></td><td><?=e(neptune_training_duration((int)$r['duration_seconds']))?></td><td><?=e($r['completed_at'])?> UTC</td><td><a class="btn secondary" href="<?=e(base_url('help/training/attempt.php?id='.(int)$r['id']))?>">Review</a></td></tr><?php endforeach;?></tbody></table></div></section>
<?php endif;?>
</div>
<?php include dirname(__DIR__,2).'/partials_footer.php';?>
