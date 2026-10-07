<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_alliance_beta_helpers.php';
require_once dirname(__DIR__).'/analytics/_augur_prediction_model.php';
require_once dirname(__DIR__).'/analytics/_depa_metrics.php';
require_once dirname(__DIR__).'/scout/_tag_schema.php';
header('Content-Type: application/json; charset=utf-8');

$u=require_role(['owner','admin','strategy']);
tag_unified_ensure_schema($pdo);
$org=(int)$u['organization_id'];
$userId=(int)$u['id'];
$canEdit=in_array((string)$u['role'],['owner','admin','strategy'],true);

function beta_out(array $data,int $code=200): never {http_response_code($code);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function beta_body(): array {$raw=file_get_contents('php://input');$j=json_decode($raw?:'{}',true);return is_array($j)?$j:[];}
function beta_csrf(array $b): void {if(!hash_equals((string)($_SESSION['csrf']??''),(string)($b['csrf']??'')))beta_out(['ok'=>false,'error'=>'Invalid CSRF token.'],419);}
function beta_context(PDO $pdo,int $org,int $eventId,int $strategyTeam): array {
    $event=alliance_beta_event($pdo,$org,$eventId);if(!$event)beta_out(['ok'=>false,'error'=>'Event not found.'],404);
    $team=alliance_beta_strategy_team($pdo,$org,$strategyTeam);if(!$team)beta_out(['ok'=>false,'error'=>'Strategy team is not part of this organization.'],404);
    $roster=alliance_beta_roster($pdo,$eventId);
    $rankings=alliance_rankings_for_event($event);
    return [$event,$team,$roster,$rankings];
}
function beta_team_metrics(PDO $pdo,int $org,array $event,array $roster,array $rankings,array $reference): array {
    $rankingMap=[];foreach((array)$rankings['rows'] as $r)$rankingMap[(int)$r['team']]=$r;
    $teamNumbers=array_values(array_unique(array_filter(array_map(static fn($row)=>(int)($row['frc_team_number']??0),$roster))));
    $settings=alliance_matchup_settings(is_array($reference['matchup_model']??null)?$reference['matchup_model']:[]);
    $metrics=[];$epaMap=[];$depaMap=[];$error='';
    try{
        if(count($teamNumbers)>=2){
            $split=(int)ceil(count($teamNumbers)/2);$a=array_slice($teamNumbers,0,$split);$b=array_slice($teamNumbers,$split);if(!$b){$b=[array_pop($a)];}
            $prediction=augur_prediction_run($pdo,$org,$event,$a,$b,$settings,['use_tba_rankings'=>false,'use_epa'=>true,'side_a_number'=>'Pool A','side_b_number'=>'Pool B']);
            foreach(['a','b'] as $side)foreach(($prediction[$side]['teams']??[]) as $row){$n=(int)($row['team']??0);if($n>0&&is_array($row['metrics']??null))$metrics[$n]=$row['metrics'];}
        }
    }catch(Throwable $e){error_log('Alliance Beta AUGUR: '.$e->getMessage());$error='Neptune EPA is temporarily unavailable.';}
    if(!$metrics){
        try{if(function_exists('augur_epa_tables_ready')&&augur_epa_tables_ready($pdo))$epaMap=augur_epa_rating_map($pdo,$org,$event,$teamNumbers,null);}catch(Throwable $ignored){}
        try{$depaMap=neptune_depa_event_map($pdo,$event,$teamNumbers);}catch(Throwable $ignored){}
    }
    $out=[];$tags=(array)($reference['alliance_tags']??[]);
    foreach($roster as $row){
        $n=(int)$row['frc_team_number'];$r=$rankingMap[$n]??[];$m=$metrics[$n]??[];$epa=$epaMap[$n]??[];$depa=$depaMap[$n]??[];
        $public=isset($m['epa'])&&is_numeric($m['epa'])?(float)$m['epa']:(isset($epa['epa'])&&is_numeric($epa['epa'])?(float)$epa['epa']:null);
        $nep=isset($m['neptune_epa'])&&is_numeric($m['neptune_epa'])?(float)$m['neptune_epa']:(isset($m['augur_epa'])&&is_numeric($m['augur_epa'])?(float)$m['augur_epa']:null);
        $out[]=[
            'team'=>$n,'frc_team_number'=>$n,'nickname'=>(string)($row['nickname']??''),'city'=>(string)($row['city']??''),'state_prov'=>(string)($row['state_prov']??''),'country'=>(string)($row['country']??''),
            'rank'=>(int)($r['rank']??0),'points'=>(float)($r['points']??0),'wins'=>(int)($r['wins']??0),'losses'=>(int)($r['losses']??0),'ties'=>(int)($r['ties']??0),'matches'=>(int)($r['matches']??0),
            'epa'=>$public===null?null:round($public,1),'neptune_epa'=>$nep===null?null:round($nep,1),'depa'=>isset($depa['depa'])&&is_numeric($depa['depa'])?round((float)$depa['depa'],1):null,'neptune_depa'=>isset($depa['neptune_depa'])&&is_numeric($depa['neptune_depa'])?round((float)$depa['neptune_depa'],1):null,'depa_verified'=>(int)($depa['verified_defense_matches']??0),'confidence'=>isset($m['confidence'])&&is_numeric($m['confidence'])?round((float)$m['confidence'],0):null,
            'trend'=>isset($m['slope'])&&is_numeric($m['slope'])?round((float)$m['slope'],2):null,'event_avg'=>isset($m['event_avg'])&&is_numeric($m['event_avg'])?round((float)$m['event_avg'],1):null,'recent_avg'=>isset($m['recent_avg'])&&is_numeric($m['recent_avg'])?round((float)$m['recent_avg'],1):null,
            'tags'=>array_values(array_filter(array_map('strval',(array)($tags[$n]??$tags[(string)$n]??[]))))
        ];
    }
    usort($out,static function($a,$b){$p=((float)$b['points'])<=>((float)$a['points']);if($p)return $p;$an=$a['neptune_epa'];$bn=$b['neptune_epa'];if($an!==null||$bn!==null){$x=((float)($bn??-INF))<=>((float)($an??-INF));if($x)return $x;}return ((int)($a['rank']?:PHP_INT_MAX))<=>((int)($b['rank']?:PHP_INT_MAX));});
    return ['teams'=>$out,'augur_error'=>$error];
}
function beta_current_reference(PDO $pdo,int $org,int $eventId): array {return alliance_beta_production_reference($pdo,$org,$eventId);}

function beta_metric_map(array $teams): array {
    $out=[];
    foreach($teams as $r){$t=(int)($r['team']??0);if($t>0)$out[$t]=$r;}
    return $out;
}
function beta_strength(array $row): float {
    if(isset($row['neptune_epa'])&&is_numeric($row['neptune_epa']))return (float)$row['neptune_epa'];
    if(isset($row['epa'])&&is_numeric($row['epa']))return (float)$row['epa'];
    return 0.0;
}
function beta_remaining_schedule(PDO $pdo,int $org,int $eventId,int $team,array $richTeams): array {
    $m=$betaMap=beta_metric_map($richTeams);
    $q=$pdo->prepare("SELECT m.id,m.match_number,m.set_number,m.comp_level,m.state,m.scheduled_time,mt.alliance
      FROM matches m JOIN match_teams mt ON mt.match_id=m.id
      WHERE m.organization_id=? AND m.event_id=? AND m.comp_level='qm' AND mt.frc_team_number=? AND m.state<>'ended'
      ORDER BY m.match_number,m.id");
    $q->execute([$org,$eventId,$team]);
    $matches=$q->fetchAll();
    if(!$matches)return [];
    $ids=array_values(array_unique(array_map(static fn($r)=>(int)$r['id'],$matches)));
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $tq=$pdo->prepare("SELECT match_id,frc_team_number,alliance,station FROM match_teams WHERE match_id IN ($ph) ORDER BY match_id,alliance,station");
    $tq->execute($ids);
    $by=[];
    foreach($tq->fetchAll() as $r)$by[(int)$r['match_id']][]=$r;
    $out=[];
    foreach($matches as $r){
        $id=(int)$r['id'];$ours=(string)$r['alliance'];$own=0.0;$opp=0.0;$all=[];
        foreach($by[$id]??[] as $tr){
            $tn=(int)$tr['frc_team_number'];$row=$m[$tn]??[];$st=beta_strength($row);
            if((string)$tr['alliance']===$ours)$own+=$st;else$opp+=$st;
            $all[]=['team'=>$tn,'alliance'=>(string)$tr['alliance'],'station'=>(int)$tr['station'],'strength'=>round($st,1)];
        }
        $diff=$own-$opp;$pwin=1.0/(1.0+exp(-$diff/12.0));
        $out[]=[
            'match_id'=>$id,'match_number'=>(int)$r['match_number'],'scheduled_time'=>$r['scheduled_time'],
            'alliance'=>$ours,'win_probability'=>round($pwin*100,0),'own_strength'=>round($own,1),'opp_strength'=>round($opp,1),'teams'=>$all,
        ];
    }
    return $out;
}
function beta_projection(array $teamRow,array $richTeams,array $remaining): array {
    $currentRank=max(1,(int)($teamRow['rank']??0));
    $remainingCount=count($remaining);
    $avgWin=$remainingCount?array_sum(array_map(static fn($m)=>(float)$m['win_probability']/100.0,$remaining))/$remainingCount:0.5;
    $center=$currentRank-(($avgWin-0.5)*$remainingCount*1.35);
    $spread=max(0.8,0.8+$remainingCount*0.55);
    $low=max(1,(int)floor($center-$spread));
    $high=min(max(1,count($richTeams)),(int)ceil($center+$spread));
    $captain=1.0/(1.0+exp(($center-8.5)/max(1.0,$spread)));
    $epaSorted=$richTeams;
    usort($epaSorted,static fn($a,$b)=>beta_strength($b)<=>beta_strength($a));
    $epaRank=count($epaSorted)+1;
    foreach($epaSorted as $i=>$r)if((int)$r['team']===(int)$teamRow['team']){$epaRank=$i+1;break;}
    $attract=max(0.0,min(1.0,(26.0-$epaRank)/25.0));
    $first=(1.0-$captain)*(0.18+0.70*$attract);
    $second=max(0.0,1.0-$captain-$first);
    $sum=$captain+$first+$second;if($sum<=0)$sum=1;
    $captain/=$sum;$first/=$sum;$second/=$sum;
    if($captain>=0.68)$recommended='captain';
    elseif($captain>=0.30)$recommended='bubble';
    elseif($first>=$second)$recommended='first_pick';
    else $recommended='second_pick';
    $maxProb=max($captain,$first,$second);
    $confidence=$remainingCount===0?'high':($maxProb>=0.68?'high':($maxProb>=0.50?'medium':'low'));
    $why=[];
    $why[]="Current qualification rank is #{$currentRank}.";
    if($remainingCount){
        $why[]=$remainingCount." qualification match".($remainingCount===1?'':'es')." remain on the local schedule.";
        $why[]="Remaining schedule projects about ".round($avgWin*100)."% average win probability using available EPA strength.";
    }else $why[]='No remaining qualification matches are loaded; the projection is close to final.';
    $why[]="Neptune EPA ranks this robot about #{$epaRank} in the event field.";
    if($low<=8&&$high>8)$why[]='The projected rank range crosses the captain boundary, so dual planning is appropriate.';
    elseif($high<=8)$why[]='The projected rank range stays inside the captain band.';
    elseif($low>8)$why[]='The projected rank range is outside the captain band.';
    return [
        'current_rank'=>$currentRank,'projected_low'=>$low,'projected_high'=>$high,'remaining_matches'=>$remainingCount,
        'avg_win_probability'=>round($avgWin*100,0),'epa_rank'=>$epaRank,'confidence'=>$confidence,'recommended'=>$recommended,
        'probabilities'=>['captain'=>round($captain*100),'first_pick'=>round($first*100),'second_pick'=>round($second*100)],
        'why'=>$why,
    ];
}
function beta_defense_evidence(PDO $pdo,int $org,int $eventId): array {
    try{
        $q=$pdo->prepare("SELECT o.id observation_id,o.frc_team_number,o.note,o.created_at,t.label,t.slug,
            m.match_number,m.set_number,m.comp_level,med.id media_id,med.media_type
          FROM tag_scouting_observations o
          JOIN tag_scouting_observation_tags ot ON ot.observation_id=o.id
          JOIN tag_scouting_tags t ON t.id=ot.tag_id
          LEFT JOIN matches m ON m.id=o.match_id
          LEFT JOIN tag_scouting_media med ON med.observation_id=o.id
          WHERE o.organization_id=? AND o.event_id=? AND (LOWER(t.slug) LIKE '%defen%' OR LOWER(t.label) LIKE '%defen%')
          ORDER BY o.created_at DESC,o.id DESC,med.id");
        $q->execute([$org,$eventId]);
        $out=[];
        foreach($q->fetchAll() as $r){
            $team=(int)$r['frc_team_number'];if($team<1)continue;
            if(!isset($out[$team]))$out[$team]=['count'=>0,'tags'=>[],'media'=>[],'notes'=>[]];
            $out[$team]['count']++;
            $label=(string)($r['label']??'Defense');if($label!==''&&!in_array($label,$out[$team]['tags'],true))$out[$team]['tags'][]=$label;
            if(!empty($r['note'])&&count($out[$team]['notes'])<3)$out[$team]['notes'][]=(string)$r['note'];
            if(!empty($r['media_id'])&&count($out[$team]['media'])<4){
                $match='';
                if(!empty($r['match_number']))$match=strtoupper((string)($r['comp_level']??'Q')).(int)$r['match_number'];
                $out[$team]['media'][]=['id'=>(int)$r['media_id'],'type'=>(string)$r['media_type'],'match'=>$match];
            }
        }
        return $out;
    }catch(Throwable $e){return [];}
}
function beta_projected_captains(array $richTeams): array {
    $rows=array_values(array_filter($richTeams,static fn($r)=>(int)($r['rank']??0)>0));
    usort($rows,static fn($a,$b)=>(int)$a['rank']<=>(int)$b['rank']);
    return array_slice($rows,0,8);
}

if(!alliance_beta_schema_ready($pdo))beta_out(['ok'=>false,'error'=>'Alliance Selection Beta tables are not installed.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $eventId=(int)($_GET['event_id']??0);$strategyTeam=(int)($_GET['strategy_team']??0);
    [$event,$team,$roster,$rankings]=beta_context($pdo,$org,$eventId,$strategyTeam);
    $reference=beta_current_reference($pdo,$org,$eventId);
    $shared=alliance_beta_load_event_state($pdo,$org,$eventId,false,(array)$rankings['rows']);
    $private=alliance_beta_load_team_state($pdo,$org,$eventId,$strategyTeam);
    $rich=beta_team_metrics($pdo,$org,$event,$roster,$rankings,$reference);
    $richMap=beta_metric_map($rich['teams']);$ownRow=$richMap[$strategyTeam]??['team'=>$strategyTeam,'rank'=>0,'neptune_epa'=>null,'epa'=>null];
    $remaining=beta_remaining_schedule($pdo,$org,$eventId,$strategyTeam,$rich['teams']);
    $projection=beta_projection($ownRow,$rich['teams'],$remaining);
    $defense=beta_defense_evidence($pdo,$org,$eventId);
    beta_out([
        'ok'=>true,'csrf'=>csrf_token(),'can_edit'=>$canEdit,'event'=>$event,'strategy_team'=>$team,'org_teams'=>alliance_beta_org_teams($pdo,$org),
        'roster'=>$rich['teams'],'shared'=>$shared,'private'=>$private,'drafted'=>alliance_beta_shared_drafted($shared['state']),
        'ranking'=>['label'=>(string)$rankings['label'],'precision'=>(int)$rankings['precision'],'available'=>!empty($rankings['rows']),'error'=>(string)$rankings['error']],
        'projection'=>$projection,'remaining_schedule'=>$remaining,'projected_captains'=>beta_projected_captains($rich['teams']),'defense_evidence'=>$defense,
        'augur_error'=>$rich['augur_error'],'production_reference'=>['available'=>(bool)$reference['available'],'draft_name'=>(string)$reference['draft_name'],'captain_picks_allowed'=>(bool)($reference['captain_picks_allowed']??true)],
    ]);
}

if($_SERVER['REQUEST_METHOD']!=='POST')beta_out(['ok'=>false,'error'=>'Method not allowed.'],405);
$b=beta_body();beta_csrf($b);
$eventId=(int)($b['event_id']??0);$strategyTeam=(int)($b['strategy_team']??0);$action=(string)($b['action']??'');
[$event,$team,$roster,$rankings]=beta_context($pdo,$org,$eventId,$strategyTeam);
$rankRows=(array)$rankings['rows'];
if(!$canEdit)beta_out(['ok'=>false,'error'=>'Read-only access.'],403);

try{
    $pdo->beginTransaction();
    if($action==='set_shared_slot'){
        $alliance=(int)($b['alliance']??0);$slot=(string)($b['slot']??'');$teamNum=(int)($b['team']??0);
        if($alliance<1||$alliance>8||!in_array($slot,['pick1','pick2','backup'],true))throw new RuntimeException('Invalid alliance pick slot. Captains come from qualification rankings.');
        if($teamNum>0&&!alliance_beta_roster_has($roster,$teamNum))throw new RuntimeException('Team is not in this event roster.');
        $load=alliance_beta_load_event_state($pdo,$org,$eventId,true,$rankRows);$state=$load['state'];
        if($teamNum>0){
            if(!empty($state['declined'][(string)$teamNum])||!empty($state['broken'][(string)$teamNum]))throw new RuntimeException('That team is currently marked unavailable event-wide.');
            foreach($state['alliances'] as &$row)foreach(['pick1','pick2','backup'] as $k)if((int)($row[$k]??0)===$teamNum)$row[$k]=null;unset($row);
            unset($state['drafted_elsewhere'][(string)$teamNum]);
        }
        $state['alliances'][(string)$alliance][$slot]=$teamNum>0?$teamNum:null;
        alliance_beta_save_event_state($pdo,$org,$eventId,$state,$userId,$rankRows);
    } elseif($action==='refresh_captains'){
        if(!$rankRows)throw new RuntimeException('Qualification rankings are not available for this event.');
        $load=alliance_beta_load_event_state($pdo,$org,$eventId,true,$rankRows);$state=$load['state'];
        if(alliance_beta_has_any_picks($state))throw new RuntimeException('Clear live draft picks before refreshing captains.');
        alliance_beta_seed_captains($state,$rankRows);alliance_beta_save_event_state($pdo,$org,$eventId,$state,$userId,$rankRows);
    } elseif($action==='toggle_drafted_elsewhere'){
        $teamNum=(int)($b['team']??0);if($teamNum<1||!alliance_beta_roster_has($roster,$teamNum))throw new RuntimeException('Invalid event team.');
        $load=alliance_beta_load_event_state($pdo,$org,$eventId,true,$rankRows);$state=$load['state'];$key=(string)$teamNum;$already=!empty($state['drafted_elsewhere'][$key]);
        foreach($state['alliances'] as &$row)foreach(['pick1','pick2','backup'] as $k)if((int)($row[$k]??0)===$teamNum){$row[$k]=null;$already=true;}unset($row);
        unset($state['drafted_elsewhere'][$key]);if(!$already)$state['drafted_elsewhere'][$key]=true;
        alliance_beta_save_event_state($pdo,$org,$eventId,$state,$userId,$rankRows);
    } elseif(in_array($action,['toggle_declined','toggle_broken'],true)){
        $teamNum=(int)($b['team']??0);if($teamNum<1||!alliance_beta_roster_has($roster,$teamNum))throw new RuntimeException('Invalid event team.');
        $field=$action==='toggle_declined'?'declined':'broken';$other=$field==='declined'?'broken':'declined';
        $load=alliance_beta_load_event_state($pdo,$org,$eventId,true,$rankRows);$state=$load['state'];$key=(string)$teamNum;
        if(!empty($state[$field][$key]))unset($state[$field][$key]);else{$state[$field][$key]=true;unset($state[$other][$key]);}
        alliance_beta_save_event_state($pdo,$org,$eventId,$state,$userId,$rankRows);
    } elseif($action==='set_pick_slot'){
        $tier=(string)($b['tier']??'');$index=(int)($b['index']??-1);$teamNum=(int)($b['team']??0);
        if(!in_array($tier,['1','2','3','4'],true)||$index<0||$index>5)throw new RuntimeException('Invalid pick-list slot.');
        if($teamNum>0&&!alliance_beta_roster_has($roster,$teamNum))throw new RuntimeException('Team is not in this event roster.');
        $load=alliance_beta_load_team_state($pdo,$org,$eventId,$strategyTeam,true);$state=$load['state'];
        if($teamNum>0){foreach($state['pick_lists'] as &$row)foreach($row as &$v)if((int)$v===$teamNum)$v=null;unset($v,$row);}
        $state['pick_lists'][$tier][$index]=$teamNum>0?$teamNum:null;
        alliance_beta_save_team_state($pdo,$org,$eventId,$strategyTeam,$state,$userId);
    } elseif(in_array($action,['toggle_unavailable','toggle_dnp','toggle_favorite'],true)){
        $teamNum=(int)($b['team']??0);if($teamNum<1||!alliance_beta_roster_has($roster,$teamNum))throw new RuntimeException('Invalid event team.');
        $map=['toggle_unavailable'=>'unavailable','toggle_dnp'=>'do_not_pick','toggle_favorite'=>'favorites'];$keyName=$map[$action];
        $load=alliance_beta_load_team_state($pdo,$org,$eventId,$strategyTeam,true);$state=$load['state'];$key=(string)$teamNum;
        if(!empty($state[$keyName][$key]))unset($state[$keyName][$key]);else$state[$keyName][$key]=true;
        alliance_beta_save_team_state($pdo,$org,$eventId,$strategyTeam,$state,$userId);
    } elseif($action==='set_planning_scenario'){
        $scenario=(string)($b['scenario']??'auto');
        if(!in_array($scenario,['auto','captain','first_pick','second_pick','bubble'],true))throw new RuntimeException('Invalid planning scenario.');
        $load=alliance_beta_load_team_state($pdo,$org,$eventId,$strategyTeam,true);$state=$load['state'];
        $state['planning']['scenario']=$scenario;
        alliance_beta_save_team_state($pdo,$org,$eventId,$strategyTeam,$state,$userId);
    } elseif($action==='set_planning_need'){
        $need=(string)($b['need']??'');$value=(int)($b['value']??0);
        if(!in_array($need,['auto','teleop','defense','endgame','reliability'],true))throw new RuntimeException('Invalid alliance need.');
        $load=alliance_beta_load_team_state($pdo,$org,$eventId,$strategyTeam,true);$state=$load['state'];
        $state['planning']['needs'][$need]=max(0,min(3,$value));
        alliance_beta_save_team_state($pdo,$org,$eventId,$strategyTeam,$state,$userId);
    } elseif($action==='import_production_strategy'){
        $ref=beta_current_reference($pdo,$org,$eventId);if(empty($ref['available']))throw new RuntimeException('No current production Alliance Selection strategy is available to import.');
        $existing=alliance_beta_load_team_state($pdo,$org,$eventId,$strategyTeam,true);
        $state=alliance_beta_default_team_state();$state['planning']=$existing['state']['planning']??$state['planning'];
        $state['pick_lists']=$ref['pick_lists'];$state['favorites']=$ref['favorites'];$state['do_not_pick']=$ref['do_not_pick'];$state['unavailable']=$ref['unavailable'];$state['alliance_tags']=$ref['alliance_tags'];
        alliance_beta_save_team_state($pdo,$org,$eventId,$strategyTeam,$state,$userId);
    } elseif($action==='reset_team'){
        alliance_beta_save_team_state($pdo,$org,$eventId,$strategyTeam,alliance_beta_default_team_state(),$userId);
    } elseif($action==='reset_shared'){
        alliance_beta_save_event_state($pdo,$org,$eventId,alliance_beta_default_event_state($rankRows),$userId,$rankRows);
    } else throw new RuntimeException('Unknown beta action.');
    $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();beta_out(['ok'=>false,'error'=>$e->getMessage()],400);}
$shared=alliance_beta_load_event_state($pdo,$org,$eventId,false,$rankRows);$private=alliance_beta_load_team_state($pdo,$org,$eventId,$strategyTeam);
beta_out(['ok'=>true,'shared'=>$shared,'private'=>$private,'drafted'=>alliance_beta_shared_drafted($shared['state'])]);
