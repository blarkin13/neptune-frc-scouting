<?php

require_once __DIR__.'/_alliance_helpers.php';
require_once dirname(__DIR__).'/prescout/_helpers.php';
require_once __DIR__.'/_augur_epa.php';

function augur_prediction_mean(array $values): float {
    return $values ? array_sum($values) / count($values) : 0.0;
}

function augur_prediction_stddev(array $values): float {
    if (count($values) < 2) return 0.0;
    $mean = augur_prediction_mean($values);
    $sum = 0.0;
    foreach ($values as $value) {
        $delta = (float)$value - $mean;
        $sum += $delta * $delta;
    }
    return sqrt($sum / count($values));
}

function augur_prediction_percentile(array $values, float $quantile): float {
    if (!$values) return 0.0;
    sort($values, SORT_NUMERIC);
    $position = (count($values) - 1) * $quantile;
    $low = (int)floor($position);
    $high = (int)ceil($position);
    if ($low === $high) return (float)$values[$low];
    return (float)$values[$low] + ((float)$values[$high] - (float)$values[$low]) * ($position - $low);
}

function augur_prediction_slope(array $values): float {
    $count = count($values);
    if ($count < 2) return 0.0;
    $xMean = ($count - 1) / 2;
    $yMean = augur_prediction_mean($values);
    $numerator = 0.0;
    $denominator = 0.0;
    foreach ($values as $index => $value) {
        $dx = $index - $xMean;
        $numerator += $dx * ((float)$value - $yMean);
        $denominator += $dx * $dx;
    }
    return $denominator > 0 ? $numerator / $denominator : 0.0;
}

function augur_prediction_clamp(float $value, float $low, float $high): float {
    return min($high, max($low, $value));
}

function augur_prediction_weighted_recent(array $values, int $limit = 6): float {
    $values = array_slice($values, -$limit);
    if (!$values) return 0.0;
    $weighted = 0.0;
    $weightTotal = 0.0;
    foreach ($values as $index => $value) {
        $weight = $index + 1;
        $weighted += (float)$value * $weight;
        $weightTotal += $weight;
    }
    return $weightTotal > 0 ? $weighted / $weightTotal : 0.0;
}

function augur_prediction_normal_cdf(float $x): float {
    $sign = $x < 0 ? -1.0 : 1.0;
    $x = abs($x) / sqrt(2.0);
    $t = 1.0 / (1.0 + 0.3275911 * $x);
    $a1 = 0.254829592;
    $a2 = -0.284496736;
    $a3 = 1.421413741;
    $a4 = -1.453152027;
    $a5 = 1.061405429;
    $erf = 1.0 - ((((($a5 * $t + $a4) * $t + $a3) * $t + $a2) * $t + $a1) * $t) * exp(-$x * $x);
    return 0.5 * (1.0 + $sign * $erf);
}

function augur_prediction_comp_rank(string $level): int {
    return match (strtolower($level)) {
        'qm' => 10,
        'ef' => 20,
        'qf' => 30,
        'sf' => 40,
        'f' => 50,
        'legacy' => 5,
        default => 15,
    };
}

function augur_prediction_match_is_prior(array $candidate, array $target): bool {
    if ((int)($candidate['id'] ?? 0) === (int)($target['id'] ?? 0)) return false;

    $candidateLevel = strtolower((string)($candidate['comp_level'] ?? ''));
    $targetLevel = strtolower((string)($target['comp_level'] ?? ''));

    // Qualification backtesting should be monotonic by qualification number.
    // This avoids bad/simulated timestamps or rerun metadata making Qual 20 look
    // like it happened before Qual 8.
    if ($candidateLevel === 'qm' && $targetLevel === 'qm') {
        return (int)($candidate['match_number'] ?? 0) < (int)($target['match_number'] ?? 0);
    }

    // Every qualification match is logically before every playoff match.
    // Do this before timestamp checks because imported/simulated event timestamps
    // are not always reliable enough for historical playoff backtesting.
    if ($candidateLevel === 'qm' && $targetLevel !== 'qm') return true;
    if ($candidateLevel !== 'qm' && $targetLevel === 'qm') return false;

    $targetCutoff = (string)($target['started_at'] ?? '');
    if ($targetCutoff === '') $targetCutoff = (string)($target['scheduled_time'] ?? '');
    if ($targetCutoff === '') $targetCutoff = (string)($target['ended_at'] ?? '');

    if ($targetCutoff !== '') {
        $targetTs = strtotime($targetCutoff);
        if ($targetTs !== false) {
            $candidateEnd = (string)($candidate['ended_at'] ?? '');
            if ($candidateEnd !== '') {
                $endTs = strtotime($candidateEnd);
                if ($endTs !== false) return $endTs < $targetTs;
            }
            if (($candidate['state'] ?? '') === 'ended') {
                $candidateStart = (string)($candidate['started_at'] ?? '');
                if ($candidateStart === '') $candidateStart = (string)($candidate['scheduled_time'] ?? '');
                if ($candidateStart !== '') {
                    $startTs = strtotime($candidateStart);
                    if ($startTs !== false) return $startTs < $targetTs;
                }
            }
        }
    }

    $a = [
        augur_prediction_comp_rank((string)($candidate['comp_level'] ?? '')),
        (int)($candidate['set_number'] ?? 1),
        (int)($candidate['match_number'] ?? 0),
        (int)($candidate['field_id'] ?? 1),
    ];
    $b = [
        augur_prediction_comp_rank((string)($target['comp_level'] ?? '')),
        (int)($target['set_number'] ?? 1),
        (int)($target['match_number'] ?? 0),
        (int)($target['field_id'] ?? 1),
    ];
    foreach ($a as $index => $value) {
        if ($value === $b[$index]) continue;
        return $value < $b[$index];
    }
    return false;
}

function augur_prediction_baseline_excluding(array $observations, int $excludeMatch): ?float {
    $values = [];
    foreach ($observations as $row) {
        if ((int)$row['match_id'] !== $excludeMatch) $values[] = (float)$row['points'];
    }
    return $values ? augur_prediction_mean($values) : null;
}

function augur_prediction_record_map(array $matches): array {
    $records = [];
    foreach ($matches as $match) {
        $redScore = $match['red_score'] ?? null;
        $blueScore = $match['blue_score'] ?? null;
        if ($redScore === null || $blueScore === null) continue;
        $redScore = (float)$redScore;
        $blueScore = (float)$blueScore;
        foreach (['red', 'blue'] as $color) {
            foreach (($match[$color] ?? []) as $team) {
                $team = (int)$team;
                $records[$team] ??= ['wins' => 0, 'losses' => 0, 'ties' => 0, 'matches' => 0];
                $records[$team]['matches']++;
                if ($redScore === $blueScore) {
                    $records[$team]['ties']++;
                } elseif (($color === 'red' && $redScore > $blueScore) || ($color === 'blue' && $blueScore > $redScore)) {
                    $records[$team]['wins']++;
                } else {
                    $records[$team]['losses']++;
                }
            }
        }
    }
    return $records;
}

/**
 * Return one active scouting observation per robot/match, independent of the
 * match table's current run_number. This matters for imports and reruns: the
 * action/session rows carry the run that was actually scouted, while the match
 * row may later be advanced to a newer run.
 */
function augur_prediction_pick_active_run_observations(array $runRows, array $sessionRows = []): array {
    $out = [];
    $keys = array_unique(array_merge(array_keys($runRows), array_keys($sessionRows)));
    foreach ($keys as $key) {
        $runs = $runRows[$key] ?? [];
        $sessionRuns = $sessionRows[$key] ?? [];
        // Prefer the newest run that has actual active actions. A newer empty
        // session should not erase a valid imported/scouted run.
        $availableRuns = $runs ? array_keys($runs) : array_keys($sessionRuns);
        if (!$availableRuns) continue;
        rsort($availableRuns, SORT_NUMERIC);
        $run = (int)$availableRuns[0];
        $agg = $runs[$run] ?? ['points'=>0.0,'defense'=>0,'attempts'=>0,'successes'=>0];
        $out[$key] = [
            'run' => $run,
            'points' => (float)($agg['points'] ?? 0.0),
            'defense' => (int)($agg['defense'] ?? 0),
            'attempts' => (int)($agg['attempts'] ?? 0),
            'successes' => (int)($agg['successes'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Prior-event Neptune scouting from the same game/season. Only events with a
 * start date before the current event are eligible, preventing future leakage.
 */
function augur_prediction_prior_neptune_observations(
    PDO $pdo,
    int $org,
    array $event,
    array $teams
): array {
    $out = [];
    $teams = array_values(array_unique(array_filter(array_map('intval', $teams), static fn($t)=>$t>0)));
    $currentStart = trim((string)($event['start_date'] ?? ''));
    $gameId = (int)($event['game_id'] ?? 0);
    $eventId = (int)($event['id'] ?? 0);
    if (!$teams || $gameId < 1 || $eventId < 1 || $currentStart === '') return $out;

    $teamPh = implode(',', array_fill(0, count($teams), '?'));
    $args = array_merge([$org, $gameId, $eventId, $currentStart], $teams);
    $stmt = $pdo->prepare(
        "SELECT sa.match_id,sa.match_run_number,sa.frc_team_number,sa.points,sa.action_type,sa.result,
                m.match_number,m.comp_level
         FROM scouting_actions sa
         JOIN matches m ON m.id=sa.match_id AND m.organization_id=sa.organization_id
         JOIN events e ON e.id=sa.event_id AND e.organization_id=sa.organization_id
         WHERE sa.organization_id=?
           AND e.game_id=?
           AND e.id<>?
           AND e.start_date IS NOT NULL
           AND e.start_date<?
           AND sa.deleted_at IS NULL
           AND sa.frc_team_number IN ($teamPh)
           AND m.comp_level IN ('qm','ef','qf','sf','f')
         ORDER BY sa.frc_team_number,sa.match_id,sa.match_run_number,sa.id"
    );
    $stmt->execute($args);

    $byRun = [];
    foreach ($stmt->fetchAll() as $row) {
        $team = (int)$row['frc_team_number'];
        $matchId = (int)$row['match_id'];
        $run = (int)$row['match_run_number'];
        $key = $team.':'.$matchId;
        $byRun[$key][$run] ??= [
            'team'=>$team,'match_id'=>$matchId,'match_number'=>(int)$row['match_number'],
            'points'=>0.0,'defense'=>0,'attempts'=>0,'successes'=>0,
        ];
        $byRun[$key][$run]['points'] += (float)$row['points'];
        if (($row['action_type'] ?? '') === 'defense' && ($row['result'] ?? '') === 'Success') {
            $byRun[$key][$run]['defense']++;
        }
        if (($row['action_type'] ?? '') === 'offense' && in_array((string)($row['result'] ?? ''), ['Success','Failure'], true)) {
            $byRun[$key][$run]['attempts']++;
            if (($row['result'] ?? '') === 'Success') $byRun[$key][$run]['successes']++;
        }
    }

    foreach ($byRun as $runs) {
        $runNumbers = array_keys($runs);
        rsort($runNumbers, SORT_NUMERIC);
        $row = $runs[(int)$runNumbers[0]];
        $out[(int)$row['team']][] = [
            'match_id'=>(int)$row['match_id'],
            'match_number'=>(int)$row['match_number'],
            'points'=>(float)$row['points'],
            'defense'=>(int)$row['defense'],
            'attempts'=>(int)$row['attempts'],
            'successes'=>(int)$row['successes'],
        ];
    }
    return $out;
}

/**
 * Legacy compatibility hook. AUGUR no longer performs live TBA/OPR fallback
 * requests from prediction pages. Public EPA comes only from the local archive.
 */
function augur_prediction_prior_tba_opr_map(array $event, array $teams): array {
    return [];
}

function augur_prediction_field_robot_average(array $observations): float {
    $values = [];
    foreach ($observations as $rows) {
        foreach ($rows as $row) $values[] = (float)($row['points'] ?? 0.0);
    }
    return $values ? augur_prediction_mean($values) : 0.0;
}

/**
 * Blend the public TBA-derived EPA baseline with current-event Neptune scoring.
 * EPA anchors the robot early in an event; scouting earns more influence as the
 * sample grows so one unusually good/bad first match cannot take over the model.
 */
function augur_prediction_offense_blend(array $points, ?float $epa, array $settings): array {
    $eventAverage=augur_prediction_mean($points);
    $recentAverage=augur_prediction_weighted_recent($points,6);
    $p75=augur_prediction_percentile($points,0.75);
    $n=count($points);
    $scoutingInfluence=$n>0 ? augur_prediction_clamp($n/4.0,0.25,1.0) : 0.0;
    $components=[
        ['value'=>$eventAverage,'weight'=>(float)$settings['event_offense_weight']*$scoutingInfluence,'available'=>$n>0],
        ['value'=>$recentAverage,'weight'=>(float)$settings['recent_offense_weight']*$scoutingInfluence,'available'=>$n>0],
        ['value'=>$p75,'weight'=>(float)$settings['ceiling_weight']*$scoutingInfluence,'available'=>$n>0],
        ['value'=>$epa??0.0,'weight'=>(float)$settings['epa_weight'],'available'=>$epa!==null],
    ];
    $weighted=0.0;$weightTotal=0.0;
    foreach($components as $component){
        if($component['available']&&$component['weight']>0){$weighted+=(float)$component['value']*(float)$component['weight'];$weightTotal+=(float)$component['weight'];}
    }
    $base=$weightTotal>0?$weighted/$weightTotal:0.0;
    $recent=array_slice($points,-6);
    $slopeRaw=count($recent)>=3?augur_prediction_slope($recent):0.0;
    $trendSample=count($recent)>=3?augur_prediction_clamp((count($recent)-2)/4.0,0.0,1.0):0.0;
    $slopeCap=max(2.0,max($base,10.0)*0.08);
    $slope=augur_prediction_clamp($slopeRaw,-$slopeCap,$slopeCap)*$trendSample;
    $trendCap=max(4.0,max($base,10.0)*0.15);
    $trendAdjustment=augur_prediction_clamp($slope*(float)$settings['trend_strength'],-$trendCap,$trendCap);
    return [
        'event_avg'=>$eventAverage,'recent_avg'=>$recentAverage,'p75'=>$p75,
        'scouting_influence'=>$scoutingInfluence,'base'=>$base,'weight_total'=>$weightTotal,
        'slope_raw'=>$slopeRaw,'slope'=>$slope,'trend_adjustment'=>$trendAdjustment,
        'neptune_epa'=>max(0.0,$base+$trendAdjustment),
        'augur_epa'=>max(0.0,$base+$trendAdjustment), // legacy compatibility alias
    ];
}

function augur_prediction_team_metrics(
    int $team,
    array $observations,
    array $priorObservations,
    array $obsByTeamMatch,
    array $teamMatchAlliance,
    array $matches,
    array $recordMap,
    array $epaMap,
    array $priorTbaOpr,
    float $fieldAverage,
    array $settings
): array {
    $rows=$observations[$team]??[];
    $priorRows=$priorObservations[$team]??[];
    $points=array_map(static fn($row)=>(float)$row['points'],$rows);
    $priorPoints=array_map(static fn($row)=>(float)$row['points'],$priorRows);
    $defense=array_map(static fn($row)=>(float)$row['defense'],$rows);
    $priorDefense=array_map(static fn($row)=>(float)$row['defense'],$priorRows);

    $epaRow=$epaMap[$team]??[];
    $epa=isset($epaRow['epa'])&&is_numeric($epaRow['epa'])?(float)$epaRow['epa']:null;
    $epaConfidence=isset($epaRow['confidence'])&&is_numeric($epaRow['confidence'])?(float)$epaRow['confidence']:null;
    $blend=augur_prediction_offense_blend($points,$epa,$settings);
    $eventAverage=$blend['event_avg'];$recentAverage=$blend['recent_avg'];$p75=$blend['p75'];
    $currentEstimate=$blend['base'];

    $priorAverage=augur_prediction_mean($priorPoints);
    $tbaOpr=isset($priorTbaOpr[$team])&&is_numeric($priorTbaOpr[$team])?(float)$priorTbaOpr[$team]:null;
    $priorWeighted=0.0;$priorWeightTotal=0.0;
    if($priorPoints){$priorWeighted+=$priorAverage*65.0;$priorWeightTotal+=65.0;}
    if($tbaOpr!==null){$priorWeighted+=$tbaOpr*25.0;$priorWeightTotal+=25.0;}
    if($fieldAverage>0){$priorWeighted+=$fieldAverage*10.0;$priorWeightTotal+=10.0;}
    $priorBaseline=$priorWeightTotal>0?$priorWeighted/$priorWeightTotal:($fieldAverage>0?$fieldAverage:0.0);

    $currentMatches=count($points);$priorMatches=count($priorPoints);
    if($currentEstimate>0){
        $base=$currentEstimate;
    }else{
        $base=$priorBaseline;
    }

    if($epa!==null&&$currentMatches>0)$source='EPA + current-event scouting';
    elseif($epa!==null)$source='EPA';
    elseif($currentMatches>0)$source=$priorBaseline>0?'current + season prior':'current-event scouting';
    elseif($priorPoints)$source=$tbaOpr!==null?'prior-event scouting':'prior-event scouting';
    elseif($tbaOpr!==null)$source='public EPA unavailable';
    elseif($fieldAverage>0)$source='event field average';
    else $source='no baseline';

    // Current-event trend is a scouting enhancement to Neptune EPA, not part of
    // the independent public EPA number.
    $recent=array_slice($points,-6);
    $slopeRaw=count($recent)>=3?augur_prediction_slope($recent):0.0;
    $trendSample=count($recent)>=3?augur_prediction_clamp((count($recent)-2)/4.0,0.0,1.0):0.0;
    $slopeCap=max(2.0,max($base,10.0)*0.08);
    $slope=augur_prediction_clamp($slopeRaw,-$slopeCap,$slopeCap)*$trendSample;
    $trendCap=max(4.0,max($base,10.0)*0.15);
    $trendAdjustment=augur_prediction_clamp($slope*(float)$settings['trend_strength'],-$trendCap,$trendCap);
    $augurEpa=max(0.0,$base+$trendAdjustment);

    $volatilitySource=count($points)>=2?$points:$priorPoints;
    $volatility=augur_prediction_stddev(array_slice($volatilitySource,-8));

    $suppressionSamples=[];
    foreach(($teamMatchAlliance[$team]??[]) as $matchId=>$color){
        $opponentColor=$color==='red'?'blue':'red';$deltas=[];
        foreach(($matches[$matchId][$opponentColor]??[]) as $opponentTeam){
            if(!isset($obsByTeamMatch[$opponentTeam][$matchId]))continue;
            $baseline=augur_prediction_baseline_excluding($observations[$opponentTeam]??[],$matchId);
            if($baseline===null)continue;
            $deltas[]=$baseline-(float)$obsByTeamMatch[$opponentTeam][$matchId]['points'];
        }
        if(count($deltas)>=2)$suppressionSamples[]=augur_prediction_mean($deltas)*3.0;
    }
    $suppressionRaw=augur_prediction_mean($suppressionSamples);
    $suppressionReliability=count($suppressionSamples)/(count($suppressionSamples)+4.0);
    $suppressionCap=max(4.0,max($augurEpa,$fieldAverage,10.0)*0.15);
    $suppression=augur_prediction_clamp($suppressionRaw*$suppressionReliability,-$suppressionCap*0.35,$suppressionCap);

    $currentDefenseRate=augur_prediction_mean($defense);$priorDefenseRate=augur_prediction_mean($priorDefense);
    if($currentMatches>0&&$priorMatches>0){$w=$currentMatches/($currentMatches+2.0);$defenseRate=$currentDefenseRate*$w+$priorDefenseRate*(1-$w);}
    elseif($currentMatches>0)$defenseRate=$currentDefenseRate;else $defenseRate=$priorDefenseRate;

    $attempts=array_sum(array_map(static fn($row)=>(int)$row['attempts'],$rows));
    $successes=array_sum(array_map(static fn($row)=>(int)$row['successes'],$rows));
    $priorAttempts=array_sum(array_map(static fn($row)=>(int)$row['attempts'],$priorRows));
    $priorSuccesses=array_sum(array_map(static fn($row)=>(int)$row['successes'],$priorRows));
    $allAttempts=$attempts+$priorAttempts;$allSuccesses=$successes+$priorSuccesses;
    $successRate=$allAttempts>0?$allSuccesses/$allAttempts:0.0;

    $record=$recordMap[$team]??[];
    $played=max(0,(int)($record['wins']??0)+(int)($record['losses']??0)+(int)($record['ties']??0));
    $winRate=$played>0?(((int)($record['wins']??0)+0.5*(int)($record['ties']??0))/$played):0.5;

    $currentFactor=augur_prediction_clamp($currentMatches/6.0,0.0,1.0);
    $priorFactor=augur_prediction_clamp($priorMatches/8.0,0.0,1.0);
    $epaFactor=$epa!==null?augur_prediction_clamp(($epaConfidence??55.0)/100.0,0.35,0.98):0.0;
    $fallbackFactor=max($priorFactor*0.75,$tbaOpr!==null?0.38:0.0,$fieldAverage>0?0.18:0.0,$epaFactor);
    $coverage=max($currentFactor,$fallbackFactor);
    $consistency=1.0-augur_prediction_clamp($volatility/max($augurEpa+10.0,10.0),0.0,1.0);
    $defenseEvidence=min(1.0,count($suppressionSamples)/4.0);
    $confidence=(0.60*$coverage+0.25*$consistency+0.15*$defenseEvidence)*100.0;
    if($currentMatches===0&&$epa===null)$confidence=min($confidence,65.0);
    elseif($currentMatches===1)$confidence=min(max($confidence,$epaFactor*70.0),78.0);
    elseif($currentMatches===2)$confidence=min(max($confidence,$epaFactor*75.0),84.0);

    return [
        'matches'=>$currentMatches,'current_matches'=>$currentMatches,'prior_season_matches'=>$priorMatches,
        'event_avg'=>$eventAverage,'recent_avg'=>$recentAverage,'p75'=>$p75,'scouting_influence'=>$blend['scouting_influence'],
        'prior_season_avg'=>$priorAverage,'prior_tba_opr'=>$tbaOpr,'field_avg_fallback'=>$fieldAverage,
        'baseline_source'=>$source,'baseline_before_trend'=>$base,'slope_raw'=>$slopeRaw,'slope'=>$slope,
        'volatility'=>$volatility,'projected_offense'=>$augurEpa,'neptune_epa'=>$augurEpa,'augur_epa'=>$augurEpa,'trend_adjustment'=>$trendAdjustment,
        'epa'=>$epa,'auto_epa'=>$epaRow['auto']??null,'teleop_epa'=>$epaRow['teleop']??null,'endgame_epa'=>$epaRow['endgame']??null,
        'epa_confidence'=>$epaConfidence,
        'defense_actions_per_match'=>$defenseRate,'opponent_suppression'=>$suppression,'opponent_suppression_raw'=>$suppressionRaw,
        'defense_sample'=>count($suppressionSamples),'success_rate'=>$successRate*100.0,
        'rank'=>(int)($record['rank']??0),'ranking_points'=>(float)($record['points']??0),
        'wins'=>(int)($record['wins']??0),'losses'=>(int)($record['losses']??0),'ties'=>(int)($record['ties']??0),
        'win_rate'=>$winRate,'confidence'=>augur_prediction_clamp($confidence,0.0,100.0),
    ];
}

function augur_prediction_alliance_summary(array $teams, array $metrics, array $identity, array $settings): array {
    $rows = [];
    foreach ($teams as $team) {
        $team = (int)$team;
        $rows[] = [
            'team' => $team,
            'nickname' => $identity[$team] ?? '',
            'metrics' => $metrics[$team] ?? [],
        ];
    }

    $base = array_sum(array_map(static fn($row) => (float)($row['metrics']['projected_offense'] ?? 0), $rows));
    $slope = array_sum(array_map(static fn($row) => (float)($row['metrics']['slope'] ?? 0), $rows));
    $volatility = sqrt(array_sum(array_map(static fn($row) => pow((float)($row['metrics']['volatility'] ?? 0), 2), $rows)));
    $suppression = augur_prediction_mean(array_map(static fn($row) => (float)($row['metrics']['opponent_suppression'] ?? 0), $rows));
    $defenseRate = augur_prediction_mean(array_map(static fn($row) => (float)($row['metrics']['defense_actions_per_match'] ?? 0), $rows));

    $suppressionWeight = max(0.0, (float)$settings['defense_suppression_weight']);
    $activityWeight = max(0.0, (float)$settings['defense_activity_weight']);
    $defenseWeightTotal = $suppressionWeight + $activityWeight;
    if ($defenseWeightTotal <= 0) $defenseWeightTotal = 1.0;
    $activityEquivalent = $defenseRate * (float)$settings['defense_action_points'];
    $defenseEstimate = ($suppression * $suppressionWeight + $activityEquivalent * $activityWeight) / $defenseWeightTotal;

    $winRate = augur_prediction_mean(array_map(static fn($row) => (float)($row['metrics']['win_rate'] ?? 0.5), $rows));
    $confidence = augur_prediction_mean(array_map(static fn($row) => (float)($row['metrics']['confidence'] ?? 0), $rows));
    $epa = array_sum(array_map(static fn($row) => (float)($row['metrics']['epa'] ?? 0), $rows));
    $augurEpa = array_sum(array_map(static fn($row) => (float)($row['metrics']['neptune_epa'] ?? $row['metrics']['augur_epa'] ?? 0), $rows));
    $scouted = array_sum(array_map(static fn($row) => (int)($row['metrics']['current_matches'] ?? 0), $rows));
    $fallbackRobots = count(array_filter($rows, static function($row){
        $m=$row['metrics']??[];
        return (int)($m['current_matches']??0)===0 && (string)($m['baseline_source']??'no baseline')!=='no baseline';
    }));

    return [
        'teams' => $rows,
        'base_offense' => $base,
        'slope' => $slope,
        'volatility' => $volatility,
        'defense_estimate' => $defenseEstimate,
        'opponent_suppression' => $suppression,
        'defense_actions_per_match' => $defenseRate,
        'win_rate' => $winRate,
        'confidence' => $confidence,
        'epa' => $epa,
        'neptune_epa' => $augurEpa,
        'augur_epa' => $augurEpa, // legacy compatibility alias
        'scouted_matches' => $scouted,
        'fallback_robots' => $fallbackRobots,
        'complete' => count($teams) >= 3,
    ];
}

function augur_prediction_apply_opponent(array $self, array $opponent, array $settings): array {
    $capPercent = augur_prediction_clamp((float)$settings['max_defense_adjustment_pct'] / 100.0, 0.0, 0.5);
    $cap = max(5.0, $self['base_offense'] * $capPercent);
    $defenseAdjustment = augur_prediction_clamp((float)$opponent['defense_estimate'], -$cap * 0.5, $cap);
    $formStrength = augur_prediction_clamp((float)$settings['tba_form_strength'] / 100.0, 0.0, 0.25);
    $formAdjustment = $self['base_offense'] * (($self['win_rate'] - 0.5) * 2.0) * $formStrength;
    $predicted = max(0.0, $self['base_offense'] - $defenseAdjustment + $formAdjustment);
    $uncertainty = max(
        6.0,
        $self['volatility'] + ((100.0 - $self['confidence']) / 100.0) * max(10.0, $predicted * 0.18)
    );

    return $self + [
        'defense_adjustment' => $defenseAdjustment,
        'form_adjustment' => $formAdjustment,
        'predicted_score' => $predicted,
        'sigma' => $uncertainty,
        'low' => max(0.0, $predicted - 1.28 * $uncertainty),
        'high' => $predicted + 1.28 * $uncertainty,
    ];
}

/**
 * Shared AUGUR alliance prediction model.
 *
 * Options:
 * - cutoff_match: match row. If supplied, only qualification matches before it are used.
 * - use_tba_rankings: use current TBA ranking/record data. Disable for historical pre-match predictions.
 * - use_epa: include locally calculated public TBA-derived EPA when available.
 * - side_a_number / side_b_number: display identifiers used in prediction factors.
 */
function augur_prediction_run(
    PDO $pdo,
    int $org,
    array $event,
    array $teamsA,
    array $teamsB,
    array $settings,
    array $options = []
): array {
    $settings = augur_prediction_settings($settings);
    $teamsA = array_values(array_unique(array_filter(array_map('intval', $teamsA), static fn($team) => $team > 0)));
    $teamsB = array_values(array_unique(array_filter(array_map('intval', $teamsB), static fn($team) => $team > 0)));
    if (!$teamsA || !$teamsB) throw new RuntimeException('Both alliances need at least one robot before AUGUR can predict the matchup.');

    $selectedTeams = array_values(array_unique(array_merge($teamsA, $teamsB)));
    $eventId = (int)$event['id'];
    $cutoffMatch = is_array($options['cutoff_match'] ?? null) ? $options['cutoff_match'] : null;
    $useTbaRankings = array_key_exists('use_tba_rankings', $options) ? (bool)$options['use_tba_rankings'] : true;
    $useEpa = array_key_exists('use_epa',$options)?(bool)$options['use_epa']:(array_key_exists('use_local_epa',$options)?(bool)$options['use_local_epa']:true);
    $sideANumber = $options['side_a_number'] ?? 1;
    $sideBNumber = $options['side_b_number'] ?? 2;

    $identity = [];
    $stmt = $pdo->prepare('SELECT frc_team_number,nickname FROM event_teams WHERE event_id=?');
    $stmt->execute([$eventId]);
    foreach ($stmt->fetchAll() as $row) $identity[(int)$row['frc_team_number']] = (string)($row['nickname'] ?? '');

    $matches = [];
    $teamMatchAlliance = [];
    $stmt = $pdo->prepare(
        "SELECT id,comp_level,set_number,match_number,field_id,run_number,red_score,blue_score,winning_alliance,scheduled_time,started_at,ended_at,state
         FROM matches
         WHERE organization_id=? AND event_id=? AND comp_level='qm'
         ORDER BY match_number,field_id,run_number"
    );
    $stmt->execute([$org, $eventId]);
    foreach ($stmt->fetchAll() as $row) {
        if ($cutoffMatch && !augur_prediction_match_is_prior($row, $cutoffMatch)) continue;
        $id = (int)$row['id'];
        $matches[$id] = $row + ['red' => [], 'blue' => []];
    }

    if ($matches) {
        $ids = array_keys($matches);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT match_id,frc_team_number,alliance FROM match_teams WHERE match_id IN ($placeholders)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $matchId = (int)$row['match_id'];
            $team = (int)$row['frc_team_number'];
            $color = strtolower((string)$row['alliance']);
            if (isset($matches[$matchId][$color])) $matches[$matchId][$color][] = $team;
            $teamMatchAlliance[$team][$matchId] = $color;
        }
    }

    $sessionRuns = [];
    if ($matches) {
        $ids = array_keys($matches);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $args = array_merge([$org, $eventId], $ids);
        $stmt = $pdo->prepare(
            "SELECT ss.match_id,ss.match_run_number,ss.frc_team_number,COUNT(*) c
             FROM scout_sessions ss
             WHERE ss.organization_id=? AND ss.event_id=? AND ss.match_id IN ($placeholders)
             GROUP BY ss.match_id,ss.match_run_number,ss.frc_team_number"
        );
        $stmt->execute($args);
        foreach ($stmt->fetchAll() as $row) {
            $team = (int)$row['frc_team_number'];
            $key = $team.':'.(int)$row['match_id'];
            $sessionRuns[$key][(int)$row['match_run_number']] = true;
        }
    }

    $actionRuns = [];
    if ($matches) {
        $ids = array_keys($matches);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $args = array_merge([$org, $eventId], $ids);
        $stmt = $pdo->prepare(
            "SELECT sa.match_id,sa.match_run_number,sa.frc_team_number,
                    COALESCE(SUM(sa.points),0) points,
                    SUM(CASE WHEN sa.action_type='defense' AND sa.result='Success' THEN 1 ELSE 0 END) defense_actions,
                    SUM(CASE WHEN sa.action_type='offense' THEN 1 ELSE 0 END) offense_attempts,
                    SUM(CASE WHEN sa.action_type='offense' AND sa.result='Success' THEN 1 ELSE 0 END) offense_successes
             FROM scouting_actions sa
             WHERE sa.organization_id=? AND sa.event_id=? AND sa.deleted_at IS NULL AND sa.match_id IN ($placeholders)
             GROUP BY sa.match_id,sa.match_run_number,sa.frc_team_number"
        );
        $stmt->execute($args);
        foreach ($stmt->fetchAll() as $row) {
            $team = (int)$row['frc_team_number'];
            $key = $team.':'.(int)$row['match_id'];
            $actionRuns[$key][(int)$row['match_run_number']] = [
                'points' => (float)$row['points'],
                'defense' => (int)$row['defense_actions'],
                'attempts' => (int)$row['offense_attempts'],
                'successes' => (int)$row['offense_successes'],
            ];
        }
    }

    $picked = augur_prediction_pick_active_run_observations($actionRuns, $sessionRuns);

    $observations = [];
    $obsByTeamMatch = [];
    foreach ($teamMatchAlliance as $team => $membership) {
        foreach ($membership as $matchId => $color) {
            $key = $team.':'.$matchId;
            if (!isset($picked[$key])) continue;
            $agg = $picked[$key];
            $row = [
                'match_id' => $matchId,
                'match_number' => (int)$matches[$matchId]['match_number'],
                'points' => (float)$agg['points'],
                'defense' => (int)$agg['defense'],
                'attempts' => (int)$agg['attempts'],
                'successes' => (int)$agg['successes'],
            ];
            $observations[$team][] = $row;
            $obsByTeamMatch[$team][$matchId] = $row;
        }
    }
    foreach ($observations as &$rows) {
        usort($rows, static fn($a, $b) => $a['match_number'] <=> $b['match_number']);
    }
    unset($rows);

    $priorObservations = augur_prediction_prior_neptune_observations($pdo, $org, $event, $selectedTeams);
    // EPA is served from Neptune's local public archive. Prediction requests do not
    // call TBA/OPR as a fallback; missing public EPA falls back only to already
    // stored Neptune scouting and the observed event field average.
    $priorTbaOpr = [];
    $fieldAverage = augur_prediction_field_robot_average($observations);

    if ($useTbaRankings) {
        $rankingRows = alliance_rankings_for_event($event);
        $recordMap = [];
        foreach ($rankingRows['rows'] as $row) $recordMap[(int)$row['team']] = $row;
    } else {
        $recordMap = augur_prediction_record_map($matches);
    }

    $epaMap = $useEpa ? augur_epa_rating_map($pdo,$org,$event,$selectedTeams,$cutoffMatch) : [];

    $metrics = [];
    foreach ($selectedTeams as $team) {
        $metrics[$team] = augur_prediction_team_metrics(
            $team,
            $observations,
            $priorObservations,
            $obsByTeamMatch,
            $teamMatchAlliance,
            $matches,
            $recordMap,
            $epaMap,
            $priorTbaOpr,
            $fieldAverage,
            $settings
        );
    }

    $sideA = augur_prediction_alliance_summary($teamsA, $metrics, $identity, $settings);
    $sideB = augur_prediction_alliance_summary($teamsB, $metrics, $identity, $settings);
    $sideA = augur_prediction_apply_opponent($sideA, $sideB, $settings);
    $sideB = augur_prediction_apply_opponent($sideB, $sideA, $settings);

    $difference = $sideA['predicted_score'] - $sideB['predicted_score'];
    $differenceSigma = sqrt($sideA['sigma'] * $sideA['sigma'] + $sideB['sigma'] * $sideB['sigma']);
    $rawProbabilityA = $differenceSigma > 0 ? augur_prediction_normal_cdf($difference / $differenceSigma) : 0.5;
    $overallConfidence = augur_prediction_clamp(
        augur_prediction_mean([$sideA['confidence'], $sideB['confidence']]) * (($sideA['complete'] && $sideB['complete']) ? 1.0 : 0.78),
        0.0,
        100.0
    );
    // Never let a thin-data matchup claim 98% certainty. Pull probability toward
    // 50/50 in proportion to the model's actual evidence confidence.
    $calibration = augur_prediction_clamp($overallConfidence / 100.0, 0.10, 1.0);
    $probabilityA = 0.5 + ($rawProbabilityA - 0.5) * $calibration;
    $probabilityA = augur_prediction_clamp($probabilityA, 0.05, 0.95);

    $factors = [];
    $offenseEdge = $sideA['base_offense'] - $sideB['base_offense'];
    if (abs($offenseEdge) >= 2) {
        $factors[] = [
            'kind' => 'offense',
            'alliance' => $offenseEdge > 0 ? $sideANumber : $sideBNumber,
            'value' => abs($offenseEdge),
            'text' => 'higher projected offensive baseline',
        ];
    }
    $trendEdge = $sideA['slope'] - $sideB['slope'];
    if (abs($trendEdge) >= 0.4) {
        $factors[] = [
            'kind' => 'trend',
            'alliance' => $trendEdge > 0 ? $sideANumber : $sideBNumber,
            'value' => abs($trendEdge),
            'text' => 'stronger recent scoring trend',
        ];
    }
    $defenseEdge = $sideA['defense_estimate'] - $sideB['defense_estimate'];
    if (abs($defenseEdge) >= 1.0) {
        $factors[] = [
            'kind' => 'defense',
            'alliance' => $defenseEdge > 0 ? $sideANumber : $sideBNumber,
            'value' => abs($defenseEdge),
            'text' => 'stronger opponent suppression estimate',
        ];
    }
    $consistencyEdge = $sideB['volatility'] - $sideA['volatility'];
    if (abs($consistencyEdge) >= 1.5) {
        $factors[] = [
            'kind' => 'consistency',
            'alliance' => $consistencyEdge > 0 ? $sideANumber : $sideBNumber,
            'value' => abs($consistencyEdge),
            'text' => 'more consistent scoring',
        ];
    }

    $dataMode = $cutoffMatch ? 'pre-match' : 'event-to-date';
    $recordSource = $useTbaRankings ? 'TBA qualification record' : 'qualification record from matches before the target match';
    $epaText=$useEpa?'TBA-derived EPA baseline when available':'EPA baseline disabled';

    return [
        'settings' => $settings,
        'confidence' => round($overallConfidence, 1),
        'a' => $sideA + ['win_probability' => round($probabilityA * 100, 1)],
        'b' => $sideB + ['win_probability' => round((1.0 - $probabilityA) * 100, 1)],
        'factors' => $factors,
        'method' => [
            'name' => 'AUGUR matchup model v5',
            'description'=>'Shared robot-level model using TBA-derived EPA as the independent baseline and blending Neptune current-event scouting, recent form, stabilized trend, defense/suppression, qualification form, volatility and fallback evidence when available.',
            'defense_note' => 'Opponent suppression is observational: it measures whether opponents scored above or below their own baseline while this robot was on the field. Alliance-partner effects can still influence it.',
            'data_mode' => $dataMode,
            'record_source' => $recordSource,
            'epa_used'=>$useEpa&&!empty($epaMap),
            'local_epa_used'=>$useEpa&&!empty($epaMap),
        ],
    ];
}
