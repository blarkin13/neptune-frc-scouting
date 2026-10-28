<?php
/** Client-reported page location for the assigned QA tester. Never used as proof of scoring. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/admin/_qa_engine.php';
$u=require_login();$org=(int)$u['organization_id'];
header('Cache-Control: private, no-store');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');json_response(['status'=>'error','message'=>'POST only'],405);}
verify_csrf();
$runId=(int)($_POST['run_id']??0);
if($runId<=0)json_response(['status'=>'error','message'=>'Missing run ID'],422);
try{
 $run=nq_load($pdo,$org,$runId,$u);
 if(!$run||(int)$run['tester_user_id']!==(int)$u['id'])json_response(['status'=>'error','message'=>'Not an assigned QA tester'],403);
 $kind=(string)($_POST['page_kind']??'');
 $expected=nq_uses_traditional_scouting((string)$run['scenario'])?'traditional':'tag-match';
 if($kind!==$expected)json_response(['status'=>'error','message'=>'Wrong QA page category'],422);
 $path=(string)($_POST['page_path']??'');
 $safe=explode('?',parse_url($path,PHP_URL_PATH)?:'')[0];
 if(strlen($safe)>200||!str_ends_with($safe,$expected==='traditional'?'/scout/match.php':'/scout/impact.php'))json_response(['status'=>'error','message'=>'Unexpected page path'],422);
 $mid=(int)($_POST['match_id']??0);$robot=(int)($_POST['robot']??0);
 if($mid!==(int)$run['match_id']||$robot!==(int)$run['frc_team_number'])json_response(['status'=>'error','message'=>'This is not the assigned QA match and robot'],422);
 $s=$pdo->prepare('INSERT INTO neptune_qa_navigation (qa_run_id,organization_id,tester_user_id,page_kind,page_path,reported_match_id,reported_robot,last_seen_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE page_kind=VALUES(page_kind),page_path=VALUES(page_path),reported_match_id=VALUES(reported_match_id),reported_robot=VALUES(reported_robot),last_seen_at=VALUES(last_seen_at)');
 $s->execute([$runId,$org,(int)$u['id'],$kind,$safe,$mid,$robot]);
 json_response(['status'=>'success']);
}catch(Throwable $ex){error_log('[Neptune QA page tracking] '.get_class($ex));json_response(['status'=>'error','message'=>'QA page presence unavailable'],503);}
