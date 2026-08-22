<?php
function neptune_event_robot_stats(PDO $pdo,int $org,int $eventId): array {
    $stats=[];
    $s=$pdo->prepare('SELECT frc_team_number,nickname FROM event_teams WHERE event_id=? ORDER BY frc_team_number');$s->execute([$eventId]);
    foreach($s->fetchAll() as $t){$n=(int)$t['frc_team_number'];$stats[$n]=['team'=>$n,'nickname'=>$t['nickname']??'', 'matches'=>0,'points'=>0.0,'ppm'=>0.0,'successes'=>0,'failures'=>0,'success_rate'=>0.0,'defense'=>0,'defense_per_match'=>0.0,'cycle_time'=>null,'actions'=>0,'pit_status'=>null,'pit_data'=>[]];}
    $s=$pdo->prepare("SELECT frc_team_number,match_id,match_run_number,action_type,result,points,match_time_sec FROM scouting_actions WHERE organization_id=? AND event_id=? AND deleted_at IS NULL ORDER BY frc_team_number,match_id,match_run_number,match_time_sec,id");$s->execute([$org,$eventId]);
    $seen=[];$scoreTimes=[];
    foreach($s->fetchAll() as $r){
        $n=(int)$r['frc_team_number'];if(!isset($stats[$n]))$stats[$n]=['team'=>$n,'nickname'=>'','matches'=>0,'points'=>0.0,'ppm'=>0.0,'successes'=>0,'failures'=>0,'success_rate'=>0.0,'defense'=>0,'defense_per_match'=>0.0,'cycle_time'=>null,'actions'=>0,'pit_status'=>null,'pit_data'=>[]];
        $key=$n.':'.$r['match_id'].':'.$r['match_run_number'];$seen[$key]=true;$stats[$n]['actions']++;$stats[$n]['points']+=(float)$r['points'];
        if(($r['action_type']??'')==='defense'&&($r['result']??'')==='Success')$stats[$n]['defense']++;
        if(($r['action_type']??'')==='offense'&&in_array($r['result'],['Success','Failure'],true)){if($r['result']==='Success')$stats[$n]['successes']++;else$stats[$n]['failures']++;}
        if(($r['result']??'')==='Success'&&(float)$r['points']>0&&$r['match_time_sec']!==null)$scoreTimes[$key][]=(int)$r['match_time_sec'];
    }
    foreach(array_keys($seen) as $key){$n=(int)explode(':',$key,2)[0];$stats[$n]['matches']++;}
    $cycles=[];foreach($scoreTimes as $key=>$times){sort($times);$n=(int)explode(':',$key,2)[0];for($i=1;$i<count($times);$i++){if($times[$i]>$times[$i-1])$cycles[$n][]=$times[$i]-$times[$i-1];}}
    foreach($stats as $n=>&$x){$x['ppm']=$x['matches']?$x['points']/$x['matches']:0;$tries=$x['successes']+$x['failures'];$x['success_rate']=$tries?($x['successes']/$tries*100):0;$x['defense_per_match']=$x['matches']?$x['defense']/$x['matches']:0;if(!empty($cycles[$n]))$x['cycle_time']=array_sum($cycles[$n])/count($cycles[$n]);}unset($x);
    $s=$pdo->prepare('SELECT frc_team_number,status,data_json FROM pit_scouting WHERE organization_id=? AND event_id=?');$s->execute([$org,$eventId]);foreach($s->fetchAll() as $p){$n=(int)$p['frc_team_number'];if(!isset($stats[$n]))continue;$stats[$n]['pit_status']=$p['status'];$d=json_decode($p['data_json'],true);$stats[$n]['pit_data']=is_array($d)?$d:[];}
    ksort($stats);return $stats;
}
function neptune_event_list(PDO $pdo,int $org): array {$s=$pdo->prepare("SELECT e.*,g.name game_name FROM events e JOIN games g ON g.id=e.game_id WHERE e.organization_id=? ORDER BY e.is_current DESC,COALESCE(e.start_date,'1900-01-01') DESC,e.id DESC");$s->execute([$org]);return $s->fetchAll();}
