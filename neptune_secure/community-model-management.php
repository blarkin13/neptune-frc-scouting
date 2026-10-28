<?php
declare(strict_types=1);

/** Called only by the platform-owner page after its authorization and CSRF checks. */
final class CommunityModelManagement {
 public function __construct(private PDO $db){}
 private function q(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 private function lock():string{return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
 private function tx(callable $fn):array {
  $mysql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
  if($mysql&&!(int)$this->db->query("SELECT GET_LOCK('community-learning-queue',3)")->fetchColumn())throw new CommunityAnalysisError(409,'Neptune is updating a run. Try again.');
  try{$this->db->beginTransaction();try{$out=$fn();$this->db->commit();return $out;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}}
  finally{if($mysql)$this->db->query("SELECT RELEASE_LOCK('community-learning-queue')");}
 }
 private function inUse():array {
  $used=[];
  foreach($this->q("SELECT id,snapshot_ndjson FROM community_learning_jobs WHERE state IN ('remote_queued','remote_running','queued','running','test_queued','test_running','evaluation_queued','evaluation_running')") as $row){
   $request=json_decode((string)$row['snapshot_ndjson'],true);
   if(is_array($request)&&is_string($request['model_job']??null))$used[$request['model_job']]=true;
  }
  return $used;
 }
 public function saved(int $season):array {
  $released=$this->q('SELECT job_id FROM community_learning_releases WHERE season=?',[$season])->fetchColumn();$used=$this->inUse();$out=[];
  foreach($this->q("SELECT id,created_at,state,LENGTH(model_json) AS model_bytes FROM community_learning_jobs WHERE season=? AND state IN ('candidate','needs_data','failed') AND model_json IS NOT NULL ORDER BY created_at DESC,id DESC",[$season]) as $row){
   $out[]=['id'=>$row['id'],'created_at'=>(int)$row['created_at'],'bytes'=>(int)$row['model_bytes'],'released'=>$released===$row['id'],'in_use'=>isset($used[$row['id']]),'state'=>$row['state']];
  }
  return $out;
 }
 public function delete(int $season,string $id,string $confirmation,bool $withdraw=false):array {
  if($confirmation!=='DELETE MODEL')throw new CommunityAnalysisError(422,'Type DELETE MODEL to confirm.');
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new CommunityAnalysisError(422,'Choose a saved model.');
  return $this->tx(function()use($season,$id,$withdraw):array{
   $row=$this->q('SELECT id,state,model_json FROM community_learning_jobs WHERE id=? AND season=?'.$this->lock(),[$id,$season])->fetch(PDO::FETCH_ASSOC);
   if(!$row)throw new CommunityAnalysisError(404,'Saved model not found for this game.');
   if($row['state']==='model_deleted')return ['ok'=>true,'already_deleted'=>true,'message'=>'This saved model has already been deleted.'];
   if(!in_array($row['state'],['candidate','needs_data','failed'],true)||$row['model_json']===null)throw new CommunityAnalysisError(409,'This is not a finished training model.');
   if(isset($this->inUse()[$id]))throw new CommunityAnalysisError(409,'A queued or running match test or evaluation uses this model. Finish or cancel that work first.');
   $released=$this->q('SELECT job_id FROM community_learning_releases WHERE season=?'.$this->lock(),[$season])->fetchColumn()===$id;
   if($released&&!$withdraw)throw new CommunityAnalysisError(409,'This model is released. Confirm that its release should also be withdrawn.');
   if($released)$this->q('DELETE FROM community_learning_releases WHERE season=? AND job_id=?',[$season,$id]);
   $this->q("UPDATE community_learning_jobs SET model_json=NULL,state='model_deleted' WHERE id=? AND season=?",[$id,$season]);
   return ['ok'=>true,'withdrawn'=>$released,'message'=>'Saved model deleted. Its training report, predictions, human labels and imports were kept. Copies already downloaded by helpers are not removed.'];
  });
 }
 public function release(int $season,string $id,int $user):array {
  return $this->tx(function()use($season,$id,$user):array{
   $row=$this->q("SELECT model_json,benchmark_json FROM community_learning_jobs WHERE id=? AND season=? AND state='candidate'".$this->lock(),[$id,$season])->fetch(PDO::FETCH_ASSOC);
   $model=$row?json_decode((string)$row['model_json'],true):null;$bench=$row?json_decode((string)$row['benchmark_json'],true):null;
   if(!$model||!$bench||!community_learning_release_valid($this->db,$model,$bench))throw new CommunityAnalysisError(422,'This model has not passed independent evaluation, or its source labels changed.');
   if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql='INSERT INTO community_learning_releases(season,job_id,released_by,released_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE job_id=VALUES(job_id),released_by=VALUES(released_by),released_at=VALUES(released_at)';
   else $sql='INSERT INTO community_learning_releases(season,job_id,released_by,released_at) VALUES(?,?,?,?) ON CONFLICT(season) DO UPDATE SET job_id=excluded.job_id,released_by=excluded.released_by,released_at=excluded.released_at';
   $this->q($sql,[$season,$id,$user,time()]);return ['id'=>$id,'released'=>true];
  });
 }
}
