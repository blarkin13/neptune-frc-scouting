<?php
declare(strict_types=1);
require_once __DIR__.'/community-video-tags.php';
/** Versioned imports remain separate from community labels and model predictions. */
function community_external_scouting_schema(PDO $db):void {
 neptune_migration_apply($db,'community.external-scouting.v1','Neptune_External_Scouting_v1_6.zip',function()use($db):void{
  $db->exec("CREATE TABLE IF NOT EXISTS community_external_imports (id CHAR(32) PRIMARY KEY,season INT NOT NULL,event_key VARCHAR(80) NOT NULL,provider VARCHAR(32) NOT NULL,adapter_version VARCHAR(80) NOT NULL,source_url VARCHAR(1000) NOT NULL,file_sha256 CHAR(64) NOT NULL,enabled TINYINT NOT NULL DEFAULT 1,timing_offset_ms INT NOT NULL DEFAULT 0,report_json LONGTEXT NOT NULL,created_by BIGINT NOT NULL,created_at BIGINT NOT NULL,UNIQUE KEY import_once(provider,event_key,file_sha256,timing_offset_ms),KEY season_imports(season,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->exec("CREATE TABLE IF NOT EXISTS community_external_observations (id CHAR(32) PRIMARY KEY,import_id CHAR(32) NOT NULL,match_key VARCHAR(100) NOT NULL,team_number INT NOT NULL,record_sha256 CHAR(64) NOT NULL,payload_json LONGTEXT NOT NULL,KEY source_rows(import_id,match_key,team_number),FOREIGN KEY(import_id) REFERENCES community_external_imports(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 });
}
function community_external_scouting_available(PDO $db):bool {
 if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return (bool)$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='community_external_imports'")->fetchColumn();
 $q=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='community_external_imports'");return (int)$q->fetchColumn()>0;
}
final class CommunityExternalScouting {
 public const ADAPTER='viper-2026-activity-v1';
 public function __construct(private PDO $db){}
 private function q(string $sql,array $p=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($p);return $q;}
 public static function parse(string $contents,string $event,int $offset=0):array {
  if(strlen($contents)>8*1024*1024)throw new CommunityAnalysisError(422,'Choose a ViperScout CSV or event JSON bundle smaller than 8 MB.');
  $text=ltrim($contents,"\xef\xbb\xbf \t\r\n");
  if(!str_starts_with($text,'{')&&!str_starts_with($text,'['))return self::parseCsv($contents,$event,$offset);
  try{$bundle=json_decode($text,false,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new CommunityAnalysisError(422,'This JSON file could not be read. Choose an original ViperScout .dat.json export.');}
  if(!$bundle instanceof stdClass)throw new CommunityAnalysisError(422,'Choose a ViperScout event bundle, not a JSON array of unknown records.');
  $scouting=[];$events=[];
  foreach(get_object_vars($bundle) as $key=>$value){
   if(preg_match('~^/data/(2026[a-z0-9-]{1,60})\.~D',$key,$m))$events[$m[1]]=true;
   if(preg_match('~^/data/(2026[a-z0-9-]{1,60})\.scouting\.csv$~D',$key,$m))$scouting[$m[1]]=$value;
  }
  if(count($scouting)!==1||count($events)!==1)throw new CommunityAnalysisError(422,'Choose a ViperScout JSON bundle containing scouting data for exactly one 2026 event.');
  $embedded=(string)array_key_first($scouting);$aliases=['2026ne-dcmp-burns-195'=>'2026necmp1'];$canonical=$aliases[$embedded]??$embedded;if($event!==''&&$event!==$embedded&&$event!==$canonical)throw new CommunityAnalysisError(422,'This JSON bundle belongs to '.$embedded.'. Use that event code so its matches are not assigned to another event.');
  if(!is_string($scouting[$embedded]))throw new CommunityAnalysisError(422,'The embedded scouting CSV must be text.');
  $parsed=self::parseCsv($scouting[$embedded],$canonical,$offset);
  // Deduplicate CSV/JSON versions by the embedded scouting bytes, not unrelated bundle data.
  // Pit forms, names, team contacts, arbitrary paths and bundled URLs are never persisted or fetched.
  $parsed['report']['source_event_key']=$embedded;$parsed['report']['canonical_event_key']=$canonical;
  if($canonical!==$embedded)$parsed['report']['event_mapping_note']='Source event '.$embedded.' maps to '.$canonical.' (Burns Division). Every match roster and video must still pass verification.';
  $parsed['report']['input_format']='viper-event-json';$parsed['report']['bundle_sha256']=hash('sha256',$contents);
  $metadata=$bundle->{'/data/'.$embedded.'.event.csv'}??null;
  if(is_string($metadata)){
   $stream=fopen('php://temp','r+');fwrite($stream,$metadata);rewind($stream);$header=fgetcsv($stream,0,',','"','');$values=fgetcsv($stream,0,',','"','');fclose($stream);
   if(is_array($header)&&is_array($values)&&count($header)===count($values)&&count($header)===count(array_unique($header))){$meta=array_combine($header,$values);
    foreach(['start','end'] as $key)if(preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',(string)($meta[$key]??'')))$parsed['report']['event_'.$key]=$meta[$key];
    if(isset($meta['name']))$parsed['report']['event_name']=mb_substr((string)$meta['name'],0,200);
   }
  }
  return $parsed;
 }
 private static function parseCsv(string $csv,string $event,int $offset=0):array {
  if(!preg_match('/^2026[a-z0-9]{1,60}$/D',$event))throw new CommunityAnalysisError(422,'This importer supports 2026 ViperScout exports. Enter the event code for this CSV, such as 2026week0.');
  if(strlen($csv)>8*1024*1024||abs($offset)>30000)throw new CommunityAnalysisError(422,'Choose a CSV under 8 MB and a clock correction within 30 seconds.');
  if(!mb_check_encoding($csv,'UTF-8'))throw new CommunityAnalysisError(422,'Save this CSV as UTF-8 and upload it again.');
  $stream=fopen('php://temp','r+');fwrite($stream,$csv);rewind($stream);$header=fgetcsv($stream,0,',','"','');if(!$header)throw new CommunityAnalysisError(422,'The CSV is empty.');$header[0]=ltrim($header[0],"\xef\xbb\xbf");
  foreach(['match','team','auto_fuel_score','tele_fuel_score'] as $key)if(!in_array($key,$header,true))throw new CommunityAnalysisError(422,'This is not a supported ViperScout scouting CSV: missing '.$key.'.');
  if(count($header)!==count(array_unique($header)))throw new CommunityAnalysisError(422,'The CSV has duplicate column names.');
  $rows=[];$issues=[];$line=1;$seen=[];$events=0;$fuel=0;$matches=[];$private=['scouter','created','modified'];
  while(($values=fgetcsv($stream,0,',','"',''))!==false){$line++;if($values===[null])continue;if(count($values)!==count($header)){ $issues[]=['line'=>$line,'problem'=>'Wrong column count'];continue;}
   $r=array_combine($header,$values);$match=(string)$r['match'];$team=filter_var($r['team'],FILTER_VALIDATE_INT);if(!preg_match('/^(?:qm[1-9][0-9]*|(?:ef|qf|sf|f)[1-9][0-9]*m[1-9][0-9]*)$/D',$match)||!$team||$team<1||$team>99999){$issues[]=['line'=>$line,'problem'=>'Invalid match or robot'];continue;}
   $fields=[];foreach($r as $key=>$value)if(!in_array($key,$private,true)&&preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$key)){$fields[$key]=mb_substr((string)$value,0,30000);}ksort($fields);
   $timeline=[];$warnings=[];foreach(preg_split('/\s+/',trim((string)($r['timeline']??''))) as $index=>$token){if($token==='')continue;if(!preg_match('/^([0-9]{1,4}):([a-z][a-z0-9_]{0,79})(?::([a-z0-9_-]{1,30}))?$/D',$token,$m)){$warnings[]='Malformed timeline entry';continue;}$time=(int)$m[1];$timeline[]=['seconds'=>$time,'action'=>$m[2],'value'=>$m[3]??null,'index'=>$index];$events++;if(in_array($m[2],['auto_fuel_score','tele_fuel_score'],true))$fuel++;}
   foreach(['auto_fuel_score','tele_fuel_score','auto_climb_level','tele_climb_level'] as $key)if(isset($r[$key])&&!preg_match('/^[0-9]{1,5}$/D',(string)$r[$key]))$warnings[]='Invalid numeric field: '.$key;
   $auto=(int)($r['auto_alliance_time']??0)+(int)($r['auto_neutral_time']??0);$tele=(int)($r['tele_alliance_time']??0)+(int)($r['tele_neutral_time']??0)+(int)($r['tele_opponent_time']??0);if($auto>22||$tele>142)$warnings[]='Zone totals exceed period length; possible merged or mistimed record';
   $payload=['fields'=>$fields,'timeline'=>$timeline,'warnings'=>array_values(array_unique($warnings))];$hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));$unique=$match.':'.$team.':'.$hash;if(isset($seen[$unique])){ $issues[]=['line'=>$line,'problem'=>'Exact duplicate row omitted'];continue;}$seen[$unique]=true;$matches[$match]=true;
   $rows[]=['match_key'=>$event.'_'.$match,'team_number'=>$team,'record_sha256'=>$hash,'payload'=>$payload];if(count($rows)>20000)throw new CommunityAnalysisError(422,'Split this export into files of at most 20,000 robot records.');
  }fclose($stream);if(!$rows)throw new CommunityAnalysisError(422,'No valid robot records were found.');
  return ['adapter_version'=>self::ADAPTER,'event_key'=>$event,'file_sha256'=>hash('sha256',$csv),'timing_offset_ms'=>$offset,'records'=>$rows,'report'=>['timeline_available'=>in_array('timeline',$header,true),'robot_records'=>count($rows),'matches'=>count($matches),'timeline_entries'=>$events,'fuel_entries'=>$fuel,'issues'=>$issues,'warning_records'=>count(array_filter($rows,fn($r)=>!empty($r['payload']['warnings']))),'private_columns_removed'=>$private,'point_targets'=>count(array_filter($rows,fn($r)=>self::reportedPoints($r['payload'])!==null)),'note'=>'Fuel entries are counted scoring activity, not scoring-sequence starts or individual points. Viper’s timer uses 20 seconds of auto plus a 3-second transition. Timing is approximate until joined to video clock anchors.']];
 }
 public function videoCheck(array $parsed):array {
  $groups=[];foreach($parsed['records'] as $row)$groups[$row['match_key']][(int)$row['team_number']]=true;
  uksort($groups,'strnatcmp');$checks=[];$found=0;$linked=0;
  foreach($groups as $key=>$teams){
   $match=$this->q('SELECT source_url,roster_json FROM community_matches WHERE match_key=?',[$key])->fetch(PDO::FETCH_ASSOC);
   $video=$match?CommunityVideoTags::videoId((string)$match['source_url']):null;$reason='';if($video)$linked++;
   if(!$video)$reason='No video files found for this match.';
   else{$roster=json_decode($match['roster_json'],true);$actual=array_merge($roster['red']??[],$roster['blue']??[]);$reported=array_keys($teams);sort($actual);sort($reported);
    if(count($actual)!==6||count(array_unique($actual))!==6||array_diff($reported,$actual)||(count($reported)>=6&&$reported!==$actual))$reason='The scouting robots do not match the video catalog roster.';
   }
   $ok=$reason==='';if($ok)$found++;
   $checks[]=['match_key'=>$key,'ready'=>$ok,'video_url'=>$video?$match['source_url']:null,'reason'=>$reason];
  }
  return ['ready'=>count($checks)>0&&$found===count($checks),'matches_found'=>$found,'matches_total'=>count($checks),'matches'=>$checks,'message'=>$linked===0?'No video files found. This source will not be imported.':($found<count($checks)?'Import blocked: some matches have no matching video or roster. Nothing from this file will be saved.':'A matching video link was found for every match. The helper still verifies the footage and clock before training.')];
 }
 public function archiveEvent(string $event,int $season,bool $restore=false):void {
  if(!preg_match('/^'.preg_quote((string)$season,'/').'[a-z0-9]{1,60}$/D',$event))throw new CommunityAnalysisError(422,'Choose an event from this game.');
  $this->q('UPDATE community_external_imports SET enabled=? WHERE event_key=? AND season=?'.($restore?' AND enabled=-1':''),[$restore?0:-1,$event,$season]);
 }
 public function save(array $parsed,int $user,string $source):array {
  $provider=$parsed['provider']??'viperscout';$adapter=$parsed['adapter_version']??self::ADAPTER;
  if($provider==='mapped'){if(!filter_var($source,FILTER_VALIDATE_URL)||parse_url($source,PHP_URL_SCHEME)!=='https'||parse_url($source,PHP_URL_USER)||strlen($source)>1000)throw new CommunityAnalysisError(422,'Enter an HTTPS attribution link without credentials. Neptune does not fetch it.');}
  elseif(!preg_match('~^https://(?:public\.)?viperscout\.com/(?:export|event)\.html#event='.preg_quote($parsed['report']['source_event_key']??$parsed['event_key'],'~').'$~D',$source))throw new CommunityAnalysisError(422,'Use the ViperScout source link for this same event. The link records attribution; Neptune does not fetch arbitrary URLs.');
  $videos=$this->videoCheck($parsed);if(!$videos['ready'])throw new CommunityAnalysisError(422,$videos['message']);$parsed['report']['video_check']=$videos;
  $prior=$this->q('SELECT id FROM community_external_imports WHERE provider=? AND event_key=? AND file_sha256=? AND timing_offset_ms=?',[$provider,$parsed['event_key'],$parsed['file_sha256'],$parsed['timing_offset_ms']])->fetchColumn();if($prior)return ['id'=>$prior,'existing'=>true];
  $id=bin2hex(random_bytes(16));$this->db->beginTransaction();try{
   $this->q('INSERT INTO community_external_imports(id,season,event_key,provider,adapter_version,source_url,file_sha256,timing_offset_ms,report_json,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,2026,$parsed['event_key'],$provider,$adapter,$source,$parsed['file_sha256'],$parsed['timing_offset_ms'],json_encode($parsed['report'],JSON_THROW_ON_ERROR),$user,time()]);
   foreach($parsed['records'] as $r)$this->q('INSERT INTO community_external_observations(id,import_id,match_key,team_number,record_sha256,payload_json) VALUES(?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$id,$r['match_key'],$r['team_number'],$r['record_sha256'],json_encode($r['payload'],JSON_THROW_ON_ERROR)]);
   $this->db->commit();return ['id'=>$id,'existing'=>false];
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function toggle(string $id,bool $enabled,int $season):void{$this->q('UPDATE community_external_imports SET enabled=? WHERE id=? AND season=? AND enabled<>-1',[$enabled?1:0,$id,$season]);}
 public function records(string $id,int $season,int $offset=0):array {
  $rows=$this->q('SELECT o.match_key,o.team_number,o.payload_json FROM community_external_observations o JOIN community_external_imports i ON i.id=o.import_id WHERE i.id=? AND i.season=? ORDER BY o.match_key,o.team_number LIMIT 101 OFFSET '.max(0,$offset),[$id,$season])->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>100;$rows=array_slice($rows,0,100);foreach($rows as &$r){$r['payload']=json_decode($r['payload_json'],true);unset($r['payload_json']);}unset($r);return ['records'=>$rows,'more'=>$more];
 }
 public function imports(int $season):array {
  if(!community_external_scouting_available($this->db))return [];
  $rows=$this->q('SELECT id,event_key,provider,source_url,enabled,report_json,created_at FROM community_external_imports WHERE season=? ORDER BY created_at DESC,id DESC',[$season])->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$r){$r['archived']=(int)$r['enabled']===-1;$r['enabled']=(int)$r['enabled']===1;$r['report']=json_decode($r['report_json'],true);unset($r['report_json']);}unset($r);return $rows;
 }
 public function resolved(int $season):array {
  if(!community_external_scouting_available($this->db))return ['records'=>[],'problems'=>[]];
  $rows=$this->q('SELECT o.*,i.event_key,i.source_url attribution_url,i.timing_offset_ms,i.created_at,i.adapter_version FROM community_external_observations o JOIN community_external_imports i ON i.id=o.import_id WHERE i.season=? AND i.enabled=1 ORDER BY i.created_at,o.id',[$season])->fetchAll(PDO::FETCH_ASSOC);$groups=[];$reported=[];foreach($rows as $r){$groups[$r['match_key'].':'.$r['team_number']][$r['record_sha256'].':'.$r['timing_offset_ms']]=$r;$reported[$r['import_id']][$r['match_key']][(int)$r['team_number']]=true;}
  $out=[];$problems=[];foreach($groups as $versions){$r=reset($versions);if(count($versions)>1){$problems[]=['match_key'=>$r['match_key'],'team'=>$r['team_number'],'reason'=>'Conflicting active imports; exclude one import to choose the intended version'];continue;}
   $m=$this->q('SELECT id,source_url,roster_json FROM community_matches WHERE match_key=?',[$r['match_key']])->fetch(PDO::FETCH_ASSOC);$roster=$m?json_decode($m['roster_json'],true):[];$vid=$m?CommunityVideoTags::videoId($m['source_url']):null;
   if(!$m||!$vid){$problems[]=['match_key'=>$r['match_key'],'team'=>$r['team_number'],'reason'=>'No linked match video in Neptune’s catalog'];continue;}if(!in_array((int)$r['team_number'],array_merge($roster['red']??[],$roster['blue']??[]),true)){ $problems[]=['match_key'=>$r['match_key'],'team'=>$r['team_number'],'reason'=>'Robot does not match the catalog roster'];continue;}
   $allTeams=array_merge($roster['red']??[],$roster['blue']??[]);$sourceTeams=array_keys($reported[$r['import_id']][$r['match_key']]);sort($allTeams);sort($sourceTeams);if(count($sourceTeams)>=6&&$sourceTeams!==$allTeams){$problems[]=['match_key'=>$r['match_key'],'team'=>$r['team_number'],'reason'=>'The full source-match roster does not match Neptune’s catalog'];continue;}
   $r['match']=$m;$r['roster']=$roster;$r['video_id']=$vid;$r['payload']=json_decode($r['payload_json'],true);unset($r['payload_json']);$out[]=$r;
  }return ['records'=>$out,'problems'=>$problems];
 }
 public static function reportedPoints(array $payload):?array {
  if(!empty($payload['warnings'])||($payload['fields']['review_requested']??'0')!=='0')return null;
  $f=$payload['fields'];$values=[];
  foreach(['auto_fuel_score','tele_fuel_score','auto_climb_level','tele_climb_level'] as $k){if(!isset($f[$k])||!preg_match('/^[0-9]{1,5}$/D',(string)$f[$k]))return null;$values[$k]=(int)$f[$k];}
  if($values['auto_climb_level']>1||$values['tele_climb_level']>3||$values['auto_fuel_score']>10000||$values['tele_fuel_score']>10000)return null;
  $points=$values['auto_fuel_score']+$values['tele_fuel_score']+15*$values['auto_climb_level']+[0,10,20,30][$values['tele_climb_level']];
  return ['robot_points'=>$points,'components'=>$values,'rules_source'=>'https://firstfrc.blob.core.windows.net/frc2026/Manual/HTML/2026GameManual.htm'];
 }
 public function training(int $season):array {
  $resolved=$this->resolved($season);$out=[];foreach($resolved['records'] as $r){$p=$r['payload'];if(($p['fields']['review_requested']??'0')!=='0')continue;$climbs=array_values(array_filter($p['timeline'],fn($e)=>$e['action']==='tele_climb_level'));$lastClimb=$climbs?end($climbs)['index']:-1;$climbTotal=array_sum(array_map(fn($e)=>ctype_digit((string)$e['value'])?(int)$e['value']:1,$climbs));
   if(($p['format']??'')==='mapped-actions-v1'){
    foreach($p['timeline'] as $e){$tag=$e['action'];$c=community_video_tag_catalog()[$tag]??null;if(!$c)continue;
     $out[]=['id'=>substr(hash('sha256','mapped|'.$r['match_key'].'|'.$r['team_number'].'|'.$r['record_sha256'].'|'.$r['timing_offset_ms'].'|'.$e['index']),0,32),'schema_version'=>2,'season'=>$season,'game_key'=>'2026-rebuilt','evidence_type'=>'external_scouting_label','time_basis'=>'phase_elapsed_ms','match_id'=>(int)$r['match']['id'],'match_key'=>$r['match_key'],'source_url'=>$r['match']['source_url'],'source_sha256'=>null,'video_id'=>$r['video_id'],'roster'=>$r['roster'],'team_number'=>(int)$r['team_number'],'tag_key'=>$tag,'tag_label'=>$c['label'],'tag_category'=>$c['category'],'definition_version'=>'mapped-actions-v1','label_scope'=>'moment','start_ms'=>$e['time_ms'],'end_ms'=>$e['time_ms'],'video_duration_ms'=>0,'certainty'=>'observed','polarity'=>'present','note'=>'Mapped outside observer action; not independent evaluation truth.','review_state'=>'contributed','created_at'=>(int)$r['created_at'],'scoring'=>null,'external'=>['provider'=>'mapped','adapter_version'=>'mapped-actions-v1','record_sha256'=>$r['record_sha256'],'source_url'=>$r['attribution_url'],'phase'=>$e['phase'],'kind'=>'reported_action','quantity'=>1,'source_seconds'=>$e['seconds'],'timing_offset_ms'=>(int)$r['timing_offset_ms'],'weak_supervision'=>true]];
    }continue;
   }
   $pointTarget=self::reportedPoints($p);
   if($pointTarget!==null){$c=community_video_tag_catalog()['scoring'];$out[]=['id'=>substr(hash('sha256','viper-points|'.$r['match_key'].'|'.$r['team_number'].'|'.$r['record_sha256']),0,32),'schema_version'=>2,'season'=>$season,'game_key'=>'2026-rebuilt','evidence_type'=>'external_scouting_label','time_basis'=>'video_elapsed_ms','match_id'=>(int)$r['match']['id'],'match_key'=>$r['match_key'],'source_url'=>$r['match']['source_url'],'source_sha256'=>null,'video_id'=>$r['video_id'],'roster'=>$r['roster'],'team_number'=>(int)$r['team_number'],'tag_key'=>'scoring','tag_label'=>$c['label'],'tag_category'=>$c['category'],'definition_version'=>'viper-2026-points-v1','label_scope'=>'match','start_ms'=>null,'end_ms'=>null,'video_duration_ms'=>0,'certainty'=>'uncertain','polarity'=>'present','note'=>'Estimate from observer-reported scored fuel and completed climb levels. Not an official robot score; penalties remain unattributed.','review_state'=>'contributed','created_at'=>(int)$r['created_at'],'scoring'=>['schema_version'=>1,'source'=>'external_scouting_estimate','mode'=>'alliance_share','method'=>'estimated','robot_points'=>$pointTarget['robot_points'],'alliance_score'=>null,'count_min'=>null,'count_max'=>null,'score_basis'=>'full_final_alliance_score'],'external'=>['provider'=>'viperscout','adapter_version'=>'viper-2026-points-v1','record_sha256'=>$r['record_sha256'],'source_url'=>$r['attribution_url'],'kind'=>'reported_score_total','components'=>$pointTarget['components'],'rules_source'=>$pointTarget['rules_source'],'weak_supervision'=>true]];}
   foreach($p['timeline'] as $e){$phase=str_starts_with($e['action'],'auto_')?'auto':'teleop';$time=$e['seconds']*1000-($phase==='auto'?0:23000)+(int)$r['timing_offset_ms'];$tag=$e['action']==='auto_fuel_score'||$e['action']==='tele_fuel_score'?'scoring':null;$kind='counted_scoring_activity';
    // Only the final recorded teleop climb height supports a completed climb event.
    if($e['action']==='tele_climb_level'&&$e['index']===$lastClimb&&$climbTotal>0&&$climbTotal<=3&&$climbTotal===(int)($p['fields']['tele_climb_level']??0)){$tag='end_completed';$kind='reported_climb_completion';}
    if($tag==='scoring'&&$e['value']!==null&&(!ctype_digit($e['value'])||(int)$e['value']<1||(int)$e['value']>1000))continue;
    if(!$tag||$time<0||$time>($phase==='auto'?20000:140000))continue;$catalog=community_video_tag_catalog()[$tag];$label=substr(hash('sha256','viper|'.$r['match_key'].'|'.$r['team_number'].'|'.$r['record_sha256'].'|'.$r['timing_offset_ms'].'|'.$e['index']),0,32);
    $out[]=['id'=>$label,'schema_version'=>2,'season'=>$season,'game_key'=>'2026-rebuilt','evidence_type'=>'external_scouting_label','time_basis'=>'phase_elapsed_ms','match_id'=>(int)$r['match']['id'],'match_key'=>$r['match_key'],'source_url'=>$r['match']['source_url'],'source_sha256'=>null,'video_id'=>$r['video_id'],'roster'=>$r['roster'],'team_number'=>(int)$r['team_number'],'tag_key'=>$tag,'tag_label'=>$catalog['label'],'tag_category'=>$catalog['category'],'definition_version'=>self::ADAPTER,'label_scope'=>'moment','start_ms'=>$time,'end_ms'=>$time,'video_duration_ms'=>0,'certainty'=>'observed','polarity'=>'present','note'=>'Imported observer activity; approximate source timer. This is not a community scoring-start annotation or an accuracy reference.','review_state'=>'contributed','created_at'=>(int)$r['created_at'],'scoring'=>null,'external'=>['provider'=>'viperscout','adapter_version'=>self::ADAPTER,'record_sha256'=>$r['record_sha256'],'source_url'=>$r['attribution_url'],'phase'=>$phase,'kind'=>$kind,'quantity'=>ctype_digit((string)$e['value'])?(int)$e['value']:1,'source_seconds'=>$e['seconds'],'timing_offset_ms'=>(int)$r['timing_offset_ms'],'weak_supervision'=>true]];
   }
  }return $out;
 }
}
