<?php
declare(strict_types=1);
/** Explicit field mapping for flat CSV / JSON action rows. No raw personal columns persist. */
function community_source_rows(string $contents):array {
 if(strlen($contents)>8*1024*1024)throw new CommunityAnalysisError(422,'Choose a file smaller than 8 MB.');
 $text=ltrim($contents,"\xef\xbb\xbf \r\n\t");
 if(str_starts_with($text,'[')||str_starts_with($text,'{')){
  try{$rows=json_decode($text,true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new CommunityAnalysisError(422,'The JSON could not be read.');}
  if(!array_is_list($rows))$rows=$rows['records']??$rows['data']??null;
  if(!is_array($rows)||!array_is_list($rows))throw new CommunityAnalysisError(422,'Use a JSON array of action records, or an object with a records/data array. Nested vendor exports need a specific adapter.');
 }else{
  $f=fopen('php://temp','r+');fwrite($f,$text);rewind($f);$header=fgetcsv($f,0,',','"','');if(!$header)throw new CommunityAnalysisError(422,'The CSV has no header.');$header=array_map('trim',$header);if(count(array_unique($header))!==count($header))throw new CommunityAnalysisError(422,'CSV column names must be unique.');$rows=[];
  while(($row=fgetcsv($f,0,',','"',''))!==false){if($row===[null])continue;if(count($row)!==count($header))throw new CommunityAnalysisError(422,'A CSV row has a different number of columns from its header.');$rows[]=array_combine($header,$row);}fclose($f);
 }
 if(!$rows||count($rows)>20000)throw new CommunityAnalysisError(422,'Use between 1 and 20,000 action records.');
 foreach($rows as $r)if(!is_array($r)||array_is_list($r))throw new CommunityAnalysisError(422,'Each action must be an object with named columns.');
 return $rows;
}
function community_source_columns(string $contents):array {
 $rows=community_source_rows($contents);$columns=[];foreach($rows as $r)foreach($r as $k=>$v)if(is_scalar($v)||$v===null)$columns[(string)$k]=true;
 return ['columns'=>array_keys($columns),'rows'=>count($rows)];
}
function community_source_mapped(string $contents,string $event,array $mapping,int $offset=0):array {
 if(!preg_match('/^2026[a-z0-9]{1,60}$/D',$event))throw new CommunityAnalysisError(422,'Mapped activity currently supports 2026. Choose its event code.');
 $rows=community_source_rows($contents);$catalog=community_video_tag_catalog();$out=[];$matches=[];$grouped=[];
 foreach(['match','team','action','time','phase'] as $name)if(empty($mapping[$name]))throw new CommunityAnalysisError(422,'Map the '.$name.' column.');
 $basis=$mapping['clock']??'phase_elapsed';if(!in_array($basis,['phase_elapsed','phase_remaining'],true))throw new CommunityAnalysisError(422,'Choose elapsed or remaining phase seconds.');
 foreach($rows as $i=>$r){$get=function($k)use($r,$mapping){$v=$r[$mapping[$k]]??null;if(!is_scalar($v))throw new CommunityAnalysisError(422,'Mapped fields must contain text or numbers.');return trim((string)$v);};
  $match=$get('match');if(ctype_digit($match))$match='qm'.(int)$match;if(!str_starts_with($match,$event.'_'))$match=$event.'_'.$match;if(!preg_match('/^'.preg_quote($event,'/').'_(?:qm[1-9][0-9]*|(?:sf|qf|f)[1-9][0-9]*m[1-9][0-9]*)$/D',$match))throw new CommunityAnalysisError(422,'Row '.($i+1).': match must be qm1, a full match key, or a playoff key such as f1m1.');
  $team=preg_replace('/^frc/i','',$get('team'));if(!ctype_digit($team)||(int)$team<1)throw new CommunityAnalysisError(422,'Row '.($i+1).': invalid team number.');
  $action=$get('action');$tag=null;foreach($catalog as $k=>$c)if(strcasecmp($action,$k)===0||strcasecmp($action,$c['label'])===0)$tag=$k;
  if(!$tag)throw new CommunityAnalysisError(422,'Row '.($i+1).': unknown action “'.$action.'”. Use a Neptune tag key or label; aggregate counts cannot be treated as timed actions.');
  $phase=strtolower($get('phase'));$phase=['autonomous'=>'auto','tele'=>'teleop'][$phase]??$phase;if(!in_array($phase,['auto','teleop'],true))throw new CommunityAnalysisError(422,'Row '.($i+1).': phase must be auto or teleop.');
  $value=$get('time');if(preg_match('/^(\d+):(\d{2}(?:\.\d+)?)$/D',$value,$m))$value=(string)((int)$m[1]*60+(float)$m[2]);if(!is_numeric($value)||!is_finite((float)$value))throw new CommunityAnalysisError(422,'Row '.($i+1).': time must be seconds or m:ss.');
  $length=$phase==='auto'?20:140;$seconds=$basis==='phase_remaining'?$length-(float)$value:(float)$value;$ms=(int)round($seconds*1000)+$offset;if($ms<0||$ms>$length*1000)throw new CommunityAnalysisError(422,'Row '.($i+1).': time is outside the selected phase.');
  $payload=['format'=>'mapped-actions-v1','fields'=>['auto_fuel_score'=>null,'tele_fuel_score'=>null],'warnings'=>[],'timeline'=>[['index'=>0,'action'=>$tag,'seconds'=>$seconds,'phase'=>$phase,'time_ms'=>$ms,'value'=>null]]];
  $key=$match.':'.(int)$team;if(!isset($grouped[$key]))$grouped[$key]=['match_key'=>$match,'team_number'=>(int)$team,'payload'=>array_replace($payload,['timeline'=>[]])];$e=$payload['timeline'][0];$e['index']=count($grouped[$key]['payload']['timeline']);$grouped[$key]['payload']['timeline'][]=$e;$matches[$match]=true;
 }
 foreach($grouped as $g){$g['record_sha256']=hash('sha256',json_encode($g,JSON_THROW_ON_ERROR));$out[]=$g;}
 // Mapping is part of identity: changing clock interpretation cannot reuse a stale preview.
 return ['provider'=>'mapped','adapter_version'=>'mapped-actions-v1','event_key'=>$event,'file_sha256'=>hash('sha256',$contents.json_encode($mapping,JSON_THROW_ON_ERROR)),'timing_offset_ms'=>$offset,'records'=>$out,'report'=>['robot_records'=>count($out),'matches'=>count($matches),'timeline_entries'=>count($rows),'fuel_entries'=>0,'warning_records'=>0,'issues'=>[],'note'=>'Explicitly mapped observer action rows. Only mapped robot/action/time fields are stored. Timings must be verified against video; these remain outside training examples, never evaluation truth.']];
}
