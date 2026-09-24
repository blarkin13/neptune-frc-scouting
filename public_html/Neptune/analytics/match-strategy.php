<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
require_once __DIR__.'/_alliance_helpers.php';
require_once __DIR__.'/_augur_prediction_model.php';

$u=require_login();
$org=(int)$u['organization_id'];
$canEdit=in_array((string)$u['role'],['owner','admin','strategy'],true);

function strategy_table_exists(PDO $pdo): bool {
    static $exists=null;
    if($exists!==null) return $exists;
    $s=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='match_strategies'");
    $exists=((int)$s->fetchColumn())>0;
    return $exists;
}
function strategy_trim(mixed $value,int $max=4000): string {
    $v=trim((string)$value);
    return function_exists('mb_substr')?mb_substr($v,0,$max):substr($v,0,$max);
}
function strategy_json_array(?string $json): array {
    $x=json_decode((string)$json,true);
    return is_array($x)?$x:[];
}
function strategy_confidence(array $r): array {
    $matches=(int)($r['matches']??0);
    $pit=(string)($r['pit_status']??'');
    if($matches>=5 && $pit==='complete') return ['High','high'];
    if($matches>=3 || ($matches>=2 && $pit==='complete')) return ['Medium','medium'];
    return ['Low','low'];
}
function strategy_role_suggestions(array $alliance,array $stats,int $minMatches=2): array {
    $allNumbers=array_map(static fn($t)=>(int)$t['frc_team_number'],$alliance);
    $eligible=array_values(array_filter($allNumbers,static fn(int $n)=>((int)($stats[$n]['matches']??0)) >= $minMatches));
    usort($eligible,static function(int $a,int $b) use($stats): int {
        return (($stats[$b]['ppm']??0)<=>($stats[$a]['ppm']??0));
    });
    $roles=array_fill_keys($allNumbers,null);
    if(isset($eligible[0])) $roles[$eligible[0]]='Primary scorer';
    if(isset($eligible[1])) $roles[$eligible[1]]='Secondary scorer';
    if(isset($eligible[2])){
        $n=$eligible[2];
        $def=(float)($stats[$n]['defense_per_match']??0);
        $ppm=(float)($stats[$n]['ppm']??0);
        $top=(float)($stats[$eligible[0]]['ppm']??0);
        $roles[$n]=$def>=0.75&&($top<=0||$ppm<=$top*0.8)?'Defense':'Feeder / support';
    }
    return $roles;
}
function strategy_common_label(array $counts): string {
    if(!$counts) return '—';
    arsort($counts);
    return (string)array_key_first($counts);
}
function strategy_first_nonempty(array ...$sets): string {
    foreach($sets as $set){
        foreach($set as $v){
            if(is_array($v)) $v=implode(', ',array_filter(array_map('strval',$v),static fn($x)=>trim($x)!==''));
            $v=trim((string)$v);
            if($v!=='') return $v;
        }
    }
    return '';
}

function strategy_comp_rank(string $level): int {
    return match(strtolower($level)){
        'qm'=>10,'ef'=>20,'qf'=>30,'sf'=>40,'f'=>50,'legacy'=>5,default=>15
    };
}
function strategy_match_is_prior(array $candidate,array $target): bool {
    if((int)($candidate['id']??0)===(int)($target['id']??0)) return false;

    $candidateLevel=strtolower((string)($candidate['comp_level']??''));
    $targetLevel=strtolower((string)($target['comp_level']??''));

    // For qualification backtesting, "prior" means a lower qualification
    // number. This keeps counts stable even if a rerun or imported timestamp is
    // strange.
    if($candidateLevel==='qm'&&$targetLevel==='qm'){
        return (int)($candidate['match_number']??0)<(int)($target['match_number']??0);
    }

    // Competition phase order is stronger than imported timestamps. In
    // particular, every qualification match is prior to any playoff match.
    if($candidateLevel!==$targetLevel){
        $candidateRank=strategy_comp_rank($candidateLevel);
        $targetRank=strategy_comp_rank($targetLevel);
        if($candidateRank!==$targetRank) return $candidateRank<$targetRank;
    }

    $targetCutoff=(string)($target['started_at']??'');
    if($targetCutoff==='') $targetCutoff=(string)($target['scheduled_time']??'');
    if($targetCutoff==='') $targetCutoff=(string)($target['ended_at']??'');

    if($targetCutoff!==''){
        $targetTs=strtotime($targetCutoff);
        if($targetTs!==false){
            $candidateEnd=(string)($candidate['ended_at']??'');
            if($candidateEnd!==''){
                $endTs=strtotime($candidateEnd);
                if($endTs!==false) return $endTs < $targetTs;
            }
            if(($candidate['state']??'')==='ended'){
                $candidateStart=(string)($candidate['started_at']??'');
                if($candidateStart==='') $candidateStart=(string)($candidate['scheduled_time']??'');
                if($candidateStart!==''){
                    $startTs=strtotime($candidateStart);
                    if($startTs!==false) return $startTs < $targetTs;
                }
            }
        }
    }

    $a=[strategy_comp_rank((string)($candidate['comp_level']??'')),(int)($candidate['set_number']??1),(int)($candidate['match_number']??0),(int)($candidate['field_id']??1)];
    $b=[strategy_comp_rank((string)($target['comp_level']??'')),(int)($target['set_number']??1),(int)($target['match_number']??0),(int)($target['field_id']??1)];
    for($i=0;$i<count($a);$i++){
        if($a[$i]===$b[$i]) continue;
        return $a[$i]<$b[$i];
    }
    return false;
}
function strategy_prematch_robot_stats(PDO $pdo,int $org,int $eventId,array $targetMatch): array {
    $stats=[];
    $s=$pdo->prepare('SELECT frc_team_number,nickname FROM event_teams WHERE event_id=? ORDER BY frc_team_number');
    $s->execute([$eventId]);
    foreach($s->fetchAll() as $t){
        $n=(int)$t['frc_team_number'];
        $stats[$n]=['team'=>$n,'nickname'=>$t['nickname']??'','matches'=>0,'points'=>0.0,'ppm'=>0.0,'successes'=>0,'failures'=>0,'success_rate'=>0.0,'defense'=>0,'defense_per_match'=>0.0,'cycle_time'=>null,'actions'=>0,'pit_status'=>null,'pit_data'=>[]];
    }

    $s=$pdo->prepare("SELECT sa.frc_team_number,sa.match_id,sa.match_run_number,sa.phase,sa.action_code,sa.action_name,sa.action_type,sa.location,sa.result,sa.points,sa.match_time_sec,
        m.id,m.comp_level,m.set_number,m.match_number,m.field_id,m.scheduled_time,m.started_at,m.ended_at,m.state
        FROM scouting_actions sa
        JOIN matches m ON m.id=sa.match_id AND m.organization_id=sa.organization_id
        WHERE sa.organization_id=? AND sa.event_id=? AND sa.deleted_at IS NULL
        ORDER BY sa.frc_team_number,sa.match_id,sa.match_run_number DESC,sa.match_time_sec,sa.id");
    $s->execute([$org,$eventId]);

    // A match can be re-run or re-scouted. The match table stores the current
    // run number, but imported/older scouting rows correctly keep the run that
    // was actually scouted. Pick the newest ACTIVE scouting run per robot/match
    // instead of requiring it to equal matches.run_number.
    $candidateRows=[];$latestRun=[];
    foreach($s->fetchAll() as $r){
        if(!strategy_match_is_prior($r,$targetMatch)) continue;
        $n=(int)$r['frc_team_number'];
        $matchKey=$n.':'.(int)$r['match_id'];
        $run=(int)$r['match_run_number'];
        $latestRun[$matchKey]=max($run,(int)($latestRun[$matchKey]??0));
        $candidateRows[]=$r;
    }

    $priorActions=[];$seen=[];$scoreTimes=[];
    foreach($candidateRows as $r){
        $n=(int)$r['frc_team_number'];
        $key=$n.':'.(int)$r['match_id'];
        if((int)$r['match_run_number']!==(int)($latestRun[$key]??0)) continue;
        $priorActions[]=$r;
        if(!isset($stats[$n]))$stats[$n]=['team'=>$n,'nickname'=>'','matches'=>0,'points'=>0.0,'ppm'=>0.0,'successes'=>0,'failures'=>0,'success_rate'=>0.0,'defense'=>0,'defense_per_match'=>0.0,'cycle_time'=>null,'actions'=>0,'pit_status'=>null,'pit_data'=>[]];
        $seen[$key]=true;
        $stats[$n]['actions']++;
        $stats[$n]['points']+=(float)$r['points'];
        if(($r['action_type']??'')==='defense'&&($r['result']??'')==='Success')$stats[$n]['defense']++;
        if(($r['action_type']??'')==='offense'&&in_array($r['result'],['Success','Failure'],true)){
            if($r['result']==='Success')$stats[$n]['successes']++;else$stats[$n]['failures']++;
        }
        if(($r['result']??'')==='Success'&&(float)$r['points']>0&&$r['match_time_sec']!==null)$scoreTimes[$key][]=(int)$r['match_time_sec'];
    }
    foreach(array_keys($seen) as $key){
        $n=(int)explode(':',$key,2)[0];
        $stats[$n]['matches']++;
    }
    $cycles=[];
    foreach($scoreTimes as $key=>$times){
        sort($times);
        $n=(int)explode(':',$key,2)[0];
        for($i=1;$i<count($times);$i++) if($times[$i]>$times[$i-1]) $cycles[$n][]=$times[$i]-$times[$i-1];
    }
    foreach($stats as $n=>&$x){
        $x['ppm']=$x['matches']?$x['points']/$x['matches']:0;
        $tries=$x['successes']+$x['failures'];
        $x['success_rate']=$tries?($x['successes']/$tries*100):0;
        $x['defense_per_match']=$x['matches']?$x['defense']/$x['matches']:0;
        if(!empty($cycles[$n]))$x['cycle_time']=array_sum($cycles[$n])/count($cycles[$n]);
    }unset($x);

    $s=$pdo->prepare('SELECT frc_team_number,status,data_json FROM pit_scouting WHERE organization_id=? AND event_id=?');
    $s->execute([$org,$eventId]);
    foreach($s->fetchAll() as $p){
        $n=(int)$p['frc_team_number'];
        if(!isset($stats[$n]))continue;
        $stats[$n]['pit_status']=$p['status'];
        $d=json_decode($p['data_json'],true);
        $stats[$n]['pit_data']=is_array($d)?$d:[];
    }
    ksort($stats);
    return [$stats,$priorActions];
}
function strategy_phase_intel(array $teamNumbers,array $priorActions): array {
    $phaseIntel=[];
    foreach($teamNumbers as $n){
        $phaseIntel[$n]=[
            'auton_match_points'=>[],'auton_attempts'=>0,'auton_success'=>0,'auton_actions'=>[],
            'endgame_attempts'=>0,'endgame_success'=>0,'endgame_actions'=>[],
        ];
    }
    foreach($priorActions as $a){
        $n=(int)$a['frc_team_number'];if(!isset($phaseIntel[$n]))continue;
        $label=trim((string)($a['action_name']?:$a['action_code']));
        if(($a['phase']??'')==='auton'){
            $phaseIntel[$n]['auton_match_points'][(int)$a['match_id']]=($phaseIntel[$n]['auton_match_points'][(int)$a['match_id']]??0)+(float)$a['points'];
            if(($a['action_type']??'')==='offense'&&in_array($a['result'],['Success','Failure'],true)){
                $phaseIntel[$n]['auton_attempts']++;
                if($a['result']==='Success')$phaseIntel[$n]['auton_success']++;
            }
            if($a['result']==='Success'&&$label!=='')$phaseIntel[$n]['auton_actions'][$label]=($phaseIntel[$n]['auton_actions'][$label]??0)+1;
        }
        if(($a['phase']??'')==='endgame'){
            $hay=strtolower($label.' '.($a['location']??''));
            if(preg_match('/climb|park|hang|endgame|tower|barge|stage/',$hay)){
                if(in_array($a['result'],['Success','Failure'],true)){
                    $phaseIntel[$n]['endgame_attempts']++;
                    if($a['result']==='Success')$phaseIntel[$n]['endgame_success']++;
                }
                if($label!=='')$phaseIntel[$n]['endgame_actions'][$label]=($phaseIntel[$n]['endgame_actions'][$label]??0)+1;
            }
        }
    }
    foreach($phaseIntel as $n=>&$p){
        $p['auton_match_count']=count($p['auton_match_points']);
        $p['auton_ppm']=$p['auton_match_count']?array_sum($p['auton_match_points'])/$p['auton_match_count']:null;
        $p['auton_success_rate']=$p['auton_attempts']?($p['auton_success']/$p['auton_attempts']*100):null;
        $p['auton_common']=strategy_common_label($p['auton_actions']);
        $p['endgame_rate']=$p['endgame_attempts']?($p['endgame_success']/$p['endgame_attempts']*100):null;
        $p['endgame_common']=strategy_common_label($p['endgame_actions']);
    }unset($p);
    return $phaseIntel;
}
function strategy_pit_role(array $pitData): ?string {
    $raw=$pitData['preferred_roles']??'';
    if(is_array($raw)) $raw=implode(' ',array_map('strval',$raw));
    $v=strtolower(trim((string)$raw));
    if($v==='') return null;
    if(str_contains($v,'primary')) return 'Primary scorer';
    if(str_contains($v,'secondary')) return 'Secondary scorer';
    if(str_contains($v,'defen')) return 'Defense';
    if(str_contains($v,'feed')||str_contains($v,'support')) return 'Feeder / support';
    if(str_contains($v,'flex')) return 'Flexible';
    return null;
}

$events=neptune_event_list($pdo,$org);
$eventId=(int)($_GET['event_id']??$_POST['event_id']??($events[0]['id']??0));
$predictionEvent=$eventId?alliance_event($pdo,$org,$eventId):null;
$predictionSettings=augur_prediction_settings();
$predictionSettingsStored=false;
if($predictionEvent&&alliance_schema_ready($pdo)){
    $predictionWorkspace=alliance_load_workspace($pdo,$org,$eventId,false);
    $predictionSettings=augur_prediction_settings($predictionWorkspace['workspace']['settings']['matchup_model']??[]);
    $predictionSettingsStored=$predictionWorkspace['exists'];
}

$s=$pdo->prepare('SELECT id,frc_team_number,nickname,display_name FROM teams WHERE organization_id=? AND active=1 ORDER BY frc_team_number');
$s->execute([$org]);
$myTeams=$s->fetchAll();
$teamNumber=(int)($_GET['team']??$_POST['team']??($myTeams[0]['frc_team_number']??0));

$matchesForTeam=[];
if($eventId&&$teamNumber){
    $s=$pdo->prepare("SELECT m.* FROM matches m JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=? WHERE m.organization_id=? AND m.event_id=? ORDER BY FIELD(m.comp_level,'qm','ef','qf','sf','f','legacy'),m.set_number,m.match_number,m.field_id");
    $s->execute([$teamNumber,$org,$eventId]);
    $matchesForTeam=$s->fetchAll();
}

$matchId=(int)($_GET['match_id']??$_POST['match_id']??0);
$validMatchIds=array_map(static fn($m)=>(int)$m['id'],$matchesForTeam);
if(!$matchId || !in_array($matchId,$validMatchIds,true)){
    $chosen=null;
    foreach($matchesForTeam as $m){
        if(in_array((string)($m['state']??''),['running','paused','ready'],true)){ $chosen=$m; break; }
    }
    if(!$chosen){
        $future=array_values(array_filter($matchesForTeam,static function(array $m): bool {
            if(($m['state']??'scheduled')==='ended') return false;
            $when=(string)($m['scheduled_time']??'');
            if($when==='') return false;
            $ts=strtotime($when);
            return $ts!==false && $ts>=time()-900;
        }));
        usort($future,static function(array $a,array $b): int {
            return strcmp((string)($a['scheduled_time']??''),(string)($b['scheduled_time']??''));
        });
        $chosen=$future[0]??null;
    }
    if(!$chosen){
        foreach($matchesForTeam as $m){if(($m['state']??'scheduled')!=='ended'){$chosen=$m;break;}}
    }
    if(!$chosen && $matchesForTeam) $chosen=$matchesForTeam[count($matchesForTeam)-1];
    $matchId=(int)($chosen['id']??0);
}

$match=null;
$matchTeams=[];
$ourAlliance='';
if($matchId){
    $s=$pdo->prepare("SELECT m.*,e.name event_name,g.name game_name FROM matches m JOIN events e ON e.id=m.event_id JOIN games g ON g.id=m.game_id WHERE m.id=? AND m.organization_id=? AND m.event_id=? LIMIT 1");
    $s->execute([$matchId,$org,$eventId]);
    $match=$s->fetch()?:null;
    if($match){
        $s=$pdo->prepare("SELECT mt.alliance,mt.station,mt.frc_team_number,COALESCE(et.nickname,'') nickname FROM match_teams mt LEFT JOIN event_teams et ON et.event_id=? AND et.frc_team_number=mt.frc_team_number WHERE mt.match_id=? ORDER BY FIELD(mt.alliance,'Red','Blue'),mt.station");
        $s->execute([$eventId,$matchId]);
        $matchTeams=$s->fetchAll();
        foreach($matchTeams as $t){if((int)$t['frc_team_number']===$teamNumber){$ourAlliance=(string)$t['alliance'];break;}}
    }
}

$stats=[];$priorActions=[];
if($eventId){
    if($match){
        [$stats,$priorActions]=strategy_prematch_robot_stats($pdo,$org,$eventId,$match);
    }else{
        $stats=neptune_event_robot_stats($pdo,$org,$eventId);
    }
}
$teamNumbers=array_values(array_unique(array_map(static fn($t)=>(int)$t['frc_team_number'],$matchTeams)));
$pitByTeam=[];$preByTeam=[];
if($teamNumbers){
    $ph=implode(',',array_fill(0,count($teamNumbers),'?'));
    $args=array_merge([$org,$eventId],$teamNumbers);
    $s=$pdo->prepare("SELECT frc_team_number,status,data_json,notes FROM pit_scouting WHERE organization_id=? AND event_id=? AND frc_team_number IN ($ph)");
    $s->execute($args);
    foreach($s->fetchAll() as $row){$row['data']=strategy_json_array($row['data_json']??'');$pitByTeam[(int)$row['frc_team_number']]=$row;}
    $s=$pdo->prepare("SELECT frc_team_number,status,data_json,notes FROM pre_scouting WHERE organization_id=? AND event_id=? AND frc_team_number IN ($ph)");
    $s->execute($args);
    foreach($s->fetchAll() as $row){$row['data']=strategy_json_array($row['data_json']??'');$preByTeam[(int)$row['frc_team_number']]=$row;}
}
$phaseIntel=strategy_phase_intel($teamNumbers,$priorActions);

$allianceTeams=[];$opponentTeams=[];
if($ourAlliance){
    foreach($matchTeams as $t){
        if($t['alliance']===$ourAlliance)$allianceTeams[]=$t;else $opponentTeams[]=$t;
    }
}
$opponentAlliance=$ourAlliance==='Red'?'Blue':($ourAlliance==='Blue'?'Red':'');

$suggestedRoles=strategy_role_suggestions($allianceTeams,$stats);

$existingStrategy=[];
$strategyRow=null;
$tableReady=strategy_table_exists($pdo);
if($tableReady&&$matchId&&$teamNumber){
    $s=$pdo->prepare('SELECT * FROM match_strategies WHERE organization_id=? AND match_id=? AND frc_team_number=? LIMIT 1');
    $s->execute([$org,$matchId,$teamNumber]);
    $strategyRow=$s->fetch()?:null;
    if($strategyRow)$existingStrategy=strategy_json_array($strategyRow['strategy_json']??'');
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_strategy'){
    try{
        if(!$canEdit) access_denied('Your Neptune role can view match strategy but cannot edit it.');
        verify_csrf();
        if(!$tableReady) throw new RuntimeException('Match Strategy storage is not installed yet. Run the included SQL migration first.');
        if(!$match || !$ourAlliance) throw new RuntimeException('Choose a valid match for one of your teams.');

        $allowedRoles=['Primary scorer','Secondary scorer','Defense','Feeder / support','Flexible'];
        $roles=[];$phasePlan=[];
        foreach($allianceTeams as $t){
            $n=(int)$t['frc_team_number'];
            $role=(string)($_POST['role'][$n]??($suggestedRoles[$n]??'Flexible'));
            if(!in_array($role,$allowedRoles,true))$role='Flexible';
            $roles[(string)$n]=$role;
            $phasePlan[(string)$n]=[
                'auton'=>strategy_trim($_POST['phase'][$n]['auton']??'',1200),
                'teleop'=>strategy_trim($_POST['phase'][$n]['teleop']??'',1200),
                'endgame'=>strategy_trim($_POST['phase'][$n]['endgame']??'',1200),
            ];
        }
        $oppNums=array_map(static fn($t)=>(int)$t['frc_team_number'],$opponentTeams);
        $defTarget=(int)($_POST['defense_target']??0);
        if($defTarget&&!in_array($defTarget,$oppNums,true))$defTarget=0;

        $payload=[
            'version'=>1,
            'roles'=>$roles,
            'phase_plan'=>$phasePlan,
            'defense_target'=>$defTarget?:null,
            'objectives'=>strategy_trim($_POST['objectives']??'',5000),
            'auton_notes'=>strategy_trim($_POST['auton_notes']??'',5000),
            'drive_notes'=>strategy_trim($_POST['drive_notes']??'',5000),
            'reviewed_with_drive_coach'=>isset($_POST['reviewed_with_drive_coach']),
            'auton_paths_confirmed'=>isset($_POST['auton_paths_confirmed']),
            'endgame_confirmed'=>isset($_POST['endgame_confirmed']),
        ];
        $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($json===false) throw new RuntimeException('Could not encode the strategy plan.');
        $uid=(int)($u['id']??0);$uid=$uid>0?$uid:null;
        $s=$pdo->prepare("INSERT INTO match_strategies (organization_id,event_id,match_id,frc_team_number,strategy_json,created_by,updated_by)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE event_id=VALUES(event_id),strategy_json=VALUES(strategy_json),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
        $s->execute([$org,$eventId,$matchId,$teamNumber,$json,$uid,$uid]);
        $_SESSION['strategy_flash']=['type'=>'good','message'=>'Match strategy saved.'];
        header('Location: '.base_url('analytics/match-strategy.php').'?'.http_build_query(['event_id'=>$eventId,'team'=>$teamNumber,'match_id'=>$matchId]));
        exit;
    }catch(Throwable $e){
        $_SESSION['strategy_flash']=['type'=>'bad','message'=>$e->getMessage()];
        header('Location: '.base_url('analytics/match-strategy.php').'?'.http_build_query(['event_id'=>$eventId,'team'=>$teamNumber,'match_id'=>$matchId]));
        exit;
    }
}

$flash=$_SESSION['strategy_flash']??null;unset($_SESSION['strategy_flash']);
$roleValues=$existingStrategy['roles']??[];
$phaseValues=$existingStrategy['phase_plan']??[];
$defenseTarget=(int)($existingStrategy['defense_target']??0);
$objectives=(string)($existingStrategy['objectives']??'');
$autonNotes=(string)($existingStrategy['auton_notes']??'');
$driveNotes=(string)($existingStrategy['drive_notes']??'');
$reviewed=!empty($existingStrategy['reviewed_with_drive_coach']);
$pathsConfirmed=!empty($existingStrategy['auton_paths_confirmed']);
$endgameConfirmed=!empty($existingStrategy['endgame_confirmed']);

$projectionMinMatches=2;
$projectionCoverage=0;
$projectionAnyData=0;
foreach($matchTeams as $t){
    $n=(int)$t['frc_team_number'];
    $r=$stats[$n]??[];
    if((int)($r['matches']??0)>0)$projectionAnyData++;
    if((int)($r['matches']??0)>=$projectionMinMatches)$projectionCoverage++;
}
$projectionRobotCount=count($matchTeams);
$actualResultAvailable=$match&&($match['state']??'')==='ended'&&$match['red_score']!==null&&$match['blue_score']!==null;

$augurPrediction=null;
$currentAugurPrediction=null;
$augurPredictionError='';
$currentAugurPredictionError='';
if($match&&$predictionEvent&&$matchTeams){
    $redPredictionTeams=[];$bluePredictionTeams=[];
    foreach($matchTeams as $t){
        $n=(int)$t['frc_team_number'];
        if(($t['alliance']??'')==='Red')$redPredictionTeams[]=$n;
        elseif(($t['alliance']??'')==='Blue')$bluePredictionTeams[]=$n;
    }
    if($redPredictionTeams&&$bluePredictionTeams){
        try{
            // Historical prediction: freeze scouting/public EPA to data that was
            // available before the selected match so the result can be backtested.
            $augurPrediction=augur_prediction_run(
                $pdo,$org,$predictionEvent,$redPredictionTeams,$bluePredictionTeams,$predictionSettings,
                [
                    'cutoff_match'=>$match,
                    'use_tba_rankings'=>false,
                    'use_epa'=>true,
                    'side_a_number'=>'Red',
                    'side_b_number'=>'Blue',
                ]
            );
        }catch(Throwable $e){
            $augurPredictionError=$e->getMessage();
        }

        try{
            // Current reference: same no-cutoff Neptune EPA used by Alliance
            // Selection today. This is display-only on historical matches; it is
            // never substituted into the pre-match prediction above.
            $currentAugurPrediction=augur_prediction_run(
                $pdo,$org,$predictionEvent,$redPredictionTeams,$bluePredictionTeams,$predictionSettings,
                [
                    'use_tba_rankings'=>false,
                    'use_epa'=>true,
                    'side_a_number'=>'Red',
                    'side_b_number'=>'Blue',
                ]
            );
        }catch(Throwable $e){
            $currentAugurPredictionError=$e->getMessage();
        }
    }
}
$projectionReady=is_array($augurPrediction);
$currentProjectionReady=is_array($currentAugurPrediction);
$predictionMetricsByTeam=[];
if($projectionReady){
    foreach(['a','b'] as $side){
        foreach(($augurPrediction[$side]['teams']??[]) as $row){
            $n=(int)($row['team']??0);
            if($n>0&&is_array($row['metrics']??null))$predictionMetricsByTeam[$n]=$row['metrics'];
        }
    }
}

$pageTitle='Match Strategy';$moduleName='AUGUR';
include dirname(__DIR__).'/partials_header.php';
?>
<style>
.strategy-page{--strategy-red:#a53d3d;--strategy-blue:#315f9b}
.strategy-head-actions{display:flex;gap:8px;flex-wrap:wrap}
.strategy-filter{display:grid;grid-template-columns:minmax(260px,1.6fr) minmax(180px,.8fr) minmax(190px,.8fr) auto;gap:12px;align-items:end}
.strategy-filter label{margin-top:0}.strategy-filter button{height:42px}
.strategy-match-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;margin:18px 0 12px}
.strategy-match-head h2{margin:2px 0}.strategy-match-meta{display:flex;gap:7px;flex-wrap:wrap}
.strategy-matchup{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,.46fr) minmax(0,1fr);gap:14px;align-items:stretch}
.strategy-alliance{border:1px solid var(--line);border-radius:10px;background:var(--panel);overflow:hidden;box-shadow:0 8px 22px var(--shadow)}
.strategy-alliance-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px 14px;border-bottom:1px solid var(--line);background:var(--panel2)}
.strategy-alliance.red .strategy-alliance-head{border-top:3px solid var(--strategy-red)}.strategy-alliance.blue .strategy-alliance-head{border-top:3px solid var(--strategy-blue)}
.strategy-alliance-head b{text-transform:uppercase;letter-spacing:.06em;font-size:.8rem}
.strategy-team-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}
.strategy-team{appearance:none;display:block;width:100%;border:0;border-right:1px solid var(--line);background:transparent;color:var(--text);padding:14px 12px;text-align:left;cursor:pointer;min-width:0;overflow:hidden}
.strategy-team:last-child{border-right:0}.strategy-team:hover{background:var(--panel2)}.strategy-team.ours{box-shadow:inset 0 0 0 2px var(--accent)}
.strategy-team strong{display:block;font-size:1.22rem;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.strategy-team .team-name{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:4px;font-size:.74rem;color:var(--muted)}
.strategy-mini-stats{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:11px}.strategy-mini-stats span{font-size:.65rem;color:var(--muted)}.strategy-mini-stats b{display:block;font-size:.82rem;color:var(--text);margin-top:1px}
.strategy-model-fallback{margin-top:9px;padding-top:8px;border-top:1px solid var(--line);font-size:.62rem;line-height:1.3;color:var(--muted)}.strategy-model-fallback i{margin-right:4px;color:var(--accent)}
.strategy-projection{display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;padding:16px;border:1px solid var(--line);border-radius:10px;background:var(--panel2);min-width:0}.strategy-projection.insufficient{border-left:4px solid var(--warn)}
.strategy-projection .vs{font-weight:900;font-size:1.15rem;margin:8px 0}.strategy-projection .proj-values{display:flex;gap:14px;align-items:center}.strategy-projection .proj-values b{font-size:1.4rem}.strategy-projection small{display:block;color:var(--muted);margin-top:8px;line-height:1.35}.strategy-projection-status{font-weight:900;font-size:1.05rem;line-height:1.25;margin-top:7px}.strategy-raw-projection{margin-top:10px;padding-top:9px;border-top:1px solid var(--line);width:100%;font-size:.72rem;color:var(--muted)}
.strategy-projection-head{width:100%;display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.strategy-projection-head>div{text-align:left}.strategy-projection-head>div>small{margin-top:2px}.strategy-projection-actions{display:flex;align-items:center;gap:6px;flex:0 0 auto}.strategy-projection-actions .compact{width:38px;height:38px;padding:0;display:inline-flex;align-items:center;justify-content:center}.strategy-prediction-values{width:100%;justify-content:center;margin-top:8px}.strategy-prediction-side{min-width:0;display:grid;gap:2px}.strategy-prediction-side>span{font-size:.68rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em}.strategy-prediction-side.red>span{color:#d97979}.strategy-prediction-side.blue>span{color:#75a7e8}.strategy-prediction-side small{margin:0;font-size:.64rem}.strategy-prediction-confidence{margin-top:10px;font-size:.72rem;font-weight:900;color:var(--text)}.strategy-prediction-details{display:grid;grid-template-columns:1fr;gap:3px;width:100%;margin-top:10px;padding-top:9px;border-top:1px solid var(--line);font-size:.66rem;color:var(--muted);text-align:left}.strategy-actual-compare{display:flex;justify-content:space-between;gap:8px;width:100%;margin-top:10px;padding-top:9px;border-top:1px solid var(--line);font-size:.7rem}.strategy-actual-compare span{color:var(--muted)}
.prediction-info-dialog{border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--text);padding:0;width:min(520px,calc(100vw - 28px));max-height:min(82vh,720px);box-shadow:0 24px 70px rgba(0,0,0,.45)}.prediction-info-dialog::backdrop{background:rgba(0,0,0,.62)}.prediction-info-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:15px 17px;border-bottom:1px solid var(--line);background:var(--panel2)}.prediction-info-head h2{margin:0;font-size:1.05rem}.prediction-info-head p{margin:3px 0 0;color:var(--muted);font-size:.72rem}.prediction-info-body{padding:16px 18px;line-height:1.48;font-size:.8rem}.prediction-info-body p{margin:0 0 12px}.prediction-info-body ul{margin:8px 0 14px;padding-left:20px}.prediction-info-body li+li{margin-top:5px}.prediction-info-mode{display:flex;align-items:flex-start;gap:9px;padding:10px 11px;border:1px solid var(--line);border-radius:8px;background:var(--panel2);color:var(--muted);font-size:.73rem}.prediction-info-mode i{color:var(--accent);margin-top:2px}.prediction-info-mode b{color:var(--text)}
.prediction-settings-dialog{border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--text);padding:0;width:min(760px,calc(100vw - 28px));max-height:min(86vh,900px);box-shadow:0 24px 70px rgba(0,0,0,.45)}.prediction-settings-dialog::backdrop{background:rgba(0,0,0,.62)}.prediction-settings-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:16px 18px;border-bottom:1px solid var(--line);background:var(--panel2)}.prediction-settings-head h2{margin:0;font-size:1.15rem}.prediction-settings-head p{margin:3px 0 0;color:var(--muted);font-size:.74rem}.prediction-settings-body{padding:16px 18px;overflow:auto;max-height:calc(86vh - 140px)}.prediction-settings-section+ .prediction-settings-section{margin-top:18px}.prediction-settings-section h3{margin:0 0 10px;font-size:.9rem}.prediction-settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.prediction-setting{border:1px solid var(--line);border-radius:8px;background:var(--panel2);padding:10px}.prediction-setting label{display:flex;justify-content:space-between;gap:10px;margin:0 0 7px;font-size:.72rem;font-weight:800}.prediction-setting output{color:var(--accent)}.prediction-setting input[type=range]{width:100%;margin:0}.prediction-settings-note{display:flex;gap:9px;margin-top:14px;padding:10px;border:1px solid var(--line);border-radius:8px;color:var(--muted);font-size:.7rem;line-height:1.4}.prediction-settings-actions{display:flex;justify-content:space-between;gap:10px;padding:14px 18px;border-top:1px solid var(--line);background:var(--panel2)}

.strategy-section{margin-top:16px;padding:0;overflow:hidden}.strategy-section-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line);background:var(--panel2)}.strategy-section-head h2{font-size:1.02rem;margin:0}.strategy-section-head p{margin:2px 0 0;font-size:.77rem;color:var(--muted)}
.strategy-role-grid,.strategy-intel-grid,.strategy-endgame-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:14px}
.strategy-role-card,.strategy-intel-card,.strategy-endgame-card{border:1px solid var(--line);border-radius:8px;padding:12px;background:var(--panel2);min-width:0}.strategy-role-card h3,.strategy-intel-card h3,.strategy-endgame-card h3{margin:0 0 8px;font-size:1rem}
.strategy-role-suggest{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:9px;font-size:.72rem;color:var(--muted)}.strategy-why{margin:8px 0 10px;border:1px solid var(--line);border-radius:6px;background:var(--panel)}.strategy-why summary{cursor:pointer;padding:7px 9px;font-size:.72rem;font-weight:800;color:var(--accent)}.strategy-why-content{padding:0 9px 9px;font-size:.72rem;line-height:1.45;color:var(--muted)}.strategy-why-content b{color:var(--text)}
.strategy-metric-row{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-top:10px}.strategy-metric{padding:8px;border:1px solid var(--line);border-radius:6px;background:var(--panel)}.strategy-metric span{display:block;color:var(--muted);font-size:.63rem}.strategy-metric b{display:block;margin-top:2px;font-size:.84rem}
.strategy-source{margin-top:10px;padding-top:9px;border-top:1px solid var(--line)}.strategy-source span{display:block;font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:800}.strategy-source p{margin:4px 0 0;font-size:.78rem;line-height:1.4;white-space:pre-wrap}
.strategy-threat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:14px}.strategy-threat{border:1px solid var(--line);border-radius:8px;padding:13px;background:var(--panel2)}.strategy-threat:first-child{border-top:3px solid var(--accent)}.strategy-threat-head{display:flex;justify-content:space-between;gap:8px}.strategy-threat h3{margin:0}.strategy-threat-tag{font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;font-weight:800;color:var(--accent)}
.strategy-plan-wrap{overflow:auto}.strategy-plan{width:100%;border-collapse:collapse;min-width:820px}.strategy-plan th,.strategy-plan td{padding:10px;border-bottom:1px solid var(--line);vertical-align:top}.strategy-plan th{background:var(--panel2);font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;text-align:left}.strategy-plan textarea{min-height:84px;resize:vertical;margin:0}.strategy-plan .robot-cell{min-width:130px}.strategy-plan .robot-cell b{display:block;font-size:1rem}.strategy-plan .robot-cell small{color:var(--muted)}
.strategy-confidence-block{margin-top:14px}.strategy-confidence-heading{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:8px}.strategy-confidence-heading h2{margin:0;font-size:1rem}.strategy-confidence-heading p{margin:2px 0 0;color:var(--muted);font-size:.74rem}.strategy-confidence-summary{font-size:.72rem;color:var(--muted);font-weight:800}.strategy-confidence{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px}.strategy-confidence-item{border:1px solid var(--line);border-radius:7px;padding:9px;background:var(--panel)}.strategy-confidence-item b{display:block}.strategy-confidence-item small{display:block;color:var(--muted);margin-top:2px}.strategy-confidence-badge{display:inline-flex;margin-top:6px;padding:2px 7px;border-radius:999px;font-size:.62rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--line)}.strategy-confidence-badge.high{color:var(--good)}.strategy-confidence-badge.medium{color:var(--warn)}.strategy-confidence-badge.low{color:var(--bad)}
.strategy-endgame-line{display:grid;grid-template-columns:1fr;gap:6px}.strategy-endgame-line div{padding:7px 0;border-bottom:1px solid var(--line)}.strategy-endgame-line div:last-child{border-bottom:0}.strategy-endgame-line span{display:block;font-size:.65rem;color:var(--muted)}.strategy-endgame-line b{display:block;margin-top:2px;font-size:.82rem}
.strategy-notes-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:14px}.strategy-notes-grid .wide{grid-column:1/-1}.strategy-checks{display:flex;gap:10px;flex-wrap:wrap;padding:0 14px 14px}.strategy-check{display:flex;align-items:center;gap:7px;padding:9px 11px;border:1px solid var(--line);border-radius:7px;background:var(--panel2);font-size:.78rem}.strategy-check input{width:18px;height:18px;margin:0}
.strategy-savebar{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px 14px;border-top:1px solid var(--line);background:var(--panel2)}.strategy-savebar .muted{font-size:.72rem}.strategy-toast-stack{position:fixed;right:18px;top:82px;z-index:3000;display:grid;gap:8px;max-width:min(380px,calc(100vw - 32px))}.strategy-toast{padding:11px 13px;border:1px solid var(--line);border-left:4px solid var(--accent);border-radius:7px;background:var(--panel);box-shadow:0 12px 30px var(--shadow);font-size:.82rem}.strategy-toast.bad{border-left-color:var(--bad)}.strategy-toast.good{border-left-color:var(--good)}
@media(max-width:1050px){.strategy-filter{grid-template-columns:1fr 1fr}.strategy-filter button{width:max-content}.strategy-matchup{grid-template-columns:1fr}.strategy-projection{order:-1}.strategy-role-grid,.strategy-intel-grid,.strategy-threat-grid,.strategy-endgame-grid{grid-template-columns:1fr 1fr}.strategy-confidence{grid-template-columns:repeat(3,1fr)}}
@media(max-width:680px){.strategy-filter{grid-template-columns:1fr}.prediction-settings-grid{grid-template-columns:1fr}.strategy-team-list{grid-template-columns:1fr}.strategy-team{border-right:0;border-bottom:1px solid var(--line)}.strategy-team:last-child{border-bottom:0}.strategy-role-grid,.strategy-intel-grid,.strategy-threat-grid,.strategy-endgame-grid,.strategy-notes-grid{grid-template-columns:1fr}.strategy-notes-grid .wide{grid-column:auto}.strategy-confidence{grid-template-columns:1fr 1fr}.strategy-head-actions{width:100%}.strategy-head-actions .btn{flex:1}.strategy-savebar{align-items:flex-start;flex-direction:column}.strategy-savebar button{width:100%}}
</style>
<section class="module-page strategy-page">
  <header class="module-page-header">
    <div>
      <div class="module-code">AUGUR</div>
      <h1>Match Strategy</h1>
      <p>Turn scouting intelligence into a plan your alliance can use.</p>
    </div>
    <div class="strategy-head-actions">
      <a class="btn secondary" href="<?=e(base_url('analytics/robots.php').'?'.http_build_query(['event_id'=>$eventId]))?>"><i class="fa-solid fa-robot"></i> Robot Intelligence</a>
      <a class="btn secondary" href="<?=e(base_url('analytics/team-display.php').'?'.http_build_query(['event_id'=>$eventId,'team'=>$teamNumber]))?>"><i class="fa-solid fa-display"></i> Match Board</a>
    </div>
  </header>

  <?php if($flash):?><div class="strategy-toast-stack" aria-live="polite"><div class="strategy-toast <?=e($flash['type']==='bad'?'bad':'good')?>"><?=e($flash['message'])?></div></div><?php endif;?>

  <div class="card">
    <form method="get" class="strategy-filter">
      <div><label>Event</label><select name="event_id"><?php foreach($events as $e):?><option value="<?=$e['id']?>" <?=$eventId===(int)$e['id']?'selected':''?>><?=e($e['name'])?></option><?php endforeach;?></select></div>
      <div><label>Our team</label><select name="team"><?php foreach($myTeams as $t):?><option value="<?=$t['frc_team_number']?>" <?=$teamNumber===(int)$t['frc_team_number']?'selected':''?>>#<?=e($t['frc_team_number'].' '.($t['display_name']?:$t['nickname']))?></option><?php endforeach;?></select></div>
      <div><label>Match</label><select name="match_id"><?php foreach($matchesForTeam as $m):?><option value="<?=$m['id']?>" <?=$matchId===(int)$m['id']?'selected':''?>><?=e(neptune_match_label($m).' · '.ucfirst($m['state']))?></option><?php endforeach;?></select></div>
      <button type="submit"><i class="fa-solid fa-crosshairs"></i> Load Match</button>
    </form>
  </div>

  <?php if(!$match || !$ourAlliance):?>
    <div class="notice" style="margin-top:16px">No match is available for this team and event yet. Sync or create the event schedule first.</div>
  <?php else:?>
    <div class="strategy-match-head">
      <div><div class="module-eyebrow"><span><?=e($match['event_name'])?></span><small><?=e($match['game_name'])?></small></div><h2><?=e(neptune_match_label($match))?></h2></div>
      <div class="strategy-match-meta">
        <span class="pill"><i class="fa-solid fa-circle-dot"></i> <?=e(ucfirst($match['state']))?></span>
        <?php if($match['scheduled_time']):?><span class="pill"><i class="fa-solid fa-clock"></i> <?=e($match['scheduled_time'])?></span><?php endif;?>
        <?php if(($match['run_number']??1)>1):?><span class="pill">Run <?=e($match['run_number'])?></span><?php endif;?>
        <?php if($actualResultAvailable):?><span class="pill"><i class="fa-solid fa-trophy"></i> Final: Red <?=e((int)$match['red_score'])?> · Blue <?=e((int)$match['blue_score'])?></span><?php endif;?>
      </div>
    </div>

    <div class="strategy-matchup">
      <?php foreach(['Red','Blue'] as $alliance):?>
        <?php if($alliance==='Blue'):?>
          <div class="strategy-projection <?=$projectionReady?'':'insufficient'?>">
            <div class="strategy-projection-head">
              <div><span class="muted">AUGUR prediction</span><small>Shared matchup model</small></div>
              <div class="strategy-projection-actions">
                <button type="button" class="secondary compact" id="predictionInfoBtn" title="How AUGUR is using data for this match" aria-label="How AUGUR is using data for this match"><i class="fa-solid fa-circle-info"></i></button>
                <button type="button" class="secondary compact" id="predictionSettingsBtn" title="Prediction model settings" aria-label="Prediction model settings"><i class="fa-solid fa-sliders"></i></button>
              </div>
            </div>
            <?php if($projectionReady):$redPred=$augurPrediction['a'];$bluePred=$augurPrediction['b'];?>
              <div class="proj-values strategy-prediction-values">
                <div class="strategy-prediction-side red"><span>Red</span><b><?=number_format((float)$redPred['predicted_score'],1)?></b><small><?=number_format((float)$redPred['win_probability'],0)?>% win · <?=number_format((float)$redPred['low'],0)?>–<?=number_format((float)$redPred['high'],0)?></small></div>
                <span class="vs">vs</span>
                <div class="strategy-prediction-side blue"><span>Blue</span><b><?=number_format((float)$bluePred['predicted_score'],1)?></b><small><?=number_format((float)$bluePred['win_probability'],0)?>% win · <?=number_format((float)$bluePred['low'],0)?>–<?=number_format((float)$bluePred['high'],0)?></small></div>
              </div>
              <div class="strategy-prediction-confidence"><i class="fa-solid fa-brain"></i> <?=number_format((float)$augurPrediction['confidence'],0)?>% model confidence</div>
              <div class="strategy-prediction-details">
                <span>Pre-match EPA <?=number_format((float)$redPred['epa'],1)?> / <?=number_format((float)$bluePred['epa'],1)?></span>
                <span>Pre-match Neptune EPA <?=number_format((float)($redPred['neptune_epa']??$redPred['augur_epa']),1)?> / <?=number_format((float)($bluePred['neptune_epa']??$bluePred['augur_epa']),1)?></span>
                <?php if($currentProjectionReady):$currentRed=$currentAugurPrediction['a'];$currentBlue=$currentAugurPrediction['b'];?>
                  <span>Current Neptune EPA <?=number_format((float)($currentRed['neptune_epa']??$currentRed['augur_epa']),1)?> / <?=number_format((float)($currentBlue['neptune_epa']??$currentBlue['augur_epa']),1)?> <small>· Alliance Selection</small></span>
                <?php endif;?>
                <span>Slope <?=number_format((float)$redPred['slope'],1)?> / <?=number_format((float)$bluePred['slope'],1)?></span>
                <span>Defense impact <?=number_format((float)$redPred['defense_estimate'],1)?> / <?=number_format((float)$bluePred['defense_estimate'],1)?></span>
              </div>
              <?php if($actualResultAvailable):?><div class="strategy-actual-compare"><span>Actual final</span><b>Red <?=e((int)$match['red_score'])?> · Blue <?=e((int)$match['blue_score'])?></b></div><?php endif;?>
            <?php else:?>
              <div class="strategy-projection-status">Prediction unavailable</div>
              <small><?=e($augurPredictionError?:'AUGUR does not yet have enough usable pre-match scouting data for this matchup.')?></small>
            <?php endif;?>
          </div>
        <?php endif;?>
        <section class="strategy-alliance <?=strtolower($alliance)?>">
          <div class="strategy-alliance-head"><b><?=$alliance?> Alliance</b><span class="muted"><?=$ourAlliance===$alliance?'Our alliance':'Opponent alliance'?></span></div>
          <div class="strategy-team-list">
            <?php foreach(array_values(array_filter($matchTeams,static fn($t)=>$t['alliance']===$alliance)) as $t):$n=(int)$t['frc_team_number'];$r=$stats[$n]??['ppm'=>0,'cycle_time'=>null,'success_rate'=>0,'matches'=>0];$hasScoutData=((int)($r['matches']??0))>0;?>
              <button type="button" class="strategy-team robot-detail-trigger <?=$n===$teamNumber?'ours':''?>" data-event-id="<?=$eventId?>" data-team="<?=$n?>">
                <strong>#<?=e($n)?></strong><span class="team-name"><?=e($t['nickname']?:'FRC Team '.$n)?></span>
                <div class="strategy-mini-stats">
                  <div><span>PPM</span><b><?=$hasScoutData?number_format((float)$r['ppm'],1):'No data'?></b></div>
                  <div><span>Cycle</span><b><?=$hasScoutData&&$r['cycle_time']!==null?number_format((float)$r['cycle_time'],1).'s':($hasScoutData?'—':'No data')?></b></div>
                  <div><span>Success</span><b><?=$hasScoutData?number_format((float)$r['success_rate'],0).'%':'No data'?></b></div>
                  <div><span>Prior matches</span><b><?=e((int)($r['matches']??0))?></b></div>
                </div>
                <?php $pm=$predictionMetricsByTeam[$n]??[];if(!$hasScoutData&&!empty($pm['baseline_source'])&&$pm['baseline_source']!=='no baseline'):?>
                  <div class="strategy-model-fallback"><i class="fa-solid fa-database"></i> Model fallback: <?=e((string)$pm['baseline_source'])?> · <?=number_format((float)($pm['projected_offense']??0),1)?> pts</div>
                <?php endif;?>
              </button>
            <?php endforeach;?>
          </div>
        </section>
      <?php endforeach;?>
    </div>

    <div class="strategy-confidence-block">
      <div class="strategy-confidence-heading">
        <div><h2><i class="fa-solid fa-signal"></i> Data Confidence</h2><p>Observed metrics use only scouting from matches completed before <?=e(neptune_match_label($match))?>. Pit status reflects the currently stored pit record.</p></div>
        <div class="strategy-confidence-summary"><?=e($projectionAnyData)?> of <?=e($projectionRobotCount)?> robots have prior scouting</div>
      </div>
      <div class="strategy-confidence">
        <?php foreach($matchTeams as $t):$n=(int)$t['frc_team_number'];$r=$stats[$n]??['matches'=>0,'pit_status'=>null];[$confidence,$confidenceClass]=strategy_confidence($r);?>
          <?php $pm=$predictionMetricsByTeam[$n]??[];$fallbackLabel=((int)($r['matches']??0)===0&&($pm['baseline_source']??'no baseline')!=='no baseline')?' · Model: '.(string)$pm['baseline_source']:'';?>
          <div class="strategy-confidence-item"><b>#<?=e($n)?></b><small><?=e((int)($r['matches']??0))?> prior matches<?=e($fallbackLabel)?> · Pit <?=e(($r['pit_status']??'')==='complete'?'complete':(($r['pit_status']??'')?'in progress':'missing'))?></small><span class="strategy-confidence-badge <?=e($confidenceClass)?>"><?=e($confidence)?></span></div>
        <?php endforeach;?>
      </div>
    </div>

    <?php if(!$tableReady):?><div class="notice fm-warning" style="margin-top:14px"><b>Read-only until storage is installed.</b> Run <code>sql/match_strategy.sql</code> from the patch once, then this page can save plans.</div><?php endif;?>

    <form method="post" id="matchStrategyForm">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_strategy"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="team" value="<?=$teamNumber?>"><input type="hidden" name="match_id" value="<?=$matchId?>">

      <section class="card strategy-section">
        <div class="strategy-section-head"><div><h2><i class="fa-solid fa-people-group"></i> Alliance Roles</h2><p>AUGUR suggests roles from observed scoring and defense. Strategy can override them.</p></div></div>
        <div class="strategy-role-grid">
          <?php foreach($allianceTeams as $t):
            $n=(int)$t['frc_team_number'];
            $r=$stats[$n]??[];
            $matchesSeen=(int)($r['matches']??0);
            $suggested=$suggestedRoles[$n]??null;
            $pitRole=strategy_pit_role($pitByTeam[$n]['data']??[]);
            $selected=(string)($roleValues[(string)$n]??$suggested??$pitRole??'Flexible');
            $rawPitRole=$pitByTeam[$n]['data']['preferred_roles']??'';
            if(is_array($rawPitRole))$rawPitRole=implode(', ',array_map('strval',$rawPitRole));
          ?>
            <div class="strategy-role-card">
              <h3>#<?=e($n)?> <?=e($t['nickname']?:'')?></h3>
              <div class="strategy-role-suggest">
                <span>Observed role:</span><b><?=e($suggested??'Not enough data')?></b>
                <?php if(trim((string)$rawPitRole)!==''):?><span class="pill">Pit: <?=e((string)$rawPitRole)?></span><?php endif;?>
              </div>
              <details class="strategy-why">
                <summary>Why?</summary>
                <div class="strategy-why-content">
                  <?php if($matchesSeen<$projectionMinMatches):?>
                    AUGUR needs at least <b><?=e($projectionMinMatches)?> prior scouted matches</b> before it suggests an observed role. This robot has <b><?=e($matchesSeen)?></b>.
                  <?php else:?>
                    Based on <b><?=e($matchesSeen)?> prior matches</b>: <?=number_format((float)($r['ppm']??0),1)?> PPM, <?=number_format((float)($r['defense_per_match']??0),1)?> successful defense actions/match, and <?=number_format((float)($r['success_rate']??0),0)?>% offensive success.
                  <?php endif;?>
                  <?php if(trim((string)$rawPitRole)!==''):?><br>Pit scouting currently reports: <b><?=e((string)$rawPitRole)?></b>.<?php endif;?>
                </div>
              </details>
              <label>Match role</label><select name="role[<?=$n?>]" <?=$canEdit&&$tableReady?'':'disabled'?>><?php foreach(['Primary scorer','Secondary scorer','Defense','Feeder / support','Flexible'] as $role):?><option <?=$selected===$role?'selected':''?>><?=e($role)?></option><?php endforeach;?></select>
              <div class="strategy-metric-row">
                <div class="strategy-metric"><span>PPM</span><b><?=$matchesSeen?number_format((float)($r['ppm']??0),1):'No data'?></b></div>
                <div class="strategy-metric"><span>Defense / match</span><b><?=$matchesSeen?number_format((float)($r['defense_per_match']??0),1):'No data'?></b></div>
                <div class="strategy-metric"><span>Success</span><b><?=$matchesSeen?number_format((float)($r['success_rate']??0),0).'%':'No data'?></b></div>
              </div>
            </div>
          <?php endforeach;?>
        </div>
      </section>

      <section class="card strategy-section">
        <div class="strategy-section-head"><div><h2><i class="fa-solid fa-route"></i> Autonomous Intelligence</h2><p>Observed autonomous performance plus pit and pre-scout information.</p></div></div>
        <div class="strategy-intel-grid">
          <?php foreach($allianceTeams as $t):$n=(int)$t['frc_team_number'];$p=$phaseIntel[$n]??[];$pit=$pitByTeam[$n]['data']??[];$pre=$preByTeam[$n]['data']??[];?>
            <div class="strategy-intel-card">
              <h3>#<?=e($n)?> <?=e($t['nickname']?:'')?></h3>
              <div class="strategy-metric-row"><div class="strategy-metric"><span>Auto pts / match</span><b><?=($p['auton_match_count']??0)>0?number_format((float)$p['auton_ppm'],1):'No data'?></b></div><div class="strategy-metric"><span>Auto success</span><b><?=isset($p['auton_success_rate'])&&$p['auton_success_rate']!==null?number_format((float)$p['auton_success_rate'],0).'%':(($p['auton_match_count']??0)>0?'—':'No data')?></b></div><div class="strategy-metric"><span>Common action</span><b><?=($p['auton_match_count']??0)>0?e($p['auton_common']??'—'):'No data'?></b></div></div>
              <?php $start=strategy_first_nonempty([$pit['auton_start_positions']??'']);if($start!==''):?><div class="strategy-source"><span>Pit · Start positions</span><p><?=e($start)?></p></div><?php endif;?>
              <?php $auto=strategy_first_nonempty([$pit['auton_description']??''],[$pre['auton_description']??'']);if($auto!==''):?><div class="strategy-source"><span><?=!empty($pit['auton_description'])?'Pit':'Pre-scout'?> · Auto description</span><p><?=e($auto)?></p></div><?php endif;?>
              <?php $restriction=strategy_first_nonempty([$pit['auton_partner_notes']??'']);if($restriction!==''):?><div class="strategy-source"><span>Pit · Partner restrictions</span><p><?=e($restriction)?></p></div><?php endif;?>
              <?php if($auto===''&&$start===''&&$restriction===''):?><div class="notice" style="margin-top:10px">No pit or pre-scout autonomous notes yet.</div><?php endif;?>
            </div>
          <?php endforeach;?>
        </div>
      </section>

      <?php $sortedOpp=$opponentTeams;usort($sortedOpp,static function($a,$b)use($stats){
          $an=(int)$a['frc_team_number'];$bn=(int)$b['frc_team_number'];
          $am=(int)($stats[$an]['matches']??0);$bm=(int)($stats[$bn]['matches']??0);
          if(($am>0)!==($bm>0)) return $bm>0?1:-1;
          return (($stats[$bn]['ppm']??0)<=>($stats[$an]['ppm']??0));
      });$observedOppIndex=0;?>
      <section class="card strategy-section">
        <div class="strategy-section-head"><div><h2><i class="fa-solid fa-crosshairs"></i> Opponent Watch</h2><p>Prioritize what deserves attention, then choose a defense target if appropriate.</p></div></div>
        <div class="strategy-threat-grid">
          <?php foreach($sortedOpp as $i=>$t):$n=(int)$t['frc_team_number'];$r=$stats[$n]??[];$pit=$pitByTeam[$n]['data']??[];$pre=$preByTeam[$n]['data']??[];$oppMatches=(int)($r['matches']??0);if($oppMatches>0)$observedOppIndex++;$threatTag=$oppMatches===0?'No prior scout data':($oppMatches<$projectionMinMatches?'Limited data':($observedOppIndex===1?'Highest observed PPM':($observedOppIndex===2?'Second observed PPM':'Opponent')));?>
            <div class="strategy-threat">
              <div class="strategy-threat-head"><div><div class="strategy-threat-tag"><?=e($threatTag)?></div><h3>#<?=e($n)?> <?=e($t['nickname']?:'')?></h3></div><button type="button" class="secondary robot-detail-trigger" data-event-id="<?=$eventId?>" data-team="<?=$n?>" aria-label="Open robot intelligence for team <?=$n?>"><i class="fa-solid fa-circle-info"></i></button></div>
              <div class="strategy-metric-row"><div class="strategy-metric"><span>PPM</span><b><?=$oppMatches?number_format((float)($r['ppm']??0),1):'No data'?></b></div><div class="strategy-metric"><span>Cycle</span><b><?=$oppMatches?(isset($r['cycle_time'])&&$r['cycle_time']!==null?number_format((float)$r['cycle_time'],1).'s':'—'):'No data'?></b></div><div class="strategy-metric"><span>Defense / match</span><b><?=$oppMatches?number_format((float)($r['defense_per_match']??0),1):'No data'?></b></div></div>
              <?php $drive=strategy_first_nonempty([$pit['drive_notes']??''],[$pre['drive_notes']??'']);if($drive!==''):?><div class="strategy-source"><span>Drive notes</span><p><?=e($drive)?></p></div><?php endif;?>
              <?php $def=strategy_first_nonempty([$pit['defense_resistance']??''],[$pre['defense_notes']??'']);if($def!==''):?><div class="strategy-source"><span>Defense / counter-defense</span><p><?=e($def)?></p></div><?php endif;?>
            </div>
          <?php endforeach;?>
        </div>
        <div style="padding:0 14px 14px;max-width:360px"><label>Defense target</label><select name="defense_target" <?=$canEdit&&$tableReady?'':'disabled'?>><option value="0">No assigned target</option><?php foreach($sortedOpp as $t):$n=(int)$t['frc_team_number'];?><option value="<?=$n?>" <?=$defenseTarget===$n?'selected':''?>>#<?=e($n.' '.($t['nickname']?:''))?></option><?php endforeach;?></select></div>
      </section>

      <section class="card strategy-section">
        <div class="strategy-section-head"><div><h2><i class="fa-solid fa-list-check"></i> Phase-by-Phase Plan</h2><p>Give each alliance robot a clear assignment for autonomous, teleop, and endgame.</p></div></div>
        <div class="strategy-plan-wrap"><table class="strategy-plan"><thead><tr><th>Robot</th><th>Autonomous</th><th>Teleop</th><th>Endgame</th></tr></thead><tbody>
          <?php foreach($allianceTeams as $t):$n=(int)$t['frc_team_number'];$saved=$phaseValues[(string)$n]??[];?>
            <tr><td class="robot-cell"><b>#<?=e($n)?></b><small><?=e($t['nickname']?:'')?></small></td><td><textarea name="phase[<?=$n?>][auton]" placeholder="Starting position, route, scoring assignment…" <?=$canEdit&&$tableReady?'':'disabled'?>><?=e((string)($saved['auton']??''))?></textarea></td><td><textarea name="phase[<?=$n?>][teleop]" placeholder="Scoring lane, feeding, defense assignment…" <?=$canEdit&&$tableReady?'':'disabled'?>><?=e((string)($saved['teleop']??''))?></textarea></td><td><textarea name="phase[<?=$n?>][endgame]" placeholder="Climb/park timing, space requirements…" <?=$canEdit&&$tableReady?'':'disabled'?>><?=e((string)($saved['endgame']??''))?></textarea></td></tr>
          <?php endforeach;?>
        </tbody></table></div>
      </section>

      <section class="card strategy-section">
        <div class="strategy-section-head"><div><h2><i class="fa-solid fa-flag-checkered"></i> Endgame Reliability</h2><p>Compare what teams report with what scouts have actually observed.</p></div></div>
        <div class="strategy-endgame-grid">
          <?php foreach($matchTeams as $t):$n=(int)$t['frc_team_number'];$p=$phaseIntel[$n]??[];$pit=$pitByTeam[$n]['data']??[];$pre=$preByTeam[$n]['data']??[];$claim=strategy_first_nonempty([$pit['endgame_capability']??''],[$pre['endgame_capability']??'']);?>
            <div class="strategy-endgame-card"><h3>#<?=e($n)?> <span class="muted"><?=e($t['alliance'])?></span></h3><div class="strategy-endgame-line"><div><span>Pit / pre-scout says</span><b><?=e($claim?:'—')?></b></div><div><span>Observed endgame</span><b><?=e($p['endgame_common']??'—')?></b></div><div><span>Observed reliability</span><b><?=isset($p['endgame_rate'])&&$p['endgame_rate']!==null?number_format((float)$p['endgame_rate'],0).'% · '.e((int)$p['endgame_success']).'/'.e((int)$p['endgame_attempts']):'—'?></b></div></div></div>
          <?php endforeach;?>
        </div>
      </section>

      <section class="card strategy-section">
        <div class="strategy-section-head"><div><h2><i class="fa-solid fa-clipboard"></i> Match Plan</h2><p>Keep the final drive-team plan concise and match-specific.</p></div></div>
        <div class="strategy-notes-grid">
          <div class="wide"><label>Key objectives <span class="muted">one per line</span></label><textarea name="objectives" rows="5" placeholder="Keep scoring lane clear for #…&#10;Pressure #…&#10;Begin endgame at …" <?=$canEdit&&$tableReady?'':'disabled'?>><?=e($objectives)?></textarea></div>
          <div><label>Autonomous notes</label><textarea name="auton_notes" rows="5" placeholder="Shared path notes, collision concerns, backups…" <?=$canEdit&&$tableReady?'':'disabled'?>><?=e($autonNotes)?></textarea></div>
          <div><label>Drive team notes</label><textarea name="drive_notes" rows="5" placeholder="Communication, priorities, contingencies…" <?=$canEdit&&$tableReady?'':'disabled'?>><?=e($driveNotes)?></textarea></div>
        </div>
        <div class="strategy-checks">
          <label class="strategy-check"><input type="checkbox" name="reviewed_with_drive_coach" <?=$reviewed?'checked':''?> <?=$canEdit&&$tableReady?'':'disabled'?>> Reviewed with drive coach</label>
          <label class="strategy-check"><input type="checkbox" name="auton_paths_confirmed" <?=$pathsConfirmed?'checked':''?> <?=$canEdit&&$tableReady?'':'disabled'?>> Autonomous paths confirmed</label>
          <label class="strategy-check"><input type="checkbox" name="endgame_confirmed" <?=$endgameConfirmed?'checked':''?> <?=$canEdit&&$tableReady?'':'disabled'?>> Endgame responsibilities confirmed</label>
        </div>
        <div class="strategy-savebar">
          <div class="muted"><?php if($strategyRow):?>Last saved <?=e($strategyRow['updated_at'])?><?php elseif($canEdit&&$tableReady):?>No saved plan for this match yet.<?php elseif(!$canEdit):?>Your role has view-only access.<?php else:?>Install the strategy table to enable saving.<?php endif;?></div>
          <?php if($canEdit&&$tableReady):?><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Match Strategy</button><?php endif;?>
        </div>
      </section>
    </form>

    <dialog class="prediction-info-dialog" id="predictionInfoDialog" aria-labelledby="predictionInfoTitle">
      <div class="prediction-info-head">
        <div><h2 id="predictionInfoTitle"><i class="fa-solid fa-circle-info"></i> How AUGUR is predicting this match</h2><p><?=e(neptune_match_label($match))?> · pre-match data rules</p></div>
        <button type="button" class="secondary compact" id="predictionInfoCloseBtn" aria-label="Close prediction information"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="prediction-info-body">
        <p>AUGUR treats this card as a historical pre-match prediction, so the score above only uses data available before <b><?=e(neptune_match_label($match))?></b>. The separate <b>Current Neptune EPA</b> line is today's no-cutoff value and is the same reference used by Alliance Selection.</p>
        <p>If a robot has little or no usable current-event scouting, the shared prediction model can fall back to:</p>
        <ul>
          <li>prior-event Neptune scouting from the current season/game,</li>
          <li>prior-event EPA already stored in Neptune's public archive, or</li>
          <li>the observed event field average when stronger robot-specific evidence is unavailable.</li>
        </ul>
        <div class="prediction-info-mode">
          <i class="fa-solid <?=$projectionReady&&!empty($augurPrediction['method']['epa_used'])?'fa-chart-line':'fa-clock-rotate-left'?>"></i>
          <span>
            <?php if($projectionReady&&!empty($augurPrediction['method']['epa_used'])):?>
              <b>EPA baseline:</b> this prediction uses Neptune's TBA-derived EPA snapshot from before this match, then blends available Neptune scouting to produce Neptune EPA. Later match results cannot leak backward.
            <?php else:?>
              <b>EPA unavailable:</b> AUGUR is temporarily relying on scouting, prior-event Neptune data, and field-average fallbacks until public EPA is archived.
            <?php endif;?>
          </span>
        </div>
      </div>
    </dialog>

    <dialog class="prediction-settings-dialog" id="predictionSettingsDialog" aria-labelledby="predictionSettingsTitle">
      <div class="prediction-settings-head">
        <div><h2 id="predictionSettingsTitle">AUGUR prediction settings</h2><p>Shared with Alliance Selection for this organization and event.</p></div>
        <button type="button" class="secondary compact" id="predictionSettingsCloseBtn" aria-label="Close prediction settings"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="prediction-settings-body">
        <section class="prediction-settings-section">
          <h3>Offensive model</h3>
          <div class="prediction-settings-grid">
            <?php foreach([
              ['event_offense_weight','Full-event offense',0,100,1],
              ['recent_offense_weight','Recent offense',0,100,1],
              ['ceiling_weight','High-end output',0,100,1],
              ['epa_weight','EPA baseline',0,100,1],
              ['trend_strength','Trend strength',0,4,.1],
              ['tba_form_strength','Qualification form influence',0,20,1],
            ] as $setting):[$key,$label,$min,$max,$step]=$setting;?>
              <div class="prediction-setting"><label><?=e($label)?><output data-prediction-output="<?=e($key)?>"><?=e($predictionSettings[$key])?></output></label><input type="range" name="<?=e($key)?>" min="<?=e($min)?>" max="<?=e($max)?>" step="<?=e($step)?>" value="<?=e($predictionSettings[$key])?>" <?=$canEdit&&alliance_schema_ready($pdo)?'':'disabled'?>></div>
            <?php endforeach;?>
          </div>
        </section>
        <section class="prediction-settings-section">
          <h3>Defense model</h3>
          <div class="prediction-settings-grid">
            <?php foreach([
              ['defense_suppression_weight','Opponent suppression',0,100,1],
              ['defense_activity_weight','Recorded defense activity',0,100,1],
              ['defense_action_points','Defense action value',0,8,.1],
              ['max_defense_adjustment_pct','Maximum defensive adjustment %',0,40,1],
            ] as $setting):[$key,$label,$min,$max,$step]=$setting;?>
              <div class="prediction-setting"><label><?=e($label)?><output data-prediction-output="<?=e($key)?>"><?=e($predictionSettings[$key])?></output></label><input type="range" name="<?=e($key)?>" min="<?=e($min)?>" max="<?=e($max)?>" step="<?=e($step)?>" value="<?=e($predictionSettings[$key])?>" <?=$canEdit&&alliance_schema_ready($pdo)?'':'disabled'?>></div>
            <?php endforeach;?>
          </div>
          <div class="prediction-settings-note"><i class="fa-solid fa-circle-info"></i><span><b>Opponent suppression</b> compares how opposing robots performed against a robot with how those same robots performed in their other scouted matches. It is useful evidence, but it is not a causal isolation of one defender.</span></div>
          <?php if(!alliance_schema_ready($pdo)):?><div class="prediction-settings-note"><i class="fa-solid fa-triangle-exclamation"></i><span>Shared model settings cannot be saved until <code>alliance_selection_state</code> is installed.</span></div><?php endif;?>
        </section>
      </div>
      <div class="prediction-settings-actions">
        <button type="button" class="secondary" id="predictionSettingsResetBtn"><i class="fa-solid fa-arrow-rotate-left"></i> Defaults</button>
        <?php if($canEdit&&alliance_schema_ready($pdo)):?><button type="button" class="good" id="predictionSettingsSaveBtn"><i class="fa-solid fa-floppy-disk"></i> Save model</button><?php endif;?>
      </div>
    </dialog>

    <?php include __DIR__.'/_robot_modal.php';?>
  <?php endif;?>
</section>
<script>
(()=>{
  const toast=document.querySelector('.strategy-toast-stack');
  if(toast)setTimeout(()=>toast.remove(),4200);

  const cfg=<?=json_encode([
    'eventId'=>$eventId,
    'csrf'=>csrf_token(),
    'settingsEndpoint'=>base_url('api/augur-prediction-settings.php'),
    'defaults'=>augur_prediction_settings(),
  ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  const infoDialog=document.getElementById('predictionInfoDialog');
  const infoOpenBtn=document.getElementById('predictionInfoBtn');
  const infoCloseBtn=document.getElementById('predictionInfoCloseBtn');
  const dialog=document.getElementById('predictionSettingsDialog');
  const openBtn=document.getElementById('predictionSettingsBtn');
  const closeBtn=document.getElementById('predictionSettingsCloseBtn');
  const resetBtn=document.getElementById('predictionSettingsResetBtn');
  const saveBtn=document.getElementById('predictionSettingsSaveBtn');

  function showToast(message,type='good'){
    let stack=document.querySelector('.strategy-toast-stack');
    if(!stack){stack=document.createElement('div');stack.className='strategy-toast-stack';stack.setAttribute('aria-live','polite');document.body.appendChild(stack);}
    const item=document.createElement('div');item.className='strategy-toast '+(type==='bad'?'bad':'good');item.textContent=message;stack.appendChild(item);setTimeout(()=>item.remove(),4200);
  }
  function inputs(){return dialog?[...dialog.querySelectorAll('.prediction-setting input[name]')]:[];}
  function syncOutput(input){const output=dialog?.querySelector(`[data-prediction-output="${input.name}"]`);if(output)output.value=input.value;}
  function fill(values){for(const input of inputs()){if(Object.prototype.hasOwnProperty.call(values,input.name))input.value=values[input.name];syncOutput(input);}}

  infoOpenBtn?.addEventListener('click',()=>{if(infoDialog&&!infoDialog.open)infoDialog.showModal();});
  infoCloseBtn?.addEventListener('click',()=>infoDialog?.close());
  infoDialog?.addEventListener('click',event=>{if(event.target===infoDialog)infoDialog.close();});
  openBtn?.addEventListener('click',()=>{if(dialog&&!dialog.open)dialog.showModal();});
  closeBtn?.addEventListener('click',()=>dialog?.close());
  dialog?.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
  dialog?.addEventListener('input',event=>{const input=event.target;if(input instanceof HTMLInputElement&&input.matches('.prediction-setting input[name]'))syncOutput(input);});
  resetBtn?.addEventListener('click',()=>fill(cfg.defaults||{}));
  saveBtn?.addEventListener('click',async()=>{
    const values={};for(const input of inputs())values[input.name]=Number(input.value);
    const body=new FormData();body.set('csrf',cfg.csrf);body.set('event_id',String(cfg.eventId));body.set('settings_json',JSON.stringify(values));
    saveBtn.disabled=true;
    try{
      const response=await fetch(cfg.settingsEndpoint,{method:'POST',body,credentials:'same-origin'});
      const data=await response.json().catch(()=>({}));
      if(!response.ok||!data.ok)throw new Error(data.message||'Could not save prediction settings.');
      showToast(data.message||'Prediction settings saved.');
      setTimeout(()=>window.location.reload(),350);
    }catch(error){showToast(error.message||'Could not save prediction settings.','bad');saveBtn.disabled=false;}
  });
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
