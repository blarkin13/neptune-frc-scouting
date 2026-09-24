<?php

declare(strict_types=1);

/**
 * Traditional FRC OPR, calculated from Neptune's local AUGUR archive samples.
 *
 * OPR is event-specific. Neptune stores one rating per team/event and lets
 * consumers derive a season summary from those event ratings. The source rows
 * are augur_epa_alliance_samples because they already contain the official
 * qualification scores and exact alliance membership needed by OPR.
 */
const AUGUR_OPR_MODEL_VERSION = 'traditional-opr-v1';

function augur_opr_install_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS augur_opr_event_ratings (
        tba_event_key VARCHAR(40) NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        frc_team_number INT UNSIGNED NOT NULL,
        event_opr DECIMAL(12,6) NOT NULL,
        matches_played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        alliance_rows SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        model_version VARCHAR(80) NOT NULL,
        calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (tba_event_key, frc_team_number),
        KEY idx_augur_opr_season_team (season_year, frc_team_number),
        KEY idx_augur_opr_season_rating (season_year, event_opr)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function augur_opr_tables_ready(PDO $pdo): bool {
    try {
        $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='augur_opr_event_ratings'");
        $s->execute();
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Solve A*x=b in the least-squares sense via normal equations. */
function augur_opr_solve(array $rows): array {
    $clean=[];$teamSet=[];$teamMatches=[];
    foreach($rows as $row){
        $teams=array_values(array_unique(array_filter(array_map('intval',(array)($row['teams']??[])),static fn($n)=>$n>0)));
        $score=$row['score']??null;
        if(count($teams)!==3 || !is_numeric($score)) continue;
        $clean[]=['teams'=>$teams,'score'=>(float)$score];
        foreach($teams as $team){$teamSet[$team]=true;$teamMatches[$team]=($teamMatches[$team]??0)+1;}
    }
    if(!$clean || count($teamSet)<3) return ['ratings'=>[],'matches'=>[],'alliance_rows'=>0];

    $teams=array_map('intval',array_keys($teamSet));sort($teams,SORT_NUMERIC);
    $index=[];foreach($teams as $i=>$team)$index[$team]=$i;
    $n=count($teams);
    $ata=array_fill(0,$n,array_fill(0,$n,0.0));
    $atb=array_fill(0,$n,0.0);

    foreach($clean as $row){
        $idx=[];foreach($row['teams'] as $team)$idx[]=$index[$team];
        foreach($idx as $i){
            $atb[$i]+=$row['score'];
            foreach($idx as $j)$ata[$i][$j]+=1.0;
        }
    }

    $solve=static function(array $a,array $b,float $ridge=0.0): ?array {
        $n=count($b);if($n===0)return [];
        if($ridge>0){for($i=0;$i<$n;$i++)$a[$i][$i]+=$ridge;}
        for($col=0;$col<$n;$col++){
            $pivot=$col;$pivotAbs=abs((float)$a[$col][$col]);
            for($r=$col+1;$r<$n;$r++){
                $v=abs((float)$a[$r][$col]);
                if($v>$pivotAbs){$pivot=$r;$pivotAbs=$v;}
            }
            if($pivotAbs<1e-10)return null;
            if($pivot!==$col){$tmp=$a[$col];$a[$col]=$a[$pivot];$a[$pivot]=$tmp;$tb=$b[$col];$b[$col]=$b[$pivot];$b[$pivot]=$tb;}
            $pv=(float)$a[$col][$col];
            for($r=$col+1;$r<$n;$r++){
                $factor=(float)$a[$r][$col]/$pv;
                if(abs($factor)<1e-16)continue;
                $a[$r][$col]=0.0;
                for($c=$col+1;$c<$n;$c++)$a[$r][$c]-=$factor*(float)$a[$col][$c];
                $b[$r]-=$factor*(float)$b[$col];
            }
        }
        $x=array_fill(0,$n,0.0);
        for($i=$n-1;$i>=0;$i--){
            $sum=(float)$b[$i];
            for($j=$i+1;$j<$n;$j++)$sum-=(float)$a[$i][$j]*$x[$j];
            $d=(float)$a[$i][$i];if(abs($d)<1e-10)return null;
            $x[$i]=$sum/$d;
        }
        return $x;
    };

    $x=$solve($ata,$atb,0.0);
    // Incomplete schedules can be singular. A tiny ridge gives a stable,
    // explicitly provisional estimate while remaining effectively identical
    // to the exact solution for full-rank completed events.
    if($x===null)$x=$solve($ata,$atb,1e-8);
    if($x===null)return ['ratings'=>[],'matches'=>$teamMatches,'alliance_rows'=>count($clean)];

    $ratings=[];foreach($teams as $i=>$team)$ratings[$team]=(float)$x[$i];
    return ['ratings'=>$ratings,'matches'=>$teamMatches,'alliance_rows'=>count($clean)];
}

/** Rebuild every official event OPR for a season from already-local archive samples. */
function augur_opr_recalculate_year(PDO $pdo,int $year): array {
    augur_opr_install_tables($pdo);

    $s=$pdo->prepare("SELECT s.tba_event_key,s.team1,s.team2,s.team3,s.official_score,
            e.name,e.start_date,e.end_date,e.event_type
        FROM augur_epa_alliance_samples s
        JOIN augur_epa_archive_events e ON e.tba_event_key=s.tba_event_key
        WHERE s.season_year=? AND s.comp_level='qm' AND s.official_score>=0
          AND e.event_type BETWEEN 0 AND 6
        ORDER BY COALESCE(e.start_date,e.end_date),s.tba_event_key,s.match_number,s.alliance");
    $s->execute([$year]);

    $events=[];
    foreach($s->fetchAll() as $r){
        $key=(string)$r['tba_event_key'];
        $teams=[(int)$r['team1'],(int)$r['team2'],(int)$r['team3']];
        if(count(array_filter($teams,static fn($n)=>$n>0))!==3)continue;
        if(!isset($events[$key]))$events[$key]=['rows'=>[],'name'=>(string)($r['name']??$key)];
        $events[$key]['rows'][]=['teams'=>$teams,'score'=>(float)$r['official_score']];
    }

    $pdo->beginTransaction();
    try{
        $pdo->prepare('DELETE FROM augur_opr_event_ratings WHERE season_year=?')->execute([$year]);
        $ins=$pdo->prepare("INSERT INTO augur_opr_event_ratings
            (tba_event_key,season_year,frc_team_number,event_opr,matches_played,alliance_rows,model_version,calculated_at)
            VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())");

        $ratedEvents=0;$teamRows=0;$allianceRows=0;
        foreach($events as $eventKey=>$event){
            $solved=augur_opr_solve($event['rows']);
            if(!$solved['ratings'])continue;
            $ratedEvents++;
            $allianceRows+=(int)$solved['alliance_rows'];
            foreach($solved['ratings'] as $team=>$opr){
                $ins->execute([
                    $eventKey,$year,(int)$team,(float)$opr,
                    (int)($solved['matches'][$team]??0),(int)$solved['alliance_rows'],AUGUR_OPR_MODEL_VERSION,
                ]);
                $teamRows++;
            }
        }
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    return ['year'=>$year,'events'=>$ratedEvents,'team_rows'=>$teamRows,'alliance_rows'=>$allianceRows,'model_version'=>AUGUR_OPR_MODEL_VERSION];
}
