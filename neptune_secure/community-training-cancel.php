<?php
declare(strict_types=1);

/** Cancel a waiting or running Actions run now, from a helper: only runs that user started. A running job's helper may be
 *  gone (closed, asleep); one still working is refused on its next update and stops. Saved files and human labels are kept.
 *  Kept apart from CommunityTrainingControl so helpers can cancel without redeploying that class. */
function community_training_cancel(PDO $db,string $id,int $owner):array {
 $q=$db->prepare("UPDATE community_learning_jobs SET state='cancelled',finished_at=?,message='Cancelled by the owner. Saved files and human labels are kept.' WHERE id=? AND created_by=? AND state IN ('remote_queued','remote_paused','queued','server_paused','remote_running','running','test_queued','test_running','evaluation_queued','evaluation_running')");
 $q->execute([time(),$id,$owner]);
 if(!$q->rowCount()){$q=$db->prepare('SELECT state FROM community_learning_jobs WHERE id=?');$q->execute([$id]);$state=$q->fetchColumn();throw new CommunityAnalysisError(409,$state===false?'That run was not found. Reload the page.':'This run could not be cancelled (it is '.$state.').');}
 $q=$db->prepare('DELETE FROM community_training_leases WHERE job_id=?');$q->execute([$id]);
 return ['ok'=>true];
}
