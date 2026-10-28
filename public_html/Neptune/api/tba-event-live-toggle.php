<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/admin/_first_event_links.php';
$u=require_role(['owner','admin','strategy']);
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'message'=>'POST required'],405);
verify_csrf();
$org=(int)$u['organization_id'];$eventId=(int)($_POST['event_id']??0);
if($eventId<1)json_response(['ok'=>false,'message'=>'Choose an event'],400);
$ready=(bool)$pdo->query("SHOW TABLES LIKE 'neptune_event_live_state'")->fetchColumn();
if(!$ready)json_response(['ok'=>false,'message'=>'Install v46 SQL first'],503);
$q=$pdo->prepare('SELECT id,name,active,tba_event_key FROM events WHERE organization_id=? AND id=? LIMIT 1');
$q->execute([$org,$eventId]);$ev=$q->fetch(PDO::FETCH_ASSOC);
if(!$ev)json_response(['ok'=>false,'message'=>'Event not found in this organization'],404);
$enabled=filter_var($_POST['enabled']??false,FILTER_VALIDATE_BOOLEAN);
if($enabled){
 if(!(int)$ev['active'])json_response(['ok'=>false,'message'=>'Unarchive this event first'],409);
 $key=trim((string)$ev['tba_event_key']);$first=neptune_first_link_code($pdo,$org,$eventId);
 if($key===''&&!preg_match('/^[A-Z0-9]{3,12}$/',$first))json_response(['ok'=>false,'message'=>'Link this event to TBA or confirm its FIRST event code first'],409);
}
$st=$pdo->prepare('INSERT INTO neptune_event_live_state (organization_id,event_id,enabled,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()');
$st->execute([$org,$eventId,$enabled?1:0,(int)$u['id']]);
json_response(['ok'=>true,'enabled'=>$enabled,'event'=>['id'=>$eventId,'name'=>$ev['name']]]);
