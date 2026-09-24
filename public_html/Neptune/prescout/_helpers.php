<?php
function neptune_prescout_rebuilt_2026_questions(): array {
    return [
        ['group'=>'2026 REBUILT Robot Questions','code'=>'auton_description','label'=>'Auto','type'=>'textarea','required'=>false,'help'=>'Auto path, starting side, sweeps, trench/bump use, midline behavior, and expected output.'],
        ['group'=>'2026 REBUILT Robot Questions','code'=>'trench_capable','label'=>'Trench','type'=>'yes_no','required'=>false,'help'=>'Can the robot reliably travel through the trench?'],
        ['group'=>'2026 REBUILT Robot Questions','code'=>'scoring_capacity','label'=>'Hopper Size','type'=>'text','required'=>false],
        ['group'=>'2026 REBUILT Robot Questions','code'=>'scoring_mechanism','label'=>'Shooter','type'=>'text','required'=>false,'help'=>'Examples: Drum, Turret, 2 Static.'],
        ['group'=>'2026 REBUILT Robot Questions','code'=>'endgame_capability','label'=>'Climb','type'=>'text','required'=>false],
        ['group'=>'2026 REBUILT Robot Questions','code'=>'scoring_throughput','label'=>'Throughput','type'=>'text','required'=>false,'help'=>'Reported scoring rate, such as 20 Bps.'],
        ['group'=>'2026 REBUILT Robot Questions','code'=>'drive_notes','label'=>'Drive Notes','type'=>'textarea','required'=>false],
        ['group'=>'2026 REBUILT Robot Questions','code'=>'defense_notes','label'=>'Defence / CounterDefence (Ramming Speed, Behavior, etc)','type'=>'textarea','required'=>false],
        ['group'=>'2026 REBUILT Robot Questions','code'=>'robot_archetype','label'=>'Archetype','type'=>'text','required'=>false],
    ];
}

function neptune_prescout_default_questions(?int $seasonYear=null, string $gameName=''): array {
    if($seasonYear===2026 && stripos($gameName,'rebuilt')!==false) return neptune_prescout_rebuilt_2026_questions();
    return [
        ['group'=>'Robot Questions','code'=>'auton_description','label'=>'Auto','type'=>'textarea','required'=>false],
        ['group'=>'Robot Questions','code'=>'drive_notes','label'=>'Drive Notes','type'=>'textarea','required'=>false],
        ['group'=>'Robot Questions','code'=>'defense_notes','label'=>'Defence / CounterDefence','type'=>'textarea','required'=>false],
        ['group'=>'Robot Questions','code'=>'robot_archetype','label'=>'Archetype','type'=>'text','required'=>false],
    ];
}

function neptune_prescout_game_questions(array|string|null $json): array {
    if(is_string($json)) $json=json_decode($json,true)?:[];
    if(!is_array($json)) return [];
    $q=$json['questions']??[];
    return is_array($q)?array_values(array_filter($q,'is_array')):[];
}

/**
 * Pre-scout questions are game-defined. There is intentionally no large
 * generic questionnaire layered on top of the game form.
 */
function neptune_prescout_all_questions(array|string|null $gameConfig, ?int $seasonYear=null, string $gameName=''): array {
    $configured=neptune_prescout_game_questions($gameConfig);
    return $configured ?: neptune_prescout_default_questions($seasonYear,$gameName);
}

function neptune_prescout_render_field(array $q, mixed $value, bool $inherited=false): void {
    $code=(string)($q['code']??'');
    $label=(string)($q['label']??$code);
    $type=(string)($q['type']??'text');
    $required=!empty($q['required']);
    $options=is_array($q['options']??null)?$q['options']:[];
    $name='pre['.$code.']';
    echo '<div class="pit-field prescout-field'.($inherited?' prescout-inherited':'').'">';
    echo '<label for="pre_'.e($code).'">'.e($label).($required?' <span class="required-mark">*</span>':'').($inherited?' <span class="prescout-known-badge">KNOWN</span>':'').'</label>';
    if($type==='yes_no'){
        echo '<select id="pre_'.e($code).'" name="'.e($name).'"'.($required?' required':'').'><option value="">— Select —</option>';
        foreach(['Yes','No','Unknown'] as $o) echo '<option value="'.e($o).'"'.((string)$value===$o?' selected':'').'>'.e($o).'</option>';
        echo '</select>';
    } elseif($type==='select'){
        echo '<select id="pre_'.e($code).'" name="'.e($name).'"'.($required?' required':'').'><option value="">— Select —</option>';
        foreach($options as $o) echo '<option value="'.e($o).'"'.((string)$value===(string)$o?' selected':'').'>'.e($o).'</option>';
        echo '</select>';
    } elseif($type==='multiselect'){
        $selected=is_array($value)?$value:[];
        echo '<div class="choice-grid">';
        foreach($options as $i=>$o){$id='pre_'.preg_replace('/[^a-z0-9_]+/i','_',$code).'_'.$i;echo '<label class="choice-chip" for="'.e($id).'"><input id="'.e($id).'" type="checkbox" name="'.e($name).'[]" value="'.e($o).'"'.(in_array($o,$selected,true)?' checked':'').'> <span>'.e($o).'</span></label>';}
        echo '</div>';
    } elseif($type==='number'){
        $min=isset($q['min'])?' min="'.e($q['min']).'"':'';$max=isset($q['max'])?' max="'.e($q['max']).'"':'';$step=isset($q['step'])?' step="'.e($q['step']).'"':'';
        echo '<input id="pre_'.e($code).'" type="number" name="'.e($name).'" value="'.e((string)$value).'"'.$min.$max.$step.($required?' required':'').'>';
    } elseif($type==='textarea'){
        echo '<textarea id="pre_'.e($code).'" name="'.e($name).'" rows="3"'.($required?' required':'').'>'.e((string)$value).'</textarea>';
    } else {
        echo '<input id="pre_'.e($code).'" name="'.e($name).'" value="'.e((string)$value).'"'.($required?' required':'').'>';
    }
    if(!empty($q['help'])) echo '<div class="field-help">'.e($q['help']).'</div>';
    echo '</div>';
}

function neptune_prescout_nonempty(mixed $v): bool {
    if(is_array($v)) return count(array_filter($v,fn($x)=>trim((string)$x)!==''))>0;
    return trim((string)$v)!=='';
}

function neptune_prescout_path(array $row, string $path, mixed $default=null): mixed {
    $cur=$row;
    foreach(explode('.',$path) as $part){
        if(!is_array($cur)||!array_key_exists($part,$cur)) return $default;
        $cur=$cur[$part];
    }
    return $cur;
}

function neptune_prescout_first_path(array $row, array $paths, mixed $default=null): mixed {
    foreach($paths as $path){
        $v=neptune_prescout_path($row,$path,null);
        if($v!==null && $v!=='') return $v;
    }
    return $default;
}

function neptune_prescout_num(mixed $value, int $decimals=1): string {
    if($value===null||$value===''||!is_numeric($value)) return '—';
    $n=(float)$value;
    if(abs($n-round($n))<0.00001) return (string)(int)round($n);
    return number_format($n,$decimals,'.','');
}

/**
 * Public EPA for Pre-Scouting is read from Neptune's local AUGUR EPA tables.
 * These helpers intentionally do not call Statbotics or TBA, so EPA remains
 * available when an event has no Internet connection.
 */
function neptune_prescout_public_epa_ready(PDO $pdo): bool {
    static $ready=null;
    if($ready!==null) return $ready;
    try{
        $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='augur_epa_season_ratings'");
        $s->execute();
        return $ready=((int)$s->fetchColumn()>0);
    }catch(Throwable $e){
        return $ready=false;
    }
}

function neptune_prescout_public_epa_summary(array $row): array {
    $wins=is_numeric($row['wins']??null)?(int)$row['wins']:null;
    $losses=is_numeric($row['losses']??null)?(int)$row['losses']:null;
    $ties=is_numeric($row['ties']??null)?(int)$row['ties']:null;
    $record='—';
    $winrate=null;
    if($wins!==null&&$losses!==null){
        $record=$wins.'-'.$losses;
        if($ties!==null&&$ties>0)$record.='-'.$ties;
        $played=$wins+$losses+($ties??0);
        if($played>0)$winrate=($wins+0.5*($ties??0))/$played;
    }
    return [
        'record'=>$record,
        'winrate'=>$winrate,
        'epa'=>isset($row['rating'])&&is_numeric($row['rating'])?(float)$row['rating']:null,
        'auto'=>isset($row['auto_rating'])&&is_numeric($row['auto_rating'])?(float)$row['auto_rating']:null,
        'teleop'=>isset($row['teleop_rating'])&&is_numeric($row['teleop_rating'])?(float)$row['teleop_rating']:null,
        'endgame'=>isset($row['endgame_rating'])&&is_numeric($row['endgame_rating'])?(float)$row['endgame_rating']:null,
        'rank'=>isset($row['epa_rank'])&&is_numeric($row['epa_rank'])?(int)$row['epa_rank']:null,
        'confidence'=>isset($row['confidence'])&&is_numeric($row['confidence'])?(float)$row['confidence']:null,
        'sigma'=>isset($row['sigma'])&&is_numeric($row['sigma'])?(float)$row['sigma']:null,
        'events_played'=>isset($row['events_played'])?(int)$row['events_played']:null,
        'matches_played'=>isset($row['matches_played'])?(int)$row['matches_played']:null,
        'last_event_key'=>(string)($row['last_event_key']??''),
        'model_version'=>(string)($row['model_version']??''),
        'source'=>'neptune_db',
    ];
}

function neptune_prescout_public_epa_map(PDO $pdo,int $seasonYear,array $teams): array {
    if($seasonYear<1992||!neptune_prescout_public_epa_ready($pdo))return [];
    $teams=array_values(array_unique(array_filter(array_map('intval',$teams),static fn($n)=>$n>0)));
    if(!$teams)return [];
    $ph=implode(',',array_fill(0,count($teams),'?'));
    $sql="SELECT r.*,
        1+(SELECT COUNT(*) FROM augur_epa_season_ratings rr WHERE rr.season_year=r.season_year AND rr.rating>r.rating) epa_rank
        FROM augur_epa_season_ratings r
        WHERE r.season_year=? AND r.frc_team_number IN ($ph)";
    try{
        $s=$pdo->prepare($sql);
        $s->execute(array_merge([$seasonYear],$teams));
        $out=[];
        foreach($s->fetchAll() as $row){
            $team=(int)($row['frc_team_number']??0);
            if($team>0)$out[$team]=neptune_prescout_public_epa_summary($row);
        }
        return $out;
    }catch(Throwable $e){
        return [];
    }
}

function neptune_prescout_public_epa_team(PDO $pdo,int $seasonYear,int $team): array {
    $map=neptune_prescout_public_epa_map($pdo,$seasonYear,[$team]);
    return $map[$team]??[];
}



/** Stored traditional OPR calculated by AUGUR from its local archive samples. */
function neptune_prescout_opr_table_ready(PDO $pdo): bool {
    static $ready=null;
    if($ready!==null)return $ready;
    try{
        $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='augur_opr_event_ratings'");
        $s->execute();
        return $ready=((int)$s->fetchColumn()>0);
    }catch(Throwable $e){
        return $ready=false;
    }
}

/**
 * Read event OPR from Neptune's persisted AUGUR OPR table.
 *
 * OPR is event-specific, so the season summary shown by Pre-Scouting is a
 * qualification-match-weighted average of the team's prior event OPR values.
 * That is intentionally labeled "Season Avg OPR" rather than pretending OPR
 * itself is a single cross-event regression.
 */
function neptune_prescout_saved_opr_context(PDO $pdo,int $seasonYear,string $currentEventKey,?string $currentStart,array $teamFilter=[]): array {
    if($seasonYear<1992 || !neptune_prescout_opr_table_ready($pdo))return ['teams'=>[],'events'=>[],'alliance_rows'=>0,'source'=>'none'];
    $teams=array_values(array_unique(array_filter(array_map('intval',$teamFilter),static fn($n)=>$n>0)));
    if(!$teams)return ['teams'=>[],'events'=>[],'alliance_rows'=>0,'source'=>'saved_opr'];

    $ph=implode(',',array_fill(0,count($teams),'?'));
    $where="r.season_year=? AND r.frc_team_number IN ($ph)";
    $args=array_merge([$seasonYear],$teams);
    $currentEventKey=trim($currentEventKey);
    $currentStart=trim((string)$currentStart);
    if($currentStart==='' && $currentEventKey!==''){
        try{
            $d=$pdo->prepare("SELECT COALESCE(start_date,end_date) FROM augur_epa_archive_events WHERE tba_event_key=? LIMIT 1");
            $d->execute([$currentEventKey]);
            $currentStart=trim((string)($d->fetchColumn()?:''));
        }catch(Throwable $e){}
    }
    if($currentEventKey!==''){$where.=' AND r.tba_event_key<>?';$args[]=$currentEventKey;}
    if($currentStart!==''){$where.=" AND COALESCE(e.start_date,e.end_date,'9999-12-31')<?";$args[]=$currentStart;}

    $sql="SELECT r.tba_event_key,r.frc_team_number,r.event_opr,r.matches_played,r.alliance_rows,
            e.name event_name,e.start_date,e.end_date
          FROM augur_opr_event_ratings r
          JOIN augur_epa_archive_events e ON e.tba_event_key=r.tba_event_key
          WHERE $where
          ORDER BY r.frc_team_number,COALESCE(e.start_date,e.end_date),r.tba_event_key";
    try{$q=$pdo->prepare($sql);$q->execute($args);$rows=$q->fetchAll();}catch(Throwable $e){return ['teams'=>[],'events'=>[],'alliance_rows'=>0,'source'=>'error'];}

    $byTeam=[];$eventMeta=[];$allianceRows=0;
    foreach($rows as $r){
        $team=(int)$r['frc_team_number'];
        $key=(string)$r['tba_event_key'];
        $row=[
            'event_key'=>$key,
            'event_name'=>(string)($r['event_name']??$key),
            'start_date'=>$r['start_date']??($r['end_date']??null),
            'opr'=>is_numeric($r['event_opr']??null)?(float)$r['event_opr']:null,
            'matches_used'=>(int)($r['matches_played']??0),
        ];
        $byTeam[$team][]=$row;
        $eventMeta[$key]=['event_key'=>$key,'event_name'=>$row['event_name'],'start_date'=>$row['start_date']];
        $allianceRows=max($allianceRows,(int)($r['alliance_rows']??0));
    }

    $out=[];
    foreach($teams as $team){
        $history=$byTeam[$team]??[];
        $latest=$history?end($history):null;
        $previous=count($history)>1?$history[count($history)-2]:null;
        $weighted=0.0;$weight=0;$matches=0;
        foreach($history as $h){
            if(!is_numeric($h['opr']??null))continue;
            $w=max(1,(int)($h['matches_used']??0));
            $weighted+=(float)$h['opr']*$w;$weight+=$w;$matches+=(int)($h['matches_used']??0);
        }
        $avg=$weight>0?$weighted/$weight:null;
        $out[$team]=[
            'events'=>$history,
            'latest_event'=>$latest,
            'previous_event'=>$previous,
            'latest_event_opr'=>is_array($latest)?($latest['opr']??null):null,
            'previous_event_opr'=>is_array($previous)?($previous['opr']??null):null,
            'season_avg_opr'=>$avg,
            // Compatibility aliases for older Pre-Scout callers.
            'opr1'=>is_array($latest)?($latest['opr']??null):null,
            'opr2'=>is_array($previous)?($previous['opr']??null):null,
            'season_opr'=>$avg,
            'matches_used'=>$matches,
            'source'=>'augur_opr_table',
        ];
    }
    return ['teams'=>$out,'events'=>array_values($eventMeta),'alliance_rows'=>$allianceRows,'source'=>'augur_opr_table'];
}

/**
 * Solve traditional FRC OPR from local qualification scores.
 *
 * Each alliance contributes one equation:
 *     team_a + team_b + team_c = alliance_score
 *
 * Neptune builds the normal equations and solves them with Gaussian
 * elimination. Completed events are normally full-rank; if an incomplete
 * schedule is singular, a tiny ridge is used only as a fallback so the page
 * can still show a provisional value without an external service.
 */
function neptune_prescout_opr_solve(array $rows): array {
    $clean=[];$teamSet=[];$teamMatches=[];
    foreach($rows as $row){
        $teams=array_values(array_unique(array_filter(array_map('intval',(array)($row['teams']??[])),static fn($n)=>$n>0)));
        $score=$row['score']??null;
        // Qualification alliances should contain exactly three robots. Skip
        // incomplete imported rows rather than assigning a missing robot's
        // contribution to its partners.
        if(count($teams)!==3||!is_numeric($score))continue;
        $clean[]=['teams'=>$teams,'score'=>(float)$score];
        foreach($teams as $team){$teamSet[$team]=true;$teamMatches[$team]=($teamMatches[$team]??0)+1;}
    }
    if(!$clean||count($teamSet)<3)return ['ratings'=>[],'matches'=>[],'alliance_rows'=>0];

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
        // Forward elimination with partial pivoting.
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
        // Back substitution.
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
    if($x===null)$x=$solve($ata,$atb,1e-8);
    if($x===null)return ['ratings'=>[],'matches'=>$teamMatches,'alliance_rows'=>count($clean)];
    $ratings=[];foreach($teams as $i=>$team)$ratings[$team]=(float)$x[$i];
    return ['ratings'=>$ratings,'matches'=>$teamMatches,'alliance_rows'=>count($clean)];
}

/**
 * Calculate prior-event OPR and season-to-date OPR entirely from Neptune's
 * local matches + match_teams tables. No TBA/Statbotics request is required.
 *
 * This is the fallback when the persisted AUGUR OPR table has not been built.
 * The two event values are the most recent and previous prior events. The
 * season summary is a match-weighted average of prior event OPR values.
 */
function neptune_prescout_local_opr_context(PDO $pdo,int $organizationId,int $gameId,int $currentEventId,?string $currentStart,array $teamFilter=[]): array {
    $currentStart=trim((string)$currentStart);
    try{
        if($currentStart!==''){
            $s=$pdo->prepare("SELECT id,name,tba_event_key,start_date FROM events WHERE organization_id=? AND game_id=? AND id<>? AND start_date IS NOT NULL AND start_date<? ORDER BY start_date,id");
            $s->execute([$organizationId,$gameId,$currentEventId,$currentStart]);
        }else{
            // Most imported events are chronological by id. This fallback is
            // only used when the selected event has no start date.
            $s=$pdo->prepare("SELECT id,name,tba_event_key,start_date FROM events WHERE organization_id=? AND game_id=? AND id<>? AND id<? ORDER BY COALESCE(start_date,'9999-12-31'),id");
            $s->execute([$organizationId,$gameId,$currentEventId,$currentEventId]);
        }
        $events=$s->fetchAll();
        if(!$events)return ['teams'=>[],'events'=>[],'alliance_rows'=>0];
        $eventIds=array_values(array_map(static fn($e)=>(int)$e['id'],$events));
        $ph=implode(',',array_fill(0,count($eventIds),'?'));
        $sql="SELECT m.id match_id,m.event_id,m.red_score,m.blue_score,mt.frc_team_number,mt.alliance,mt.station
              FROM matches m JOIN match_teams mt ON mt.match_id=m.id
              WHERE m.organization_id=? AND m.event_id IN ($ph) AND m.comp_level='qm'
                AND m.red_score IS NOT NULL AND m.blue_score IS NOT NULL
                AND m.red_score>=0 AND m.blue_score>=0
              ORDER BY m.event_id,m.id,mt.alliance,mt.station";
        $q=$pdo->prepare($sql);$q->execute(array_merge([$organizationId],$eventIds));
        $raw=$q->fetchAll();
    }catch(Throwable $e){
        return ['teams'=>[],'events'=>[],'alliance_rows'=>0];
    }

    $matches=[];
    foreach($raw as $r){
        $eventId=(int)$r['event_id'];$matchId=(int)$r['match_id'];$alliance=(string)$r['alliance'];
        if(!isset($matches[$eventId][$matchId]))$matches[$eventId][$matchId]=['Red'=>[],'Blue'=>[],'red_score'=>$r['red_score'],'blue_score'=>$r['blue_score']];
        $matches[$eventId][$matchId][$alliance][]=(int)$r['frc_team_number'];
    }

    $eventMaps=[];$eventMeta=[];$totalAllianceRows=0;
    foreach($events as $ev){
        $eid=(int)$ev['id'];$rows=[];
        foreach($matches[$eid]??[] as $m){
            $red=array_values(array_unique(array_map('intval',$m['Red']??[])));
            $blue=array_values(array_unique(array_map('intval',$m['Blue']??[])));
            if(count($red)===3&&is_numeric($m['red_score']))$rows[]=['teams'=>$red,'score'=>(float)$m['red_score']];
            if(count($blue)===3&&is_numeric($m['blue_score']))$rows[]=['teams'=>$blue,'score'=>(float)$m['blue_score']];
        }
        $solved=neptune_prescout_opr_solve($rows);
        $eventMaps[$eid]=$solved;
        $totalAllianceRows+=(int)($solved['alliance_rows']??0);
        $eventMeta[$eid]=[
            'event_id'=>$eid,
            'event_key'=>(string)($ev['tba_event_key']??''),
            'event_name'=>(string)($ev['name']??('Event '.$eid)),
            'start_date'=>$ev['start_date']??null,
        ];
    }
    $filter=array_values(array_unique(array_filter(array_map('intval',$teamFilter),static fn($n)=>$n>0)));
    if(!$filter){
        foreach($eventMaps as $map)foreach(array_keys((array)($map['ratings']??[])) as $team)$filter[]=(int)$team;
        $filter=array_values(array_unique($filter));
    }

    $teams=[];
    foreach($filter as $team){
        $history=[];
        foreach($events as $ev){
            $eid=(int)$ev['id'];$rating=$eventMaps[$eid]['ratings'][$team]??null;
            if(!is_numeric($rating))continue;
            $meta=$eventMeta[$eid];
            $meta['opr']=(float)$rating;
            $meta['matches_used']=(int)($eventMaps[$eid]['matches'][$team]??0);
            $history[]=$meta;
        }
        $latest=$history?end($history):null;
        $previous=count($history)>1?$history[count($history)-2]:null;
        $weighted=0.0;$weight=0;$matchesUsed=0;
        foreach($history as $h){
            if(!is_numeric($h['opr']??null))continue;
            $w=max(1,(int)($h['matches_used']??0));
            $weighted+=(float)$h['opr']*$w;$weight+=$w;$matchesUsed+=(int)($h['matches_used']??0);
        }
        $seasonAvg=$weight>0?$weighted/$weight:null;
        $teams[$team]=[
            'events'=>$history,
            'latest_event'=>$latest,
            'previous_event'=>$previous,
            'latest_event_opr'=>is_array($latest)?($latest['opr']??null):null,
            'previous_event_opr'=>is_array($previous)?($previous['opr']??null):null,
            'season_avg_opr'=>$seasonAvg,
            'opr1'=>is_array($latest)?($latest['opr']??null):null,
            'opr2'=>is_array($previous)?($previous['opr']??null):null,
            'season_opr'=>$seasonAvg,
            'matches_used'=>$matchesUsed,
            'source'=>'local_opr_fallback',
        ];
    }
    return ['teams'=>$teams,'events'=>$eventMeta,'alliance_rows'=>$totalAllianceRows];
}


/** Prefer the persisted AUGUR OPR table; fill gaps from local event tables. */
function neptune_prescout_opr_context(PDO $pdo,int $organizationId,int $gameId,int $currentEventId,int $seasonYear,string $currentEventKey,?string $currentStart,array $teamFilter=[]): array {
    $saved=neptune_prescout_saved_opr_context($pdo,$seasonYear,$currentEventKey,$currentStart,$teamFilter);
    $missing=[];
    foreach($teamFilter as $team){$team=(int)$team;if($team>0 && empty($saved['teams'][$team]['events']))$missing[]=$team;}
    if(!$missing)return $saved;

    $fallback=neptune_prescout_local_opr_context($pdo,$organizationId,$gameId,$currentEventId,$currentStart,$missing);
    foreach($fallback['teams']??[] as $team=>$row)$saved['teams'][(int)$team]=$row;
    if(empty($saved['source'])||$saved['source']==='none')$saved['source']='local_opr_fallback';
    elseif($fallback['teams']??[])$saved['source'].='+fallback';
    return $saved;
}

/** Normalize a Statbotics team-year record across API minor-version changes. */
function neptune_prescout_statbotics_summary(array $row): array {
    $wins=neptune_prescout_first_path($row,['record.total.wins','record.wins']);
    $losses=neptune_prescout_first_path($row,['record.total.losses','record.losses']);
    $ties=neptune_prescout_first_path($row,['record.total.ties','record.ties']);
    $winrate=neptune_prescout_first_path($row,['record.total.winrate','record.winrate']);
    $record='—';
    if(is_numeric($wins)&&is_numeric($losses)){
        $record=(int)$wins.'-'.(int)$losses;
        if(is_numeric($ties)&&(int)$ties>0)$record.='-'.(int)$ties;
    } elseif(is_numeric($winrate)) {
        $record=round((float)$winrate*100).'%';
    }

    $epa=neptune_prescout_first_path($row,['epa.breakdown.total_points','epa.total_points.mean','epa_end','epa.mean']);
    $auto=neptune_prescout_first_path($row,['epa.breakdown.auto_points','breakdown.auto_points','auto_epa']);
    $teleop=neptune_prescout_first_path($row,['epa.breakdown.teleop_points','breakdown.teleop_points','teleop_epa']);
    $endgame=neptune_prescout_first_path($row,['epa.breakdown.endgame_points','breakdown.endgame_points','endgame_epa']);
    $rank=neptune_prescout_first_path($row,['epa.ranks.total.rank','epa.ranks.total_points','epa.rank.total_points','epa_rank','rank']);

    return [
        'record'=>$record,
        'winrate'=>is_numeric($winrate)?(float)$winrate:null,
        'epa'=>$epa,
        'auto'=>$auto,
        'teleop'=>$teleop,
        'endgame'=>$endgame,
        'rank'=>$rank,
    ];
}

function neptune_prescout_team_number_from_statbotics(array $row): int {
    return (int)neptune_prescout_first_path($row,['team','team_number','teamNumber'],0);
}

function neptune_prescout_tba_status_text(array $status): string {
    foreach(['overall_status_str','playoff_status_str','qual_status_str'] as $k){
        if(!empty($status[$k])) return trim(strip_tags((string)$status[$k]));
    }
    $a=$status['alliance']??null;
    if(is_array($a)&&isset($a['number'])){
        $text='Alliance '.(int)$a['number'];
        if(!empty($a['pick']))$text.=' · Pick '.(int)$a['pick'];
        return $text;
    }
    return '';
}

function neptune_prescout_profile_stats(array $profile): array {
    $raw=json_decode((string)($profile['tba_json']??''),true);
    if(!is_array($raw))return [];
    return is_array($raw['prescout_intel']??null)?$raw['prescout_intel']:[];
}

/** Map earlier Neptune/pit field names into the compact spreadsheet-style form. */
function neptune_prescout_normalize_robot_answers(array $data): array {
    $out=$data;
    $aliases=[
        'auton_description'=>['auton_description','auto','auto_notes'],
        'trench_capable'=>['trench_capable','trench'],
        'scoring_capacity'=>['scoring_capacity','hopper_size'],
        'scoring_mechanism'=>['scoring_mechanism','shooter','shooter_type'],
        'endgame_capability'=>['endgame_capability','climb','climb_detail'],
        'scoring_throughput'=>['scoring_throughput','throughput'],
        'drive_notes'=>['drive_notes'],
        'defense_notes'=>['defense_notes','defense_counterdefense'],
        'robot_archetype'=>['robot_archetype','archetype'],
    ];
    foreach($aliases as $target=>$sources){
        if(neptune_prescout_nonempty($out[$target]??''))continue;
        foreach($sources as $src){
            if(neptune_prescout_nonempty($data[$src]??'')){$out[$target]=$data[$src];break;}
        }
    }
    return $out;
}
