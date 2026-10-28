<?php
/** Reconcile genuine saved scouting work against the assignee's active assignments.
 * This never deletes tasks or scouting data; every transition is audited. */
declare(strict_types=1);
function nac_finish_assignments(PDO $pdo, int $org, string $kind, ?int $eventId=null, ?int $item=null, ?int $scouter=null): int {
    if($org<1 || !in_array($kind,['match','pit'],true))return 0;
    $params=[$org];
    $where="a.organization_id=? AND a.kind='".$kind."' AND a.status IN ('assigned','acknowledged')";
    if($eventId!==null){$where.=' AND a.event_id=?';$params[]=$eventId;}
    if($item!==null){$where.=' AND a.'.($kind==='match'?'match_id':'frc_team_number').'=?';$params[]=$item;}
    if($scouter!==null){$where.=' AND a.assignee_user_id=?';$params[]=$scouter;}
    if($kind==='pit'){
        $evidence="EXISTS (SELECT 1 FROM pit_scouting ps
           WHERE ps.organization_id=a.organization_id AND ps.event_id=a.event_id
             AND ps.frc_team_number=a.frc_team_number AND ps.status='complete'
             AND ps.submitted_by=a.assignee_user_id AND ps.completed_at >= a.assigned_at)";
    }else{
        // Keep the Tag and traditional predicates independent. A malformed or
        // incompatible traditional schema must never block valid Tag reports.
        // Both report and assignment timestamps are stored as UTC datetimes.
        $evidence="EXISTS (SELECT 1 FROM tag_scouting_match_data t
            WHERE t.organization_id=a.organization_id AND t.event_id=a.event_id
              AND t.match_id=a.match_id AND t.user_id=a.assignee_user_id
              AND LOWER(t.alliance)=LOWER(a.alliance) AND t.station=a.station
              AND COALESCE(a.scouting_mode,'either') IN ('tag','either')
              AND t.updated_at >= DATE_SUB(a.assigned_at, INTERVAL TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP()) SECOND))";
    }
    $sql="SELECT a.id,a.event_id,a.assignee_user_id,a.status
          FROM scouting_assignments a WHERE $where AND $evidence ORDER BY a.id ASC LIMIT 500";
    $sel=$pdo->prepare($sql);$sel->execute($params);
    $candidates=$sel->fetchAll(PDO::FETCH_ASSOC);
    if(!$candidates)return 0;
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try {
        $update=$pdo->prepare("UPDATE scouting_assignments SET status='completed',completed_at=UTC_TIMESTAMP(),completed_by=assignee_user_id,updated_by=assignee_user_id
          WHERE id=? AND organization_id=? AND event_id=? AND assignee_user_id=? AND status IN ('assigned','acknowledged')");
        $audit=$pdo->prepare('INSERT INTO scouting_assignment_history
            (organization_id,event_id,assignment_id,action,previous_user_id,new_user_id,previous_status,new_status,actor_user_id,notes,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
        $finished=0;
        foreach($candidates as $r){
            $id=(int)$r['id'];$evt=(int)$r['event_id'];$who=(int)$r['assignee_user_id'];
            $update->execute([$id,$org,$evt,$who]);
            if($update->rowCount()!==1)continue;
            $audit->execute([$org,$evt,$id,'completed',$who,$who,(string)$r['status'],'completed',$who,
                $kind==='pit'?'Auto-completed after saved complete pit scouting record':'Auto-completed from saved Tag Scouting report']);
            $finished++;
        }
        if($owns)$pdo->commit();
        return $finished;
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
/** Legacy traditional match completion is independent of Tag completion.
 * Run only after a match ends; failures are logged and cannot prevent Tag closure. */
function nac_finish_traditional_assignments(PDO $pdo, int $org, ?int $eventId=null, ?int $matchId=null, ?int $scouter=null): int {
    $params=[$org];
    $where="a.organization_id=? AND a.kind='match' AND a.status IN ('assigned','acknowledged') AND COALESCE(a.scouting_mode,'either') IN ('traditional','either')";
    if($eventId!==null){$where.=' AND a.event_id=?';$params[]=$eventId;}
    if($matchId!==null){$where.=' AND a.match_id=?';$params[]=$matchId;}
    if($scouter!==null){$where.=' AND a.assignee_user_id=?';$params[]=$scouter;}
    $sql="SELECT a.id,a.event_id,a.assignee_user_id,a.status FROM scouting_assignments a
      JOIN matches m ON m.id=a.match_id AND m.organization_id=a.organization_id AND m.event_id=a.event_id
      WHERE $where AND m.state='ended' AND EXISTS(
       SELECT 1 FROM scouting_actions act
       JOIN scout_sessions ss ON ss.id=act.scout_session_id AND ss.organization_id=act.organization_id AND ss.match_id=act.match_id
       WHERE act.organization_id=a.organization_id AND act.match_id=a.match_id
         AND act.match_run_number=m.run_number AND act.deleted_at IS NULL
         AND act.created_by=a.assignee_user_id AND ss.user_id=a.assignee_user_id
         AND act.recorded_at>=a.assigned_at AND ss.alliance=a.alliance AND ss.station=a.station
      ) ORDER BY a.id LIMIT 500";
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows)return 0;
    $owns=!$pdo->inTransaction(); if($owns)$pdo->beginTransaction();
    try{
       $upd=$pdo->prepare("UPDATE scouting_assignments SET status='completed',completed_at=UTC_TIMESTAMP(),completed_by=assignee_user_id,updated_by=assignee_user_id WHERE id=? AND organization_id=? AND status IN ('assigned','acknowledged')");
       $audit=$pdo->prepare("INSERT INTO scouting_assignment_history (organization_id,event_id,assignment_id,action,previous_user_id,new_user_id,previous_status,new_status,actor_user_id,notes,created_at) VALUES (?,?,?,'completed',?,?,?,'completed',?,'Auto-completed after ended traditional match scouting',UTC_TIMESTAMP())");
       $count=0;
       foreach($rows as $r){$upd->execute([(int)$r['id'],$org]);if($upd->rowCount()===1){$audit->execute([$org,(int)$r['event_id'],(int)$r['id'],(int)$r['assignee_user_id'],(int)$r['assignee_user_id'],(string)$r['status'],(int)$r['assignee_user_id']]);$count++;}}
       if($owns)$pdo->commit();return $count;
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
/** Safe hook from report-save handlers: a failed optional reconciliation never undoes the saved report. */
function nac_try_finish(PDO $pdo,int $org,string $kind,?int $eventId=null,?int $item=null,?int $userId=null): void {
    try{nac_finish_assignments($pdo,$org,$kind,$eventId,$item,$userId);}
    catch(Throwable $e){error_log('[Neptune assignment Tag/pit auto completion] '.get_class($e).': '.$e->getMessage());}
    if($kind==='match')try{nac_finish_traditional_assignments($pdo,$org,$eventId,$item,$userId);}
    catch(Throwable $e){error_log('[Neptune assignment traditional auto completion] '.get_class($e).': '.$e->getMessage());}
}
/** Sweep every open assignment group. Used by the sync writers (rows arrive without
 * a save hook) and by the scheduler tick (catches anything a hook missed).
 * Each (org,event,kind) group is reconciled independently; one failure never
 * blocks the others. Returns counts for telemetry. */
function nac_sweep_open(PDO $pdo, ?int $org=null, ?int $eventId=null, ?array $kinds=null): array {
    $out=['groups'=>0,'completed'=>0,'errors'=>0];
    $kinds=$kinds?array_values(array_intersect($kinds,['match','pit'])):['match','pit'];
    if(!$kinds)return $out;
    $where="status IN ('assigned','acknowledged') AND kind IN ('".implode("','",$kinds)."')";
    $params=[];
    if($org!==null){$where.=' AND organization_id=?';$params[]=$org;}
    if($eventId!==null){$where.=' AND event_id=?';$params[]=$eventId;}
    $q=$pdo->prepare("SELECT DISTINCT organization_id,event_id,kind FROM scouting_assignments WHERE $where ORDER BY organization_id,event_id,kind LIMIT 200");
    $q->execute($params);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $g){
        $gOrg=(int)$g['organization_id'];$gEvent=(int)$g['event_id'];$gKind=(string)$g['kind'];
        $out['groups']++;
        try{$out['completed']+=nac_finish_assignments($pdo,$gOrg,$gKind,$gEvent);}
        catch(Throwable $e){$out['errors']++;error_log('[Neptune assignment sweep] '.$gKind.' '.get_class($e).': '.$e->getMessage());}
        if($gKind==='match')try{$out['completed']+=nac_finish_traditional_assignments($pdo,$gOrg,$gEvent);}
        catch(Throwable $e){$out['errors']++;error_log('[Neptune assignment sweep] traditional '.get_class($e).': '.$e->getMessage());}
    }
    return $out;
}
/** Hook for sync endpoints: runs after the batch has committed and never fails the response. */
function nac_try_sweep(PDO $pdo, int $org, ?int $eventId=null, ?array $kinds=null): void {
    try{nac_sweep_open($pdo,$org,$eventId,$kinds);}
    catch(Throwable $e){error_log('[Neptune assignment sweep] '.get_class($e).': '.$e->getMessage());}
}
