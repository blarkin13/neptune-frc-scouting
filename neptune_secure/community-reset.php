<?php
declare(strict_types=1);
require_once __DIR__.'/community-training-progress.php';
/** Called only by the platform-owner, CSRF-protected training page. No filesystem paths accepted. */
function community_reset_machine_data(PDO $db,string $confirmation):array {
 if($confirmation!=='RESET MACHINE DATA')throw new CommunityAnalysisError(422,'Type RESET MACHINE DATA to confirm.');
 community_training_progress_schema($db);$db->beginTransaction();try{
  $sql="SELECT id FROM community_learning_jobs WHERE state IN ('remote_queued','remote_running','queued','running','test_queued','test_running','evaluation_queued','evaluation_running')";
  if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')$sql.=' FOR UPDATE';
  if($db->query($sql)->fetchColumn())throw new CommunityAnalysisError(409,'Stop the active run and wait for it to pause before resetting.');
  $keep=['community_video_labels','community_video_label_reviews','community_matches','community_match_catalog'];$hashes=[];$counts=[];
  foreach($keep as $t){$rows=$db->query("SELECT * FROM `$t` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);$hashes[$t]=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));$counts[$t]=count($rows);}
  foreach(['community_learning_progress','community_learning_releases','community_training_leases','community_learning_jobs','community_publications','community_submissions','community_leases','community_attempt_media','community_attempts','community_external_observations','community_external_imports','community_pair_requests','community_pair_limits'] as $t)$db->exec("DELETE FROM `$t`");
  $db->exec('UPDATE community_training_computers SET revoked=1');$db->exec('UPDATE community_devices SET revoked=1');
  foreach($keep as $t)if(!hash_equals($hashes[$t],hash('sha256',json_encode($db->query("SELECT * FROM `$t` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR))))throw new RuntimeException('Human data preservation check failed.');
  $db->commit();return ['ok'=>true,'preserved'=>$counts,'message'=>'Machine data reset. Human tags, scoring estimates and match links were kept. Helpers were disconnected. Local video caches remain on their computers.'];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
