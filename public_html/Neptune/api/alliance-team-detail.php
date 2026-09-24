<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_alliance_helpers.php';
require_once dirname(__DIR__).'/analytics/_augur_prediction_model.php';
require_once dirname(__DIR__).'/pit/_helpers.php';
require_once dirname(__DIR__).'/prescout/_helpers.php';
$spotHelper=dirname(__DIR__).'/spot/_helpers.php';
if(is_file($spotHelper)) require_once $spotHelper;

$u=require_login();
$org=(int)$u['organization_id'];
$canEdit=in_array((string)$u['role'],['owner','admin','strategy'],true);
$eventId=(int)($_GET['event_id']??0);
$team=(int)($_GET['team']??0);
if($eventId<1||$team<1) json_response(['ok'=>false,'message'=>'Missing event or team.'],400);

$event=alliance_event($pdo,$org,$eventId);
if(!$event||!alliance_team_on_roster($pdo,$eventId,$team)) json_response(['ok'=>false,'message'=>'That team is not on this organization\'s event roster.'],404);

$s=$pdo->prepare('SELECT nickname,city,state_prov,country FROM event_teams WHERE event_id=? AND frc_team_number=? LIMIT 1');
$s->execute([$eventId,$team]);
$identity=$s->fetch()?:[];

$allStats=neptune_event_robot_stats($pdo,$org,$eventId);
$stats=$allStats[$team]??[
    'matches'=>0,'points'=>0,'ppm'=>0,'success_rate'=>0,'defense_per_match'=>0,'cycle_time'=>null,'actions'=>0,'successes'=>0,'failures'=>0
];

function alliance_detail_value(mixed $value): string {
    if(is_array($value)) return implode(', ',array_values(array_filter(array_map('strval',$value),static fn($x)=>trim($x)!=='')));
    if(is_bool($value)) return $value?'Yes':'No';
    return trim((string)$value);
}

function alliance_tba_avatar_src(mixed $value): string {
    $b64=preg_replace('/\\s+/','',trim((string)$value));
    if($b64==='' || strlen($b64)>3000000) return '';
    if(!preg_match('/^[A-Za-z0-9+\\/=]+$/',$b64)) return '';
    if(base64_decode($b64,true)===false) return '';
    return 'data:image/png;base64,'.$b64;
}

function alliance_tba_http_url(mixed $value): string {
    $url=trim((string)$value);
    if($url==='') return '';
    $parts=parse_url($url);
    if(!is_array($parts)) return '';
    $scheme=strtolower((string)($parts['scheme']??''));
    return in_array($scheme,['http','https'],true)?$url:'';
}

function alliance_tba_location(array $team): string {
    return implode(', ',array_values(array_filter([
        trim((string)($team['city']??'')),
        trim((string)($team['state_prov']??'')),
        trim((string)($team['country']??'')),
    ],static fn($v)=>$v!=='')));
}

function alliance_tba_record_string(mixed $record): string {
    if(!is_array($record)) return '';
    if(!array_key_exists('wins',$record) && !array_key_exists('losses',$record) && !array_key_exists('ties',$record)) return '';
    return (int)($record['wins']??0).'-'.(int)($record['losses']??0).'-'.(int)($record['ties']??0);
}

function alliance_tba_team_number(mixed $key): ?int {
    $value=trim((string)$key);
    if(preg_match('/^frc(\d+)$/i',$value,$m)) return (int)$m[1];
    if(ctype_digit($value)) return (int)$value;
    return null;
}

function alliance_detail_fields(array $data,array $questions): array {
    $map=[];foreach($questions as $q){if(!is_array($q))continue;$code=(string)($q['code']??'');if($code!=='')$map[$code]=$q;}
    $groups=[];
    foreach($data as $code=>$value){
        $code=(string)$code;if($code===''||str_starts_with($code,'_'))continue;
        $display=alliance_detail_value($value);if($display==='')continue;
        $q=$map[$code]??[];
        $group=(string)($q['group']??'Other');
        $label=(string)($q['label']??ucwords(str_replace('_',' ',$code)));
        $groups[$group][]=['code'=>$code,'label'=>$label,'value'=>$display];
    }
    return $groups;
}

$pit=null;$pitData=[];$pitGroups=[];$photos=[];
$s=$pdo->prepare('SELECT * FROM pit_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=? LIMIT 1');
$s->execute([$org,$eventId,$team]);
if($pit=$s->fetch()){
    $pitData=json_decode((string)$pit['data_json'],true);if(!is_array($pitData))$pitData=[];
    $revision=null;
    if(!empty($event['game_revision_id'])) $revision=neptune_revision_by_id($pdo,(int)$event['game_revision_id']);
    $pitQuestions=neptune_pit_all_questions($revision['pit_config_json']??null);
    $pitGroups=alliance_detail_fields($pitData,$pitQuestions);
    $p=$pdo->prepare('SELECT category,file_path,caption,created_at FROM pit_scouting_photos WHERE pit_scouting_id=? AND organization_id=? ORDER BY FIELD(category,\'front\',\'back\',\'left\',\'right\',\'mechanism\',\'other\'),created_at DESC');
    $p->execute([(int)$pit['id'],$org]);
    foreach($p->fetchAll() as $row){
        $photos[]=[
            'category'=>(string)$row['category'],
            'caption'=>(string)($row['caption']??''),
            'url'=>base_url((string)$row['file_path']),
            'created_at'=>(string)$row['created_at'],
        ];
    }
}

$pre=null;$preData=[];$preGroups=[];
$s=$pdo->prepare('SELECT * FROM pre_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=? LIMIT 1');
$s->execute([$org,$eventId,$team]);
if($pre=$s->fetch()){
    $preData=json_decode((string)$pre['data_json'],true);if(!is_array($preData))$preData=[];
    $revision=null;
    if(!empty($event['game_revision_id'])) $revision=neptune_revision_by_id($pdo,(int)$event['game_revision_id']);
    $questions=neptune_prescout_all_questions($revision['pre_scout_config_json']??null,(int)$event['season_year'],(string)$event['game_name']);
    $preGroups=alliance_detail_fields($preData,$questions);
}

$seasonProfile=null;$seasonData=[];
$s=$pdo->prepare('SELECT * FROM robot_season_profiles WHERE organization_id=? AND game_id=? AND frc_team_number=? LIMIT 1');
$s->execute([$org,(int)$event['game_id'],$team]);
if($seasonProfile=$s->fetch()){
    $seasonData=json_decode((string)$seasonProfile['data_json'],true);if(!is_array($seasonData))$seasonData=[];
}

$matches=[];
$s=$pdo->prepare(
    "SELECT m.id,m.comp_level,m.set_number,m.match_number,m.state,m.run_number,m.red_score,m.blue_score,m.winning_alliance,m.scheduled_time,mt.alliance,mt.station,
     COALESCE(SUM(CASE WHEN sa.match_run_number=m.run_number THEN sa.points ELSE 0 END),0) scout_points,
     COUNT(CASE WHEN sa.match_run_number=m.run_number THEN sa.id END) action_count,
     SUM(CASE WHEN sa.match_run_number=m.run_number AND sa.action_type='offense' AND sa.result='Success' THEN 1 ELSE 0 END) offense_success,
     SUM(CASE WHEN sa.match_run_number=m.run_number AND sa.action_type='offense' AND sa.result IN ('Success','Failure') THEN 1 ELSE 0 END) offense_attempts,
     SUM(CASE WHEN sa.match_run_number=m.run_number AND sa.action_type='defense' AND sa.result='Success' THEN 1 ELSE 0 END) defense_actions
     FROM matches m
     JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=?
     LEFT JOIN scouting_actions sa ON sa.match_id=m.id AND sa.organization_id=? AND sa.event_id=? AND sa.frc_team_number=? AND sa.deleted_at IS NULL
     WHERE m.organization_id=? AND m.event_id=?
     GROUP BY m.id,m.comp_level,m.set_number,m.match_number,m.state,m.run_number,m.red_score,m.blue_score,m.winning_alliance,m.scheduled_time,mt.alliance,mt.station
     ORDER BY FIELD(m.comp_level,'qm','ef','qf','sf','f','legacy'),m.set_number,m.match_number"
);
$s->execute([$team,$org,$eventId,$team,$org,$eventId]);
foreach($s->fetchAll() as $m){
    $attempts=(int)$m['offense_attempts'];
    $matches[]=[
        'id'=>(int)$m['id'],
        'run_number'=>(int)$m['run_number'],
        'comp_level'=>(string)$m['comp_level'],
        'match_number'=>(int)$m['match_number'],
        'label'=>neptune_match_label($m),
        'state'=>(string)$m['state'],
        'alliance'=>(string)$m['alliance'],
        'station'=>(int)$m['station'],
        'scout_points'=>round((float)$m['scout_points'],1),
        'actions'=>(int)$m['action_count'],
        'offense_rate'=>$attempts>0?round((int)$m['offense_success']*100/$attempts,1):null,
        'defense'=>(int)$m['defense_actions'],
        'red_score'=>$m['red_score']===null?null:(int)$m['red_score'],
        'blue_score'=>$m['blue_score']===null?null:(int)$m['blue_score'],
        'winning_alliance'=>(string)$m['winning_alliance'],
    ];
}

// Build qualification trend series from matches that were actually scouted. Using
// scout_sessions lets a legitimate 0-point / 0-defense match stay on the chart
// instead of being mistaken for an unscouted match.
$scoutedRuns=[];
$ss=$pdo->prepare(
    "SELECT match_id,match_run_number,COUNT(*) sessions
     FROM scout_sessions
     WHERE organization_id=? AND event_id=? AND frc_team_number=?
     GROUP BY match_id,match_run_number"
);
$ss->execute([$org,$eventId,$team]);
foreach($ss->fetchAll() as $row){
    $scoutedRuns[(int)$row['match_id'].':'.(int)$row['match_run_number']]=(int)$row['sessions'];
}

$qualificationTrend=[];
foreach($matches as &$matchRow){
    $key=(int)$matchRow['id'].':'.(int)($matchRow['run_number']??0);
    $matchRow['scouted']=isset($scoutedRuns[$key]) || (int)$matchRow['actions']>0;
    if((string)$matchRow['comp_level']!=='qm' || !$matchRow['scouted']) continue;
    $qualificationTrend[]=[
        'match_id'=>(int)$matchRow['id'],
        'match_number'=>(int)$matchRow['match_number'],
        'label'=>(string)$matchRow['label'],
        'points_per_match'=>(float)$matchRow['scout_points'],
        'defense_actions'=>(int)$matchRow['defense'],
        'offense_success_rate'=>$matchRow['offense_rate'],
    ];
}
unset($matchRow);

function alliance_rolling_average(array $values,int $span=3): array {
    $out=[];
    foreach($values as $i=>$value){
        $start=max(0,$i-$span+1);
        $slice=array_slice($values,$start,$i-$start+1);
        $out[]=count($slice)?array_sum($slice)/count($slice):0;
    }
    return $out;
}
$offenseValues=array_map(static fn($r)=>(float)$r['points_per_match'],$qualificationTrend);
$defenseValues=array_map(static fn($r)=>(float)$r['defense_actions'],$qualificationTrend);
$offenseRolling=alliance_rolling_average($offenseValues,3);
$defenseRolling=alliance_rolling_average($defenseValues,3);
foreach($qualificationTrend as $i=>&$trendRow){
    $trendRow['points_rolling_3']=round((float)($offenseRolling[$i]??0),2);
    $trendRow['defense_rolling_3']=round((float)($defenseRolling[$i]??0),2);
}
unset($trendRow);

$actions=[];
$s=$pdo->prepare(
    "SELECT phase,COALESCE(NULLIF(action_name,''),action_code) action_label,action_type,result,COUNT(*) attempts,COALESCE(SUM(points),0) points
     FROM scouting_actions
     WHERE organization_id=? AND event_id=? AND frc_team_number=? AND deleted_at IS NULL
     GROUP BY phase,action_code,action_name,action_type,result
     ORDER BY FIELD(phase,'auton','teleop','endgame','post_match','unknown'),action_type,action_label,result"
);
$s->execute([$org,$eventId,$team]);
foreach($s->fetchAll() as $row){
    $actions[]=[
        'phase'=>(string)$row['phase'],
        'label'=>(string)$row['action_label'],
        'type'=>(string)($row['action_type']??''),
        'result'=>(string)$row['result'],
        'attempts'=>(int)$row['attempts'],
        'points'=>round((float)$row['points'],1),
    ];
}

$epaRating=[];$epaRatingError='';
if(augur_epa_tables_ready($pdo)){
    try{
        $map=augur_epa_rating_map($pdo,$org,$event,[$team],null);
        $base=$map[$team]??[];
        $eventKey=trim((string)($event['tba_event_key']??''));
        $eventRating=$eventKey!==''?augur_epa_event_rating($pdo,$eventKey,$team):null;
        $seasonRating=augur_epa_season_rating($pdo,(int)$event['season_year'],$team);
        if($base){
            $rank=null;
            if($eventRating&&$eventKey!==''){
                $q=$pdo->prepare('SELECT 1+COUNT(*) FROM augur_epa_event_ratings WHERE tba_event_key=? AND rating>?');
                $q->execute([$eventKey,(float)$eventRating['rating']]);$rank=(int)$q->fetchColumn();
            }
            $r=$eventRating?:$seasonRating?:[];
            $record=$r?(int)($r['wins']??0).'-'.(int)($r['losses']??0).'-'.(int)($r['ties']??0):'—';
            $settings=augur_prediction_default_settings();
            try{
                if(alliance_schema_ready($pdo)){
                    $loaded=alliance_load_workspace($pdo,$org,$eventId,false);
                    $settings=augur_prediction_settings($loaded['workspace']['settings']['matchup_model']??[]);
                }
            }catch(Throwable $ignored){}
            $plainEpa=isset($base['epa'])&&is_numeric($base['epa'])?(float)$base['epa']:null;
            $blend=augur_prediction_offense_blend($offenseValues,$plainEpa,$settings);
            $epaRating=[
                'epa'=>$plainEpa,'auto'=>$base['auto']??null,'teleop'=>$base['teleop']??null,'endgame'=>$base['endgame']??null,
                'augur_epa'=>$blend['weight_total']>0?$blend['augur_epa']:null,
                'scouting_matches'=>count($offenseValues),'scouting_influence'=>round((float)$blend['scouting_influence']*100,1),
                'confidence'=>$r['confidence']??null,'sigma'=>$r['sigma']??null,'trend'=>$r['trend']??null,'matches'=>$r['matches_played']??0,
                'record'=>$record,'rank'=>$rank,'model_version'=>$r['model_version']??AUGUR_EPA_MODEL_VERSION,'updated_at'=>$r['updated_at']??null,
            ];
        }
    }catch(Throwable $e){$epaRatingError=$e->getMessage();}
}else{$epaRatingError='EPA ratings are not installed yet.';}

/*
|--------------------------------------------------------------------------
| Spot Scouting
|--------------------------------------------------------------------------
| Alliance Selection shows organization-private observations only. Current
| event observations plus event-less general team observations are included.
| Media stays protected behind spot/media.php and requires the same login/org.
*/
$spot=[
    'available'=>false,
    'observations'=>[],
    'tag_counts'=>[],
    'open_warning_count'=>0,
    'photo_count'=>0,
    'video_count'=>0,
    'latest_at'=>null,
];
if(function_exists('spot_tables_ready')&&spot_tables_ready($pdo)&&function_exists('spot_observation_rows')){
    try{
        $candidateRows=spot_observation_rows($pdo,$org,null,$team,200,false);
        $spotRows=[];
        foreach($candidateRows as $row){
            $rowEvent=$row['event_id']===null?null:(int)$row['event_id'];
            if($rowEvent!==null&&$rowEvent!==$eventId)continue;
            $spotRows[]=$row;
            if(count($spotRows)>=80)break;
        }

        $mediaMap=function_exists('spot_media_for_observations')
            ? spot_media_for_observations($pdo,$org,array_column($spotRows,'id'))
            : [];

        $tagCounts=[];
        $openWarnings=0;$photoCount=0;$videoCount=0;$latestAt=null;
        foreach($spotRows as &$row){
            $row['media']=$mediaMap[(int)$row['id']]??[];
            $photoCount+=(int)($row['photo_count']??0);
            $videoCount+=(int)($row['video_count']??0);
            if((string)($row['status']??'open')==='open'&&in_array((string)($row['severity']??'info'),['warning','critical'],true))$openWarnings++;
            $created=(string)($row['created_at']??'');
            if($created!==''&&($latestAt===null||$created>$latestAt))$latestAt=$created;
            foreach((array)($row['tags']??[]) as $tag){
                $id=(int)($tag['id']??0);
                $key=$id>0?'id:'.$id:'label:'.strtolower((string)($tag['label']??''));
                if(!isset($tagCounts[$key])){
                    $tagCounts[$key]=[
                        'id'=>$id,
                        'label'=>(string)($tag['label']??'Tag'),
                        'icon'=>(string)($tag['icon']??'fa-solid fa-tag'),
                        'severity'=>(string)($tag['severity']??'info'),
                        'count'=>0,
                    ];
                }
                $tagCounts[$key]['count']++;
            }
        }
        unset($row);
        $tagCounts=array_values($tagCounts);
        usort($tagCounts,static function($a,$b){
            $cmp=((int)$b['count'])<=>((int)$a['count']);
            if($cmp!==0)return $cmp;
            $rank=['critical'=>4,'warning'=>3,'positive'=>2,'info'=>1];
            $sev=($rank[$b['severity']]??0)<=>($rank[$a['severity']]??0);
            return $sev!==0?$sev:strcmp((string)$a['label'],(string)$b['label']);
        });

        $spot=[
            'available'=>true,
            'observations'=>$spotRows,
            'tag_counts'=>$tagCounts,
            'open_warning_count'=>$openWarnings,
            'photo_count'=>$photoCount,
            'video_count'=>$videoCount,
            'latest_at'=>$latestAt,
        ];
    }catch(Throwable $ignored){
        $spot['available']=true;
    }
}


$tbaProfile=[
    'available'=>false,
    'nickname'=>(string)($identity['nickname']??''),
    'full_name'=>'',
    'location'=>alliance_tba_location($identity),
    'maps_url'=>'',
    'district'=>null,
    'rookie_year'=>null,
    'last_competed'=>null,
    'website'=>'',
    'avatar'=>'',
    'tba_url'=>'https://www.thebluealliance.com/team/'.$team,
    'first_url'=>'https://frc-events.firstinspires.org/team/'.$team,
    'error'=>'',
];
try{
    require_once dirname(__DIR__,3).'/neptune_secure/tba.php';
    $teamKey='frc'.$team;
    $live=tba_get('team/'.$teamKey,21600);
    if(is_array($live) && !empty($live['team_number'])){
        $tbaProfile['available']=true;
        $tbaProfile['nickname']=trim((string)($live['nickname']??$tbaProfile['nickname']));
        $tbaProfile['full_name']=trim((string)($live['name']??''));
        $tbaProfile['location']=alliance_tba_location($live) ?: $tbaProfile['location'];
        if($tbaProfile['location']!==''){
            $tbaProfile['maps_url']='https://maps.google.com/maps?q='.rawurlencode($tbaProfile['location']);
        }
        $rookie=(int)($live['rookie_year']??0);
        $tbaProfile['rookie_year']=$rookie>0?$rookie:null;
        $tbaProfile['website']=alliance_tba_http_url($live['website']??'');
    }

    $districtRows=tba_get('team/'.$teamKey.'/districts',21600);
    if(is_array($districtRows)){
        $seasonYear=(int)($event['season_year']??0);
        $selectedDistrict=null;
        foreach($districtRows as $row){
            if(!is_array($row)) continue;
            $year=(int)($row['year']??0);
            if($seasonYear>0 && $year===$seasonYear){ $selectedDistrict=$row; break; }
        }
        if($selectedDistrict){
            $name=trim((string)($selectedDistrict['display_name']??''));
            if($name!=='' && !preg_match('/\\bdistrict$/i',$name)) $name.=' District';
            $abbr=trim((string)($selectedDistrict['abbreviation']??''));
            $year=(int)($selectedDistrict['year']??$seasonYear);
            $tbaProfile['district']=[
                'name'=>$name,
                'year'=>$year,
                'key'=>(string)($selectedDistrict['key']??''),
                'url'=>$abbr!==''&&$year>0?'https://www.thebluealliance.com/events/'.rawurlencode(strtolower($abbr)).'/'.$year:'',
            ];
        }
    }

    $years=tba_get('team/'.$teamKey.'/years_participated',21600);
    if(is_array($years)){
        $years=array_values(array_filter(array_map('intval',$years),static fn($y)=>$y>0));
        if($years) $tbaProfile['last_competed']=max($years);
    }

    $seasonYear=(int)($event['season_year']??date('Y'));
    $mediaYears=array_values(array_unique(array_filter([$seasonYear,$seasonYear-1,$seasonYear-2],static fn($y)=>$y>=1992)));
    foreach($mediaYears as $mediaYear){
        $media=tba_get('team/'.$teamKey.'/media/'.$mediaYear,21600);
        if(!is_array($media)) continue;
        foreach($media as $item){
            if(!is_array($item) || (string)($item['type']??'')!=='avatar') continue;
            $avatar=alliance_tba_avatar_src($item['details']['base64Image']??'');
            if($avatar!==''){
                $tbaProfile['avatar']=$avatar;
                break 2;
            }
        }
    }
}catch(Throwable $e){
    $tbaProfile['error']=$e->getMessage();
}

$tbaMatches=[];
$tbaMatchesError='';
try{
    require_once dirname(__DIR__,3).'/neptune_secure/tba.php';
    $eventKey=trim((string)($event['tba_event_key']??''));
    if($eventKey===''){
        $tbaMatchesError='This event is not linked to The Blue Alliance.';
    }else{
        $rawMatches=tba_get('event/'.rawurlencode($eventKey).'/matches',120);
        if(!is_array($rawMatches)) $rawMatches=[];
        $teamKey='frc'.$team;
        $levelOrder=['qm'=>1,'ef'=>2,'qf'=>3,'sf'=>4,'f'=>5];
        foreach($rawMatches as $tm){
            if(!is_array($tm)) continue;
            $alliances=is_array($tm['alliances']??null)?$tm['alliances']:[];
            $teamAlliance='';
            foreach(['red','blue'] as $color){
                $keys=is_array($alliances[$color]['team_keys']??null)?$alliances[$color]['team_keys']:[];
                if(in_array($teamKey,$keys,true)){ $teamAlliance=$color; break; }
            }
            if($teamAlliance==='') continue;

            $comp=(string)($tm['comp_level']??'qm');
            $set=(int)($tm['set_number']??0);
            $match=(int)($tm['match_number']??0);
            $label=match($comp){
                'qm'=>'QM '.$match,
                'ef'=>'EF '.($set>0?$set.'-':'').$match,
                'qf'=>'QF '.($set>0?$set.'-':'').$match,
                'sf'=>'SF '.($set>0?$set.'-':'').$match,
                'f'=>'F '.$match,
                default=>strtoupper($comp).' '.($set>0?$set.'-':'').$match,
            };

            $redRaw=$alliances['red']['score']??null;
            $blueRaw=$alliances['blue']['score']??null;
            $redScore=is_numeric($redRaw)&&(int)$redRaw>=0?(int)$redRaw:null;
            $blueScore=is_numeric($blueRaw)&&(int)$blueRaw>=0?(int)$blueRaw:null;
            $winner=(string)($tm['winning_alliance']??'');
            $result='';
            if($winner==='red'||$winner==='blue') $result=$winner===$teamAlliance?'W':'L';
            elseif($redScore!==null&&$blueScore!==null&&$redScore===$blueScore) $result='T';

            $youtube='';
            foreach((array)($tm['videos']??[]) as $video){
                if(!is_array($video)) continue;
                if((string)($video['type']??'')==='youtube' && trim((string)($video['key']??''))!==''){
                    $youtube=trim((string)$video['key']);
                    break;
                }
            }
            $redTeams=[];$blueTeams=[];
            foreach((array)($alliances['red']['team_keys']??[]) as $key){ if(preg_match('/^frc(\d+)$/',(string)$key,$m)) $redTeams[]=(int)$m[1]; }
            foreach((array)($alliances['blue']['team_keys']??[]) as $key){ if(preg_match('/^frc(\d+)$/',(string)$key,$m)) $blueTeams[]=(int)$m[1]; }

            $tbaMatches[]=[
                'key'=>(string)($tm['key']??''),
                'label'=>$label,
                'comp_level'=>$comp,
                'set_number'=>$set,
                'match_number'=>$match,
                'alliance'=>$teamAlliance,
                'result'=>$result,
                'red_score'=>$redScore,
                'blue_score'=>$blueScore,
                'red_teams'=>$redTeams,
                'blue_teams'=>$blueTeams,
                'youtube_id'=>$youtube,
                'time'=>is_numeric($tm['actual_time']??null)?(int)$tm['actual_time']:(is_numeric($tm['time']??null)?(int)$tm['time']:null),
                '_order'=>$levelOrder[$comp]??99,
            ];
        }
        usort($tbaMatches,static fn($a,$b)=>($a['_order']<=>$b['_order']) ?: ($a['set_number']<=>$b['set_number']) ?: ($a['match_number']<=>$b['match_number']));
        foreach($tbaMatches as &$row) unset($row['_order']);
        unset($row);
    }
}catch(Throwable $e){$tbaMatchesError=$e->getMessage();}

$history=[];
$historyError='';
$eventList=[];
try{
    require_once dirname(__DIR__,3).'/neptune_secure/tba.php';
    $teamKey='frc'.$team;
    $eventList=tba_get('team/'.$teamKey.'/events/simple',21600);
    if(!is_array($eventList))$eventList=[];
    $eventMap=[];$years=[];
    foreach($eventList as $ev){
        if(!is_array($ev))continue;
        $key=(string)($ev['key']??'');$year=(int)($ev['year']??0);
        if($key==='')continue;
        $eventMap[$key]=['name'=>(string)($ev['name']??$key),'year'=>$year];
        if($year>0)$years[$year]=true;
    }
    $years=array_keys($years);rsort($years);$years=array_slice($years,0,8);
    foreach($years as $year){
        $statuses=tba_get('team/'.$teamKey.'/events/'.$year.'/statuses',21600);
        if(!is_array($statuses))continue;
        foreach($statuses as $eventKey=>$status){
            if(!is_array($status)||!is_array($status['alliance']??null))continue;
            $a=$status['alliance'];
            $number=(int)($a['number']??0);if($number<1)continue;
            $pick=isset($a['pick'])&&is_numeric($a['pick'])?(int)$a['pick']:null;
            $role=$pick===0?'Captain':($pick===1?'Pick 1':($pick===2?'Pick 2':($pick!==null?'Pick '.($pick+1):'Alliance Member')));
            if(!empty($a['backup']))$role='Backup';
            $meta=$eventMap[$eventKey]??['name'=>$eventKey,'year'=>(int)$year];

            $qual=is_array($status['qual']??null)?$status['qual']:[];
            $ranking=is_array($qual['ranking']??null)?$qual['ranking']:[];
            $playoff=is_array($status['playoff']??null)?$status['playoff']:[];
            $qualRecord=alliance_tba_record_string($ranking['record']??null);
            $playoffRecord=alliance_tba_record_string($playoff['record']??null);

            $history[]=[
                'year'=>(int)$meta['year'],
                'event'=>(string)$meta['name'],
                'event_key'=>(string)$eventKey,
                'event_url'=>'https://www.thebluealliance.com/event/'.rawurlencode((string)$eventKey),
                'alliance'=>$number,
                'role'=>$role,
                'qual_rank'=>isset($ranking['rank'])&&is_numeric($ranking['rank'])?(int)$ranking['rank']:null,
                'qual_teams'=>isset($qual['num_teams'])&&is_numeric($qual['num_teams'])?(int)$qual['num_teams']:null,
                'qual_record'=>$qualRecord,
                'playoff_record'=>$playoffRecord,
                'playoff_status'=>trim((string)($playoff['status']??'')),
                'playoff_level'=>trim((string)($playoff['level']??'')),
                'status'=>trim(html_entity_decode(strip_tags((string)($status['overall_status_str']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8')),
                'alliance_teams'=>[],
                'backups'=>[],
            ];
        }
    }

    usort($history,static fn($a,$b)=>($b['year']<=>$a['year']) ?: strcmp($b['event_key'],$a['event_key']));
    // Bound the enriched list so veteran-team history does not trigger hundreds
    // of event-alliance requests when Team Intelligence opens.
    $history=array_slice($history,0,18);

    $allianceCache=[];
    foreach($history as &$historyRow){
        $eventKey=(string)$historyRow['event_key'];
        if(!array_key_exists($eventKey,$allianceCache)){
            try{
                $rows=tba_get('event/'.rawurlencode($eventKey).'/alliances',21600);
                $allianceCache[$eventKey]=is_array($rows)?$rows:[];
            }catch(Throwable $ignored){
                $allianceCache[$eventKey]=[];
            }
        }

        $number=(int)$historyRow['alliance'];
        $allianceRows=$allianceCache[$eventKey];
        $allianceRow=(isset($allianceRows[$number-1])&&is_array($allianceRows[$number-1]))?$allianceRows[$number-1]:null;
        if(!$allianceRow){
            foreach($allianceRows as $candidate){
                if(is_array($candidate)&&(int)($candidate['number']??0)===$number){$allianceRow=$candidate;break;}
            }
        }
        if(!$allianceRow)continue;

        $picks=is_array($allianceRow['picks']??null)?$allianceRow['picks']:[];
        $members=[];
        foreach($picks as $i=>$key){
            $member=alliance_tba_team_number($key);
            if(!$member)continue;
            $members[]=[
                'team'=>$member,
                'role'=>$i===0?'Captain':('Pick '.$i),
                'is_team'=>$member===$team,
            ];
        }
        $historyRow['alliance_teams']=$members;

        $backups=[];
        $backupRaw=$allianceRow['backup']??null;
        if(is_array($backupRaw) && (array_key_exists('in',$backupRaw) || array_key_exists('out',$backupRaw))){
            $backupRaw=[$backupRaw];
        }
        foreach((array)$backupRaw as $backup){
            if(!is_array($backup))continue;
            $in=alliance_tba_team_number($backup['in']??null);
            $out=alliance_tba_team_number($backup['out']??null);
            if($in||$out)$backups[]=['in'=>$in,'out'=>$out];
        }
        $historyRow['backups']=$backups;
    }
    unset($historyRow);
}catch(Throwable $e){$historyError=$e->getMessage();}

json_response([
    'ok'=>true,
    'can_edit'=>$canEdit,
    'team'=>[
        'number'=>$team,
        'nickname'=>(string)($identity['nickname']??''),
        'city'=>(string)($identity['city']??''),
        'state_prov'=>(string)($identity['state_prov']??''),
        'country'=>(string)($identity['country']??''),
    ],
    'tba_profile'=>$tbaProfile,
    'event'=>[
        'id'=>$eventId,
        'name'=>(string)$event['name'],
        'game_name'=>(string)$event['game_name'],
        'season_year'=>(int)$event['season_year'],
    ],
    'event_stats'=>[
        'matches'=>(int)($stats['matches']??0),
        'points'=>round((float)($stats['points']??0),1),
        'ppm'=>round((float)($stats['ppm']??0),1),
        'success_rate'=>round((float)($stats['success_rate']??0),1),
        'defense_per_match'=>round((float)($stats['defense_per_match']??0),2),
        'cycle_time'=>isset($stats['cycle_time'])&&$stats['cycle_time']!==null?round((float)$stats['cycle_time'],1):null,
        'actions'=>(int)($stats['actions']??0),
        'successes'=>(int)($stats['successes']??0),
        'failures'=>(int)($stats['failures']??0),
    ],
    'epa_rating'=>$epaRating,
    'epa_rating_error'=>$epaRatingError,
    // Compatibility aliases for clients from the previous AUGUR build.
    'augur_rating'=>$epaRating,
    'augur_rating_error'=>$epaRatingError,
    'matches'=>$matches,
    'tba_matches'=>$tbaMatches,
    'tba_matches_error'=>$tbaMatchesError,
    'trends'=>[
        'qualification'=>$qualificationTrend,
        'source'=>'Neptune scouting',
        'offense_metric'=>'points_per_match',
        'defense_metric'=>'successful_defensive_actions_per_match',
    ],
    'actions'=>$actions,
    'spot'=>$spot,
    'pit'=>[
        'status'=>$pit?(string)$pit['status']:'not_scouted',
        'updated_at'=>$pit?(string)$pit['updated_at']:'',
        'notes'=>$pit?(string)($pit['notes']??''):'',
        'groups'=>$pitGroups,
    ],
    'pre_scout'=>[
        'status'=>$pre?(string)$pre['status']:'not_scouted',
        'updated_at'=>$pre?(string)$pre['updated_at']:'',
        'notes'=>$pre?(string)($pre['notes']??''):'',
        'groups'=>$preGroups,
    ],
    'season_profile'=>$seasonData,
    'photos'=>$photos,
    'history'=>$history,
    'history_error'=>$historyError,
    'history_source'=>'The Blue Alliance',
]);
