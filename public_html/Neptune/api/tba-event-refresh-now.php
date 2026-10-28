<?php
/** Refresh only the selected organization's event now, without enabling automatic polling. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/admin/_tba_live_sync.php';
require_once dirname(__DIR__).'/admin/_first_events.php';
require_once dirname(__DIR__).'/admin/_first_event_links.php';

$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];
// Read-only status is used by the page when the POST response is interrupted.
// Do not expose another organization's event or any credentials.
if($_SERVER['REQUEST_METHOD']==='GET' && (string)($_GET['status']??'')==='1'){
 $statusEventId=filter_var($_GET['event_id']??null,FILTER_VALIDATE_INT);
 if(!$statusEventId||$statusEventId<1)json_response(['ok'=>false,'message'=>'Invalid event.'],400);
 try{
  $statusQuery=$pdo->prepare('SELECT e.id,e.last_tba_sync_at,s.last_attempt_at,s.last_success_at,s.last_message FROM events e LEFT JOIN neptune_event_live_state s ON s.event_id=e.id AND s.organization_id=e.organization_id WHERE e.id=? AND e.organization_id=? LIMIT 1');
  $statusQuery->execute([$statusEventId,$org]);$statusRow=$statusQuery->fetch(PDO::FETCH_ASSOC);
  if(!$statusRow)json_response(['ok'=>false,'message'=>'Event not found.'],404);
  json_response(['ok'=>true,'last_imported_at'=>$statusRow['last_tba_sync_at']??null,'last_attempt_at'=>$statusRow['last_attempt_at']??null,'last_success_at'=>$statusRow['last_success_at']??null,'last_message'=>$statusRow['last_message']??null]);
 }catch(Throwable $ex){error_log('[Neptune manual refresh status] '.$ex->getMessage());json_response(['ok'=>false,'message'=>'Refresh status unavailable.'],500);}
}
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'message'=>'POST required.'],405);
verify_csrf();
$eventId=filter_var($_POST['event_id']??null,FILTER_VALIDATE_INT);
if(!$eventId||$eventId<1)json_response(['ok'=>false,'message'=>'Select an event first.'],400);

try{
 $ready=(bool)$pdo->query("SHOW TABLES LIKE 'neptune_event_live_state'")->fetchColumn();
 if(!$ready)json_response(['ok'=>false,'message'=>'Install the v46 event-live database migration first.'],503);
 $q=$pdo->prepare('SELECT e.id,e.name,e.active,e.tba_event_key,g.season_year FROM events e JOIN games g ON g.id=e.game_id WHERE e.id=? AND e.organization_id=? LIMIT 1');
 $q->execute([$eventId,$org]);$event=$q->fetch(PDO::FETCH_ASSOC);
 if(!$event)json_response(['ok'=>false,'message'=>'Event not found in your organization.'],404);
 if(!(int)$event['active'])json_response(['ok'=>false,'message'=>'Unarchive the event before refreshing it.'],409);
 $key=trim((string)($event['tba_event_key']??''));
 $firstCode=neptune_first_link_code($pdo,$org,$eventId);
 if(!preg_match('/^[A-Z0-9]{3,12}$/',$firstCode))$firstCode='';
 if($key===''&&$firstCode==='')json_response(['ok'=>false,'message'=>'Link this event to TBA or confirm a FIRST event code before refreshing.'],409);
}catch(Throwable $ex){error_log('[Neptune manual event refresh preflight] '.$ex->getMessage());json_response(['ok'=>false,'message'=>'Could not check the event configuration.'],500);}

// Hold the lock across TBA/FIRST imports AND scoreboard/EPA computations.
// The v54 scheduled runner uses the same lock; a different event has a different name.
$eventLock='neptune_event_live_'.$org.'_'.$eventId;
try{
 $lk=$pdo->prepare('SELECT GET_LOCK(?,0)');$lk->execute([$eventLock]);$hasLock=(int)$lk->fetchColumn()===1;
}catch(Throwable $ex){$hasLock=false;}
if(!$hasLock)json_response(['ok'=>false,'message'=>'This event is already refreshing. Wait for it to finish, then try again.'],409);

$result=[];$httpStatus=200;
try{
 @set_time_limit(120);
 // Upsert while preserving enabled state for an existing row (also supports Live OFF).
 $insert=$pdo->prepare('INSERT INTO neptune_event_live_state (organization_id,event_id,enabled,last_attempt_at,updated_by) VALUES (?,?,0,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE last_attempt_at=UTC_TIMESTAMP(),updated_by=VALUES(updated_by)');
 $insert->execute([$org,$eventId,(int)$u['id']]);

 $tba=null;$first=null;$tbaError='';$firstError='';
 if($key!==''){
  try{$tba=neptune_tba_refresh_linked_event($pdo,$org,$eventId,false);}
  catch(Throwable $ex){$tbaError=$ex->getMessage();}
 }
 $needsFirst=$key===''||$tba===null||(int)($tba['teams']??0)<1||(int)($tba['matches']??0)<1||(int)($tba['scored_matches']??0)<(int)($tba['matches']??0);
 if($needsFirst&&$firstCode!==''){
  $pdo->prepare('UPDATE neptune_event_live_state SET last_first_attempt_at=UTC_TIMESTAMP() WHERE organization_id=? AND event_id=?')->execute([$org,$eventId]);
  try{$first=neptune_first_sync($pdo,$org,$eventId,$firstCode,(int)$event['season_year']);}
  catch(Throwable $ex){$firstError=$ex->getMessage();}
 }
 if($tba===null&&$first===null){
  $details=implode(' | ',array_filter([$tbaError!==''?'TBA: '.$tbaError:'',$firstError!==''?'FIRST: '.$firstError:'']));
  throw new RuntimeException($details?:'No updated data was returned. Verify the TBA/FIRST event link.');
 }
 $source=$tba!==null&&$first!==null?'TBA + FIRST':($tba!==null?'TBA':'FIRST');
 $teamCount=(int)($tba['teams']??$first['teams']??0);
 $matchCount=(int)($tba['matches']??$first['matches']??0);
 $scoreCount=(int)($tba['scored_matches']??$first['scored']??0);
 $warning=implode(' | ',array_filter([$tbaError!==''?'TBA warning: '.$tbaError:'',$firstError!==''?'FIRST warning: '.$firstError:'']));
 $metricsNote=$tba!==null?' Public EPA/OPR recalculation was not run by this manual request.':'';
 $message=$source.' official event data refreshed: '.$teamCount.' teams, '.$matchCount.' matches, '.$scoreCount.' scored'.($warning!==''?' (FIRST/TBA warning recorded)':'').'.'.$metricsNote;
 $save=$pdo->prepare('UPDATE neptune_event_live_state SET last_success_at=UTC_TIMESTAMP(),last_message=? WHERE organization_id=? AND event_id=?');
 $save->execute([substr($message.($warning!==''?' '.$warning:''),0,480),$org,$eventId]);
 $result=['ok'=>true,'message'=>$message,'source'=>$source,'teams'=>$teamCount,'matches'=>$matchCount,'scored_matches'=>$scoreCount,'warning'=>$warning,'derived_metrics_recalculated'=>false];
}catch(Throwable $ex){
 if($pdo->inTransaction())$pdo->rollBack();
 $errorMessage='Refresh failed: '.$ex->getMessage();
 try{$save=$pdo->prepare('UPDATE neptune_event_live_state SET last_message=? WHERE organization_id=? AND event_id=?');$save->execute([substr($errorMessage,0,480),$org,$eventId]);}
 catch(Throwable $dbError){error_log('[Neptune refresh result save] '.$dbError->getMessage());}
 $result=['ok'=>false,'message'=>$errorMessage];$httpStatus=502;
}finally{
 try{$unlock=$pdo->prepare('SELECT RELEASE_LOCK(?)');$unlock->execute([$eventLock]);}
 catch(Throwable $ignored){}
}

try{
 $q=$pdo->prepare('SELECT s.last_attempt_at,s.last_success_at,s.last_message,e.last_tba_sync_at FROM neptune_event_live_state s JOIN events e ON e.id=s.event_id AND e.organization_id=s.organization_id WHERE s.organization_id=? AND s.event_id=? LIMIT 1');
 $q->execute([$org,$eventId]);$state=$q->fetch(PDO::FETCH_ASSOC)?:[];
 $result['last_attempt_at']=$state['last_attempt_at']??null;
 $result['last_success_at']=$state['last_success_at']??null;
 $result['last_message']=$state['last_message']??null;
 $result['last_imported_at']=$state['last_tba_sync_at']??null;
}catch(Throwable $ignored){}
json_response($result,$httpStatus);
