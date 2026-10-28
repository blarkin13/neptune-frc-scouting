<?php
declare(strict_types=1);

/** Platform-owner page only. The root helper has a fixed allowlist and accepts no paths. */
function community_storage_cleanup(array $request): array {
 $action=(string)($request['action']??'');
 if(!in_array($action,['preview','delete'],true))throw new CommunityAnalysisError(422,'Choose a storage cleanup action.');
 $payload=['action'=>$action];
 if($action==='delete'){
  $fingerprint=(string)($request['fingerprint']??'');
  if(!preg_match('/^[0-9a-f]{64}$/D',$fingerprint))throw new CommunityAnalysisError(422,'Preview the files again.');
  $payload['fingerprint']=$fingerprint;
 }
 $program='/usr/local/sbin/neptune-helper-artifact-cleanup';
 if(!is_callable('proc_open'))throw new CommunityAnalysisError(503,'Storage cleanup is not configured on this server.');
 $process=proc_open(['/usr/bin/sudo','-n',$program],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new CommunityAnalysisError(503,'Storage cleanup is not configured on this server.');
 fwrite($pipes[0],json_encode($payload,JSON_THROW_ON_ERROR));fclose($pipes[0]);
 $output=stream_get_contents($pipes[1],262144);fclose($pipes[1]);
 $error=stream_get_contents($pipes[2],1024);fclose($pipes[2]);
 $status=proc_close($process);
 $result=json_decode((string)$output,true);
 if($status!==0||!is_array($result)||($result['ok']??false)!==true){
  if(is_array($result)&&isset($result['error']))throw new CommunityAnalysisError(409,(string)$result['error']);
  error_log('Auto Scout storage cleanup: '.substr((string)$error,0,300));
  throw new CommunityAnalysisError(503,'Storage cleanup is unavailable. No saved scouting data was changed.');
 }
 return $result;
}
