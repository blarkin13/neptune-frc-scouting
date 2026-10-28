<?php
declare(strict_types=1);
require_once __DIR__.'/community-analysis.php';
require_once __DIR__.'/community-coverage.php';
function community_catalog_schema(PDO $pdo):void {
 neptune_migration_apply($pdo,'community.catalog.v1','Neptune_Community_Catalog_v1.zip',function()use($pdo):void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS community_match_catalog (match_id BIGINT UNSIGNED PRIMARY KEY, season INT NOT NULL,event_key VARCHAR(80) NOT NULL,video_id VARCHAR(32) NOT NULL DEFAULT '',updated_at BIGINT NOT NULL,KEY event_lookup(event_key),FOREIGN KEY(match_id) REFERENCES community_matches(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS community_attempt_media (attempt_id VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY,source_sha256 CHAR(64) NOT NULL,duration_ms INT NOT NULL,FOREIGN KEY(attempt_id) REFERENCES community_attempts(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 });
}
final class CommunityCatalog {
 public function __construct(private PDO $db,private mixed $fetch=null){if($this->fetch===null){require_once __DIR__.'/tba.php';$this->fetch=fn($path,$ttl)=>tba_get($path,$ttl);}}
 public static function sourceEvents():array {return json_decode((string)file_get_contents(__DIR__.'/community-source-videos.json'),true,64,JSON_THROW_ON_ERROR);}
 public static function videoNote(string $key,string $url):string {
  $known=json_decode((string)file_get_contents(__DIR__.'/community-broadcast-videos.json'),true,64,JSON_THROW_ON_ERROR)[$key]??null;
  return $known&&$url==='https://www.youtube.com/watch?v='.$known['video_id']?(string)($known['coverage_note']??''):'';
 }
 public static function season(int $year):void {if($year<2025||$year>(int)gmdate('Y')+1)throw new CommunityAnalysisError(422,'Choose a season from 2025 onward, up to next year.');}
 public function events(int $year):array {
  self::season($year);$events=($this->fetch)('events/'.$year.'/simple',3600);$seen=array_column($events,'key');foreach(self::sourceEvents() as $known)if(str_starts_with($known['key'],(string)$year)&&!in_array($known['key'],$seen,true))$events[]=$known;$out=[];
  foreach($events as $e){if(!is_array($e)||!preg_match('/^'.$year.'[a-z0-9]+$/D',(string)($e['key']??'')))continue;$out[]=['key'=>$e['key'],'name'=>(string)($e['name']??$e['key']),'start_date'=>$e['start_date']??null,'end_date'=>$e['end_date']??null,'location'=>implode(', ',array_filter([$e['city']??'',$e['state_prov']??'',$e['country']??'']))];}
  usort($out,fn($a,$b)=>strcmp(($a['start_date']??'9999').$a['name'],($b['start_date']??'9999').$b['name']));return $out;
 }
 public static function normalize(array $m,string $event,string $title):?array {
  if(!preg_match('/^'.preg_quote($event,'/').'_(?:qm[1-9][0-9]*|(?:ef|qf|sf|f)[1-9][0-9]*m[1-9][0-9]*)$/D',(string)($m['key']??'')))return null;
  $roster=['red'=>[],'blue'=>[]];foreach(['red','blue'] as $a)foreach($m['alliances'][$a]['team_keys']??[] as $t){if(preg_match('/^frc([1-9][0-9]*)$/D',(string)$t,$n))$roster[$a][]=(int)$n[1];}
  $all=array_merge($roster['red'],$roster['blue']);$valid=count($roster['red'])===3&&count($roster['blue'])===3&&count(array_unique($all))===6;
  $video='';foreach($m['videos']??[] as $v)if(($v['type']??'')==='youtube'&&preg_match('/^[A-Za-z0-9_-]{11}$/D',(string)($v['key']??''))){$video=$v['key'];break;}
  $preferences=json_decode((string)file_get_contents(__DIR__.'/community-broadcast-videos.json'),true,64,JSON_THROW_ON_ERROR);
  $preferred=$preferences[$m['key']]??null;
  if($preferred&&$roster===$preferred['roster'])foreach($m['videos']??[] as $candidate)if(($candidate['type']??'')==='youtube'&&($candidate['key']??'')===$preferred['video_id']){$video=$preferred['video_id'];break;}
  $labels=['qm'=>'Qualification','ef'=>'Octofinal','qf'=>'Quarterfinal','sf'=>'Semifinal','f'=>'Final'];$level=(string)($m['comp_level']??'');
  $label=($labels[$level]??'Match').' '.($level==='qm'?'':(int)($m['set_number']??0).'–').(int)($m['match_number']??0);
  return ['match_key'=>$m['key'],'title'=>mb_substr($title.' · '.$label,0,255),'roster'=>$roster,'video_id'=>$video,'ready'=>$valid&&$video!==''?1:0,'comp_level'=>$level,'set_number'=>(int)($m['set_number']??0),'match_number'=>(int)($m['match_number']??0),'missing_roster'=>!$valid];
 }
 public function matches(string $event):array {
  if(!preg_match('/^(20[0-9]{2})[a-z0-9]{1,60}$/D',$event,$n))throw new CommunityAnalysisError(422,'Invalid event.');self::season((int)$n[1]);
  $known=self::sourceEvents()[$event]??null;$meta=$known??($this->fetch)('event/'.$event.'/simple',3600);$rows=$known['matches']??($this->fetch)('event/'.$event.'/matches',300);$normalized=[];
  foreach($rows as $m){if(!is_array($m))continue;$r=self::normalize($m,$event,(string)($meta['name']??$event));if($r)$normalized[]=$r;}
  $levels=['qm'=>0,'ef'=>1,'qf'=>2,'sf'=>3,'f'=>4];usort($normalized,fn($a,$b)=>[$levels[$a['comp_level']]??9,$a['set_number'],$a['match_number']]<=>[$levels[$b['comp_level']]??9,$b['set_number'],$b['match_number']]);
  $insert=$this->db->prepare('INSERT INTO community_matches(match_key,title,roster_json,source_url,source_sha256,duration_ms,rules_text,ready,created_at) VALUES(?,?,?,?,\'\',0,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),roster_json=VALUES(roster_json),source_url=VALUES(source_url),ready=VALUES(ready)');
  $find=$this->db->prepare('SELECT id FROM community_matches WHERE match_key=?');$link=$this->db->prepare('INSERT INTO community_match_catalog(match_id,season,event_key,video_id,updated_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE video_id=VALUES(video_id),updated_at=VALUES(updated_at)');
  $status=$this->db->prepare("SELECT CASE WHEN EXISTS(SELECT 1 FROM community_publications WHERE match_id=?) THEN 'completed' WHEN EXISTS(SELECT 1 FROM community_submissions WHERE match_id=? AND review_state='pending') THEN 'awaiting_review' WHEN EXISTS(SELECT 1 FROM community_leases WHERE match_id=? AND expires_at>?) THEN 'in_progress' ELSE 'available' END");
  $out=[];$this->db->beginTransaction();try {foreach($normalized as $r){
   // Metadata already attached to a contributor's work must remain stable.
   $find->execute([$r['match_key']]);$id=$find->fetchColumn();
   if($id){$q=$this->db->prepare('SELECT id FROM community_matches WHERE id=? FOR UPDATE');$q->execute([$id]);$q=$this->db->prepare('SELECT 1 FROM community_attempts WHERE match_id=? LIMIT 1');$q->execute([$id]);$hasWork=(bool)$q->fetchColumn();}else $hasWork=false;
   if(!$hasWork){$insert->execute([$r['match_key'],$r['title'],json_encode($r['roster'],JSON_THROW_ON_ERROR),$r['video_id']!==''?'https://www.youtube.com/watch?v='.$r['video_id']:'','Season '.substr($event,0,4).'. Describe only visible autonomous, scoring activity, defense, reliability and endgame. Team identities must be supported by the video and roster. Preserve uncertainty; do not estimate scores or scoring shares.', $r['ready'],time()]);$find->execute([$r['match_key']]);$id=$find->fetchColumn();$link->execute([$id,(int)$n[1],$event,$r['video_id'],time()]);}
   $q=$this->db->prepare('SELECT title,roster_json,ready,source_url FROM community_matches WHERE id=?');$q->execute([$id]);$saved=$q->fetch(PDO::FETCH_ASSOC);
   $status->execute([$id,$id,$id,time()]);$work=$status->fetchColumn();if($work==='available'&&!(int)$saved['ready'])$work=$saved['source_url']===''?'video_needed':'roster_needed';
   $out[]=['id'=>(int)$id,'match_key'=>$r['match_key'],'title'=>$saved['title'],'roster'=>json_decode($saved['roster_json'],true),'work_status'=>$work,'video_url'=>$saved['source_url'],'video_note'=>self::videoNote($r['match_key'],$saved['source_url'])];
  }$this->db->commit();}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}$coverage=community_match_coverage($this->db,$out);foreach($out as &$match)$match['coverage']=$coverage[$match['id']];unset($match);return $out;
 }
}
