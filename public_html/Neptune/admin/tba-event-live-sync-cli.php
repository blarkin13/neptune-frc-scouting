<?php
/** Neptune v46 per-event live job. Run once per minute via cron; event state controls polling interval. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_tba_scheduler.php';
require_once __DIR__.'/_tba_live_sync.php';
require_once __DIR__.'/_first_events.php';
require_once __DIR__.'/_first_event_links.php';
if(!(bool)$pdo->query("SHOW TABLES LIKE 'neptune_event_live_state'")->fetchColumn()){fwrite(STDERR,"Install v46 SQL first.\n");exit(2);}
$lock=fopen(sys_get_temp_dir().'/neptune_event_live_'.substr(sha1(dirname(__DIR__,3)),0,12).'.lock','c+');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
$settings=neptune_tba_schedule_settings($pdo);$interval=max(1,min(60,(int)($settings['live_interval_minutes']??2)));
$q=$pdo->prepare("SELECT s.organization_id,s.event_id,e.name,e.tba_event_key,g.season_year
 FROM neptune_event_live_state s JOIN events e ON e.id=s.event_id AND e.organization_id=s.organization_id
 JOIN games g ON g.id=e.game_id
 WHERE s.enabled=1 AND e.active=1 AND (s.last_attempt_at IS NULL OR s.last_attempt_at <= UTC_TIMESTAMP()-INTERVAL ? MINUTE)
 ORDER BY COALESCE(s.last_attempt_at,'1900-01-01'),s.organization_id,s.event_id");
$q->execute([$interval]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
$mark=$pdo->prepare('UPDATE neptune_event_live_state SET last_attempt_at=UTC_TIMESTAMP() WHERE organization_id=? AND event_id=? AND enabled=1 AND (last_attempt_at IS NULL OR last_attempt_at <= UTC_TIMESTAMP()-INTERVAL ? MINUTE)');
$done=$pdo->prepare('UPDATE neptune_event_live_state SET last_success_at=UTC_TIMESTAMP(),last_message=? WHERE organization_id=? AND event_id=?');
$fail=$pdo->prepare('UPDATE neptune_event_live_state SET last_message=? WHERE organization_id=? AND event_id=?');
foreach($rows as $row){
 $org=(int)$row['organization_id'];$event=(int)$row['event_id'];
 // Match the manual Refresh Now endpoint's event-scoped MySQL advisory lock.
 // Separate events can refresh independently without a duplicate import for the same event.
 $eventLock='neptune_event_live_'.$org.'_'.$event;
 $lockQuery=$pdo->prepare('SELECT GET_LOCK(?,0)');
 $lockQuery->execute([$eventLock]);
 if((int)$lockQuery->fetchColumn()!==1)continue;
 try{
 $mark->execute([$org,$event,$interval]);if(!$mark->rowCount())continue;
 try{
  $tbaKey=trim((string)($row['tba_event_key']??''));$tba=null;$first=null;$tbaError='';$firstError='';
  if($tbaKey!=='')try{$tba=neptune_tba_refresh_linked_event($pdo,$org,$event);}catch(Throwable $ex){$tbaError=$ex->getMessage();}
  $firstCode=neptune_first_link_code($pdo,$org,$event);
  if(!preg_match('/^[A-Z0-9]{3,12}$/',$firstCode))$firstCode='';
  $needFirst=$tba===null||(int)($tba['teams']??0)<1||(int)($tba['matches']??0)<1||(int)($tba['scored_matches']??0)<(int)($tba['matches']??0);
  if($needFirst&&$firstCode!==''){
   // Separate event-scoped FIRST throttle; avoids hammering FIRST when live interval is 2 min.
   $fq=$pdo->prepare('SELECT last_first_attempt_at FROM neptune_event_live_state WHERE organization_id=? AND event_id=?');$fq->execute([$org,$event]);$last=$fq->fetchColumn();
   if(!$last||strtotime((string)$last)<time()-300){
    $pdo->prepare('UPDATE neptune_event_live_state SET last_first_attempt_at=UTC_TIMESTAMP() WHERE organization_id=? AND event_id=?')->execute([$org,$event]);
    try{$first=neptune_first_sync($pdo,$org,$event,$firstCode,(int)$row['season_year']);}catch(Throwable $ex){$firstError=$ex->getMessage();}
   }
  }
  if($tba===null&&$first===null&&($tbaError!==''||$firstError!==''))throw new RuntimeException(trim('TBA '.$tbaError.' FIRST '.$firstError));
  if($tba===null&&$first===null&&$firstCode===''&&$tbaKey==='')throw new RuntimeException('No TBA or FIRST event link');
  $msg=$tba!==null?'TBA updated':($first!==null?'FIRST updated':'FIRST rate limit; previous import retained');
  if($tba!==null&&$first!==null)$msg='TBA and FIRST updated';
  $done->execute([substr($msg,0,480),$org,$event]);echo "[ok] org=$org event=$event $msg\n";
 }catch(Throwable $ex){$msg=substr('ERROR: '.$ex->getMessage(),0,480);$fail->execute([$msg,$org,$event]);fwrite(STDERR,"[failed] org=$org event=$event $msg\n");}
 }finally{
  try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$eventLock]);}catch(Throwable $ignored){}
 }
}
