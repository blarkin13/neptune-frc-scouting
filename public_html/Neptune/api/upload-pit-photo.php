<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/image.php';
header('Content-Type: application/json; charset=utf-8');
function pit_photo_result(bool $ok,string $message,int $status=200): never {
    http_response_code($status);
    echo json_encode(['ok'=>$ok,'message'=>$message],JSON_UNESCAPED_SLASHES);
    exit;
}
try {
    if($_SERVER['REQUEST_METHOD']!=='POST')pit_photo_result(false,'POST required.',405);
    if(empty($_POST) && (int)($_SERVER['CONTENT_LENGTH']??0)>0)pit_photo_result(false,'Photo exceeds server request limit.',413);
    $u=require_login();
    verify_csrf();
    $org=(int)$u['organization_id'];
    $eventId=(int)($_POST['event_id']??0);
    $team=(int)($_POST['team']??0);
    $category=(string)($_POST['category']??'');
    if(!in_array($category,['front','back','left','right','mechanism'],true))pit_photo_result(false,'Invalid photo category.',422);
    $file=$_FILES['photo']??null;
    if(!is_array($file))pit_photo_result(false,'Select a photo.',422);
    $s=$pdo->prepare('SELECT ps.id FROM pit_scouting ps JOIN events e ON e.id=ps.event_id AND e.organization_id=ps.organization_id JOIN event_teams et ON et.event_id=e.id AND et.frc_team_number=ps.frc_team_number WHERE ps.organization_id=? AND ps.event_id=? AND ps.frc_team_number=? LIMIT 1');
    $s->execute([$org,$eventId,$team]);
    $pitId=(int)$s->fetchColumn();
    if(!$pitId)pit_photo_result(false,'Save this robot’s scouting answers before uploading photos.',404);
    $relative='uploads/pit/'.$org.'/'.$eventId.'/'.$team;
    $publicRoot=dirname(__DIR__);
    $stored=neptune_store_uploaded_image($file,$publicRoot.'/'.$relative,$category,1800,20*1024*1024);
    $path=$relative.'/'.$stored['filename'];
    try {
        $pdo->beginTransaction();
        $old=$pdo->prepare('SELECT id,file_path FROM pit_scouting_photos WHERE pit_scouting_id=? AND organization_id=? AND category=? FOR UPDATE');
        $old->execute([$pitId,$org,$category]);
        $previous=$old->fetchAll();
        $insert=$pdo->prepare('INSERT INTO pit_scouting_photos(pit_scouting_id,organization_id,category,file_path,caption,uploaded_by) VALUES(?,?,?,?,?,?)');
        $insert->execute([$pitId,$org,$category,$path,'Optimized '.$stored['format'].' · '.$stored['width'].'×'.$stored['height'],(int)$u['id']]);
        if($previous){
            $ids=array_column($previous,'id');
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $del=$pdo->prepare("DELETE FROM pit_scouting_photos WHERE id IN ($marks) AND pit_scouting_id=? AND organization_id=? AND category=?");
            $del->execute(array_merge($ids,[$pitId,$org,$category]));
        }
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        neptune_delete_pit_image_file($publicRoot,$path);
        throw $e;
    }
    foreach($previous as $row)neptune_delete_pit_image_file($publicRoot,(string)$row['file_path']);
    pit_photo_result(true,'Photo saved.');
}catch(Throwable $e){
    error_log('Neptune pit photo upload: '.$e->getMessage());
    pit_photo_result(false,$e instanceof RuntimeException?$e->getMessage():'The server could not save this photo.',422);
}
