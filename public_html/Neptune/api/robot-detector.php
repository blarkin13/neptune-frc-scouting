<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis-install.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-training-control.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-learning.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-training-cancel.php';
require_once dirname(__DIR__,3).'/neptune_secure/tracking-identity.php';
require_once dirname(__DIR__,3).'/neptune_secure/robot-detector-jobs.php';
require_once dirname(__DIR__,3).'/neptune_secure/autoscout-train-overview.php';
require_once dirname(__DIR__,3).'/neptune_secure/external-bumper-training.php';
require_once dirname(__DIR__,3).'/neptune_secure/robot-label-frames.php';
require_once dirname(__DIR__,3).'/neptune_secure/youtube-uploader.php';
require_once dirname(__DIR__,3).'/neptune_secure/score-moments.php';
header('Cache-Control: private, no-store');
// Connected helpers: claim detector requests, report progress, upload pre-fill boxes or an adopted detector,
// and download the current shared detector.
try {
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new CommunityAnalysisError(405,'POST required.');
 $headers=function_exists('getallheaders')?getallheaders():[];$authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??$headers['Authorization']??$headers['authorization']??'');
 if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$m))throw new CommunityAnalysisError(401,'Training computer connection required.');
 $computer=(new CommunityTrainers($pdo))->authenticate($m[1],(int)($config['app']['platform_organization_id']??1));
 $raw=file_get_contents('php://input',false,null,0,64*1024*1024+1);if(strlen($raw)>64*1024*1024)throw new CommunityAnalysisError(413,'Request too large.');
 $p=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
 $jobs=new RobotDetectorJobs(dirname(__DIR__,3).'/neptune_secure',(int)$computer['organization_id']);
 $out=match((string)($p['action']??'')){
  'dashboard'=>(function()use($pdo,$computer,$jobs){
   $control=new CommunityTrainingControl($pdo);$owner=(int)$computer['user_id'];
   $computers=autoscout_computers($pdo,$jobs,$control->computers($owner),$jobs->requests());
   $queue=[];foreach($jobs->requests() as $request)if(in_array($request['state']??'', ['queued','claimed'],true))
    $queue[]=['request'=>$request['id'],'title'=>match($request['kind']??''){'train'=>'Train robot detector','train_field'=>'Train field detector','prepare'=>'Prepare frames','test_robot'=>'Test robot detection',default=>'Training'},'state'=>$request['state']==='claimed'?'running':'waiting','message'=>$request['message']??'','target'=>$request['target']??null,'computer_id'=>$request['computer_id']??null,
     'label'=>match($request['kind']??''){'train_field'=>'2 · Field — field detector','prepare'=>'1 · Robots — prepare frames','test_robot'=>'1 · Robots — test match',default=>'1 · Robots — robot detector'},'started'=>$request['claimed_at']??null];
   $q=$pdo->prepare("SELECT id,state,message,control_json,created_at,started_at FROM community_learning_jobs WHERE season=? AND state IN ('remote_queued','remote_running','queued','running','test_queued','test_running','evaluation_queued','evaluation_running') ORDER BY created_at DESC LIMIT 20");$q->execute([(int)gmdate('Y')]);
   foreach($q as $row){$control=json_decode((string)($row['control_json']??''),true)?:[];$state=(string)$row['state'];$queue[]=['job'=>$row['id'],'title'=>str_starts_with($state,'test_')?'Test a match':(str_starts_with($state,'evaluation_')?'Independent evaluation':'Train action models & scoring'),'state'=>str_ends_with($state,'queued')?'waiting':'running','message'=>$row['message']??'','target'=>$control['computer_id']??null,
     'label'=>str_starts_with($state,'test_')||str_starts_with($state,'evaluation_')?'Test & release':'3 · Actions — action & scoring models','started'=>(int)($row['started_at']??0)?:(isset($row['created_at'])?(int)$row['created_at']:null)];}
   foreach($computers as &$row){$row=array_intersect_key($row,array_flip(['id','name','device','online','status','last_seen','platform','stats','counters','log','reported_at','version','job','waiting','update','form']));$row['log']=array_slice($row['log']??[],-35);}unset($row);
   return ['game'=>RobotDetectorJobs::defaultGame(),'computers'=>$computers,'queue'=>$queue,'generated_at'=>time()];
  })(),
  // A helper's model pages: the owner's Train page overview, trimmed. Queue starts a detector run like the Train page.
  'models'=>(function()use($pdo,$computer){
   $owner=(int)$computer['user_id'];$season=(int)gmdate('Y');$game=(string)(community_learning_games()[(string)$season]['game_key']??'');
   $control=new CommunityTrainingControl($pdo);$names=array_column($control->computers($owner),'name','id');
   return ['season'=>$season]+autoscout_helper_models(autoscout_train_overview($pdo,$control->panel($season,$owner),(int)$computer['organization_id'],$owner,$game))
    +['tracking'=>(new TrackingIdentityShare(dirname(__DIR__,3).'/neptune_secure',(int)$computer['organization_id']))->summary($pdo,(int)$computer['organization_id'],$names)];
  })(),
  'queue_training'=>(function()use($pdo,$computer,$jobs,$p){
   $kind=(string)($p['kind']??'');if(!in_array($kind,['train','train_field'],true))throw new CommunityAnalysisError(422,'Choose a detector.');
   $owner=(int)$computer['user_id'];$control=new CommunityTrainingControl($pdo);$target=(string)($p['computer']??'');
   if($target!==''&&!in_array($target,array_column(array_filter($control->computers($owner),fn($c)=>!$c['revoked']),'id'),true))throw new CommunityAnalysisError(422,'Choose one of your connected computers.');
   $season=(int)gmdate('Y');$game=(string)(community_learning_games()[(string)$season]['game_key']??'');
   $o=autoscout_train_overview($pdo,$control->panel($season,$owner),(int)$computer['organization_id'],$owner,$game);
   try{return ['request'=>$jobs->queue($kind,['computer'=>$target,'game_key'=>$game,'holdout'=>$o[$kind==='train'?'robot':'field']['holdout']],$owner)];}
   catch(InvalidArgumentException $e){throw new CommunityAnalysisError(422,$e->getMessage());}
  })(),
  // Owner's Computers page in a helper: request or cancel a helper update, or switch auto-update, on one of the owner's computers or all of them.
  'update_computer'=>(function()use($pdo,$computer,$jobs,$p){
   $mine=array_column(array_filter((new CommunityTrainingControl($pdo))->computers((int)$computer['user_id']),fn($c)=>!$c['revoked']),'id');$target=(string)($p['computer']??'');
   if($target!=='all'&&!in_array($target,$mine,true))throw new CommunityAnalysisError(422,'Choose one of your connected computers.');
   $change=array_intersect_key($p,array_flip(['update','auto_update']));if(!$change)throw new CommunityAnalysisError(422,'Nothing to change.');
   try{foreach($target==='all'?$mine:[$target] as $id)$jobs->setSettings($id,$change);}catch(InvalidArgumentException $e){throw new CommunityAnalysisError(422,$e->getMessage());}
   return ['ok'=>true];
  })(),
  // Cancel from a helper: a detector run (request), or an Actions run (job) stopped after its current match or cancelled now.
  'cancel_run'=>(function()use($pdo,$computer,$jobs,$p){
   if(isset($p['request'])){try{return $jobs->cancel((string)$p['request']);}catch(InvalidArgumentException $e){throw new CommunityAnalysisError(409,$e->getMessage());}}
   $control=new CommunityTrainingControl($pdo);$job=(string)($p['job']??'');
   return ($p['mode']??'')==='stop'?$control->stop($job,(int)$computer['user_id']):community_training_cancel($pdo,$job,(int)$computer['user_id']);
  })(),
  // Remove an old entry from the owner's computers, as the website's Remove does: only when it is not connected and holds no run.
  'remove_computer'=>(function()use($pdo,$computer,$jobs,$p){
   $id=(string)($p['computer']??'');$c=null;foreach((new CommunityTrainingControl($pdo))->computers((int)$computer['user_id']) as $x)if($x['id']===$id)$c=$x;
   if(!$c)throw new CommunityAnalysisError(422,'Choose one of your computers.');
   if($id===$computer['id']||($c['online']&&!$c['revoked']))throw new CommunityAnalysisError(409,'This computer is connected right now. Close its helper first.');
   $q=$pdo->prepare('SELECT COUNT(*) FROM community_training_leases WHERE computer_id=? AND expires_at>?');$q->execute([$id,time()]);if((int)$q->fetchColumn())throw new CommunityAnalysisError(409,'This computer still holds a run. Cancel the run first.');
   $q=$pdo->prepare('DELETE FROM community_training_computers WHERE id=? AND user_id=?');$q->execute([$id,(int)$computer['user_id']]);$jobs->forget($id);
   return ['ok'=>true];
  })(),
  // Shared robot-identity model: the reviewing helper reports after training; every helper downloads the model in use.
  'tracking_put'=>(new TrackingIdentityShare(dirname(__DIR__,3).'/neptune_secure',(int)$computer['organization_id']))->put($computer,$p),
  'tracking_current'=>(new TrackingIdentityShare(dirname(__DIR__,3).'/neptune_secure',(int)$computer['organization_id']))->current(isset($p['have'])?(string)$p['have']:null),
  'claim'=>['request'=>$jobs->claim($computer)],
  'training_supplement_index'=>['packages'=>$jobs->matchVideoOnly($computer)?[]:(new ExternalBumperTraining(dirname(__DIR__,3).'/neptune_secure',(int)$computer['organization_id']))->index()],
  'training_supplements'=>(function()use($computer,$p){$store=new ExternalBumperTraining(dirname(__DIR__,3).'/neptune_secure',(int)$computer['organization_id']);if(isset($p['id']))return ['datasets'=>[$store->get((string)$p['id'],(string)($p['sha256']??''))]];foreach($store->index() as $item)if($item['id']==='6962-workshop-v1')return ['datasets'=>[$store->get($item['id'],$item['sha256'])]];return ['datasets'=>[]];})(),
  'progress'=>$jobs->progress($computer,$p),
  'finish'=>$jobs->finish($computer,$p),
  'label_frame_put'=>$jobs->putLabelFrame($computer,$p),
  'label_frame_get'=>(function()use($computer,$p){$d=(new RobotLabelFrames(dirname(__DIR__,3).'/neptune_secure',(int)$computer['organization_id']))->get((string)($p['video_id']??''),(int)($p['t_ms']??-1),(string)($p['sha256']??''));if(!$d)throw new InvalidArgumentException('Source frame not found');return ['sha256'=>$d['sha256'],'image_b64'=>base64_encode($d['bytes'])];})(),
  'prefill_put'=>$jobs->putPrefill($computer,$p),
  'current'=>$jobs->current(isset($p['have'])?(string)$p['have']:null),
  'adopt'=>$jobs->adopt($computer,$p),
  'trial_video_put'=>$jobs->putTestVideo($computer,$p,new YouTubeUploader(dirname(__DIR__,3).'/neptune_secure')),
  'computer_poll'=>$jobs->poll($computer,$p),
  'selfcheck_put'=>$jobs->putSelfcheck($computer,$p),
  'score_put'=>(new ScoreMoments(dirname(__DIR__,3).'/neptune_secure',(int)$computer['organization_id']))->put($p),
  'review_put'=>$jobs->putReview($computer,$p,new YouTubeUploader(dirname(__DIR__,3).'/neptune_secure')),
  'robot_test_put'=>$jobs->putRobotTest($computer,$p),
  'current_field'=>$jobs->currentField(isset($p['have'])?(string)$p['have']:null,isset($p['game_key'])?(string)$p['game_key']:null),
  'adopt_field'=>$jobs->adoptField($computer,$p),
  default=>throw new CommunityAnalysisError(422,'Unknown action.'),
 };
 community_training_json_response($out);
}catch(InvalidArgumentException $e){community_training_json_response(['error'=>$e->getMessage()],422);}
catch(CommunityAnalysisError $e){community_training_json_response(['error'=>$e->getMessage()],$e->httpStatus);}
catch(Throwable $e){error_log('Robot detector API: '.$e->getMessage());community_training_json_response(['error'=>'Robot detector service unavailable.'],503);}
