<?php
/** Refresh one already-linked Neptune event from TBA. */
require_once dirname(__DIR__,3).'/neptune_secure/tba.php';
require_once dirname(__DIR__).'/analytics/_augur_epa.php';
require_once dirname(__DIR__).'/analytics/_augur_depa.php';
require_once dirname(__DIR__).'/analytics/_augur_opr.php';

function neptune_tba_live_optional(string $path,int $ttl=0): array {
    try{return tba_get($path,$ttl);}catch(Throwable $e){
        if(str_contains($e->getMessage(),'HTTP 404'))return [];
        throw $e;
    }
}

function neptune_tba_refresh_linked_event(PDO $pdo,int $organizationId,int $eventId): array {
    $s=$pdo->prepare("SELECT e.*,g.season_year FROM events e JOIN games g ON g.id=e.game_id WHERE e.id=? AND e.organization_id=? LIMIT 1");
    $s->execute([$eventId,$organizationId]);$event=$s->fetch();
    if(!$event)throw new RuntimeException('Current Neptune event was not found.');
    $eventKey=trim((string)($event['tba_event_key']??''));
    if($eventKey==='')throw new RuntimeException('Current event is not linked to The Blue Alliance.');

    // Fetch network data before opening a database transaction. TTL 0 bypasses
    // Neptune's normal cache for event-live operation.
    $meta=neptune_tba_live_optional('event/'.$eventKey.'/simple',0);
    $teams=neptune_tba_live_optional('event/'.$eventKey.'/teams/simple',0);
    $matches=neptune_tba_live_optional('event/'.$eventKey.'/matches/simple',0);
    // Alliance Selection consumes TBA rankings directly. Warm that shared cache
    // on the same live cadence so every device sees current ranking data too.
    try{augur_epa_tba_get($pdo,'event/'.rawurlencode($eventKey).'/rankings',120,true);}catch(Throwable $ignored){}

    $teamCount=0;$matchCount=0;$scoreCount=0;
    $pdo->beginTransaction();
    try{
        if($meta){
            $pdo->prepare("UPDATE events SET name=COALESCE(NULLIF(?,''),name),event_code=COALESCE(?,event_code),start_date=COALESCE(?,start_date),end_date=COALESCE(?,end_date) WHERE id=? AND organization_id=?")
                ->execute([(string)($meta['name']??''),$meta['event_code']??null,$meta['start_date']??null,$meta['end_date']??null,$eventId,$organizationId]);
        }

        $upTeam=$pdo->prepare("INSERT INTO event_teams(event_id,frc_team_number,nickname,city,state_prov,country,tba_team_key)
            VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nickname=COALESCE(VALUES(nickname),nickname),city=COALESCE(VALUES(city),city),state_prov=COALESCE(VALUES(state_prov),state_prov),country=COALESCE(VALUES(country),country),tba_team_key=COALESCE(VALUES(tba_team_key),tba_team_key)");
        foreach($teams as $t){
            if(!is_array($t))continue;$tn=(int)($t['team_number']??0);if($tn<1)continue;
            $upTeam->execute([$eventId,$tn,$t['nickname']??null,$t['city']??null,$t['state_prov']??null,$t['country']??null,$t['key']??('frc'.$tn)]);$teamCount++;
        }

        $upMatch=$pdo->prepare("INSERT INTO matches
              (organization_id,event_id,game_id,tba_match_key,comp_level,set_number,match_number,field_id,scheduled_time,red_score,blue_score,winning_alliance,state)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              tba_match_key=VALUES(tba_match_key),scheduled_time=VALUES(scheduled_time),red_score=VALUES(red_score),blue_score=VALUES(blue_score),winning_alliance=VALUES(winning_alliance),
              state=IF(VALUES(red_score) IS NOT NULL AND VALUES(blue_score) IS NOT NULL,'ended',state)");
        $findMatch=$pdo->prepare("SELECT id FROM matches WHERE organization_id=? AND event_id=? AND comp_level=? AND set_number=? AND match_number=? AND field_id=1 LIMIT 1");
        $upStation=$pdo->prepare("INSERT INTO match_teams(match_id,frc_team_number,alliance,station) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE frc_team_number=VALUES(frc_team_number)");
        $upRosterKey=$pdo->prepare("INSERT INTO event_teams(event_id,frc_team_number,tba_team_key) VALUES(?,?,?) ON DUPLICATE KEY UPDATE tba_team_key=COALESCE(tba_team_key,VALUES(tba_team_key))");

        foreach($matches as $m){
            if(!is_array($m))continue;
            $level=strtolower((string)($m['comp_level']??'qm'))?:'qm';
            $set=max(1,(int)($m['set_number']??1));$num=max(1,(int)($m['match_number']??1));
            $sched=!empty($m['predicted_time'])?gmdate('Y-m-d H:i:s',(int)$m['predicted_time']):(!empty($m['time'])?gmdate('Y-m-d H:i:s',(int)$m['time']):null);
            $rs=$m['alliances']['red']['score']??null;$bs=$m['alliances']['blue']['score']??null;
            $rs=is_numeric($rs)&&(int)$rs>=0?(int)$rs:null;$bs=is_numeric($bs)&&(int)$bs>=0?(int)$bs:null;
            $winner='Unknown';if($rs!==null&&$bs!==null){$winner=$rs===$bs?'Tie':($rs>$bs?'Red':'Blue');$scoreCount++;}
            $state=$rs!==null&&$bs!==null?'ended':'scheduled';
            $upMatch->execute([$organizationId,$eventId,(int)$event['game_id'],$m['key']??null,$level,$set,$num,1,$sched,$rs,$bs,$winner,$state]);
            $findMatch->execute([$organizationId,$eventId,$level,$set,$num]);$matchId=(int)$findMatch->fetchColumn();if($matchId<1)continue;
            foreach(['red'=>'Red','blue'=>'Blue'] as $ak=>$av){
                foreach((array)($m['alliances'][$ak]['team_keys']??[]) as $i=>$tk){
                    $tn=(int)preg_replace('/\D/','',(string)$tk);if($tn<1)continue;
                    $upStation->execute([$matchId,$tn,$av,$i+1]);
                    $upRosterKey->execute([$eventId,$tn,'frc'.$tn]);
                }
            }
            $matchCount++;
        }

        if($teamCount>0)$pdo->prepare('UPDATE events SET roster_synced_at=UTC_TIMESTAMP() WHERE id=?')->execute([$eventId]);
        if($matchCount>0){
            $pdo->prepare("UPDATE events SET schedule_synced_at=UTC_TIMESTAMP(),event_status=CASE WHEN event_status='complete' THEN 'complete' WHEN ? > 0 THEN 'running' WHEN event_status IN ('planned','pit_open') THEN 'schedule_ready' ELSE event_status END WHERE id=?")
                ->execute([$scoreCount,$eventId]);
        }
        $pdo->prepare('UPDATE events SET last_tba_sync_at=UTC_TIMESTAMP() WHERE id=?')->execute([$eventId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    // Rebuild Public EPA from the full TBA match payload. Rebuild D-EPA only
    // when the event source actually changed, which avoids an expensive
    // season-wide defensive rebuild every two minutes when no score changed.
    $epa=null;$epaSeason=null;$opr=null;$depa=null;$sourceChanged=false;
    if(function_exists('augur_epa_tables_ready')&&augur_epa_tables_ready($pdo)){
        $before=augur_epa_archive_event($pdo,$eventKey);
        $beforeHash=(string)($before['source_hash']??'');
        $epa=augur_epa_ensure_neptune_event($pdo,$organizationId,$eventId,true);
        $after=augur_epa_archive_event($pdo,$eventKey);
        $afterHash=(string)($after['source_hash']??'');
        $sourceChanged=$afterHash!==''&&$afterHash!==$beforeHash;
    }
    if($sourceChanged){
        // A newly scored/changed match affects season-order EPA, traditional OPR
        // and defensive baselines. Rebuild those only when TBA's played-match
        // source hash actually changes, not on every two-minute poll.
        $year=(int)$event['season_year'];
        $epaSeason=augur_epa_recalculate_year($pdo,$year);
        if(function_exists('augur_opr_recalculate_year'))$opr=augur_opr_recalculate_year($pdo,$year);
        if(function_exists('augur_depa_tables_ready')&&augur_depa_tables_ready($pdo))$depa=augur_depa_rebuild_year($pdo,$year);
    }

    return [
        'event_id'=>$eventId,'event_key'=>$eventKey,'event_name'=>(string)$event['name'],
        'teams'=>$teamCount,'matches'=>$matchCount,'scored_matches'=>$scoreCount,
        'epa_source_changed'=>$sourceChanged,'epa'=>$epa,'epa_season'=>$epaSeason,'opr'=>$opr,'depa'=>$depa,
    ];
}
