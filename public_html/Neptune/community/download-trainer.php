<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
require_once dirname(__DIR__,3).'/neptune_secure/platform-security.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-trainers.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-robot-models.php';
// Installable apps contain no credential or private weights; approval happens in the app.
if($_SERVER['REQUEST_METHOD']==='GET'&&in_array(($_GET['native']??''),['mac','windows','linux'],true)) {
 $native=(string)$_GET['native'];
 $packages=['mac'=>['mac/Neptune-Training-Helper.dmg','application/x-apple-diskimage'],
            'windows'=>['windows/Neptune-Training-Helper-Setup.exe','application/vnd.microsoft.portable-executable'],
            'linux'=>['linux/Neptune-Training-Helper_all.deb','application/vnd.debian.binary-package']];
 [$file,$type]=$packages[$native];
 $path=__DIR__.'/downloads/'.$file;
 if(!is_readable($path)){http_response_code(503);exit('This helper installer is not published yet.');}
 header('Cache-Control: public, max-age=300');header('X-Content-Type-Options: nosniff');
 header('Content-Type: '.$type);header('Content-Disposition: attachment; filename="'.basename($file).'"');
 header('Content-Length: '.filesize($path));readfile($path);exit;
}
$u=require_role(['owner','admin']);if((int)$u['organization_id']!==(int)($config['app']['platform_organization_id']??1))access_denied('Platform owner required.');
function neptune_trainer_site(): string {
 global $config;
 $site=rtrim(base_url(''),'/');
 if(str_starts_with($site,'/')||$site==='') {
  // The configured Google callback supplies the trusted public origin, including
  // deployments where HTTPS terminates at a proxy. Never trust the request Host.
  $origin=(string)($config['app']['public_origin']??'');
  if($origin==='')$origin=(string)(neptune_google_settings()['redirect_uri']??'');
  // Production's fixed public origin; alternate installations can set public_origin.
  if($origin==='')$origin='https://neptunefrc.com';
  $callback=parse_url($origin);
  if(($callback['scheme']??'')!=='https'||empty($callback['host']))throw new RuntimeException('A canonical HTTPS site address is required.');
  $site='https://'.$callback['host'].(isset($callback['port'])?':'.$callback['port']:'').$site;
 }
 $url=parse_url($site);if(($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment']))throw new RuntimeException('A canonical HTTPS site address is required.');
 return $site;
}
$names=['mac'=>'Neptune-Trainer-Mac.zip','windows'=>'Neptune-Trainer-Windows.zip','linux'=>'Neptune-Trainer-Linux.zip'];
if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['check'])) {
 header('Cache-Control: private, no-store');$checks=[];$site=null;$siteError=null;
 try{$site=neptune_trainer_site();}catch(Throwable $error){$siteError=$error->getMessage();}
 foreach($names as $platform=>$file) {
  $path=__DIR__.'/downloads/'.$file;$available=is_file($path)&&is_readable($path);$temp=null;
  $checks[$platform]=['available'=>$available,'package_writable'=>false];
  try {
   if(!$site)throw new RuntimeException((string)$siteError);
   $temp=tempnam(sys_get_temp_dir(),'neptune-trainer-check-');if(!$temp||!copy($path,$temp))throw new RuntimeException('Cannot prepare package.');chmod($temp,0600);
   $zip=new ZipArchive();if($zip->open($temp)!==true)throw new RuntimeException('Cannot open package.');
   $folder=['mac'=>'Neptune Trainer/','windows'=>'Neptune Trainer/app/','linux'=>'neptune-trainer/app/'][$platform];
   community_robot_models_add($zip,$folder);
   if(!$zip->addFromString($folder.'trainer-connection.json',json_encode(['site'=>$site,'token'=>'INVALID-PREFLIGHT-NOT-A-CREDENTIAL','expires_at'=>0],JSON_THROW_ON_ERROR))||!$zip->close())throw new RuntimeException('Cannot write package.');
   $zip=new ZipArchive();if($zip->open($temp)!==true)throw new RuntimeException('Cannot verify package.');
   $connection=json_decode($zip->getFromName($folder.'trainer-connection.json'),true,512,JSON_THROW_ON_ERROR);$zip->close();
   if($connection['site']!==$site)throw new RuntimeException('Connection verification failed.');
   $checks[$platform]['package_writable']=true;
  }catch(Throwable $error){$checks[$platform]['error']=$error->getMessage();}
  finally{if(is_string($temp)&&is_file($temp))@unlink($temp);}
 }
 json_response(['site'=>$site,'platforms'=>$checks]);exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Use Download trainer on Training & Models.');}verify_csrf();
$platform=(string)($_POST['platform']??'');if(!isset($names[$platform])){http_response_code(422);exit('Choose Mac, Windows or Linux.');}
$source=__DIR__.'/downloads/'.$names[$platform];if(!is_readable($source)){http_response_code(503);exit('Trainer package is unavailable. Reinstall the Training Computers package.');}
community_learning_schema($pdo);community_trainers_schema($pdo);
$temp=null;
try {
 $site=neptune_trainer_site();
 $temp=tempnam(sys_get_temp_dir(),'neptune-trainer-');if($temp===false)throw new RuntimeException('Could not create temporary trainer package.');chmod($temp,0600);
 if(!copy($source,$temp))throw new RuntimeException('Could not prepare trainer.');$zip=new ZipArchive();if($zip->open($temp)!==true)throw new RuntimeException('Could not open trainer package.');
 $grant=(new CommunityTrainers($pdo))->enroll($u,ucfirst($platform).' training computer');
 $folder=['mac'=>'Neptune Trainer/','windows'=>'Neptune Trainer/app/','linux'=>'neptune-trainer/app/'][$platform];
 community_robot_models_add($zip,$folder);
 if(!$zip->addFromString($folder.'trainer-connection.json',json_encode(['site'=>$site,'token'=>$grant['token'],'expires_at'=>$grant['expires_at'],'computer_id'=>$grant['id']],JSON_THROW_ON_ERROR))||!$zip->close())throw new RuntimeException('Could not save trainer connection.');
 header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$names[$platform].'"');header('Content-Length: '.filesize($temp));if(session_status()===PHP_SESSION_ACTIVE)session_write_close();readfile($temp);
}catch(Throwable $error){
 error_log('Trainer download: '.get_class($error).': '.$error->getMessage());
 http_response_code(503);header('Content-Type: text/html; charset=utf-8');
 echo '<!doctype html><title>Trainer download unavailable</title><h1>Trainer download could not be prepared</h1><p>Please return to Training &amp; Models and try again. The server log contains the cause.</p><p><a href="../autoscout/train.php#training-computers">Return to Training &amp; Models</a></p>';
}finally{if(is_string($temp)&&is_file($temp))@unlink($temp);}

