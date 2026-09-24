<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/tba.php';
require_once __DIR__.'/_helpers.php';
$u=require_login();$org=(int)$u['organization_id'];$eventId=(int)($_GET['event_id']??$_POST['event_id']??0);$team=(int)($_GET['team']??$_POST['team']??0);$msg='';$error='';

$s=$pdo->prepare("SELECT e.*,g.name game_name,g.season_year,gr.pre_scout_config_json,gr.field_image_path,gr.revision_number game_revision_number,et.nickname,et.city,et.state_prov,et.country,et.tba_team_key FROM events e JOIN games g ON g.id=e.game_id JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id JOIN event_teams et ON et.event_id=e.id AND et.frc_team_number=? WHERE e.id=? AND e.organization_id=?");$s->execute([$team,$eventId,$org]);$event=$s->fetch();if(!$event){http_response_code(404);exit('Team/event not found.');}
$questions=neptune_prescout_all_questions($event['pre_scout_config_json'],(int)$event['season_year'],(string)$event['game_name']);

// NEPTUNE_PRESCOUT_AUTON_CANVAS_V1
// Use the same per-game field background managed in VULCAN -> Game Builder.
$autonFieldPath=neptune_revision_field_path($event,(int)($event['game_id']??0),$org);
$autonFieldUrl=$autonFieldPath!==''?'/'.ltrim($autonFieldPath,'/'):'';

function pre_safe_tba(string $path,int $ttl=3600): array { try{return tba_get($path,$ttl);}catch(Throwable $e){return [];} }

// Pull public reference data. All external sources are optional; the form still works offline/from cache.
$tba=pre_safe_tba('team/frc'.$team,86400);
if($tba){
    $pdo->prepare('UPDATE event_teams SET nickname=COALESCE(?,nickname),city=COALESCE(?,city),state_prov=COALESCE(?,state_prov),country=COALESCE(?,country),tba_team_key=COALESCE(?,tba_team_key) WHERE event_id=? AND frc_team_number=?')->execute([$tba['nickname']??null,$tba['city']??null,$tba['state_prov']??null,$tba['country']??null,$tba['key']??null,$eventId,$team]);
    $event['nickname']=$tba['nickname']??$event['nickname'];$event['city']=$tba['city']??$event['city'];$event['state_prov']=$tba['state_prov']??$event['state_prov'];$event['country']=$tba['country']??$event['country'];
}
$tbaStatuses=pre_safe_tba('team/frc'.$team.'/events/'.(int)$event['season_year'].'/statuses',3600);
$epaSummary=neptune_prescout_public_epa_team($pdo,(int)$event['season_year'],$team);

// Read existing season/event knowledge before refreshing the public-data cache.
$s=$pdo->prepare('SELECT * FROM robot_season_profiles WHERE organization_id=? AND game_id=? AND frc_team_number=?');$s->execute([$org,$event['game_id'],$team]);$seasonProfile=$s->fetch()?:null;$profileData=$seasonProfile?(json_decode($seasonProfile['data_json']??'{}',true)?:[]):[];$cachedIntel=$seasonProfile?neptune_prescout_profile_stats($seasonProfile):[];if(!$epaSummary&&is_array($cachedIntel['public_epa']??null))$epaSummary=$cachedIntel['public_epa'];if(!$epaSummary&&is_array($cachedIntel['statbotics']??null))$epaSummary=$cachedIntel['statbotics'];
$s=$pdo->prepare('SELECT ps.*,e.name event_name FROM pre_scouting ps JOIN events e ON e.id=ps.event_id WHERE ps.organization_id=? AND ps.game_id=? AND ps.frc_team_number=? AND ps.event_id<>? ORDER BY ps.updated_at DESC LIMIT 1');$s->execute([$org,$event['game_id'],$team,$eventId]);$priorPre=$s->fetch()?:null;$priorPreData=$priorPre?(json_decode($priorPre['data_json']??'{}',true)?:[]):[];
$s=$pdo->prepare('SELECT ps.*,e.name event_name FROM pit_scouting ps JOIN events e ON e.id=ps.event_id WHERE ps.organization_id=? AND e.game_id=? AND ps.frc_team_number=? AND ps.event_id<>? ORDER BY ps.updated_at DESC LIMIT 1');$s->execute([$org,$event['game_id'],$team,$eventId]);$priorPit=$s->fetch()?:null;$priorPitData=$priorPit?(json_decode($priorPit['data_json']??'{}',true)?:[]):[];
$s=$pdo->prepare('SELECT * FROM pre_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=?');$s->execute([$org,$eventId,$team]);$current=$s->fetch()?:null;$currentData=$current?(json_decode($current['data_json']??'{}',true)?:[]):[];

$profileData=neptune_prescout_normalize_robot_answers($profileData);
$priorPreData=neptune_prescout_normalize_robot_answers($priorPreData);
$priorPitData=neptune_prescout_normalize_robot_answers($priorPitData);
$currentData=neptune_prescout_normalize_robot_answers($currentData);

$knownData=[];$knownSource=[];
foreach($priorPitData as $k=>$v){if($k!=='scout'&&neptune_prescout_nonempty($v)){$knownData[$k]=$v;$knownSource[$k]='pit';}}
foreach($priorPreData as $k=>$v){if($k!=='scout'&&neptune_prescout_nonempty($v)){$knownData[$k]=$v;$knownSource[$k]='prior_pre';}}
foreach($profileData as $k=>$v){if($k!=='scout'&&neptune_prescout_nonempty($v)){$knownData[$k]=$v;$knownSource[$k]='season';}}
$data=$knownData;foreach($currentData as $k=>$v)$data[$k]=$v;

// Build prior-event and season-to-date OPR from Neptune's local database.
// No TBA /oprs request is needed. Qualification scores in matches + match_teams
// reproduce traditional TBA-style OPR and remain available with no Internet.
$currentStart=(string)($event['start_date']??'');
$localOprContext=neptune_prescout_local_opr_context($pdo,$org,(int)$event['game_id'],$eventId,$currentStart,[$team]);
$localOpr=$localOprContext['teams'][$team]??[];
$oprHistory=is_array($localOpr['events']??null)?array_values($localOpr['events']):[];
$eventHistory=[];
foreach($oprHistory as $oh){
    $key=(string)($oh['event_key']??'');
    $status=is_array($tbaStatuses[$key]??null)?$tbaStatuses[$key]:[];
    $statusText=neptune_prescout_tba_status_text($status);
    $label=$key!==''?$key:(string)($oh['event_name']??'Prior event');
    $short=$label.($statusText!==''?': '.$statusText:'');
    $eventHistory[]=[
        'key'=>$key,
        'name'=>$oh['event_name']??$label,
        'start_date'=>$oh['start_date']??null,
        'status'=>$statusText,
        'short'=>$short,
        'opr'=>is_numeric($oh['opr']??null)?(float)$oh['opr']:null,
    ];
}
if(!$eventHistory&&is_array($cachedIntel['event_history']??null))$eventHistory=$cachedIntel['event_history'];
if(!$oprHistory&&is_array($cachedIntel['oprs']??null))$oprHistory=$cachedIntel['oprs'];
$opr1=$localOpr['opr1']??($oprHistory[0]['opr']??null);
$opr2=$localOpr['opr2']??($oprHistory[1]['opr']??null);
$oprTotal=$localOpr['season_opr']??($cachedIntel['season_opr']??null);
if(!is_numeric($oprTotal)){
    // Compatibility for profiles saved before Season OPR existed.
    $oprTotal=(is_numeric($opr1)?(float)$opr1:0)+(is_numeric($opr2)?(float)$opr2:0);
    if(!is_numeric($opr1)&&!is_numeric($opr2))$oprTotal=null;
}

// Cache the public reference data in the season profile so the event overview can
// display it without making dozens of TBA requests.
$existingCache=$seasonProfile?(json_decode((string)($seasonProfile['tba_json']??''),true)?:[]):[];
$existingCache['team']=$tba?:($existingCache['team']??[]);
$prescoutIntel=is_array($existingCache['prescout_intel']??null)?$existingCache['prescout_intel']:[];
$prescoutIntel['public_epa']=$epaSummary?:($prescoutIntel['public_epa']??[]);
$prescoutIntel['oprs']=$oprHistory?:($prescoutIntel['oprs']??[]);
if(is_numeric($oprTotal))$prescoutIntel['season_opr']=(float)$oprTotal;
if(isset($localOpr['matches_used']))$prescoutIntel['opr_matches_used']=(int)$localOpr['matches_used'];
$prescoutIntel['event_history']=$eventHistory?:($prescoutIntel['event_history']??[]);
$prescoutIntel['refreshed_at']=gmdate('c');
$existingCache['prescout_intel']=$prescoutIntel;
$profileCacheJson=json_encode($existingCache,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
if($tba||$epaSummary||$oprHistory||$eventHistory){
    $cacheSql="INSERT INTO robot_season_profiles(organization_id,game_id,frc_team_number,data_json,tba_json,tba_updated_at,last_event_id) VALUES(?,?,?,'{}',?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE tba_json=VALUES(tba_json),tba_updated_at=UTC_TIMESTAMP(),last_event_id=VALUES(last_event_id)";
    $pdo->prepare($cacheSql)->execute([$org,$event['game_id'],$team,$profileCacheJson,$eventId]);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $posted=is_array($_POST['pre']??null)?$_POST['pre']:[];$clean=[];$missing=[];
        foreach($questions as $q){$code=(string)($q['code']??'');if($code==='')continue;$type=(string)($q['type']??'text');$v=$posted[$code]??($type==='multiselect'?[]:'');if($type==='multiselect'){$v=is_array($v)?array_values(array_filter(array_map('trim',$v),fn($x)=>$x!=='')):[];}else{$v=trim((string)$v);}if(!empty($q['required'])&&($_POST['save_mode']??'complete')==='complete'&&!neptune_prescout_nonempty($v))$missing[]=(string)($q['label']??$code);$clean[$code]=$v;}
        $clean['scout']=trim((string)($_POST['scout']??''));
        $autonRaw=trim((string)($_POST['auton_path_json']??''));
        if($autonRaw!==''){
            if(strlen($autonRaw)>300000)throw new RuntimeException('Autonomous path is too large.');
            $auton=json_decode($autonRaw,true);
            if(!is_array($auton)||!isset($auton['strokes'])||!is_array($auton['strokes']))throw new RuntimeException('Invalid autonomous path data.');
            $safeStrokes=[];
            foreach(array_slice($auton['strokes'],0,60) as $stroke){
                if(!is_array($stroke))continue;
                $safePoints=[];
                foreach(array_slice($stroke,0,2500) as $point){
                    if(!is_array($point)||!is_numeric($point['x']??null)||!is_numeric($point['y']??null))continue;
                    $safePoints[]=[
                        'x'=>max(0,min(1,(float)$point['x'])),
                        'y'=>max(0,min(1,(float)$point['y'])),
                    ];
                }
                if($safePoints)$safeStrokes[]=$safePoints;
            }
            $clean['_auton_path']=['version'=>1,'strokes'=>$safeStrokes];
        }elseif(isset($currentData['_auton_path'])&&is_array($currentData['_auton_path'])){
            $clean['_auton_path']=$currentData['_auton_path'];
        }elseif(isset($data['_auton_path'])&&is_array($data['_auton_path'])){
            $clean['_auton_path']=$data['_auton_path'];
        }
        if($missing)throw new RuntimeException('Complete the required fields: '.implode(', ',$missing));
        $recordStatus=(($_POST['save_mode']??'complete')==='draft')?'in_progress':'complete';$contactStatus=(string)($_POST['contact_status']??'not_started');if(!in_array($contactStatus,['not_started','contacted','received','no_response','unavailable'],true))$contactStatus='not_started';if($recordStatus==='complete'&&$contactStatus==='not_started')$contactStatus='received';
        $contactedAt=null;$rawDate=trim((string)($_POST['contacted_at']??''));if($rawDate!==''){$dt=new DateTime($rawDate);$dt->setTimezone(new DateTimeZone('UTC'));$contactedAt=$dt->format('Y-m-d H:i:s');}elseif($contactStatus!=='not_started'){$contactedAt=$current['contacted_at']??gmdate('Y-m-d H:i:s');}
        $notes=trim((string)($_POST['notes']??''));$inheritEvent=(int)($current['inherited_from_event_id']??($priorPre['event_id']??0));$json=json_encode($clean,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $sql="INSERT INTO pre_scouting(organization_id,event_id,game_id,frc_team_number,submitted_by,contact_status,contact_name,contact_method,contact_details,contacted_at,data_json,notes,status,inherited_from_event_id,completed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,IF(?='complete',UTC_TIMESTAMP(),NULL)) ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),submitted_by=VALUES(submitted_by),contact_status=VALUES(contact_status),contact_name=VALUES(contact_name),contact_method=VALUES(contact_method),contact_details=VALUES(contact_details),contacted_at=VALUES(contacted_at),data_json=VALUES(data_json),notes=VALUES(notes),status=VALUES(status),inherited_from_event_id=COALESCE(inherited_from_event_id,VALUES(inherited_from_event_id)),completed_at=IF(VALUES(status)='complete',UTC_TIMESTAMP(),completed_at)";
        $pdo->prepare($sql)->execute([$org,$eventId,$event['game_id'],$team,(int)$u['id'],$contactStatus,trim((string)($_POST['contact_name']??''))?:null,trim((string)($_POST['contact_method']??''))?:null,trim((string)($_POST['contact_details']??''))?:null,$contactedAt,$json,$notes,$recordStatus,$inheritEvent?:null,$recordStatus]);

        $newProfile=$profileData;foreach($clean as $k=>$v){if($k==='scout')continue;if(neptune_prescout_nonempty($v))$newProfile[$k]=$v;}
        $profileSql="INSERT INTO robot_season_profiles(organization_id,game_id,frc_team_number,data_json,notes,tba_json,tba_updated_at,last_event_id,updated_by) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(),?,?) ON DUPLICATE KEY UPDATE data_json=VALUES(data_json),notes=IF(VALUES(notes) IS NULL OR VALUES(notes)='',notes,VALUES(notes)),tba_json=COALESCE(VALUES(tba_json),tba_json),tba_updated_at=UTC_TIMESTAMP(),last_event_id=VALUES(last_event_id),updated_by=VALUES(updated_by)";
        $pdo->prepare($profileSql)->execute([$org,$event['game_id'],$team,json_encode($newProfile,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$notes?:null,$profileCacheJson,$eventId,(int)$u['id']]);
        $msg=$recordStatus==='complete'?'Pre-scouting marked complete and season robot knowledge updated.':'Pre-scouting draft saved.';
        $s=$pdo->prepare('SELECT * FROM pre_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=?');$s->execute([$org,$eventId,$team]);$current=$s->fetch()?:null;$currentData=$clean;$data=$knownData;foreach($clean as $k=>$v)$data[$k]=$v;
    }catch(Throwable $e){$error=$e->getMessage();}
}

$stats=['events'=>0,'matches'=>0,'points'=>0,'ppg'=>0,'success_rate'=>null,'defense'=>0];
$s=$pdo->prepare("SELECT COUNT(DISTINCT sa.event_id) events,COUNT(DISTINCT sa.match_id) matches,COALESCE(SUM(sa.points),0) points,COALESCE(SUM(sa.action_type='defense'),0) defense,SUM(CASE WHEN sa.action_type='offense' AND sa.result IN ('Success','Failure') THEN 1 ELSE 0 END) offense_attempts,SUM(CASE WHEN sa.action_type='offense' AND sa.result='Success' THEN 1 ELSE 0 END) offense_success FROM scouting_actions sa WHERE sa.organization_id=? AND sa.game_id=? AND sa.frc_team_number=? AND sa.deleted_at IS NULL");$s->execute([$org,$event['game_id'],$team]);if($r=$s->fetch()){$stats=array_merge($stats,$r);$stats['ppg']=(int)$r['matches']>0?round((float)$r['points']/(int)$r['matches'],1):0;$stats['success_rate']=(int)$r['offense_attempts']>0?round((int)$r['offense_success']*100/(int)$r['offense_attempts']):null;}

$s=$pdo->prepare("SELECT et.frc_team_number FROM event_teams et LEFT JOIN pre_scouting ps ON ps.organization_id=? AND ps.event_id=et.event_id AND ps.frc_team_number=et.frc_team_number AND ps.status='complete' WHERE et.event_id=? AND ps.id IS NULL ORDER BY CASE WHEN et.frc_team_number>? THEN 0 ELSE 1 END,et.frc_team_number LIMIT 1");$s->execute([$org,$eventId,$team]);$nextTeam=(int)$s->fetchColumn();
$groups=[];foreach($questions as $q)$groups[(string)($q['group']??'Robot Questions')][]=$q;
$autonPath=is_array($data['_auton_path']??null)?$data['_auton_path']:['version'=>1,'strokes'=>[]];
$loc=trim(implode(', ',array_filter([$event['city']??'',$event['state_prov']??'',$event['country']??''])));$pageTitle='Pre-Scout #'.$team;$moduleName='TRIDENT';include dirname(__DIR__).'/partials_header.php';
$scoutValue=trim((string)($currentData['scout']??''));if($scoutValue==='')$scoutValue=(string)($u['display_name']??$u['username']??'');
?>
<style>
.prescout-auton-card{margin-top:16px}
.prescout-auton-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.prescout-auton-head h2{margin:0 0 4px}
.prescout-auton-wrap{position:relative;width:100%;margin-top:12px;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--panel2);aspect-ratio:2/1;min-height:240px}
.prescout-auton-canvas{display:block;width:100%;height:100%;touch-action:none;cursor:crosshair}
.prescout-auton-empty{position:absolute;inset:0;display:grid;place-items:center;pointer-events:none;color:var(--muted);text-align:center;padding:24px;background:linear-gradient(135deg,transparent,rgba(127,127,127,.04))}
.prescout-auton-empty i{display:block;font-size:2rem;margin-bottom:8px}
.prescout-auton-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}
.prescout-auton-help{font-size:.82rem;color:var(--muted);margin-top:8px;line-height:1.45}
@media(max-width:700px){.prescout-auton-wrap{min-height:190px}.prescout-auton-head{display:block}.prescout-auton-head .pill{margin-top:8px}}
</style>
<div class="toolbar" style="justify-content:space-between"><div><div class="module-eyebrow"><span>TRIDENT</span><small>Pre-Scouting</small></div><div class="muted"><?=e($event['name'])?> · <?=e($event['season_year'].' '.$event['game_name'])?></div><h1 style="margin:3px 0">#<?=e($team)?> <?=e($event['nickname']?:($tba['nickname']??''))?></h1><div class="muted"><?=e($loc?:'Location unavailable')?> · Pre-Scouting</div></div><div class="toolbar"><a class="btn secondary" href="index.php?event_id=<?=$eventId?>"><i class="fa-solid fa-arrow-left"></i> Team List</a><?php if($nextTeam):?><a class="btn secondary" href="team.php?event_id=<?=$eventId?>&team=<?=$nextTeam?>">Next Incomplete <i class="fa-solid fa-arrow-right"></i></a><?php endif;?></div></div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>

<section class="card prescout-performance-card">
  <div class="section-kicker"><i class="fa-solid fa-chart-line"></i> PRE-EVENT ROBOT DATA</div>
  <div class="toolbar" style="justify-content:space-between;align-items:flex-start"><div><h2 style="margin-bottom:4px">Season Performance</h2><div class="muted">Public EPA and OPR are read/calculated from Neptune's local database. OPR uses prior qualification scores and schedules, so no OPR Internet request is required.</div></div><div class="prescout-source-links"><a href="https://www.thebluealliance.com/team/<?=$team?>/<?=e($event['season_year'])?>" target="_blank" rel="noopener">Powered by The Blue Alliance</a><a href="<?=e(base_url('analytics/augur-ratings.php?year='.(int)$event['season_year']))?>">Public EPA in Neptune</a></div></div>
  <div class="prescout-performance-grid">
    <div><span>Win Rate</span><b><?=e($epaSummary['record']??'—')?></b></div>
    <div><span>EPA</span><b><?=e(neptune_prescout_num($epaSummary['epa']??null,1))?></b></div>
    <div><span>Auto</span><b><?=e(neptune_prescout_num($epaSummary['auto']??null,1))?></b></div>
    <div><span>Teleop</span><b><?=e(neptune_prescout_num($epaSummary['teleop']??null,1))?></b></div>
    <div><span>Endgame</span><b><?=e(neptune_prescout_num($epaSummary['endgame']??null,1))?></b></div>
    <div><span>OPR 1</span><b><?=e(neptune_prescout_num($opr1,2))?></b></div>
    <div><span>OPR 2</span><b><?=e(neptune_prescout_num($opr2,2))?></b></div>
    <div><span>Season OPR</span><b><?=e(neptune_prescout_num($oprTotal,2))?></b></div>
    <div><span>Rank</span><b><?=e(neptune_prescout_num($epaSummary['rank']??null,0))?></b></div>
  </div>
</section>

<div class="prescout-intel-grid">
  <section class="card prescout-tba-card"><div class="section-kicker"><i class="fa-solid fa-cloud"></i> TEAM REFERENCE</div><div class="prescout-reference-list"><div><span>Team</span><b>#<?=e($team)?> <?=e($tba['nickname']??$event['nickname']??'')?></b></div><div><span>State / Country</span><b><?=e(trim(implode(' / ',array_filter([$tba['state_prov']??$event['state_prov']??'',$tba['country']??$event['country']??''])))?:'—')?></b></div><div><span>Rookie year</span><b><?=e($tba['rookie_year']??'—')?></b></div><div><span>Website</span><b><?php if(!empty($tba['website'])):?><a href="<?=e($tba['website'])?>" target="_blank" rel="noopener">Open team site</a><?php else:?>—<?php endif;?></b></div></div></section>
  <section class="card"><div class="section-kicker"><i class="fa-solid fa-database"></i> NEPTUNE SEASON KNOWLEDGE</div><div class="prescout-stat-grid"><div><b><?=e($stats['events'])?></b><span>Events scouted</span></div><div><b><?=e($stats['matches'])?></b><span>Matches</span></div><div><b><?=e($stats['ppg'])?></b><span>Scout pts/match</span></div><div><b><?=e($stats['success_rate']===null?'—':$stats['success_rate'].'%')?></b><span>Offense success</span></div></div><div class="prescout-known-lines"><?php if($priorPre):?><div><i class="fa-solid fa-envelope-open-text"></i><span>Prior pre-scout: <b><?=e($priorPre['event_name'])?></b></span></div><?php endif;?><?php if($priorPit):?><div><i class="fa-solid fa-clipboard-list"></i><span>Prior pit scouting: <b><?=e($priorPit['event_name'])?></b></span></div><?php endif;?><?php if(!$priorPre&&!$priorPit&&!$seasonProfile):?><div class="muted">No prior Neptune data for this robot in <?=e($event['season_year'])?> yet.</div><?php endif;?></div></section>
</div>

<?php if($eventHistory):?><section class="card" style="margin-top:16px"><div class="section-kicker"><i class="fa-solid fa-trophy"></i> ALLIANCES / PRIOR EVENTS</div><div class="prescout-history-list"><?php foreach($eventHistory as $i=>$h):?><div><span><b><?=e($h['name'])?></b><small><?=e($h['key'])?></small></span><span><?=e($h['status']?:'No alliance status available')?></span><b><?=is_numeric($h['opr']??null)?'OPR '.e(neptune_prescout_num($h['opr'],2)):''?></b></div><?php endforeach;?></div></section><?php endif;?>

<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="team" value="<?=$team?>"><input type="hidden" name="auton_path_json" id="prescoutAutonPathJson" value="">
<section class="card pit-form-section" style="margin-top:16px"><div class="section-kicker"><i class="fa-solid fa-address-card"></i> PRE-SCOUT ASSIGNMENT / CONTACT</div><h2>Scout & Team Contact</h2><div class="pit-form-grid"><div class="pit-field"><label>Scout</label><input name="scout" value="<?=e($scoutValue)?>" placeholder="Person assigned to research/contact this team"></div><div class="pit-field"><label>Contact status</label><select name="contact_status"><option value="not_started" <?=($current['contact_status']??'not_started')==='not_started'?'selected':''?>>Not started</option><option value="contacted" <?=($current['contact_status']??'')==='contacted'?'selected':''?>>Contacted / waiting</option><option value="received" <?=($current['contact_status']??'')==='received'?'selected':''?>>Response received</option><option value="no_response" <?=($current['contact_status']??'')==='no_response'?'selected':''?>>No response</option><option value="unavailable" <?=($current['contact_status']??'')==='unavailable'?'selected':''?>>Unable / declined</option></select></div><div class="pit-field"><label>Team contact / representative</label><input name="contact_name" value="<?=e($current['contact_name']??'')?>"></div><div class="pit-field"><label>Contact method</label><select name="contact_method"><option value="">— Select —</option><?php foreach(['Email','Phone / Text','Discord','Social Media','Team Website','In Person','Other'] as $cm):?><option value="<?=e($cm)?>" <?=($current['contact_method']??'')===$cm?'selected':''?>><?=e($cm)?></option><?php endforeach;?></select></div><div class="pit-field"><label>Contact detail / handle / address</label><input name="contact_details" value="<?=e($current['contact_details']??'')?>"></div><div class="pit-field"><label>Contacted / response time</label><input type="datetime-local" name="contacted_at" value="<?php if(!empty($current['contacted_at'])){$dt=new DateTime($current['contacted_at'],new DateTimeZone('UTC'));$dt->setTimezone(new DateTimeZone(date_default_timezone_get()));echo e($dt->format('Y-m-d\\TH:i'));}?>"></div></div></section>

<?php foreach($groups as $group=>$qs):?><section class="card pit-form-section"><h2><?=e($group)?></h2><div class="pit-form-grid"><?php foreach($qs as $q):$code=(string)$q['code'];$isInherited=!array_key_exists($code,$currentData)&&array_key_exists($code,$knownData)&&neptune_prescout_nonempty($knownData[$code]);neptune_prescout_render_field($q,$data[$code]??($q['type']==='multiselect'?[]:''),$isInherited);endforeach;?></div></section><?php endforeach;?>
<section class="card pit-form-section"><h2>Additional Notes</h2><textarea name="notes" rows="5" placeholder="Anything else learned from the team or from pre-event research"><?=e($current['notes']??($priorPre['notes']??''))?></textarea></section>
<section class="card pit-form-section prescout-auton-card">
  <div class="prescout-auton-head">
    <div>
      <div class="section-kicker"><i class="fa-solid fa-route"></i> AUTONOMOUS ROUTE</div>
      <h2>Autonomous Path</h2>
      <div class="muted">Draw the robot's expected autonomous route on the same field background used by Pit Scouting. Mouse, touch, and stylus are supported.</div>
    </div>
    <span class="pill"><i class="fa-solid fa-pen"></i> Saved with pre-scout</span>
  </div>
  <div class="prescout-auton-wrap" id="prescoutAutonWrap">
    <canvas class="prescout-auton-canvas" id="prescoutAutonCanvas" aria-label="Draw autonomous robot path"></canvas>
    <div class="prescout-auton-empty" id="prescoutAutonEmpty" <?= $autonFieldUrl!==''?'hidden':'' ?>>
      <div><i class="fa-regular fa-map"></i><b>No field background set</b><br><span>Owners/admins can add the field image in VULCAN → Game Builder. You can still draw on the grid.</span></div>
    </div>
  </div>
  <div class="prescout-auton-toolbar">
    <button type="button" class="secondary" id="prescoutAutonUndo"><i class="fa-solid fa-rotate-left"></i> Undo Stroke</button>
    <button type="button" class="secondary" id="prescoutAutonClear"><i class="fa-solid fa-eraser"></i> Clear Path</button>
    <span class="muted" id="prescoutAutonStrokeCount"></span>
  </div>
  <div class="prescout-auton-help">The path is saved with this team's pre-scout record and season robot knowledge. Pit Scouting can reuse it for the same game.</div>
</section>
<div class="sticky-save"><button class="secondary" name="save_mode" value="draft"><i class="fa-solid fa-floppy-disk"></i> Save Draft</button><button class="good" name="save_mode" value="complete"><i class="fa-solid fa-circle-check"></i> Mark Complete</button></div>
</form>
<script>
(function(){
  const canvas=document.getElementById('prescoutAutonCanvas');
  const wrap=document.getElementById('prescoutAutonWrap');
  const hidden=document.getElementById('prescoutAutonPathJson');
  const undoBtn=document.getElementById('prescoutAutonUndo');
  const clearBtn=document.getElementById('prescoutAutonClear');
  const countEl=document.getElementById('prescoutAutonStrokeCount');
  const emptyEl=document.getElementById('prescoutAutonEmpty');
  const fieldUrl=<?=json_encode($autonFieldUrl,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  const initialPath=<?=json_encode($autonPath,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  let strokes=Array.isArray(initialPath?.strokes)?initialPath.strokes.filter(Array.isArray):[];
  let activeStroke=null;
  let drawing=false;
  let dpr=Math.max(1,window.devicePixelRatio||1);
  let bg=null;

  function syncHidden(){
    if(hidden)hidden.value=JSON.stringify({version:1,strokes});
    if(countEl)countEl.textContent=strokes.length+' stroke'+(strokes.length===1?'':'s');
    if(undoBtn)undoBtn.disabled=strokes.length===0;
    if(clearBtn)clearBtn.disabled=strokes.length===0;
  }
  function fitCanvas(){
    if(!canvas||!wrap)return;
    if(bg?.naturalWidth&&bg?.naturalHeight)wrap.style.aspectRatio=bg.naturalWidth+' / '+bg.naturalHeight;
    const r=wrap.getBoundingClientRect();
    dpr=Math.max(1,window.devicePixelRatio||1);
    canvas.width=Math.max(1,Math.round(r.width*dpr));
    canvas.height=Math.max(1,Math.round(r.height*dpr));
    draw();
  }
  function drawGrid(ctx,w,h){
    ctx.save();
    ctx.fillStyle='#111827';ctx.fillRect(0,0,w,h);
    ctx.strokeStyle='rgba(255,255,255,.12)';ctx.lineWidth=1*dpr;
    for(let i=1;i<12;i++){const x=w*i/12;ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x,h);ctx.stroke();}
    for(let i=1;i<6;i++){const y=h*i/6;ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(w,y);ctx.stroke();}
    ctx.restore();
  }
  function drawArrow(ctx,a,b,w,h){
    if(!a||!b)return;
    const ax=a.x*w,ay=a.y*h,bx=b.x*w,by=b.y*h;
    const angle=Math.atan2(by-ay,bx-ax),size=12*dpr;
    ctx.beginPath();
    ctx.moveTo(bx,by);ctx.lineTo(bx-size*Math.cos(angle-Math.PI/6),by-size*Math.sin(angle-Math.PI/6));
    ctx.moveTo(bx,by);ctx.lineTo(bx-size*Math.cos(angle+Math.PI/6),by-size*Math.sin(angle+Math.PI/6));
    ctx.stroke();
  }
  function renderStroke(ctx,stroke,w,h,color,width){
    if(!Array.isArray(stroke)||stroke.length<1)return;
    ctx.strokeStyle=color;ctx.fillStyle=color;ctx.lineWidth=width;ctx.lineCap='round';ctx.lineJoin='round';
    ctx.beginPath();
    stroke.forEach((p,i)=>{const x=p.x*w,y=p.y*h;i?ctx.lineTo(x,y):ctx.moveTo(x,y)});
    ctx.stroke();
  }
  function draw(){
    if(!canvas)return;
    const ctx=canvas.getContext('2d'),w=canvas.width,h=canvas.height;
    ctx.clearRect(0,0,w,h);
    if(bg?.complete&&bg.naturalWidth){ctx.drawImage(bg,0,0,w,h);}else{drawGrid(ctx,w,h);}
    ctx.save();
    strokes.forEach(stroke=>{
      if(!Array.isArray(stroke)||stroke.length<1)return;
      renderStroke(ctx,stroke,w,h,'rgba(255,255,255,.92)',Math.max(8,9*dpr));
      renderStroke(ctx,stroke,w,h,'#ff3b30',Math.max(4,5*dpr));
      const start=stroke[0];
      ctx.fillStyle='#ff3b30';ctx.strokeStyle='white';ctx.lineWidth=2*dpr;
      ctx.beginPath();ctx.arc(start.x*w,start.y*h,6*dpr,0,Math.PI*2);ctx.fill();ctx.stroke();
      ctx.strokeStyle='#ff3b30';ctx.lineWidth=Math.max(4,5*dpr);
      if(stroke.length>1)drawArrow(ctx,stroke[stroke.length-2],stroke[stroke.length-1],w,h);
    });
    ctx.restore();
  }
  function pointFromEvent(e){
    const r=canvas.getBoundingClientRect();
    return {x:Math.max(0,Math.min(1,(e.clientX-r.left)/r.width)),y:Math.max(0,Math.min(1,(e.clientY-r.top)/r.height))};
  }
  canvas?.addEventListener('pointerdown',e=>{
    e.preventDefault();canvas.setPointerCapture?.(e.pointerId);drawing=true;activeStroke=[pointFromEvent(e)];strokes.push(activeStroke);syncHidden();draw();
  });
  canvas?.addEventListener('pointermove',e=>{
    if(!drawing||!activeStroke)return;e.preventDefault();
    const p=pointFromEvent(e),last=activeStroke[activeStroke.length-1];
    if(!last||Math.hypot(p.x-last.x,p.y-last.y)>.003){activeStroke.push(p);syncHidden();draw();}
  });
  function stopDrawing(){drawing=false;activeStroke=null;syncHidden();draw();}
  canvas?.addEventListener('pointerup',stopDrawing);
  canvas?.addEventListener('pointercancel',stopDrawing);
  undoBtn?.addEventListener('click',()=>{strokes.pop();syncHidden();draw();});
  clearBtn?.addEventListener('click',()=>{if(strokes.length&&confirm('Clear the entire autonomous path?')){strokes=[];syncHidden();draw();}});

  if(fieldUrl){
    bg=new Image();
    bg.onload=()=>{if(emptyEl)emptyEl.hidden=true;fitCanvas();};
    bg.onerror=()=>{if(emptyEl)emptyEl.hidden=false;fitCanvas();};
    bg.src=fieldUrl;
  }
  const ro=('ResizeObserver' in window)?new ResizeObserver(fitCanvas):null;
  ro?.observe(wrap);
  window.addEventListener('resize',fitCanvas);
  syncHidden();
  requestAnimationFrame(fitCanvas);
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
