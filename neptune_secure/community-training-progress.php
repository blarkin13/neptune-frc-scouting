<?php
declare(strict_types=1);

/** Keep frequent heartbeats off job rows containing multi-megabyte training snapshots. */
function community_training_progress_schema(PDO $db):void {
 static $ready=null;$ready??=new WeakMap();if(isset($ready[$db]))return;
 // MySQL CREATE TABLE IF NOT EXISTS commits even when the table already exists.
 // Constructors can be called inside authorization/result transactions.
 if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
  $exists=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='community_learning_progress'")->fetchColumn();
  if((int)$exists===1){$ready[$db]=true;return;}
  if($db->inTransaction())throw new RuntimeException('Initialize training progress storage before starting a transaction.');
 }
 $suffix=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
 $db->exec('CREATE TABLE IF NOT EXISTS community_learning_progress (job_id CHAR(32) PRIMARY KEY,heartbeat_at BIGINT NOT NULL,message VARCHAR(1000) NOT NULL,checkpoint_json TEXT NULL)'.$suffix);
 $ready[$db]=true;
}
function community_training_progress_read(PDO $db,string $id):?array {
 $q=$db->prepare('SELECT heartbeat_at,message,checkpoint_json FROM community_learning_progress WHERE job_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function community_training_progress_write(PDO $db,string $id,string $message,?array $checkpoint=null):void {
 $old=community_training_progress_read($db,$id);$fields=[];
 if($checkpoint!==null){
  foreach(['matches_done','matches_total'] as $key)if(isset($checkpoint[$key])&&is_int($checkpoint[$key])&&$checkpoint[$key]>=0)$fields[$key]=$checkpoint[$key];
  foreach(['current_match','phase'] as $key)if(isset($checkpoint[$key])&&is_string($checkpoint[$key]))$fields[$key]=mb_substr($checkpoint[$key],0,200);
 }
 $json=$checkpoint===null?($old['checkpoint_json']??null):json_encode($fields,JSON_THROW_ON_ERROR);
 $now=time();$message=mb_substr($message,0,900);
 if($old&&(int)$old['heartbeat_at']===$now&&$old['message']===$message&&$old['checkpoint_json']===$json)return;
 $q=$db->prepare($old?'UPDATE community_learning_progress SET heartbeat_at=?,message=?,checkpoint_json=? WHERE job_id=?':'INSERT INTO community_learning_progress(heartbeat_at,message,checkpoint_json,job_id) VALUES(?,?,?,?)');
 $q->execute([$now,$message,$json,$id]);
}
function community_training_progress_overlay(PDO $db,array $row):array {
 $p=community_training_progress_read($db,(string)$row['id']);if(!$p)return $row;
 if(isset($row['control_json'])&&$row['control_json']&&$p['checkpoint_json']){
  $control=json_decode($row['control_json'],true,128,JSON_THROW_ON_ERROR);
  $row['control_json']=json_encode(array_replace($control,json_decode($p['checkpoint_json'],true,16,JSON_THROW_ON_ERROR)),JSON_THROW_ON_ERROR);
 }
 if(in_array($row['state']??'', ['remote_running','running','test_running','evaluation_running'],true)&&(int)$p['heartbeat_at']>=(int)($row['heartbeat_at']??0)){
  $row['heartbeat_at']=(int)$p['heartbeat_at'];$row['message']=$p['message'];
 }
 return $row;
}
/** Correlated primary-key lookup supports MySQL and SQLite without stale-lease claims. */
function community_training_progress_heartbeat_sql(string $alias='community_learning_jobs'):string {
 if(!preg_match('/^[a-z_]+$/D',$alias))throw new InvalidArgumentException('Invalid internal table alias');
 return 'CAST(COALESCE((SELECT p.heartbeat_at FROM community_learning_progress p WHERE p.job_id='.$alias.'.id AND p.heartbeat_at>COALESCE('.$alias.'.heartbeat_at,0)),'.$alias.'.heartbeat_at,0) AS DECIMAL(20,0))';
}
