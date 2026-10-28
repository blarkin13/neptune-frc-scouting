<?php
declare(strict_types=1);
require_once __DIR__.'/community-game-actions.php';
require_once __DIR__.'/community-model-json.php';
require_once __DIR__.'/community-learning.php';
require_once __DIR__.'/community-training-progress.php';
function community_trainers_schema(PDO $db):void {
 neptune_migration_apply($db,'community.training.computers.v1','Neptune_Training_Computers_v1.zip',function()use($db):void{
  $db->exec("CREATE TABLE IF NOT EXISTS community_training_computers (id CHAR(32) PRIMARY KEY,user_id BIGINT NOT NULL,credential_revision CHAR(64) NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,name VARCHAR(100) NOT NULL,created_at BIGINT NOT NULL,expires_at BIGINT NOT NULL,revoked INT NOT NULL DEFAULT 0,last_seen BIGINT NULL,hardware_json TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->exec("CREATE TABLE IF NOT EXISTS community_training_leases (job_id CHAR(32) PRIMARY KEY,computer_id CHAR(32) NOT NULL,lease_hash CHAR(64) NOT NULL,expires_at BIGINT NOT NULL,result_hash CHAR(64) NULL,game_json TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 });
}
/** Keep supported worker capabilities while discarding unrelated telemetry. */
function community_training_hardware(array $input):array {
 $out=[];foreach(['platform','device','name','torch_version','training_protocol','robot_tracking','action_features'] as $key)if(isset($input[$key])&&is_scalar($input[$key]))$out[$key]=mb_substr((string)$input[$key],0,200);
 if(is_string($input['engine_version']??null)&&preg_match('/^[a-zA-Z0-9.\-]{1,40}$/D',$input['engine_version']))$out['engine_version']=$input['engine_version'];
 if(is_string($input['engine_sha256']??null)&&preg_match('/^[a-f0-9]{64}$/D',$input['engine_sha256']))$out['engine_sha256']=$input['engine_sha256'];
 if(is_string($input['robot_detector_sha256']??null)&&preg_match('/^[a-f0-9]{64}$/D',$input['robot_detector_sha256']))$out['robot_detector_sha256']=$input['robot_detector_sha256'];
 if(is_array($input['outside_adapters']??null))$out['outside_adapters']=array_values(array_intersect(['viper-2026-activity-v1','mapped-actions-v1','viper-2026-points-v1'],array_filter($input['outside_adapters'],'is_string')));
 if(is_array($input['label_contracts']??null))$out['label_contracts']=array_values(array_intersect(['game-builder-moments-v1'],array_filter($input['label_contracts'],'is_string')));
 return $out;
}
/** Explicit feature contracts; model weights cannot choose an arbitrary input size. */
function community_action_feature_dimension(array $model):int {
 $version=$model['feature_version']??'';
 if($version==='robot-rgb-motion-v1')return 1296;
 if($version!=='robot-r3d18-clip-v1')return 0;
 $e=$model['action_encoder']??[];
 if(($e['name']??'')!=='torchvision-r3d18-kinetics400-v1'||($e['feature_version']??'')!==$version||($e['dimension']??0)!==512||($e['weights_sha256']??'')!=='b3b3357ead25631ec9c57362ff2128a92d0427e01e2cd184951a44380c3f2e9d'||($e['frames']??0)!==16||($e['fps']??0)!==4||($e['seconds']??0)!=4||($e['backbone_frozen']??false)!==true||($e['head_training']??'')!=='per-game-Neptune-action-labels')return 0;
 return 512;
}
final class CommunityTrainers {
 public function __construct(private PDO $db){community_training_progress_schema($db);}
 private function q(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 private function lock():string{return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
 private function tx(callable $fn):mixed{$this->db->beginTransaction();try{$r=$fn();$this->db->commit();return $r;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}}
 public function enroll(array $user,string $name):array {
  if(!in_array($user['role']??'',['owner','admin'],true))throw new CommunityAnalysisError(403,'Auto Scout helpers are for admins of the owner organization.');
  $id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));$expiry=time()+30*86400;
  $this->q('INSERT INTO community_training_computers(id,user_id,credential_revision,token_hash,name,created_at,expires_at) VALUES(?,?,?,?,?,?,?)',[$id,$user['id'],$user['credential_revision'],hash('sha256',$token),mb_substr($name,0,100),time(),$expiry]);
  return ['id'=>$id,'token'=>$token,'expires_at'=>$expiry];
 }
 public function authenticate(string $token,int $platformOrg):array {
  if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new CommunityAnalysisError(401,'Training computer connection required.');
  $r=$this->q('SELECT c.*,u.role,u.organization_id,u.active,u.must_change_password,u.password_hash,o.platform_status FROM community_training_computers c JOIN users u ON u.id=c.user_id JOIN organizations o ON o.id=u.organization_id WHERE c.token_hash=?',[hash('sha256',$token)])->fetch(PDO::FETCH_ASSOC);
  if(!$r||$r['revoked']||(int)$r['expires_at']<=time()||!(int)$r['active']||(int)$r['must_change_password']||($r['platform_status']??'active')!=='active'||!in_array($r['role'],['owner','admin'],true)||(int)$r['organization_id']!==$platformOrg||!hash_equals($r['credential_revision'],hash('sha256',$r['password_hash'])))throw new CommunityAnalysisError(401,'Training connection expired, revoked or no longer authorized. Download a new connection from Training & Models.');
  return $r;
 }
 public function queue(int $season,int $user):string {
  $game=community_learning_games()[(string)$season]??null;if(!$game||empty($game['configured']))throw new CommunityAnalysisError(422,'Configure this game first.');
  $preflight=community_learning_baseline_preflight(community_learning_export($this->db),$season);if(!$preflight['can_run_baseline'])throw new CommunityAnalysisError(422,$preflight['message']);
  // Use the same queue lock as server training to serialize requests from all owners.
  $mysql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';if($mysql&&!(int)$this->db->query("SELECT GET_LOCK('community-learning-queue',3)")->fetchColumn())throw new CommunityAnalysisError(409,'Training queue busy; retry shortly.');
  try{return $this->tx(function()use($season,$user):string{
   $existing=$this->q("SELECT id FROM community_learning_jobs WHERE season=? AND state IN ('remote_queued','remote_running','remote_paused','queued','running') ORDER BY created_at LIMIT 1",[$season])->fetchColumn();if($existing)return (string)$existing;
   $recovery=$this->q("SELECT id FROM community_learning_jobs WHERE created_by=? AND season=? AND state='failed' AND message='Unsupported candidate head.' AND control_json IS NULL ORDER BY created_at,id LIMIT 1",[$user,$season])->fetchColumn();if($recovery)return (string)$recovery;
   $snapshot=community_learning_export($this->db);$lines=array_filter(explode("\n",$snapshot),fn($l)=>$l!==''&&(int)(json_decode($l,true)['season']??0)===$season);$snapshot=implode("\n",$lines).($lines?"\n":'');$id=bin2hex(random_bytes(16));
   $this->q("INSERT INTO community_learning_jobs(id,season,state,created_by,created_at,message,snapshot_sha256,snapshot_ndjson) VALUES(?,?,'remote_queued',?,?,'Waiting for a connected training computer',?,?)",[$id,$season,$user,time(),hash('sha256',$snapshot),$snapshot]);return $id;
  });}finally{if($mysql)$this->db->query("SELECT RELEASE_LOCK('community-learning-queue')");}
 }
 public function claim(array $computer,array $hardware,string $resumeJob=''):array {
  $hardware=community_training_hardware($hardware);
  $this->q('UPDATE community_training_computers SET last_seen=?,hardware_json=? WHERE id=?',[time(),json_encode($hardware,JSON_THROW_ON_ERROR),$computer['id']]);
  return $this->tx(function()use($computer,$hardware,$resumeJob):array{
   if($resumeJob!==''){
    $job=$this->q("SELECT * FROM community_learning_jobs WHERE id=? AND created_by=? AND state='remote_paused' AND control_json IS NULL".$this->lock(),[$resumeJob,$computer['user_id']])->fetch(PDO::FETCH_ASSOC);
    if(!$job)return ['job'=>null,'resume_unavailable'=>true];
   }else{
    // Recover this owner's previously rejected result before starting new work.
    $job=in_array(($hardware['training_protocol']??''),['checkpoint-v1','scoring-v1'],true)?$this->q("SELECT * FROM community_learning_jobs WHERE created_by=? AND state='failed' AND message='Unsupported candidate head.' AND control_json IS NULL ORDER BY created_at,id LIMIT 1".$this->lock(),[$computer['user_id']])->fetch(PDO::FETCH_ASSOC):false;
    if(!$job)$job=$this->q("SELECT * FROM community_learning_jobs WHERE control_json IS NULL AND (state='remote_queued' OR (state='remote_running' AND ".community_training_progress_heartbeat_sql()."<?)) ORDER BY created_at,id LIMIT 1".$this->lock(),[time()-600])->fetch(PDO::FETCH_ASSOC);
    if(!$job)return ['job'=>null];
   }
   if(!in_array(($hardware['training_protocol']??''),['pu-v1','checkpoint-v1','scoring-v1'],true))throw new CommunityAnalysisError(422,'This trainer uses the old learning method. Close it and download Trainer v1.2 from Training & Models. Your saved tags and reports are kept.');
   if($resumeJob!==''&&!in_array(($hardware['training_protocol']??''),['checkpoint-v1','scoring-v1'],true))throw new CommunityAnalysisError(422,'Resume this batch using Trainer v1.3.');
   community_require_game_action_helper($job['snapshot_ndjson'],$hardware);
   foreach(array_filter(explode("\n",$job['snapshot_ndjson'])) as $line){if(community_score_target(json_decode($line,true)??[])&&($hardware['training_protocol']??'')!=='scoring-v1')throw new CommunityAnalysisError(422,'Download Trainer v1.4 to learn saved scoring estimates. Your prior data and cache are kept.');}
   $old=$this->q('SELECT game_json FROM community_training_leases WHERE job_id=?',[$job['id']])->fetchColumn();$game=$old?json_decode($old,true):community_learning_games()[(string)$job['season']];$lease=bin2hex(random_bytes(32));
   $this->q('DELETE FROM community_training_leases WHERE job_id=?',[$job['id']]);$this->q('INSERT INTO community_training_leases(job_id,computer_id,lease_hash,expires_at,game_json) VALUES(?,?,?,?,?)',[$job['id'],$computer['id'],hash('sha256',$lease),time()+600,json_encode($game,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)]);
   $this->q("UPDATE community_learning_jobs SET state='remote_running',started_at=COALESCE(started_at,?),heartbeat_at=?,message='Processing on a training computer' WHERE id=?",[time(),time(),$job['id']]);
   return ['job'=>['id'=>$job['id'],'season'=>(int)$job['season'],'snapshot'=>$job['snapshot_ndjson'],'snapshot_sha256'=>$job['snapshot_sha256'],'game_pack'=>$game,'lease'=>$lease]];
  });
 }
 private function lease(array $computer,array $p,bool $finished=false):array {
  $r=$this->q('SELECT l.*,j.state,j.season,j.snapshot_ndjson FROM community_training_leases l JOIN community_learning_jobs j ON j.id=l.job_id WHERE l.job_id=?'.$this->lock(),[(string)($p['job_id']??'')])->fetch(PDO::FETCH_ASSOC);
  if(!$r||$r['computer_id']!==$computer['id']||!hash_equals($r['lease_hash'],hash('sha256',(string)($p['lease']??'')))||(!$finished&&((int)$r['expires_at']<=time()||!in_array($r['state'],$computer['id']==='neptune-server'?['running']:['remote_running'],true))))throw new CommunityAnalysisError(409,'This training reservation expired or moved to another computer. Local results are retained.');return $r;
 }
 public function heartbeat(array $computer,array $p):array{return $this->tx(function()use($computer,$p):array{$r=$this->lease($computer,$p);$this->q('UPDATE community_training_leases SET expires_at=? WHERE job_id=?',[time()+600,$r['job_id']]);community_training_progress_write($this->db,$r['job_id'],(string)($p['message']??'Training on connected computer'));$this->q('UPDATE community_training_computers SET last_seen=? WHERE id=?',[time(),$computer['id']]);return ['ok'=>true];});}
 public function pause(array $computer,array $p):array{return $this->tx(function()use($computer,$p):array{
  $r=$this->lease($computer,$p);$completed=max(0,(int)($p['matches_done']??0));$total=max($completed,(int)($p['matches_total']??0));
  $message=isset($p['message'])?mb_substr((string)$p['message'],0,900):'Paused: '.$completed.' of '.$total.' matches prepared on this computer. Resume this saved batch from Neptune.';
  $this->q("UPDATE community_learning_jobs SET state='remote_paused',heartbeat_at=?,message=? WHERE id=?",[time(),$message,$r['job_id']]);return ['ok'=>true,'state'=>'remote_paused'];
 });}
 public function finish(array $computer,array $p):array {
  return $this->tx(function()use($computer,$p):array{
   $r=$this->lease($computer,$p,true);$result=$p['result']??null;if(!is_array($result)||!is_array($result['report']??null)||!is_array($result['model']??null))throw new CommunityAnalysisError(422,'Training report and candidate required.');
   $hash=hash('sha256',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));if($r['result_hash']){if(!hash_equals($r['result_hash'],$hash))throw new CommunityAnalysisError(409,'A different result was already saved.');return ['ok'=>true,'state'=>$r['state']];}
   if(!in_array($r['state'],$computer['id']==='neptune-server'?['running']:['remote_running'],true)||(int)$r['expires_at']<=time())throw new CommunityAnalysisError(409,'Training reservation expired.');
   $model=$result['model'];$game=json_decode($r['game_json'],true);if(($model['game_key']??'')!==$game['game_key']||!community_action_feature_dimension($model)||!is_array($model['heads']??null)||!preg_match('/^[a-f0-9]{64}$/D',(string)($model['model_id']??'')))throw new CommunityAnalysisError(422,'Candidate does not match this game or supported model format.');
   $allowed=[];$scoreAllowed=[];foreach(array_filter(explode("\n",$r['snapshot_ndjson'])) as $line){$l=json_decode($line,true);if(($l['certainty']??'')==='observed')$allowed[$l['id']]=true;if(community_score_target($l)||community_import_score_target($l))$scoreAllowed[$l['id']]=true;}
   if(!empty($model['scoring_head'])){if(!community_score_model_valid($model['scoring_head']))throw new CommunityAnalysisError(422,'Unsupported scoring model.');foreach($model['scoring_head']['used_label_ids'] as $id){if(!isset($scoreAllowed[$id]))throw new CommunityAnalysisError(422,'Scoring candidate references unavailable score evidence.');$allowed[$id]=true;}}
   if(!is_array($model['used_label_ids']??null))throw new CommunityAnalysisError(422,'Candidate label provenance missing.');foreach($model['scoring_head']['used_label_ids']??[] as $scoreId)if(!in_array($scoreId,$model['used_label_ids'],true))throw new CommunityAnalysisError(422,'Scoring label provenance is incomplete.');foreach($model['used_label_ids'] as $id)if(!isset($allowed[$id]))throw new CommunityAnalysisError(422,'Candidate references evidence outside its observed snapshot.');
   $gameActions=community_game_action_definitions($r['snapshot_ndjson'],$game['game_key']);
   foreach($model['heads'] as $tag=>$head){if((!isset(community_video_tag_catalog()[$tag])&&(!isset($gameActions[$tag])||!community_game_action_definition_matches($model['action_definitions'][$tag]??null,$gameActions[$tag])))||($head['feature_dimension']??0)!==community_action_feature_dimension($model)||!is_array($head['weights']??null)||count($head['weights'])!==community_action_feature_dimension($model))throw new CommunityAnalysisError(422,'Unsupported candidate head.');foreach(array_merge($head['weights'],[$head['bias']??null,$head['threshold']??null]) as $value)if(!is_numeric($value)||!is_finite((float)$value))throw new CommunityAnalysisError(422,'Invalid candidate weights.');}
   // A remote report is not publication authority. Owner release remains a separate action.
   $bench=$result['benchmark']??null;$pass=is_array($bench)&&!empty($bench['release_ready'])&&($bench['model_id']??'')===$model['model_id']&&count($bench['matches']??[])>=10&&($bench['events']??0)>=2&&!empty($bench['all_six_robot_gate']);
   foreach(array_keys($model['heads']) as $tag){$m=$bench['by_tag'][$tag]??[];if(($m['precision']??0)<.9||($m['recall']??0)<.8||($m['tp']??0)+($m['fn']??0)<10)$pass=false;}
   $hasScore=!empty($model['scoring_head']);if($hasScore&&!community_score_benchmark_pass($bench['scoring']??null))$pass=false;
   $model['release_ready']=$pass&&(count($model['heads'])>0||$hasScore);if(is_array($bench))$bench['release_ready']=$model['release_ready'];$state=($model['heads']||$hasScore)?'candidate':'needs_data';$report=$result['report'];unset($report['folder']);
   $this->q('UPDATE community_learning_jobs SET state=?,finished_at=?,heartbeat_at=?,message=?,report_json=?,model_json=?,benchmark_json=? WHERE id=?',[$state,time(),time(),$state==='needs_data'?'Training computer finished: more suitable examples are needed.':'Candidate returned. Review its evaluation before release.',json_encode($report,JSON_THROW_ON_ERROR),community_model_document($model),$bench?json_encode($bench,JSON_THROW_ON_ERROR):null,$r['job_id']]);$this->q('UPDATE community_training_leases SET result_hash=? WHERE job_id=?',[$hash,$r['job_id']]);return ['ok'=>true,'state'=>$state];
  });
 }
 public function fail(array $computer,array $p):array{return $this->tx(function()use($computer,$p):array{$r=$this->lease($computer,$p);$this->q("UPDATE community_learning_jobs SET state='failed',finished_at=?,message=? WHERE id=?",[time(),mb_substr((string)($p['message']??'Training computer could not finish.'),0,900),$r['job_id']]);return ['ok'=>true];});}
}
