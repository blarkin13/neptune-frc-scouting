<?php
/**
 * Connection Guard rehearsal report: actions sent vs rows stored.
 *
 *   php admin/connection-guard-rehearsal-cli.php --event=21 --since='2026-10-20 14:00:00' [ledger.json ...]
 *
 * --since is UTC (receipts and recorded_at are stored in UTC). Ledger files are the
 * downloads from NeptuneConnectionGuard.ledgerExport() on each rehearsal device.
 *
 * Without ledgers it still checks the server side: receipts whose action row is
 * missing, and stored actions that never went through Connection Guard.
 * With ledgers it accounts for every action each device sent:
 *   stored + still on the device must equal sent; anything else is LOST.
 * Exit code 1 when anything is lost or missing, so it can gate a release.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';

$opts=getopt('',['event:','since:'],$rest);
$eventId=(int)($opts['event']??0);
$since=(string)($opts['since']??'');
if($eventId<1||strtotime($since.' UTC')===false){fwrite(STDERR,"Usage: php ".$argv[0]." --event=ID --since='YYYY-MM-DD HH:MM:SS' [ledger.json ...]\n");exit(2);}
$since=gmdate('Y-m-d H:i:s',strtotime($since.' UTC'));
$ledgerFiles=array_slice($argv,$rest);

$ev=$pdo->prepare('SELECT id,organization_id,name FROM events WHERE id=?');$ev->execute([$eventId]);$event=$ev->fetch(PDO::FETCH_ASSOC);
if(!$event){fwrite(STDERR,"Event $eventId not found.\n");exit(2);}
echo "Event {$event['id']} · {$event['name']} · since $since UTC\n\n";
$problems=0;

// Receipts written by Connection Guard in the window, with the action id each one reported.
$receipts=[];
$q=$pdo->prepare("SELECT request_uuid,session_hash,response_code,response_body,created_at FROM action_request_receipts WHERE created_at>=?");
$q->execute([$since]);
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
    $body=json_decode((string)$r['response_body'],true);
    $r['action_id']=is_array($body)?(int)($body['id']??0):0;
    $receipts[strtolower((string)$r['request_uuid'])]=$r;
}

// Stored actions for the event in the window.
$q=$pdo->prepare("SELECT a.id,a.scout_session_id,a.deleted_at,ss.user_id,u.display_name,m.match_number,ss.alliance,ss.station
  FROM scouting_actions a
  LEFT JOIN scout_sessions ss ON ss.id=a.scout_session_id
  LEFT JOIN users u ON u.id=ss.user_id
  LEFT JOIN matches m ON m.id=a.match_id
  WHERE a.organization_id=? AND a.event_id=? AND a.recorded_at>=? AND a.source='scout'");
$q->execute([(int)$event['organization_id'],$eventId,$since]);
$actions=[];
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $a)$actions[(int)$a['id']]=$a;

$receiptByAction=[];
foreach($receipts as $u=>$r)if($r['action_id']>0)$receiptByAction[$r['action_id']]=$u;

// 1. Server side.
$perScout=[];
foreach($actions as $id=>$a){
    $k=($a['display_name']??'?').' · M'.($a['match_number']??'?').' '.($a['alliance']??'').($a['station']??'');
    $perScout[$k]['stored']=($perScout[$k]['stored']??0)+1;
    if(!isset($receiptByAction[$id]))$perScout[$k]['no_receipt']=($perScout[$k]['no_receipt']??0)+1;
}
ksort($perScout);
echo "Stored actions by scout and match:\n";
foreach($perScout as $k=>$c)printf("  %-44s %4d stored%s\n",$k,$c['stored'],!empty($c['no_receipt'])?'  ('.$c['no_receipt'].' sent without Connection Guard)':'');
$missingRows=[];
foreach($receipts as $u=>$r){
    if($r['action_id']<1)continue;
    $chk=$pdo->prepare('SELECT event_id,deleted_at FROM scouting_actions WHERE id=?');$chk->execute([$r['action_id']]);$row=$chk->fetch(PDO::FETCH_ASSOC);
    if(!$row)$missingRows[]=$u;
}
$bypass=count(array_filter($actions,static fn($a,$id)=>!isset($receiptByAction[$id]),ARRAY_FILTER_USE_BOTH));
printf("\nServer: %d actions stored, %d receipts since %s, %d receipts pointing at a missing row, %d actions without a receipt.\n",
    count($actions),count($receipts),$since,count($missingRows),$bypass);
if($missingRows){$problems++;echo "  MISSING ROWS for receipts: ".implode(', ',array_slice($missingRows,0,10))."\n"
    ."  The server confirmed these actions, then the rows disappeared. App code only soft-deletes actions, but deleting\n"
    ."  a match or event removes its actions (ON DELETE CASCADE). Do not reset the rehearsal event until this report passes.\n";}
if($bypass)echo "  Note: actions without a receipt were saved directly (page without Connection Guard, or IndexedDB unavailable, e.g. a private window).\n";

// 2. Device ledgers.
foreach($ledgerFiles as $file){
    $data=json_decode((string)@file_get_contents($file),true);
    if(!is_array($data)||!is_array($data['sent']??null)){echo "\n$file: not a ledger export\n";$problems++;continue;}
    $onDevice=[];foreach(($data['still_on_device']??[]) as $p)$onDevice[strtolower((string)$p['request_uuid'])]=$p;
    $sent=0;$stored=0;$waiting=0;$blocked=0;$lost=[];
    foreach($data['sent'] as $s){
        $u=strtolower((string)($s['request_uuid']??''));if($u==='')continue;
        if(strtotime((string)($s['created_at']??'')) < strtotime($since.' UTC'))continue;
        $sent++;
        if(isset($receipts[$u])&&isset($actions[$receipts[$u]['action_id']])){$stored++;continue;}
        if(isset($onDevice[$u])){$onDevice[$u]['blocked']?$blocked++:$waiting++;continue;}
        $lost[]=$u.' '.($s['label']??'');
    }
    printf("\n%s\n  device: %s\n  sent %d · stored %d · waiting on device %d · refused (kept on device) %d · LOST %d\n",
        basename($file),substr((string)($data['device']??'?'),0,90),$sent,$stored,$waiting,$blocked,count($lost));
    foreach(array_slice($lost,0,10) as $l)echo "    lost: $l\n";
    foreach($onDevice as $u=>$p)if(!empty($p['blocked'])||!empty($p['last_error']))echo "    on device: $u ".($p['blocked']?'refused':'waiting').' · '.(string)($p['last_error']??'')."\n";
    if($lost)$problems++;
}

echo "\n".($problems?"RESULT: PROBLEMS FOUND ($problems)":"RESULT: every action is accounted for")."\n";
exit($problems?1:0);
