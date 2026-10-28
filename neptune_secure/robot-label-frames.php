<?php
declare(strict_types=1);
/** Private, content-addressed source stills. Saved labels refer to immutable image bytes. */
final class RobotLabelFrames {
 private string $root;
 public function __construct(string $secure,int $org){$this->root=$secure.'/robot-label-frames/'.$org;}
 private function folder(string $video):string{if(!preg_match('/^[A-Za-z0-9_-]{11}$/D',$video))throw new InvalidArgumentException('Invalid video');return $this->root.'/'.$video;}
 private static function time(int $t):void{if($t<0||$t>36000000)throw new InvalidArgumentException('Invalid frame time');}
 private static function hash(string $s):void{if(!preg_match('/^[a-f0-9]{64}$/D',$s))throw new InvalidArgumentException('Invalid frame checksum');}
 public function put(string $video,int $t,string $b64,string $source):array{
  self::time($t);self::hash($source);$dir=$this->folder($video);
  if(strlen($b64)>4194304)throw new InvalidArgumentException('Frame too large');
  $raw=base64_decode($b64,true);$size=$raw===false?false:@getimagesizefromstring($raw);
  if(!$size||($size[2]??null)!==IMAGETYPE_JPEG||$size[0]>4096||$size[1]>2160||$size[0]<16||$size[1]<16)throw new InvalidArgumentException('Invalid JPEG frame');
  if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Frame storage unavailable');
  $sha=hash('sha256',$raw);$file=$dir.'/'.$t.'-'.$sha.'.jpg';
  if(!is_file($file)){$used=0;foreach(glob($dir.'/*.jpg')?:[] as $f)$used+=filesize($f);if($used+strlen($raw)>192*1024*1024)throw new InvalidArgumentException('Frame storage limit reached for this video');
   $orgUsed=0;foreach(glob($this->root.'/*/*.jpg')?:[] as $cached)$orgUsed+=filesize($cached);if($orgUsed+strlen($raw)>2*1024*1024*1024)throw new InvalidArgumentException('Private source-frame storage is full; no saved frames were removed');
   $tmp=tempnam($dir,'pending-');if($tmp===false||file_put_contents($tmp,$raw,LOCK_EX)===false)throw new RuntimeException('Could not save frame');chmod($tmp,0600);rename($tmp,$file);}
  $meta=['video_id'=>$video,'t_ms'=>$t,'sha256'=>$sha,'source_sha256'=>$source,'width'=>$size[0],'height'=>$size[1]];
  $tmp=tempnam($dir,'pending-');file_put_contents($tmp,json_encode($meta,JSON_THROW_ON_ERROR),LOCK_EX);chmod($tmp,0600);rename($tmp,$dir.'/'.$t.'.json');return $meta;
 }
 public function index(string $video):array{$dir=$this->folder($video);$out=[];foreach(glob($dir.'/*.json')?:[] as $f){$d=json_decode((string)file_get_contents($f),true);if(is_array($d))$out[(string)$d['t_ms']]=$d;}ksort($out,SORT_NUMERIC);return $out;}
 public function get(string $video,int $t,?string $sha=null):?array{
  self::time($t);$dir=$this->folder($video);$meta=$dir.'/'.$t.'.json';if(!is_file($meta))return null;
  $d=json_decode((string)file_get_contents($meta),true);$sha??=$d['sha256'];self::hash($sha);$f=$dir.'/'.$t.'-'.$sha.'.jpg';if(!is_file($f))return null;
  $raw=file_get_contents($f);if(hash('sha256',$raw)!==$sha)throw new RuntimeException('Frame checksum mismatch');
  return ['meta'=>$d,'sha256'=>$sha,'bytes'=>$raw];
 }
}
