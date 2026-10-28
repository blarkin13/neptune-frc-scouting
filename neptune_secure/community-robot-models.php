<?php
declare(strict_types=1);
/** Owner-personalized downloads only. Never copy private weights into public base ZIPs. */
function community_robot_models_add(ZipArchive $zip,string $folder,?string $root=null):void {
 $root??=__DIR__.'/community-learning/robot-models';
 $manifest=$root.'/manifest.json';
 if(!is_readable($manifest))throw new RuntimeException('Private robot tracking models are not installed on Neptune.');
 $data=json_decode((string)file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
 if(($data['schema_version']??null)!==1)throw new RuntimeException('Unsupported private robot model manifest.');
 foreach(['bumper','context'] as $key){
  $row=$data[$key]??[];$file=$row['file']??'';
  if(!is_string($file)||!preg_match('/^[A-Za-z0-9_-]+\.pt$/D',$file))throw new RuntimeException('Invalid private robot model filename.');
  $path=$root.'/'.$file;
  if(!is_readable($path)||filesize($path)>250*1024*1024||!hash_equals((string)($row['sha256']??''),(string)hash_file('sha256',$path)))throw new RuntimeException('Private robot model fingerprint mismatch.');
  if(!$zip->addFile($path,$folder.'robot-models/'.$file))throw new RuntimeException('Could not include private robot model.');
 }
 if(!$zip->addFile($manifest,$folder.'robot-models/manifest.json'))throw new RuntimeException('Could not include robot model manifest.');
}
