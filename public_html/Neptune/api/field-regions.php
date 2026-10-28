<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis-install.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-training-control.php';
header('Cache-Control: private, no-store');
try {
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new CommunityAnalysisError(405,'POST required.');
 $headers=function_exists('getallheaders')?getallheaders():[];$authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??$headers['Authorization']??$headers['authorization']??'');
 if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$m))throw new CommunityAnalysisError(401,'Training computer connection required.');
 $service=new CommunityTrainers($pdo);$computer=$service->authenticate($m[1],(int)($config['app']['platform_organization_id']??1));
 $raw=file_get_contents('php://input',false,null,0,4097);if(strlen($raw)>4096)throw new CommunityAnalysisError(413,'Request too large.');
 $p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
 $base=dirname(__DIR__,3).'/neptune_secure/field-regions';
 if(!empty($p['all'])){
  // Each document gets its match name, so Neptune and the helper name the held-out match the same way.
  $videos=[];foreach($pdo->query('SELECT match_key,source_url FROM community_matches')->fetchAll(PDO::FETCH_ASSOC) as $m){parse_str((string)parse_url((string)$m['source_url'],PHP_URL_QUERY),$q);if(is_string($q['v']??null))$videos[$q['v']]=(string)$m['match_key'];}
  $docs=[];foreach(array_merge(glob($base.'/seed-documents/*.json')?:[],glob($base.'/'.(int)$computer['user_id'].'/*.json')?:[]) as $file){if(filesize($file)>2097152)continue;$d=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);if(!is_array($d))continue;
   $vid=(string)($d['source']['video_id']??preg_replace('/\.[a-z0-9]+$/i','',(string)($d['source']['name']??'')));if(empty($d['source']['match_key'])&&isset($videos[$vid]))$d['source']['match_key']=$videos[$vid];$docs[]=$d;}
  community_training_json_response(['documents'=>$docs]);
 }
 if(isset($p['event'])){
  // Every saved region set from a match at this event, so the helper can apply them event-wide.
  $event=(string)$p['event'];if(!preg_match('/^[0-9]{4}[a-z0-9]{1,20}$/D',$event))throw new CommunityAnalysisError(422,'Invalid event.');
  $videos=[];foreach($pdo->query('SELECT match_key,source_url FROM community_matches')->fetchAll(PDO::FETCH_ASSOC) as $m){parse_str((string)parse_url((string)$m['source_url'],PHP_URL_QUERY),$q);if(is_string($q['v']??null))$videos[$q['v'].'.mp4']=(string)$m['match_key'];}
  $docs=[];foreach(array_merge(glob($base.'/seed-documents/*.json')?:[],glob($base.'/'.(int)$computer['user_id'].'/*.json')?:[]) as $file){
   if(filesize($file)>2097152)continue;$d=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
   $key=(string)($d['source']['match_key']??($videos[(string)($d['source']['name']??'')]??''));
   if(str_starts_with($key,$event.'_'))$docs[]=$d;
  }
  community_training_json_response(['documents'=>$docs,'event'=>$event]);
 }
 $name=(string)($p['video_name']??'');$sha=(string)($p['source_sha256']??'');$size=$p['video_size']??0;
 if(!preg_match('/^[A-Za-z0-9_-]{11}\.mp4$/D',$name)||!preg_match('/^[a-f0-9]{64}$/D',$sha)||!is_int($size)||$size<=0)throw new CommunityAnalysisError(422,'Verified video details required.');
 $files=array_merge(glob($base.'/seed-documents/*.json')?:[],glob($base.'/'.(int)$computer['user_id'].'/*.json')?:[]);$match=null;
 foreach($files as $file){if(filesize($file)>2097152)continue;$d=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);// Regions marked on the YouTube stream carry no file size; the 11-character video ID in the name binds them.
 if(($d['source']['name']??'')!==$name||(isset($d['source']['size'])&&(int)$d['source']['size']!==$size))continue;if(isset($d['source_sha256'])&&$d['source_sha256']!==$sha)continue;$match=$d;}
 community_training_json_response(['document'=>$match,'binding'=> $match?'verify_source_locally':'no_matching_annotations']);
}catch(CommunityAnalysisError $e){community_training_json_response(['error'=>$e->getMessage()],$e->httpStatus);}catch(Throwable $e){error_log('Field regions API: '.$e->getMessage());community_training_json_response(['error'=>'Field regions unavailable.'],503);}
