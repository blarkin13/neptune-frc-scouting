<?php
/** Connection Guard QA module: the tester's device reports its ledger and queue for this run.
 *  Stored on the QA run only; never touches scouting data. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_json_request_guard.php';neptune_require_request_header();
require_once dirname(__DIR__).'/admin/_qa_engine.php';
$u=require_login();$org=(int)$u['organization_id'];
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');json_response(['status'=>'error','message'=>'POST only'],405);}
verify_csrf();
$runId=(int)($_POST['run_id']??0);
$raw=(string)($_POST['report']??'');
if($runId<=0||$raw==='')json_response(['status'=>'error','message'=>'Run and report are required'],422);
if(strlen($raw)>262144)json_response(['status'=>'error','message'=>'Report too large'],413);
try{
    nq_install($pdo);
    $run=nq_load($pdo,$org,$runId,$u);
    if(!$run||(int)$run['tester_user_id']!==(int)$u['id'])json_response(['status'=>'error','message'=>'Not the assigned QA tester'],403);
    if((string)$run['scenario']!=='connection_guard')json_response(['status'=>'error','message'=>'Not a Connection Guard QA run'],422);
    if(in_array((string)$run['status'],['passed','abandoned'],true))json_response(['status'=>'success','frozen'=>true]);
    $in=json_decode($raw,true);
    if(!is_array($in))json_response(['status'=>'error','message'=>'Report is not valid JSON'],422);
    $uuid='/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    $ledger=[];
    foreach(array_slice((array)($in['ledger']??[]),-1000) as $e){
        if(!is_array($e)||!preg_match($uuid,(string)($e['request_uuid']??''))||strtotime((string)($e['created_at']??''))===false)continue;
        $ledger[]=['request_uuid'=>strtolower((string)$e['request_uuid']),'session_id'=>(int)($e['session_id']??0),
            'created_at'=>gmdate(DATE_ATOM,(int)strtotime((string)$e['created_at'])),'label'=>mb_substr((string)($e['label']??''),0,120)];
    }
    $pending=[];
    foreach(array_slice((array)($in['pending']??[]),0,1000) as $p){
        if(!is_array($p)||!preg_match($uuid,(string)($p['request_uuid']??'')))continue;
        $pending[]=['request_uuid'=>strtolower((string)$p['request_uuid']),'blocked'=>!empty($p['blocked']),'needs_auth'=>!empty($p['needs_auth']),
            'last_error'=>mb_substr((string)($p['last_error']??''),0,200)];
    }
    $report=['received_epoch'=>time(),'device'=>mb_substr((string)($in['device']??''),0,200),'ledger_on'=>!empty($in['ledger_on']),
        'indexeddb'=>!empty($in['indexeddb']),'ledger'=>$ledger,'pending'=>$pending];
    $pdo->prepare('UPDATE neptune_qa_runs SET client_report_json=? WHERE id=? AND organization_id=? AND tester_user_id=?')
        ->execute([json_encode($report,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$runId,$org,(int)$u['id']]);
    json_response(['status'=>'success','ledger'=>count($ledger),'pending'=>count($pending)]);
}catch(Throwable $ex){error_log('[Neptune QA guard report] '.$ex->getMessage());json_response(['status'=>'error','message'=>'Report could not be saved'],503);}
