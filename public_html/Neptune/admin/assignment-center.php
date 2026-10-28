<?php
/** SATURN / Assignment Center v2. Admin planning and tracking only.
 * No changes to existing match scouting, TAG, pit scouting, or offline sync.
 */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_event_selection.php';
require_once __DIR__.'/_assignment_center.php';
require_once dirname(__DIR__).'/_assignment_push_delivery.php';
$u=require_role(['owner','admin']);
$org=(int)$u['organization_id'];
$actor=(int)$u['id'];

function sac_audit(PDO $pdo,int $org,int $eventId,int $assignmentId,string $action,?int $oldUser,?int $newUser,?string $oldStatus,string $newStatus,int $actor,?string $notes=null): void {
    $s=$pdo->prepare('INSERT INTO scouting_assignment_history (organization_id,event_id,assignment_id,action,previous_user_id,new_user_id,previous_status,new_status,actor_user_id,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
    $s->execute([$org,$eventId,$assignmentId,$action,$oldUser,$newUser,$oldStatus,$newStatus,$actor,$notes]);
}
function sac_valid_user(PDO $pdo,int $org,int $userId): void {
    if($userId<1)throw new InvalidArgumentException('Select a roboticist.');
    $s=$pdo->prepare('SELECT id FROM users WHERE id=? AND organization_id=? AND active=1 LIMIT 1');
    $s->execute([$userId,$org]);
    if(!$s->fetchColumn())throw new InvalidArgumentException('That user is not active in this organization.');
}
function sac_slot_save(PDO $pdo,int $org,int $eventId,int $actor,string $kind,string $key,?int $matchId,?string $alliance,?int $station,?string $mode,?int $team,int $assignee): bool {
    // Caller holds a transaction. The unique (organization_id,slot_key) index
    // prevents duplicate positions even if two administrators save simultaneously.
    $s=$pdo->prepare('SELECT * FROM scouting_assignments WHERE organization_id=? AND slot_key=? FOR UPDATE');
    $s->execute([$org,$key]);
    $existing=$s->fetch(PDO::FETCH_ASSOC);
    if(!$existing){
        $s=$pdo->prepare("INSERT INTO scouting_assignments (organization_id,event_id,kind,slot_key,match_id,alliance,station,scouting_mode,frc_team_number,assignee_user_id,status,created_by,updated_by,assigned_at) VALUES (?,?,?,?,?,?,?,?,?,?,'assigned',?,?,UTC_TIMESTAMP())");
        $s->execute([$org,$eventId,$kind,$key,$matchId,$alliance,$station,$mode,$team,$assignee,$actor,$actor]);
        sac_audit($pdo,$org,$eventId,(int)$pdo->lastInsertId(),'created',null,$assignee,null,'assigned',$actor);
        return true;
    }
    if((int)$existing['event_id']!==$eventId || $existing['kind']!==$kind || (string)$existing['slot_key']!==$key)throw new RuntimeException('Assignment slot conflict.');
    if((int)$existing['assignee_user_id']===$assignee && (string)$existing['status']==='assigned' && (string)($existing['scouting_mode']??'')===(string)($mode??''))return false;
    $id=(int)$existing['id'];
    $s=$pdo->prepare("UPDATE scouting_assignments SET assignee_user_id=?,scouting_mode=?,status='assigned',assigned_at=UTC_TIMESTAMP(),acknowledged_at=NULL,acknowledged_by=NULL,completed_at=NULL,completed_by=NULL,cancelled_at=NULL,updated_by=? WHERE id=? AND organization_id=? AND event_id=?");
    $s->execute([$assignee,$mode,$actor,$id,$org,$eventId]);
    sac_audit($pdo,$org,$eventId,$id,'reassigned',$existing['assignee_user_id']===null?null:(int)$existing['assignee_user_id'],$assignee,(string)$existing['status'],'assigned',$actor,'Assignment saved or reassigned by administrator');
    // Mode-only edits to an existing person's assignment do not issue a new-assignment alert.
    return (int)$existing['assignee_user_id'] !== $assignee || in_array((string)$existing['status'],['cancelled','completed'],true);
}
/** Create one durable notification per admin operation, inside its assignment transaction. */
function sac_notify(PDO $pdo,int $org,int $eventId,int $recipient,string $kind,string $title,string $body,?int $assignmentId=null): void {
    // Match MySQL VARCHAR character limits without cutting Unicode mid-sequence.
    preg_match('/^.{0,190}/us',$title,$titlePart);
    preg_match('/^.{0,500}/us',$body,$bodyPart);
    $q=$pdo->prepare('INSERT INTO scouting_assignment_notifications (organization_id,recipient_user_id,event_id,assignment_id,kind,title,body,created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())');
    $q->execute([$org,$recipient,$eventId,$assignmentId,$kind,$titlePart[0]??'', $bodyPart[0]??'']);
    $GLOBALS['sac_push_recipients'][$org.':'.$recipient]=[$org,$recipient];
}
function sac_status_save(PDO $pdo,int $org,int $eventId,int $actor,int $id,string $op,?string $response,?array $image): void {
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT * FROM scouting_assignments WHERE id=? AND organization_id=? AND event_id=? FOR UPDATE');
        $s->execute([$id,$org,$eventId]);
        $old=$s->fetch(PDO::FETCH_ASSOC);
        if(!$old)throw new InvalidArgumentException('Assignment not found for this event.');
        [$newStatus,$auditAction]=sac_status_transition((string)$old['status'],$op);
        if($op==='complete' && $old['kind']==='request'){
            $response=trim((string)$response);
            $existingEvidence=$pdo->prepare('SELECT COUNT(*) FROM scouting_assignment_evidence WHERE organization_id=? AND assignment_id=?');
            $existingEvidence->execute([$org,$id]);
            $hasEvidence=(int)$existingEvidence->fetchColumn()>0 || $image!==null;
            if($old['request_kind']==='question' && $response==='' && trim((string)$old['response_text'])==='')throw new InvalidArgumentException('Record the answer before completing this question.');
            if($old['request_kind']==='photo' && !$hasEvidence)throw new InvalidArgumentException('Upload a photograph before completing this photo request.');
        }
        $sql="UPDATE scouting_assignments SET status=?,updated_by=?,acknowledged_at=IF(?='acknowledged',UTC_TIMESTAMP(),IF(?='assigned',NULL,acknowledged_at)),acknowledged_by=IF(?='acknowledged',?,IF(?='assigned',NULL,acknowledged_by)),completed_at=IF(?='completed',UTC_TIMESTAMP(),IF(?='assigned',NULL,completed_at)),completed_by=IF(?='completed',?,IF(?='assigned',NULL,completed_by)),cancelled_at=IF(?='cancelled',UTC_TIMESTAMP(),IF(?='assigned',NULL,cancelled_at))";
        $params=[$newStatus,$actor,$newStatus,$newStatus,$newStatus,$actor,$newStatus,$newStatus,$newStatus,$actor,$newStatus,$newStatus,$newStatus,$newStatus];
        if($op==='complete' && $old['kind']==='request'){$sql.=',response_text=?';$params[]=trim((string)$response)!==''?trim((string)$response):$old['response_text'];}
        $sql.=' WHERE id=? AND organization_id=? AND event_id=?';
        array_push($params,$id,$org,$eventId);
        $pdo->prepare($sql)->execute($params);
        if($image!==null && $old['kind']==='request'){
            $s=$pdo->prepare('INSERT INTO scouting_assignment_evidence (organization_id,assignment_id,mime_type,image_data,uploaded_by,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())');
            $s->bindValue(1,$org,PDO::PARAM_INT);$s->bindValue(2,$id,PDO::PARAM_INT);
            $s->bindValue(3,$image[0]);$s->bindValue(4,$image[1],PDO::PARAM_LOB);
            $s->bindValue(5,$actor,PDO::PARAM_INT);$s->execute();
        }
        sac_audit($pdo,$org,$eventId,$id,$auditAction,$old['assignee_user_id']===null?null:(int)$old['assignee_user_id'],$old['assignee_user_id']===null?null:(int)$old['assignee_user_id'],(string)$old['status'],$newStatus,$actor,'Recorded in Assignment Center');
        if($newStatus==='assigned' && in_array((string)$old['status'],['cancelled','completed'],true) && (int)$old['assignee_user_id']>0){
            $type=(string)$old['kind'];
            sac_notify($pdo,$org,$eventId,(int)$old['assignee_user_id'],$type,
                'Assignment reopened',
                $type==='match'?($old['alliance'].' '.strtoupper(substr((string)$old['alliance'],0,1)).$old['station'].' · Match assignment reopened'):
                ($type==='pit'?'Pit team #'.(int)$old['frc_team_number'].' reopened':'Scouting request reopened'),$id);
        }
        $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

// Tenant-protected image endpoint: browser cannot retrieve another organization's media.
if(isset($_GET['photo_user']) || isset($_GET['evidence'])){
    $image=null;
    if(isset($_GET['photo_user'])){
        $s=$pdo->prepare('SELECT p.mime_type,p.image_data FROM scouting_assignment_user_photos p JOIN users u ON u.id=p.user_id AND u.organization_id=p.organization_id WHERE p.organization_id=? AND p.user_id=? LIMIT 1');
        $s->execute([$org,(int)$_GET['photo_user']]);$image=$s->fetch(PDO::FETCH_ASSOC);
    } else {
        $s=$pdo->prepare('SELECT p.mime_type,p.image_data FROM scouting_assignment_evidence p JOIN scouting_assignments a ON a.id=p.assignment_id AND a.organization_id=p.organization_id WHERE p.organization_id=? AND p.id=? LIMIT 1');
        $s->execute([$org,(int)$_GET['evidence']]);$image=$s->fetch(PDO::FETCH_ASSOC);
    }
    if(!$image){http_response_code(404);exit('Image not found');}
    if(!in_array($image['mime_type'],['image/jpeg','image/png','image/webp'],true)){http_response_code(415);exit;}
    header('Content-Type: '.$image['mime_type']);header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');header('Content-Disposition: inline');
    echo $image['image_data'];exit;
}

$events=neptune_event_selector_rows($pdo,$org,(int)($_POST['event_id']??$_GET['event_id']??0),neptune_selector_show_history());
$eventId=(int)($_POST['event_id']??$_GET['event_id']??($events[0]['id']??0));
$event=null;
foreach($events as $item){if((int)$item['id']===$eventId){$event=$item;break;}}
if($event===null){$eventId=0;}
$msg='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try {
        $op=(string)($_POST['op']??'');
        if(!$event && $op!=='status')throw new InvalidArgumentException('Select an event from this organization.');
        if($op==='bulk_match' || $op==='one_match'){
            $ids=$op==='one_match'?[(int)($_POST['match_id']??0)]:(array)($_POST['match_ids']??[]);
            $ids=array_values(array_unique(array_map('intval',$ids)));
            if(!$ids || count($ids)>250 || min($ids)<1)throw new InvalidArgumentException('Select 1 to 250 valid matches.');
            $userId=(int)($_POST['user_id']??0);sac_valid_user($pdo,$org,$userId);
            $alliance=(string)($_POST['alliance']??'');$station=(int)($_POST['station']??0);
            $mode=sac_mode((string)($_POST['scouting_mode']??'either'));
            sac_match_slot($ids[0],$alliance,$station);
            $qs=implode(',',array_fill(0,count($ids),'?'));
            $s=$pdo->prepare("SELECT id,comp_level,set_number,match_number FROM matches WHERE organization_id=? AND event_id=? AND id IN ($qs)");
            $s->execute(array_merge([$org,$eventId],$ids));
            $validatedMatches=$s->fetchAll(PDO::FETCH_ASSOC);
            if(count($validatedMatches)!==count($ids))throw new InvalidArgumentException('At least one match does not belong to this event.');
            $matchLabelById=[];
            foreach($validatedMatches as $validMatch)$matchLabelById[(int)$validMatch['id']]=sac_match_label($validMatch);
            $pdo->beginTransaction();
            try{
                $newCount=0;
                $newMatches=[];
                foreach($ids as $mid){
                    if(sac_slot_save($pdo,$org,$eventId,$actor,'match',sac_match_slot($mid,$alliance,$station),$mid,$alliance,$station,$mode,null,$userId)){
                        $newCount++;$newMatches[]=$matchLabelById[$mid]??('Match #'.$mid);
                    }
                }
                if($newCount>0){
                    $slot=$alliance.' '.strtoupper(substr($alliance,0,1)).$station;
                    $eventName=(string)($event['name']??('Event #'.$eventId));
                    $shortMatches=implode(', ',array_slice($newMatches,0,5));
                    if($newCount>5)$shortMatches.=' +'.($newCount-5).' more';
                    sac_notify($pdo,$org,$eventId,$userId,'match',
                        $newCount===1?'New match assignment':$newCount.' new match assignments',
                        $shortMatches.' · '.$slot.' · '.ucfirst($mode).' scouting · '.$eventName);
                }
                $pdo->commit();
            }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();throw $ex;}
            $msg=count($ids).' match position(s) saved.';
        }elseif($op==='bulk_pit'){
            $teams=array_values(array_unique(array_map('intval',(array)($_POST['teams']??[]))));
            if(!$teams || count($teams)>250 || min($teams)<1)throw new InvalidArgumentException('Select 1 to 250 pit teams.');
            $userId=(int)($_POST['user_id']??0);sac_valid_user($pdo,$org,$userId);
            $qs=implode(',',array_fill(0,count($teams),'?'));
            $s=$pdo->prepare("SELECT frc_team_number FROM event_teams WHERE event_id=? AND frc_team_number IN ($qs)");
            $s->execute(array_merge([$eventId],$teams));
            if(count($s->fetchAll(PDO::FETCH_COLUMN))!==count($teams))throw new InvalidArgumentException('At least one team is not on this event roster.');
            $pdo->beginTransaction();
            try{
                $newCount=0;
                $newTeams=[];
                foreach($teams as $team){
                    if(sac_slot_save($pdo,$org,$eventId,$actor,'pit',sac_pit_slot($eventId,$team),null,null,null,null,$team,$userId)){
                        $newCount++;$newTeams[]=$team;
                    }
                }
                if($newCount>0){
                    $shortTeams=implode(', ',array_map(static fn($n)=>'#'.$n,array_slice($newTeams,0,4)));
                    if($newCount>4)$shortTeams.=' +'.($newCount-4).' more';
                    sac_notify($pdo,$org,$eventId,$userId,'pit',
                        $newCount===1?'New pit assignment':$newCount.' new pit assignments',
                        'Pit teams '.$shortTeams.' · '.(string)($event['name']??('Event #'.$eventId)));
                }
                $pdo->commit();
            }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();throw $ex;}
            $msg=count($teams).' pit assignment(s) saved.';
        }elseif($op==='new_request'){
            $userId=(int)($_POST['user_id']??0);sac_valid_user($pdo,$org,$userId);
            $kind=(string)($_POST['request_kind']??'');
            if(!in_array($kind,['question','photo','verify','other'],true))throw new InvalidArgumentException('Choose a request type.');
            $team=(int)($_POST['frc_team_number']??0);$team=$team>0?$team:null;
            if($team!==null){$s=$pdo->prepare('SELECT 1 FROM event_teams WHERE event_id=? AND frc_team_number=?');$s->execute([$eventId,$team]);if(!$s->fetchColumn())throw new InvalidArgumentException('Requested team is not on the event roster.');}
            $instructions=trim((string)($_POST['instructions']??''));
            if($instructions==='' || strlen($instructions)>2000)throw new InvalidArgumentException('Enter request instructions (up to 2000 characters).');
            $pdo->beginTransaction();
            try{
                $s=$pdo->prepare("INSERT INTO scouting_assignments (organization_id,event_id,kind,frc_team_number,request_kind,instructions,assignee_user_id,status,created_by,updated_by,assigned_at) VALUES (?,?,'request',?,?,?,?,'assigned',?,?,UTC_TIMESTAMP())");
                $s->execute([$org,$eventId,$team,$kind,$instructions,$userId,$actor,$actor]);
                $newId=(int)$pdo->lastInsertId();
                sac_audit($pdo,$org,$eventId,$newId,'created',null,$userId,null,'assigned',$actor);
                sac_notify($pdo,$org,$eventId,$userId,'request','New scouting request · '.ucfirst($kind),
                    ($team!==null?'Team #'.$team.' · ':'').$instructions,$newId);
                $pdo->commit();
            }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();throw $ex;}
            $msg='Scouting request saved.';
        }elseif($op==='status'){
            $id=(int)($_POST['assignment_id']??0);$verb=(string)($_POST['transition']??'');
            $response=isset($_POST['response_text'])?trim((string)$_POST['response_text']):null;
            if($response!==null && strlen($response)>4000)throw new InvalidArgumentException('Response is too long.');
            $image=null;
            if(isset($_FILES['evidence_photo']) && ($_FILES['evidence_photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$image=sac_upload_image($_FILES['evidence_photo'],4*1024*1024);}
            // Resolve the assignment's own event, rather than assuming the selected event.
            $ownerEvent=$pdo->prepare('SELECT event_id FROM scouting_assignments WHERE id=? AND organization_id=? LIMIT 1');
            $ownerEvent->execute([$id,$org]);
            $assignmentEvent=(int)$ownerEvent->fetchColumn();
            if($assignmentEvent<1)throw new InvalidArgumentException('Assignment not found.');
            sac_status_save($pdo,$org,$assignmentEvent,$actor,$id,$verb,$response,$image);
            $msg='Assignment status updated.';
        }
    }catch(Throwable $ex){$error=$ex instanceof InvalidArgumentException?$ex->getMessage():'Could not save your changes. Check that the database migration is installed.';error_log('[Scouting Assignment Center] '.$ex->getMessage());}
    if($error===''){
        foreach(($GLOBALS['sac_push_recipients']??[]) as $recipient){neptune_assignment_push_send($pdo,(int)$recipient[0],(int)$recipient[1],25);}
        $tab=(string)($_POST['tab']??'matches');
        if(!in_array($tab,['matches','pit','requests'],true))$tab='matches';
        header('Location: '.base_url('admin/assignment-center.php').'?event_id='.$eventId.'&tab='.$tab.'&match_id='.(int)($_POST['view_match']??0).'&saved=1',true,303);exit;
    }
}
if(isset($_GET['saved']) && !$error)$msg='Changes saved to Neptune.';

$scoutPresence=[];$actionLatest=[];$tagNotes=[];$onlineUsers=[];$onlinePages=[];$users=[];$photos=[];$matches=[];$matchTeams=[];$roster=[];$assignments=[];$assignBySlot=[];$requests=[];$pitByTeam=[];$history=[];
$s=$pdo->prepare('SELECT id,display_name,username,role,active FROM users WHERE organization_id=? ORDER BY active DESC,display_name,username');
$s->execute([$org]);$users=$s->fetchAll(PDO::FETCH_ASSOC);
$activeUsers=array_values(array_filter($users,static fn($x)=>(int)$x['active']===1));
try{
    $s=$pdo->prepare('SELECT user_id FROM scouting_assignment_user_photos WHERE organization_id=?');$s->execute([$org]);
    $photos=array_fill_keys(array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN)),true);
    if($event){
        $s=$pdo->prepare("SELECT id,comp_level,set_number,match_number,field_id,scheduled_time,state FROM matches WHERE organization_id=? AND event_id=? ORDER BY FIELD(comp_level,'qm','ef','qf','sf','f','legacy'),set_number,match_number,field_id,id");
        $s->execute([$org,$eventId]);$matches=$s->fetchAll(PDO::FETCH_ASSOC);
        $s=$pdo->prepare('SELECT mt.match_id,mt.alliance,mt.station,mt.frc_team_number FROM match_teams mt JOIN matches m ON m.id=mt.match_id WHERE m.organization_id=? AND m.event_id=?');
        $s->execute([$org,$eventId]);foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$matchTeams[(int)$r['match_id']][(string)$r['alliance']][(int)$r['station']]=(int)$r['frc_team_number'];
        $s=$pdo->prepare('SELECT frc_team_number,nickname FROM event_teams WHERE event_id=? ORDER BY frc_team_number');
        $s->execute([$eventId]);$roster=$s->fetchAll(PDO::FETCH_ASSOC);
        $s=$pdo->prepare('SELECT a.*,u.display_name assignee_name,u.active assignee_active,u.last_login_at assignee_last_login FROM scouting_assignments a LEFT JOIN users u ON u.id=a.assignee_user_id AND u.organization_id=a.organization_id WHERE a.organization_id=? AND a.event_id=? ORDER BY a.id DESC');
        $s->execute([$org,$eventId]);$assignments=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($assignments as $a){if($a['kind']==='pit')$pitByTeam[(int)$a['frc_team_number']]=$a;elseif($a['kind']==='match')$assignBySlot[(int)$a['match_id']][(string)$a['alliance']][(int)$a['station']]=$a;}
        // Requests remain visible across event changes, including acknowledged tasks.
        $rq=$pdo->prepare("SELECT a.*,u.display_name assignee_name,u.active assignee_active,u.last_login_at assignee_last_login,ev.name request_event_name FROM scouting_assignments a JOIN events ev ON ev.id=a.event_id AND ev.organization_id=a.organization_id LEFT JOIN users u ON u.id=a.assignee_user_id AND u.organization_id=a.organization_id WHERE a.organization_id=? AND a.kind='request' ORDER BY CASE a.status WHEN 'assigned' THEN 0 WHEN 'acknowledged' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END,a.id DESC LIMIT 300");
        $rq->execute([$org]);$requests=$rq->fetchAll(PDO::FETCH_ASSOC);
        // Site-wide online state is independent of the match-scouting heartbeat.
        // If the optional presence table is not installed, last sign-in still displays.
        try{
            $ps=$pdo->prepare('SELECT user_id,last_seen_at FROM neptune_user_presence WHERE organization_id=? AND last_seen_at>=UTC_TIMESTAMP()-INTERVAL 150 SECOND');
            $ps->execute([$org]);
            foreach($ps->fetchAll(PDO::FETCH_ASSOC) as $record){
                $uid=(int)$record['user_id'];
                $onlineUsers[$uid]=(string)$record['last_seen_at'];
            }
            // Separate per-page heartbeats avoid clobbering TAG presence if the same
            // scouter has another visible Neptune tab or device open simultaneously.
            $ps=$pdo->prepare('SELECT user_id,page_kind,last_seen_at FROM neptune_user_page_presence WHERE organization_id=? AND last_seen_at>=UTC_TIMESTAMP()-INTERVAL 150 SECOND ORDER BY last_seen_at DESC');
            $ps->execute([$org]);
            $pagePriority=['tag-match'=>5,'tag-pit'=>4,'tag-team'=>3,'traditional'=>2,'other'=>1];
            foreach($ps->fetchAll(PDO::FETCH_ASSOC) as $record){
                $uid=(int)$record['user_id'];
                $kind=(string)$record['page_kind'];
                if(!isset($onlineUsers[$uid]))continue;
                if(($pagePriority[$kind]??0)>($pagePriority[$onlinePages[$uid]??'']??0))$onlinePages[$uid]=$kind;
            }
        }catch(Throwable $onlineError){error_log('[Assignment online presence] '.get_class($onlineError));}

        // Match-scoped scouting activity for the assigned user/station, current run only.
        // A heartbeat is evidence of a recent scouting connection, not site-wide presence.
        try {
            $q=$pdo->prepare("SELECT ss.match_id,ss.user_id,ss.alliance,ss.station,MAX(ss.last_seen_at) last_seen_at,
                        MAX(CASE WHEN ss.status IN ('connected','scouting') AND ss.last_seen_at >= UTC_TIMESTAMP()-INTERVAL 120 SECOND THEN 1 ELSE 0 END) recently_connected,
                        COUNT(sa.id) action_count,MAX(sa.id) last_action_id,
                        MAX(sa.recorded_at) last_action_at,
                        MAX(CASE WHEN sa.recorded_at >= UTC_TIMESTAMP()-INTERVAL 120 SECOND THEN 1 ELSE 0 END) recent_action
                        FROM scout_sessions ss
                        JOIN matches mm ON mm.id=ss.match_id AND mm.organization_id=ss.organization_id AND mm.event_id=ss.event_id AND mm.run_number=ss.match_run_number
                        LEFT JOIN scouting_actions sa ON sa.scout_session_id=ss.id AND sa.organization_id=ss.organization_id AND sa.match_run_number=ss.match_run_number AND sa.deleted_at IS NULL
                        WHERE ss.organization_id=? AND ss.event_id=?
                        GROUP BY ss.match_id,ss.user_id,ss.alliance,ss.station");
            $q->execute([$org,$eventId]);
            $actionIds=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $entry){
                $key=(int)$entry['match_id'].':'.(int)$entry['user_id'].':'.$entry['alliance'].':'.(int)$entry['station'];
                $scoutPresence[$key]=$entry;
                if((int)$entry['last_action_id']>0)$actionIds[]=(int)$entry['last_action_id'];
            }
            if($actionIds){
                $actionIds=array_values(array_unique($actionIds));
                $qs=implode(',',array_fill(0,count($actionIds),'?'));
                $q=$pdo->prepare("SELECT id,action_name,result,recorded_at FROM scouting_actions WHERE organization_id=? AND id IN ($qs)");
                $q->execute(array_merge([$org],$actionIds));
                foreach($q->fetchAll(PDO::FETCH_ASSOC) as $action)$actionLatest[(int)$action['id']]=$action;
            }
        }catch(Throwable $presenceError){error_log('[Assignment match activity] '.$presenceError->getMessage());}
        // TAG match observations are distinct from traditional scouting actions.
        // Optional table: an older installation may not have TAG scouting enabled.
        try {
            $q=$pdo->prepare("SELECT match_id,user_id,alliance,station,note,tags_json,updated_at,UNIX_TIMESTAMP(updated_at) AS updated_epoch,COUNT(*) OVER (PARTITION BY match_id,user_id,alliance,station) AS report_count FROM tag_scouting_match_data WHERE organization_id=? AND event_id=? ORDER BY updated_at DESC,id DESC");
            $q->execute([$org,$eventId]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $tag){
                $key=(int)$tag['match_id'].':'.(int)$tag['user_id'].':'.$tag['alliance'].':'.(int)$tag['station'];
                if(!isset($tagNotes[$key]))$tagNotes[$key]=$tag;
            }
        }catch(Throwable $tagError){error_log('[Assignment TAG observations] '.$tagError->getMessage());}
        $s=$pdo->prepare('SELECT h.*,u.display_name actor_name FROM scouting_assignment_history h LEFT JOIN users u ON u.id=h.actor_user_id AND u.organization_id=h.organization_id WHERE h.organization_id=? AND h.event_id=? ORDER BY h.id DESC LIMIT 25');
        $s->execute([$org,$eventId]);$history=$s->fetchAll(PDO::FETCH_ASSOC);
    }
}catch(Throwable $ex){$error='Assignment tables are unavailable. Apply the included SQL migration to this environment first.';error_log('[Scouting Assignment Center query] '.$ex->getMessage());}

// Flag potential double coverage without preventing deliberate multi-position plans.
$matchConflicts=[];
foreach($assignBySlot as $mid=>$alliances){
    $counts=[];
    foreach($alliances as $positions){
        foreach($positions as $slot){
            if(($slot['status']??'')==='cancelled')continue;
            $uid=(int)($slot['assignee_user_id']??0);
            if($uid>0)$counts[$uid]=($counts[$uid]??0)+1;
        }
    }
    $matchConflicts[$mid]=count(array_filter($counts,static fn($n)=>$n>1));
}
$tab=(string)($_GET['tab']??($_POST['tab']??'matches'));
if(!in_array($tab,['matches','pit','requests'],true))$tab='matches';
$viewMatchId=(int)($_POST['view_match']??$_GET['match_id']??0);
$matchIndexes=array_column($matches,'id');
$viewIndex=array_search($viewMatchId,array_map('intval',$matchIndexes),true);
if($viewIndex===false)$viewIndex=0;
$viewMatch=$matches[$viewIndex]??null;
$viewMatchId=$viewMatch?(int)$viewMatch['id']:0;
$boardLink=static fn(int $mid): string=>'?event_id='.$eventId.'&tab=matches&match_id='.$mid;
$avatar=function(?array $a,int $size=40) use($photos): string {
    $id=(int)($a['assignee_user_id']??$a['id']??0);
    $name=(string)($a['assignee_name']??$a['display_name']??'Unassigned');
    if(isset($photos[$id]))return '<img class="sac-avatar" width="'.$size.'" height="'.$size.'" src="'.e(base_url('admin/assignment-center.php').'?photo_user='.$id).'" alt="" loading="lazy">';
    return '<span class="sac-avatar sac-initials" style="width:'.$size.'px;height:'.$size.'px" aria-hidden="true">'.e(sac_initials($name)).'</span>';
};
$userOptions=function(int $selected=0) use($activeUsers): string {
    $html='<option value="">Choose roboticist</option>';
    foreach($activeUsers as $x){$id=(int)$x['id'];$html.='<option value="'.$id.'"'.($selected===$id?' selected':'').'>'.e((string)$x['display_name']).' · @'.e((string)$x['username']).'</option>';}
    return $html;
};
$statusAction=function(array $a,string $tab) use($eventId): string {
    $state=(string)$a['status'];
    $next=['assigned'=>['acknowledge'=>'Acknowledge','complete'=>'Complete','cancel'=>'Cancel'],'acknowledged'=>['complete'=>'Complete','cancel'=>'Cancel'],'completed'=>['reopen'=>'Reopen'],'cancelled'=>['reopen'=>'Reopen']][$state]??[];
    $html='<form method="post" class="sac-status-form" enctype="multipart/form-data"><input type="hidden" name="view_match" value="'.(int)($GLOBALS['viewMatchId']??0).'"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="event_id" value="'.$eventId.'"><input type="hidden" name="tab" value="'.e($tab).'"><input type="hidden" name="op" value="status"><input type="hidden" name="assignment_id" value="'.(int)$a['id'].'">';
    if($a['kind']==='request' && in_array($state,['assigned','acknowledged'],true)){
        $html.='<label class="sac-small-label">Response <textarea name="response_text" rows="2" maxlength="4000" placeholder="Answer or verification notes">'.e((string)($a['response_text']??'')).'</textarea></label>';
        $html.='<label class="sac-small-label">Photo evidence (JPG, PNG, WebP · 4 MB max)<input type="file" name="evidence_photo" accept="image/jpeg,image/png,image/webp"></label>';
    }
    foreach($next as $key=>$label)$html.='<button class="'.($key==='cancel'?'sac-quiet':'sac-action').'" type="submit" name="transition" value="'.e($key).'">'.e($label).'</button>';
    return $html.'</form>';
};
// A recent heartbeat shows a connected scouting session; recent saved actions or
// TAG observations show actual scouting. Last login is not a live online signal.
function sac_live_card_state(?array $activity,?array $tag,?array $lastAction=null,?string $lastLogin=null): array {
    $recentTraditional=$activity && (int)($activity['recent_action']??0)===1;
    $tagAt=trim((string)($tag['updated_at']??''));
    $tagTs=isset($tag['updated_epoch'])?(int)$tag['updated_epoch']:false;
    $recentTag=$tagTs!==false && $tagTs>=time()-120 && $tagTs<=time()+60;
    $connected=$activity && (int)($activity['recently_connected']??0)===1;
    $state=($recentTraditional||$recentTag)?'scouting':($connected?'connected':'idle');
    return [
        'state'=>$state,
        'actions'=>(int)($activity['action_count']??0),
        'last_seen'=>(string)($activity['last_seen_at']??''),
        'last_action_at'=>(string)($activity['last_action_at']??''),
        'tag_updated_at'=>$tagAt,
        'tag_saved'=>$tag!==null,
        'tag_note'=>(string)($tag['note']??''),
        'latest_action'=>$lastAction?trim((string)($lastAction['action_name']??'').(trim((string)($lastAction['result']??''))!==''?' · '.(string)$lastAction['result']:'')):'',
        'last_login'=>(string)($lastLogin??''),
    ];
}
function sac_local_time(?string $utc): string {
    $utc=trim((string)$utc);
    if($utc==='')return '<span class="sac-muted">Not recorded</span>';
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$utc,new DateTimeZone('UTC'));
    if(!$date)return '<span class="sac-muted">Not recorded</span>';
    return '<time data-neptune-utc="'.e($date->format('Y-m-d\TH:i:s\Z')).'" datetime="'.e($date->format('Y-m-d\TH:i:s\Z')).'">'.e($date->format('M j, g:i A')).' UTC</time>';
}
$photoUrl=fn(int $id)=>base_url('admin/assignment-center.php').'?photo_user='.$id;
// Read-only refresh does not navigate or clear bulk-selection checkboxes.
if(isset($_GET['activity_json'])){
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $live=[];
    foreach($assignBySlot as $mid=>$alliances){
        foreach(['Red','Blue'] as $side){
            for($station=1;$station<=3;$station++){
                $a=$alliances[$side][$station]??null;
                if(!$a||($a['status']??'')==='cancelled')continue;
                $key=(int)$mid.':'.(int)$a['assignee_user_id'].':'.$side.':'.$station;
                $activity=$scoutPresence[$key]??null;$lastAction=$activity?($actionLatest[(int)$activity['last_action_id']]??null):null;
                $live[$mid.':'.$side.':'.$station]=sac_live_card_state($activity,$tagNotes[$key]??null,$lastAction,$a['assignee_last_login']??null);
            }
        }
    }
    echo json_encode(['status'=>$live,'checked_at'=>gmdate('Y-m-d\TH:i:s\Z')],JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Lightweight visibility polling. Read-only, authorized and organization-scoped;
// the administrator can keep match selections while statuses refresh.
if(isset($_GET['presence_poll'])){
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $live=[];$tagRecent=[];
    foreach($scoutPresence as $key=>$row){if((int)($row['recently_connected']??0)===1)$live[]=$key;}
    foreach($tagNotes as $key=>$row){if(!empty($row['updated_at']) && (int)($row['updated_epoch']??0)>=time()-180)$tagRecent[]=$key;}
    echo json_encode(['online'=>array_keys($onlineUsers),'pages'=>$onlinePages,'scouting'=>$live,'tag_recent'=>$tagRecent],JSON_THROW_ON_ERROR);
    exit;
}
$pageTitle='Assignment Center';$moduleName='SATURN';
include dirname(__DIR__).'/partials_header.php';
?>
<style>
.sac-hero{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap}.sac-hero h1{margin:5px 0}.sac-kicker{font-size:.75rem;font-weight:900;letter-spacing:.16em;color:var(--accent,#60d3e9)}
.sac-sub{color:var(--muted,#9eb4ca);line-height:1.5;max-width:850px}.sac-card{border:1px solid var(--line,var(--border,#354a62));border-radius:18px;background:var(--panel,var(--card,#14263b));padding:18px;margin-top:16px}.sac-card h2,.sac-card h3{margin:0 0 12px}.sac-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.sac-tab{display:inline-flex;gap:8px;align-items:center;border:1px solid var(--line,var(--border,#354a62));border-radius:12px;padding:11px 15px;text-decoration:none;color:var(--text,#eef7ff);background:var(--panel,var(--card,#162c43));font-weight:800}.sac-tab[aria-current=page]{border-color:var(--accent,#60d3e9);background:color-mix(in srgb,var(--accent,#60d3e9) 12%,var(--panel,#14263b))}
.sac-grid-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end}.sac-grid-form label,.sac-small-label{display:flex;flex-direction:column;gap:6px;font-size:.83rem;font-weight:700}.sac-grid-form select,.sac-grid-form input,.sac-small-label textarea,.sac-small-label input,.sac-card select,.sac-card textarea{max-width:100%;min-width:0;width:100%;padding:10px;border:1px solid var(--line,var(--border,#39546f));border-radius:9px;background:var(--panel2,var(--panel,#102238));color:var(--text,#f0f6ff);font:inherit}.sac-card button{cursor:pointer}.sac-grid-form button{align-self:end}.sac-match-picks,.sac-team-picks{display:flex;gap:7px;flex-wrap:wrap;max-height:240px;overflow:auto;margin:12px 0;padding:7px}.sac-choice{position:relative;display:flex;gap:7px;align-items:center;padding:8px 12px;border:1px solid var(--line,var(--border,#39546f));border-radius:11px;cursor:pointer}.sac-choice:has(input:checked){border-color:var(--accent,#60d3e9);background:color-mix(in srgb,var(--accent,#60d3e9) 13%,transparent)}.sac-choice input{accent-color:var(--accent,#60d3e9)}
.sac-match{border:1px solid var(--line,var(--border,#39546f));border-radius:15px;padding:15px;margin:15px 0}.sac-match-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:13px}.sac-match-head b{font-size:1.15rem}.sac-match-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.sac-alliance{border:1px solid var(--line,var(--border,#39546f));border-radius:12px;padding:11px;min-width:0}.sac-alliance.red{border-top:4px solid #da5964}.sac-alliance.blue{border-top:4px solid #4f8bf5}.sac-alliance-title{font-size:.76rem;letter-spacing:.09em;font-weight:900;margin-bottom:10px}.sac-position{display:flex;gap:9px;align-items:center;padding:10px 0;border-top:1px solid var(--line,var(--border,#344a60));min-width:0}.sac-position:first-of-type{border:0}.sac-person{display:flex;gap:9px;align-items:center;min-width:0;flex:1}.sac-avatar{width:40px;height:40px;object-fit:cover;border-radius:50%;flex:0 0 auto;border:1px solid var(--line,var(--border,#38516c));background:var(--panel2,#18364f)}.sac-initials{display:grid;place-items:center;color:var(--text,#fff);font-size:.85rem;font-weight:900}.sac-person-meta{min-width:0}.sac-person-meta strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.89rem}.sac-person-meta small{display:block;color:var(--muted,#a3b9cc);font-size:.76rem}.sac-position-controls{display:flex;flex-wrap:wrap;gap:5px;align-items:end}.sac-position-controls select{min-width:130px;width:auto;font-size:.82rem;padding:7px}.sac-position-controls button,.sac-status-form button,.sac-action,.sac-quiet{border-radius:9px;padding:8px 10px;border:1px solid var(--line,var(--border,#39546f));background:var(--panel2,var(--panel,#11263a));color:var(--text,#fff);font-weight:750;font-size:.8rem}.sac-position-controls button,.sac-status-form .sac-action{border-color:var(--accent,#58cadd)}.sac-badge{display:inline-block;font-size:.71rem;text-transform:uppercase;letter-spacing:.04em;font-weight:900;padding:4px 7px;border-radius:7px;background:var(--panel2,#17354b);border:1px solid var(--line,#35465e)}.sac-badge.completed{border-color:#359f71}.sac-badge.acknowledged{border-color:#62b7df}.sac-badge.cancelled{opacity:.7}.sac-status-form{display:flex;gap:5px;flex-wrap:wrap;align-items:end}.sac-status-form .sac-small-label{flex:1 1 190px}.sac-roster{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}.sac-roster-item{border:1px solid var(--line,var(--border,#39546f));border-radius:12px;padding:12px;min-width:0}.sac-roster-item .sac-status-form{margin-top:9px}.sac-muted{color:var(--muted,#a3b9cc)}.sac-summary{display:flex;gap:10px;flex-wrap:wrap}.sac-stat{padding:9px 15px;border:1px solid var(--line,var(--border,#39546f));border-radius:11px}.sac-stat strong{font-size:1.3rem;display:block}.sac-requests{display:grid;gap:11px}.sac-history{max-height:280px;overflow:auto}.sac-history-line{padding:8px 0;border-top:1px solid var(--line,var(--border,#39546f));font-size:.85rem}.sac-user-photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}.sac-user-photo-card{display:flex;gap:12px;border:1px solid var(--line,var(--border,#39546f));padding:12px;border-radius:12px;align-items:center;flex-wrap:wrap}.sac-user-photo-card form{width:100%}.sac-error{border:1px solid #e07272;border-radius:11px;padding:12px;margin-top:12px}.sac-ok{border:1px solid #4dba91;border-radius:11px;padding:12px;margin-top:12px}
@media(max-width:1000px){.sac-grid-form{grid-template-columns:repeat(2,minmax(0,1fr))}.sac-position{flex-wrap:wrap}.sac-position-controls{width:100%}}@media(max-width:640px){.sac-match-grid,.sac-grid-form{grid-template-columns:1fr}.sac-card{padding:12px}.sac-position-controls select{flex:1}}


/* Focus-first assignment planner: one match at a time; edits live in deliberate panels. */
.sac-layout-note{margin-top:10px;color:var(--muted,#a3b9cc);font-size:.92rem}
.sac-board-top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.sac-board-headline{display:flex;align-items:center;gap:11px;flex-wrap:wrap}.sac-board-headline h2{margin:0;font-size:1.4rem}
.sac-board-nav{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.sac-board-nav a{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid var(--line,var(--border,#39546f));border-radius:10px;color:var(--text,#fff);padding:10px 13px;background:var(--panel2,var(--panel,#14263b));font-weight:750}
.sac-board-nav a[aria-disabled="true"]{opacity:.35;pointer-events:none}.sac-board-nav select{min-width:150px;width:auto}
.sac-board-single{margin:0;padding:0;border:0}.sac-alliance{padding:15px}.sac-alliance-title{font-size:.94rem;padding:5px 0 12px;margin:0}.sac-alliance.red{background:color-mix(in srgb,#c62f44 7%,var(--panel,#14263b))}.sac-alliance.blue{background:color-mix(in srgb,#2467e5 7%,var(--panel,#14263b))}
.sac-slot{padding:12px;border:1px solid var(--line,var(--border,#39546f));border-radius:12px;margin:9px 0;background:var(--panel,var(--card,#14263b))}.sac-slot-main{display:flex;gap:12px;align-items:center;min-width:0}
.sac-slot-pos{display:flex;align-items:center;justify-content:center;font-weight:900;border:1px solid var(--line,var(--border,#39546f));border-radius:8px;min-width:40px;min-height:38px;font-size:.86rem}.sac-alliance.red .sac-slot-pos{border-color:#d65b6a}.sac-alliance.blue .sac-slot-pos{border-color:#6094eb}
.sac-slot .sac-avatar{height:52px;width:52px}.sac-slot .sac-person-meta{flex:1}.sac-slot .sac-person-meta strong{white-space:normal;font-size:1rem}.sac-slot .sac-person-meta small{margin-top:4px;line-height:1.55}
.sac-slot-footer{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px}
.sac-edit{margin-top:8px;border-top:1px solid var(--line,var(--border,#39546f));padding-top:7px}.sac-edit summary,.sac-progress summary{cursor:pointer;color:var(--accent,#67d3e5);font-weight:750;list-style:none;font-size:.85rem}.sac-edit summary::-webkit-details-marker,.sac-progress summary::-webkit-details-marker{display:none}.sac-edit summary:before,.sac-progress summary:before{content:'▸';display:inline-block;padding-right:6px}.sac-edit[open] summary:before,.sac-progress[open] summary:before{content:'▾'}
.sac-edit .sac-position-controls{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;gap:8px;align-items:end;margin-top:10px}.sac-edit .sac-position-controls select{width:100%;min-width:0}.sac-edit .sac-position-controls button{min-height:40px}.sac-progress{margin-top:9px}.sac-progress .sac-status-form{margin-top:9px}
.sac-step{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;border:1px solid var(--accent,#65ceda);font-size:.78rem;font-weight:800;margin-right:6px}.sac-bulk-planner{margin:16px 0}.sac-bulk-planner summary{cursor:pointer;font-weight:900;font-size:1.03rem;list-style:none}.sac-bulk-planner summary:before{content:'+ ';font-size:1.15rem;color:var(--accent,#67d3e5)}.sac-bulk-planner[open] summary:before{content:'− '}
.sac-bulk-inner{border-top:1px solid var(--line,var(--border,#39546f));padding-top:15px;margin-top:13px}.sac-step-row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin:14px 0 6px}
.sac-board-overview{margin-top:20px}.sac-board-overview summary{cursor:pointer;font-weight:800}.sac-cover-table{width:100%;border-collapse:collapse;margin-top:12px;font-size:.85rem}.sac-cover-table th,.sac-cover-table td{padding:9px;border-bottom:1px solid var(--line,var(--border,#39546f));text-align:center}.sac-cover-table th:first-child,.sac-cover-table td:first-child{text-align:left}.sac-cover-table a{color:var(--accent,#67d3e5);font-weight:800}.sac-cover-dot{display:inline-flex;justify-content:center;align-items:center;border-radius:7px;min-width:25px;height:25px;border:1px solid var(--line,var(--border,#39546f));color:var(--muted,#b1c2d2)}.sac-cover-dot.filled{color:var(--text,#fff);border-color:#459c7c;background:color-mix(in srgb,#39a478 12%,transparent)}
@media(max-width:660px){.sac-board-top{align-items:stretch}.sac-board-nav{width:100%}.sac-board-nav select{flex:1}.sac-edit .sac-position-controls{grid-template-columns:1fr}.sac-slot-main{gap:9px}.sac-slot .sac-avatar{width:44px;height:44px}.sac-slot-pos{min-width:33px}}

.sac-bulk-primary{border-color:color-mix(in srgb,var(--accent,#58cadd) 50%,var(--line));}
.sac-bulk-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:15px}.sac-bulk-header h2{margin:0 0 5px}.sac-bulk-header p{margin:0}
.sac-bulk-actions{display:flex;align-items:center;flex-wrap:wrap;gap:9px;margin-top:15px}.sac-bulk-actions span{margin-right:auto}.sac-bulk-actions button{border-radius:10px}.sac-primary-save{background:var(--accent,#36bdd2)!important;color:#071522!important;font-weight:850!important}
.sac-coverage-list{display:grid;gap:9px;max-height:none;overflow:visible;padding:0;margin:0}.sac-coverage-row{display:grid;grid-template-columns:130px minmax(0,1fr);gap:11px;align-items:center;border:1px solid var(--line);border-radius:13px;padding:10px;background:var(--panel2,var(--panel))}.sac-coverage-row:has(input:checked){outline:2px solid var(--accent,#58cadd);outline-offset:-2px}.sac-row-selector{display:flex;flex-wrap:wrap;align-items:center;gap:7px;cursor:pointer}.sac-row-selector input{width:18px;height:18px;accent-color:var(--accent,#58cadd)}.sac-row-selector small{width:100%;padding-left:25px;color:var(--muted)}
.sac-coverage-alliances{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;min-width:0}.sac-coverage-side{padding:8px;border-radius:9px;border-top:3px solid #d65765;min-width:0}.sac-coverage-side.blue{border-top-color:#4f8bf5}.sac-coverage-title{font-size:.74rem;font-weight:900;margin-bottom:6px}.sac-coverage-three{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:5px}.sac-coverage-cell{display:flex;gap:5px;align-items:center;min-width:0;border:1px solid var(--line);border-radius:9px;padding:5px}.sac-coverage-cell .sac-avatar{width:32px;height:32px;flex:0 0 32px}.sac-position-key{font-size:.72rem;font-weight:900;flex:none}.sac-coverage-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.79rem}.sac-cover-empty{font-size:.77rem;color:var(--muted)}
.sac-individual>summary{cursor:pointer;font-size:1rem;padding:4px}.sac-individual .sac-board-single{margin-bottom:0}
@media(max-width:850px){.sac-coverage-row{grid-template-columns:1fr}.sac-row-selector small{width:auto;padding:0}.sac-coverage-three{grid-template-columns:1fr}.sac-coverage-cell{min-height:39px}}
@media(max-width:550px){.sac-coverage-alliances{grid-template-columns:1fr}.sac-coverage-three{grid-template-columns:repeat(3,minmax(0,1fr))}.sac-coverage-cell{flex-wrap:wrap}.sac-coverage-name{flex-basis:100%}}
.sac-match-activity{font-size:.74rem;color:var(--muted);padding:4px 5px 8px 16px;line-height:1.5;overflow-wrap:anywhere}.sac-presence.sac-online{font-weight:800;color:#42d5a3}.sac-last-action{color:var(--text);font-weight:600}


/* Assignment Center v8: use Neptune's shared tokens and nested-page hero. */
.sac-page-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap}
.sac-page-hero-main{min-width:0;flex:1 1 400px}
.sac-page-hero .btn{position:relative;z-index:1;white-space:nowrap}
.sac-page-hero p{max-width:62ch;margin:6px 0 0}
.sac-card{background:var(--panel);border-color:var(--line);border-radius:var(--neptune-hero-radius,16px);box-shadow:0 8px 20px var(--shadow)}
.sac-coverage{min-width:0}
.sac-coverage-list{display:grid;gap:12px;max-height:none;overflow:visible;margin:0;padding:0}
.sac-coverage-row{display:block;min-width:0;border:1px solid var(--line);background:var(--panel2);border-radius:14px;padding:13px;margin:0;transition:border-color .16s,background-color .16s}
.sac-coverage-row:has(.sac-match-check:checked){border-color:var(--accent);background:color-mix(in srgb,var(--accent) 5%,var(--panel2))}
.sac-row-selector{position:relative;display:inline-flex;align-items:center;flex-wrap:wrap;gap:9px;min-height:42px;padding:3px 8px 3px 3px;margin:0 0 10px;color:var(--text);border-radius:10px;cursor:pointer;user-select:none}
.sac-row-selector:hover .sac-check-visual{border-color:var(--accent)}
.sac-row-selector strong{font-size:1.06rem;line-height:1}
.sac-row-selector small{display:inline;color:var(--muted);font-weight:700;width:auto;padding:0;white-space:nowrap;font-size:.79rem}
.sac-match-check{position:absolute!important;width:1px!important;height:1px!important;margin:0!important;padding:0!important;opacity:0!important;clip-path:inset(50%);overflow:hidden}
.sac-check-visual{width:27px;height:27px;flex:0 0 27px;display:grid;place-items:center;border:2px solid var(--line);border-radius:8px;background:var(--panel);transition:background-color .16s,border-color .16s}
.sac-check-visual::after{content:'';display:block;width:12px;height:7px;border:solid var(--solid-text,#071522);border-width:0 0 3px 3px;transform:translateY(-2px) rotate(-45deg) scale(.6);opacity:0;transition:transform .12s,opacity .12s}
.sac-match-check:checked + .sac-check-visual{border-color:var(--accent);background:var(--accent)}
.sac-match-check:checked + .sac-check-visual::after{transform:translateY(-2px) rotate(-45deg) scale(1);opacity:1}
.sac-match-check:focus-visible + .sac-check-visual{outline:3px solid color-mix(in srgb,var(--accent) 65%,transparent);outline-offset:3px}
.sac-selected-indicator{display:none;border:1px solid var(--accent);color:var(--accent);border-radius:6px;font-size:.66rem;font-weight:900;letter-spacing:.035em;padding:3px 6px}
.sac-match-check:checked ~ .sac-selected-indicator{display:inline-block}
.sac-coverage-alliances{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:12px;min-width:0;align-items:stretch}
.sac-coverage-side{display:flex;flex-direction:column;min-width:0;gap:8px;padding:10px;border:1px solid var(--line);border-top:4px solid #d95568;border-radius:12px;background:var(--panel)}
.sac-coverage-side.blue{border-top-color:#5399ee;background:var(--panel)}
.sac-coverage-title{font-size:.82rem;font-weight:850;margin:0;color:var(--text);letter-spacing:0}
.sac-coverage-three{display:grid;grid-template-columns:1fr;grid-auto-rows:146px;grid-template-rows:none;gap:8px;min-width:0;align-content:start}
.sac-coverage-cell{box-sizing:border-box;display:flex;flex-direction:column;gap:5px;height:146px;min-height:0!important;min-width:0;overflow:hidden;border:1px solid var(--line);border-radius:10px;padding:9px;background:var(--panel2);align-items:stretch}
.sac-coverage-cell.is-assigned{border-color:color-mix(in srgb,var(--line) 74%,var(--accent) 26%)}
.sac-card-top{display:flex;justify-content:space-between;align-items:center;gap:4px;min-height:18px;flex:0 0 auto;min-width:0}
.sac-badge-short{display:none}
.sac-card-top .sac-badge{font-size:.60rem;line-height:1.2;padding:2px 5px;letter-spacing:0;white-space:nowrap;max-width:75%;overflow:hidden;text-overflow:ellipsis}
.sac-position-key{font-size:.84rem;font-weight:950;flex:none;color:var(--text)}
.sac-card-person{display:flex;gap:9px;align-items:center;min-width:0;flex:0 0 auto}
.sac-card-person .sac-avatar{width:42px!important;height:42px!important;flex:0 0 42px!important;object-fit:cover;border:2px solid var(--line)}
.sac-card-person .sac-initials{font-size:.85rem}
.sac-coverage-name{font-size:.81rem;font-weight:800;line-height:1.21;white-space:normal;overflow-wrap:anywhere;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.sac-live-line{display:flex;align-items:center;gap:5px;min-width:0;flex:0 0 auto;font-size:.72rem;font-weight:800;line-height:1.15;color:var(--muted)}
.sac-live-line:before{content:'';width:7px;height:7px;flex:0 0 7px;background:var(--muted);border-radius:50%}
.sac-live-line[data-state=online]:before{background:#5dafff}.sac-live-line[data-state=scouting]:before{background:#34d399}.sac-live-line[data-state=recent]:before{background:#d2a35c}
.sac-live-line span{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-width:0}
.sac-card-mini{display:flex;flex-direction:column;gap:2px;min-width:0;min-height:0;flex:1}
.sac-card-small{font-size:.69rem;line-height:1.2;color:var(--muted);min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sac-card-small strong{color:var(--text);font-weight:800}
.sac-card-note-button{display:block;align-self:flex-start;max-width:100%;margin-top:auto!important;padding:1px 0!important;background:transparent!important;border:0!important;color:var(--accent)!important;text-align:left;text-decoration:underline;text-underline-offset:3px;font-size:.68rem!important;font-weight:850!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sac-card-note-button:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.sac-coverage-cell.is-empty{border-style:dashed;justify-content:flex-start}
.sac-card-empty-icon{display:grid;place-items:center;width:32px;height:32px;flex:0 0 32px;border:1px dashed var(--line);border-radius:50%;font-size:.85rem;color:var(--muted);margin-top:8px}
.sac-cover-empty{font-size:.8rem;color:var(--muted);line-height:1.2;font-weight:700}
.sac-card-empty-meta{font-size:.70rem;color:var(--muted);line-height:1.28;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.sac-observation-dialog{border:1px solid var(--line);background:var(--panel);color:var(--text);border-radius:14px;padding:20px;width:min(460px,calc(100vw - 30px));max-height:80vh;overflow:auto;box-shadow:0 25px 90px rgba(0,0,0,.4)}
.sac-observation-dialog::backdrop{background:rgba(0,0,0,.65)}
.sac-observation-dialog h3{margin:0 0 12px}.sac-observation-dialog p{line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere}
.sac-observation-dialog button{border-radius:8px;margin-top:14px}
/* At desktop widths, show all three stations across each alliance instead of six very wide rows. */
@media(min-width:1020px){
 .sac-coverage-three{grid-template-columns:repeat(3,minmax(0,1fr));grid-auto-rows:204px;gap:8px}
 .sac-coverage-cell{height:204px;padding:9px}
 .sac-card-person{flex-direction:column;align-items:flex-start;gap:4px}
 .sac-card-person .sac-avatar{width:50px!important;height:50px!important;flex:0 0 50px!important}
}
@media(max-width:700px){
 .sac-coverage-row{padding:9px}
 .sac-coverage-alliances{gap:8px}
 .sac-coverage-side{padding:7px 6px;gap:7px}
 .sac-coverage-title{font-size:.79rem}
 .sac-coverage-three{grid-template-columns:1fr!important;grid-auto-rows:192px;gap:7px}
 .sac-coverage-cell{height:192px;padding:7px 7px;gap:4px}
 .sac-card-person{flex-direction:column;align-items:flex-start;gap:4px}
 .sac-card-person .sac-avatar{width:49px!important;height:49px!important;flex:0 0 49px!important}
 .sac-card-top .sac-badge{font-size:.56rem;padding:2px 3px}
 .sac-badge-long{display:none}.sac-badge-short{display:inline}
 .sac-coverage-name{font-size:.79rem}
 .sac-live-line{font-size:.69rem}
 .sac-card-small{font-size:.66rem}
 .sac-card-empty-icon{margin-top:12px}
 .sac-row-selector{gap:7px;min-height:39px}
 .sac-check-visual{width:26px;height:26px;flex-basis:26px}
}
@media(max-width:360px){
 .sac-coverage-row{padding:6px}
 .sac-coverage-alliances{gap:6px}
 .sac-coverage-side{padding:6px 4px}
 .sac-coverage-cell{padding:6px 5px}
 .sac-card-person .sac-avatar{width:46px!important;height:46px!important;flex-basis:46px!important}
 .sac-coverage-name{font-size:.75rem}
 .sac-live-line{font-size:.66rem}
 .sac-card-small{font-size:.63rem}
 .sac-selected-indicator{font-size:.58rem}
}
@media(prefers-reduced-motion:reduce){.sac-check-visual,.sac-check-visual::after,.sac-coverage-row{transition:none}}

/* v9: compact intrinsic station cards and accessible modal interaction */
/* Stable selectable pit-team grid: contain names inside cards and avoid overlapping labels. */
.sac-team-picks{display:grid!important;grid-template-columns:repeat(auto-fill,minmax(min(100%,210px),1fr));gap:10px;max-height:350px;overflow:auto;align-items:stretch}
.sac-team-picks .sac-choice{display:flex;align-items:flex-start;min-width:0;max-width:100%;overflow-wrap:anywhere;white-space:normal;line-height:1.35;padding:12px;gap:10px}
.sac-team-picks .sac-choice input{flex:0 0 auto;width:18px;height:18px;margin-top:1px}
.sac-requests .sac-roster-item{overflow-wrap:anywhere}

.sac-coverage-three{grid-auto-rows:minmax(110px,auto)!important;align-items:stretch}
.sac-coverage-cell{height:100%!important;min-height:110px!important;max-height:160px;cursor:pointer;transition:border-color .15s,background .15s;position:relative}
.sac-coverage-cell:hover,.sac-coverage-cell:focus-visible,.sac-pit-clickable:hover,.sac-pit-clickable:focus-visible{border-color:var(--accent,#52c7ea)!important;outline:2px solid transparent;background:color-mix(in srgb,var(--panel2) 85%,var(--accent) 15%)}
.sac-coverage-name{font-size:.89rem!important}.sac-card-person .sac-avatar{height:46px!important;width:46px!important;flex-basis:46px!important}
.sac-card-mini{flex:0 0 auto}.sac-card-small{font-size:.74rem}.sac-card-empty-icon{margin:2px 0!important}.sac-card-empty-meta{font-size:.74rem!important}
.sac-pit-clickable{cursor:pointer;min-height:112px;display:flex;flex-direction:column;justify-content:space-between;gap:6px}
.sac-pick-dialog{width:min(470px,calc(100vw - 24px));max-height:90vh;padding:0;background:var(--panel,#112235);border:1px solid var(--line,#345);border-radius:16px;color:var(--text,#fff);box-shadow:0 22px 80px #0008}
.sac-pick-dialog::backdrop{background:#030b17ba;backdrop-filter:blur(3px)}
.sac-dialog-head,.sac-dialog-foot{padding:16px 18px;display:flex;justify-content:space-between;align-items:center;gap:12px}.sac-dialog-head{border-bottom:1px solid var(--line)}.sac-dialog-head h2{margin:3px 0}.sac-dialog-head small{color:var(--accent);font-weight:800}.sac-dialog-head p{margin:2px 0 0;font-size:.9rem}.sac-dialog-close{background:transparent!important;border:0!important;color:var(--text)!important;font-size:1.4rem!important;padding:4px 10px!important}.sac-dialog-body{padding:18px;display:grid;gap:15px}.sac-dialog-body label{font-size:.9rem;font-weight:800}.sac-dialog-body select{display:block;width:100%;margin-top:7px}.sac-dialog-foot{border-top:1px solid var(--line);flex-wrap:wrap}.sac-danger-button{background:#ba2736!important;color:#fff!important;border-color:#ba2736!important}
@media(max-width:650px){.sac-coverage-three{grid-auto-rows:minmax(116px,auto)!important}.sac-coverage-cell{min-height:116px!important;max-height:170px;padding:8px!important}.sac-card-person{gap:5px!important}.sac-card-person .sac-avatar{width:38px!important;height:38px!important;flex-basis:38px!important}.sac-coverage-name{font-size:.78rem!important}}

/* Mission Control visual system — shared palette and responsive typography. */
.sac-mission-page{--sac-accent:var(--accent,#47c6e5)}
.sac-mission-header{position:relative;isolation:isolate;overflow:hidden;display:flex;align-items:center;min-height:210px;margin-bottom:clamp(16px,2.5vw,28px);padding:clamp(24px,4vw,46px);border:1px solid color-mix(in srgb,var(--sac-accent) 32%,var(--line));border-radius:20px;background:radial-gradient(ellipse at 85% 18%,color-mix(in srgb,var(--sac-accent) 12%,transparent),transparent 58%),linear-gradient(125deg,color-mix(in srgb,var(--card,var(--panel)) 92%,transparent),color-mix(in srgb,var(--panel2,var(--panel)) 92%,transparent));box-shadow:0 16px 46px rgba(0,0,0,.13)}
.sac-mission-header::after{content:'';pointer-events:none;position:absolute;inset:0;z-index:0;opacity:.25;background-image:radial-gradient(circle,color-mix(in srgb,var(--sac-accent) 70%,white) 1px,transparent 2px);background-size:52px 56px;mask-image:linear-gradient(90deg,transparent 40%,#000)}
.sac-mission-header .nd-mission-content{position:relative;z-index:1;max-width:800px;min-width:0}
.sac-mission-header .nd-mission-kicker{display:block;margin-bottom:12px;letter-spacing:.17em;font-weight:850;font-size:.76rem;color:var(--sac-accent)}
.sac-mission-header h1{font-size:clamp(2.2rem,4.4vw,4.15rem)!important;line-height:1.08!important;letter-spacing:-.048em;margin:0 0 13px!important;color:var(--text)!important}
.sac-mission-header p{max-width:58ch;font-size:clamp(.98rem,1.3vw,1.1rem);line-height:1.6;color:var(--muted);margin:0!important}
.sac-mission-header p strong{color:var(--text)}
.sac-mission-mark{position:absolute;right:clamp(25px,5vw,65px);top:50%;transform:translateY(-50%);font-size:clamp(5rem,12vw,11rem);opacity:.10;color:var(--sac-accent);pointer-events:none}
.sac-mission-back{display:inline-flex;align-items:center;gap:9px;margin-top:20px;border:1px solid var(--line);border-radius:10px;padding:9px 13px;color:var(--text);background:color-mix(in srgb,var(--panel) 70%,transparent);font-weight:800;text-decoration:none;font-size:.85rem}
.sac-mission-back:hover,.sac-mission-back:focus-visible{border-color:var(--sac-accent);color:var(--sac-accent)}
.sac-mission-page>.sac-card{border-radius:16px;box-shadow:0 6px 20px rgba(0,0,0,.055)}
.sac-mission-page .sac-stat{background:color-mix(in srgb,var(--sac-accent) 4%,var(--panel2,var(--panel)))}
.sac-mission-page .sac-tab[aria-current=page]{box-shadow:inset 0 -2px 0 var(--sac-accent)}
/* Station cards: no artificially tall photo column; useful robot and mode context. */
.sac-coverage-three{grid-auto-rows:minmax(142px,auto)!important;align-items:stretch}
.sac-coverage-cell{height:100%!important;min-height:142px!important;max-height:none!important;gap:7px!important;padding:10px!important;justify-content:flex-start!important}
.sac-card-person{flex-direction:row!important;align-items:center!important;gap:9px!important;min-height:39px}
.sac-card-person .sac-avatar{width:39px!important;height:39px!important;flex:0 0 39px!important}
.sac-coverage-name{font-size:.82rem!important;line-height:1.22!important;overflow-wrap:anywhere;white-space:normal!important}
.sac-card-context{display:flex;align-items:center;gap:6px;flex-wrap:wrap;min-width:0}
.sac-card-context span{display:inline-flex;align-items:center;gap:4px;font-size:.67rem;font-weight:800;border-radius:6px;padding:3px 6px;border:1px solid var(--line);line-height:1.15;color:var(--muted);max-width:100%}
.sac-card-context .sac-context-robot{color:var(--text);background:color-mix(in srgb,var(--sac-accent) 7%,var(--panel2))}
.sac-card-context .sac-context-mode{border-color:color-mix(in srgb,var(--sac-accent) 26%,var(--line))}
.sac-live-line{font-size:.69rem!important;line-height:1.24!important;overflow-wrap:anywhere}
.sac-live-line span{display:block;overflow-wrap:anywhere}
.sac-card-mini{display:flex;flex-wrap:wrap;flex-direction:row;align-items:center;gap:5px 11px;flex:0 0 auto;margin-top:auto;padding-top:5px;border-top:1px solid color-mix(in srgb,var(--line) 55%,transparent)}
.sac-card-small{font-size:.68rem!important;max-width:100%}
.sac-card-note-button{margin-top:0!important;font-size:.67rem!important}
.sac-coverage-cell.is-empty{justify-content:center!important}
.sac-coverage-side.red .sac-coverage-cell.is-assigned{border-left:3px solid #d95568}
.sac-coverage-side.blue .sac-coverage-cell.is-assigned{border-left:3px solid #5399ee}
@media(min-width:1020px){.sac-coverage-three{grid-template-columns:repeat(3,minmax(0,1fr));grid-auto-rows:minmax(142px,auto)!important}}
@media(max-width:700px){.sac-mission-header{min-height:0;padding:24px 20px;border-radius:16px}.sac-mission-header h1{font-size:clamp(2rem,9vw,3rem)!important}.sac-mission-mark{right:-16px;opacity:.05}.sac-coverage-three{grid-auto-rows:minmax(146px,auto)!important}.sac-coverage-cell{min-height:146px!important}.sac-card-person .sac-avatar{width:34px!important;height:34px!important;flex-basis:34px!important}.sac-card-context span{font-size:.61rem}.sac-coverage-name{font-size:.75rem!important}}
@media(prefers-reduced-motion:reduce){.sac-coverage-cell{transition:none}}
</style>
<section class="module-page sac-mission-page">
<header class="module-page-header nd-mission-header sac-mission-header" style="--nd-accent:var(--accent,#47c6e5)">
  <div class="nd-mission-content sac-page-hero-main">
    <span class="nd-mission-kicker">SATURN / EVENT OPERATIONS</span>
    <h1>Assignment Center</h1>
    <p><strong>Get every robot covered.</strong> Coordinate match positions, pit scouting, and individual requests across the event.</p>
    <a class="sac-mission-back" href="<?=e(base_url('admin/index.php'))?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Command Center</a>
  </div>
  <i class="fa-solid fa-users-viewfinder sac-mission-mark" aria-hidden="true"></i>
</header>
<?php if($error):?><div class="sac-error" role="alert"><?=e($error)?></div><?php endif;?>
<?php if($msg):?><div class="sac-ok" role="status"><?=e($msg)?></div><?php endif;?>
<div class="sac-card"><form method="get"><label for="sac-event"><b>Event</b></label><select id="sac-event" name="event_id" onchange="this.form.submit()" style="max-width:600px;margin-top:7px"><?=neptune_event_options_html($events,$eventId)?></select><button type="submit" class="secondary">Load event</button></form></div>
<?php if(!$event):?><div class="sac-card"><p>No active event is available for this organization. Create an event in Event Setup.</p></div>
<?php else:?>
<?php $assignedCount=count(array_filter($assignments,fn($a)=>$a['status']!=='cancelled'));$ackCount=count(array_filter($assignments,fn($a)=>$a['status']==='acknowledged'));$doneCount=count(array_filter($assignments,fn($a)=>$a['status']==='completed')); ?>
<div class="sac-card sac-summary"><div class="sac-stat"><strong><?=count($matches)?></strong><small>Matches</small></div><div class="sac-stat"><strong><?=$assignedCount?></strong><small>Active assignments</small></div><div class="sac-stat"><strong><?=$ackCount?></strong><small>Acknowledged</small></div><div class="sac-stat"><strong><?=$doneCount?></strong><small>Completed</small></div><div class="sac-stat"><strong><?=count($requests)?></strong><small>Requests</small></div></div>
<nav class="sac-tabs" aria-label="Assignment sections">
<?php foreach(['matches'=>'Match Positions','pit'=>'Pit Scouting','requests'=>'Requests'] as $key=>$label):?><a class="sac-tab" href="?event_id=<?=$eventId?>&tab=<?=e($key)?>" <?=$tab===$key?'aria-current="page"':''?>><?=e($label)?></a><?php endforeach;?>
</nav>
<?php if($tab==='matches'):?>
<div class="sac-card sac-bulk-primary">
 <div class="sac-bulk-header"><div><h2><i class="fa-solid fa-users-rectangle"></i> Bulk match assignments</h2><p class="sac-muted">Select multiple matches below, choose a position and scouter, then save once. Existing assignments in that position are replaced for selected matches.</p></div><span class="sac-badge">Primary workflow</span></div>
 <form method="post" id="sac-bulk-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="tab" value="matches"><input type="hidden" name="op" value="bulk_match">
 <div class="sac-grid-form"><label>Alliance position<select name="alliance" id="sac-alliance"><option value="Red">Red</option><option value="Blue">Blue</option></select></label><label>Station<select name="station"><option value="1">1</option><option value="2">2</option><option value="3">3</option></select></label><label>Assign scouter<select name="user_id" required><?=$userOptions()?></select></label><label>Scouting mode<select name="scouting_mode"><option value="either">Either / shared</option><option value="traditional">Traditional</option><option value="tag">TAG</option></select></label></div>
 </form>
 <div class="sac-bulk-actions"><span aria-live="polite" aria-atomic="true"><strong id="sac-selected-count">0 selected</strong> of <?=count($matches)?> matches</span><button type="button" class="secondary" id="sac-select-all">Select all</button><button type="button" class="secondary" id="sac-clear-all">Clear</button><button type="submit" form="sac-bulk-form" class="sac-primary-save"><i class="fa-solid fa-floppy-disk"></i> Assign selected matches</button></div>
 </div>
 <div class="sac-card sac-coverage"><div class="sac-bulk-header"><div><h2>Match coverage</h2><p class="sac-muted">Select any matches for bulk assignment. Red and Blue stay side by side; each scouter card shows their status, last login, and recorded scouting activity.</p></div><span class="sac-badge"><?=count($matches)?> matches</span></div>
 <div class="sac-coverage-footer"><span><b>Online · TAG page</b> means an authenticated, visible Neptune TAG page checked in recently. <b>Scouting this match</b> requires a current traditional match heartbeat; saved TAG notes appear separately.</span><span>Last sign-in is historical, not proof of being online now.</span></div>
 <?php if(!$matches):?><p class="sac-muted">No matches loaded for this event yet.</p><?php else:?>
 <div class="sac-match-picks sac-coverage-list" id="sac-match-picks">
 <?php foreach($matches as $m):$mid=(int)$m['id'];$filled=0;foreach(['Red','Blue'] as $side){for($st=1;$st<=3;$st++){ $item=$assignBySlot[$mid][$side][$st]??null;if($item && $item['status']!=='cancelled')$filled++;}}?>
 <article class="sac-coverage-row"><label class="sac-row-selector"><input class="sac-match-check" type="checkbox" form="sac-bulk-form" name="match_ids[]" value="<?=$mid?>"><span class="sac-check-visual" aria-hidden="true"></span><strong><?=e(sac_match_label($m))?></strong><small><?=$filled?> of 6 assigned</small><span class="sac-selected-indicator" aria-hidden="true">Selected</span><?php if(!empty($matchConflicts[$mid])):?><span class="sac-badge" style="border-color:#d7a245">Double-booked scouter</span><?php endif;?></label>
 <div class="sac-coverage-alliances">
 <?php foreach(['Red','Blue'] as $side):?><section class="sac-coverage-side <?=strtolower($side)?>" aria-label="<?=e($side)?> alliance scouting positions"><div class="sac-coverage-title"><?=e($side)?> Alliance</div><div class="sac-coverage-three">
 <?php for($station=1;$station<=3;$station++):
    $a=$assignBySlot[$mid][$side][$station]??null;
    $present=$a && ($a['status']??'')!=='cancelled';
    $key=$present ? $mid.':'.(int)($a['assignee_user_id']??0).':'.$side.':'.$station : '';
    $activity=$present?($scoutPresence[$key]??null):null;
    $tag=$present?($tagNotes[$key]??null):null;
    $lastAction=$activity?($actionLatest[(int)($activity['last_action_id']??0)]??null):null;
    $live=$activity && (int)($activity['recently_connected']??0)===1;
    $onlineNow=$present && isset($onlineUsers[(int)($a['assignee_user_id']??0)]);
    $recentTag=$tag && !empty($tag['updated_at']) && (int)($tag['updated_epoch']??0)>=time()-180;
    $loginAt=$present?trim((string)($a['assignee_last_login']??'')):'';
    $tagAt=$tag?trim((string)($tag['updated_at']??'')):'';
    $tagEpoch=$tag?(int)($tag['updated_epoch']??0):0;
    $tagCount=$tag?(int)($tag['report_count']??0):0;
    $uid=$present?(int)($a['assignee_user_id']??0):0;
    $pageKind=$onlinePages[$uid]??'other';
    $lastActionText=$lastAction?trim((string)($lastAction['action_name']??'').' · '.(string)($lastAction['result']??'')):'';
    $note=$tag?trim((string)($tag['note']??'')):'';
    $actionCount=(int)($activity['action_count']??0);
    $teamNum=(int)($matchTeams[$mid][$side][$station]??0);
    $statusLabel=$live?'Scouting this match':($onlineNow && $recentTag && $pageKind==='tag-match'?'TAG updated · online':($onlineNow?(str_starts_with($pageKind,'tag-')?'Online · '.(['tag-match'=>'TAG match page','tag-pit'=>'TAG pit page','tag-team'=>'TAG team page'][$pageKind]??'TAG page'):($pageKind==='traditional'?'Online · Scout page':'Online · Neptune')):($recentTag?'TAG updated recently':'No live check-in')));
    $statusState=$live||($onlineNow && $recentTag && $pageKind==='tag-match')?'scouting':($onlineNow?'online':($recentTag?'recent':'idle'));
 ?>
 <div tabindex="0" role="button" data-sac-slot-action="<?=$present?'remove':'assign'?>" data-sac-mid="<?=$mid?>" data-sac-alliance="<?=e($side)?>" data-sac-station="<?=$station?>" data-sac-robot="<?=$teamNum?>" data-sac-assignment="<?=$present?(int)$a['id']:0?>" data-sac-scouter="<?=e($present?(string)($a['assignee_name']??'Assigned'):'')?>" class="sac-coverage-cell <?=$present?'is-assigned':'is-empty'?>" aria-label="<?=e($side.' '.$station.': '.($present?($a['assignee_name']??'Assigned'):'Unassigned'))?>">
  <div class="sac-card-top"><span class="sac-position-key"><?=e(strtoupper(substr($side,0,1)).$station)?></span>
  <?php if($present):?><span class="sac-badge <?=e((string)($a['status']??''))?>"><span class="sac-badge-long"><?=e(ucfirst((string)($a['status']??'assigned')))?></span><span class="sac-badge-short"><?=e(match((string)($a['status']??'assigned')){'acknowledged'=>'Acked','completed'=>'Done',default=>ucfirst((string)($a['status']??'assigned'))})?></span></span><?php endif;?></div>
  <?php if($present):?>
   <div class="sac-card-person"><?=$avatar($a,52)?><strong class="sac-coverage-name" title="<?=e((string)($a['assignee_name']??'Assigned'))?>"><?=e((string)($a['assignee_name']??'Assigned'))?></strong></div>
   <div class="sac-card-context" aria-label="Assigned robot and scouting mode"><span class="sac-context-robot"><i class="fa-solid fa-robot" aria-hidden="true"></i> <?=$teamNum>0?'#'.$teamNum:'Robot TBD'?></span><span class="sac-context-mode"><?=e(match((string)($a['scouting_mode']??'either')){'tag'=>'TAG','traditional'=>'Traditional',default=>'Either mode'})?></span></div>
   <div class="sac-live-line" data-sac-user="<?=$uid?>" data-sac-scouter-key="<?=e($key)?>" data-state="<?=e($statusState)?>" title="<?=e($live?'Traditional scouting heartbeat for this assigned match and position':($onlineNow?'Recently checked in from a visible Neptune page':($recentTag?'TAG observation updated recently':'No current heartbeat recorded')))?>"><span data-sac-status-label><?=e($statusLabel)?></span></div>
   <div class="sac-card-mini">
    <div class="sac-card-small">Sign-in: <?php if($loginAt!==''):?><strong><time class="sac-relative-time" datetime="<?=e(gmdate('Y-m-d\TH:i:s\Z',strtotime($loginAt.' UTC')))?>" title="<?=e($loginAt)?> UTC"><?=e(gmdate('M j',strtotime($loginAt.' UTC')))?> UTC</time></strong><?php else:?>not recorded<?php endif;?></div>
    <div class="sac-card-small" title="<?=e($actionCount.' traditional actions'.($tagAt!==''?' · TAG updated '.gmdate('Y-m-d H:i:s',$tagEpoch).' UTC':''))?>"><strong><?=$actionCount?></strong> traditional actions<?php if($tagAt!==''):?> · <strong><?=$tagCount?></strong> TAG reports<?php endif;?><?php if($tagAt!==''):?> · TAG <time class="sac-relative-time" datetime="<?=e(gmdate('Y-m-d\TH:i:s\Z',$tagEpoch))?>" title="<?=e($tagAt)?> UTC"><?=e(gmdate('M j',$tagEpoch))?> UTC</time><?php endif;?></div>
    <?php if($lastActionText!==''||$note!==''):?><button type="button" class="sac-card-note-button" data-sac-note="<?=e($note)?>" data-sac-action="<?=e($lastActionText)?>" data-sac-name="<?=e((string)($a['assignee_name']??'Assigned'))?>" data-sac-position="<?=e(sac_match_label($m).' · '.strtoupper(substr($side,0,1)).$station)?>"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i> View details</button><?php endif;?>
   </div>
  <?php else:?>
   <span class="sac-card-empty-icon" aria-hidden="true"><i class="fa-solid fa-user-plus"></i></span>
   <span class="sac-cover-empty">Unassigned</span>
   <span class="sac-card-empty-meta"><?=$teamNum>0?'Robot #'.$teamNum.' · ':''?>Needs scouter</span>
  <?php endif;?>
 </div>
 <?php endfor;?></div></section><?php endforeach;?></div>
 </article><?php endforeach;?>
 </div><?php endif;?>
 <dialog class="sac-observation-dialog" id="sacRecordedDetails" aria-labelledby="sacRecordedDetailsTitle">
  <h3 id="sacRecordedDetailsTitle">Recorded scouting details</h3>
  <div id="sacRecordedDetailsBody"></div>
  <form method="dialog"><button type="submit">Close</button></form>
 </dialog>
 </div>
<details class="sac-card sac-individual"><summary><strong>Edit one match individually</strong> <small class="sac-muted">Optional · for quick corrections</small></summary><div class="sac-bulk-inner"><div class="sac-card">
  <div class="sac-board-top">
    <div class="sac-board-headline"><h2>Edit a single match</h2><span class="sac-badge">Individual match editor</span></div>
    <?php if($viewMatch):?>
    <div class="sac-board-nav">
      <a href="<?=e($boardLink((int)($matches[max(0,$viewIndex-1)]['id']??$viewMatchId)))?>" <?=$viewIndex===0?'aria-disabled="true" tabindex="-1"':''?> aria-label="Previous match">&larr; Previous</a>
      <label class="sac-small-label">Match
        <select id="sac-board-pick" aria-label="Jump to match">
          <?php foreach($matches as $m):?><option value="<?=e($boardLink((int)$m['id']))?>" <?=(int)$m['id']===$viewMatchId?'selected':''?>><?=e(sac_match_label($m))?></option><?php endforeach;?>
        </select>
      </label>
      <a href="<?=e($boardLink((int)($matches[min(count($matches)-1,$viewIndex+1)]['id']??$viewMatchId)))?>" <?=$viewIndex===count($matches)-1?'aria-disabled="true" tabindex="-1"':''?> aria-label="Next match">Next &rarr;</a>
    </div>
    <?php endif;?>
  </div>
  <p class="sac-layout-note">See each alliance position and its assigned scouter. Choose <b>Assign / Change</b> under a position when you need to edit it.</p>
  <?php if(!$viewMatch):?><p>No matches are loaded for this event. Import or create the match schedule first.</p><?php else:?>
  <?php $mid=$viewMatchId;$m=$viewMatch;
   $filled=0;foreach(['Red','Blue'] as $side){for($st=1;$st<=3;$st++){ $cell=$assignBySlot[$mid][$side][$st]??null;if($cell && $cell['status']!=='cancelled')$filled++;}}
  ?>
  <div class="sac-match-head"><b><?=e(sac_match_label($m))?> <small class="sac-muted">· Field <?=(int)$m['field_id']?></small></b><div><span class="sac-badge"><?=$filled?>/6 covered</span> <?php if(!empty($matchConflicts[$mid])):?><span class="sac-badge" style="border-color:#d7a245">Double booking</span><?php endif;?></div></div>
  <div class="sac-match sac-board-single"><div class="sac-match-grid">
  <?php foreach(['Red','Blue'] as $alliance):?><div class="sac-alliance <?=strtolower($alliance)?>"><div class="sac-alliance-title"><?=e(strtoupper($alliance))?> ALLIANCE</div>
  <?php for($station=1;$station<=3;$station++):$a=$assignBySlot[$mid][$alliance][$station]??null;$team=$matchTeams[$mid][$alliance][$station]??null;$hasScouter=$a && $a['status']!=='cancelled';?>
  <div class="sac-slot"><div class="sac-slot-main"><span class="sac-slot-pos"><?=e(strtoupper(substr($alliance,0,1)))?><?=$station?></span>
   <?php if($hasScouter):?><?=$avatar($a,52)?><?php else:?><span class="sac-avatar sac-initials" style="height:52px;width:52px">+</span><?php endif;?>
   <div class="sac-person-meta"><strong><?=e($hasScouter?($a['assignee_name']??'Deleted user'):'Unassigned')?></strong>
     <small><?=$team?'Robot #'.(int)$team.' · ':''?><?=$hasScouter?e(ucfirst((string)($a['scouting_mode']??'either'))).' scouting':'Choose a scouter below'?></small></div></div>
   <div class="sac-slot-footer"><?php if($hasScouter):?><span class="sac-badge <?=e($a['status'])?>"><?=e($a['status'])?></span><?php else:?><span class="sac-badge">Needs coverage</span><?php endif;?></div>
   <details class="sac-edit"><summary><?=$hasScouter?'Change scouter':'Assign scouter'?></summary>
   <form method="post" class="sac-position-controls"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="view_match" value="<?=$mid?>"><input type="hidden" name="tab" value="matches"><input type="hidden" name="op" value="one_match"><input type="hidden" name="match_id" value="<?=$mid?>"><input type="hidden" name="alliance" value="<?=e($alliance)?>"><input type="hidden" name="station" value="<?=$station?>">
     <label class="sac-small-label">Roboticist<select name="user_id" required><?=$userOptions((int)($a['assignee_user_id']??0))?></select></label>
     <label class="sac-small-label">Scouting method<select name="scouting_mode"><option value="either" <?=($a['scouting_mode']??'either')==='either'?'selected':''?>>Either</option><option value="traditional" <?=($a['scouting_mode']??'')==='traditional'?'selected':''?>>Traditional</option><option value="tag" <?=($a['scouting_mode']??'')==='tag'?'selected':''?>>TAG</option></select></label>
     <button type="submit">Save</button></form></details>
   <?php if($hasScouter):?><details class="sac-progress"><summary>Update assignment status</summary><?=$statusAction($a,'matches')?></details><?php endif;?>
  </div>
  <?php endfor;?></div><?php endforeach;?>
  </div></div>
  <?php endif;?>
</div>
</div></details><?php elseif($tab==='pit'):?>
<div class="sac-card"><h2>Assign pit scouting</h2><p class="sac-muted">Select teams on this event's roster, then assign one scouter to those teams.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="tab" value="pit"><input type="hidden" name="op" value="bulk_pit">
<label style="display:block;max-width:400px">Roboticist<select name="user_id" required><?=$userOptions()?></select></label>
<div class="sac-team-picks"><?php foreach($roster as $r):$t=(int)$r['frc_team_number'];?><label class="sac-choice"><input type="checkbox" name="teams[]" value="<?=$t?>">#<?=$t?> <?=e($r['nickname']??'')?></label><?php endforeach;?></div><button type="submit">Save pit assignments</button></form></div>
<div class="sac-card"><h2>Pit Assignment Roster</h2><div class="sac-roster">
<?php foreach($roster as $r):$team=(int)$r['frc_team_number'];$a=$pitByTeam[$team]??null;?><div class="sac-roster-item sac-pit-clickable" role="button" tabindex="0" data-sac-pit-action="<?=($a && $a['status']!=='cancelled')?'remove':'assign'?>" data-sac-team="<?=$team?>" data-sac-team-name="<?=e((string)($r['nickname']??''))?>" data-sac-assignment="<?=($a && $a['status']!=='cancelled')?(int)$a['id']:0?>"><b>#<?=$team?> <?=e($r['nickname']??'')?></b><div class="sac-person" style="margin:10px 0"><?php if($a && $a['status']!=='cancelled'):?><?=$avatar($a)?><div class="sac-person-meta"><strong><?=e($a['assignee_name']??'Deleted user')?></strong><small><span class="sac-badge <?=e($a['status'])?>"><?=e($a['status'])?></span></small></div><?php else:?><span class="sac-muted">Not assigned</span><?php endif;?></div>
<small class="sac-muted">Tap to <?=($a && $a['status']!=='cancelled')?'remove assignment':'assign roboticist'?></small></div><?php endforeach;?>
</div><?php if(!$roster):?><p>No teams on this event roster.</p><?php endif;?></div>
<?php elseif($tab==='requests'):?>
<div class="sac-card"><h2>New scouting request</h2><p class="sac-muted">Assign a question, picture, or fact check. Requests remain in Neptune with separate acknowledgment and completion states.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="tab" value="requests"><input type="hidden" name="op" value="new_request">
<div class="sac-grid-form" style="grid-template-columns:repeat(3,minmax(0,1fr))"><label>Roboticist<select name="user_id" required><?=$userOptions()?></select></label><label>Team (optional)<select name="frc_team_number"><option value="">Any / event-wide</option><?php foreach($roster as $r):?><option value="<?=(int)$r['frc_team_number']?>">#<?=(int)$r['frc_team_number']?> <?=e($r['nickname']??'')?></option><?php endforeach;?></select></label><label>Request type<select name="request_kind"><option value="question">Ask a question</option><option value="photo">Take a picture</option><option value="verify">Verify information</option><option value="other">Other</option></select></label></div>
<label style="display:block;margin:12px 0">Instructions<textarea name="instructions" rows="3" maxlength="2000" placeholder="What question needs answering or what photograph is needed?" required></textarea></label><button type="submit">Save request</button></form></div>
<div class="sac-card"><h2>Request Queue <small class="sac-muted">All organization events</small></h2><p class="sac-muted">Assigned and acknowledged requests stay visible until completed, even when another event is selected.</p><div class="sac-requests">
<?php foreach($requests as $a):?><div class="sac-roster-item"><div class="sac-person"><?=$avatar($a)?><div class="sac-person-meta"><strong><?=e($a['assignee_name']??'Deleted user')?> · <?=e(ucfirst($a['request_kind']??'request'))?></strong><small><?=e((string)($a['request_event_name']??'Event'))?> · <?=!empty($a['frc_team_number'])?'Team #'.(int)$a['frc_team_number'].' · ':''?><span class="sac-badge <?=e($a['status'])?>"><?=e($a['status'])?></span></small></div></div><p><?=nl2br(e($a['instructions']??''))?></p><?php if($a['response_text']):?><p><b>Response:</b> <?=nl2br(e($a['response_text']))?></p><?php endif;?>
<?php $es=$pdo->prepare('SELECT id FROM scouting_assignment_evidence WHERE organization_id=? AND assignment_id=? ORDER BY id DESC LIMIT 8');$es->execute([$org,(int)$a['id']]);foreach($es->fetchAll(PDO::FETCH_COLUMN) as $eid):?><a href="?evidence=<?=(int)$eid?>" target="_blank" rel="noopener"><img src="?evidence=<?=(int)$eid?>" loading="lazy" alt="Request photo evidence" style="width:100px;height:100px;object-fit:cover;border-radius:10px;margin:4px"></a><?php endforeach;?>
<?=$statusAction($a,'requests')?></div><?php endforeach;?>
<?php if(!$requests):?><p class="sac-muted">No requests have been created yet.</p><?php endif;?></div></div>
<?php endif;?>
<dialog class="sac-pick-dialog" id="sacAssignDialog" aria-labelledby="sacAssignTitle">
 <form method="post" id="sacDialogAssignForm">
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="tab" id="sacDialogTab" value="matches"><input type="hidden" name="op" id="sacDialogOp" value="one_match"><input type="hidden" name="match_id" id="sacDialogMatch"><input type="hidden" name="alliance" id="sacDialogAlliance"><input type="hidden" name="station" id="sacDialogStation"><input type="hidden" name="teams[]" id="sacDialogTeam">
  <div class="sac-dialog-head"><div><small>Assignment Center</small><h2 id="sacAssignTitle">Assign position</h2><p id="sacAssignContext" class="sac-muted"></p></div><button type="button" class="sac-dialog-close" data-sac-close aria-label="Close">×</button></div>
  <div class="sac-dialog-body"><label>Roboticist<select name="user_id" required><?=$userOptions()?></select></label><label id="sacDialogModeLabel">Scouting method<select name="scouting_mode"><option value="either">Traditional or TAG</option><option value="traditional">Traditional</option><option value="tag">TAG</option></select></label></div>
  <div class="sac-dialog-foot"><button type="button" class="secondary" data-sac-close>Cancel</button><button type="submit">Save assignment</button></div>
 </form>
</dialog>
<dialog class="sac-pick-dialog" id="sacRemoveDialog" aria-labelledby="sacRemoveTitle">
 <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="tab" id="sacRemoveTab" value="matches"><input type="hidden" name="op" value="status"><input type="hidden" name="assignment_id" id="sacRemoveId"><input type="hidden" name="transition" value="cancel">
 <div class="sac-dialog-head"><div><small>Confirm change</small><h2 id="sacRemoveTitle">Remove assignment?</h2></div><button type="button" class="sac-dialog-close" data-sac-close aria-label="Close">×</button></div>
 <div class="sac-dialog-body"><p id="sacRemoveContext"></p><p class="sac-muted">This cancels the assignment and records the change in history. Existing scouting observations are not deleted.</p></div>
 <div class="sac-dialog-foot"><button type="button" class="secondary" data-sac-close>Keep assignment</button><button type="submit" class="sac-danger-button">Remove assignment</button></div></form>
</dialog>
<div class="sac-card"><h3>Recent assignment changes</h3><p class="sac-muted">Actor and time are recorded for assignments, acknowledgments, completions, cancellations, and reassignments.</p><div class="sac-history"><?php foreach($history as $h):?><div class="sac-history-line"><b><?=e(ucfirst(str_replace('_',' ',$h['action'])))?></b> · Assignment #<?=(int)$h['assignment_id']?> · <?=e($h['actor_name']??'Deleted user')?> · <?=e($h['created_at'])?> UTC <span class="sac-muted">(<?=e($h['previous_status']??'new')?> → <?=e($h['new_status'])?>)</span></div><?php endforeach;?><?php if(!$history):?><p class="sac-muted">No changes recorded.</p><?php endif;?></div></div>
<?php endif;?>
</section>
<script>
(()=>{
 const pick=document.getElementById('sac-board-pick');
 pick?.addEventListener('change',()=>{if(pick.value)window.location.assign(pick.value)});
 const root=document.getElementById('sac-match-picks');
 if(!root)return;
 const count=document.getElementById('sac-selected-count');
 const checks=[...root.querySelectorAll('input.sac-match-check')];
 const update=()=>{if(count)count.textContent=checks.filter(el=>el.checked).length+' selected';};
 const toggle=checked=>{checks.forEach(el=>el.checked=checked);update();};
 // Shift-click selects a continuous match range, useful for long event schedules.
 let lastCheckedIndex=-1;
 root.addEventListener('click',event=>{
   const box=event.target.closest('input.sac-match-check');
   if(!box)return;
   const index=checks.indexOf(box);
   if(event.shiftKey&&lastCheckedIndex!==-1){
     const start=Math.min(index,lastCheckedIndex),end=Math.max(index,lastCheckedIndex);
     for(let i=start;i<=end;i++)checks[i].checked=box.checked;
   }
   lastCheckedIndex=index;
   update();
 });
 root.addEventListener('change',update);
 document.getElementById('sac-select-all')?.addEventListener('click',()=>toggle(true));
 document.getElementById('sac-clear-all')?.addEventListener('click',()=>toggle(false));
 const bulk=document.getElementById('sac-bulk-form');
 bulk?.addEventListener('submit',event=>{
  if(root.querySelector('input.sac-match-check:checked'))return;
  event.preventDefault();
  let message=document.getElementById('sac-selection-error');
  if(!message){message=document.createElement('span');message.id='sac-selection-error';message.setAttribute('role','alert');message.className='sac-error';document.querySelector('.sac-bulk-actions')?.appendChild(message)}
  message.textContent='Choose one or more matches first.';
 });
 function localize(){
  document.querySelectorAll('time[data-neptune-utc]').forEach(el=>{
    const date=new Date(el.dataset.neptuneUtc);
    if(Number.isNaN(date.getTime()))return;
    el.textContent=date.toLocaleString(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
    el.title='Your local time';
  });
 }
 localize();update();

})();
</script>
<script>
(()=>{
 const flags=[...document.querySelectorAll('[data-sac-scouter-key]')];
 const endpoint=<?=json_encode(base_url('admin/assignment-center.php').'?event_id='.$eventId.'&presence_poll=1',JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
 let lastScouting=new Set(),lastTag=new Set();
 function applyStatus(online,pages){
   for(const el of flags){
     const user=Number(el.dataset.sacUser);
     const [state,label]=statusLabel(pages[user]||'other',online.has(user),lastScouting.has(el.dataset.sacScouterKey),lastTag.has(el.dataset.sacScouterKey));
     el.dataset.state=state;
     const output=el.querySelector('[data-sac-status-label]');if(output)output.textContent=label;
   }
 }
 let wsPresence=null;
 function onPresence(msg){
   if(msg.type==='presence-snapshot'){
     wsPresence=msg.users||{};
     applyStatus(new Set(Object.keys(wsPresence).map(Number)),wsPresence);
   } else if(msg.type==='presence-offline'){
     wsPresence=null;
     poll();
   }
 }
 function bindPresence(){if(window.NeptunePresence)window.NeptunePresence.subscribe(onPresence);}
 document.addEventListener('DOMContentLoaded',bindPresence);

 let busy=false;
 function statusLabel(kind,online,scouting,recent){
   if(scouting)return ['scouting','Scouting this match'];
   if(online && recent && kind==='tag-match')return ['scouting','TAG updated · online'];
   if(online){
     const pages={'tag-match':'Online · TAG match page','tag-pit':'Online · TAG pit page','tag-team':'Online · TAG team page',traditional:'Online · Scout page'};
     return ['online',pages[kind]||'Online · Neptune'];
   }
   return recent?['recent','TAG updated recently']:['idle','No live check-in'];
 }
 async function poll(){
   if(document.visibilityState!=='visible'||busy||!flags.length)return;
   busy=true;
   try{
     const response=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
     if(!response.ok)return;
     const data=await response.json();
     lastScouting=new Set(data.scouting||[]);
     lastTag=new Set(data.tag_recent||[]);
     const pages=wsPresence||data.pages||{};
     const online=wsPresence?new Set(Object.keys(wsPresence).map(Number)):new Set((data.online||[]).map(Number));
     applyStatus(online,pages);
   }catch(_ignored){/* Retain last known state on a connectivity failure. */}
   finally{busy=false;}
 }
 poll();setInterval(poll,30000);
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')poll()});
 window.addEventListener('focus',poll);
})();
(()=>{
 const dialog=document.getElementById('sacRecordedDetails');
 const content=document.getElementById('sacRecordedDetailsBody');
 const heading=document.getElementById('sacRecordedDetailsTitle');
 if(!dialog||!content||!heading)return;
 document.addEventListener('click',event=>{
   const button=event.target.closest('.sac-card-note-button');
   if(!button)return;
   heading.textContent=(button.dataset.sacPosition||'Match')+' · '+(button.dataset.sacName||'Scouter');
   content.replaceChildren();
   for(const [label,text] of [['Last traditional action',button.dataset.sacAction],['TAG observation',button.dataset.sacNote]]){
     if(!text)continue;
     const title=document.createElement('strong');title.textContent=label;
     const paragraph=document.createElement('p');paragraph.textContent=text;
     content.append(title,paragraph);
   }
   if(!dialog.open)dialog.showModal();
 });
})();
</script>
<script>
(()=>{
 const nodes=[...document.querySelectorAll('.sac-relative-time')];
 if(!nodes.length)return;
 const render=()=>{
  for(const node of nodes){
   const date=new Date(node.dateTime);
   if(!Number.isFinite(date.getTime()))continue;
   const delta=Math.max(0,Math.floor((Date.now()-date.getTime())/1000));
   const ago=delta<60?'just now':delta<3600?Math.floor(delta/60)+'m ago':delta<86400?Math.floor(delta/3600)+'h ago':delta<604800?Math.floor(delta/86400)+'d ago':date.toLocaleDateString(undefined,{month:'short',day:'numeric'});
   node.textContent=ago;
   node.title=date.toLocaleString();
  }
 };
 render();setInterval(render,60000);
})();
</script>
<script>
(()=>{
 const assign=document.getElementById('sacAssignDialog'),remove=document.getElementById('sacRemoveDialog');
 if(!assign||!remove)return;
 document.querySelectorAll('[data-sac-close]').forEach(el=>el.addEventListener('click',()=>el.closest('dialog')?.close()));
 for(const dlg of [assign,remove])dlg.addEventListener('click',e=>{if(e.target===dlg)dlg.close()});
 function activate(card){
  const pit=!!card.dataset.sacPitAction;
  const tab=pit?'pit':'matches';
  const label=pit?'Pit · Team #'+card.dataset.sacTeam+(card.dataset.sacTeamName?' '+card.dataset.sacTeamName:''):'Match '+(card.closest('.sac-coverage-row')?.querySelector('.sac-row-selector strong')?.textContent||'')+' · '+card.dataset.sacAlliance+' '+card.dataset.sacStation+(Number(card.dataset.sacRobot)>0?' · Robot #'+card.dataset.sacRobot:'');
  if((pit?card.dataset.sacPitAction:card.dataset.sacSlotAction)==='remove'){
   document.getElementById('sacRemoveId').value=card.dataset.sacAssignment;
   document.getElementById('sacRemoveTab').value=tab;
   document.getElementById('sacRemoveContext').textContent='Remove '+(card.dataset.sacScouter||'the assigned roboticist')+' from '+label+'?';
   remove.showModal();return;
  }
  document.getElementById('sacDialogOp').value=pit?'bulk_pit':'one_match';
  document.getElementById('sacDialogTab').value=tab;
  document.getElementById('sacDialogTeam').disabled=!pit;
  document.getElementById('sacDialogTeam').value=pit?card.dataset.sacTeam:'';
  document.getElementById('sacDialogMatch').disabled=pit;
  document.getElementById('sacDialogMatch').value=pit?'':card.dataset.sacMid;
  document.getElementById('sacDialogAlliance').disabled=pit;
  document.getElementById('sacDialogAlliance').value=pit?'':card.dataset.sacAlliance;
  document.getElementById('sacDialogStation').disabled=pit;
  document.getElementById('sacDialogStation').value=pit?'':card.dataset.sacStation;
  document.getElementById('sacDialogModeLabel').hidden=pit;
  document.getElementById('sacAssignTitle').textContent=pit?'Assign pit scouting':'Assign alliance position';
  document.getElementById('sacAssignContext').textContent=label;
  assign.querySelector('[name=user_id]').value='';
  assign.showModal();
 }
 document.addEventListener('click',e=>{
  if(e.target.closest('button,a,select,input,label,textarea,summary,form'))return;
  const card=e.target.closest('[data-sac-slot-action],[data-sac-pit-action]');if(card)activate(card);
 });
 document.addEventListener('keydown',e=>{
  if(e.key!=='Enter'&&e.key!==' ')return;
  const card=e.target.closest('[data-sac-slot-action],[data-sac-pit-action]');if(!card||e.target!==card)return;
  e.preventDefault();activate(card);
 });
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
