<?php
declare(strict_types=1);
/** Read-only, organization-scoped image supplements. Installed by the owner; never public downloads. */
final class ExternalBumperTraining {
 private string $root;
 public function __construct(string $secure,int $org){$this->root=$secure.'/external-bumper-training/'.$org;}
 public function index():array{
  $rows=[];foreach(glob($this->root.'/*.json')?:[] as $file){$id=basename($file,'.json');if(!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D',$id)||is_link($file))continue;if(filesize($file)>24*1024*1024)throw new InvalidArgumentException('External training package too large');$rows[]=['id'=>$id,'sha256'=>hash_file('sha256',$file)];}
  if(count($rows)>8)throw new InvalidArgumentException('Too many external training packages');return $rows;
 }
 public function get(string $id,string $expected):array{
  if(!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new InvalidArgumentException('Invalid external training package');
  $file=$this->root.'/'.$id.'.json';if(!is_file($file)||is_link($file)||filesize($file)>24*1024*1024)throw new InvalidArgumentException('External training package unavailable');
  $raw=(string)file_get_contents($file);if(!hash_equals($expected,hash('sha256',$raw)))throw new InvalidArgumentException('Training package changed; restart preparation');return json_decode($raw,true,64,JSON_THROW_ON_ERROR);
 }
}
