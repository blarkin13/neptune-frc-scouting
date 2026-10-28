<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/community-training-control.php';
require_once __DIR__.'/community-training-server.php';
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
community_video_tags_schema($pdo);community_training_control_schema($pdo);
if(!(int)$pdo->query("SELECT GET_LOCK('community-learning-worker',10)")->fetchColumn())exit;
$control=new CommunityTrainingControl($pdo);$computer=['id'=>'neptune-server'];
$root=dirname(__DIR__).'/maintenance-uploads/community-learning-work';
if(!is_dir($root)&&!mkdir($root,0700,true))throw new RuntimeException('Neptune cannot create its private training folder.');
try {
 $q=$pdo->prepare("SELECT id,control_json FROM community_learning_jobs WHERE state IN ('running','test_running','evaluation_running')");$q->execute();foreach($q as $old){$c=$old['control_json']?json_decode($old['control_json'],true):[];if(($c['executor']??'')==='server'){$fail=$pdo->prepare("UPDATE community_learning_jobs SET state='failed',finished_at=?,message='The server worker was interrupted. Saved examples are kept; start another run to reuse them.' WHERE id=?");$fail->execute([time(),$old['id']]);}}
 while(true){
  file_put_contents($root.'/worker-alive.json',json_encode(['updated_at'=>time(),'managed'=>getenv('NEPTUNE_SERVER_CGROUP')==='1'],JSON_THROW_ON_ERROR),LOCK_EX);
  $access=dirname($root).'/community-server-video-access.json';$request=dirname($root).'/community-server-video-request.json';$previous=is_file($access)?json_decode((string)file_get_contents($access),true):null;
  if(is_file($request)&&filemtime($request)>(int)($previous['checked_at']??0)){
   $probe=proc_open([community_training_server_python(),__DIR__.'/community-learning/server_run.py','--check-video'],[0=>['file','/dev/null','r'],1=>['file',$root.'/video-access-check.json','w'],2=>['file',$root.'/video-access-check.log','a']],$pipes,__DIR__.'/community-learning');$began=time();
   if(is_resource($probe)){do{$status=proc_get_status($probe);if(!$status['running'])break;file_put_contents($root.'/worker-alive.json',json_encode(['updated_at'=>time(),'managed'=>getenv('NEPTUNE_SERVER_CGROUP')==='1']),LOCK_EX);if(time()-$began>120){if(function_exists('posix_kill'))@posix_kill(-$status['pid'],15);proc_terminate($probe);break;}usleep(500000);}while(true);proc_close($probe);}
   $lines=explode("\n",trim((string)@file_get_contents($root.'/video-access-check.json')));$video=json_decode((string)end($lines),true);if(!is_array($video)||!isset($video['ready']))$video=['ready'=>false];$video['checked_at']=time();file_put_contents($access,json_encode($video),LOCK_EX);
  }
  $job=$control->reserveServer();if(!$job){if(in_array('--daemon',$argv,true)){sleep(3);continue;}break;}
  $dir=$root.'/runs/'.$job['id'];$proc=null;$pid=null;$suspended=false;
  try {
   if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Private work folder is unavailable.');
   if(isset($job['model']))$job['model']=json_decode(community_model_document($job['model']));
   file_put_contents($dir.'/job.json',json_encode($job,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),LOCK_EX);chmod($dir.'/job.json',0600);@unlink($dir.'/output.json');
   file_put_contents($dir.'/commands.json','{"stop_requested":false}',LOCK_EX);
   $command=[community_training_server_python(),__DIR__.'/community-learning/server_run.py','--job',$dir.'/job.json','--root',$root.'/computer','--output',$dir.'/output.json','--commands',$dir.'/commands.json','--progress',$dir.'/progress.json'];
   $proc=proc_open($command,[0=>['file','/dev/null','r'],1=>['file',$dir.'/progress.log','a'],2=>['file',$dir.'/errors.log','a']],$pipes,__DIR__.'/community-learning');if(!is_resource($proc))throw new RuntimeException('Neptune could not start the background worker.');
   $began=microtime(true);$lastBeat=0;$exit=null;
   while(true){$s=proc_get_status($proc);$pid=$s['pid'];if(!$s['running']){$exit=$s['exitcode'];break;}
    // One active second in ten, on one CPU, keeps this below a busy desktop run.
    $shouldSuspend=((microtime(true)-$began)>=2)&&(((int)(microtime(true)-$began)%10)!==0);
    if(getenv('NEPTUNE_SERVER_CGROUP')!=='1'&&function_exists('posix_kill')&&$pid>0&&$shouldSuspend!==$suspended){@posix_kill(-$pid,$shouldSuspend?19:18);$suspended=$shouldSuspend;}
    if(time()-$lastBeat>=3){file_put_contents($root.'/worker-alive.json',json_encode(['updated_at'=>time(),'managed'=>getenv('NEPTUNE_SERVER_CGROUP')==='1'],JSON_THROW_ON_ERROR),LOCK_EX);$progress=is_file($dir.'/progress.json')?json_decode((string)file_get_contents($dir.'/progress.json'),true):[];$p=['job_id'=>$job['id'],'lease'=>$job['lease'],'message'=>$progress['message']??'Neptune is preparing this run slowly in the background'];if(is_array($progress['checkpoint']??null))$p['checkpoint']=$progress['checkpoint'];$reply=$control->heartbeat($computer,$p);file_put_contents($dir.'/commands.json',json_encode(['stop_requested'=>$reply['stop_requested']],JSON_THROW_ON_ERROR),LOCK_EX);$lastBeat=time();}
    usleep(250000);
   }
   if($suspended&&function_exists('posix_kill'))@posix_kill(-$pid,18);proc_close($proc);$proc=null;
   $output=is_file($dir.'/output.json')?json_decode((string)file_get_contents($dir.'/output.json'),true,128,JSON_THROW_ON_ERROR):null;if(!$output)throw new RuntimeException('Neptune could not finish this run. Check its private worker log. Saved examples are kept.');
   $p=$output['payload'];
   if($output['action']==='pause'){$control->pauseServer($p);$q=$pdo->prepare('SELECT control_json FROM community_learning_jobs WHERE id=?');$q->execute([$job['id']]);$c=json_decode((string)$q->fetchColumn(),true);if(empty($c['stop_requested'])&&$c['executor']==='server'){$q=$pdo->prepare("UPDATE community_learning_jobs SET state='queued',message='Saved this match. Continuing the next match slowly on Neptune.' WHERE id=? AND state='server_paused'");$q->execute([$job['id']]);}}
   elseif($output['action']==='finish')(new CommunityTrainers($pdo))->finish($computer,$p);
   elseif($output['action']==='trial_finish')(new CommunityScoutingTrials($pdo))->finish($computer,$p);
   elseif($output['action']==='page_evaluation_finish')$control->finishEvaluation($computer,$p);
   elseif($output['action']==='page_fail')$control->fail($computer,$p);
   else throw new RuntimeException('Neptune returned an unsupported worker result.');
  }catch(Throwable $e){if(is_resource($proc)){if($pid&&function_exists('posix_kill')){@posix_kill(-$pid,18);@posix_kill(-$pid,15);}proc_terminate($proc);proc_close($proc);}error_log('Community server worker: '.$e->getMessage());try{$control->fail($computer,['job_id'=>$job['id'],'lease'=>$job['lease'],'message'=>$e->getMessage()]);}catch(Throwable $ignored){}}
 }
}finally{$pdo->query("SELECT RELEASE_LOCK('community-learning-worker')");}
