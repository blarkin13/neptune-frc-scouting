<?php
declare(strict_types=1);
/**
 * FIRST-only, event-local provisional AUGUR EPA.
 * Reuses AUGUR's EPA update/initialization routines but never writes the public
 * TBA archive or season ratings. This protects Monday's historical backfill and
 * avoids double-counting the same event if TBA later publishes it.
 */
require_once dirname(__DIR__).'/analytics/_augur_epa.php';

function neptune_first_epa_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS augur_first_event_ratings (
        organization_id BIGINT UNSIGNED NOT NULL, event_id BIGINT UNSIGNED NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL, frc_team_number INT UNSIGNED NOT NULL,
        rating DECIMAL(12,5) NOT NULL, auto_rating DECIMAL(12,5) NULL,
        matches_played SMALLINT UNSIGNED NOT NULL DEFAULT 0, source_hash CHAR(64) NOT NULL,
        calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (organization_id,event_id,frc_team_number),
        KEY idx_first_event (event_id,season_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS augur_first_match_ratings (
        organization_id BIGINT UNSIGNED NOT NULL, event_id BIGINT UNSIGNED NOT NULL,
        frc_team_number INT UNSIGNED NOT NULL, match_number SMALLINT UNSIGNED NOT NULL,
        pre_rating DECIMAL(12,5) NOT NULL,post_rating DECIMAL(12,5) NOT NULL,
        pre_auto_rating DECIMAL(12,5) NULL,post_auto_rating DECIMAL(12,5) NULL,
        source_hash CHAR(64) NOT NULL,
        PRIMARY KEY (organization_id,event_id,frc_team_number,match_number),
        KEY idx_first_match (event_id,match_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** @return array{changed:bool,teams:int,matches:int,source:string} */
function neptune_first_epa_rebuild(PDO $pdo,int $org,int $eventId,int $year,array $matches): array {
    if(!augur_epa_tables_ready($pdo))throw new RuntimeException('AUGUR public season baseline is not installed.');
    $played=[];
    foreach($matches as $match){
        if(!is_array($match)||strtolower((string)($match['tournamentLevel']??''))!=='qualification')continue;
        $number=(int)($match['matchNumber']??0);
        $red=$match['scoreRedFinal']??null;$blue=$match['scoreBlueFinal']??null;
        if($number<1 || !is_numeric($red)||!is_numeric($blue)||(float)$red<0||(float)$blue<0||empty($match['postResultTime']))continue;
        $station=[];
        foreach(($match['teams']??[]) as $t){
            $s=(string)($t['station']??'');$n=(int)($t['teamNumber']??0);
            if($n>0&&preg_match('/^(Red|Blue)[123]$/',$s))$station[$s]=$n;
        }
        if(count($station)!==6||count(array_unique($station))!==6)continue;
        ksort($station);
        $played[$number]=[
            'number'=>$number,'teams'=>$station,'red'=>(float)$red,'blue'=>(float)$blue,
            'red_foul'=>is_numeric($match['scoreRedFoul']??null)?max(0,(float)$match['scoreRedFoul']):0.0,
            'blue_foul'=>is_numeric($match['scoreBlueFoul']??null)?max(0,(float)$match['scoreBlueFoul']):0.0,
            'red_auto'=>is_numeric($match['scoreRedAuto']??null)?max(0,(float)$match['scoreRedAuto']):null,
            'blue_auto'=>is_numeric($match['scoreBlueAuto']??null)?max(0,(float)$match['scoreBlueAuto']):null,
        ];
    }
    ksort($played,SORT_NUMERIC);
    // Never overwrite prior ratings with an empty/incomplete feed.
    if(!$played)return ['changed'=>false,'teams'=>0,'matches'=>0,'source'=>'FIRST provisional'];
    $hash=hash('sha256',json_encode(array_values($played),JSON_UNESCAPED_SLASHES));
    neptune_first_epa_install($pdo);
    $q=$pdo->prepare('SELECT source_hash FROM augur_first_event_ratings WHERE organization_id=? AND event_id=? LIMIT 1');
    $q->execute([$org,$eventId]);
    if($q->fetchColumn()===$hash)return ['changed'=>false,'teams'=>0,'matches'=>count($played),'source'=>'FIRST provisional'];

    $stats=augur_epa_year_score_stats($pdo,$year);
    // FIRST-only offseason events are not official public EPA. The same AUGUR
    // learning rule uses the existing public season baseline when available;
    // otherwise derive a provisional baseline only from this event's played scores.
    if(($stats['samples']??0)<1){
        $scores=[];$autos=[];
        foreach($played as $m){$scores[]=max(0,$m['red']-$m['red_foul']);$scores[]=max(0,$m['blue']-$m['blue_foul']);
            foreach(['red_auto','blue_auto'] as $col)if($m[$col]!==null)$autos[]=$m[$col];
        }
        $stats=['mean'=>augur_epa_mean($scores),'sd'=>max(1,augur_epa_stddev($scores)),
            'samples'=>count($scores),'auto_mean'=>$autos?augur_epa_mean($autos):null,
            'teleop_mean'=>null,'endgame_mean'=>null];
    }
    $priors=augur_epa_prior_norm_map($pdo,$year);
    $state=[];$snapshots=[];
    foreach($played as $m){
        foreach($m['teams'] as $team){
            if(!isset($state[$team]))$state[$team]=augur_epa_initial_state($team,$year,$stats,$priors);
        }
        $before=[];
        foreach($m['teams'] as $team)$before[$team]=[$state[$team]['rating'],$state[$team]['auto']];
        $r=[$m['teams']['Red1'],$m['teams']['Red2'],$m['teams']['Red3']];
        $b=[$m['teams']['Blue1'],$m['teams']['Blue2'],$m['teams']['Blue3']];
        augur_epa_update_stream($state,$r,max(0,$m['red']-$m['red_foul']),'rating',$year,1.0);
        augur_epa_update_stream($state,$b,max(0,$m['blue']-$m['blue_foul']),'rating',$year,1.0);
        if($m['red_auto']!==null)augur_epa_update_stream($state,$r,$m['red_auto'],'auto',$year,1.0);
        if($m['blue_auto']!==null)augur_epa_update_stream($state,$b,$m['blue_auto'],'auto',$year,1.0);
        foreach($m['teams'] as $team){
            $snapshots[]=[$org,$eventId,$team,$m['number'],$before[$team][0],$state[$team]['rating'],
                $before[$team][1],$state[$team]['auto'],$hash];
            $state[$team]['count']++;
        }
    }
    $pdo->beginTransaction();
    try{
        $pdo->prepare('DELETE FROM augur_first_match_ratings WHERE organization_id=? AND event_id=?')->execute([$org,$eventId]);
        $pdo->prepare('DELETE FROM augur_first_event_ratings WHERE organization_id=? AND event_id=?')->execute([$org,$eventId]);
        $q=$pdo->prepare('INSERT INTO augur_first_match_ratings (organization_id,event_id,frc_team_number,match_number,pre_rating,post_rating,pre_auto_rating,post_auto_rating,source_hash) VALUES(?,?,?,?,?,?,?,?,?)');
        foreach($snapshots as $row)$q->execute($row);
        $q=$pdo->prepare('INSERT INTO augur_first_event_ratings (organization_id,event_id,season_year,frc_team_number,rating,auto_rating,matches_played,source_hash) VALUES(?,?,?,?,?,?,?,?)');
        foreach($state as $team=>$s){$q->execute([$org,$eventId,$year,(int)$team,$s['rating'],$s['auto'],$s['count'],$hash]);}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['changed'=>true,'teams'=>count($state),'matches'=>count($played),'source'=>'FIRST provisional'];
}

/** Event-scoped provisional EPA for events not yet supported by TBA's archive. */
function neptune_first_epa_rating_map(PDO $pdo,int $org,array $event,array $teams,?array $cutoffMatch=null): array {
    $eventId=(int)($event['id']??0);$year=(int)($event['season_year']??0);
    if($org<1||$eventId<1||$year<1)return [];
    try{
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='augur_first_event_ratings'");
        $q->execute();if(!$q->fetchColumn())return [];
        $out=[];
        $eventQuery=$pdo->prepare('SELECT rating,auto_rating FROM augur_first_event_ratings WHERE organization_id=? AND event_id=? AND frc_team_number=? LIMIT 1');
        $before=$pdo->prepare('SELECT pre_rating rating,pre_auto_rating auto_rating FROM augur_first_match_ratings WHERE organization_id=? AND event_id=? AND frc_team_number=? AND match_number=? LIMIT 1');
        $prev=$pdo->prepare('SELECT post_rating rating,post_auto_rating auto_rating FROM augur_first_match_ratings WHERE organization_id=? AND event_id=? AND frc_team_number=? AND match_number<? ORDER BY match_number DESC LIMIT 1');
        // Snapshots are qualification-only, so a playoff match number (sf 1, f 2) is not a
        // qualification number. Playoffs use the team's final qualification post-rating.
        $finalQual=$pdo->prepare('SELECT post_rating rating,post_auto_rating auto_rating FROM augur_first_match_ratings WHERE organization_id=? AND event_id=? AND frc_team_number=? ORDER BY match_number DESC LIMIT 1');
        $playoff=$cutoffMatch!==null&&strtolower((string)($cutoffMatch['comp_level']??'qm'))!=='qm';
        foreach(array_unique(array_map('intval',$teams)) as $t){
            if($t<1)continue;
            $row=null;$number=(int)($cutoffMatch['match_number']??0);
            if($playoff){
                $finalQual->execute([$org,$eventId,$t]);$row=$finalQual->fetch(PDO::FETCH_ASSOC)?:null;
            }elseif($number>0){
                $before->execute([$org,$eventId,$t,$number]);$row=$before->fetch(PDO::FETCH_ASSOC)?:null;
                if(!$row){$prev->execute([$org,$eventId,$t,$number]);$row=$prev->fetch(PDO::FETCH_ASSOC)?:null;}
            }else{
                $eventQuery->execute([$org,$eventId,$t]);$row=$eventQuery->fetch(PDO::FETCH_ASSOC)?:null;
            }
            if(!$row)continue;
            $out[$t]=['epa'=>(float)$row['rating'],'auto'=>$row['auto_rating']===null?null:(float)$row['auto_rating'],
                'teleop'=>null,'endgame'=>null,'confidence'=>null,'sigma'=>null,
                'source'=>'AUGUR FIRST event-local provisional EPA (not public season ratings)'];
        }
        return $out;
    }catch(PDOException $e){return [];}
}
