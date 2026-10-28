<?php
/** QA Center reset: clears test data for the dedicated QA events ONLY. Never touches any other event. */
declare(strict_types=1);
require_once __DIR__.'/_qa_engine.php';

function nq_reset_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_qa_reset_log (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      organization_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL,
      scope VARCHAR(40) NOT NULL,
      match_ids_json TEXT NOT NULL,
      counts_json TEXT NOT NULL,
      created_at DATETIME NOT NULL,
      KEY ix_qa_reset_org (organization_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // A compact copy of each run is kept when its test data is reset, so pass rates and exit reasons survive a reset.
    $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_qa_run_archive (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      organization_id BIGINT UNSIGNED NOT NULL,
      original_run_id BIGINT UNSIGNED NOT NULL,
      scenario VARCHAR(32) NOT NULL,
      tester_user_id BIGINT UNSIGNED NOT NULL,
      event_id BIGINT UNSIGNED NOT NULL,
      match_id BIGINT UNSIGNED NOT NULL,
      frc_team_number INT UNSIGNED NOT NULL,
      status VARCHAR(16) NOT NULL,
      exit_reason VARCHAR(24) NULL,
      created_at DATETIME NOT NULL,
      completed_at DATETIME NULL,
      archived_at DATETIME NOT NULL,
      UNIQUE KEY uq_qa_archive_run (organization_id,original_run_id),
      KEY ix_qa_archive_org (organization_id,scenario,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function nq_reset_table_exists(PDO $pdo,string $table): bool {
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$table]);return (int)$q->fetchColumn()>0;
}
/** Every QA match of this organization (dedicated event name+code, no TBA key, isolated virtual field). */
function nq_reset_matches(PDO $pdo,int $org): array {
    $q=$pdo->prepare("SELECT m.id,m.event_id,m.match_number,m.field_id,m.state,m.run_number,e.name event_name,e.event_code,e.tba_event_key
      FROM matches m JOIN events e ON e.id=m.event_id AND e.organization_id=m.organization_id
      WHERE m.organization_id=? AND e.name IN ('QA Test Event 1','QA Test Event 2') ORDER BY e.name,m.match_number");
    $q->execute([$org]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $m){
        $slot=nq_qa_slot($m);
        if($slot===null||(int)$m['field_id']!==100+$slot*10+(int)$m['match_number'])continue;
        $m['slot']=$slot;$out[]=$m;
    }
    return $out;
}
/** Resolve 'all' | 'event:<slot>' | 'match:<id>' to QA matches only. Anything else is refused. */
function nq_reset_resolve(PDO $pdo,int $org,string $scope): array {
    $all=nq_reset_matches($pdo,$org);
    if($scope==='all')$sel=$all;
    elseif(preg_match('/^event:([12])$/D',$scope,$m))$sel=array_values(array_filter($all,static fn($x)=>$x['slot']===(int)$m[1]));
    elseif(preg_match('/^match:([0-9]+)$/D',$scope,$m))$sel=array_values(array_filter($all,static fn($x)=>(int)$x['id']===(int)$m[1]));
    else throw new InvalidArgumentException('Choose what to reset.');
    if(!$sel)throw new InvalidArgumentException('That target is not a dedicated QA match in your organization. Nothing was changed.');
    return $sel;
}
/** Order matters: children before parents. 'sub' = clause needs the assignment sub-select (extra org param). */
function nq_reset_specs(string $in): array {
    $byMatch="organization_id=? AND match_id IN ($in)";
    $viaAssign="organization_id=? AND assignment_id IN (SELECT id FROM scouting_assignments WHERE organization_id=? AND match_id IN ($in))";
    return [
      ['scouting_actions',$byMatch,0],['scout_sessions',$byMatch,0],
      ['tag_scouting_match_data',$byMatch,0],['tag_scouting_match_metrics',$byMatch,0],
      ['tag_scouting_observations',$byMatch,0],
      ['tag_manual_alliance_score_history',$byMatch,0],['tag_manual_alliance_scores',$byMatch,0],
      ['scouting_assignment_notifications',$viaAssign,1],['scouting_assignments',$byMatch,0],
      ['match_strategies',$byMatch,0],['pit_match_board_match_state',$byMatch,0],
      ['augur_depa_team_match_evidence',$byMatch,0],['neptune_portable_match_bases',$byMatch,0],
      ['neptune_quality_review_audit',"organization_id=? AND flag_key IN (SELECT LEFT(SHA2(CONCAT('coverage:',qm.id),256),48) FROM matches qm WHERE qm.organization_id=? AND qm.id IN ($in))",1],
      ['neptune_quality_reviews',"organization_id=? AND flag_key IN (SELECT LEFT(SHA2(CONCAT('coverage:',qm.id),256),48) FROM matches qm WHERE qm.organization_id=? AND qm.id IN ($in))",1],
    ];
}
function nq_reset_params(array $spec,int $org,array $ids): array {
    return $spec[2]?array_merge([$org,$org],$ids):array_merge([$org],$ids);
}
/** Read-only preview: how many rows a reset of these matches would delete, per table. */
function nq_reset_counts(PDO $pdo,int $org,array $matches): array {
    $ids=array_map(static fn($m)=>(int)$m['id'],$matches);if(!$ids)return [];
    $in=implode(',',array_fill(0,count($ids),'?'));$counts=[];
    foreach(nq_reset_specs($in) as $spec){
        if(!nq_reset_table_exists($pdo,$spec[0]))continue;
        $q=$pdo->prepare('SELECT COUNT(*) FROM `'.$spec[0].'` WHERE '.$spec[1]);$q->execute(nq_reset_params($spec,$org,$ids));
        $n=(int)$q->fetchColumn();if($n>0)$counts[$spec[0]]=$n;
    }
    return $counts;
}
/** Deletes the scouting data of the selected QA matches and returns them to READY. Test runs, results, debrief answers and feedback reports are kept. */
function nq_reset_execute(PDO $pdo,int $org,int $userId,string $scope): array {
    nq_reset_install($pdo);
    $pdo->beginTransaction();
    try{
        $matches=nq_reset_resolve($pdo,$org,$scope);
        $ids=array_map(static fn($m)=>(int)$m['id'],$matches);
        $in=implode(',',array_fill(0,count($ids),'?'));$counts=[];
        // Test RESULTS are never deleted by a reset. Finished runs (passed / failed / exited) keep their result and evidence.
        // A run that has not finished can no longer be completed once its match is wiped, so it is closed as "reset".
        $closeRuns=$pdo->prepare("UPDATE neptune_qa_runs SET status='abandoned',exit_reason='reset',exit_note='The QA data for this match was reset by a QA manager before the test finished.',exited_at=UTC_TIMESTAMP(),completed_at=COALESCE(completed_at,UTC_TIMESTAMP()) WHERE organization_id=? AND match_id IN ($in) AND status IN ('open','in_progress')");
        $closeRuns->execute(array_merge([$org],$ids));
        $closed=$closeRuns->rowCount();
        if($closed>0)$counts['unfinished_runs_closed']=$closed;
        foreach(nq_reset_specs($in) as $spec){
            if(!nq_reset_table_exists($pdo,$spec[0]))continue;
            $d=$pdo->prepare('DELETE FROM `'.$spec[0].'` WHERE '.$spec[1]);$d->execute(nq_reset_params($spec,$org,$ids));
            if($d->rowCount()>0)$counts[$spec[0]]=$d->rowCount();
        }
        // Derived per-event Tag ratings for the QA events are regenerated from scratch later.
        $eventIds=array_values(array_unique(array_map(static fn($m)=>(int)$m['event_id'],$matches)));
        if(nq_reset_table_exists($pdo,'augur_tag_event_ratings')){
            $ein=implode(',',array_fill(0,count($eventIds),'?'));
            $d=$pdo->prepare("DELETE FROM augur_tag_event_ratings WHERE organization_id=? AND event_id IN ($ein)");$d->execute(array_merge([$org],$eventIds));
            if($d->rowCount()>0)$counts['augur_tag_event_ratings']=$d->rowCount();
        }
        // Back to READY. The run number moves forward so a stale device or queued upload can never match the fresh run.
        $u=$pdo->prepare("UPDATE matches SET state='ready',started_at=NULL,ended_at=NULL,paused_at=NULL,total_pause_seconds=0,red_score=NULL,blue_score=NULL,winning_alliance='Unknown',run_number=run_number+1 WHERE organization_id=? AND id IN ($in)");
        $u->execute(array_merge([$org],$ids));$counts['matches_returned_to_ready']=$u->rowCount();
        $pdo->prepare('INSERT INTO neptune_qa_reset_log (organization_id,user_id,scope,match_ids_json,counts_json,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())')
            ->execute([$org,$userId,$scope,json_encode($ids),json_encode($counts)]);
        $pdo->commit();
        return ['matches'=>count($ids),'counts'=>$counts];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Runs removed by an earlier version of Reset were archived. Put their results back (same id, so feedback links reconnect). Safe to repeat. */
function nq_restore_archived_runs(PDO $pdo): int {
    if(!nq_reset_table_exists($pdo,'neptune_qa_run_archive')||!nq_reset_table_exists($pdo,'neptune_qa_runs'))return 0;
    try{
        $q=$pdo->query("SELECT a.* FROM neptune_qa_run_archive a WHERE NOT EXISTS (SELECT 1 FROM neptune_qa_runs r WHERE r.id=a.original_run_id) ORDER BY a.original_run_id LIMIT 500");
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$n=0;
        foreach($rows as $a){
            $t=$pdo->prepare('SELECT alliance,station FROM match_teams WHERE match_id=? AND frc_team_number=? LIMIT 1');$t->execute([(int)$a['match_id'],(int)$a['frc_team_number']]);$mt=$t->fetch(PDO::FETCH_ASSOC);
            if(!$mt)continue; // the match no longer exists; nothing sensible to restore
            $status=in_array((string)$a['status'],['passed','failed','abandoned'],true)?(string)$a['status']:'abandoned';
            $reason=$status==='abandoned'?((string)($a['exit_reason']??'')!==''?(string)$a['exit_reason']:'reset'):null;
            $evidence=json_encode(['status'=>$status,'steps'=>[],'evidence'=>['restored_from_archive'=>true,'note'=>'The detailed step evidence was removed by an earlier reset. The result was restored from the archive.']],JSON_UNESCAPED_SLASHES);
            $ins=$pdo->prepare("INSERT IGNORE INTO neptune_qa_runs (id,organization_id,created_by,tester_user_id,event_id,match_id,frc_team_number,alliance,station,scenario,baseline_run,status,exit_reason,evidence_json,created_at,completed_at,exited_at) VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?)");
            $ins->execute([(int)$a['original_run_id'],(int)$a['organization_id'],(int)$a['tester_user_id'],(int)$a['tester_user_id'],(int)$a['event_id'],(int)$a['match_id'],(int)$a['frc_team_number'],$mt['alliance'],(int)$mt['station'],(string)$a['scenario'],$status,$reason,$evidence,$a['created_at'],$a['completed_at'],$status==='abandoned'?$a['archived_at']:null]);
            $n+=$ins->rowCount();
        }
        return $n;
    }catch(Throwable $e){error_log('[Neptune QA restore] '.$e->getMessage());return 0;}
}
