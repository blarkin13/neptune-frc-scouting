<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function nps_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nps_json(['ok'=>false,'error'=>'POST required.'],405);
}

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/portable.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    nps_json(['ok'=>false,'error'=>'Database unavailable.'],500);
}

$raw=file_get_contents('php://input');
$data=json_decode($raw ?: '',true);
if(!is_array($data)) nps_json(['ok'=>false,'error'=>'Invalid JSON body.'],400);

$org=(int)($data['organization_id']??0);
$event=(int)($data['event_id']??0);
$section=trim((string)($data['section']??''));
$rows=is_array($data['rows']??null)?$data['rows']:[];
$token=trim((string)($_SERVER['HTTP_X_NEPTUNE_PORTABLE_TOKEN']??''));

if($org<=0||$event<=0||$section==='') nps_json(['ok'=>false,'error'=>'organization_id, event_id and section are required.'],422);
$eventCheck=$pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=? LIMIT 1');
$eventCheck->execute([$event,$org]);
if(!$eventCheck->fetchColumn()) nps_json(['ok'=>false,'error'=>'Event is not available for this organization.'],422);
if(count($rows)>500) nps_json(['ok'=>false,'error'=>'Sync batch too large.'],413);

try{
    neptune_portable_authenticate_token($pdo,$token,$org,$event);
}catch(Throwable $e){
    nps_json(['ok'=>false,'error'=>'Unauthorized.'],401);
}

function nps_user(PDO $pdo,int $id,int $org): ?int {
    if($id<=0)return null;
    $s=$pdo->prepare('SELECT id FROM users WHERE id=? AND organization_id=? AND active=1 LIMIT 1');
    $s->execute([$id,$org]);
    return $s->fetchColumn()?(int)$id:null;
}
function nps_team(PDO $pdo,int $id,int $org): ?int {
    if($id<=0)return null;
    $s=$pdo->prepare('SELECT id FROM teams WHERE id=? AND organization_id=? LIMIT 1');
    $s->execute([$id,$org]);
    return $s->fetchColumn()?(int)$id:null;
}
function nps_event_game(PDO $pdo,int $event,int $org): int {
    static $cache=[];
    $k=$org.':'.$event;
    if(isset($cache[$k]))return $cache[$k];
    $s=$pdo->prepare('SELECT game_id FROM events WHERE id=? AND organization_id=? LIMIT 1');
    $s->execute([$event,$org]);
    $id=(int)$s->fetchColumn();
    if($id<=0)throw new RuntimeException('Event is unavailable.');
    return $cache[$k]=$id;
}
function nps_match_id(PDO $pdo,int $org,int $event,array $row): ?int {
    $id=(int)($row['match_id']??0);
    if($id>0){
        $s=$pdo->prepare('SELECT id FROM matches WHERE id=? AND organization_id=? AND event_id=? LIMIT 1');
        $s->execute([$id,$org,$event]);
        $found=(int)$s->fetchColumn();
        if($found>0)return $found;
    }
    $level=trim((string)($row['match_comp_level']??$row['comp_level']??''));
    $set=(int)($row['match_set_number']??$row['set_number']??1);
    $num=(int)($row['match_number']??0);
    $field=(int)($row['match_field_id']??$row['field_id']??1);
    if($level===''||$num<=0)return null;
    $s=$pdo->prepare(
        'SELECT id FROM matches
         WHERE organization_id=? AND event_id=? AND comp_level=? AND set_number=? AND match_number=? AND field_id=?
         LIMIT 1'
    );
    $s->execute([$org,$event,$level,max(1,$set),$num,max(1,$field)]);
    $found=(int)$s->fetchColumn();
    return $found>0?$found:null;
}
function nps_valid_uuid(string $uuid): bool {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',$uuid);
}
function nps_enum(string $v,array $allowed,string $fallback): string {
    return in_array($v,$allowed,true)?$v:$fallback;
}

$counts=['processed'=>0,'inserted'=>0,'updated'=>0,'existing'=>0,'skipped'=>0];

try{
    $pdo->beginTransaction();

    switch($section){
        case 'event':
            $s=$pdo->prepare(
                'UPDATE events
                 SET event_status=?,is_current=?,roster_synced_at=?,schedule_synced_at=?,last_tba_sync_at=?
                 WHERE id=? AND organization_id=?'
            );
            foreach($rows as $r){
                if(!is_array($r)||(int)($r['id']??0)!==$event){$counts['skipped']++;continue;}
                $status=nps_enum((string)($r['event_status']??'planned'),['planned','pit_open','schedule_ready','running','complete'],'planned');
                $s->execute([$status,!empty($r['is_current'])?1:0,$r['roster_synced_at']??null,$r['schedule_synced_at']??null,$r['last_tba_sync_at']??null,$event,$org]);
                $counts['processed']++;$counts['updated']+=$s->rowCount()>0?1:0;
            }
            break;

        case 'event_teams':
            $s=$pdo->prepare(
                'INSERT INTO event_teams(event_id,frc_team_number,nickname,city,state_prov,country,tba_team_key)
                 VALUES(?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   nickname=VALUES(nickname),city=VALUES(city),state_prov=VALUES(state_prov),
                   country=VALUES(country),tba_team_key=VALUES(tba_team_key)'
            );
            foreach($rows as $r){
                if(!is_array($r)||(int)($r['event_id']??0)!==$event||(int)($r['frc_team_number']??0)<=0){$counts['skipped']++;continue;}
                $s->execute([$event,(int)$r['frc_team_number'],$r['nickname']??null,$r['city']??null,$r['state_prov']??null,$r['country']??null,$r['tba_team_key']??null]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'matches':
            $game=nps_event_game($pdo,$event,$org);
            $find=$pdo->prepare(
                'SELECT id FROM matches
                 WHERE organization_id=? AND event_id=? AND comp_level=? AND set_number=? AND match_number=? AND field_id=? LIMIT 1'
            );
            $insert=$pdo->prepare(
                'INSERT INTO matches
                 (organization_id,event_id,game_id,tba_match_key,comp_level,set_number,match_number,field_id,
                  scheduled_time,started_at,ended_at,paused_at,total_pause_seconds,run_number,
                  red_score,blue_score,winning_alliance,state)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $update=$pdo->prepare(
                'UPDATE matches SET
                    tba_match_key=?,scheduled_time=?,started_at=?,ended_at=?,paused_at=?,
                    total_pause_seconds=?,run_number=?,red_score=?,blue_score=?,winning_alliance=?,state=?
                 WHERE id=? AND organization_id=? AND event_id=?'
            );
            foreach($rows as $r){
                if(!is_array($r)){ $counts['skipped']++; continue; }
                $level=trim((string)($r['comp_level']??'qm'));
                $set=max(1,(int)($r['set_number']??1));
                $num=(int)($r['match_number']??0);
                $field=max(1,(int)($r['field_id']??1));
                if($num<=0){$counts['skipped']++;continue;}
                $state=nps_enum((string)($r['state']??'scheduled'),['scheduled','ready','running','paused','ended'],'scheduled');
                $win=nps_enum((string)($r['winning_alliance']??'Unknown'),['Red','Blue','Tie','Unknown'],'Unknown');
                $find->execute([$org,$event,$level,$set,$num,$field]);
                $cloudId=(int)$find->fetchColumn();
                $vals=[
                    $r['tba_match_key']??null,$r['scheduled_time']??null,$r['started_at']??null,$r['ended_at']??null,
                    $r['paused_at']??null,max(0,(int)($r['total_pause_seconds']??0)),max(1,(int)($r['run_number']??1)),
                    isset($r['red_score'])&&$r['red_score']!==''?(int)$r['red_score']:null,
                    isset($r['blue_score'])&&$r['blue_score']!==''?(int)$r['blue_score']:null,
                    $win,$state
                ];
                if($cloudId>0){
                    $update->execute(array_merge($vals,[$cloudId,$org,$event]));
                    $counts['updated']++;
                }else{
                    $insert->execute([
                        $org,$event,$game,$r['tba_match_key']??null,$level,$set,$num,$field,
                        $r['scheduled_time']??null,$r['started_at']??null,$r['ended_at']??null,$r['paused_at']??null,
                        max(0,(int)($r['total_pause_seconds']??0)),max(1,(int)($r['run_number']??1)),
                        isset($r['red_score'])&&$r['red_score']!==''?(int)$r['red_score']:null,
                        isset($r['blue_score'])&&$r['blue_score']!==''?(int)$r['blue_score']:null,
                        $win,$state
                    ]);
                    $counts['inserted']++;
                }
                $counts['processed']++;
            }
            break;

        case 'match_teams':
            $s=$pdo->prepare(
                'INSERT INTO match_teams(match_id,frc_team_number,alliance,station)
                 VALUES(?,?,?,?)
                 ON DUPLICATE KEY UPDATE frc_team_number=VALUES(frc_team_number)'
            );
            foreach($rows as $r){
                if(!is_array($r)){ $counts['skipped']++; continue; }
                $mid=nps_match_id($pdo,$org,$event,$r);
                $team=(int)($r['frc_team_number']??0);
                $all=nps_enum((string)($r['alliance']??''),['Red','Blue'],'');
                $station=(int)($r['station']??0);
                if(!$mid||$team<=0||$all===''||$station<1||$station>3){$counts['skipped']++;continue;}
                $s->execute([$mid,$team,$all,$station]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'scout_sessions':
            $exists=$pdo->prepare('SELECT id FROM scout_sessions WHERE uuid=? AND organization_id=? LIMIT 1');
            $insert=$pdo->prepare(
                'INSERT INTO scout_sessions
                 (uuid,organization_id,event_id,match_id,user_id,scout_name,frc_team_number,alliance,station,field_id,
                  match_run_number,status,last_seen_at,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $update=$pdo->prepare(
                'UPDATE scout_sessions SET
                    event_id=?,match_id=?,user_id=?,scout_name=?,frc_team_number=?,alliance=?,station=?,field_id=?,
                    match_run_number=?,status=?,last_seen_at=?
                 WHERE uuid=? AND organization_id=?'
            );
            foreach($rows as $r){
                if(!is_array($r)){ $counts['skipped']++; continue; }
                $uuid=strtolower(trim((string)($r['uuid']??'')));
                $mid=nps_match_id($pdo,$org,$event,$r);
                $all=nps_enum((string)($r['alliance']??''),['Red','Blue'],'');
                if(!nps_valid_uuid($uuid)||!$mid||$all===''){ $counts['skipped']++; continue; }
                $uid=nps_user($pdo,(int)($r['user_id']??0),$org);
                $status=nps_enum((string)($r['status']??'assigned'),['assigned','connected','scouting','submitted','closed'],'assigned');
                $exists->execute([$uuid,$org]);
                $id=(int)$exists->fetchColumn();
                if($id>0){
                    $update->execute([$event,$mid,$uid,$r['scout_name']??null,(int)$r['frc_team_number'],$all,
                        isset($r['station'])?(int)$r['station']:null,max(1,(int)($r['field_id']??1)),
                        max(1,(int)($r['match_run_number']??1)),$status,$r['last_seen_at']??null,$uuid,$org]);
                    $counts['updated']++;
                }else{
                    $insert->execute([$uuid,$org,$event,$mid,$uid,$r['scout_name']??null,(int)$r['frc_team_number'],$all,
                        isset($r['station'])?(int)$r['station']:null,max(1,(int)($r['field_id']??1)),
                        max(1,(int)($r['match_run_number']??1)),$status,$r['last_seen_at']??null,
                        $r['created_at']??gmdate('Y-m-d H:i:s')]);
                    $counts['inserted']++;
                }
                $counts['processed']++;
            }
            break;

        case 'scouting_actions':
            $exists=$pdo->prepare('SELECT id,deleted_at FROM scouting_actions WHERE uuid=? AND organization_id=? LIMIT 1');
            $session=$pdo->prepare('SELECT id FROM scout_sessions WHERE uuid=? AND organization_id=? LIMIT 1');
            $insert=$pdo->prepare(
                'INSERT INTO scouting_actions
                 (uuid,organization_id,owner_team_id,event_id,match_id,scout_session_id,game_id,frc_team_number,alliance,
                  action_code,action_name,action_type,location,result,points,match_time_sec,match_run_number,phase,source,
                  source_ip,created_by,recorded_at,legacy_source,legacy_id,deleted_at,deleted_by,deletion_reason)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $softDelete=$pdo->prepare(
                'UPDATE scouting_actions SET deleted_at=?,deleted_by=?,deletion_reason=? WHERE uuid=? AND organization_id=?'
            );
            $game=nps_event_game($pdo,$event,$org);
            foreach($rows as $r){
                if(!is_array($r)){ $counts['skipped']++; continue; }
                $uuid=strtolower(trim((string)($r['uuid']??'')));
                $mid=nps_match_id($pdo,$org,$event,$r);
                $all=nps_enum((string)($r['alliance']??''),['Red','Blue'],'');
                if(!nps_valid_uuid($uuid)||!$mid||$all===''||trim((string)($r['action_code']??''))===''){ $counts['skipped']++; continue; }
                $exists->execute([$uuid,$org]);
                $existing=$exists->fetch(PDO::FETCH_ASSOC);
                if($existing){
                    $counts['existing']++;
                    if(!empty($r['deleted_at']) && empty($existing['deleted_at'])){
                        $softDelete->execute([$r['deleted_at'],nps_user($pdo,(int)($r['deleted_by']??0),$org),$r['deletion_reason']??null,$uuid,$org]);
                        $counts['updated']++;
                    }
                    $counts['processed']++;
                    continue;
                }
                $sid=null;
                $suuid=strtolower(trim((string)($r['scout_session_uuid']??'')));
                if(nps_valid_uuid($suuid)){
                    $session->execute([$suuid,$org]);
                    $sid=(int)$session->fetchColumn() ?: null;
                }
                $owner=nps_team($pdo,(int)($r['owner_team_id']??0),$org);
                $created=nps_user($pdo,(int)($r['created_by']??0),$org);
                $deleted=nps_user($pdo,(int)($r['deleted_by']??0),$org);
                $result=nps_enum((string)($r['result']??'Neutral'),['Success','Failure','Neutral'],'Neutral');
                $phase=nps_enum((string)($r['phase']??'unknown'),['pre_match','auton','teleop','endgame','post_match','unknown'],'unknown');
                $source=nps_enum((string)($r['source']??'scout'),['scout','admin','legacy_import','shared'],'scout');
                $insert->execute([
                    $uuid,$org,$owner,$event,$mid,$sid,$game,(int)$r['frc_team_number'],$all,
                    (string)$r['action_code'],$r['action_name']??null,$r['action_type']??null,$r['location']??null,$result,
                    (float)($r['points']??0),isset($r['match_time_sec'])&&$r['match_time_sec']!==''?(int)$r['match_time_sec']:null,
                    max(1,(int)($r['match_run_number']??1)),$phase,$source,$r['source_ip']??null,$created,
                    $r['recorded_at']??gmdate('Y-m-d H:i:s'),$r['legacy_source']??null,
                    isset($r['legacy_id'])&&$r['legacy_id']!==''?(int)$r['legacy_id']:null,
                    $r['deleted_at']??null,$deleted,$r['deletion_reason']??null
                ]);
                $counts['processed']++;$counts['inserted']++;
            }
            break;

        case 'pit_scouting':
            $s=$pdo->prepare(
                'INSERT INTO pit_scouting
                 (organization_id,owner_team_id,event_id,frc_team_number,submitted_by,data_json,notes,status,
                  started_at,completed_at,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                  owner_team_id=VALUES(owner_team_id),submitted_by=VALUES(submitted_by),data_json=VALUES(data_json),
                  notes=VALUES(notes),status=VALUES(status),started_at=VALUES(started_at),
                  completed_at=VALUES(completed_at),updated_at=VALUES(updated_at)'
            );
            foreach($rows as $r){
                if(!is_array($r)||(int)($r['event_id']??0)!==$event||(int)($r['frc_team_number']??0)<=0){$counts['skipped']++;continue;}
                $s->execute([$org,nps_team($pdo,(int)($r['owner_team_id']??0),$org),$event,(int)$r['frc_team_number'],
                    nps_user($pdo,(int)($r['submitted_by']??0),$org),(string)($r['data_json']??'{}'),$r['notes']??null,
                    nps_enum((string)($r['status']??'in_progress'),['in_progress','complete'],'in_progress'),
                    $r['started_at']??null,$r['completed_at']??null,$r['created_at']??gmdate('Y-m-d H:i:s'),
                    $r['updated_at']??gmdate('Y-m-d H:i:s')]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'pre_scouting':
            $game=nps_event_game($pdo,$event,$org);
            $s=$pdo->prepare(
                'INSERT INTO pre_scouting
                 (organization_id,event_id,game_id,frc_team_number,submitted_by,contact_status,contact_name,contact_method,
                  contact_details,contacted_at,data_json,notes,status,inherited_from_event_id,completed_at,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                  submitted_by=VALUES(submitted_by),contact_status=VALUES(contact_status),contact_name=VALUES(contact_name),
                  contact_method=VALUES(contact_method),contact_details=VALUES(contact_details),contacted_at=VALUES(contacted_at),
                  data_json=VALUES(data_json),notes=VALUES(notes),status=VALUES(status),
                  inherited_from_event_id=VALUES(inherited_from_event_id),completed_at=VALUES(completed_at),updated_at=VALUES(updated_at)'
            );
            foreach($rows as $r){
                if(!is_array($r)||(int)($r['event_id']??0)!==$event||(int)($r['frc_team_number']??0)<=0){$counts['skipped']++;continue;}
                $contact=nps_enum((string)($r['contact_status']??'not_started'),['not_started','contacted','received','no_response','unavailable'],'not_started');
                $status=nps_enum((string)($r['status']??'in_progress'),['in_progress','complete'],'in_progress');
                $inherited=(int)($r['inherited_from_event_id']??0);
                if($inherited>0){
                    $q=$pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=? LIMIT 1');$q->execute([$inherited,$org]);
                    if(!$q->fetchColumn())$inherited=0;
                }
                $s->execute([$org,$event,$game,(int)$r['frc_team_number'],nps_user($pdo,(int)($r['submitted_by']??0),$org),
                    $contact,$r['contact_name']??null,$r['contact_method']??null,$r['contact_details']??null,$r['contacted_at']??null,
                    (string)($r['data_json']??'{}'),$r['notes']??null,$status,$inherited?:null,$r['completed_at']??null,
                    $r['created_at']??gmdate('Y-m-d H:i:s'),$r['updated_at']??gmdate('Y-m-d H:i:s')]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'match_strategies':
            if(!neptune_portable_table_exists($pdo,'match_strategies'))break;
            $s=$pdo->prepare(
                'INSERT INTO match_strategies
                 (organization_id,event_id,match_id,frc_team_number,strategy_json,created_by,updated_by,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE strategy_json=VALUES(strategy_json),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)'
            );
            foreach($rows as $r){
                if(!is_array($r)){ $counts['skipped']++; continue; }
                $mid=nps_match_id($pdo,$org,$event,$r);
                if(!$mid||(int)($r['frc_team_number']??0)<=0){$counts['skipped']++;continue;}
                $s->execute([$org,$event,$mid,(int)$r['frc_team_number'],(string)($r['strategy_json']??'{}'),
                    nps_user($pdo,(int)($r['created_by']??0),$org),nps_user($pdo,(int)($r['updated_by']??0),$org),
                    $r['created_at']??gmdate('Y-m-d H:i:s'),$r['updated_at']??gmdate('Y-m-d H:i:s')]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'robot_season_profiles':
            if(!neptune_portable_table_exists($pdo,'robot_season_profiles'))break;
            $game=nps_event_game($pdo,$event,$org);
            $s=$pdo->prepare(
                'INSERT INTO robot_season_profiles
                 (organization_id,game_id,frc_team_number,data_json,notes,tba_json,tba_updated_at,last_event_id,updated_by,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE data_json=VALUES(data_json),notes=VALUES(notes),tba_json=VALUES(tba_json),
                  tba_updated_at=VALUES(tba_updated_at),last_event_id=VALUES(last_event_id),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)'
            );
            foreach($rows as $r){
                if(!is_array($r)||(int)($r['game_id']??0)!==$game||(int)($r['frc_team_number']??0)<=0){$counts['skipped']++;continue;}
                $last=(int)($r['last_event_id']??0);
                if($last>0){$q=$pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=? LIMIT 1');$q->execute([$last,$org]);if(!$q->fetchColumn())$last=0;}
                $s->execute([$org,$game,(int)$r['frc_team_number'],(string)($r['data_json']??'{}'),$r['notes']??null,
                    $r['tba_json']??null,$r['tba_updated_at']??null,$last?:null,nps_user($pdo,(int)($r['updated_by']??0),$org),
                    $r['created_at']??gmdate('Y-m-d H:i:s'),$r['updated_at']??gmdate('Y-m-d H:i:s')]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'tag_definitions':
            if(!neptune_portable_table_exists($pdo,'tag_scouting_tags'))break;
            $s=$pdo->prepare(
                'INSERT INTO tag_scouting_tags
                 (organization_id,seed_key,label,slug,category,icon,severity,match_enabled,pit_enabled,team_enabled,
                  active,sort_order,created_by,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE label=VALUES(label),category=VALUES(category),icon=VALUES(icon),
                  severity=VALUES(severity),match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),
                  team_enabled=VALUES(team_enabled),active=VALUES(active),sort_order=VALUES(sort_order),updated_at=VALUES(updated_at)'
            );
            foreach($rows as $r){
                if(!is_array($r)||(int)($r['organization_id']??0)!==$org||trim((string)($r['slug']??''))===''){$counts['skipped']++;continue;}
                $sev=nps_enum((string)($r['severity']??'info'),['positive','info','warning','critical'],'info');
                $s->execute([$org,$r['seed_key']??null,(string)($r['label']??$r['slug']),$r['slug'],$r['category']??'General',
                    $r['icon']??'fa-solid fa-tag',$sev,!empty($r['match_enabled'])?1:0,!empty($r['pit_enabled'])?1:0,
                    !empty($r['team_enabled'])?1:0,!empty($r['active'])?1:0,(int)($r['sort_order']??100),
                    nps_user($pdo,(int)($r['created_by']??0),$org),$r['created_at']??gmdate('Y-m-d H:i:s'),
                    $r['updated_at']??gmdate('Y-m-d H:i:s')]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'tag_observations':
            if(!neptune_portable_table_exists($pdo,'tag_scouting_observations'))break;
            $s=$pdo->prepare(
                'INSERT INTO tag_scouting_observations
                 (uuid,organization_id,event_id,match_id,field_id,frc_team_number,context,entry_mode,source_key,note,severity,
                  status,created_by,created_at,resolved_by,resolved_at,resolution_note)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                  event_id=VALUES(event_id),match_id=VALUES(match_id),field_id=VALUES(field_id),frc_team_number=VALUES(frc_team_number),
                  context=VALUES(context),entry_mode=VALUES(entry_mode),note=VALUES(note),severity=VALUES(severity),status=VALUES(status),
                  resolved_by=VALUES(resolved_by),resolved_at=VALUES(resolved_at),resolution_note=VALUES(resolution_note)'
            );
            foreach($rows as $r){
                if(!is_array($r)){ $counts['skipped']++; continue; }
                $uuid=strtolower(trim((string)($r['uuid']??'')));
                $rowEvent=(int)($r['event_id']??0);
                if(!nps_valid_uuid($uuid)||($rowEvent!==0&&$rowEvent!==$event)){ $counts['skipped']++; continue; }
                $mid=null;
                if((int)($r['match_id']??0)>0)$mid=nps_match_id($pdo,$org,$event,$r);
                $ctx=nps_enum((string)($r['context']??'team'),['match','pit','team'],'team');
                $mode=nps_enum((string)($r['entry_mode']??'manual'),['auto','manual'],'manual');
                $sev=nps_enum((string)($r['severity']??'info'),['positive','info','warning','critical'],'info');
                $status=nps_enum((string)($r['status']??'open'),['open','resolved'],'open');
                $s->execute([$uuid,$org,$rowEvent?:null,$mid,isset($r['field_id'])?(int)$r['field_id']:null,(int)$r['frc_team_number'],
                    $ctx,$mode,$r['source_key']??null,$r['note']??null,$sev,$status,nps_user($pdo,(int)($r['created_by']??0),$org),
                    $r['created_at']??gmdate('Y-m-d H:i:s'),nps_user($pdo,(int)($r['resolved_by']??0),$org),
                    $r['resolved_at']??null,$r['resolution_note']??null]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'tag_observation_tags':
            if(!neptune_portable_table_exists($pdo,'tag_scouting_observation_tags'))break;
            $obs=$pdo->prepare('SELECT id FROM tag_scouting_observations WHERE uuid=? AND organization_id=? LIMIT 1');
            $tagSeed=$pdo->prepare('SELECT id FROM tag_scouting_tags WHERE seed_key=? LIMIT 1');
            $tagSlug=$pdo->prepare('SELECT id FROM tag_scouting_tags WHERE organization_id=? AND slug=? LIMIT 1');
            $up=$pdo->prepare(
                'INSERT INTO tag_scouting_observation_tags(observation_id,tag_id,weight)
                 VALUES(?,?,?) ON DUPLICATE KEY UPDATE weight=VALUES(weight)'
            );
            foreach($rows as $r){
                if(!is_array($r)){ $counts['skipped']++; continue; }
                $uuid=strtolower(trim((string)($r['observation_uuid']??'')));
                if(!nps_valid_uuid($uuid)){ $counts['skipped']++; continue; }
                $obs->execute([$uuid,$org]);$oid=(int)$obs->fetchColumn();
                if($oid<=0){$counts['skipped']++;continue;}
                $tid=0;$seed=trim((string)($r['seed_key']??''));
                if($seed!==''){$tagSeed->execute([$seed]);$tid=(int)$tagSeed->fetchColumn();}
                if($tid<=0){
                    $slug=trim((string)($r['slug']??''));
                    if($slug!==''){$tagSlug->execute([$org,$slug]);$tid=(int)$tagSlug->fetchColumn();}
                }
                if($tid<=0){$counts['skipped']++;continue;}
                $up->execute([$oid,$tid,isset($r['weight'])&&$r['weight']!==''?(int)$r['weight']:null]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'tag_match_data':
            if(!neptune_portable_table_exists($pdo,'tag_scouting_match_data'))break;
            $obs=$pdo->prepare('SELECT id FROM tag_scouting_observations WHERE uuid=? AND organization_id=? LIMIT 1');
            $game=nps_event_game($pdo,$event,$org);
            $s=$pdo->prepare(
                'INSERT INTO tag_scouting_match_data
                 (observation_id,organization_id,event_id,match_id,game_id,user_id,frc_team_number,alliance,station,
                  scoring_contribution,tags_json,tag_weights_json,note,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE scoring_contribution=VALUES(scoring_contribution),tags_json=VALUES(tags_json),
                  tag_weights_json=VALUES(tag_weights_json),note=VALUES(note),updated_at=VALUES(updated_at)'
            );
            foreach($rows as $r){
                if(!is_array($r)){ $counts['skipped']++; continue; }
                $uuid=strtolower(trim((string)($r['observation_uuid']??'')));
                $obs->execute([$uuid,$org]);$oid=(int)$obs->fetchColumn();
                $mid=nps_match_id($pdo,$org,$event,$r);
                $uid=nps_user($pdo,(int)($r['user_id']??0),$org);
                $all=nps_enum((string)($r['alliance']??''),['Red','Blue'],'');
                if($oid<=0||!$mid||!$uid||$all===''){ $counts['skipped']++; continue; }
                $s->execute([$oid,$org,$event,$mid,$game,$uid,(int)$r['frc_team_number'],$all,
                    isset($r['station'])?(int)$r['station']:null,
                    isset($r['scoring_contribution'])&&$r['scoring_contribution']!==''?(int)$r['scoring_contribution']:null,
                    (string)($r['tags_json']??'[]'),$r['tag_weights_json']??null,$r['note']??null,
                    $r['created_at']??gmdate('Y-m-d H:i:s'),$r['updated_at']??gmdate('Y-m-d H:i:s')]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'pit_match_board_state':
            if(!neptune_portable_table_exists($pdo,'pit_match_board_state'))break;
            $s=$pdo->prepare(
                'INSERT INTO pit_match_board_state
                 (organization_id,event_id,frc_team_number,robot_status,battery_label,bumper_color,inspection_ready,
                  drive_team_ready,pit_note,updated_by,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE robot_status=VALUES(robot_status),battery_label=VALUES(battery_label),
                  bumper_color=VALUES(bumper_color),inspection_ready=VALUES(inspection_ready),drive_team_ready=VALUES(drive_team_ready),
                  pit_note=VALUES(pit_note),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)'
            );
            foreach($rows as $r){
                if(!is_array($r)||(int)($r['frc_team_number']??0)<=0){$counts['skipped']++;continue;}
                $s->execute([$org,$event,(int)$r['frc_team_number'],$r['robot_status']??'unset',$r['battery_label']??null,
                    $r['bumper_color']??null,!empty($r['inspection_ready'])?1:0,!empty($r['drive_team_ready'])?1:0,
                    $r['pit_note']??null,nps_user($pdo,(int)($r['updated_by']??0),$org),$r['updated_at']??gmdate('Y-m-d H:i:s')]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'pit_match_board_match_state':
            if(!neptune_portable_table_exists($pdo,'pit_match_board_match_state'))break;
            $s=$pdo->prepare(
                'INSERT INTO pit_match_board_match_state
                 (organization_id,event_id,frc_team_number,match_id,queue_lead_minutes,queued,checklist_json,match_note,updated_by,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE queue_lead_minutes=VALUES(queue_lead_minutes),queued=VALUES(queued),
                  checklist_json=VALUES(checklist_json),match_note=VALUES(match_note),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)'
            );
            foreach($rows as $r){
                if(!is_array($r)){ $counts['skipped']++; continue; }
                $mid=nps_match_id($pdo,$org,$event,$r);
                if(!$mid||(int)($r['frc_team_number']??0)<=0){$counts['skipped']++;continue;}
                $s->execute([$org,$event,(int)$r['frc_team_number'],$mid,max(0,(int)($r['queue_lead_minutes']??10)),
                    !empty($r['queued'])?1:0,$r['checklist_json']??null,$r['match_note']??null,
                    nps_user($pdo,(int)($r['updated_by']??0),$org),$r['updated_at']??gmdate('Y-m-d H:i:s')]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'alliance_selection_state':
            if(!neptune_portable_table_exists($pdo,'alliance_selection_state'))break;
            $s=$pdo->prepare(
                'INSERT INTO alliance_selection_state(organization_id,event_id,state_json,version,updated_by,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)'
            );
            foreach($rows as $r){
                if(!is_array($r)){ $counts['skipped']++; continue; }
                $s->execute([$org,$event,(string)($r['state_json']??'{}'),max(1,(int)($r['version']??1)),
                    nps_user($pdo,(int)($r['updated_by']??0),$org),$r['created_at']??gmdate('Y-m-d H:i:s'),
                    $r['updated_at']??gmdate('Y-m-d H:i:s')]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'alliance_selection_beta_event_state':
            if(!neptune_portable_table_exists($pdo,'alliance_selection_beta_event_state'))break;
            $s=$pdo->prepare(
                'INSERT INTO alliance_selection_beta_event_state(organization_id,event_id,state_json,version,updated_by,updated_at)
                 VALUES(?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)'
            );
            foreach($rows as $r){
                if(!is_array($r)){ $counts['skipped']++; continue; }
                $s->execute([$org,$event,(string)($r['state_json']??'{}'),max(1,(int)($r['version']??1)),
                    nps_user($pdo,(int)($r['updated_by']??0),$org),$r['updated_at']??gmdate('Y-m-d H:i:s')]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        case 'alliance_selection_beta_team_state':
            if(!neptune_portable_table_exists($pdo,'alliance_selection_beta_team_state'))break;
            $s=$pdo->prepare(
                'INSERT INTO alliance_selection_beta_team_state
                 (organization_id,event_id,strategy_frc_team_number,state_json,version,updated_by,updated_at)
                 VALUES(?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)'
            );
            foreach($rows as $r){
                if(!is_array($r)||(int)($r['strategy_frc_team_number']??0)<=0){$counts['skipped']++;continue;}
                $s->execute([$org,$event,(int)$r['strategy_frc_team_number'],(string)($r['state_json']??'{}'),
                    max(1,(int)($r['version']??1)),nps_user($pdo,(int)($r['updated_by']??0),$org),
                    $r['updated_at']??gmdate('Y-m-d H:i:s')]);
                $counts['processed']++;$counts['updated']++;
            }
            break;

        default:
            throw new RuntimeException('Unsupported sync section.');
    }

    $pdo->commit();
    nps_json([
        'ok'=>true,
        'organization_id'=>$org,
        'event_id'=>$event,
        'section'=>$section,
        'counts'=>$counts,
        'server_time_utc'=>gmdate('c'),
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[Neptune portable sync] '.$section.' '.$e->getMessage());
    nps_json(['ok'=>false,'error'=>'Portable synchronization failed.','detail'=>$e->getMessage()],500);
}
