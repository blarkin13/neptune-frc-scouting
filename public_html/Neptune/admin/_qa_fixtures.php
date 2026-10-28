<?php
/** Manager-initiated, additive provisioning of dedicated QA events and fresh fixtures. */
declare(strict_types=1);
function nq_qa_events(PDO $pdo,int $org): array {
    $q=$pdo->prepare("SELECT e.id,e.name,e.event_code,e.game_id,e.game_revision_id,e.tba_event_key,
      (SELECT COUNT(*) FROM matches m WHERE m.event_id=e.id AND m.organization_id=e.organization_id) match_count
      FROM events e WHERE e.organization_id=? AND e.name IN ('QA Test Event 1','QA Test Event 2') ORDER BY e.name");
    $q->execute([$org]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function nq_source_events(PDO $pdo,int $org): array {
    // Only source organization-owned published games with real six-robot match assignments.
    $q=$pdo->prepare("SELECT e.id,e.name,g.name game_name,g.season_year,gr.revision_number,
      (SELECT COUNT(*) FROM matches m WHERE m.event_id=e.id AND m.organization_id=e.organization_id AND m.comp_level='qm' AND
        (SELECT COUNT(*) FROM match_teams mt WHERE mt.match_id=m.id)=6) complete_quals
      FROM events e JOIN games g ON g.id=e.game_id AND g.organization_id=e.organization_id
      JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id AND gr.organization_id=e.organization_id AND gr.status='published'
      WHERE e.organization_id=? AND e.name NOT IN ('QA Test Event 1','QA Test Event 2')
      ORDER BY (e.name='Test Event') DESC, g.season_year DESC, e.id DESC LIMIT 100");
    $q->execute([$org]);return array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),static fn($e)=>(int)$e['complete_quals']>0));
}
function nq_seed_events(PDO $pdo,int $org,int $sourceEventId): array {
    $pdo->beginTransaction();
    try{
        $source=$pdo->prepare("SELECT e.id,e.organization_id,e.game_id,e.game_revision_id,e.name,gr.status revision_status
          FROM events e JOIN games g ON g.id=e.game_id AND g.organization_id=e.organization_id
          JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id AND gr.organization_id=e.organization_id
          WHERE e.id=? AND e.organization_id=? LIMIT 1 FOR UPDATE");
        $source->execute([$sourceEventId,$org]);$s=$source->fetch(PDO::FETCH_ASSOC);
        if(!$s || $s['revision_status']!=='published' || in_array($s['name'],['QA Test Event 1','QA Test Event 2'],true))throw new InvalidArgumentException('Select an organization-owned event with a published game revision.');
        $qual=$pdo->prepare("SELECT m.id,m.match_number FROM matches m WHERE m.event_id=? AND m.organization_id=? AND m.comp_level='qm'
          AND (SELECT COUNT(*) FROM match_teams mt WHERE mt.match_id=m.id)=6 ORDER BY m.match_number ASC,m.id ASC LIMIT 6");
        $qual->execute([$sourceEventId,$org]);$fixtures=$qual->fetchAll(PDO::FETCH_ASSOC);
        if(!$fixtures)throw new InvalidArgumentException('The source event has no complete qualification-match team assignments.');
        $teamQuery=$pdo->prepare('SELECT frc_team_number,alliance,station FROM match_teams WHERE match_id=? ORDER BY alliance,station');
        $roster=[];foreach($fixtures as $f){$teamQuery->execute([(int)$f['id']]);$members=$teamQuery->fetchAll(PDO::FETCH_ASSOC);if(count($members)===6)$roster[]=$members;}
        if(!$roster)throw new RuntimeException('No valid six-team fixtures were found.');
        // Look up existing names before writing. Never take over a similarly named real event.
        $lookup=$pdo->prepare('SELECT id,name,event_code,game_id,game_revision_id,tba_event_key FROM events WHERE organization_id=? AND name=? FOR UPDATE');
        $insertEvent=$pdo->prepare("INSERT INTO events (organization_id,game_id,game_revision_id,name,event_code,tba_event_key,start_date,end_date,active,event_status,is_current) VALUES (?,?,?,?,?,NULL,CURDATE(),CURDATE(),1,'schedule_ready',0)");
        $insertMatch=$pdo->prepare("INSERT INTO matches (organization_id,event_id,game_id,comp_level,set_number,match_number,field_id,state) VALUES (?,?,?,'qm',1,?,?,'ready')");
        $insertTeams=$pdo->prepare('INSERT INTO match_teams (match_id,frc_team_number,alliance,station) VALUES (?,?,?,?)');
        $insertRoster=$pdo->prepare('INSERT IGNORE INTO event_teams (event_id,frc_team_number,nickname,city,state_prov,country,tba_team_key)
            SELECT ?,?,COALESCE(et.nickname,t.nickname),et.city,et.state_prov,et.country,NULL
            FROM organizations o LEFT JOIN event_teams et ON et.event_id=? AND et.frc_team_number=?
            LEFT JOIN teams t ON t.organization_id=o.id AND t.frc_team_number=? WHERE o.id=?');
        $findMatch=$pdo->prepare("SELECT id,field_id FROM matches WHERE organization_id=? AND event_id=? AND comp_level='qm' AND set_number=1 AND match_number=? LIMIT 1");
        $out=[];
        foreach([1,2] as $slot){
            $name='QA Test Event '.$slot;$code='NEPTUNE_QA_'.$slot;
            $lookup->execute([$org,$name]);$existing=$lookup->fetchAll(PDO::FETCH_ASSOC);
            if(count($existing)>1)throw new RuntimeException($name.' exists more than once; no changes applied.');
            if($existing){
                $e=$existing[0];
                if($e['event_code']!==$code || $e['tba_event_key']!==null || (int)$e['game_id']!==(int)$s['game_id'] || (int)$e['game_revision_id']!==(int)$s['game_revision_id']){
                    throw new RuntimeException($name.' already exists with different configuration. Provisioning refused; no existing event was modified.');
                }
                $eventId=(int)$e['id'];
            }else{
                $insertEvent->execute([$org,(int)$s['game_id'],(int)$s['game_revision_id'],$name,$code]);$eventId=(int)$pdo->lastInsertId();
            }
            // Each event gets six clean matches; cloning team fixtures only (no sessions, scores or actions).
            for($n=1;$n<=6;$n++){
                $findMatch->execute([$org,$eventId,$n]);$existingMatch=$findMatch->fetch(PDO::FETCH_ASSOC);
                $qaField=100+$slot*10+$n;
                if($existingMatch){
                    if((int)$existingMatch['field_id']!==$qaField)throw new RuntimeException('Existing '. $name .' match '.$n.' uses another field; refuse to alter it.');
                    continue; // Never overwrite matches already used by testers.
                }
                $members=$roster[($n-1)%count($roster)];
                $insertMatch->execute([$org,$eventId,(int)$s['game_id'],$n,$qaField]);$mid=(int)$pdo->lastInsertId();
                foreach($members as $t){
                    $insertTeams->execute([$mid,(int)$t['frc_team_number'],$t['alliance'],(int)$t['station']]);
                    $insertRoster->execute([$eventId,(int)$t['frc_team_number'],$sourceEventId,(int)$t['frc_team_number'],(int)$t['frc_team_number'],$org]);
                }
            }
            $out[$slot]=['event_id'=>$eventId,'name'=>$name];
        }
        $pdo->commit();return $out;
    }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();throw $ex;}
}

/** Admin-level step done for the tester: once every step except the end (and what the end triggers) has passed on a
 *  Traditional-style run, end the isolated QA match exactly like Match Control would. */
function nq_auto_finish(PDO $pdo,int $org,array $run,array $check): array {
    if(!nq_uses_traditional_scouting((string)($run['scenario']??''))||($check['status']??'')!=='in_progress'||($check['evidence']['current_state']??'')!=='running')return $check;
    $pending=array_values(array_filter($check['steps']??[],static fn($s)=>($s['status']??'')!=='passed'));
    $keys=array_map(static fn($s)=>$s['key'],$pending);
    if(!in_array('end',$keys,true)||array_diff($keys,['end','autoclose']))return $check;
    try{
        nq_control_match($pdo,$org,(int)$run['match_id'],(int)$run['baseline_run'],'end');
        $after=nq_evaluate($pdo,$run);$after['evidence']['auto_ended']=true;return $after;
    }catch(Throwable $ex){error_log('[Neptune QA auto-end] '.$ex->getMessage());return $check;}
}

/** QA-only match controls: never alter is_current, an unrelated match or a production field. */
function nq_control_match(PDO $pdo,int $org,int $matchId,int $expectedRun,string $op): void {
    if(!in_array($op,['start','end'],true))throw new InvalidArgumentException('Unsupported QA match operation.');
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT m.id,m.event_id,m.run_number,m.state,m.field_id,m.match_number,e.name,e.event_code,e.tba_event_key FROM matches m JOIN events e ON e.id=m.event_id AND e.organization_id=m.organization_id WHERE m.id=? AND m.organization_id=? FOR UPDATE');
        $q->execute([$matchId,$org]);$m=$q->fetch(PDO::FETCH_ASSOC);
        if(!$m || nq_qa_slot($m)===null || (int)$m['field_id']!==(100+nq_qa_slot($m)*10+(int)$m['match_number']))throw new RuntimeException('The target is not a dedicated QA match with its isolated virtual field.');
        if((int)$m['run_number']!==$expectedRun)throw new RuntimeException('QA match run changed; start a fresh QA test before continuing.');
        $expectedState=$op==='start'?'ready':'running';
        if($m['state']!==$expectedState)throw new RuntimeException('QA match must be '.$expectedState.' to '.$op.'. It is '.$m['state'].'.');
        if($op==='start'){
            $pdo->prepare("UPDATE matches SET state='running',started_at=UTC_TIMESTAMP(),ended_at=NULL,paused_at=NULL,total_pause_seconds=0 WHERE id=? AND organization_id=? AND state='ready' AND run_number=?")->execute([$matchId,$org,$expectedRun]);
        }else{
            $pdo->prepare("UPDATE matches SET state='ended',ended_at=UTC_TIMESTAMP(),paused_at=NULL WHERE id=? AND organization_id=? AND state='running' AND run_number=?")->execute([$matchId,$org,$expectedRun]);
        }
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    // Match Control runs the assignment auto-completion hook whenever a match ends; do the same for the isolated QA match.
    if($op==='end'){
        $hook=dirname(__DIR__).'/_assignment_auto_complete.php';
        if(is_file($hook)){require_once $hook;nac_try_finish($pdo,$org,'match',(int)$m['event_id'],$matchId);}
    }
}
