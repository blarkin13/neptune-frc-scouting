<?php
require_once dirname(__DIR__).'/_event_selection.php';
require_once __DIR__.'/_scoring_source.php';
require_once __DIR__.'/_tag_activity_source.php';
function neptune_event_robot_stats(PDO $pdo,int $org,int $eventId): array {
    $stats=[];
    $s=$pdo->prepare('SELECT frc_team_number,nickname FROM event_teams WHERE event_id=? ORDER BY frc_team_number');$s->execute([$eventId]);
    foreach($s->fetchAll() as $t){$n=(int)$t['frc_team_number'];$stats[$n]=['team'=>$n,'nickname'=>$t['nickname']??'', 'matches'=>0,'points'=>0.0,'ppm'=>0.0,'successes'=>0,'failures'=>0,'success_rate'=>0.0,'defense'=>0,'defense_per_match'=>0.0,'cycle_time'=>null,'actions'=>0,'pit_status'=>null,'pit_data'=>[]];}
    $s=$pdo->prepare("SELECT a.frc_team_number,a.match_id,a.match_run_number,a.action_type,a.result,a.points,a.match_time_sec,COALESCE(a.created_by,ss.user_id) scout_uid,ss.scout_name FROM scouting_actions a LEFT JOIN scout_sessions ss ON ss.id=a.scout_session_id AND ss.organization_id=a.organization_id WHERE a.organization_id=? AND a.event_id=? AND a.deleted_at IS NULL ORDER BY a.frc_team_number,a.match_id,a.match_run_number,a.match_time_sec,a.id");$s->execute([$org,$eventId]);
    $actionRows=$s->fetchAll();
    // When several scouts covered the same robot in the same match, each scout's rows count
    // with share weight/Σweights (scout accuracy weights; 1 when never computed). One scout
    // per robot-match keeps a factor of exactly 1. Without this, two scouts doubled the points.
    require_once __DIR__.'/_scout_accuracy.php';
    $scoutWeights=neptune_scout_weights($pdo,$org,$eventId);$keyScouts=[];
    foreach($actionRows as $r){$keyScouts[$r['frc_team_number'].':'.$r['match_id'].':'.$r['match_run_number']][nsa_scout_key($r['scout_uid'],$r['scout_name'])]=true;}
    $shareOf=static function(string $key,string $scout) use($keyScouts,$scoutWeights):float{
        $scouts=$keyScouts[$key]??[];if(count($scouts)<2)return 1.0;
        $total=0.0;foreach($scouts as $sk=>$_)$total+=$scoutWeights[$sk]??1.0;
        return $total>0?($scoutWeights[$scout]??1.0)/$total:1.0/count($scouts);
    };
    $seen=[];$scoutedTeamMatches=[];$scoredTeamMatches=[];$scoreTimes=[];
    foreach($actionRows as $r){
        $n=(int)$r['frc_team_number'];if(!isset($stats[$n]))$stats[$n]=['team'=>$n,'nickname'=>'','matches'=>0,'points'=>0.0,'ppm'=>0.0,'successes'=>0,'failures'=>0,'success_rate'=>0.0,'defense'=>0,'defense_per_match'=>0.0,'cycle_time'=>null,'actions'=>0,'pit_status'=>null,'pit_data'=>[]];
        $key=$n.':'.$r['match_id'].':'.$r['match_run_number'];$seen[$key]=true;$share=$shareOf($key,nsa_scout_key($r['scout_uid'],$r['scout_name']));$scoutedTeamMatches[$n][(int)$r['match_id']]=true;
        if((($r['action_type']??'')==='offense'&&in_array($r['result'],['Success','Failure'],true))||(float)$r['points']!=0.0)$scoredTeamMatches[$n][(int)$r['match_id']]=true;$stats[$n]['actions']++;$stats[$n]['points']+=(float)$r['points']*$share;
        if(($r['action_type']??'')==='defense'&&($r['result']??'')==='Success')$stats[$n]['defense']+=$share;
        if(($r['action_type']??'')==='offense'&&in_array($r['result'],['Success','Failure'],true)){if($r['result']==='Success')$stats[$n]['successes']+=$share;else$stats[$n]['failures']+=$share;}
        if(($r['result']??'')==='Success'&&(float)$r['points']>0&&$r['match_time_sec']!==null)$scoreTimes[$key][]=(int)$r['match_time_sec'];
    }
    foreach(array_keys($seen) as $key){$n=(int)explode(':',$key,2)[0];$stats[$n]['matches']++;}
    // Preserve the genuine scouting averages before augmenting unavailable matches.
    foreach($stats as &$x){
        $x['scouted_matches']=$x['matches'];
        $x['scouted_points']=$x['points'];
        $x['scouted_ppm']=$x['matches']?$x['points']/$x['matches']:0.0;
        $x['tag_estimated_matches']=0;
        $x['scouted_offense_matches']=count($scoredTeamMatches[(int)$x['team']]??[]);
        $x['scoring_matches']=$x['scouted_offense_matches'];
    }
    unset($x);
    foreach(neptune_scoring_tag_match_points($pdo,$org,$eventId) as $n=>$tagMatches){
        if(!isset($stats[$n])) continue;
        foreach($tagMatches as $matchId=>$row){
            if(isset($scoredTeamMatches[$n][$matchId])) continue;
            $stats[$n]['points']+=(float)$row['points'];
            if(!isset($scoutedTeamMatches[$n][$matchId]))$stats[$n]['matches']++;
            $stats[$n]['tag_estimated_matches']++;
            $stats[$n]['scoring_matches']++;
        }
    }
    // Only fill absent activity evidence, never invent traditional action rows.
    $activity=neptune_tag_activity_matches($pdo,$org,$eventId);
    foreach($stats as $n=>&$x){
        $x['tag_observation_matches']=0;
        $x['tag_activity_signals']=0;
        $x['tag_activity_matches']=0;
        $x['tag_defense_matches']=0;
        $x['tag_defense_known_matches']=0;
        $x['tag_defense_mixed_matches']=0;
        foreach($activity[$n]??[] as $matchId=>$evidence){
            $x['tag_observation_matches']++;
            // A traditional action in this robot-match takes priority for activity.
            if(!isset($scoutedTeamMatches[$n][$matchId])){
                $x['tag_activity_signals']+=(int)$evidence['activity_signal_count'];
                $x['tag_activity_matches']++;
            }
            if(!isset($scoutedTeamMatches[$n][$matchId])){
                $status=(string)$evidence['defense_status'];
                if($status!=='unknown')$x['tag_defense_known_matches']++;
                if($status==='attempted'||$status==='observed')$x['tag_defense_matches']++;
                if($status==='mixed')$x['tag_defense_mixed_matches']++;
            }
        }
        $x['activity_display_source']=$x['actions']>0?'traditional':
            ($x['tag_activity_matches']>0?'tag':'none');
        $x['defense_display_source']=$x['scouted_matches']>0?'traditional':
            ($x['tag_defense_known_matches']>0?'tag':'none');
        $x['tag_defense_match_rate']=$x['tag_defense_known_matches']>0?
            $x['tag_defense_matches']/$x['tag_defense_known_matches']:null;
    }
    unset($x);
    foreach($stats as &$x){
        $x['has_scoring_data']=$x['scoring_matches']>0;
        $x['points_source']=$x['tag_estimated_matches']>0
            ?($x['scouted_matches']>0?'mixed':'tag_estimate')
            :'traditional';
    }
    unset($x);
    $cycles=[];foreach($scoreTimes as $key=>$times){sort($times);$n=(int)explode(':',$key,2)[0];for($i=1;$i<count($times);$i++){if($times[$i]>$times[$i-1])$cycles[$n][]=$times[$i]-$times[$i-1];}}
    foreach($stats as $n=>&$x){$x['ppm']=$x['scoring_matches']?$x['points']/$x['scoring_matches']:0;$tries=$x['successes']+$x['failures'];$x['success_rate']=$tries?($x['successes']/$tries*100):0;$x['defense_per_match']=$x['scouted_matches']?$x['defense']/$x['scouted_matches']:0;if(!empty($cycles[$n]))$x['cycle_time']=array_sum($cycles[$n])/count($cycles[$n]);}unset($x);
    $s=$pdo->prepare('SELECT frc_team_number,status,data_json FROM pit_scouting WHERE organization_id=? AND event_id=?');$s->execute([$org,$eventId]);foreach($s->fetchAll() as $p){$n=(int)$p['frc_team_number'];if(!isset($stats[$n]))continue;$stats[$n]['pit_status']=$p['status'];$d=json_decode($p['data_json'],true);$stats[$n]['pit_data']=is_array($d)?$d:[];}
    ksort($stats);return $stats;
}
function neptune_event_list(PDO $pdo,int $org): array {return neptune_event_selector_rows($pdo,$org,(int)($_GET['event_id']??0),neptune_selector_show_history());}
