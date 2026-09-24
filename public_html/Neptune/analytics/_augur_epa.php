<?php

declare(strict_types=1);

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/tba.php';

const AUGUR_EPA_MODEL_VERSION = 'neptune-epa-v5.1-official-season';
const AUGUR_EPA_LIVE_REFRESH_SECONDS = 300;
const AUGUR_EPA_NORM_MEAN = 1500.0;
const AUGUR_EPA_NORM_SD = 250.0;
const AUGUR_EPA_INIT_PENALTY = 0.2;
const AUGUR_EPA_YEAR_ONE_WEIGHT = 0.7;
const AUGUR_EPA_MEAN_REVERSION = 0.4;
const AUGUR_EPA_ELIM_WEIGHT = 1.0 / 3.0;

function augur_epa_tables_ready(PDO $pdo): bool {
    static $ready=null;
    if($ready!==null) return $ready;
    $names=[
        'augur_epa_archive_years',
        'augur_epa_archive_events',
        'augur_epa_alliance_samples',
        'augur_epa_event_ratings',
        'augur_epa_match_ratings',
        'augur_epa_season_ratings',
    ];
    $ph=implode(',',array_fill(0,count($names),'?'));
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($ph)");
    $s->execute($names);
    return $ready=((int)$s->fetchColumn()===count($names));
}

function augur_epa_mean(array $v): float { return $v?array_sum($v)/count($v):0.0; }
function augur_epa_slope(array $v): float {
    $n=count($v); if($n<2)return 0.0;
    $xm=($n-1)/2; $ym=augur_epa_mean($v); $num=0.0;$den=0.0;
    foreach($v as $i=>$y){$dx=$i-$xm;$num+=$dx*((float)$y-$ym);$den+=$dx*$dx;}
    return $den>0?$num/$den:0.0;
}
function augur_epa_clamp(float $v,float $lo,float $hi): float { return max($lo,min($hi,$v)); }

function augur_epa_find_numeric(array $row,array $keys): ?float {
    $wanted=array_map(static fn($k)=>strtolower(preg_replace('/[^a-z0-9]/i','',(string)$k)),$keys);
    foreach($row as $k=>$v){
        if(!is_numeric($v))continue;
        $norm=strtolower(preg_replace('/[^a-z0-9]/i','',(string)$k));
        if(in_array($norm,$wanted,true))return (float)$v;
    }
    return null;
}


function augur_epa_phase_maps_ready(PDO $pdo): bool {
    static $ready=null;
    if($ready!==null)return $ready;
    $s=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='augur_epa_phase_maps'");
    return $ready=((int)$s->fetchColumn()>0);
}

function augur_epa_phase_map(PDO $pdo,int $year,bool $refresh=false): ?array {
    if(!isset($GLOBALS['__augur_epa_phase_map_cache']) || !is_array($GLOBALS['__augur_epa_phase_map_cache'])){
        $GLOBALS['__augur_epa_phase_map_cache']=[];
    }
    if(!$refresh && array_key_exists($year,$GLOBALS['__augur_epa_phase_map_cache'])){
        return $GLOBALS['__augur_epa_phase_map_cache'][$year];
    }
    if(!augur_epa_phase_maps_ready($pdo)){
        return $GLOBALS['__augur_epa_phase_map_cache'][$year]=null;
    }

    $s=$pdo->prepare('SELECT mapping_json FROM augur_epa_phase_maps WHERE season_year=? AND enabled=1 LIMIT 1');
    $s->execute([$year]);
    $json=$s->fetchColumn();
    if(!is_string($json) || trim($json)===''){
        return $GLOBALS['__augur_epa_phase_map_cache'][$year]=null;
    }
    $map=json_decode($json,true);
    if(!is_array($map)){
        return $GLOBALS['__augur_epa_phase_map_cache'][$year]=null;
    }
    return $GLOBALS['__augur_epa_phase_map_cache'][$year]=$map;
}

function augur_epa_path_numeric(array $row,string $path): ?float {
    $path=trim($path);
    if($path==='')return null;
    $node=$row;
    foreach(explode('.',$path) as $part){
        if(!is_array($node) || !array_key_exists($part,$node))return null;
        $node=$node[$part];
    }
    return is_numeric($node)?(float)$node:null;
}

function augur_epa_eval_terms(array $row,array $terms,?int &$resolved=null): ?float {
    $sum=0.0;$count=0;
    foreach($terms as $term){
        $path='';$weight=1.0;
        if(is_string($term)){
            $path=trim($term);
        }elseif(is_array($term)){
            $path=trim((string)($term['path']??''));
            $weight=is_numeric($term['weight']??null)?(float)$term['weight']:1.0;
        }
        if($path==='')continue;
        $v=augur_epa_path_numeric($row,$path);
        if($v===null)continue;
        $sum += $weight*$v;
        $count++;
    }
    $resolved=$count;
    return $count>0?$sum:null;
}

function augur_epa_eval_phase_spec(array $row,mixed $spec): array {
    if(!is_array($spec))return ['mode'=>'unavailable','value'=>null,'resolved'=>0];
    $mode=strtolower(trim((string)($spec['mode']??'sum')));
    if($mode==='remainder')return ['mode'=>'remainder','value'=>null,'resolved'=>0];
    if($mode==='unavailable'||$mode==='none')return ['mode'=>'unavailable','value'=>null,'resolved'=>0];
    $resolved=0;
    $value=augur_epa_eval_terms($row,is_array($spec['terms']??null)?$spec['terms']:[],$resolved);
    return ['mode'=>'sum','value'=>$value,'resolved'=>$resolved];
}

function augur_epa_apply_configured_phase_map(PDO $pdo,int $year,array $row,float $baseModeled): ?array {
    $map=augur_epa_phase_map($pdo,$year);
    if(!$map)return null;

    $modeled=$baseModeled;
    $subtractResolved=0;
    $subtract=augur_epa_eval_terms(
        $row,
        is_array($map['modeled_subtract']??null)?$map['modeled_subtract']:[],
        $subtractResolved
    );
    if($subtract!==null)$modeled-=$subtract;
    $modeled=max(0.0,$modeled);

    $parts=[];
    foreach(['auto','teleop','endgame'] as $phase){
        $parts[$phase]=augur_epa_eval_phase_spec($row,$map[$phase]??[]);
    }

    // Resolve one or more remainder phases when the other two phases are known.
    for($pass=0;$pass<3;$pass++){
        foreach(['auto','teleop','endgame'] as $phase){
            if(($parts[$phase]['mode']??'')!=='remainder' || $parts[$phase]['value']!==null)continue;
            $others=array_values(array_filter(['auto','teleop','endgame'],static fn($p)=>$p!==$phase));
            $a=$parts[$others[0]]['value']??null;
            $b=$parts[$others[1]]['value']??null;
            if($a!==null && $b!==null)$parts[$phase]['value']=max(0.0,$modeled-(float)$a-(float)$b);
        }
    }

    $auto=$parts['auto']['value']??null;
    $tele=$parts['teleop']['value']??null;
    $end=$parts['endgame']['value']??null;

    $reconcile=strtolower(trim((string)($map['reconcile']??'')));
    if(in_array($reconcile,['auto','teleop','endgame'],true) && $auto!==null && $tele!==null && $end!==null){
        $delta=$modeled-($auto+$tele+$end);
        if($reconcile==='auto')$auto+=$delta;
        elseif($reconcile==='teleop')$tele+=$delta;
        else $end+=$delta;
    }

    return [
        'modeled'=>$modeled,
        'auto'=>$auto,
        'teleop'=>$tele,
        'endgame'=>$end,
        'phase_map_source'=>'database',
    ];
}

/**
 * Convert TBA's season-specific score_breakdown into one alliance-level phase
 * total without embedding FRC game objects in the EPA engine.
 */
function augur_epa_phase_total(array $row,string $phase): ?float {
    $exact=match($phase){
        'auto'=>['totalAutoPoints','autoPoints','autoTotalPoints','autoScore'],
        'teleop'=>['totalTeleopPoints','teleopPoints','teleopTotalPoints','teleopScore'],
        'endgame'=>['totalEndgamePoints','endgamePoints','endGamePoints','endGameScore','endgameScore'],
        default=>[],
    };
    $v=augur_epa_find_numeric($row,$exact);
    if($v!==null)return $v;

    $needle=$phase==='endgame'?'endgame':$phase;
    $candidates=[];
    foreach($row as $key=>$value){
        if(!is_numeric($value))continue;
        $norm=strtolower(preg_replace('/[^a-z0-9]/i','',(string)$key));
        if(!str_contains($norm,$needle))continue;
        if(!str_contains($norm,'point')&&!str_contains($norm,'score'))continue;
        if(str_contains($norm,'rp')||str_contains($norm,'count')||str_contains($norm,'threshold')||str_contains($norm,'bonus')||str_contains($norm,'ranking'))continue;
        $candidates[$norm]=(float)$value;
    }
    if(!$candidates)return null;
    foreach($candidates as $key=>$value)if(str_contains($key,'total'))return $value;
    return array_sum($candidates);
}

function augur_epa_model_score_from_breakdown(float $official,array $row): float {
    $foul=augur_epa_find_numeric($row,['foulPoints']);
    $adjust=augur_epa_find_numeric($row,['adjustPoints','adjustmentPoints']);
    return max(0.0,$official-($foul??0.0)-($adjust??0.0));
}

function augur_epa_model_score(array $match,string $color): float {
    $score=(float)($match['alliances'][$color]['score']??0);
    $row=is_array($match['score_breakdown'][$color]??null)?$match['score_breakdown'][$color]:[];
    return augur_epa_model_score_from_breakdown($score,$row);
}

/**
 * Normalize TBA's score breakdown into the four public EPA streams.
 *
 * The EPA update math is game-agnostic. Only this normalization layer needs to
 * understand how TBA represents phase totals for a given season.
 */
function augur_epa_normalize_breakdown(PDO $pdo,int $year,array $row,float $official): array {
    $modeled=augur_epa_model_score_from_breakdown($official,$row);

    // A database phase map always wins. This keeps future FRC seasons out of
    // the PHP engine: configure the TBA field mapping from the admin page,
    // then recalculate the year from the already archived raw breakdown JSON.
    $configured=augur_epa_apply_configured_phase_map($pdo,$year,$row,$modeled);
    if($configured!==null)return $configured;

    $auto=$tele=$end=null;

    // Statbotics removes score-based RP bonuses in 2016/2017 before attribution.
    if($year===2016){
        $modeled-=((float)($row['breachPoints']??0)+(float)($row['capturePoints']??0));
        $autoReach=(float)($row['autoReachPoints']??0);
        $autoCross=(float)($row['autoCrossingPoints']??0);
        $autoLow=(float)($row['autoBouldersLow']??0);
        $autoHigh=(float)($row['autoBouldersHigh']??0);
        $autoBoulderPoints=(float)($row['autoBoulderPoints']??(5*$autoLow+10*$autoHigh));
        if(abs((5*$autoLow+10*$autoHigh)-$autoBoulderPoints)>0.01){
            $autoHigh=floor($autoBoulderPoints/10);
            $autoLow=($autoBoulderPoints-10*$autoHigh)/5;
        }
        $auto=$autoReach+$autoCross+5*$autoLow+10*$autoHigh;
        $tele=(float)($row['teleopCrossingPoints']??0)
            +2*(float)($row['teleopBouldersLow']??0)
            +5*(float)($row['teleopBouldersHigh']??0);
        $end=(float)($row['teleopChallengePoints']??0)+(float)($row['teleopScalePoints']??0);
    }elseif($year===2017){
        $modeled-=((float)($row['rotorBonusPoints']??0)+(float)($row['kPaBonusPoints']??0));
        $autoFuelLow=(float)($row['autoFuelLow']??0);
        $autoFuelHigh=(float)($row['autoFuelHigh']??0);
        $autoRotor=(float)($row['autoRotorPoints']??0);
        $numAutoRotors=floor($autoRotor/60);
        $autoRotor-=40*$numAutoRotors;
        $teleRotor=(float)($row['teleopRotorPoints']??0)+40*$numAutoRotors;
        $autoKpa9=3*$autoFuelLow+9*$autoFuelHigh;
        $auto=(float)($row['autoMobilityPoints']??0)+$autoRotor+floor($autoKpa9/9);
        $teleKpa9=fmod($autoKpa9,9)+(float)($row['teleopFuelLow']??0)+3*(float)($row['teleopFuelHigh']??0);
        $tele=$teleRotor+floor($teleKpa9/9);
        $end=(float)($row['teleopTakeoffPoints']??0);
    }elseif($year===2018){
        $auto=(float)($row['autoRunPoints']??0)
            +2*(float)($row['autoSwitchOwnershipSec']??0)
            +2*(float)($row['autoScaleOwnershipSec']??0);
        $tele=(float)($row['teleopSwitchOwnershipSec']??0)
            +(float)($row['teleopSwitchBoostSec']??0)
            +(float)($row['teleopScaleOwnershipSec']??0)
            +(float)($row['teleopScaleBoostSec']??0)
            +(float)($row['vaultPoints']??0);
        $end=(float)($row['endgamePoints']??0);
    }elseif($year===2019){
        $auto=(float)($row['sandStormBonusPoints']??0);
        $end=(float)($row['habClimbPoints']??0);
        $tele=max(0.0,$modeled-$auto-$end);
    }elseif($year===2020||$year===2021){
        $auto=(float)($row['autoInitLinePoints']??0)
            +2*(float)($row['autoCellsBottom']??0)
            +4*(float)($row['autoCellsOuter']??0)
            +6*(float)($row['autoCellsInner']??0);
        $tele=(float)($row['teleopCellsBottom']??0)
            +2*(float)($row['teleopCellsOuter']??0)
            +3*(float)($row['teleopCellsInner']??0)
            +(float)($row['controlPanelPoints']??0);
        $end=(float)($row['endgamePoints']??0);
    }elseif($year===2022){
        $auto=(float)($row['autoTaxiPoints']??0)+(float)($row['autoCargoPoints']??0);
        $tele=(float)($row['teleopCargoPoints']??0);
        $end=(float)($row['endgamePoints']??0);
    }elseif($year===2023){
        // TBA exposes the official aggregate auto score; separate endgame explicitly.
        $auto=augur_epa_find_numeric($row,['autoPoints']);
        $end=(float)($row['endGameChargeStationPoints']??0)+(float)($row['endGameParkPoints']??0);
        if($auto!==null)$tele=max(0.0,$modeled-$auto-$end);
    }elseif($year===2024){
        $auto=(float)($row['autoLeavePoints']??0)
            +2*(float)($row['autoAmpNoteCount']??0)
            +5*(float)($row['autoSpeakerNoteCount']??0);
        $amp=(float)($row['teleopAmpNoteCount']??0);
        $speaker=(float)($row['teleopSpeakerNoteCount']??0);
        $amplified=(float)($row['teleopSpeakerNoteAmplifiedCount']??0);
        $tele=$amp+2*($speaker+$amplified)+3*$amplified;
        $end=(float)($row['endGameParkPoints']??0)
            +(float)($row['endGameOnStagePoints']??0)
            +(float)($row['endGameHarmonyPoints']??0)
            +(float)($row['endGameNoteInTrapPoints']??0)
            +(float)($row['endGameSpotLightBonusPoints']??0);
    }elseif($year===2025){
        $auto=(float)($row['autoMobilityPoints']??0)+(float)($row['autoCoralPoints']??0);
        $tele=(float)($row['teleopCoralPoints']??0)
            +6*(float)($row['wallAlgaeCount']??0)
            +4*(float)($row['netAlgaeCount']??0);
        $end=(float)($row['endGameBargePoints']??0);
    }elseif($year===2026){
        $hub=is_array($row['hubScore']??null)?$row['hubScore']:[];
        $auto=(float)($hub['autoPoints']??0)+(float)($row['autoTowerPoints']??0);
        $tele=(float)($hub['transitionPoints']??0)
            +(float)($hub['shift1Points']??0)+(float)($hub['shift2Points']??0)
            +(float)($hub['shift3Points']??0)+(float)($hub['shift4Points']??0);
        $end=(float)($hub['endgamePoints']??0)+(float)($row['endGameTowerPoints']??0);
    }else{
        $auto=augur_epa_phase_total($row,'auto');
        $tele=augur_epa_phase_total($row,'teleop');
        $end=augur_epa_phase_total($row,'endgame');
    }

    $modeled=max(0.0,$modeled);
    if($year>=2016 && $auto!==null && $tele!==null && $end!==null){
        // Statbotics reconciles any scoring discrepancy into teleop.
        $tele += $modeled-($auto+$tele+$end);
    }

    return ['modeled'=>$modeled,'auto'=>$auto,'teleop'=>$tele,'endgame'=>$end,'phase_map_source'=>'legacy_or_auto'];
}

function augur_epa_breakdown_components(?array $row,?float $modeledTotal=null,int $year=0): array {
    if(!$row)return ['auto'=>null,'teleop'=>null,'endgame'=>null];
    $auto=augur_epa_phase_total($row,'auto');
    $tele=augur_epa_phase_total($row,'teleop');
    $end=augur_epa_phase_total($row,'endgame');
    if($modeledTotal!==null&&$auto!==null&&$tele!==null&&$end!==null){
        $tele += $modeledTotal-($auto+$tele+$end);
    }
    return ['auto'=>$auto,'teleop'=>$tele,'endgame'=>$end];
}

function augur_epa_num_teams(int $year): int { return $year<=2004?2:3; }

function augur_epa_percent(int $year,int $count): float {
    $prev=min(0.5,max(0.3,0.5-(0.2/6.0)*($count-6)));
    return $year<=2015?0.5*$prev:(2.0/3.0)*$prev;
}

function augur_epa_stddev(array $values): float {
    if(!$values)return 1.0;
    $mean=augur_epa_mean($values);
    $sum=0.0;
    foreach($values as $v){$d=(float)$v-$mean;$sum+=$d*$d;}
    return max(1.0,sqrt($sum/max(1,count($values))));
}

function augur_epa_event_is_historical(?string $endDate): bool {
    if(!$endDate)return false;
    return $endDate < gmdate('Y-m-d');
}

function augur_epa_tba_get(PDO $pdo,string $path,int $ttl=300,bool $force=false): array {
    if($force){
        try{
            $pdo->prepare('DELETE FROM tba_cache WHERE cache_key=?')->execute(['tba:'.sha1($path)]);
        }catch(Throwable $ignored){}
    }
    return tba_get($path,$ttl);
}

function augur_epa_is_official_event_type(mixed $eventType): bool {
    if(!is_numeric($eventType))return false;
    $n=(int)$eventType;
    return $n>=0 && $n<=6;
}

function augur_epa_upsert_event_metadata(PDO $pdo,array $ev): ?string {
    $key=trim((string)($ev['key']??''));
    if($key==='')return null;
    $year=(int)($ev['year']??preg_replace('/\D.*/','',$key));
    if($year<1990)$year=(int)substr($key,0,4);
    $district=is_array($ev['district']??null)?(string)($ev['district']['key']??''):(string)($ev['district_key']??'');
    $end=trim((string)($ev['end_date']??''));$start=trim((string)($ev['start_date']??''));
    $isComplete=augur_epa_event_is_historical($end?:null)?1:0;
    $s=$pdo->prepare("INSERT INTO augur_epa_archive_events
        (tba_event_key,season_year,name,short_name,event_code,event_type,district_key,event_week,start_date,end_date,city,state_prov,country,is_complete)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE season_year=VALUES(season_year),name=VALUES(name),short_name=VALUES(short_name),
        event_code=VALUES(event_code),event_type=VALUES(event_type),district_key=VALUES(district_key),event_week=VALUES(event_week),
        start_date=VALUES(start_date),end_date=VALUES(end_date),city=VALUES(city),state_prov=VALUES(state_prov),country=VALUES(country),
        is_complete=VALUES(is_complete),updated_at=CURRENT_TIMESTAMP");
    $s->execute([
        $key,$year,(string)($ev['name']??$key),(string)($ev['short_name']??'')?:null,(string)($ev['event_code']??'')?:null,
        isset($ev['event_type'])?(int)$ev['event_type']:null,$district?:null,isset($ev['week'])?(int)$ev['week']:null,
        $start?:null,$end?:null,(string)($ev['city']??'')?:null,(string)($ev['state_prov']??'')?:null,(string)($ev['country']??'')?:null,$isComplete
    ]);
    return $key;
}

function augur_epa_discover_year(PDO $pdo,int $year,bool $force=false): array {
    if(!augur_epa_tables_ready($pdo))throw new RuntimeException('Public EPA Archive tables are not installed.');
    if($year<1992||$year>(int)date('Y')+1)throw new RuntimeException('Invalid FRC season year.');

    $s=$pdo->prepare('SELECT * FROM augur_epa_archive_years WHERE season_year=? LIMIT 1');
    $s->execute([$year]);$cached=$s->fetch()?:null;
    $historical=$year<(int)date('Y');
    if(!$force&&$cached&&($cached['source_status']??'')==='ready'){
        $last=strtotime((string)($cached['last_discovered_at']??''))?:0;
        if($historical||$last>time()-21600){
            return ['year'=>$year,'events'=>(int)$cached['event_count'],'cached'=>true];
        }
    }

    try{
        $events=augur_epa_tba_get($pdo,'events/'.$year.'/simple',$historical?2592000:3600,$force);
        $hash=hash('sha256',json_encode($events,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $count=0;
        $pdo->beginTransaction();
        foreach($events as $ev)if(is_array($ev)&&augur_epa_upsert_event_metadata($pdo,$ev)!==null)$count++;
        $up=$pdo->prepare("INSERT INTO augur_epa_archive_years(season_year,source_status,event_count,source_hash,last_discovered_at,last_error)
            VALUES(?,'ready',?,?,UTC_TIMESTAMP(),NULL)
            ON DUPLICATE KEY UPDATE source_status='ready',event_count=VALUES(event_count),source_hash=VALUES(source_hash),
            last_discovered_at=UTC_TIMESTAMP(),last_error=NULL,updated_at=CURRENT_TIMESTAMP");
        $up->execute([$year,$count,$hash]);
        $pdo->commit();
        return ['year'=>$year,'events'=>$count,'cached'=>false];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $up=$pdo->prepare("INSERT INTO augur_epa_archive_years(season_year,source_status,event_count,last_discovered_at,last_error)
            VALUES(?,'error',0,UTC_TIMESTAMP(),?)
            ON DUPLICATE KEY UPDATE source_status='error',last_error=VALUES(last_error),last_discovered_at=UTC_TIMESTAMP()");
        $up->execute([$year,substr($e->getMessage(),0,4000)]);
        throw $e;
    }
}

function augur_epa_archive_event(PDO $pdo,string $eventKey): ?array {
    $s=$pdo->prepare('SELECT * FROM augur_epa_archive_events WHERE tba_event_key=? LIMIT 1');
    $s->execute([$eventKey]);return $s->fetch()?:null;
}

function augur_epa_ensure_event_metadata(PDO $pdo,string $eventKey,bool $force=false): array {
    $event=augur_epa_archive_event($pdo,$eventKey);
    if($event&&!$force)return $event;
    $ev=augur_epa_tba_get($pdo,'event/'.rawurlencode($eventKey).'/simple',86400,$force);
    if(!$ev)throw new RuntimeException('TBA event metadata was not available for '.$eventKey.'.');
    augur_epa_upsert_event_metadata($pdo,$ev);
    $event=augur_epa_archive_event($pdo,$eventKey);
    if(!$event)throw new RuntimeException('Could not store TBA event metadata.');
    return $event;
}

function augur_epa_extract_teams(array $alliance): array {
    $out=[];
    $keys=(array)($alliance['team_keys']??[]);
    foreach($keys as $key){
        $key=trim((string)$key);
        if(!preg_match('/^frc([1-9][0-9]*)$/',$key,$m))return [];
        $out[]=(int)$m[1];
    }
    $unique=array_values(array_unique($out));
    return count($unique)===count($keys)?$unique:[];
}

function augur_epa_ingest_event(PDO $pdo,string $eventKey,bool $force=false): array {
    $event=augur_epa_ensure_event_metadata($pdo,$eventKey,false);
    $historical=(int)($event['is_complete']??0)===1;

    $fetchedAt=strtotime((string)($event['source_fetched_at']??''))?:0;
    $status=(string)($event['source_status']??'discovered');
    if(!$force){
        if($historical&&in_array($status,['ready','no_qual_data'],true)){
            return [
                'event_key'=>$eventKey,'fetched'=>false,'cached'=>true,'status'=>$status,
                'matches'=>(int)round(((int)($event['alliance_sample_count']??0))/2),
                'quals'=>(int)($event['qual_match_count']??0),
            ];
        }
        if(!$historical&&in_array($status,['ready','waiting','no_qual_data'],true)&&$fetchedAt>time()-AUGUR_EPA_LIVE_REFRESH_SECONDS){
            return [
                'event_key'=>$eventKey,'fetched'=>false,'cached'=>true,'status'=>$status,
                'matches'=>(int)round(((int)($event['alliance_sample_count']??0))/2),
                'quals'=>(int)($event['qual_match_count']??0),
            ];
        }
    }

    try{
        $matches=augur_epa_tba_get($pdo,'event/'.rawurlencode($eventKey).'/matches',$historical?2592000:60,$force);
        $played=[];$qualCount=0;
        foreach($matches as $m){
            if(!is_array($m))continue;
            $level=strtolower((string)($m['comp_level']??''));
            if(!in_array($level,['qm','ef','qf','sf','f'],true))continue;
            $red=$m['alliances']['red']['score']??-1;$blue=$m['alliances']['blue']['score']??-1;
            if(!is_numeric($red)||!is_numeric($blue)||(float)$red<0||(float)$blue<0)continue;
            $played[]=$m;
            if($level==='qm')$qualCount++;
        }
        usort($played,static function($a,$b){
            $ta=(int)($a['actual_time']??($a['time']??0));$tb=(int)($b['actual_time']??($b['time']??0));
            if($ta&&$tb&&$ta!==$tb)return $ta<=>$tb;
            $rank=static fn($x)=>match(strtolower((string)($x['comp_level']??''))){'qm'=>10,'ef'=>20,'qf'=>30,'sf'=>40,'f'=>50,default=>99};
            return [$rank($a),(int)($a['set_number']??1),(int)($a['match_number']??0)]
                <=>[$rank($b),(int)($b['set_number']??1),(int)($b['match_number']??0)];
        });

        $sourceHash=hash('sha256',json_encode($played,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        if(!$force&&$sourceHash!==''&&$sourceHash===($event['source_hash']??'')&&$status==='ready'){
            $pdo->prepare('UPDATE augur_epa_archive_events SET source_fetched_at=UTC_TIMESTAMP(),last_error=NULL WHERE tba_event_key=?')->execute([$eventKey]);
            return ['event_key'=>$eventKey,'fetched'=>true,'changed'=>false,'status'=>'ready','matches'=>count($played),'quals'=>$qualCount];
        }

        $year=(int)$event['season_year'];
        $autoN=0;$teleN=0;$endN=0;
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM augur_epa_alliance_samples WHERE tba_event_key=?')->execute([$eventKey]);
        $ins=$pdo->prepare("INSERT INTO augur_epa_alliance_samples
            (tba_event_key,season_year,tba_match_key,comp_level,set_number,match_number,actual_time,alliance,team1,team2,team3,team_keys_json,
             official_score,modeled_score,auto_score,teleop_score,endgame_score,score_breakdown_json,source_hash)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $storedMatches=0;
        foreach($played as $m){
            $level=strtolower((string)($m['comp_level']??'qm'));
            $redTeamsCheck=augur_epa_extract_teams($m['alliances']['red']??[]);
            $blueTeamsCheck=augur_epa_extract_teams($m['alliances']['blue']??[]);
            $allTeamsCheck=array_merge($redTeamsCheck,$blueTeamsCheck);
            if(count($redTeamsCheck)!==augur_epa_num_teams($year)
                || count($blueTeamsCheck)!==augur_epa_num_teams($year)
                || count(array_unique($allTeamsCheck))!==count($allTeamsCheck))continue;
            $storedMatches++;
            foreach(['red'=>'Red','blue'=>'Blue'] as $color=>$label){
                $teams=augur_epa_extract_teams($m['alliances'][$color]??[]);
                $official=(float)($m['alliances'][$color]['score']??0);
                $break=is_array($m['score_breakdown'][$color]??null)?$m['score_breakdown'][$color]:[];
                $parts=augur_epa_normalize_breakdown($pdo,$year,$break,$official);
                if($parts['auto']!==null)$autoN++;
                if($parts['teleop']!==null)$teleN++;
                if($parts['endgame']!==null)$endN++;
                $actual=(int)($m['actual_time']??0);
                if($actual<=0)$actual=(int)($m['time']??0);
                $matchKey=(string)($m['key']??($eventKey.'_'.$level.(int)($m['match_number']??0)));
                $payload=[
                    $eventKey,$year,$matchKey,$level,
                    (int)($m['set_number']??1),(int)($m['match_number']??0),$actual>0?$actual:null,$label,
                    $teams[0]??null,$teams[1]??null,$teams[2]??null,json_encode($teams),
                    $official,$parts['modeled'],$parts['auto'],$parts['teleop'],$parts['endgame'],
                    $break?json_encode($break,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,
                    hash('sha256',json_encode([$matchKey,$label,$teams,$official,$parts],JSON_UNESCAPED_SLASHES))
                ];
                $ins->execute($payload);
            }
        }
        $sourceStatus=$storedMatches>0?'ready':($historical?'no_qual_data':'waiting');
        $pdo->prepare("UPDATE augur_epa_archive_events SET source_status=?,qual_match_count=?,alliance_sample_count=?,
            auto_sample_count=?,teleop_sample_count=?,endgame_sample_count=?,source_hash=?,source_fetched_at=UTC_TIMESTAMP(),
            ratings_calculated_at=NULL,model_version=NULL,last_error=NULL WHERE tba_event_key=?")
            ->execute([$sourceStatus,$qualCount,$storedMatches*2,$autoN,$teleN,$endN,$sourceHash,$eventKey]);
        $pdo->commit();
        return [
            'event_key'=>$eventKey,'fetched'=>true,'changed'=>true,'status'=>$sourceStatus,
            'matches'=>$storedMatches,'quals'=>$qualCount,
            'component_alliance_samples'=>['auto'=>$autoN,'teleop'=>$teleN,'endgame'=>$endN]
        ];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $pdo->prepare("UPDATE augur_epa_archive_events SET source_status='error',source_fetched_at=UTC_TIMESTAMP(),last_error=? WHERE tba_event_key=?")
            ->execute([substr($e->getMessage(),0,4000),$eventKey]);
        throw $e;
    }
}

function augur_epa_prior_rating(PDO $pdo,int $year,string $eventKey,int $team,?string $eventStart): ?array {
    $sql="SELECT r.* FROM augur_epa_event_ratings r
          JOIN augur_epa_archive_events e ON e.tba_event_key=r.tba_event_key
          WHERE r.season_year=? AND r.frc_team_number=? AND r.tba_event_key<>?";
    $args=[$year,$team,$eventKey];
    if($eventStart){
        $sql.=" AND COALESCE(e.end_date,e.start_date) < ?";
        $args[]=$eventStart;
    }
    $sql.=" ORDER BY COALESCE(e.end_date,e.start_date) DESC,e.tba_event_key DESC LIMIT 1";
    $s=$pdo->prepare($sql);$s->execute($args);return $s->fetch()?:null;
}

function augur_epa_sample_teams(array $sample): array {
    return array_values(array_filter([(int)($sample['team1']??0),(int)($sample['team2']??0),(int)($sample['team3']??0)],static fn($n)=>$n>0));
}

function augur_epa_comp_rank(string $level): int {
    return match(strtolower($level)){'qm'=>10,'ef'=>20,'qf'=>30,'sf'=>40,'f'=>50,default=>99};
}

/** Re-read stored raw TBA score breakdowns using the current normalizer. */
function augur_epa_renormalize_year_samples(PDO $pdo,int $year): int {
    $s=$pdo->prepare('SELECT id,official_score,score_breakdown_json FROM augur_epa_alliance_samples WHERE season_year=?');
    $s->execute([$year]);
    $up=$pdo->prepare('UPDATE augur_epa_alliance_samples SET modeled_score=?,auto_score=?,teleop_score=?,endgame_score=? WHERE id=?');
    $n=0;
    foreach($s->fetchAll() as $row){
        $bd=json_decode((string)($row['score_breakdown_json']??''),true);
        if(!is_array($bd))$bd=[];
        $parts=augur_epa_normalize_breakdown($pdo,$year,$bd,(float)$row['official_score']);
        $up->execute([$parts['modeled'],$parts['auto'],$parts['teleop'],$parts['endgame'],(int)$row['id']]);
        $n++;
    }
    return $n;
}

function augur_epa_year_score_stats(PDO $pdo,int $year): array {
    $s=$pdo->prepare('SELECT s.official_score,s.modeled_score,s.auto_score,s.teleop_score,s.endgame_score
        FROM augur_epa_alliance_samples s
        JOIN augur_epa_archive_events e ON e.tba_event_key=s.tba_event_key
        WHERE s.season_year=? AND e.event_type BETWEEN 0 AND 6');
    $s->execute([$year]);
    $official=[];$modeled=[];$phases=['auto'=>[],'teleop'=>[],'endgame'=>[]];
    foreach($s->fetchAll() as $r){
        $official[]=(float)$r['official_score'];
        $modeled[]=(float)$r['modeled_score'];
        foreach($phases as $p=>$_){$c=$p.'_score';if($r[$c]!==null)$phases[$p][]=(float)$r[$c];}
    }
    $mean=augur_epa_mean($modeled);
    $sd=augur_epa_stddev($official?:$modeled);
    return [
        'mean'=>$mean,'sd'=>max(1.0,$sd),'samples'=>count($modeled),
        'auto_mean'=>$phases['auto']?augur_epa_mean($phases['auto']):null,
        'teleop_mean'=>$phases['teleop']?augur_epa_mean($phases['teleop']):null,
        'endgame_mean'=>$phases['endgame']?augur_epa_mean($phases['endgame']):null,
    ];
}

function augur_epa_to_norm(float $epa,array $stats,int $numTeams): float {
    $sd=max(1.0,(float)($stats['sd']??1));
    return AUGUR_EPA_NORM_MEAN+AUGUR_EPA_NORM_SD*($epa-((float)($stats['mean']??0)/max(1,$numTeams)))/$sd;
}

function augur_epa_prior_norm_map(PDO $pdo,int $year): array {
    $min=max(1992,$year-4);
    $s=$pdo->prepare("SELECT season_year,frc_team_number,rating FROM augur_epa_season_ratings
        WHERE season_year BETWEEN ? AND ? AND model_version=? ORDER BY frc_team_number,season_year DESC");
    $s->execute([$min,$year-1,AUGUR_EPA_MODEL_VERSION]);
    $rows=$s->fetchAll();
    if(!$rows)return [];
    $statsByYear=[];$out=[];
    foreach($rows as $r){
        $t=(int)$r['frc_team_number'];
        if(count($out[$t]??[])>=2)continue;
        $py=(int)$r['season_year'];
        if(!isset($statsByYear[$py]))$statsByYear[$py]=augur_epa_year_score_stats($pdo,$py);
        if(($statsByYear[$py]['samples']??0)<1)continue;
        $out[$t][]=augur_epa_to_norm((float)$r['rating'],$statsByYear[$py],augur_epa_num_teams($py));
    }
    return $out;
}

function augur_epa_initial_state(int $team,int $year,array $stats,array $priorNorms): array {
    $initNorm=AUGUR_EPA_NORM_MEAN-AUGUR_EPA_INIT_PENALTY*AUGUR_EPA_NORM_SD;
    $p=$priorNorms[$team]??[];
    $n1=(float)($p[0]??$initNorm);$n2=(float)($p[1]??$initNorm);
    $prev=AUGUR_EPA_YEAR_ONE_WEIGHT*$n1+(1-AUGUR_EPA_YEAR_ONE_WEIGHT)*$n2;
    $curr=(1-AUGUR_EPA_MEAN_REVERSION)*$prev+AUGUR_EPA_MEAN_REVERSION*$initNorm;
    $z=($curr-AUGUR_EPA_NORM_MEAN)/AUGUR_EPA_NORM_SD;
    $n=augur_epa_num_teams($year);
    $mean=(float)($stats['mean']??0);$sd=max(1.0,(float)($stats['sd']??1));
    $z=max(-$mean/max(1,$n)/$sd,$z);
    $rating=max(0.0,$mean/max(1,$n)+$sd*$z);
    $sdFrac=$sd/max(1.0,$mean);
    $phase=static function(?float $phaseMean)use($n,$sdFrac,$z): ?float {
        if($phaseMean===null)return null;
        return max(0.0,$phaseMean/max(1,$n)+$phaseMean*$sdFrac*$z);
    };
    return [
        'rating'=>$rating,
        'auto'=>$phase($stats['auto_mean']??null),
        'teleop'=>$phase($stats['teleop_mean']??null),
        'endgame'=>$phase($stats['endgame_mean']??null),
        'count'=>0,'wins'=>0,'losses'=>0,'ties'=>0,'history'=>[],'events'=>[],'last_event'=>null,
    ];
}

function augur_epa_update_stream(array &$state,array $teams,float $actual,string $field,int $year,float $weight): void {
    $teams=array_values(array_filter(array_map('intval',$teams),static fn($t)=>$t>0&&isset($state[$t])));
    $n=count($teams);if($n<1)return;
    $pred=0.0;
    foreach($teams as $t){
        $v=$field==='rating'?$state[$t]['rating']:($state[$t][$field]??null);
        if($v===null)return;
        $pred+=(float)$v;
    }
    $err=$actual-$pred;
    foreach($teams as $t){
        $pct=augur_epa_percent($year,(int)$state[$t]['count']);
        $state[$t][$field]=max(0.0,(float)$state[$t][$field]+$weight*$pct*$err/$n);
    }
}

/**
 * Recalculate an entire season in chronological order using Statbotics' public
 * core EPA mechanics: equal residual sharing, EWMA learning-rate decay,
 * cross-season normalized mean reversion, and 1/3 elimination weighting.
 */
function augur_epa_recalculate_year(PDO $pdo,int $year): array {
    if(!augur_epa_tables_ready($pdo))throw new RuntimeException('Public EPA Archive tables are not installed.');
    $renormalized=augur_epa_renormalize_year_samples($pdo,$year);
    $stats=augur_epa_year_score_stats($pdo,$year);
    if(($stats['samples']??0)<1)throw new RuntimeException('No archived alliance samples exist for '.$year.'.');
    $priorNorms=augur_epa_prior_norm_map($pdo,$year);

    $s=$pdo->prepare("SELECT s.*,e.start_date,e.end_date
        FROM augur_epa_alliance_samples s
        JOIN augur_epa_archive_events e ON e.tba_event_key=s.tba_event_key
        WHERE s.season_year=? AND e.event_type BETWEEN 0 AND 6");
    $s->execute([$year]);$rows=$s->fetchAll();
    $groups=[];
    foreach($rows as $r){
        $key=(string)$r['tba_match_key'];
        $groups[$key]['event_key']=(string)$r['tba_event_key'];
        $groups[$key]['start_date']=$r['start_date']??null;
        $groups[$key]['end_date']=$r['end_date']??null;
        $groups[$key][strtolower((string)$r['alliance'])]=$r;
    }
    uasort($groups,static function($a,$b){
        $ra=$a['red']??$a['blue']??[];$rb=$b['red']??$b['blue']??[];
        $ta=(int)($ra['actual_time']??0);$tb=(int)($rb['actual_time']??0);
        if($ta>0&&$tb>0&&$ta!==$tb)return $ta<=>$tb;
        $da=strtotime((string)($a['start_date']??''))?:0;$db=strtotime((string)($b['start_date']??''))?:0;
        if($da!==$db)return $da<=>$db;
        $ec=strcmp((string)($a['event_key']??''),(string)($b['event_key']??''));if($ec!==0)return $ec;
        $cr=augur_epa_comp_rank((string)($ra['comp_level']??''))<=>augur_epa_comp_rank((string)($rb['comp_level']??''));
        if($cr!==0)return $cr;
        return [(int)($ra['set_number']??1),(int)($ra['match_number']??0)]<=>[(int)($rb['set_number']??1),(int)($rb['match_number']??0)];
    });

    $state=[];$eventFinal=[];$eventStats=[];$snapshots=[];$eventKeys=[];$qualMatches=0;$allMatches=0;
    $ensure=function(int $team)use(&$state,$year,$stats,$priorNorms): void {
        if($team>0&&!isset($state[$team]))$state[$team]=augur_epa_initial_state($team,$year,$stats,$priorNorms);
    };

    foreach($groups as $matchKey=>$pair){
        if(empty($pair['red'])||empty($pair['blue']))continue;
        $red=$pair['red'];$blue=$pair['blue'];$eventKey=(string)$pair['event_key'];
        $teamCount=augur_epa_num_teams($year);
        $redTeams=array_slice(augur_epa_sample_teams($red),0,$teamCount);
        $blueTeams=array_slice(augur_epa_sample_teams($blue),0,$teamCount);
        $combinedTeams=array_merge($redTeams,$blueTeams);
        if(count($redTeams)!==$teamCount || count($blueTeams)!==$teamCount
            || count(array_unique($combinedTeams))!==count($combinedTeams))continue;
        foreach($combinedTeams as $t)$ensure($t);
        $all=array_values(array_unique($combinedTeams));
        $before=[];foreach($all as $t)$before[$t]=$state[$t];

        $level=strtolower((string)($red['comp_level']??'qm'));
        $elim=$level!=='qm';$weight=$elim?AUGUR_EPA_ELIM_WEIGHT:1.0;
        augur_epa_update_stream($state,$redTeams,(float)$red['modeled_score'],'rating',$year,$weight);
        augur_epa_update_stream($state,$blueTeams,(float)$blue['modeled_score'],'rating',$year,$weight);
        foreach(['auto','teleop','endgame'] as $phase){
            $col=$phase.'_score';
            if($red[$col]!==null)augur_epa_update_stream($state,$redTeams,(float)$red[$col],$phase,$year,$weight);
            if($blue[$col]!==null)augur_epa_update_stream($state,$blueTeams,(float)$blue[$col],$phase,$year,$weight);
        }

        $redOfficial=(float)$red['official_score'];$blueOfficial=(float)$blue['official_score'];
        $winner=$redOfficial>$blueOfficial?'Red':($blueOfficial>$redOfficial?'Blue':'Tie');
        foreach(['Red'=>$redTeams,'Blue'=>$blueTeams] as $color=>$list){
            foreach($list as $t){
                $state[$t]['wins']+=($winner===$color?1:0);
                $state[$t]['losses']+=(($winner!=='Tie'&&$winner!==$color)?1:0);
                $state[$t]['ties']+=($winner==='Tie'?1:0);
                $state[$t]['history'][]=(float)$state[$t]['rating'];
                $state[$t]['events'][$eventKey]=true;$state[$t]['last_event']=$eventKey;
                if(!$elim)$state[$t]['count']++;

                if(!isset($eventStats[$eventKey][$t]))$eventStats[$eventKey][$t]=['matches'=>0,'wins'=>0,'losses'=>0,'ties'=>0,'history'=>[]];
                if(!$elim)$eventStats[$eventKey][$t]['matches']++;
                $eventStats[$eventKey][$t]['wins']+=($winner===$color?1:0);
                $eventStats[$eventKey][$t]['losses']+=(($winner!=='Tie'&&$winner!==$color)?1:0);
                $eventStats[$eventKey][$t]['ties']+=($winner==='Tie'?1:0);
                $eventStats[$eventKey][$t]['history'][]=(float)$state[$t]['rating'];
                $eventFinal[$eventKey][$t]=$state[$t];
            }
        }

        if(!$elim){
            $qualMatches++;
            foreach(['Red'=>$redTeams,'Blue'=>$blueTeams] as $color=>$list){
                foreach($list as $t){
                    $pre=$before[$t];$post=$state[$t];
                    $preSigma=max(1.0,(float)$stats['sd']/sqrt(max(1,((int)$pre['count'])+1)));
                    $postSigma=max(1.0,(float)$stats['sd']/sqrt(max(1,((int)$post['count'])+1)));
                    $snapshots[]=[
                        $eventKey,$year,$matchKey,(int)$red['match_number'],$t,$color,
                        $pre['rating'],$post['rating'],$post['rating']-$pre['rating'],
                        $pre['auto'],$post['auto'],$pre['teleop'],$post['teleop'],$pre['endgame'],$post['endgame'],
                        $preSigma,$postSigma,AUGUR_EPA_MODEL_VERSION
                    ];
                }
            }
        }
        $eventKeys[$eventKey]=true;$allMatches++;
    }

    $pdo->beginTransaction();
    try{
        $pdo->prepare('DELETE FROM augur_epa_match_ratings WHERE season_year=?')->execute([$year]);
        $pdo->prepare('DELETE FROM augur_epa_event_ratings WHERE season_year=?')->execute([$year]);
        $pdo->prepare('DELETE FROM augur_epa_season_ratings WHERE season_year=?')->execute([$year]);

        $snap=$pdo->prepare("INSERT INTO augur_epa_match_ratings
            (tba_event_key,season_year,tba_match_key,match_number,frc_team_number,alliance,pre_rating,post_rating,rating_delta,
             pre_auto_rating,post_auto_rating,pre_teleop_rating,post_teleop_rating,pre_endgame_rating,post_endgame_rating,
             pre_sigma,post_sigma,model_version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach($snapshots as $row)$snap->execute($row);

        $eventUp=$pdo->prepare("INSERT INTO augur_epa_event_ratings
            (tba_event_key,season_year,frc_team_number,rating,auto_rating,teleop_rating,endgame_rating,trend,sigma,confidence,
             matches_played,wins,losses,ties,model_version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $eventTeamRows=0;
        foreach($eventFinal as $eventKey=>$teams){
            foreach($teams as $team=>$r){
                $es=$eventStats[$eventKey][$team];
                $sigma=max(1.0,(float)$stats['sd']/sqrt(max(1,((int)$r['count'])+1)));
                $confidence=augur_epa_clamp(18+min(72,(int)$r['count']*4)+min(8,count($r['events'])*2),10,98);
                $eventUp->execute([
                    $eventKey,$year,$team,$r['rating'],$r['auto'],$r['teleop'],$r['endgame'],
                    augur_epa_slope(array_slice($es['history'],-6)),$sigma,$confidence,
                    $es['matches'],$es['wins'],$es['losses'],$es['ties'],AUGUR_EPA_MODEL_VERSION
                ]);
                $eventTeamRows++;
            }
        }

        $seasonUp=$pdo->prepare("INSERT INTO augur_epa_season_ratings
            (season_year,frc_team_number,rating,auto_rating,teleop_rating,endgame_rating,trend,sigma,confidence,events_played,
             matches_played,wins,losses,ties,last_event_key,model_version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach($state as $team=>$r){
            $sigma=max(1.0,(float)$stats['sd']/sqrt(max(1,((int)$r['count'])+1)));
            $confidence=augur_epa_clamp(18+min(72,(int)$r['count']*4)+min(8,count($r['events'])*2),10,99);
            $seasonUp->execute([
                $year,$team,$r['rating'],$r['auto'],$r['teleop'],$r['endgame'],
                augur_epa_slope(array_slice($r['history'],-8)),$sigma,$confidence,count($r['events']),
                $r['count'],$r['wins'],$r['losses'],$r['ties'],$r['last_event'],AUGUR_EPA_MODEL_VERSION
            ]);
        }

        $pdo->prepare("UPDATE augur_epa_archive_events SET ratings_calculated_at=UTC_TIMESTAMP(),model_version=?,last_error=NULL
            WHERE season_year=? AND alliance_sample_count>0 AND event_type BETWEEN 0 AND 6")
            ->execute([AUGUR_EPA_MODEL_VERSION,$year]);
        $pdo->prepare("UPDATE augur_epa_archive_events SET ratings_calculated_at=NULL,model_version=NULL
            WHERE season_year=? AND (event_type IS NULL OR event_type<0 OR event_type>6)")->execute([$year]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    return [
        'year'=>$year,'events'=>count($eventKeys),'event_team_rows'=>$eventTeamRows,'matches'=>$qualMatches,'all_matches'=>$allMatches,
        'teams'=>count($state),'renormalized_samples'=>$renormalized,'model_version'=>AUGUR_EPA_MODEL_VERSION,
    ];
}

/** Compatibility entry point: v4 ratings are season-global, so rebuilding one event rebuilds its season. */
function augur_epa_calculate_event_from_samples(PDO $pdo,string $eventKey,bool $rebuildSeason=true): array {
    $event=augur_epa_archive_event($pdo,$eventKey);
    if(!$event)throw new RuntimeException('EPA archive event not found: '.$eventKey);
    if(($event['source_status']??'')!=='ready'||(int)($event['alliance_sample_count']??0)<1){
        throw new RuntimeException('No completed match source data is archived for '.$eventKey.'.');
    }
    $year=(int)$event['season_year'];
    $season=augur_epa_recalculate_year($pdo,$year);
    $s=$pdo->prepare('SELECT COUNT(*) FROM augur_epa_event_ratings WHERE tba_event_key=?');$s->execute([$eventKey]);$teams=(int)$s->fetchColumn();
    $s=$pdo->prepare('SELECT COUNT(DISTINCT tba_match_key) FROM augur_epa_alliance_samples WHERE tba_event_key=?');$s->execute([$eventKey]);$matches=(int)$s->fetchColumn();
    return ['event_key'=>$eventKey,'year'=>$year,'matches'=>$matches,'teams'=>$teams,'model_version'=>AUGUR_EPA_MODEL_VERSION,'season'=>$season];
}

/** Retained for older callers; v4 season summary is written by the chronological season rebuild. */
function augur_epa_rebuild_season_summary(PDO $pdo,int $year): void {
    augur_epa_recalculate_year($pdo,$year);
}

function augur_epa_event_needs_work(array $event): bool {
    $status=(string)($event['source_status']??'discovered');
    $historical=(int)($event['is_complete']??0)===1;
    $fetched=strtotime((string)($event['source_fetched_at']??''))?:0;
    if($status==='error'||$status==='discovered')return true;
    if($status==='waiting')return $fetched<time()-AUGUR_EPA_LIVE_REFRESH_SECONDS;
    if($status==='no_qual_data')return !$historical&&$fetched<time()-AUGUR_EPA_LIVE_REFRESH_SECONDS;
    if($status==='ready'){
        // Browser backfill uses this function to find SOURCE work only. Model
        // changes are recalculated once per year after source ingestion, rather
        // than recalculating the whole season once for every archived event.
        if(!$historical&&$fetched<time()-AUGUR_EPA_LIVE_REFRESH_SECONDS)return true;
        return false;
    }
    return true;
}

function augur_epa_next_work_event(PDO $pdo,int $year): ?array {
    $s=$pdo->prepare("SELECT * FROM augur_epa_archive_events WHERE season_year=? ORDER BY COALESCE(start_date,end_date),tba_event_key");
    $s->execute([$year]);
    foreach($s->fetchAll() as $event)if(augur_epa_event_needs_work($event))return $event;
    return null;
}

function augur_epa_ensure_event_by_key(PDO $pdo,string $eventKey,bool $force=false): array {
    if(!augur_epa_tables_ready($pdo))throw new RuntimeException('Public EPA Archive tables are not installed.');
    $event=augur_epa_ensure_event_metadata($pdo,$eventKey,false);
    $status=(string)($event['source_status']??'discovered');
    $historical=(int)($event['is_complete']??0)===1;
    $fetched=strtotime((string)($event['source_fetched_at']??''))?:0;

    $needsFetch=$force||!in_array($status,['ready','no_qual_data'],true);
    if(!$historical&&$fetched<time()-AUGUR_EPA_LIVE_REFRESH_SECONDS)$needsFetch=true;
    if($historical&&in_array($status,['ready','no_qual_data'],true)&&!$force)$needsFetch=false;

    $ingest=['fetched'=>false,'cached'=>true,'status'=>$status,'matches'=>(int)($event['qual_match_count']??0)];
    if($needsFetch)$ingest=augur_epa_ingest_event($pdo,$eventKey,$force);
    $event=augur_epa_archive_event($pdo,$eventKey)?:$event;

    $calc=null;
    if(($event['source_status']??'')==='ready'&&(int)($event['alliance_sample_count']??0)>0){
        $needCalc=$force||(string)($event['model_version']??'')!==AUGUR_EPA_MODEL_VERSION||empty($event['ratings_calculated_at'])||!empty($ingest['changed']);
        if($needCalc)$calc=augur_epa_calculate_event_from_samples($pdo,$eventKey,true);
    }
    return ['event_key'=>$eventKey,'source'=>$ingest,'calculation'=>$calc,'event'=>augur_epa_archive_event($pdo,$eventKey)];
}

function augur_epa_neptune_event(PDO $pdo,int $org,int $eventId): ?array {
    $s=$pdo->prepare("SELECT e.id,e.organization_id,e.name,e.tba_event_key,e.start_date,e.end_date,g.season_year
        FROM events e JOIN games g ON g.id=e.game_id WHERE e.id=? AND e.organization_id=? LIMIT 1");
    $s->execute([$eventId,$org]);return $s->fetch()?:null;
}

function augur_epa_ensure_neptune_event(PDO $pdo,int $org,int $eventId,bool $force=false): array {
    $event=augur_epa_neptune_event($pdo,$org,$eventId);
    if(!$event)throw new RuntimeException('Neptune event not found.');
    $key=trim((string)($event['tba_event_key']??''));
    if($key==='')throw new RuntimeException('This Neptune event is not linked to TBA.');
    // Local event metadata is enough to register the archive row without another TBA metadata call.
    if(!augur_epa_archive_event($pdo,$key)){
        augur_epa_upsert_event_metadata($pdo,[
            'key'=>$key,'year'=>(int)$event['season_year'],'name'=>(string)$event['name'],
            'start_date'=>$event['start_date'],'end_date'=>$event['end_date']
        ]);
    }
    return augur_epa_ensure_event_by_key($pdo,$key,$force);
}

/** Backward-compatible entry point used by older Neptune pages. */
function augur_epa_rebuild_event(PDO $pdo,int $org,int $eventId): array {
    $r=augur_epa_ensure_neptune_event($pdo,$org,$eventId,true);
    $calc=$r['calculation']??[];
    $event=$r['event']??[];
    return [
        'event_id'=>$eventId,'event_key'=>$event['tba_event_key']??null,'year'=>(int)($event['season_year']??0),
        'matches'=>(int)($event['qual_match_count']??($calc['matches']??0)),
        'teams'=>(int)($calc['teams']??0),
        'component_alliance_samples'=>[
            'auto'=>(int)($event['auto_sample_count']??0),
            'teleop'=>(int)($event['teleop_sample_count']??0),
            'endgame'=>(int)($event['endgame_sample_count']??0),
        ],
        'model_version'=>AUGUR_EPA_MODEL_VERSION,
    ];
}

function augur_epa_event_rating(PDO $pdo,string $eventKey,int $team): ?array {
    $s=$pdo->prepare('SELECT * FROM augur_epa_event_ratings WHERE tba_event_key=? AND frc_team_number=? LIMIT 1');
    $s->execute([$eventKey,$team]);return $s->fetch()?:null;
}
function augur_epa_season_rating(PDO $pdo,int $year,int $team): ?array {
    $s=$pdo->prepare('SELECT * FROM augur_epa_season_ratings WHERE season_year=? AND frc_team_number=? LIMIT 1');
    $s->execute([$year,$team]);return $s->fetch()?:null;
}

function augur_epa_rating_map(PDO $pdo,int $org,array $event,array $teams,?array $cutoffMatch=null): array {
    if(!augur_epa_tables_ready($pdo))return [];
    $key=trim((string)($event['tba_event_key']??''));
    $year=(int)($event['season_year']??0);
    if($key===''||$year<1)return [];
    $eventStart=(string)($event['start_date']??'')?:null;
    $out=[];
    foreach(array_values(array_unique(array_map('intval',$teams))) as $team){
        if($team<1)continue;$row=null;
        if($cutoffMatch&&(int)($cutoffMatch['match_number']??0)>0){
            $target=(int)$cutoffMatch['match_number'];
            $targetLevel=strtolower((string)($cutoffMatch['comp_level']??'qm'));

            if($targetLevel==='qm'){
                // Qualification backtest: use the EPA snapshot immediately before
                // this qualification match so later results cannot leak backward.
                $s=$pdo->prepare("SELECT pre_rating rating,pre_auto_rating auto_rating,pre_teleop_rating teleop_rating,
                    pre_endgame_rating endgame_rating,pre_sigma sigma,match_number
                    FROM augur_epa_match_ratings WHERE tba_event_key=? AND frc_team_number=? AND match_number=?
                    ORDER BY id DESC LIMIT 1");
                $s->execute([$key,$team,$target]);$row=$s->fetch()?:null;
                if(!$row){
                    $s=$pdo->prepare("SELECT post_rating rating,post_auto_rating auto_rating,post_teleop_rating teleop_rating,
                        post_endgame_rating endgame_rating,post_sigma sigma,match_number
                        FROM augur_epa_match_ratings WHERE tba_event_key=? AND frc_team_number=? AND match_number<?
                        ORDER BY match_number DESC,id DESC LIMIT 1");
                    $s->execute([$key,$team,$target]);$row=$s->fetch()?:null;
                }
            }else{
                // Match-rating snapshots are qualification-only. For a playoff
                // backtest, the correct non-leaking public baseline is therefore
                // the team's final qualification post-rating—not "QM <playoff #>".
                $s=$pdo->prepare("SELECT post_rating rating,post_auto_rating auto_rating,post_teleop_rating teleop_rating,
                    post_endgame_rating endgame_rating,post_sigma sigma,match_number
                    FROM augur_epa_match_ratings WHERE tba_event_key=? AND frc_team_number=?
                    ORDER BY match_number DESC,id DESC LIMIT 1");
                $s->execute([$key,$team]);$row=$s->fetch()?:null;
            }
            if(!$row)$row=augur_epa_prior_rating($pdo,$year,$key,$team,$eventStart);
        }else{
            $row=augur_epa_event_rating($pdo,$key,$team);
            if(!$row)$row=augur_epa_season_rating($pdo,$year,$team);
        }
        if($row)$out[$team]=[
            'epa'=>(float)$row['rating'],
            'auto'=>array_key_exists('auto_rating',$row)&&$row['auto_rating']!==null?(float)$row['auto_rating']:null,
            'teleop'=>array_key_exists('teleop_rating',$row)&&$row['teleop_rating']!==null?(float)$row['teleop_rating']:null,
            'endgame'=>array_key_exists('endgame_rating',$row)&&$row['endgame_rating']!==null?(float)$row['endgame_rating']:null,
            'confidence'=>isset($row['confidence'])?(float)$row['confidence']:null,
            'sigma'=>isset($row['sigma'])?(float)$row['sigma']:null,
            'source'=>'AUGUR Public EPA Archive v4 · Statbotics core',
        ];
    }
    return $out;
}

function augur_epa_archive_summary(PDO $pdo): array {
    $out=['years'=>0,'events'=>0,'rated_events'=>0,'samples'=>0,'event_team_ratings'=>0,'match_team_ratings'=>0,'season_team_ratings'=>0];
    if(!augur_epa_tables_ready($pdo))return $out;
    foreach([
        'years'=>'augur_epa_archive_years',
        'events'=>'augur_epa_archive_events',
        'samples'=>'augur_epa_alliance_samples',
        'event_team_ratings'=>'augur_epa_event_ratings',
        'match_team_ratings'=>'augur_epa_match_ratings',
        'season_team_ratings'=>'augur_epa_season_ratings',
    ] as $k=>$table){
        $out[$k]=(int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }
    $out['rated_events']=(int)$pdo->query("SELECT COUNT(*) FROM augur_epa_archive_events WHERE ratings_calculated_at IS NOT NULL")->fetchColumn();
    return $out;
}
