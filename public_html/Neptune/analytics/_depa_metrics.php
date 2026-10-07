<?php
require_once __DIR__.'/_augur_depa.php';

function neptune_depa_event_map(PDO $pdo,array $event,array $teamNumbers=[]): array {
    if(!augur_depa_tables_ready($pdo)) return [];
    $eventKey=trim((string)($event['tba_event_key']??''));
    if($eventKey==='') return [];
    $sql="SELECT frc_team_number,macro_depa AS depa,neptune_depa,verified_defense_matches,possible_defense_matches,observed_matches,defense_actions,confidence_score,validation_label
          FROM augur_depa_public_event_ratings WHERE tba_event_key=?";
    $params=[$eventKey];
    $teams=array_values(array_unique(array_filter(array_map('intval',$teamNumbers),fn($n)=>$n>0)));
    if($teams){$sql.=' AND frc_team_number IN ('.implode(',',array_fill(0,count($teams),'?')).')';$params=array_merge($params,$teams);}
    $s=$pdo->prepare($sql);$s->execute($params);$out=[];
    foreach($s->fetchAll() as $r)$out[(int)$r['frc_team_number']]=$r;
    return $out;
}


function neptune_depa_season_map(PDO $pdo,int $year,array $teamNumbers=[]): array {
    if(!augur_depa_tables_ready($pdo)||$year<1)return [];
    $sql="SELECT frc_team_number,public_depa AS depa,neptune_depa,verified_defense_matches,possible_defense_matches,observed_matches,defense_actions,confidence_score,validation_label FROM augur_depa_public_season_ratings WHERE season_year=?";
    $params=[$year];
    $teams=array_values(array_unique(array_filter(array_map('intval',$teamNumbers),fn($n)=>$n>0)));
    if($teams){$sql.=' AND frc_team_number IN ('.implode(',',array_fill(0,count($teams),'?')).')';$params=array_merge($params,$teams);}
    $q=$pdo->prepare($sql);$q->execute($params);$out=[];
    foreach($q->fetchAll() as $r)$out[(int)$r['frc_team_number']]=$r;
    return $out;
}

function neptune_depa_season_rating(PDO $pdo,int $year,int $team): ?array {
    if(!augur_depa_tables_ready($pdo)||$year<1||$team<1)return null;
    $s=$pdo->prepare("SELECT public_depa AS depa,neptune_depa,verified_defense_matches,possible_defense_matches,observed_matches,defense_actions,confidence_score,validation_label,last_event_key FROM augur_depa_public_season_ratings WHERE season_year=? AND frc_team_number=? LIMIT 1");
    $s->execute([$year,$team]);return $s->fetch()?:null;
}

/**
 * Prefer event-specific D-EPA, then fall back to the season rating for teams
 * whose event row has not been calculated yet. This keeps live/event views
 * useful while the public event archive is still catching up.
 */
function neptune_depa_event_map_with_season_fallback(PDO $pdo,array $event,array $teamNumbers=[]): array {
    $teams=array_values(array_unique(array_filter(array_map('intval',$teamNumbers),fn($n)=>$n>0)));
    $out=neptune_depa_event_map($pdo,$event,$teams);
    if(!$teams)return $out;

    $missing=[];
    foreach($teams as $team){
        $row=$out[$team]??null;
        if(!$row||(($row['depa']??null)===null&&($row['neptune_depa']??null)===null))$missing[]=$team;
    }
    if(!$missing)return $out;

    $year=(int)($event['season_year']??0);
    if($year<1){
        $key=trim((string)($event['tba_event_key']??''));
        if($key!==''){
            try{$s=$pdo->prepare('SELECT season_year FROM augur_epa_archive_events WHERE tba_event_key=? LIMIT 1');$s->execute([$key]);$year=(int)$s->fetchColumn();}
            catch(Throwable $ignored){$year=0;}
        }
    }
    if($year<1)return $out;
    $season=neptune_depa_season_map($pdo,$year,$missing);
    foreach($season as $team=>$row){$row['rating_scope']='season';$out[$team]=$row;}
    foreach($out as &$row){if(!isset($row['rating_scope']))$row['rating_scope']='event';}unset($row);
    return $out;
}
