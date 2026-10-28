<?php
/** Authenticated, organization-isolated assignment inbox. All timestamps are UTC. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$org=(int)($u['organization_id']??0);
$userId=(int)($u['id']??0);
if($org<1 || $userId<1){http_response_code(403);exit;}
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
try {
    if($_SERVER['REQUEST_METHOD']==='GET' && ($_GET['action']??'')==='assignments'){
        // Read-only data view; only the signed-in user's active work is exposed.
        $q=$pdo->prepare("SELECT a.id,a.kind,a.status,a.event_id,a.match_id,a.frc_team_number,
            a.alliance,a.station,a.scouting_mode,a.request_kind,a.instructions,a.response_text,
            e.name event_name,m.comp_level,m.set_number,m.match_number,
            mt.frc_team_number AS match_team
            FROM scouting_assignments a
            JOIN events e ON e.id=a.event_id AND e.organization_id=a.organization_id
            LEFT JOIN matches m ON m.id=a.match_id AND m.organization_id=a.organization_id AND m.event_id=a.event_id
            LEFT JOIN match_teams mt ON mt.match_id=a.match_id AND mt.alliance=a.alliance AND mt.station=a.station
            WHERE a.organization_id=? AND a.assignee_user_id=?
              AND a.status IN ('assigned','acknowledged')
            ORDER BY CASE a.status WHEN 'assigned' THEN 0 ELSE 1 END,a.assigned_at DESC,a.id DESC LIMIT 400");
        $q->execute([$org,$userId]);
        $rows=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $rows[]=[
                'id'=>(int)$r['id'],'kind'=>(string)$r['kind'],'status'=>(string)$r['status'],
                'event_id'=>(int)$r['event_id'],'match_id'=>(int)$r['match_id'],
                'team'=>(int)($r['kind']==='match'?$r['match_team']:$r['frc_team_number']),
                'alliance'=>(string)($r['alliance']??''),'station'=>(int)($r['station']??0),
                'mode'=>(string)($r['scouting_mode']??'either'),
                'request_kind'=>(string)($r['request_kind']??''),'instructions'=>(string)($r['instructions']??''),
                'event'=>(string)$r['event_name'],
                'match'=>(string)(($r['comp_level']??'')==='qm'?'Q'.(int)$r['match_number']:
                  strtoupper((string)($r['comp_level']??'M')).(int)($r['set_number']??0).'-'.(int)($r['match_number']??0))
            ];
        }
        json_response(['status'=>'success','assignments'=>$rows]);exit;
    }
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $q=$pdo->prepare('SELECT COUNT(*) FROM scouting_assignment_notifications WHERE organization_id=? AND recipient_user_id=? AND read_at IS NULL');
        $q->execute([$org,$userId]);
        $unread=(int)$q->fetchColumn();
        $q=$pdo->prepare('SELECT id,kind,title,body,created_at,read_at FROM scouting_assignment_notifications WHERE organization_id=? AND recipient_user_id=? ORDER BY id DESC LIMIT 30');
        $q->execute([$org,$userId]);
        $items=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $items[]=[
                'id'=>(int)$r['id'],
                'kind'=>(string)$r['kind'],
                'title'=>(string)$r['title'],
                'body'=>(string)$r['body'],
                'created_at'=>(string)$r['created_at'],
                'read_at'=>$r['read_at']===null?null:(string)$r['read_at'],
            ];
        }
        json_response(['status'=>'success','unread'=>$unread,'notifications'=>$items]);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        json_response(['status'=>'error','message'=>'Method not allowed'],405);exit;
    }
    verify_csrf();
    $action=(string)($_POST['action']??'');
    if($action==='reconcile_assignments'){
        // Idempotent and CSRF-protected reconciliation. Only real completed pit
        // records or ended matches containing assignee evidence can close work.
        require_once dirname(__DIR__).'/_assignment_auto_complete.php';
        nac_try_finish($pdo,$org,'pit',null,null,$userId);
        nac_try_finish($pdo,$org,'match',null,null,$userId);
        json_response(['status'=>'success']);exit;
    }
    if($action==='assignment_transition'){
        require_once dirname(__DIR__).'/admin/_assignment_center.php';
        $id=filter_var($_POST['assignment_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $transition=(string)($_POST['transition']??'');
        if(!$id||!in_array($transition,['acknowledge','complete'],true)){
            json_response(['status'=>'error','message'=>'Invalid assignment operation'],422);exit;
        }
        $response=trim((string)($_POST['response_text']??''));
        if(strlen($response)>4000){json_response(['status'=>'error','message'=>'Response too long'],422);exit;}
        $photo=null;
        if(isset($_FILES['evidence_photo'])&&($_FILES['evidence_photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            $photo=sac_upload_image($_FILES['evidence_photo'],4*1024*1024);
        }
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT * FROM scouting_assignments WHERE id=? AND organization_id=? AND assignee_user_id=? FOR UPDATE');
            $q->execute([(int)$id,$org,$userId]);$a=$q->fetch(PDO::FETCH_ASSOC);
            if(!$a)throw new InvalidArgumentException('Assignment not found for this account.');
            [$next,$audit]=sac_status_transition((string)$a['status'],$transition);
            if($transition==='complete'&&$a['kind']==='request'){
                if($a['request_kind']==='question'&&$response===''&&trim((string)$a['response_text'])===''){
                    throw new InvalidArgumentException('Provide the answer before completing this question.');
                }
                if($a['request_kind']==='photo'&&!$photo){
                    $q=$pdo->prepare('SELECT COUNT(*) FROM scouting_assignment_evidence WHERE organization_id=? AND assignment_id=?');
                    $q->execute([$org,$id]);
                    if(!(int)$q->fetchColumn())throw new InvalidArgumentException('Attach a photo before completing this request.');
                }
            }
            $q=$pdo->prepare("UPDATE scouting_assignments SET status=?,updated_by=?,
                acknowledged_at=IF(?='acknowledged',UTC_TIMESTAMP(),acknowledged_at),
                acknowledged_by=IF(?='acknowledged',?,acknowledged_by),
                completed_at=IF(?='completed',UTC_TIMESTAMP(),completed_at),
                completed_by=IF(?='completed',?,completed_by),
                response_text=IF(?='completed' AND kind='request' AND ?<>'',?,response_text)
                WHERE id=? AND organization_id=? AND assignee_user_id=?");
            $q->execute([$next,$userId,$next,$next,$userId,$next,$next,$userId,$next,$response,$response,$id,$org,$userId]);
            if($photo&&$transition==='complete'&&$a['kind']==='request'){
                $q=$pdo->prepare('INSERT INTO scouting_assignment_evidence (organization_id,assignment_id,mime_type,image_data,uploaded_by,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())');
                $q->bindValue(1,$org,PDO::PARAM_INT);$q->bindValue(2,(int)$id,PDO::PARAM_INT);
                $q->bindValue(3,$photo[0]);$q->bindValue(4,$photo[1],PDO::PARAM_LOB);
                $q->bindValue(5,$userId,PDO::PARAM_INT);$q->execute();
            }
            $q=$pdo->prepare('INSERT INTO scouting_assignment_history
                (organization_id,event_id,assignment_id,action,previous_user_id,new_user_id,previous_status,new_status,actor_user_id,notes,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            $q->execute([$org,(int)$a['event_id'],(int)$id,$audit,$userId,$userId,$a['status'],$next,$userId,'Updated by scouter in notification assignment panel']);
            $pdo->commit();
            json_response(['status'=>'success']);exit;
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            if($e instanceof InvalidArgumentException){json_response(['status'=>'error','message'=>$e->getMessage()],422);exit;}
            throw $e;
        }
    }
    if($action==='mark_read'){
        $id=filter_var($_POST['notification_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false || $id===null){json_response(['status'=>'error','message'=>'Invalid notification ID'],400);exit;}
        $q=$pdo->prepare('UPDATE scouting_assignment_notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE id=? AND organization_id=? AND recipient_user_id=?');
        $q->execute([(int)$id,$org,$userId]);
        json_response(['status'=>'success']);exit;
    }
    if($action==='mark_all_read'){
        $q=$pdo->prepare('UPDATE scouting_assignment_notifications SET read_at=UTC_TIMESTAMP() WHERE organization_id=? AND recipient_user_id=? AND read_at IS NULL');
        $q->execute([$org,$userId]);
        json_response(['status'=>'success']);exit;
    }
    json_response(['status'=>'error','message'=>'Unknown action'],400);exit;
} catch(Throwable $e){
    error_log('[Neptune assignment notifications] '.get_class($e).': '.$e->getMessage());
    json_response(['status'=>'error','message'=>'Notification inbox unavailable'],503);exit;
}
