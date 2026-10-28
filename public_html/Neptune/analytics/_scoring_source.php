<?php
/**
 * Shared AUGUR offense evidence. Real traditional match actions take precedence;
 * human Tag Scouting may supply a missing robot/match point observation.
 * This helper reads event-local organization-scoped official scores, or manual finals only when official is absent.
 * It never creates scouting_actions or treats tags as cycle/phase/defense actions.
 */

/**
 * Shared source resolver for all AUGUR scoring consumers.
 * Treat each robot/match/scout as one estimate, average independent scouts,
 * then normalize the three robot shares only when the entire alliance was
 * observed. Missing robots never implicitly score zero.
 */
function neptune_scoring_tag_rows(array $rows): array {
    $latest=[];
    foreach($rows as $r){
        $team=(int)($r['frc_team_number']??0);
        $match=(int)($r['match_id']??0);
        $observer=(int)($r['user_id']??0);
        $note=(string)($r['note']??'');
        $alliance=(string)($r['alliance']??'');
        $pct=$r['scoring_contribution']??null;
        if($team<1||$match<1||$observer===900000001
            ||str_starts_with($note,'Synthetic Tag Scouting estimate')
            ||!is_numeric($pct)||(float)$pct<0||(float)$pct>100
            ||!in_array($alliance,['Red','Blue'],true)
            ||!is_numeric($r[$alliance==='Red'?'red_score':'blue_score']??null)) continue;
        $score=(float)($alliance==='Red'?$r['red_score']:$r['blue_score']);
        if($score<0) continue;
        $key=$match.':'.$alliance.':'.$team.':'.$observer;
        if(isset($latest[$key])){
            $prev=$latest[$key];
            $cmp=strcmp((string)($r['updated_at']??''),(string)($prev['updated_at']??''));
            if($cmp<0 || ($cmp===0 && (int)($r['id']??0)<=(int)($prev['id']??0)))continue;
        }
        $latest[$key]=$r;
    }
    $alliances=[];
    foreach($latest as $r){
        $match=(int)$r['match_id'];$alliance=(string)$r['alliance'];$team=(int)$r['frc_team_number'];
        $alliances[$match][$alliance][$team][]=$r;
    }
    $out=[];
    foreach($alliances as $match=>$allianceRows){
        foreach($allianceRows as $alliance=>$byTeam){
            $means=[];$scores=[];$expected=3;
            foreach($byTeam as $team=>$group){
                $means[$team]=array_sum(array_map(static fn($r)=>(float)$r['scoring_contribution'],$group))/count($group);
                $first=$group[0];
                $scores[$team]=(float)($alliance==='Red'?$first['red_score']:$first['blue_score']);
                if((int)($first['alliance_team_count']??0)>0)$expected=(int)$first['alliance_team_count'];
            }
            $sum=array_sum($means);
            $complete=count($byTeam)===$expected && $expected>0;
            // Explicit zero shares against a positive final are contradictory.
            // A zero-point alliance may legitimately have three zero shares.
            if($sum<=0 && max($scores)>0)continue;
            $normalized=$complete && $sum>0;
            foreach($byTeam as $team=>$group){
                $first=$group[0];$score=$scores[$team];
                $share=$normalized?$means[$team]/$sum*100.0:$means[$team];
                $individual=array_map(static fn($r)=>(float)$r['scoring_contribution'],$group);
                $squared=0.0;
                foreach($individual as $v)$squared+=($v-$means[$team])**2;
                $stddev=sqrt($squared/count($individual));
                $observers=count($group);
                // Transparent evidence-quality heuristic; not a calibrated probability.
                $agreement=max(0.0,1.0-min(1.0,$stddev/35.0));
                $depth=min(1.0,log($observers+1)/log(5));
                $confidence=round(100.0*(0.15+0.35*$depth+0.30*$agreement+0.20*($complete?1.0:0.0)),1);
                $origin=(string)($first['score_origin']??'official');
                if($origin==='manual')$confidence=round($confidence*0.90,1); // Human-entered final, not independently verified.
                $out[(int)$team][(int)$match]=[
                    'match_id'=>(int)$match,
                    'match_number'=>(int)($first['match_number']??0),
                    'comp_level'=>(string)($first['comp_level']??'qm'),
                    'points'=>$score*$share/100.0,
                    'contribution_pct'=>$share,
                    'raw_contribution_pct'=>$means[$team],
                    'official_alliance_score'=>$origin==='official'?$score:null,
                    'alliance_score'=>$score,
                    'alliance_score_source'=>$origin,
                    'observers'=>$observers,
                    'observer_stddev_pct'=>$stddev,
                    'alliance_complete'=>$complete,
                    'normalized'=>$normalized,
                    'confidence_pct'=>$confidence,
                    'points_source'=>'tag_estimate',
                ];
            }
        }
    }
    return $out;
}

/** Summarize match estimates without weighting matches with more observers more heavily. */
function neptune_scoring_tag_summary(array $byMatch): array {
    if(!$byMatch)return ['estimated_ppm'=>null,'recent_avg'=>null,'p75'=>null,'slope'=>null,'confidence_pct'=>null,'scored_matches'=>0,'observers'=>0,'normalized_matches'=>0];
    $rows=array_values($byMatch);
    usort($rows,static fn($a,$b)=>(int)($a['match_number']??0)<=>(int)($b['match_number']??0) ?: (int)$a['match_id']<=>(int)$b['match_id']);
    $values=array_map(static fn($r)=>(float)$r['points'],$rows);
    $n=count($values);
    $sorted=$values;sort($sorted,SORT_NUMERIC);
    $position=($n-1)*0.75;$low=(int)floor($position);$high=(int)ceil($position);
    $p75=$sorted[$low]+($sorted[$high]-$sorted[$low])*($position-$low);
    $recent=array_slice($values,-min(6,$n));
    $slope=null;$k=count($recent);
    if($k>=2){
        $xMean=($k-1)/2;$yMean=array_sum($recent)/$k;$num=0.0;$den=0.0;
        foreach($recent as $i=>$y){$num+=($i-$xMean)*($y-$yMean);$den+=($i-$xMean)**2;}
        $slope=$den>0?$num/$den:null;
    }
    $meanConfidence=array_sum(array_map(static fn($r)=>(float)($r['confidence_pct']??0),$rows))/$n;
    // More separate matches improve confidence; repeated scouts in one match
    // alone cannot make a one-match estimate appear highly reliable.
    $coverage=min(1.0,$n/5.0);
    return [
        'estimated_ppm'=>array_sum($values)/$n,
        'recent_avg'=>array_sum($recent)/count($recent),
        'p75'=>$p75,'slope'=>$slope,
        'confidence_pct'=>round($meanConfidence*(0.5+0.5*$coverage),1),
        'scored_matches'=>$n,
        'observers'=>array_sum(array_map(static fn($r)=>(int)($r['observers']??0),$rows)),
        'normalized_matches'=>count(array_filter($rows,static fn($r)=>!empty($r['normalized']))),
    ];
}

/**
 * Extend Robot Lookup's event summaries with LOCAL Tag estimates only.
 * Existing shared traditional data is never broadened to other organizations.
 * A missing traditional scoring sample may be filled; a recorded zero wins.
 */
function neptune_scoring_tag_lookup_events(PDO $pdo,int $org,int $team,array $eventSummaries): array {
    if($org<1||$team<1)return $eventSummaries;
    $present=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tag_scouting_match_data'");
    $present->execute();
    if(!(int)$present->fetchColumn())return $eventSummaries;
    // Every existing Tag event must be independently scoped to this organization.
    $q=$pdo->prepare("SELECT DISTINCT e.id AS event_id,e.game_id,g.name AS game_name,g.season_year,
            e.name AS event_name,e.start_date
        FROM tag_scouting_match_data d
        JOIN events e ON e.id=d.event_id AND e.organization_id=d.organization_id
        JOIN matches m ON m.id=d.match_id AND m.event_id=e.id AND m.organization_id=e.organization_id
        JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=d.frc_team_number AND mt.alliance=d.alliance
        JOIN games g ON g.id=e.game_id
        WHERE d.organization_id=? AND d.frc_team_number=?
        ORDER BY g.season_year DESC,e.start_date DESC,e.id DESC");
    $q->execute([$org,$team]);
    $indexed=[];
    foreach($eventSummaries as $i=>$row)$indexed[(int)($row['event_id']??0)]=$i;
    foreach($q->fetchAll() as $event){
        $eventId=(int)$event['event_id'];
        $tagRows=neptune_scoring_tag_match_points($pdo,$org,$eventId)[$team]??[];
        if(!$tagRows)continue;
        // The previous implementation discarded EVERY Tag estimate when even one
        // traditional scoring action existed in the event. Reconcile by match ID.
        $s=$pdo->prepare("SELECT a.match_id,
                    SUM(a.points) AS points,
                    MAX(CASE WHEN (a.action_type='offense' AND a.result IN ('Success','Failure'))
                             OR a.points<>0 THEN 1 ELSE 0 END) AS score_observed
            FROM scouting_actions a
            JOIN matches m ON m.id=a.match_id AND m.event_id=a.event_id
                AND m.organization_id=a.organization_id AND m.run_number=a.match_run_number
            WHERE a.organization_id=? AND a.event_id=? AND a.frc_team_number=?
                AND a.deleted_at IS NULL
            GROUP BY a.match_id");
        $s->execute([$org,$eventId,$team]);
        $observations=[];
        foreach($s->fetchAll() as $row){
            if((int)$row['score_observed']!==1)continue;
            $observations[(int)$row['match_id']]=['points'=>(float)$row['points'],'points_source'=>'traditional'];
        }
        $tagUsed=[];
        foreach($tagRows as $matchId=>$tag){
            if(isset($observations[$matchId]))continue;
            $observations[$matchId]=$tag;
            $tagUsed[$matchId]=$tag;
        }
        if(!$observations)continue;
        $tagSummary=neptune_scoring_tag_summary($tagUsed);
        $total=array_sum(array_map(static fn($r)=>(float)$r['points'],$observations));
        $index=$indexed[$eventId]??null;
        if($index!==null){
            // Preserve real action counts and phase splits from the existing
            // summary; only amend blended event-level scoring totals.
            $eventSummaries[$index]['total_points']=$total;
            $eventSummaries[$index]['match_count']=count($observations);
            $eventSummaries[$index]['tag_estimated_matches']=count($tagUsed);
            $eventSummaries[$index]['tag_confidence_pct']=$tagSummary['confidence_pct'];
        }else{
            $eventSummaries[]=$event+[
                'action_count'=>0,'match_count'=>count($observations),
                'success_count'=>0,'failure_count'=>0,'scoring_events'=>0,
                'total_points'=>$total,
                'auton_points'=>null,'teleop_points'=>null,'endgame_points'=>null,
                'tag_estimated_matches'=>count($tagUsed),
                'tag_confidence_pct'=>$tagSummary['confidence_pct'],
            ];
        }
    }
    usort($eventSummaries,static fn($a,$b)=>
        ((int)($b['season_year']??0)<=>(int)($a['season_year']??0))
        ?:strcmp((string)($b['start_date']??''),(string)($a['start_date']??''))
        ?:((int)($b['event_id']??0)<=>(int)($a['event_id']??0)));
    return $eventSummaries;
}

/** Fetch scored human Tag estimates; resolve each alliance independently. Official finals win, then manual finals. */
function neptune_scoring_tag_match_points(PDO $pdo,int $org,int $eventId,?array $matchIds=null): array {
    if($org<1||$eventId<1) return [];
    if($matchIds!==null){
        $matchIds=array_values(array_unique(array_filter(array_map('intval',$matchIds),static fn(int $id)=>$id>0)));
        if(!$matchIds) return [];
    }
    static $knownTables=[];
    $dbKey=spl_object_id($pdo);
    if(!array_key_exists($dbKey,$knownTables)){
        $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $s->execute(['tag_scouting_match_data']);
        $knownTables[$dbKey]=(int)$s->fetchColumn()===1;
    }
    if(!$knownTables[$dbKey]) return [];
    $matchFilter='';$params=[$org,$eventId];
    if($matchIds!==null){
        $matchFilter=' AND m.id IN ('.implode(',',array_fill(0,count($matchIds),'?')).')';
        $params=array_merge($params,$matchIds);
    }
    $manualPresent=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $manualPresent->execute(['tag_manual_alliance_scores']);
    $hasManual=(int)$manualPresent->fetchColumn()===1;
    $manualJoin=$hasManual?' LEFT JOIN tag_manual_alliance_scores ms ON ms.organization_id=m.organization_id AND ms.event_id=m.event_id AND ms.match_id=m.id ':'';
    $red=$hasManual?'COALESCE(m.red_score,ms.red_score)':'m.red_score';
    $blue=$hasManual?'COALESCE(m.blue_score,ms.blue_score)':'m.blue_score';
    $scoreOrigin=$hasManual?"CASE WHEN (d.alliance='Red' AND m.red_score IS NULL) OR (d.alliance='Blue' AND m.blue_score IS NULL) THEN 'manual' ELSE 'official' END":"'official'";
    $sql="SELECT d.id,d.match_id,d.frc_team_number,d.user_id,d.note,
                 d.scoring_contribution,d.updated_at,d.alliance,
                 m.match_number,m.comp_level,$red AS red_score,$blue AS blue_score,$scoreOrigin AS score_origin,
                 (SELECT COUNT(DISTINCT mt2.frc_team_number) FROM match_teams mt2
                  WHERE mt2.match_id=m.id AND mt2.alliance=d.alliance) AS alliance_team_count
          FROM tag_scouting_match_data d
          JOIN matches m ON m.id=d.match_id AND m.organization_id=d.organization_id AND m.event_id=d.event_id
          $manualJoin
          JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=d.frc_team_number
                              AND mt.alliance=d.alliance
          WHERE d.organization_id=? AND d.event_id=?
            AND d.scoring_contribution IS NOT NULL
            AND d.user_id<>900000001
            AND (d.note IS NULL OR d.note NOT LIKE 'Synthetic Tag Scouting estimate%')
            AND m.comp_level IN ('qm','ef','qf','sf','f')
            AND ((d.alliance='Red' AND $red IS NOT NULL) OR (d.alliance='Blue' AND $blue IS NOT NULL))
            $matchFilter
          ORDER BY d.frc_team_number,m.match_number,d.match_id,d.user_id,d.updated_at,d.id";
    $stmt=$pdo->prepare($sql);
    $stmt->execute($params);
    return neptune_scoring_tag_rows($stmt->fetchAll());
}

/** Replaces only missing robot/matches; an observed zero is never replaced. */
function neptune_scoring_fill_missing(array $traditional,array $tags): array {
    foreach($tags as $team=>$byMatch){
        foreach($byMatch as $matchId=>$tag){
            $found=false;
            if(isset($traditional[$team]))foreach($traditional[$team] as &$r){
                if((int)($r['match_id']??0)!==(int)$matchId)continue;
                $found=true;
                // Only an explicit absence of measured scoring permits replacing
                // a match's zero with a Tag estimate. Defense observations remain.
                if(array_key_exists('score_observed',$r) && !$r['score_observed']){
                    $r['points']=(float)$tag['points'];
                    $r['points_source']='tag_estimate';
                    $r['tag_confidence_pct']=$tag['confidence_pct']??null;
                }
                break;
            }
            unset($r);
            if($found) continue;
            $traditional[$team][]=[
                'match_id'=>(int)$matchId,
                'match_number'=>(int)$tag['match_number'],
                'points'=>(float)$tag['points'],
                'defense'=>0,'attempts'=>0,'successes'=>0,
                'points_source'=>'tag_estimate',
                'tag_confidence_pct'=>$tag['confidence_pct']??null,
            ];
        }
    }
    foreach($traditional as &$rows){
        usort($rows,static fn(array $a,array $b)=>
            ((int)($a['match_number']??0)<=>(int)($b['match_number']??0)) ?:
            ((int)($a['match_id']??0)<=>(int)($b['match_id']??0))
        );
    }
    unset($rows);
    return $traditional;
}
