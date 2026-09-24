<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';

$u=require_login();
$org=(int)$u['organization_id'];
if(!spot_tables_ready($pdo)){http_response_code(404);exit;}
$id=(int)($_GET['id']??0);
if($id<=0){http_response_code(404);exit;}
$s=$pdo->prepare("SELECT m.* FROM spot_observation_media m JOIN spot_observations o ON o.id=m.observation_id WHERE m.id=? AND m.organization_id=? AND o.organization_id=? LIMIT 1");
$s->execute([$id,$org,$org]);
$m=$s->fetch();
if(!$m){http_response_code(404);exit;}

$root=realpath(spot_secure_media_root());
$rel=ltrim(str_replace('\\','/',(string)$m['storage_relpath']),'/');
if(!$root||$rel===''||str_contains($rel,'../')){http_response_code(404);exit;}
$file=realpath($root.'/'.$rel);
if(!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);exit;}

$size=(int)filesize($file);
$mime=(string)($m['mime_type']?:'application/octet-stream');
$name=preg_replace('/[\r\n"\\]+/','_',basename((string)($m['original_filename']?:basename($file))))?:'media';
header('Content-Type: '.$mime);
header('Content-Disposition: inline; filename="'.$name.'"');
header('Cache-Control: private, max-age=3600');
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');

$start=0;$end=max(0,$size-1);$status=200;
$range=(string)($_SERVER['HTTP_RANGE']??'');
if($range!=='' && preg_match('/bytes=(\d*)-(\d*)/',$range,$match)){
    if($match[1]!=='' || $match[2]!==''){
        if($match[1]==='' && $match[2]!==''){
            $suffix=min($size,max(0,(int)$match[2]));$start=max(0,$size-$suffix);
        }else{
            $start=max(0,(int)$match[1]);
            if($match[2]!=='')$end=min($end,(int)$match[2]);
        }
        if($start>$end||$start>=$size){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
        $status=206;
    }
}
$length=$end-$start+1;
http_response_code($status);
if($status===206)header("Content-Range: bytes {$start}-{$end}/{$size}");
header('Content-Length: '.$length);
if($_SERVER['REQUEST_METHOD']==='HEAD')exit;

$fh=fopen($file,'rb');if(!$fh){http_response_code(404);exit;}
fseek($fh,$start);$remaining=$length;
while($remaining>0&&!feof($fh)){
    $chunk=fread($fh,min(1024*1024,$remaining));
    if($chunk===false||$chunk==='')break;
    echo $chunk;$remaining-=strlen($chunk);flush();
}
fclose($fh);
exit;
