<?php
/** QA state endpoint: authenticated read-only scouting inspection, no arbitrary SQL. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/admin/_qa_engine.php';
require_once dirname(__DIR__).'/admin/_qa_fixtures.php';
$u=require_login();$org=(int)$u['organization_id'];
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');json_response(['status'=>'error','message'=>'POST only'],405);}
verify_csrf();
$runId=(int)($_POST['run_id']??0);
if($runId<=0)json_response(['status'=>'error','message'=>'A QA run ID is required'],422);
try{
    $run=nq_load($pdo,$org,$runId,$u);
    if(!$run)json_response(['status'=>'error','message'=>'QA run not found or access denied'],404);
    $resetSince=(int)$run['current_run']!==(int)$run['baseline_run'];
    if((string)$run['status']==='abandoned'||($resetSince&&in_array((string)$run['status'],['passed','failed'],true))){
        // An exited test is final: no further verification, auto-actions or checkpoints.
        $stored=json_decode((string)($run['evidence_json']??''),true)?:[];
        $stored['status']=(string)$run['status'];$stored['steps']=$stored['steps']??[];$stored['evidence']=($stored['evidence']??[])+['exit_reason'=>$run['exit_reason']??null];
        json_response(['status'=>'success','qa_run'=>$runId,'scenario'=>$run['scenario'],'result'=>$stored,'checkpoints'=>[],'checked_at'=>gmdate(DATE_ATOM),'transport'=>'database','exited'=>(string)$run['status']==='abandoned','frozen'=>true,'checks'=>nq_check_answers($pdo,$runId)]);
    }
    $check=nq_evaluate($pdo,$run);
    $check=nq_auto_finish($pdo,$org,$run,$check); // admin-level end step is performed automatically
    $status=$check['status'];
    // Only record material database-evidence changes, not every poll or transient
    // page-presence heartbeat. Persistent checkpoints support audit/review.
    $e=$check['evidence'];
    $relevant=['match'=>$e['match']??null,'state'=>$e['current_state']??null,
      'action_ids'=>$e['action_ids']??[],'active_actions'=>$e['active_actions']??null,
      'tag_row_id'=>$e['tag_row_id']??null,'tag_updated_at'=>$e['tag_updated_at']??null,
      'share_pct'=>$e['share_pct']??null,'tags'=>$e['tags']??[],
      'observation_id'=>$e['observation_id']??null,
      'required_sequence_ids'=>$e['required_sequence_ids']??null,
      'unified_tags'=>$e['unified_tags']??null,
      'assignment'=>$e['assignment']??null,'connection_guard'=>$e['connection_guard']??null,'analytics'=>$e['analytics']??null,'data_quality'=>$e['data_quality']??null];
    $hash=hash('sha256',json_encode([$status,array_map(static fn($s)=>[$s['key'],$s['status']],$check['steps']),$relevant]));
    $prior=json_decode((string)($run['evidence_json']??''),true)?:[];
    if(($prior['_evidence_hash']??'')!==$hash){
        $check['_evidence_hash']=$hash;
        $json=json_encode($check,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $pdo->beginTransaction();
        try{
          $q=$pdo->prepare('UPDATE neptune_qa_runs SET status=?,evidence_json=?,completed_at=CASE WHEN ?=\'passed\' THEN COALESCE(completed_at,UTC_TIMESTAMP()) ELSE completed_at END WHERE id=? AND organization_id=?');
          $q->execute([$status,$json,$status,$runId,$org]);
          $q=$pdo->prepare('INSERT IGNORE INTO neptune_qa_checkpoints (organization_id,qa_run_id,evidence_hash,result_status,snapshot_json,checked_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())');
          $q->execute([$org,$runId,$hash,$status,$json]);
          $pdo->commit();
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }
    $q=$pdo->prepare('SELECT result_status,checked_at FROM neptune_qa_checkpoints WHERE qa_run_id=? AND organization_id=? ORDER BY id DESC LIMIT 12');
    $q->execute([$runId,$org]);
    json_response(['status'=>'success','qa_run'=>$runId,'scenario'=>$run['scenario'],'result'=>$check,'checkpoints'=>$q->fetchAll(PDO::FETCH_ASSOC),'checked_at'=>gmdate(DATE_ATOM),'transport'=>'database','checks'=>nq_check_answers($pdo,$runId)]);
}catch(Throwable $ex){error_log('[Neptune QA check] '.$ex->getMessage());json_response(['status'=>'error','message'=>'QA database verification failed. See server error log.'],503);}
