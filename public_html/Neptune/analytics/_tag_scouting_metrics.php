<?php
declare(strict_types=1);

/**
 * Neptune Tag Scouting derived analytics.
 *
 * Raw observations remain in tag_scouting_match_data.
 * This helper turns those observations into:
 *   - one consensus/estimated-value row per robot/match
 *   - one running AUGUR Tag rating row per robot/event
 *
 * The offensive blend intentionally reuses AUGUR's existing public-EPA +
 * current-event scouting blend. Traditional and Tag Scouting remain separate
 * data sources; this helper never reads scouting_actions for its point estimate.
 */

require_once __DIR__ . '/_augur_prediction_model.php';

const TAG_SCOUTING_ANALYTICS_MODEL_VERSION = 'tag-analytics-v1';
const TAG_SCOUTING_SYNTHETIC_USER_ID = 900000001;

function tag_scouting_analytics_table_exists(PDO $pdo, string $table): bool {
    $q=$pdo->prepare("SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
}

function tag_scouting_analytics_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tag_scouting_match_metrics (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organization_id BIGINT UNSIGNED NOT NULL,
        event_id BIGINT UNSIGNED NOT NULL,
        match_id BIGINT UNSIGNED NOT NULL,
        tba_match_key VARCHAR(64) NULL,
        comp_level VARCHAR(8) NOT NULL DEFAULT 'qm',
        match_number SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        frc_team_number INT UNSIGNED NOT NULL,
        alliance ENUM('Red','Blue') NOT NULL,
        station TINYINT UNSIGNED NULL,

        observer_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        synthetic_observer_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        score_estimate_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,

        raw_share_avg DECIMAL(8,3) NULL,
        raw_share_median DECIMAL(8,3) NULL,
        share_stddev DECIMAL(8,3) NULL,
        alliance_score_robot_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        alliance_raw_share_total DECIMAL(8,3) NULL,
        normalized_share DECIMAL(8,3) NULL,
        effective_share DECIMAL(8,3) NULL,

        official_score DECIMAL(10,3) NULL,
        modeled_score DECIMAL(10,3) NULL,
        estimated_official_points DECIMAL(10,3) NULL,
        estimated_modeled_points DECIMAL(10,3) NULL,
        estimated_points DECIMAL(10,3) NULL,
        point_source ENUM('modeled','official','none') NOT NULL DEFAULT 'none',

        tags_consensus_json LONGTEXT NOT NULL,
        tag_weights_consensus_json LONGTEXT NULL,

        defense_status ENUM('Unknown','None','Possible','Verified') NOT NULL DEFAULT 'Unknown',
        defense_support_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        no_defense_support_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        defense_strength DECIMAL(6,3) NULL,
        raw_depa DECIMAL(12,4) NULL,

        confidence_score DECIMAL(6,2) NOT NULL DEFAULT 0,
        latest_observation_at DATETIME NULL,
        model_version VARCHAR(80) NOT NULL,
        calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        UNIQUE KEY uq_tag_metric_robot_match (organization_id,match_id,frc_team_number),
        KEY idx_tag_metric_event_team (organization_id,event_id,frc_team_number),
        KEY idx_tag_metric_match (organization_id,match_id),
        KEY idx_tag_metric_depa (organization_id,event_id,defense_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS augur_tag_event_ratings (
        organization_id BIGINT UNSIGNED NOT NULL,
        event_id BIGINT UNSIGNED NOT NULL,
        tba_event_key VARCHAR(40) NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        frc_team_number INT UNSIGNED NOT NULL,

        matches_with_tag_data SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        matches_with_score_share SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        tag_points_avg DECIMAL(12,4) NULL,
        tag_points_recent DECIMAL(12,4) NULL,
        tag_points_high DECIMAL(12,4) NULL,
        tag_points_trend DECIMAL(12,4) NULL,

        public_epa DECIMAL(12,4) NULL,
        neptune_epa DECIMAL(12,4) NULL,

        public_depa DECIMAL(12,4) NULL,
        tag_defense_depa DECIMAL(12,4) NULL,
        possible_defense_depa DECIMAL(12,4) NULL,
        nondefense_suppression DECIMAL(12,4) NULL,
        neptune_depa DECIMAL(12,4) NULL,

        verified_defense_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        possible_defense_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        no_defense_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        unknown_defense_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,

        offense_confidence DECIMAL(6,2) NOT NULL DEFAULT 0,
        defense_confidence DECIMAL(6,2) NOT NULL DEFAULT 0,

        model_version VARCHAR(80) NOT NULL,
        calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

        PRIMARY KEY (organization_id,event_id,frc_team_number),
        KEY idx_augur_tag_event_epa (organization_id,event_id,neptune_epa),
        KEY idx_augur_tag_event_depa (organization_id,event_id,neptune_depa),
        KEY idx_augur_tag_year_team (organization_id,season_year,frc_team_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tag_scouting_analytics_mean(array $values): ?float {
    $x=[];
    foreach($values as $v) if(is_numeric($v)) $x[]=(float)$v;
    return $x ? array_sum($x)/count($x) : null;
}

function tag_scouting_analytics_median(array $values): ?float {
    $x=[];
    foreach($values as $v) if(is_numeric($v)) $x[]=(float)$v;
    if(!$x) return null;
    sort($x,SORT_NUMERIC);
    $n=count($x);$mid=intdiv($n,2);
    return $n%2 ? (float)$x[$mid] : ((float)$x[$mid-1]+(float)$x[$mid])/2.0;
}

function tag_scouting_analytics_stddev(array $values): ?float {
    $x=[];
    foreach($values as $v) if(is_numeric($v)) $x[]=(float)$v;
    if(!$x) return null;
    if(count($x)<2) return 0.0;
    $m=array_sum($x)/count($x);$sum=0.0;
    foreach($x as $v){$d=$v-$m;$sum+=$d*$d;}
    return sqrt($sum/count($x));
}

function tag_scouting_analytics_event(PDO $pdo,int $org,int $eventId): ?array {
    $s=$pdo->prepare("SELECT e.*,g.season_year,g.name game_name
        FROM events e JOIN games g ON g.id=e.game_id
        WHERE e.id=? AND e.organization_id=? LIMIT 1");
    $s->execute([$eventId,$org]);
    return $s->fetch()?:null;
}

function tag_scouting_analytics_settings(PDO $pdo,int $org,int $eventId): array {
    $settings=augur_prediction_settings();
    try{
        if(function_exists('alliance_schema_ready') && alliance_schema_ready($pdo)){
            $workspace=alliance_load_workspace($pdo,$org,$eventId,false);
            $settings=augur_prediction_settings($workspace['workspace']['settings']['matchup_model']??[]);
        }
    }catch(Throwable $e){}
    return $settings;
}

function tag_scouting_analytics_public_epa(PDO $pdo,string $eventKey,int $team): ?float {
    if($eventKey==='' || !tag_scouting_analytics_table_exists($pdo,'augur_epa_event_ratings')) return null;
    $s=$pdo->prepare("SELECT rating FROM augur_epa_event_ratings
        WHERE tba_event_key=? AND frc_team_number=? LIMIT 1");
    $s->execute([$eventKey,$team]);
    $v=$s->fetchColumn();
    return is_numeric($v)?(float)$v:null;
}

function tag_scouting_analytics_raw_depa(
    PDO $pdo,int $org,int $eventId,int $matchId,int $team,string $eventKey,string $matchKey,string $alliance
): ?float {
    if(tag_scouting_analytics_table_exists($pdo,'augur_depa_team_match_evidence')){
        $s=$pdo->prepare("SELECT raw_alliance_suppression
            FROM augur_depa_team_match_evidence
            WHERE organization_id=? AND event_id=? AND match_id=? AND frc_team_number=?
            LIMIT 1");
        $s->execute([$org,$eventId,$matchId,$team]);
        $v=$s->fetchColumn();
        if(is_numeric($v)) return (float)$v;
    }
    if($eventKey!=='' && $matchKey!=='' && tag_scouting_analytics_table_exists($pdo,'augur_depa_match_samples')){
        $s=$pdo->prepare("SELECT raw_suppression FROM augur_depa_match_samples
            WHERE tba_event_key=? AND tba_match_key=? AND defending_alliance=? LIMIT 1");
        $s->execute([$eventKey,$matchKey,$alliance]);
        $v=$s->fetchColumn();
        if(is_numeric($v)) return (float)$v;
    }
    return null;
}

function tag_scouting_analytics_match_scores(PDO $pdo,array $match): array {
    $out=[
        'Red'=>['official'=>$match['red_score']===null?null:(float)$match['red_score'],'modeled'=>null],
        'Blue'=>['official'=>$match['blue_score']===null?null:(float)$match['blue_score'],'modeled'=>null],
    ];
    $key=trim((string)($match['tba_match_key']??''));
    if($key==='' || !tag_scouting_analytics_table_exists($pdo,'augur_epa_alliance_samples')) return $out;
    $s=$pdo->prepare("SELECT alliance,official_score,modeled_score
        FROM augur_epa_alliance_samples WHERE tba_match_key=?");
    $s->execute([$key]);
    foreach($s->fetchAll() as $r){
        $a=(string)$r['alliance'];
        if(!isset($out[$a])) continue;
        if(is_numeric($r['official_score']??null)) $out[$a]['official']=(float)$r['official_score'];
        if(is_numeric($r['modeled_score']??null)) $out[$a]['modeled']=(float)$r['modeled_score'];
    }
    return $out;
}

function tag_scouting_analytics_rebuild_match(PDO $pdo,int $org,int $matchId,bool $rebuildEvent=true): array {
    tag_scouting_analytics_install($pdo);

    $s=$pdo->prepare("SELECT m.*,e.tba_event_key,g.season_year
        FROM matches m
        JOIN events e ON e.id=m.event_id AND e.organization_id=m.organization_id
        JOIN games g ON g.id=m.game_id
        WHERE m.id=? AND m.organization_id=? LIMIT 1");
    $s->execute([$matchId,$org]);
    $match=$s->fetch();
    if(!$match) throw new RuntimeException('Tag analytics match not found.');

    $eventId=(int)$match['event_id'];
    $eventKey=trim((string)($match['tba_event_key']??''));

    // Clear the derived rows for this match first. Rebuild only robots that
    // currently have at least one raw Tag Scouting observation.
    $pdo->prepare("DELETE FROM tag_scouting_match_metrics
        WHERE organization_id=? AND match_id=?")->execute([$org,$matchId]);

    if(!tag_scouting_analytics_table_exists($pdo,'tag_scouting_match_data')){
        if($rebuildEvent) tag_scouting_analytics_rebuild_event_ratings($pdo,$org,$eventId);
        return ['event_id'=>$eventId,'match_id'=>$matchId,'rows'=>0];
    }

    $t=$pdo->prepare("SELECT frc_team_number,alliance,station
        FROM match_teams WHERE match_id=?
        ORDER BY FIELD(alliance,'Red','Blue'),station");
    $t->execute([$matchId]);
    $teams=$t->fetchAll();
    if(!$teams){
        if($rebuildEvent) tag_scouting_analytics_rebuild_event_ratings($pdo,$org,$eventId);
        return ['event_id'=>$eventId,'match_id'=>$matchId,'rows'=>0];
    }

    $obs=$pdo->prepare("SELECT user_id,frc_team_number,alliance,station,scoring_contribution,
            tags_json,tag_weights_json,note,updated_at
        FROM tag_scouting_match_data
        WHERE organization_id=? AND event_id=? AND match_id=?
        ORDER BY frc_team_number,user_id");
    $obs->execute([$org,$eventId,$matchId]);

    $byTeam=[];
    foreach($obs->fetchAll() as $r){
        $team=(int)$r['frc_team_number'];
        $byTeam[$team][]=$r;
    }

    $summary=[];
    foreach($teams as $teamRow){
        $team=(int)$teamRow['frc_team_number'];
        $rows=$byTeam[$team]??[];
        if(!$rows) continue;

        $shares=[];$tagCounts=[];$weightValues=[];$latest=null;$synthetic=0;
        foreach($rows as $r){
            if($r['scoring_contribution']!==null && is_numeric($r['scoring_contribution'])){
                $shares[]=(float)$r['scoring_contribution'];
            }

            if((int)$r['user_id']===TAG_SCOUTING_SYNTHETIC_USER_ID
                || str_starts_with((string)($r['note']??''),'Synthetic Tag Scouting estimate')){
                $synthetic++;
            }

            $ts=(string)($r['updated_at']??'');
            if($ts!=='' && ($latest===null || strcmp($ts,$latest)>0)) $latest=$ts;

            $tags=json_decode((string)($r['tags_json']??'[]'),true);
            if(!is_array($tags)) $tags=[];
            $weights=json_decode((string)($r['tag_weights_json']??'{}'),true);
            if(!is_array($weights)) $weights=[];

            foreach(array_values(array_unique(array_map('strval',$tags))) as $tag){
                $tagCounts[$tag]=($tagCounts[$tag]??0)+1;
                if(isset($weights[$tag]) && is_numeric($weights[$tag])){
                    $w=(int)$weights[$tag];
                    if($w>=1 && $w<=5) $weightValues[$tag][]=$w;
                }
            }
        }

        $observerCount=count($rows);
        $consensus=[];
        $weightConsensus=[];
        foreach($tagCounts as $tag=>$count){
            $weights=$weightValues[$tag]??[];
            $consensus[$tag]=[
                'count'=>$count,
                'support_pct'=>$observerCount>0?round($count/$observerCount*100,1):0,
            ];
            if($weights){
                $weightConsensus[$tag]=[
                    'weighted_count'=>count($weights),
                    'avg_weight'=>round((float)tag_scouting_analytics_mean($weights),2),
                ];
            }
        }

        uasort($consensus,static function(array $a,array $b): int{
            return ($b['count']<=>$a['count']) ?: ($b['support_pct']<=>$a['support_pct']);
        });

        $positiveDefense=(int)(($tagCounts['good_defense']??0)+($tagCounts['elite_defense']??0));
        // Count observers, not tag hits, for defensive support.
        $positiveObservers=0;$noneObservers=0;$defenseWeights=[];
        foreach($rows as $r){
            $tags=json_decode((string)($r['tags_json']??'[]'),true);
            if(!is_array($tags)) $tags=[];
            $set=array_fill_keys(array_map('strval',$tags),true);
            if(isset($set['good_defense'])||isset($set['elite_defense'])) $positiveObservers++;
            if(isset($set['no_defense'])) $noneObservers++;
            $weights=json_decode((string)($r['tag_weights_json']??'{}'),true);
            if(is_array($weights)){
                foreach(['good_defense','elite_defense'] as $tag){
                    if(isset($weights[$tag])&&is_numeric($weights[$tag])){
                        $w=(int)$weights[$tag];if($w>=1&&$w<=5)$defenseWeights[]=$w;
                    }
                }
            }
        }

        if($positiveObservers>=2 && $positiveObservers>$noneObservers && ($positiveObservers/$observerCount)>=0.5){
            $defenseStatus='Verified';
        } elseif($positiveObservers>0){
            $defenseStatus='Possible';
        } elseif($noneObservers>0){
            $defenseStatus='None';
        } else {
            $defenseStatus='Unknown';
        }

        $summary[$team]=[
            'team'=>$team,
            'alliance'=>(string)$teamRow['alliance'],
            'station'=>(int)$teamRow['station'],
            'observers'=>$observerCount,
            'synthetic'=>$synthetic,
            'share_count'=>count($shares),
            'share_avg'=>tag_scouting_analytics_mean($shares),
            'share_median'=>tag_scouting_analytics_median($shares),
            'share_sd'=>tag_scouting_analytics_stddev($shares),
            'tags'=>$consensus,
            'weights'=>$weightConsensus,
            'defense_status'=>$defenseStatus,
            'defense_support'=>$positiveObservers,
            'no_defense_support'=>$noneObservers,
            'defense_strength'=>tag_scouting_analytics_mean($defenseWeights),
            'latest'=>$latest,
        ];
    }

    $alliances=['Red'=>[],'Blue'=>[]];
    foreach($teams as $tr) $alliances[(string)$tr['alliance']][]=(int)$tr['frc_team_number'];

    $allianceMeta=[];
    foreach($alliances as $color=>$teamNums){
        $rated=[];
        foreach($teamNums as $team){
            if(isset($summary[$team]) && $summary[$team]['share_count']>0 && $summary[$team]['share_avg']!==null){
                $rated[$team]=(float)$summary[$team]['share_avg'];
            }
        }
        $rawTotal=$rated?array_sum($rated):null;
        $complete=count($teamNums)>0 && count($rated)===count($teamNums);
        $allianceMeta[$color]=[
            'rated_count'=>count($rated),
            'raw_total'=>$rawTotal,
            'complete'=>$complete,
        ];
    }

    $scores=tag_scouting_analytics_match_scores($pdo,$match);
    $insert=$pdo->prepare("INSERT INTO tag_scouting_match_metrics
        (organization_id,event_id,match_id,tba_match_key,comp_level,match_number,frc_team_number,alliance,station,
         observer_count,synthetic_observer_count,score_estimate_count,
         raw_share_avg,raw_share_median,share_stddev,alliance_score_robot_count,alliance_raw_share_total,normalized_share,effective_share,
         official_score,modeled_score,estimated_official_points,estimated_modeled_points,estimated_points,point_source,
         tags_consensus_json,tag_weights_consensus_json,
         defense_status,defense_support_count,no_defense_support_count,defense_strength,raw_depa,
         confidence_score,latest_observation_at,model_version,calculated_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");

    $written=0;
    foreach($summary as $team=>$r){
        $a=$r['alliance'];$meta=$allianceMeta[$a];
        $raw=$r['share_avg'];
        $normalized=null;
        if($raw!==null && $meta['complete'] && $meta['raw_total']!==null && $meta['raw_total']>0){
            $normalized=(float)$raw/(float)$meta['raw_total']*100.0;
        }
        $effective=$normalized??$raw;

        $official=$scores[$a]['official']??null;
        $modeled=$scores[$a]['modeled']??null;
        $estOfficial=($effective!==null&&$official!==null)?$official*$effective/100.0:null;
        $estModeled=($effective!==null&&$modeled!==null)?$modeled*$effective/100.0:null;
        if($estModeled!==null){$estPoints=$estModeled;$pointSource='modeled';}
        elseif($estOfficial!==null){$estPoints=$estOfficial;$pointSource='official';}
        else{$estPoints=null;$pointSource='none';}

        $obsFactor=min(1.0,$r['observers']/3.0);
        $estimateFactor=min(1.0,$r['share_count']/3.0);
        $spreadFactor=$r['share_count']<=1?0.55:max(0.0,1.0-min(1.0,((float)$r['share_sd'])/30.0));
        $coverageFactor=min(1.0,$meta['rated_count']/max(1,count($alliances[$a])));
        if($meta['complete'] && $meta['raw_total']!==null){
            $coherence=max(0.0,1.0-min(1.0,abs((float)$meta['raw_total']-100.0)/50.0));
        } else {
            $coherence=$coverageFactor*0.55;
        }
        $confidence=100.0*(0.25*$obsFactor+0.25*$estimateFactor+0.25*$spreadFactor+0.25*$coherence);
        if($r['share_count']===0) $confidence=100.0*(0.65*$obsFactor);
        $confidence=max(0.0,min(100.0,$confidence));

        $tagsJson=json_encode($r['tags'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}';
        $weightsJson=$r['weights']?json_encode($r['weights'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null;
        $rawDepa=tag_scouting_analytics_raw_depa(
            $pdo,$org,$eventId,$matchId,$team,$eventKey,(string)($match['tba_match_key']??''),$a
        );

        $insert->execute([
            $org,$eventId,$matchId,(string)($match['tba_match_key']??''),
            (string)$match['comp_level'],(int)$match['match_number'],$team,$a,$r['station'],
            $r['observers'],$r['synthetic'],$r['share_count'],
            $r['share_avg'],$r['share_median'],$r['share_sd'],$meta['rated_count'],$meta['raw_total'],$normalized,$effective,
            $official,$modeled,$estOfficial,$estModeled,$estPoints,$pointSource,
            $tagsJson,$weightsJson,
            $r['defense_status'],$r['defense_support'],$r['no_defense_support'],$r['defense_strength'],$rawDepa,
            $confidence,$r['latest'],TAG_SCOUTING_ANALYTICS_MODEL_VERSION
        ]);
        $written++;
    }

    if($rebuildEvent) tag_scouting_analytics_rebuild_event_ratings($pdo,$org,$eventId);
    return ['event_id'=>$eventId,'match_id'=>$matchId,'rows'=>$written];
}

function tag_scouting_analytics_rebuild_event_ratings(PDO $pdo,int $org,int $eventId): array {
    tag_scouting_analytics_install($pdo);
    $event=tag_scouting_analytics_event($pdo,$org,$eventId);
    if(!$event) throw new RuntimeException('Tag analytics event not found.');

    $eventKey=trim((string)($event['tba_event_key']??''));
    $year=(int)$event['season_year'];
    $settings=tag_scouting_analytics_settings($pdo,$org,$eventId);

    $q=$pdo->prepare("SELECT *
        FROM tag_scouting_match_metrics
        WHERE organization_id=? AND event_id=? AND comp_level='qm'
        ORDER BY match_number,match_id,frc_team_number");
    $q->execute([$org,$eventId]);
    $byTeam=[];
    foreach($q->fetchAll() as $r) $byTeam[(int)$r['frc_team_number']][]=$r;

    $pdo->prepare("DELETE FROM augur_tag_event_ratings
        WHERE organization_id=? AND event_id=?")->execute([$org,$eventId]);

    $ins=$pdo->prepare("INSERT INTO augur_tag_event_ratings
        (organization_id,event_id,tba_event_key,season_year,frc_team_number,
         matches_with_tag_data,matches_with_score_share,tag_points_avg,tag_points_recent,tag_points_high,tag_points_trend,
         public_epa,neptune_epa,
         public_depa,tag_defense_depa,possible_defense_depa,nondefense_suppression,neptune_depa,
         verified_defense_matches,possible_defense_matches,no_defense_matches,unknown_defense_matches,
         offense_confidence,defense_confidence,model_version,calculated_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");

    $written=0;
    foreach($byTeam as $team=>$rows){
        $points=[];$matchConf=[];$allRaw=[];$verified=[];$possible=[];$none=[];
        $verifiedConf=[];

        foreach($rows as $r){
            if($r['estimated_points']!==null && is_numeric($r['estimated_points'])){
                $points[]=(float)$r['estimated_points'];
                $matchConf[]=(float)$r['confidence_score'];
            }
            if($r['raw_depa']!==null && is_numeric($r['raw_depa'])){
                $raw=(float)$r['raw_depa'];$allRaw[]=$raw;
                $status=(string)$r['defense_status'];
                if($status==='Verified'){$verified[]=$raw;$verifiedConf[]=(float)$r['confidence_score'];}
                elseif($status==='Possible')$possible[]=$raw;
                elseif($status==='None')$none[]=$raw;
            }
        }

        $publicEpa=tag_scouting_analytics_public_epa($pdo,$eventKey,$team);
        $blend=augur_prediction_offense_blend($points,$publicEpa,$settings);

        $avgConf=tag_scouting_analytics_mean($matchConf)??0.0;
        $sampleFactor=$points?min(1.0,0.45+count($points)*0.15):0.0;
        $offenseConfidence=max(0.0,min(100.0,$avgConf*$sampleFactor));

        $publicDepa=tag_scouting_analytics_mean($allRaw);
        $defenseDepa=tag_scouting_analytics_mean($verified);
        $possibleDepa=tag_scouting_analytics_mean($possible);
        $nonDefense=tag_scouting_analytics_mean($none);
        $neptuneDepa=$defenseDepa;

        if($verified){
            $baseConf=tag_scouting_analytics_mean($verifiedConf)??45.0;
            $countFactor=min(1.0,0.50+count($verified)*0.20);
            $defenseConfidence=max(0.0,min(100.0,$baseConf*$countFactor));
        } elseif($possible){
            $defenseConfidence=min(45.0,20.0+count($possible)*10.0);
        } elseif($none){
            $defenseConfidence=min(55.0,20.0+count($none)*10.0);
        } else {
            $defenseConfidence=0.0;
        }

        $ins->execute([
            $org,$eventId,$eventKey!==''?$eventKey:null,$year,$team,
            count($rows),count($points),
            $points?($blend['event_avg']??null):null,
            $points?($blend['recent_avg']??null):null,
            $points?($blend['p75']??null):null,
            $points?($blend['trend_adjustment']??null):null,
            $publicEpa,
            ($points||$publicEpa!==null)?($blend['augur_epa']??null):null,
            $publicDepa,$defenseDepa,$possibleDepa,$nonDefense,$neptuneDepa,
            count($verified),count($possible),count($none),
            count(array_filter($rows,static fn($r)=>(string)$r['defense_status']==='Unknown')),
            $offenseConfidence,$defenseConfidence,TAG_SCOUTING_ANALYTICS_MODEL_VERSION
        ]);
        $written++;
    }

    return ['event_id'=>$eventId,'ratings'=>$written,'teams'=>count($byTeam)];
}

function tag_scouting_analytics_rebuild_event(PDO $pdo,int $org,int $eventId): array {
    tag_scouting_analytics_install($pdo);
    $event=tag_scouting_analytics_event($pdo,$org,$eventId);
    if(!$event) throw new RuntimeException('Tag analytics event not found.');

    $q=$pdo->prepare("SELECT id FROM matches
        WHERE organization_id=? AND event_id=?
        ORDER BY FIELD(comp_level,'qm','ef','qf','sf','f','legacy'),set_number,match_number,field_id");
    $q->execute([$org,$eventId]);
    $matches=0;$metrics=0;
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $matchId){
        $r=tag_scouting_analytics_rebuild_match($pdo,$org,(int)$matchId,false);
        $matches++;$metrics+=(int)$r['rows'];
    }
    $ratings=tag_scouting_analytics_rebuild_event_ratings($pdo,$org,$eventId);
    return [
        'event_id'=>$eventId,
        'matches'=>$matches,
        'match_metric_rows'=>$metrics,
        'rating_rows'=>(int)$ratings['ratings'],
        'model_version'=>TAG_SCOUTING_ANALYTICS_MODEL_VERSION,
    ];
}

function tag_scouting_analytics_ensure_event(PDO $pdo,int $org,int $eventId): array {
    tag_scouting_analytics_install($pdo);

    $latestSource=0;
    if(tag_scouting_analytics_table_exists($pdo,'tag_scouting_match_data')){
        $s=$pdo->prepare("SELECT UNIX_TIMESTAMP(MAX(updated_at))
            FROM tag_scouting_match_data WHERE organization_id=? AND event_id=?");
        $s->execute([$org,$eventId]);$latestSource=max($latestSource,(int)$s->fetchColumn());
    }
    $s=$pdo->prepare("SELECT UNIX_TIMESTAMP(last_tba_sync_at) FROM events
        WHERE id=? AND organization_id=? LIMIT 1");
    $s->execute([$eventId,$org]);$latestSource=max($latestSource,(int)$s->fetchColumn());

    if(tag_scouting_analytics_table_exists($pdo,'augur_epa_alliance_samples')){
        $ev=tag_scouting_analytics_event($pdo,$org,$eventId);
        $key=trim((string)($ev['tba_event_key']??''));
        if($key!==''){
            $s=$pdo->prepare("SELECT UNIX_TIMESTAMP(MAX(updated_at))
                FROM augur_epa_alliance_samples WHERE tba_event_key=?");
            $s->execute([$key]);$latestSource=max($latestSource,(int)$s->fetchColumn());
        }
    }

    $s=$pdo->prepare("SELECT UNIX_TIMESTAMP(MAX(calculated_at))
        FROM augur_tag_event_ratings WHERE organization_id=? AND event_id=?");
    $s->execute([$org,$eventId]);$latestCalc=(int)$s->fetchColumn();

    if($latestCalc===0 || $latestSource>$latestCalc){
        return tag_scouting_analytics_rebuild_event($pdo,$org,$eventId)+['rebuilt'=>true];
    }
    return ['event_id'=>$eventId,'rebuilt'=>false,'model_version'=>TAG_SCOUTING_ANALYTICS_MODEL_VERSION];
}
