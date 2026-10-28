<?php
declare(strict_types=1);
function community_training_server_python():string {
 $private=dirname(__DIR__).'/maintenance-uploads/community-learning-venv/bin/python3';return is_executable($private)?$private:'/usr/bin/python3';
}
function community_training_server_health(bool $refresh=false):array {
 if(is_file(dirname(__DIR__).'/maintenance-uploads/community-server-disabled.json'))return ['ready'=>false,'disabled'=>true,'message'=>'Training runs on helper computers. Server training is turned off.','missing'=>[],'checked_at'=>time()];
 $folder=dirname(__DIR__).'/maintenance-uploads';$cache=$folder.'/community-server-health.json';
 if(!$refresh&&is_file($cache)&&filemtime($cache)>time()-60){$r=json_decode((string)file_get_contents($cache),true);if(is_array($r)){ $access=$folder.'/community-server-video-access.json';if(empty($r['checking_video'])||!is_file($access)||filemtime($access)<(int)($r['checked_at']??0))return $r;}}
 $out=['ready'=>false,'message'=>'Neptune needs its video tools checked before it can train.','missing'=>[]];
 if(!is_callable('proc_open')||!is_executable(community_training_server_python()))return $out+['checked_at'=>time()];
 $proc=proc_open([community_training_server_python(),__DIR__.'/community-learning/server_run.py','--check'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__.'/community-learning');
 if(is_resource($proc)){stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$raw='';$began=microtime(true);do{$raw.=stream_get_contents($pipes[1]);$s=proc_get_status($proc);if(!$s['running'])break;if(microtime(true)-$began>12){proc_terminate($proc);break;}usleep(100000);}while(true);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);$decoded=json_decode($raw,true);if(!is_array($decoded)){$lines=explode("\n",trim($raw));$decoded=json_decode((string)end($lines),true);}if(is_array($decoded)&&isset($decoded['ready'],$decoded['missing']))$out=$decoded;}
 $alive=$folder.'/community-learning-work/worker-alive.json';if(!is_file($alive)||filemtime($alive)<time()-90||empty(json_decode((string)file_get_contents($alive),true)['managed'])){$out['ready']=false;$out['message']='Neptune’s background worker is not connected yet. Its one-time server setup must finish before it can run training. You can use a helper computer now.';}
 $videoCheck=$folder.'/community-server-video-access.json';$request=$folder.'/community-server-video-request.json';$video=is_file($videoCheck)?json_decode((string)file_get_contents($videoCheck),true):null;
 if($out['ready']){
  if($refresh&&(!$video||(int)($video['checked_at']??0)<time()-60)&&(!is_file($request)||filemtime($request)<time()-120))file_put_contents($request,json_encode(['requested_at'=>time()]),LOCK_EX);
  $pending=is_file($request)&&filemtime($request)>(int)($video['checked_at']??0);
  if($pending){$out['ready']=false;$out['checking_video']=true;$out['message']='Neptune’s tools are ready. Its background worker is checking video access. Wait here; this updates automatically.';}
  elseif(($video['ready']??false)!==true){$out['ready']=false;$out['message']=($video['reason']??'')==='provider_sign_in'?'YouTube is requiring a sign-in / bot check from Neptune’s AWS server. Server training cannot download videos right now. Choose Use helper computer to run this batch. Your tags, models and saved results are kept.':'Neptune’s tools are installed, but the video provider is blocking or cannot serve downloads from this server. Use a helper computer, or check Neptune again later. Your tags and saved models are kept.';}
 }

 $out['checked_at']=time();if(is_dir($folder)&&is_writable($folder))file_put_contents($cache,json_encode($out,JSON_THROW_ON_ERROR),LOCK_EX);return $out;
}
