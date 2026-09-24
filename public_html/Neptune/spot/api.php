<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';

$u=require_login();
$org=(int)$u['organization_id'];
$userId=(int)$u['id'];
if(!spot_tables_ready($pdo))json_response(['status'=>'error','message'=>'Spot Scouting is not installed. Import sql/2026-09-22_spot-scouting-v1.sql.'],503);

$action=trim((string)($_GET['action']??$_POST['action']??''));
$json=[];
if($_SERVER['REQUEST_METHOD']==='POST' && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')){
    $json=json_decode((string)file_get_contents('php://input'),true)?:[];
    if($action==='')$action=trim((string)($json['action']??''));
}

if($_SERVER['REQUEST_METHOD']==='GET'){
    if($action==='state'){
        $eventId=(int)($_GET['event_id']??0);
        $feed=spot_observation_rows($pdo,$org,$eventId?:null,null,30,false);
        $review=spot_can_manage($u)?spot_observation_rows($pdo,$org,$eventId?:null,null,30,true):[];
        $ids=array_merge(array_column($feed,'id'),array_column($review,'id'));
        $media=spot_media_for_observations($pdo,$org,$ids);
        foreach($feed as &$r)$r['media']=$media[$r['id']]??[];unset($r);
        foreach($review as &$r)$r['media']=$media[$r['id']]??[];unset($r);
        json_response([
            'status'=>'success',
            'active_matches'=>spot_active_matches($pdo,$org),
            'preference'=>spot_user_preference($pdo,$org,$userId),
            'feed'=>$feed,
            'review'=>$review,
            'server_time'=>date(DATE_ATOM),
        ]);
    }

    if($action==='matches'){
        $eventId=(int)($_GET['event_id']??0);
        if($eventId<=0)json_response(['status'=>'error','message'=>'Event is required.'],422);
        $s=$pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=? LIMIT 1');$s->execute([$eventId,$org]);
        if(!(int)$s->fetchColumn())json_response(['status'=>'error','message'=>'Event not found.'],404);
        json_response(['status'=>'success','matches'=>spot_matches_for_event($pdo,$org,$eventId)]);
    }

    if($action==='search'){
        $q=trim((string)($_GET['q']??''));
        json_response(['status'=>'success','teams'=>spot_team_search($pdo,$org,$q,40)]);
    }

    json_response(['status'=>'error','message'=>'Unknown Spot Scouting request.'],404);
}

if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['status'=>'error','message'=>'Method not allowed.'],405);

// When PHP rejects a large multipart body before this script runs, $_POST and
// $_FILES are empty. Return the real cause instead of a misleading CSRF error.
if($action==='save' && (int)($_SERVER['CONTENT_LENGTH']??0)>0 && empty($_POST) && empty($_FILES)){
    json_response(['status'=>'error','message'=>'The attachment was larger than the server direct-upload limit. Reload Spot Scouting and try again; videos are uploaded in small chunks by the current version.'],413);
}

if($action==='upload_chunk'){
    spot_csrf_or_fail((string)($_POST['csrf']??''));
    $token=trim((string)($_POST['upload_token']??''));
    $chunkIndex=(int)($_POST['chunk_index']??-1);
    $totalChunks=(int)($_POST['total_chunks']??0);
    $fileName=(string)($_POST['file_name']??'video');
    $fileSize=(int)($_POST['file_size']??0);
    $fileType=(string)($_POST['file_type']??'');
    $chunk=$_FILES['chunk']??null;
    if(!is_array($chunk))json_response(['status'=>'error','message'=>'Video chunk is missing.'],422);
    try{
        spot_cleanup_staged_uploads($org,$userId);
        $result=spot_receive_video_chunk($chunk,$org,$userId,$token,$chunkIndex,$totalChunks,$fileName,$fileSize,$fileType);
        json_response(['status'=>'success']+$result);
    }catch(Throwable $e){
        json_response(['status'=>'error','message'=>$e->getMessage()],422);
    }
}

if($action==='preference'){
    spot_csrf_or_fail((string)($json['csrf']??''));
    $eventId=(int)($json['event_id']??0);
    $fieldId=(int)($json['field_id']??0);
    $auto=!empty($json['auto_follow'])?1:0;
    if($eventId>0){
        $s=$pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=? LIMIT 1');$s->execute([$eventId,$org]);
        if(!(int)$s->fetchColumn())json_response(['status'=>'error','message'=>'Event not found.'],404);
    }
    $s=$pdo->prepare("INSERT INTO spot_scout_preferences (organization_id,user_id,event_id,field_id,auto_follow)
      VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE event_id=VALUES(event_id),field_id=VALUES(field_id),auto_follow=VALUES(auto_follow),updated_at=CURRENT_TIMESTAMP");
    $s->execute([$org,$userId,$eventId?:null,$fieldId?:null,$auto]);
    json_response(['status'=>'success','preference'=>spot_user_preference($pdo,$org,$userId)]);
}

if($action==='resolve'){
    if(!spot_can_manage($u))json_response(['status'=>'error','message'=>'Strategy, admin, or owner access is required to resolve Spot Scouting observations.'],403);
    spot_csrf_or_fail((string)($json['csrf']??''));
    $id=(int)($json['observation_id']??0);
    $note=mb_substr(trim((string)($json['resolution_note']??'')),0,4000);
    $s=$pdo->prepare("UPDATE spot_observations SET status='resolved',resolved_by=?,resolved_at=NOW(),resolution_note=? WHERE id=? AND organization_id=?");
    $s->execute([$userId,$note!==''?$note:null,$id,$org]);
    if(!$s->rowCount())json_response(['status'=>'error','message'=>'Observation not found or already resolved.'],404);
    json_response(['status'=>'success','observation_id'=>$id]);
}

if($action==='reopen'){
    if(!spot_can_manage($u))json_response(['status'=>'error','message'=>'Strategy, admin, or owner access is required to reopen Spot Scouting observations.'],403);
    spot_csrf_or_fail((string)($json['csrf']??''));
    $id=(int)($json['observation_id']??0);
    $s=$pdo->prepare("UPDATE spot_observations SET status='open',resolved_by=NULL,resolved_at=NULL,resolution_note=NULL WHERE id=? AND organization_id=?");
    $s->execute([$id,$org]);
    if(!$s->rowCount())json_response(['status'=>'error','message'=>'Observation not found or already open.'],404);
    json_response(['status'=>'success','observation_id'=>$id]);
}

if($action!=='save')json_response(['status'=>'error','message'=>'Unknown Spot Scouting action.'],404);

spot_csrf_or_fail((string)($_POST['csrf']??''));
$context=(string)($_POST['context']??'general');
if(!in_array($context,['match','pit','general'],true))$context='general';
$entryMode=(string)($_POST['entry_mode']??'manual');
if(!in_array($entryMode,['auto','manual'],true))$entryMode='manual';
$eventId=(int)($_POST['event_id']??0);
$matchId=(int)($_POST['match_id']??0);
$team=(int)($_POST['frc_team_number']??0);
$note=mb_substr(trim((string)($_POST['note']??'')),0,8000);
$tagIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['tag_ids']??[])),static fn($v)=>$v>0)));
if($team<=0)json_response(['status'=>'error','message'=>'Select or enter a team number.'],422);

if($eventId>0){
    $s=$pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=? LIMIT 1');$s->execute([$eventId,$org]);
    if(!(int)$s->fetchColumn())json_response(['status'=>'error','message'=>'Event not found for your organization.'],404);
}

$fieldId=null;
if($context==='match'){
    if($matchId<=0)json_response(['status'=>'error','message'=>'Select a match for a match observation.'],422);
    $s=$pdo->prepare("SELECT m.id,m.event_id,m.field_id FROM matches m WHERE m.id=? AND m.organization_id=? LIMIT 1");
    $s->execute([$matchId,$org]);$match=$s->fetch();
    if(!$match)json_response(['status'=>'error','message'=>'Match not found.'],404);
    $eventId=(int)$match['event_id'];$fieldId=(int)$match['field_id'];
    $s=$pdo->prepare('SELECT COUNT(*) FROM match_teams WHERE match_id=? AND frc_team_number=?');$s->execute([$matchId,$team]);
    if((int)$s->fetchColumn()===0)json_response(['status'=>'error','message'=>'That team is not assigned to the selected match.'],422);
}else{
    $matchId=0;
}

$tags=[];
if($tagIds){
    $contextColumn=$context==='match'?'match_enabled':($context==='pit'?'pit_enabled':'general_enabled');
    $visible=spot_visible_tags($pdo,$org,false);
    $wanted=array_fill_keys($tagIds,true);
    foreach($visible as $tag){
        if(isset($wanted[(int)$tag['id']]) && !empty($tag[$contextColumn]))$tags[]=$tag;
    }
    $allowed=array_map('intval',array_column($tags,'id'));
    sort($allowed);$requested=$tagIds;sort($requested);
    if($allowed!==$requested)json_response(['status'=>'error','message'=>'One or more selected tags are unavailable for this observation type.'],422);
}

$uploads=[];
foreach(['photos','videos','uploads'] as $field){
    foreach(spot_flatten_uploads($_FILES,$field) as $f){
        if((int)($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)$uploads[]=$f;
    }
}
$stagedVideoTokens=array_values(array_unique(array_filter(array_map(
    static fn($v)=>trim((string)$v),
    (array)($_POST['staged_video_tokens']??[])
),static fn($v)=>$v!=='')));
if(count($uploads)+count($stagedVideoTokens)>8)json_response(['status'=>'error','message'=>'Attach no more than 8 photos/videos to one observation.'],422);
if(!$tags && $note==='' && !$uploads && !$stagedVideoTokens)json_response(['status'=>'error','message'=>'Choose a tag, write a note, or attach a photo/video.'],422);

$severity=spot_highest_severity($tags);
$uuid=uuidv4();
$storedFiles=[];
try{
    $pdo->beginTransaction();
    $s=$pdo->prepare("INSERT INTO spot_observations
      (uuid,organization_id,event_id,match_id,field_id,frc_team_number,context,entry_mode,note,severity,status,created_by,created_at)
      VALUES(?,?,?,?,?,?,?,?,?,?,'open',?,NOW())");
    $s->execute([$uuid,$org,$eventId?:null,$matchId?:null,$fieldId,$team,$context,$entryMode,$note!==''?$note:null,$severity,$userId]);
    $observationId=(int)$pdo->lastInsertId();

    if($tags){
        $insertTag=$pdo->prepare('INSERT INTO spot_observation_tags (observation_id,tag_id) VALUES(?,?)');
        foreach($tags as $tag)$insertTag->execute([$observationId,(int)$tag['id']]);
    }

    if($uploads || $stagedVideoTokens){
        $insertMedia=$pdo->prepare("INSERT INTO spot_observation_media
          (observation_id,organization_id,media_type,storage_relpath,original_filename,mime_type,file_size,width,height,duration_seconds,uploaded_by)
          VALUES(?,?,?,?,?,?,?,?,?,?,?)");
        foreach($uploads as $file){
            $stored=spot_store_media_file($file,$org,$observationId,$team);
            $storedFiles[]=$stored['storage_relpath'];
            $insertMedia->execute([
                $observationId,$org,$stored['media_type'],$stored['storage_relpath'],$stored['original_filename'],$stored['mime_type'],
                $stored['file_size'],$stored['width'],$stored['height'],$stored['duration_seconds'],$userId
            ]);
        }
        foreach($stagedVideoTokens as $token){
            $stored=spot_store_staged_video($token,$org,$userId,$observationId,$team);
            $storedFiles[]=$stored['storage_relpath'];
            $insertMedia->execute([
                $observationId,$org,$stored['media_type'],$stored['storage_relpath'],$stored['original_filename'],$stored['mime_type'],
                $stored['file_size'],$stored['width'],$stored['height'],$stored['duration_seconds'],$userId
            ]);
        }
    }

    $pdo->commit();
    json_response(['status'=>'success','observation_id'=>$observationId,'uuid'=>$uuid,'message'=>'Spot observation saved.']);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($storedFiles as $rel)spot_delete_stored_relpath($rel);
    json_response(['status'=>'error','message'=>$e->getMessage()],500);
}
