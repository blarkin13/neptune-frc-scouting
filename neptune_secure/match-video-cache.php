<?php
declare(strict_types=1);
/** Private original match sources; one upload at a time, bounded across organizations. */
final class MatchVideoCache {
 const CAP=12*1024*1024*1024, RESERVE=10*1024*1024*1024, MAX=512*1024*1024, CHUNK=8*1024*1024;
 private string $root; private int $org;
 public function __construct(string $secure,int $org){$this->root=$secure.'/match-video-cache';$this->org=$org;}
 private static function video(string $v):void{if(!preg_match('/^[A-Za-z0-9_-]{11}$/D',$v))throw new InvalidArgumentException('Invalid match video');}
 private static function digest(string $v):void{if(!preg_match('/^[a-f0-9]{64}$/D',$v))throw new InvalidArgumentException('Invalid video checksum');}
 private function dir():string{return $this->root.'/'.$this->org;}
 private function index(string $v):?array{self::video($v);$p=$this->dir().'/'.$v.'.json';return is_file($p)?json_decode((string)file_get_contents($p),true,16,JSON_THROW_ON_ERROR):null;}
 private function locked(callable $fn):array{
  if(!is_dir($this->root)&&!mkdir($this->root,0700,true))throw new RuntimeException('Video cache unavailable');
  $lock=fopen($this->root.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Video cache busy');
  try{return $fn();}finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 private function write(string $path,array $data):void{$tmp=tempnam(dirname($path),'pending-');if($tmp===false)throw new RuntimeException('Cache unavailable');try{if(file_put_contents($tmp,json_encode($data,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Cache write failed');chmod($tmp,0600);if(!rename($tmp,$path))throw new RuntimeException('Cache publication failed');}finally{if(is_file($tmp))unlink($tmp);}}
 public function info(string $video):array{$d=$this->index($video);if(!$d||!is_file($this->dir().'/'.$video.'.mp4'))return ['available'=>false];return ['available'=>true,'video'=>$d,'chunk_size'=>self::CHUNK];}
 public function chunk(string $video,string $sha,int $offset):array{
  self::digest($sha);$d=$this->index($video);if(!$d||$d['sha256']!==$sha)throw new InvalidArgumentException('Cached source changed or missing');
  if($offset<0||$offset>=$d['bytes']||$offset%self::CHUNK!==0)throw new InvalidArgumentException('Invalid video offset');
  $f=fopen($this->dir().'/'.$video.'.mp4','rb');if(!$f)throw new RuntimeException('Cached source missing');try{fseek($f,$offset);$raw=fread($f,self::CHUNK);}finally{fclose($f);}if($raw===false||$raw==='')throw new RuntimeException('Cached source truncated');
  return ['offset'=>$offset,'sha256'=>hash('sha256',$raw),'data_b64'=>base64_encode($raw)];
 }
 public function begin(string $video,string $sha,int $bytes,string $owner):array{
  self::video($video);self::digest($sha);if($bytes<16||$bytes>self::MAX)throw new InvalidArgumentException('Individual match source exceeds cache limits');
  return $this->locked(function()use($video,$sha,$bytes,$owner){
   $existing=$this->info($video);if($existing['available']){if($existing['video']['sha256']!==$sha)throw new InvalidArgumentException('A different original source is already cached; it was preserved');return $existing;}
   $lease=$this->root.'/.upload.json';$part=$this->root.'/.upload.part';$d=is_file($lease)?json_decode((string)file_get_contents($lease),true):null;
   if($d&&($d['touched']??0)>time()-1800){if($d['org']===$this->org&&$d['owner']===$owner&&$d['video_id']===$video&&$d['sha256']===$sha&&$d['bytes']===$bytes)return ['available'=>false,'upload_id'=>$d['upload_id'],'offset'=>is_file($part)?filesize($part):0,'chunk_size'=>self::CHUNK];throw new InvalidArgumentException('Another match video is uploading; retry later');}
   if(is_file($part))unlink($part);if(is_file($lease))unlink($lease);
   $used=0;foreach(glob($this->root.'/*/*.mp4')?:[] as $p)$used+=filesize($p);
   if($used+$bytes>self::CAP)throw new InvalidArgumentException('Shared video cache reached its 12 GiB limit; original videos were preserved');
   if(disk_free_space($this->root)-$bytes<self::RESERVE)throw new InvalidArgumentException('Video cache paused to keep 10 GiB free');
   $d=['upload_id'=>bin2hex(random_bytes(16)),'org'=>$this->org,'owner'=>$owner,'video_id'=>$video,'sha256'=>$sha,'bytes'=>$bytes,'touched'=>time()];
   if(file_put_contents($part,'')===false)throw new RuntimeException('Cannot stage match source');chmod($part,0600);$this->write($lease,$d);
   return ['available'=>false,'upload_id'=>$d['upload_id'],'offset'=>0,'chunk_size'=>self::CHUNK];
  });
 }
 public function put(string $id,int $offset,string $b64,string $owner):array{
  if(strlen($b64)>intdiv(self::CHUNK+2,3)*4)throw new InvalidArgumentException('Video chunk too large');$raw=base64_decode($b64,true);if($raw===false||strlen($raw)<1||strlen($raw)>self::CHUNK)throw new InvalidArgumentException('Invalid video chunk');
  return $this->locked(function()use($id,$offset,$raw,$owner){
   $lease=$this->root.'/.upload.json';$part=$this->root.'/.upload.part';$d=is_file($lease)?json_decode((string)file_get_contents($lease),true):null;
   if(!$d||!hash_equals($d['upload_id'],$id)||$d['org']!==$this->org||$d['owner']!==$owner)throw new InvalidArgumentException('Match upload expired');
   clearstatcache(true,$part);$size=filesize($part);if($offset!==$size||$size+strlen($raw)>$d['bytes'])throw new InvalidArgumentException('Unexpected video chunk offset');
   if(disk_free_space($this->root)-strlen($raw)<self::RESERVE)throw new InvalidArgumentException('Video cache paused to keep 10 GiB free');
   if(file_put_contents($part,$raw,FILE_APPEND|LOCK_EX)!==strlen($raw))throw new RuntimeException('Video cache write failed');$size+=strlen($raw);$d['touched']=time();$this->write($lease,$d);
   if($size===$d['bytes']){
    if(hash_file('sha256',$part)!==$d['sha256']){unlink($part);unlink($lease);throw new InvalidArgumentException('Uploaded match video failed checksum');}
    $f=fopen($part,'rb');$header=fread($f,16);fclose($f);if(substr($header,4,4)!=='ftyp'){unlink($part);unlink($lease);throw new InvalidArgumentException('Expected an original MP4 match source');}
    $dir=$this->dir();if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Cache unavailable');
    $meta=['video_id'=>$d['video_id'],'sha256'=>$d['sha256'],'bytes'=>$d['bytes'],'cached_at'=>time(),'original'=>true];
    if(!rename($part,$dir.'/'.$d['video_id'].'.mp4'))throw new RuntimeException('Cache publication failed');$this->write($dir.'/'.$d['video_id'].'.json',$meta);unlink($lease);
    return ['available'=>true,'video'=>$meta,'offset'=>$size];
   }
   return ['available'=>false,'offset'=>$size];
  });
 }
}
