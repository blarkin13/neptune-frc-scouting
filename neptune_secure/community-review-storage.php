<?php
declare(strict_types=1);

/** Owner-scoped, source-bound backup of a helper's robot identity review. */
final class CommunityReviewStorage {
 public function __construct(private PDO $db) {}
 public function schema():void {
  if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
   $this->db->exec('CREATE TABLE IF NOT EXISTS community_identity_reviews (organization_id INTEGER NOT NULL,source_sha256 TEXT NOT NULL,match_key TEXT NOT NULL,video_id TEXT NOT NULL,roster_sha256 TEXT NOT NULL,revision TEXT NOT NULL,review_gzip BLOB NOT NULL,updated_at INTEGER NOT NULL,updated_by INTEGER NOT NULL,PRIMARY KEY(organization_id,source_sha256))');
   $this->db->exec('CREATE INDEX IF NOT EXISTS community_identity_reviews_by_match ON community_identity_reviews(organization_id,match_key)');
   return;
  }
  $this->db->exec("CREATE TABLE IF NOT EXISTS community_identity_reviews (
   organization_id BIGINT NOT NULL,
   source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
   match_key VARCHAR(100) NOT NULL,
   video_id CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
   roster_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
   revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
   review_gzip LONGBLOB NOT NULL,
   updated_at BIGINT NOT NULL,
   updated_by BIGINT NOT NULL,
   PRIMARY KEY (organization_id,source_sha256),
   KEY by_match (organization_id,match_key)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 }
 private function source(array $p):string {
  $source=$p['source_sha256']??null;
  if(!is_string($source)||!preg_match('/^[a-f0-9]{64}$/D',$source))throw new CommunityAnalysisError(422,'Invalid video fingerprint.');
  return $source;
 }
 public function listing(array $computer):array {
  $q=$this->db->prepare('SELECT source_sha256,match_key,video_id,updated_at FROM community_identity_reviews WHERE organization_id=? ORDER BY updated_at DESC LIMIT 500');
  $q->execute([(int)$computer['organization_id']]);return ['reviews'=>$q->fetchAll(PDO::FETCH_ASSOC)];
 }
 public function get(array $computer,array $p):array {
  $source=$this->source($p);$org=(int)$computer['organization_id'];
  $q=$this->db->prepare('SELECT match_key,video_id,roster_sha256,revision,review_gzip,updated_at FROM community_identity_reviews WHERE organization_id=? AND source_sha256=?');
  $q->execute([$org,$source]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row)return ['found'=>false];
  return ['found'=>true,'source_sha256'=>$source,'match_key'=>$row['match_key'],'video_id'=>$row['video_id'],'roster_sha256'=>$row['roster_sha256'],'revision'=>$row['revision'],'review_gzip_b64'=>base64_encode($row['review_gzip']),'updated_at'=>(int)$row['updated_at']];
 }
 public function put(array $computer,array $p):array {
  $source=$this->source($p);
  $b64=$p['review_gzip_b64']??null;
  if(!is_string($b64)||strlen($b64)>3*1024*1024||!preg_match('/^[A-Za-z0-9+\/=]+$/D',$b64))throw new CommunityAnalysisError(413,'Review exceeds the storage limit.');
  $gzip=base64_decode($b64,true);
  if($gzip===false||strlen($gzip)>2*1024*1024)throw new CommunityAnalysisError(413,'Review exceeds the storage limit.');
  $raw=@gzdecode($gzip,16*1024*1024);
  if($raw===false||strlen($raw)>16*1024*1024)throw new CommunityAnalysisError(422,'Invalid compressed review.');
  $review=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
  if(!is_array($review)||($review['schema_version']??null)!==1||($review['source_sha256']??null)!==$source||!is_array($review['points']??null)||count($review['points'])>100000||!is_array($review['corrections']??null)||count($review['corrections'])>1000)throw new CommunityAnalysisError(422,'Invalid tracking review.');
  $match=$review['match_key']??null;
  if(!is_string($match)||!preg_match('/^\d{4}[A-Za-z0-9_-]{1,95}$/D',$match))throw new CommunityAnalysisError(422,'Invalid review match.');
  $videoId=$review['video_id']??null;
  if(!is_string($videoId)||!preg_match('/^[A-Za-z0-9_-]{11}$/D',$videoId))throw new CommunityAnalysisError(422,'Review needs its verified video ID.');
  $roster=$review['roster']??null;
  if(!is_array($roster)||array_keys($roster)!==['red','blue'])throw new CommunityAnalysisError(422,'Invalid review roster.');
  foreach(['red','blue'] as $alliance){$teams=$roster[$alliance];if(!is_array($teams)||count($teams)!==3||count(array_unique($teams))!==3)throw new CommunityAnalysisError(422,'Invalid review roster.');foreach($teams as $team)if(!is_int($team)||$team<1||$team>99999)throw new CommunityAnalysisError(422,'Invalid review roster.');}
  $rosterHash=hash('sha256',json_encode($roster,JSON_THROW_ON_ERROR));$revision=hash('sha256',$raw);
  $expected=$p['expected_revision']??null;
  if($expected!==null&&(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)))throw new CommunityAnalysisError(422,'Invalid expected revision.');
  $org=(int)$computer['organization_id'];$this->db->beginTransaction();
  try {
   $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
   $q=$this->db->prepare('SELECT revision,match_key,video_id,roster_sha256 FROM community_identity_reviews WHERE organization_id=? AND source_sha256=?'.$lock);$q->execute([$org,$source]);$current=$q->fetch(PDO::FETCH_ASSOC);
   if($current&&$current['revision']===$revision){$this->db->commit();return ['ok'=>true,'revision'=>$revision,'unchanged'=>true];}
   if(($current['revision']??null)!==$expected)throw new CommunityAnalysisError(409,'A newer tracking review is saved on Neptune. Your local review was kept. Reopen it to resolve the difference.');
   if($current&&($current['match_key']!==$match||$current['video_id']!==$videoId||$current['roster_sha256']!==$rosterHash))throw new CommunityAnalysisError(409,'Saved review match, video or roster changed.');
   if($current){$q=$this->db->prepare('UPDATE community_identity_reviews SET revision=?,review_gzip=?,updated_at=?,updated_by=? WHERE organization_id=? AND source_sha256=?');$q->execute([$revision,$gzip,time(),$computer['user_id'],$org,$source]);}
   else{$q=$this->db->prepare('INSERT INTO community_identity_reviews(organization_id,source_sha256,match_key,video_id,roster_sha256,revision,review_gzip,updated_at,updated_by) VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$org,$source,$match,$videoId,$rosterHash,$revision,$gzip,time(),$computer['user_id']]);}
   $this->db->commit();return ['ok'=>true,'revision'=>$revision,'unchanged'=>false];
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
