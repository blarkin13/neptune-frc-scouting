<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis-install.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-training-control.php';
header('Cache-Control: private, no-store');
try {
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new CommunityAnalysisError(405,'POST required.');
 community_training_control_schema($pdo);
 $action=(string)($_GET['action']??'');
 if(in_array($action,['connect_start','connect_exchange'],true)) {
  require_once dirname(__DIR__,3).'/neptune_secure/community-training-pairing.php';
  $raw=file_get_contents('php://input',false,null,0,4097);if(strlen($raw)>4096)throw new CommunityAnalysisError(413,'Connection request too large.');
  $p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($p))throw new CommunityAnalysisError(422,'Expected connection details.');
  community_training_pairing_schema($pdo);community_trainers_schema($pdo);
  $settings=community_analysis_settings($pdo,$config);$settings['platform_organization_id']=(int)($config['app']['platform_organization_id']??1);
  if(empty($settings['enabled']))throw new CommunityAnalysisError(404,'Community training is not enabled.');
  $pairing=new CommunityTrainingPairing($pdo,$settings);
  community_training_json_response($action==='connect_start'?$pairing->start($p,(string)($_SERVER['REMOTE_ADDR']??'')):$pairing->exchange($p));
 }

 $headers=function_exists('getallheaders')?getallheaders():[];$authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??$headers['Authorization']??$headers['authorization']??'');if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$m))throw new CommunityAnalysisError(401,'Training computer connection required.');
 $service=new CommunityTrainers($pdo);$computer=$service->authenticate($m[1],(int)($config['app']['platform_organization_id']??1));if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
 $raw=file_get_contents('php://input',false,null,0,4194305);if(strlen($raw)>4194304)throw new CommunityAnalysisError(413,'Training report exceeds 4 MB.');$p=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($p))throw new CommunityAnalysisError(422,'Expected training details.');
 $action=(string)($_GET['action']??'');
 $control=new CommunityTrainingControl($pdo);
 if(in_array($action,['review_list','review_get','review_put'],true)) {
  require_once dirname(__DIR__,3).'/neptune_secure/community-review-storage.php';
  $reviews=new CommunityReviewStorage($pdo);$reviews->schema();
  community_training_json_response($action==='review_list'?$reviews->listing($computer):($action==='review_get'?$reviews->get($computer,$p):$reviews->put($computer,$p)));
 }
 if($action==='runtime_bundle') {
  // Existing owner bearer authorization above applies; never expose private weights in public app archives.
  require_once dirname(__DIR__,3).'/neptune_secure/community-robot-models.php';
  $temp=tempnam(sys_get_temp_dir(),'neptune-native-tools-');if(!$temp)throw new RuntimeException('Cannot prepare robot tools.');chmod($temp,0600);
  try {$zip=new ZipArchive();if($zip->open($temp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot prepare robot tools.');community_robot_models_add($zip,'');$zip->close();$zip=new ZipArchive();$zip->open($temp);$files=[];
   foreach(['manifest.json','best_bumper_model.pt','coco_model.pt'] as $name){$data=$zip->getFromName('robot-models/'.$name);if($data===false||strlen($data)>100*1024*1024)throw new RuntimeException('Robot tools unavailable.');$files[]=['name'=>$name,'sha256'=>hash('sha256',$data),'data'=>base64_encode($data)];}$zip->close();
  } finally {@unlink($temp);}
  community_training_json_response(['files'=>$files]);
 }

 if($action==='page_claim')community_training_json_response($control->claim($computer,is_array($p['hardware']??null)?$p['hardware']:[]));
 if($action==='page_heartbeat')community_training_json_response($control->heartbeat($computer,$p));
 if($action==='page_evaluation_finish')community_training_json_response($control->finishEvaluation($computer,$p));
 if($action==='page_fail')community_training_json_response($control->fail($computer,$p));
 if(str_starts_with($action,'trial_')){require_once dirname(__DIR__,3).'/neptune_secure/community-scouting-trials.php';$trials=new CommunityScoutingTrials($pdo);if($action==='trial_matches')community_training_json_response(['matches'=>$trials->matches((int)($p['season']??0))]);if($action==='trial_start')community_training_json_response($trials->start($computer,$p));if($action==='trial_heartbeat')community_training_json_response($trials->heartbeat($computer,$p));if($action==='trial_finish')community_training_json_response($trials->finish($computer,$p));}
 if($action==='status'){$hardware=is_array($p['hardware']??null)?community_training_hardware($p['hardware']):null;if($hardware!==null){$q=$pdo->prepare('UPDATE community_training_computers SET last_seen=?,hardware_json=? WHERE id=?');$q->execute([time(),json_encode($hardware,JSON_THROW_ON_ERROR),$computer['id']]);}else{$q=$pdo->prepare('UPDATE community_training_computers SET last_seen=? WHERE id=?');$q->execute([time(),$computer['id']]);}community_training_json_response(['ok'=>true]);}
 if($action==='claim')community_training_json_response($service->claim($computer,is_array($p['hardware']??null)?$p['hardware']:[],(string)($p['resume_job']??'')));
 if($action==='heartbeat')community_training_json_response($service->heartbeat($computer,$p));
 if($action==='pause')community_training_json_response($service->pause($computer,$p));
 if($action==='finish')community_training_json_response($service->finish($computer,$p));
 if($action==='fail')community_training_json_response($service->fail($computer,$p));
 throw new CommunityAnalysisError(404,'Unknown training action.');
}catch(CommunityAnalysisError $e){community_training_json_response(['error'=>$e->getMessage()],$e->httpStatus);}catch(JsonException $e){community_training_json_response(['error'=>'Invalid training JSON.'],422);}catch(Throwable $e){error_log('Community training API: '.$e->getMessage());community_training_json_response(['error'=>'Training service unavailable.'],503);}
