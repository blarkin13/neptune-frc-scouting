<?php
declare(strict_types=1);
/** FIRST Events API adapter. Read secrets only from QA/prod secure root. */
require_once __DIR__.'/_first_epa_local.php';
require_once __DIR__.'/_first_event_links.php';
function neptune_first_config(): array {
    $path=dirname(__DIR__,3).'/neptune_secure/first-api.php';
    if(!is_file($path))throw new RuntimeException('FIRST API credentials are not configured in neptune_secure/first-api.php');
    $c=require $path;
    if(!is_array($c)||empty($c['username'])||empty($c['token']))throw new RuntimeException('FIRST API configuration is incomplete.');
    return $c;
}
function neptune_first_get(string $path): array {
    if(!preg_match('~^[0-9]{4}/[A-Za-z0-9/?=&_%-]+$~',$path))throw new InvalidArgumentException('Invalid FIRST API path');
    $c=neptune_first_config();$url='https://frc-api.firstinspires.org/v3.0/'.$path;
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>(string)$c['username'].':'.(string)$c['token'],CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($raw===false||$status!==200)throw new RuntimeException('FIRST API request failed (HTTP '.$status.'): '.$error);
    $data=json_decode($raw,true);
    if(!is_array($data))throw new RuntimeException('FIRST API returned invalid JSON.');
    return $data;
}
/** A not-yet-published playoff schedule may be HTTP 404; that must not
 * prevent qualification roster/results sync. Other provider errors surface. */
function neptune_first_optional_playoff_get(string $path,string $root): array {
    try{$data=neptune_first_get($path);}
    catch(RuntimeException $e){
        if(str_contains($e->getMessage(),'HTTP 404'))return [];
        throw $e;
    }
    $rows=$data[$root]??[];
    if(!is_array($rows))throw new RuntimeException('FIRST playoff response has invalid '.$root.' data.');
    return $rows;
}
function neptune_first_code(string $value): string {
    $code=strtoupper(trim($value));
    if(!preg_match('/^[A-Z0-9]{3,12}$/',$code))throw new InvalidArgumentException('Invalid FIRST event code.');
    return $code;
}
function neptune_first_match_teams(array $m): array {
    $stations=[];
    foreach(($m['teams']??[]) as $t){
        $s=(string)($t['station']??'');$n=(int)($t['teamNumber']??0);
        if($n>0&&preg_match('/^(Red|Blue)([123])$/',$s,$parts))$stations[$s]=[$n,$parts[1],(int)$parts[2]];
    }
    return $stations;
}
/**
 * Map FIRST's qualification/playoff schedule identity into Neptune's TBA-
 * compatible competition tuple. A 2026 double-elimination playoff match N is
 * sfNm1; finals use f1mN. FIRST provides both series/matchNumber and a human
 * description; prefer its explicit playoff/final match label where present.
 *
 * Never turn unrecognised tournament levels into qualifications.
 * @return array{0:string,1:int,2:int}|null
 */
function neptune_first_match_identity(array $match,string $requestedLevel): ?array {
    $level=strtolower(trim((string)($match['tournamentLevel']??$requestedLevel)));
    $number=(int)($match['matchNumber']??0);
    if($number<1)return null;
    if(in_array($level,['qualification','qual','qm'],true)){
        return $requestedLevel==='qual'?['qm',1,$number]:null;
    }
    if(!in_array($level,['playoff','playoffs'],true)||$requestedLevel!=='playoff')return null;

    $description=trim((string)($match['description']??''));
    $series=(int)($match['series']??0);
    // Official display examples: 'Match 7 (R2)', 'Playoff Match 7',
    // 'Final 1', 'Finals Match 2'. Distinguish finals before generic Match.
    // Accommodate tournaments using traditional quarterfinal/semi rounds.
    // Official double-elimination events use numbered 'Playoff Match N'
    // instead, and fall through to the sfNm1 mapping below.
    if(preg_match('/\bquarter[ -]?finals?\b/i',$description)){
        return $series>=1&&$series<=8&&$number<=6?['qf',$series,$number]:null;
    }
    if(preg_match('/\bsemi[ -]?finals?\b/i',$description)){
        return $series>=1&&$series<=8&&$number<=6?['sf',$series,$number]:null;
    }
    if(preg_match('/\bfinals?\b/i',$description)){
        $final=$number;
        if(preg_match('/\bfinals?\s*(?:match\s*)?#?\s*(\d+)\b/i',$description,$m))$final=(int)$m[1];
        return $final>0&&$final<=20?['f',1,$final]:null;
    }
    if(preg_match('/\b(?:playoff\s*)?match\s*#?\s*(\d+)\b/i',$description,$m)){
        $ordinal=(int)$m[1];
        if($ordinal>=1&&$ordinal<=13){
            // If this particular playoff series has an extra match, keep that
            // distinct; otherwise match 1 is the usual double-elim format.
            $inSet=($series===$ordinal&&$number<=6)?$number:1;
            return ['sf',$ordinal,$inSet];
        }
        // Some feeds label the final phase as Playoff Match 14/15/16.
        if($ordinal>=14&&$ordinal<=20)return ['f',1,$ordinal-13];
    }
    // Fallback for FIRST feeds that omit the human-readable description.
    if($series>=1&&$series<=13)return ['sf',$series,$number];
    if($series>=14&&$series<=20)return ['f',1,$number===1?$series-13:$number];
    if($series===0&&$number>=1&&$number<=13)return ['sf',$number,1];
    if($series===0&&$number>=14&&$number<=20)return ['f',1,$number-13];
    return null;
}

/** @return string Unique scoped match identity without depending on event ID. */
function neptune_first_identity_key(array $id): string {
    return $id[0].':'.$id[1].':'.$id[2];
}

/** Merge a single fixture stream without inventing placeholder alliances. */
function neptune_first_merge_fixtures(array &$fixtures,array $rows,string $level,bool $fromSchedule): void {
    foreach($rows as $m){
        if(!is_array($m))continue;
        $id=neptune_first_match_identity($m,$level);
        if($id===null)continue;
        $key=neptune_first_identity_key($id);
        if(!isset($fixtures[$key])){
            $fixtures[$key]=['id'=>$id,'schedule'=>null,'result'=>null];
        }
        // Schedule roster takes precedence when complete. Results fill holes.
        $slot=$fromSchedule?'schedule':'result';
        $fixtures[$key][$slot]=$m;
    }
}

/** Upsert FIRST roster, qualifications and playoffs without erasing scouting. */
function neptune_first_sync(PDO $pdo,int $org,int $eventId,string $code,int $year): array {
    $code=neptune_first_code($code);
    if($year<1992||$year>2100)throw new InvalidArgumentException('Invalid season.');
    $s=$pdo->prepare('SELECT e.*,g.season_year FROM events e JOIN games g ON g.id=e.game_id WHERE e.id=? AND e.organization_id=? LIMIT 1');
    $s->execute([$eventId,$org]);$event=$s->fetch(PDO::FETCH_ASSOC);
    if(!$event)throw new RuntimeException('Event not found in this organization.');
    if((int)$event['season_year']!==$year)throw new RuntimeException('Selected FIRST season does not match Neptune game season.');
    $teams=neptune_first_get($year.'/teams?eventCode='.$code)['teams']??[];
    $qualSchedule=neptune_first_get($year.'/schedule/'.$code.'?tournamentLevel=qual')['Schedule']??[];
    $qualResults=neptune_first_get($year.'/matches/'.$code.'?tournamentLevel=qual')['Matches']??[];
    $playoffSchedule=neptune_first_optional_playoff_get($year.'/schedule/'.$code.'?tournamentLevel=playoff','Schedule');
    $playoffResults=neptune_first_optional_playoff_get($year.'/matches/'.$code.'?tournamentLevel=playoff','Matches');
    foreach([$teams,$qualSchedule,$qualResults,$playoffSchedule,$playoffResults] as $part){
        if(!is_array($part))throw new RuntimeException('Unexpected FIRST API response format.');
    }
    if(!$teams&&!$qualSchedule&&!$qualResults&&!$playoffSchedule&&!$playoffResults){
        throw new RuntimeException('FIRST returned no roster or match data; nothing changed.');
    }

    // The FIRST identifier is separate from TBA's events.event_code, so a
    // subsequent TBA link cannot destroy the FIRST fallback link.
    // Link migrations contain DDL, so save the link BEFORE the DML transaction.
    // MySQL CREATE TABLE (even IF NOT EXISTS) implicitly commits transactions.
    neptune_first_link_save($pdo,$org,$eventId,$year,$code);
    $fixtures=[];
    neptune_first_merge_fixtures($fixtures,$qualSchedule,'qual',true);
    neptune_first_merge_fixtures($fixtures,$qualResults,'qual',false);
    neptune_first_merge_fixtures($fixtures,$playoffSchedule,'playoff',true);
    neptune_first_merge_fixtures($fixtures,$playoffResults,'playoff',false);
    uasort($fixtures,static function(array $a,array $b): int {
        $rank=static fn(array $id): int=>match($id[0]){'qm'=>0,'sf'=>1,'f'=>2,default=>3};
        return [$rank($a['id']),$a['id'][1],$a['id'][2]]<=>[$rank($b['id']),$b['id'][1],$b['id'][2]];
    });

    $teamCount=0;$matchCount=0;$scored=0;$stationCount=0;$playoffs=0;$playoffScored=0;
    $pdo->beginTransaction();
    try{
        $upTeam=$pdo->prepare('INSERT INTO event_teams(event_id,frc_team_number,nickname,city,state_prov,country,tba_team_key) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nickname=COALESCE(nickname,VALUES(nickname)),city=COALESCE(city,VALUES(city)),state_prov=COALESCE(state_prov,VALUES(state_prov)),country=COALESCE(country,VALUES(country))');
        foreach($teams as $t){
            if(!is_array($t))continue;
            $n=(int)($t['teamNumber']??0);if($n<1)continue;
            $upTeam->execute([$eventId,$n,$t['nameShort']??null,$t['city']??null,$t['stateProv']??null,$t['country']??null,'frc'.$n]);$teamCount++;
        }
        $upMatch=$pdo->prepare("INSERT INTO matches
            (organization_id,event_id,game_id,tba_match_key,comp_level,set_number,match_number,field_id,scheduled_time,red_score,blue_score,winning_alliance,state)
            VALUES(?,?,?,NULL,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              scheduled_time=IF(tba_match_key IS NOT NULL AND scheduled_time IS NOT NULL,scheduled_time,COALESCE(VALUES(scheduled_time),scheduled_time)),
              red_score=IF(tba_match_key IS NOT NULL AND red_score IS NOT NULL,red_score,COALESCE(VALUES(red_score),red_score)),
              blue_score=IF(tba_match_key IS NOT NULL AND blue_score IS NOT NULL,blue_score,COALESCE(VALUES(blue_score),blue_score)),
              winning_alliance=IF(tba_match_key IS NOT NULL AND red_score IS NOT NULL AND blue_score IS NOT NULL,winning_alliance,IF(VALUES(red_score) IS NOT NULL AND VALUES(blue_score) IS NOT NULL,VALUES(winning_alliance),winning_alliance)),
              state=IF(VALUES(red_score) IS NOT NULL AND VALUES(blue_score) IS NOT NULL AND state='scheduled','ended',state)");
        $find=$pdo->prepare('SELECT id,tba_match_key FROM matches WHERE organization_id=? AND event_id=? AND comp_level=? AND set_number=? AND match_number=? AND field_id=1 LIMIT 1');
        $upStation=$pdo->prepare('INSERT INTO match_teams(match_id,frc_team_number,alliance,station) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE frc_team_number=VALUES(frc_team_number)');
        $roster=$pdo->prepare('INSERT INTO event_teams(event_id,frc_team_number,tba_team_key) VALUES(?,?,?) ON DUPLICATE KEY UPDATE frc_team_number=VALUES(frc_team_number)');
        foreach($fixtures as $fixture){
            [$comp,$set,$number]=$fixture['id'];
            $sched=$fixture['schedule'];$result=$fixture['result'];
            // A completed result can supply the roster if a schedule entry is
            // missing or still holds placeholder alliance slots.
            $stations=is_array($sched)?neptune_first_match_teams($sched):[];
            if(count($stations)!==6||count(array_unique(array_column($stations,0)))!==6){
                $stations=is_array($result)?neptune_first_match_teams($result):[];
            }
            if(count($stations)!==6||count(array_unique(array_column($stations,0)))!==6)continue;

            $red=null;$blue=null;
            if(is_array($result)&&!empty($result['postResultTime'])
                &&is_numeric($result['scoreRedFinal']??null)&&is_numeric($result['scoreBlueFinal']??null)
                &&(float)$result['scoreRedFinal']>=0&&(float)$result['scoreBlueFinal']>=0){
                $red=(int)$result['scoreRedFinal'];$blue=(int)$result['scoreBlueFinal'];
            }
            $winner=$red===null?'Unknown':($red===$blue?'Tie':($red>$blue?'Red':'Blue'));
            $timestamp=trim((string)($sched['startTime']??$result['actualStartTime']??''));
            // FIRST timestamps are venue-local; keep UTC conversion out of this
            // path because the current Neptune schema stores naive DATETIME.
            $scheduled=null;
            if($timestamp!==''){
                $dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',substr($timestamp,0,19));
                if($dt)$scheduled=$dt->format('Y-m-d H:i:s');
            }
            $upMatch->execute([$org,$eventId,(int)$event['game_id'],$comp,$set,$number,1,$scheduled,$red,$blue,$winner,$red===null?'scheduled':'ended']);
            $find->execute([$org,$eventId,$comp,$set,$number]);
            $existing=$find->fetch(PDO::FETCH_ASSOC);$mid=(int)($existing['id']??0);
            if($mid<1)throw new RuntimeException('FIRST match upsert did not return a match ID.');
            // TBA-owned schedules/teams remain authoritative, as before.
            foreach($stations as [$team,$alliance,$station]){
                $roster->execute([$eventId,$team,'frc'.$team]);
                if(empty($existing['tba_match_key'])){
                    $upStation->execute([$mid,$team,$alliance,$station]);$stationCount++;
                }
            }
            if($comp==='qm'){$matchCount++;if($red!==null)$scored++;}
            else{$playoffs++;if($red!==null)$playoffScored++;}
        }
        if($teamCount)$pdo->prepare('UPDATE events SET roster_synced_at=UTC_TIMESTAMP() WHERE id=?')->execute([$eventId]);
        if($matchCount||$playoffs)$pdo->prepare("UPDATE events SET schedule_synced_at=UTC_TIMESTAMP(),event_status=CASE WHEN event_status='complete' THEN 'complete' WHEN ? > 0 THEN 'running' WHEN event_status IN ('planned','pit_open') THEN 'schedule_ready' ELSE event_status END WHERE id=?")->execute([$scored+$playoffScored,$eventId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    // The existing provisional FIRST EPA model is intentionally qualification
    // only. Do not blend playoffs into its historical qualification snapshots.
    $epa=null;$epaError=null;
    try{$epa=neptune_first_epa_rebuild($pdo,$org,$eventId,$year,$qualResults);}
    catch(Throwable $e){$epaError=$e->getMessage();}
    return ['teams'=>$teamCount,'matches'=>$matchCount,'stations'=>$stationCount,'scored'=>$scored,
        'playoff_matches'=>$playoffs,'playoff_scored'=>$playoffScored,
        'source'=>'FIRST','epa'=>$epa,'epa_error'=>$epaError];
}
