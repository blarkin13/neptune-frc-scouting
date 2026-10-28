<?php
declare(strict_types=1);
/** Scoreboard score changes per match video, sent by helpers. A tagging aid shown on Watch & tag: never a training
 *  label or model input (official scores are for evaluation only). Files: neptune_secure/score-moments/<org>/<video>.json */
final class ScoreMoments {
 private string $dir;
 public function __construct(string $secure,int $org){$this->dir=$secure.'/score-moments/'.$org;}
 private static function video(string $v):string{if(!preg_match('/^[A-Za-z0-9_-]{11}$/D',$v))throw new InvalidArgumentException('Invalid video');return $v;}
 public function put(array $p):array{
  $v=self::video((string)($p['video_id']??''));$num=fn($x)=>is_numeric($x)&&is_finite((float)$x);
  $bursts=[];foreach(array_slice(is_array($p['bursts']??null)?$p['bursts']:[],0,400) as $b){
   if(!is_array($b)||!in_array($b['alliance']??'',['red','blue'],true)||!$num($b['start']??null)||!$num($b['end']??null)||!$num($b['points']??null))continue;
   $bursts[]=['start'=>round((float)$b['start'],1),'end'=>round((float)$b['end'],1),'alliance'=>$b['alliance'],'points'=>(int)$b['points'],'phase'=>in_array($b['phase']??'',['auto','teleop'],true)?$b['phase']:null];
  }
  if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true))throw new RuntimeException('Storage unavailable');
  $doc=['video_id'=>$v,'match_key'=>preg_match('/^\d{4}[A-Za-z0-9_-]{1,95}$/D',(string)($p['match_key']??''))?$p['match_key']:null,'bursts'=>$bursts,'saved_at'=>time()];
  $tmp=tempnam($this->dir,'pending-');if($tmp===false||file_put_contents($tmp,json_encode($doc,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Could not save');
  chmod($tmp,0600);if(!rename($tmp,$this->dir.'/'.$v.'.json'))throw new RuntimeException('Could not commit save');
  return ['ok'=>true,'bursts'=>count($bursts)];
 }
 public function get(string $video):?array{$f=$this->dir.'/'.self::video($video).'.json';return is_file($f)?json_decode((string)file_get_contents($f),true):null;}
}
