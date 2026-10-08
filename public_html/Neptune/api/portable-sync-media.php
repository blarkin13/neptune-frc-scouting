<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function npsm_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    npsm_json(['ok'=>false,'error'=>'POST required.'],405);
}

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/portable.php';

$org=(int)($_POST['organization_id']??0);
$event=(int)($_POST['event_id']??0);
$kind=trim((string)($_POST['kind']??''));
$token=trim((string)($_SERVER['HTTP_X_NEPTUNE_PORTABLE_TOKEN']??''));

if($org<=0||$event<=0||!in_array($kind,['pit','tag'],true)){
    npsm_json(['ok'=>false,'error'=>'Invalid media sync request.'],422);
}
$eventCheck=$pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=? LIMIT 1');
$eventCheck->execute([$event,$org]);
if(!$eventCheck->fetchColumn()) npsm_json(['ok'=>false,'error'=>'Event is not available for this organization.'],422);
try{
    neptune_portable_authenticate_token($pdo,$token,$org,$event);
}catch(Throwable $e){
    npsm_json(['ok'=>false,'error'=>'Unauthorized.'],401);
}

if(empty($_FILES['media'])||!is_array($_FILES['media'])){
    npsm_json(['ok'=>false,'error'=>'Media file is required.'],422);
}
$file=$_FILES['media'];
if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
    npsm_json(['ok'=>false,'error'=>'Upload failed.','upload_error'=>(int)($file['error']??-1)],400);
}
$tmp=(string)($file['tmp_name']??'');
$size=(int)($file['size']??0);
if(!is_uploaded_file($tmp)||$size<=0){
    npsm_json(['ok'=>false,'error'=>'Uploaded media is invalid.'],400);
}
if($size>64*1024*1024){
    npsm_json(['ok'=>false,'error'=>'Portable media files are limited to 64 MB each.'],413);
}

$finfo=new finfo(FILEINFO_MIME_TYPE);
$mime=(string)$finfo->file($tmp);
$extMap=[
    'image/jpeg'=>'jpg',
    'image/png'=>'png',
    'image/webp'=>'webp',
    'image/avif'=>'avif',
    'image/gif'=>'gif',
    'video/mp4'=>'mp4',
    'video/quicktime'=>'mov',
    'video/webm'=>'webm',
];
if(!isset($extMap[$mime])){
    npsm_json(['ok'=>false,'error'=>'Unsupported portable media type.'],415);
}
if($kind==='pit' && !str_starts_with($mime,'image/')){
    npsm_json(['ok'=>false,'error'=>'Pit media must be an image.'],415);
}

$sha=hash_file('sha256',$tmp);
if(!$sha)npsm_json(['ok'=>false,'error'=>'Could not hash uploaded media.'],500);
$ext=$extMap[$mime];

$relDir='uploads/portable-sync/org-'.$org.'/event-'.$event.'/'.$kind;
$publicRoot=dirname(__DIR__);
$absDir=$publicRoot.'/'.$relDir;
if(!is_dir($absDir) && !mkdir($absDir,0775,true) && !is_dir($absDir)){
    npsm_json(['ok'=>false,'error'=>'Could not create portable media directory.'],500);
}
$relPath=$relDir.'/'.$sha.'.'.$ext;
$absPath=$publicRoot.'/'.$relPath;

if(!is_file($absPath)){
    if(!move_uploaded_file($tmp,$absPath)){
        npsm_json(['ok'=>false,'error'=>'Could not store portable media.'],500);
    }
    @chmod($absPath,0664);
}

try{
    $pdo->beginTransaction();

    if($kind==='pit'){
        $team=(int)($_POST['frc_team_number']??0);
        $category=(string)($_POST['category']??'other');
        if(!in_array($category,['front','back','left','right','mechanism','other'],true))$category='other';
        $caption=trim((string)($_POST['caption']??''));
        if(strlen($caption)>190)$caption=substr($caption,0,190);

        $s=$pdo->prepare(
            'SELECT id FROM pit_scouting
             WHERE organization_id=? AND event_id=? AND frc_team_number=?
             LIMIT 1'
        );
        $s->execute([$org,$event,$team]);
        $pitId=(int)$s->fetchColumn();
        if($pitId<=0)throw new RuntimeException('Pit scouting row must sync before its photo.');

        $s=$pdo->prepare(
            'SELECT id FROM pit_scouting_photos
             WHERE pit_scouting_id=? AND organization_id=? AND file_path=?
             LIMIT 1'
        );
        $s->execute([$pitId,$org,$relPath]);
        $existing=(int)$s->fetchColumn();
        if($existing<=0){
            $s=$pdo->prepare(
                'INSERT INTO pit_scouting_photos
                 (pit_scouting_id,organization_id,category,file_path,caption,uploaded_by)
                 VALUES(?,?,?,?,?,NULL)'
            );
            $s->execute([$pitId,$org,$category,$relPath,$caption!==''?$caption:null]);
            $existing=(int)$pdo->lastInsertId();
        }

        $pdo->commit();
        npsm_json([
            'ok'=>true,'kind'=>'pit','id'=>$existing,'file_path'=>$relPath,
            'sha256'=>$sha,'bytes'=>$size,'mime'=>$mime
        ]);
    }

    $uuid=strtolower(trim((string)($_POST['observation_uuid']??'')));
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',$uuid)){
        throw new RuntimeException('Observation UUID is invalid.');
    }
    $s=$pdo->prepare(
        'SELECT id FROM tag_scouting_observations
         WHERE uuid=? AND organization_id=? AND event_id=?
         LIMIT 1'
    );
    $s->execute([$uuid,$org,$event]);
    $obsId=(int)$s->fetchColumn();
    if($obsId<=0)throw new RuntimeException('Tag observation must sync before its media.');

    $mediaType=(string)($_POST['media_type']??(str_starts_with($mime,'video/')?'video':'photo'));
    if(!in_array($mediaType,['photo','video'],true))$mediaType=str_starts_with($mime,'video/')?'video':'photo';
    $original=trim((string)($_POST['original_filename']??''));
    if(strlen($original)>255)$original=substr($original,0,255);
    $duration=(string)($_POST['duration_seconds']??'');
    $duration=$duration!==''&&is_numeric($duration)?(float)$duration:null;

    $s=$pdo->prepare(
        'SELECT id FROM tag_scouting_media
         WHERE observation_id=? AND organization_id=? AND storage_relpath=?
         LIMIT 1'
    );
    $s->execute([$obsId,$org,$relPath]);
    $existing=(int)$s->fetchColumn();

    if($existing<=0){
        $width=null;$height=null;
        if(str_starts_with($mime,'image/')){
            $dim=@getimagesize($absPath);
            if(is_array($dim)){ $width=(int)($dim[0]??0)?:null; $height=(int)($dim[1]??0)?:null; }
        }
        $s=$pdo->prepare(
            'INSERT INTO tag_scouting_media
             (observation_id,organization_id,media_type,storage_relpath,original_filename,mime_type,
              file_size,width,height,duration_seconds,uploaded_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,NULL)'
        );
        $s->execute([
            $obsId,$org,$mediaType,$relPath,$original!==''?$original:null,$mime,$size,
            $width,$height,$duration
        ]);
        $existing=(int)$pdo->lastInsertId();
    }

    $pdo->commit();
    npsm_json([
        'ok'=>true,'kind'=>'tag','id'=>$existing,'storage_relpath'=>$relPath,
        'sha256'=>$sha,'bytes'=>$size,'mime'=>$mime
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[Neptune portable media sync] '.$e->getMessage());
    npsm_json(['ok'=>false,'error'=>'Portable media synchronization failed.','detail'=>$e->getMessage()],500);
}
