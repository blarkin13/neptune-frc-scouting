<?php
declare(strict_types=1);
/** Conservative, tenant-scoped, FIRST -> TBA discovery and handoff. */
require_once __DIR__.'/_first_event_links.php';
require_once dirname(__DIR__,3).'/neptune_secure/tba.php';
function neptune_tba_discovery_optional(string $path,int $ttl=300): array {
    try{return tba_get($path,$ttl);}
    catch(Throwable $e){if(str_contains($e->getMessage(),'HTTP 404'))return [];throw $e;}
}

function neptune_tba_discovery_name(string $name): string {
    $name=strtolower(trim($name));
    $name=preg_replace('/\b(first|frc|event|competition|tournament|offseason|off-season|presented by)\b/i',' ',$name);
    $name=preg_replace('/[^a-z0-9]+/',' ',$name);
    return trim((string)preg_replace('/\s+/',' ',$name));
}
function neptune_tba_discovery_name_match(string $local,string $remote): float {
    $a=neptune_tba_discovery_name($local);$b=neptune_tba_discovery_name($remote);
    if($a===''||$b==='')return 0.0;
    if($a===$b)return 1.0;
    similar_text($a,$b,$pct);$best=$pct/100;
    // A manual name may include a sponsor/team prefix (e.g. STEM Gals - ...).
    foreach(preg_split('/\s*[-–—]\s*/u',$local)?:[] as $fragment){
        $f=neptune_tba_discovery_name($fragment);
        if(strlen($f)>=8 && ($f===$b||str_contains($b,$f)))$best=max($best,0.96);
    }
    foreach(preg_split('/\s*[-–—]\s*/u',$remote)?:[] as $fragment){
        $f=neptune_tba_discovery_name($fragment);
        if(strlen($f)>=8 && ($f===$a||str_contains($a,$f)))$best=max($best,0.96);
    }
    if(strlen($a)>=10&&strlen($b)>=10&&(str_contains($a,$b)||str_contains($b,$a)))$best=max($best,0.92);
    return $best;
}
function neptune_tba_discovery_date_delta(string $a,string $b): ?int {
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$a)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$b))return null;
    $x=DateTimeImmutable::createFromFormat('!Y-m-d',$a,new DateTimeZone('UTC'));
    $y=DateTimeImmutable::createFromFormat('!Y-m-d',$b,new DateTimeZone('UTC'));
    return ($x&&$y)?(int)$x->diff($y)->format('%r%a'):null;
}
function neptune_tba_discovery_team_numbers(array $rows): array {
    $out=[];
    foreach($rows as $row){
        if(!is_array($row))continue;
        $n=(int)($row['team_number']??$row['frc_team_number']??0);
        if($n<1&&isset($row['key']))$n=(int)preg_replace('/\D/','',(string)$row['key']);
        if($n>0)$out[$n]=true;
    }
    return $out;
}
/** Pure matching logic, supports fixture testing without database/network. */
function neptune_tba_discovery_assess(array $event,array $candidate,string $firstCode,array $localTeams,array $remoteTeams): array {
    $codeMatch=$firstCode!==''&&strcasecmp(trim((string)($candidate['event_code']??'')),$firstCode)===0;
    $name=max(
        neptune_tba_discovery_name_match((string)($event['name']??''),(string)($candidate['name']??'')),
        neptune_tba_discovery_name_match((string)($event['name']??''),(string)($candidate['short_name']??''))
    );
    $delta=neptune_tba_discovery_date_delta((string)($event['start_date']??''),(string)($candidate['start_date']??''));
    $local=neptune_tba_discovery_team_numbers($localTeams);
    $remote=neptune_tba_discovery_team_numbers($remoteTeams);
    $overlap=count(array_intersect_key($local,$remote));
    $denom=min(count($local),count($remote));
    $ratio=$denom>0?$overlap/$denom:0.0;
    $score=($codeMatch?110:0)+(int)round($name*70)+($delta===0?40:($delta!==null&&abs($delta)<=1?25:0))+
        (($denom>=6&&$overlap>=5&&$ratio>=0.5)?50:0);
    $reason='';
    if($delta!==null&&abs($delta)>3)$reason='Event dates differ by more than 3 days.';
    elseif(!$codeMatch&&$name<0.92)$reason='Neither FIRST event code nor an exact/strong name match.';
    elseif($delta===null&&!$codeMatch)$reason='Cannot confirm dates without matching FIRST event code.';
    elseif($denom>=6&&($overlap<5||$ratio<0.5))$reason='Team rosters do not sufficiently overlap.';
    elseif($denom<6&&!($codeMatch&&$name>=0.85&&$delta!==null&&abs($delta)<=1))$reason='Insufficient team evidence for automatic linking.';
    elseif(!$codeMatch&&!($name>=0.92&&$delta!==null&&abs($delta)<=1&&$overlap>=5&&$ratio>=0.5))$reason='Name, date, and roster confirmation is incomplete.';
    elseif($score<145)$reason='Confidence below the automatic-link threshold.';
    return ['safe'=>$reason==='','reason'=>$reason,'score'=>$score,'code_match'=>$codeMatch,
        'name_match'=>round($name,3),'date_delta'=>$delta,'team_overlap'=>$overlap,'team_ratio'=>round($ratio,3)];
}
/** Reject mismatched FIRST/TBA qualifying match station rosters before linking. */
function neptune_tba_discovery_schedule_compare(array $localRows,array $tbaMatches): array {
    $local=[];
    foreach($localRows as $r){
        $n=(int)$r['match_number'];$a=strtolower((string)$r['alliance']);
        if(in_array($a,['red','blue'],true))$local[$n][$a][(int)$r['frc_team_number']]=true;
    }
    $compared=0;$errors=[];
    foreach($tbaMatches as $m){
        if(!is_array($m)||strtolower((string)($m['comp_level']??'qm'))!=='qm')continue;
        $n=(int)($m['match_number']??0);
        if(!$n||!isset($local[$n]['red'],$local[$n]['blue']))continue;
        $incoming=[];
        foreach(['red','blue'] as $a){
            foreach((array)($m['alliances'][$a]['team_keys']??[]) as $k){
                $t=(int)preg_replace('/\D/','',(string)$k);if($t>0)$incoming[$a][$t]=true;
            }
        }
        if(count($incoming['red']??[])!==3||count($incoming['blue']??[])!==3)continue;
        $compared++;
        foreach(['red','blue'] as $a){
            $aNums=array_keys($local[$n][$a]);$bNums=array_keys($incoming[$a]);sort($aNums);sort($bNums);
            if($aNums!==$bNums){$errors[]='Qualification '.$n.' has different '.$a.' teams';break;}
        }
    }
    $published=0;foreach($tbaMatches as $m)if(is_array($m)&&($m['comp_level']??'qm')==='qm')$published++;
    if($published>0&&count($local)>0&&$compared===0)$errors[]='No qualification match stations could be cross-checked';
    return ['safe'=>!$errors,'checked'=>$compared,'reason'=>implode('; ',array_slice($errors,0,3))];
}
function neptune_tba_discovery_schedule_safe(PDO $pdo,int $org,int $eventId,array $tbaMatches): array {
    $q=$pdo->prepare("SELECT m.match_number,mt.alliance,mt.frc_team_number
        FROM matches m JOIN match_teams mt ON mt.match_id=m.id
        WHERE m.organization_id=? AND m.event_id=? AND m.comp_level='qm' AND m.set_number=1");
    $q->execute([$org,$eventId]);
    return neptune_tba_discovery_schedule_compare($q->fetchAll(PDO::FETCH_ASSOC),$tbaMatches);
}
/** Caller throttles via last_tba_discovery_at. Network calls precede linking. */
function neptune_tba_discover_and_link(PDO $pdo,int $org,int $eventId,int $year,string $firstCode): array {
    $q=$pdo->prepare('SELECT id,name,start_date,end_date,tba_event_key FROM events WHERE id=? AND organization_id=? LIMIT 1');
    $q->execute([$eventId,$org]);$event=$q->fetch(PDO::FETCH_ASSOC);
    if(!$event)throw new RuntimeException('Event not found in organization.');
    if(trim((string)($event['tba_event_key']??''))!=='')return ['status'=>'already_linked'];
    if(!preg_match('/^[A-Z0-9]{3,12}$/',$firstCode))return ['status'=>'unlinked'];
    try {
        $directory=tba_get('events/'.$year.'/simple',1800);
        if(!is_array($directory))throw new RuntimeException('Invalid TBA event directory.');
        $candidates=[];
        foreach($directory as $row){
            if(!is_array($row)||empty($row['key'])||!str_starts_with((string)$row['key'],(string)$year))continue;
            $sim=max(neptune_tba_discovery_name_match((string)$event['name'],(string)($row['name']??'')),
                neptune_tba_discovery_name_match((string)$event['name'],(string)($row['short_name']??'')));
            $codeMatch=strcasecmp((string)($row['event_code']??''),$firstCode)===0;
            if(!$codeMatch&&$sim<0.72)continue;
            $delta=neptune_tba_discovery_date_delta((string)($event['start_date']??''),(string)($row['start_date']??''));
            if($delta!==null&&abs($delta)>3)continue;
            $candidates[]=['event'=>$row,'rough'=>($codeMatch?120:0)+(int)round($sim*80)+($delta===0?45:0)];
        }
        usort($candidates,static fn($a,$b)=>$b['rough']<=>$a['rough']);
        $localQ=$pdo->prepare('SELECT frc_team_number FROM event_teams WHERE event_id=?');$localQ->execute([$eventId]);
        $locals=array_map(static fn($n)=>['frc_team_number'=>(int)$n],$localQ->fetchAll(PDO::FETCH_COLUMN));
        $valid=[];$rejects=[];
        foreach(array_slice($candidates,0,4) as $item){
            $row=$item['event'];$key=(string)$row['key'];
            $teams=neptune_tba_discovery_optional('event/'.rawurlencode($key).'/teams/simple',300);
            $e=neptune_tba_discovery_assess($event,$row,$firstCode,$locals,$teams);
            if(!$e['safe']){$rejects[]=$key.': '.$e['reason'];continue;}
            $matches=neptune_tba_discovery_optional('event/'.rawurlencode($key).'/matches/simple',300);
            $guard=neptune_tba_discovery_schedule_safe($pdo,$org,$eventId,$matches);
            if(!$guard['safe']){$rejects[]=$key.': '.$guard['reason'];continue;}
            $valid[]=['key'=>$key,'score'=>$e['score'],'checks'=>$guard['checked']];
        }
        usort($valid,static fn($a,$b)=>$b['score']<=>$a['score']);
        $best=$valid[0]??null;$second=$valid[1]??null;
        if(!$best){$status=$candidates?'needs_review':'not_found';$note=$rejects?implode(' | ',array_slice($rejects,0,2)):'No TBA candidate meets code/name/date checks';neptune_first_discovery_record($pdo,$org,$eventId,$status,$note,(string)($candidates[0]['event']['key']??''));return ['status'=>$status,'note'=>$note];}
        if($second&&$best['score']-$second['score']<30){neptune_first_discovery_record($pdo,$org,$eventId,'ambiguous','Multiple TBA matches are too close to auto-link.',$best['key']);return ['status'=>'ambiguous','candidate'=>$best['key']];}
        $key=$best['key'];
        $pdo->beginTransaction();
        try{
            $other=$pdo->prepare('SELECT id FROM events WHERE organization_id=? AND tba_event_key=? AND id<>? LIMIT 1');
            $other->execute([$org,$key,$eventId]);
            if($other->fetchColumn())throw new RuntimeException('TBA key belongs to another Neptune event in this organization.');
            $upd=$pdo->prepare("UPDATE events SET tba_event_key=? WHERE id=? AND organization_id=? AND (tba_event_key IS NULL OR tba_event_key='')");
            $upd->execute([$key,$eventId,$org]);
            if($upd->rowCount()!==1)throw new RuntimeException('Event link changed while discovery was running.');
            neptune_first_discovery_record($pdo,$org,$eventId,'linked','TBA linked automatically; '.$best['checks'].' station matches checked.',$key);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return ['status'=>'linked','key'=>$key,'score'=>$best['score'],'matches_checked'=>$best['checks']];
    }catch(Throwable $e){
        neptune_first_discovery_record($pdo,$org,$eventId,'error',$e->getMessage());
        throw $e;
    }
}
