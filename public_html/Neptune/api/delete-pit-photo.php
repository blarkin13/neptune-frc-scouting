<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/image.php';

$u=require_login();
$d=json_decode(file_get_contents('php://input'),true)?:[];
$photoId=(int)($d['photo_id']??0);
$csrf=(string)($d['csrf']??'');
if(!$photoId)json_response(['status'=>'error','message'=>'Photo is required.'],422);
if(!hash_equals($_SESSION['csrf']??'', $csrf))json_response(['status'=>'error','message'=>'Invalid CSRF token.'],419);

$s=$pdo->prepare('SELECT p.id,p.file_path,p.category,p.pit_scouting_id FROM pit_scouting_photos p JOIN pit_scouting ps ON ps.id=p.pit_scouting_id WHERE p.id=? AND p.organization_id=? AND ps.organization_id=?');
$s->execute([$photoId,(int)$u['organization_id'],(int)$u['organization_id']]);
$photo=$s->fetch();
if(!$photo)json_response(['status'=>'error','message'=>'Photo not found.'],404);

$pdo->prepare('DELETE FROM pit_scouting_photos WHERE id=? AND organization_id=?')->execute([$photoId,(int)$u['organization_id']]);
$fileDeleted=neptune_delete_pit_image_file(dirname(__DIR__),(string)$photo['file_path']);

json_response([
    'status'=>'success',
    'photo_id'=>$photoId,
    'file_deleted'=>$fileDeleted,
]);
