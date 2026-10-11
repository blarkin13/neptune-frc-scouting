<?php
require_once dirname(__DIR__).'/_event_selection.php';
require_once __DIR__.'/_scoring_source.php';
function neptune_event_robot_stats(PDO $pdo,int $org,int $eventId): array {
    $stats=[];
    $s=$pdo->prepare('SELECT frc_team_number,nickname FROM event_teams WHERE event_id=? ORDER BY frc_team_number');$s->execute([$eventId]);
    foreach($s->fetchAll() as $t){$n=(int)$t['frc_team_number'];$stats[$n]=['team'=>$n,'nickname'=>$t['nickname']??'', 'matches'=>0,'points'=>0.0,'ppm'=>0.0,'successes'=>0,'failures'=>0,'success_rate'=>0.0,'defense'=>0,'defense_per_match'=>0.0,'cycle_time'=>null,'actions'=>0,'pit_status'=>null,'pit_data'=>[]];}
    $s=$pdo->prepare("SELECT frc_team_number,match_id,match_run_number,action_type,result,points,match_time_sec FROM scouting_actions WHERE organization_id=? AND event_id=? AND deleted_at IS NULL ORDER BY frc_team_number,match_id,match_run_number,match_time_sec,id");$s->execute([$org,$eventId]);
    $seen=[];$scoutedTeamMatches=[];$scoredTeamMatches=[];$scoreTimes=[];
    foreach($s->fetchAll() as $r){
        $n=(int)$r['frc_team_number'];if(!isset($stats[$n]))$stats[$n]=['team'=>$n,'nickname'=>'','matches'=>0,'points'=>0.0,'ppm'=>0.0,'successes'=>0,'failures'=>0,'success_rate'=>0.0,'defense'=>0,'defense_per_match'=>0.0,'cycle_time'=>null,'actions'=>0,'pit_status'=>null,'pit_data'=>[]];
        $key=$n.':'.$r['match_id'].':'.$r['match_run_number'];$seen[$key]=true;$scoutedTeamMatches[$n][(int)$r['match_id']]=true;
        if((($r['action_type']??'')==='offense'&&in_array($r['result'],['Success','Failure'],true))||(float)$r['points']!=0.0)$scoredTeamMatches[$n][(int)$r['match_id']]=true;$stats[$n]['actions']++;$stats[$n]['points']+=(float)$r['points'];
        if(($r['action_type']??'')==='defense'&&($r['result']??'')==='Success')$stats[$n]['defense']++;
        if(($r['action_type']??'')==='offense'&&in_array($r['result'],['Success','Failure'],true)){if($r['result']==='Success')$stats[$n]['successes']++;else$stats[$n]['failures']++;}
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
