<?php
declare(strict_types=1);
require_once dirname(__DIR__,4).'/neptune_secure/bootstrap.php';
$u=require_login();require_once __DIR__.'/_training.php';
try{neptune_training_ensure_schema($pdo);}catch(Throwable $e){http_response_code(500);exit('Training database setup failed: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));}
$org=(int)$u['organization_id'];$uid=(int)$u['id'];$courseCode=(string)($_GET['course']??$_POST['course']??'scouter');$course=neptune_training_course($courseCode);
if(!$course){http_response_code(404);exit('Training course not found.');}
if(!neptune_training_role_allowed($u,$course)){http_response_code(403);$accessDeniedUser=$u;$accessDeniedMessage='Your Neptune role is not authorized for this CBT.';include dirname(__DIR__,2).'/errors/403.php';exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$op=(string)($_POST['op']??'');
 try{
  if($op==='start'){$id=neptune_training_create_attempt($pdo,$u,$course);header('Location: '.base_url('help/training/cbt.php?course='.rawurlencode($courseCode).'&attempt='.$id));exit;}
  if($op==='submit'){$id=(int)($_POST['attempt_id']??0);$answers=$_POST['answer']??[];if(!is_array($answers))$answers=[];neptune_training_grade_attempt($pdo,$u,$id,$answers);header('Location: '.base_url('help/training/attempt.php?id='.$id));exit;}
 }catch(Throwable $e){$error=$e->getMessage();}
}
$attemptId=(int)($_GET['attempt']??0);$attempt=null;$qmap=neptune_training_question_map($course);$ids=[];$orders=[];
if($attemptId){$attempt=neptune_training_get_attempt($pdo,$org,$attemptId);if(!$attempt||(int)$attempt['user_id']!==$uid||(string)$attempt['course_code']!==$courseCode){http_response_code(404);exit('Training attempt not found.');}if($attempt['status']!=='in_progress'){header('Location: '.base_url('help/training/attempt.php?id='.$attemptId));exit;}$ids=json_decode((string)$attempt['question_ids_json'],true)?:[];$orders=json_decode((string)$attempt['option_order_json'],true)?:[];}
$pageTitle=$course['short_title'];$moduleName='NEPTUNE';$bodyClass='training-cbt-page';include dirname(__DIR__,2).'/partials_header.php';
$trainingActive='cbt';
require_once dirname(__DIR__).'/_training_media.php';
?>
<style>
.cbt-shell{max-width:1000px;margin:0 auto}.cbt-hero{margin-bottom:16px}.cbt-meta{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.cbt-brief{display:grid;gap:7px;margin:14px 0}.cbt-brief div{display:flex;gap:9px;align-items:flex-start}.cbt-question{margin:14px 0;padding:18px}.cbt-question h3{margin:0 0 13px;line-height:1.4}.cbt-num{display:inline-flex;min-width:32px;height:32px;align-items:center;justify-content:center;border-radius:50%;background:var(--panel2);border:1px solid var(--line);margin-right:8px}.cbt-option{display:flex;gap:12px;align-items:flex-start;border:1px solid var(--line);border-radius:8px;padding:13px 14px;margin:8px 0;background:var(--panel2);cursor:pointer;width:100%;box-sizing:border-box}.cbt-option:hover{border-color:var(--accent)}.cbt-option input[type=radio]{width:18px!important;height:18px!important;min-width:18px!important;max-width:18px!important;flex:0 0 18px!important;margin:2px 0 0!important;padding:0!important}.cbt-option>span{display:block;flex:1 1 auto;min-width:0;width:auto!important;line-height:1.45;overflow-wrap:anywhere}.cbt-submit{position:sticky;bottom:10px;z-index:5;display:flex;justify-content:space-between;align-items:center;gap:12px;box-shadow:0 12px 30px var(--shadow)}@media(max-width:700px){.cbt-submit{align-items:stretch;flex-direction:column}.cbt-submit button{width:100%}}
</style>
<div class="ntp-hub">
<section class="neptune-page-hero cbt-hero"><div class="neptune-hero-kicker"><i class="fa-solid fa-laptop-file"></i> CBT · <?=e(strtoupper($course['module']))?></div><h1><?=e($course['title'])?></h1><p><?=e($course['description'])?></p><div class="neptune-hero-pills cbt-meta"><span class="neptune-hero-pill"><i class="fa-solid fa-shuffle"></i> <?=e($course['question_count'])?> randomized questions</span><span class="neptune-hero-pill"><i class="fa-solid fa-bullseye"></i> <?=e($course['passing_score'])?>% required</span><span class="neptune-hero-pill"><i class="fa-solid fa-rotate"></i> Retakes allowed</span><span class="neptune-hero-pill"><i class="fa-solid fa-floppy-disk"></i> Every grade recorded</span></div><div class="toolbar ntp-hero-actions"><a class="btn secondary" href="<?=e(base_url('help/training/#cbt-courses'))?>"><i class="fa-solid fa-arrow-left"></i> CBT Courses</a><a class="btn secondary" href="<?=e(base_url('help/event-training.php'))?>"><i class="fa-solid fa-book-open"></i> Study Manual</a></div></section>
<?php include dirname(__DIR__).'/_training_nav.php'; ?>
</div>
<div class="cbt-shell">
<section class="card cbt-brief-card"><div class="module-eyebrow"><span>BEFORE YOU BEGIN</span><small>Course briefing</small></div><div class="cbt-brief"><?php foreach($course['briefing'] as $x):?><div><i class="fa-solid fa-circle-check" style="color:var(--good);margin-top:3px"></i><span><?=e($x)?></span></div><?php endforeach;?></div></section>
<?php if(!$attempt): ?>
<section class="card"><div class="module-eyebrow"><span>VISUAL REFRESHER</span><small>Review before testing</small></div><h2>Recognize the screens you will use</h2><p class="muted">These are reference images, not answers to the assessment. Click or tap a screenshot to enlarge it.</p>
<?php if($courseCode==='admin'): ?>
<?php neptune_training_gallery([
 ['game-builder','Verify timing and the live Scout Grid before competition.'],
 ['event-setup','Create and select the correct event.'],
 ['match-control','Coordinate match state and the next match.'],
 ['live-monitor','Watch scout coverage and incoming actions.']
]); ?>
<?php else: ?>
<?php neptune_training_gallery([
 ['live-match-scouting','Confirm match and robot assignment before recording actions.','phone'],
 ['action-swipe','Swipe left for Failure and right for Success.','phone'],
 ['tag-match','Confirm Tag Scouting context and robot.','phone'],
 ['tag-observations','Record only traits you actually observed.','phone']
]); ?>
<?php endif; ?>
</section>
<?php endif; ?>
<?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<?php if(!$attempt):?>
<section class="card"><h2>Assessment rules</h2><p>This is intentionally not a vocabulary quiz. Most questions are event scenarios with plausible wrong answers. Question selection and answer order are randomized for each attempt. There is no penalty for retaking, but every completed attempt remains in the organization training record.</p><p><b>No answer feedback is shown until the attempt is submitted.</b></p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="start"><input type="hidden" name="course" value="<?=e($courseCode)?>"><button type="submit"><i class="fa-solid fa-play"></i> Begin <?=e($course['short_title'])?></button></form></section>
<?php else:?>
<form method="post" id="cbtForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="submit"><input type="hidden" name="course" value="<?=e($courseCode)?>"><input type="hidden" name="attempt_id" value="<?=$attemptId?>">
<?php foreach($ids as $i=>$qid):$qid=(string)$qid;$q=$qmap[$qid]??null;if(!$q)continue;$order=$orders[$qid]??array_keys($q['options']);?>
<section class="card cbt-question" data-question><h3><span class="cbt-num"><?=($i+1)?></span><?=e($q['question'])?></h3><?php foreach($order as $optionId):if(!isset($q['options'][$optionId]))continue;?><label class="cbt-option"><input type="radio" name="answer[<?=e($qid)?>]" value="<?=e($optionId)?>" required><span><?=e($q['options'][$optionId])?></span></label><?php endforeach;?></section>
<?php endforeach;?>
<section class="card cbt-submit"><div><b>Attempt #<?=e($attempt['attempt_number'])?></b><div class="muted" id="answeredCount">0 / <?=e(count($ids))?> answered</div></div><button type="submit"><i class="fa-solid fa-paper-plane"></i> Submit for Grade</button></section></form>
<script>
(()=>{const form=document.getElementById('cbtForm'),out=document.getElementById('answeredCount');if(!form||!out)return;function update(){let n=0;form.querySelectorAll('[data-question]').forEach(q=>{if(q.querySelector('input[type=radio]:checked'))n++});out.textContent=n+' / <?=e(count($ids))?> answered'}form.addEventListener('change',update);form.addEventListener('submit',async e=>{e.preventDefault();const missing=[...form.querySelectorAll('[data-question]')].filter(q=>!q.querySelector('input[type=radio]:checked'));if(missing.length){missing[0].scrollIntoView({behavior:'smooth',block:'center'});return}const ok=await NeptuneUI.confirm('Submit this CBT for grading? You will not be able to change this attempt after submission.',{title:'Submit CBT',confirmText:'Submit for grade'});if(ok)form.submit()});update()})();
</script>
<?php endif;?></div><?php include dirname(__DIR__,2).'/partials_footer.php';?>
