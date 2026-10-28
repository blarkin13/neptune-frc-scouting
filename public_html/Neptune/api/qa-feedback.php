<?php
/** Structured QA feedback. Assigned testers answer the module debrief and file bug reports; QA managers can also add reports.
 *  Everything is validated against fixed catalogs: no SQL or free-form keys are ever accepted. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/admin/_qa_engine.php';
$u=require_login();$org=(int)$u['organization_id'];
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');json_response(['status'=>'error','message'=>'POST only'],405);}
verify_csrf();
$runId=filter_var($_POST['run_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$runId)json_response(['status'=>'error','message'=>'Invalid QA run'],422);
/** Whitelisted, size-limited browser context the tester's device sends along with a bug report. */
function nq_clean_context(string $raw): array {
    $in=json_decode($raw,true);if(!is_array($in))return [];
    $out=[];
    foreach(['ua'=>240,'screen'=>24,'viewport'=>24,'lang'=>16,'online'=>8,'page'=>200,'touch'=>8,'tz'=>40] as $k=>$max){
        if(isset($in[$k])&&(is_string($in[$k])||is_bool($in[$k])||is_int($in[$k]))){
            $v=is_bool($in[$k])?($in[$k]?'yes':'no'):(string)$in[$k];
            $out[$k]=mb_substr(preg_replace('/[\x00-\x1F\x7F]/u','',$v)??'',0,$max);
        }
    }
    return $out;
}
try{
  nq_install($pdo);
  $run=nq_load($pdo,$org,(int)$runId,$u);
  if(!$run)json_response(['status'=>'error','message'=>'QA run not found or access denied'],404);
  $isTester=(int)$run['tester_user_id']===(int)$u['id'];
  $action=(string)($_POST['action']??'list');
  $stored=json_decode((string)($run['evidence_json']??''),true)?:[];
  $stepKeys=array_map(static fn($s)=>(string)$s['key'],(array)($stored['steps']??[]));
  if($action==='submit'){
    $cats=nq_bug_categories();$impacts=nq_impact_levels();$repeats=nq_repeat_levels();
    $category=(string)($_POST['category']??'');
    $impact=(string)($_POST['impact']??'');
    $repeatable=(string)($_POST['repeatable']??'unknown');
    $stepKey=trim((string)($_POST['step_key']??''));
    $summary=trim((string)($_POST['summary']??''));
    $trying=trim((string)($_POST['trying_text']??''));
    $expected=trim((string)($_POST['expected_text']??''));
    $details=trim((string)($_POST['details']??''));
    if(!isset($cats[$category]))json_response(['status'=>'error','message'=>'Choose what kind of problem this is'],422);
    if(!isset($impacts[$impact]))json_response(['status'=>'error','message'=>'Choose how much it affected you'],422);
    if(!isset($repeats[$repeatable]))$repeatable='unknown';
    if(strlen($stepKey)>48||!preg_match('/^[a-z0-9_-]*$/D',$stepKey))json_response(['status'=>'error','message'=>'Invalid step identifier'],422);
    if(mb_strlen($summary)<5||mb_strlen($summary)>190)json_response(['status'=>'error','message'=>'“What happened” must be 5–190 characters'],422);
    if(mb_strlen($trying)>400||mb_strlen($expected)>600||mb_strlen($details)>3000)json_response(['status'=>'error','message'=>'One of the answers is too long'],422);
    $s=$pdo->prepare('SELECT COUNT(*) FROM neptune_qa_feedback WHERE organization_id=? AND qa_run_id=? AND reporter_user_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 MINUTE');
    $s->execute([$org,(int)$runId,(int)$u['id']]);
    if((int)$s->fetchColumn()>=3)json_response(['status'=>'error','message'=>'Too many reports. Wait one minute before submitting more.'],429);
    $ctx=nq_clean_context((string)($_POST['client_context']??''));
    $ctx['run_status']=(string)$run['status'];$ctx['scenario']=(string)$run['scenario'];
    $ctx['steps']=array_map(static fn($x)=>($x['key']??'').'='.substr((string)($x['status']??''),0,1),(array)($stored['steps']??[]));
    $s=$pdo->prepare('INSERT INTO neptune_qa_feedback (organization_id,qa_run_id,reporter_user_id,step_key,category,severity,impact,repeatable,trying_text,summary,expected_text,details,context_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
    $s->execute([$org,(int)$runId,(int)$u['id'],$stepKey,$category,$impacts[$impact]['severity'],$impact,$repeatable,$trying!==''?$trying:null,$summary,$expected!==''?$expected:null,$details!==''?$details:null,json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
  }elseif($action==='answers'){
    if(!$isTester)json_response(['status'=>'error','message'=>'Only the assigned tester answers the debrief'],403);
    $index=nq_question_index((string)$run['scenario']);
    $given=$_POST['a']??[];
    if(!is_array($given)||!$given||count($given)>40)json_response(['status'=>'error','message'=>'No answers were sent'],422);
    $pdo->beginTransaction();
    try{
      $up=$pdo->prepare('INSERT INTO neptune_qa_responses (organization_id,qa_run_id,user_id,scenario,question_key,answer_value,created_at,updated_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE answer_value=VALUES(answer_value),updated_at=UTC_TIMESTAMP()');
      $del=$pdo->prepare('DELETE FROM neptune_qa_responses WHERE qa_run_id=? AND organization_id=? AND question_key=?');
      foreach($given as $key=>$value){
        $key=(string)$key;
        if(!isset($index[$key])||!is_scalar($value))throw new InvalidArgumentException('Unknown question.');
        if(trim((string)$value)===''){$del->execute([(int)$runId,$org,$key]);continue;}
        $clean=nq_clean_answer($index[$key],(string)$value,$stepKeys);
        $up->execute([$org,(int)$runId,(int)$u['id'],(string)$run['scenario'],$key,$clean]);
      }
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }elseif($action!=='list')json_response(['status'=>'error','message'=>'Unsupported feedback action'],422);
  $s=$pdo->prepare('SELECT f.id,f.step_key,f.category,f.severity,f.impact,f.repeatable,f.trying_text,f.summary,f.expected_text,f.details,f.review_status,f.created_at,u.display_name reporter_name FROM neptune_qa_feedback f JOIN users u ON u.id=f.reporter_user_id AND u.organization_id=f.organization_id WHERE f.organization_id=? AND f.qa_run_id=? ORDER BY f.id DESC LIMIT 40');
  $s->execute([$org,(int)$runId]);
  json_response(['status'=>'success','run_id'=>(int)$runId,'feedback'=>$s->fetchAll(PDO::FETCH_ASSOC),'answers'=>nq_check_answers($pdo,(int)$runId)]);
}catch(InvalidArgumentException $ex){json_response(['status'=>'error','message'=>$ex->getMessage()],422);}
catch(Throwable $ex){error_log('[Neptune QA feedback] '.get_class($ex).': '.$ex->getMessage());json_response(['status'=>'error','message'=>'Feedback could not be saved or loaded'],503);}
