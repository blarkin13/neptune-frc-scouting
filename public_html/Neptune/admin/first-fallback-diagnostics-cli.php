<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_tba_scheduler.php';
require_once __DIR__.'/_tba_auto_discovery.php';

/** Read-only QA diagnostics: no event links, TBA API calls, or data writes. */
$org=max(1,(int)($argv[1]??1));
$eventId=max(1,(int)($argv[2]??11));
function nfd_one(PDO $pdo,string $sql,array $args=[]): mixed {$s=$pdo->prepare($sql);$s->execute($args);return $s->fetchColumn();}
function nfd_table(PDO $pdo,string $name): bool {
    return (int)nfd_one($pdo,'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$name])>0;
}
function nfd_report(string $name,mixed $value): void {echo str_pad($name.':',29).(is_scalar($value)?(string)$value:json_encode($value,JSON_UNESCAPED_SLASHES))."\n";}

$hb=neptune_tba_scheduler_read_heartbeat($pdo);
$hbTs=strtotime((string)($hb['finished_at']??$hb['started_at']??$hb['written_at']??''));
$age=$hbTs!==false?max(0,time()-$hbTs):null;
nfd_report('Scheduler heartbeat age',$age===null?'NOT FOUND':$age.' seconds');
nfd_report('Scheduler heartbeat state',$hb['status']??'NOT FOUND');
nfd_report('Scheduler heartbeat health',$age===null?'NOT VERIFIED':($age>300?'STALE - investigate cron/systemd':'RECENT'));
nfd_report('Last scheduler message',$hb['message']??'—');
$s=$pdo->prepare("SELECT e.id,e.name,e.event_code,e.tba_event_key,e.is_current,g.season_year
    FROM events e JOIN games g ON g.id=e.game_id WHERE e.organization_id=? AND e.id=? LIMIT 1");
$s->execute([$org,$eventId]);$ev=$s->fetch(PDO::FETCH_ASSOC);
if(!$ev){fwrite(STDERR,"No event #$eventId in organization #$org.\n");exit(2);}
nfd_report('Neptune event',$ev['name'].' (#'.$eventId.')');
nfd_report('FIRST link table',nfd_table($pdo,'neptune_first_event_links')?'PRESENT':'MISSING');
if(nfd_table($pdo,'neptune_first_event_links')){
    $q=$pdo->prepare('SELECT first_event_code,last_tba_discovery_at,tba_discovery_status,tba_discovery_note,tba_discovery_candidate FROM neptune_first_event_links WHERE organization_id=? AND event_id=?');
    $q->execute([$org,$eventId]);$link=$q->fetch(PDO::FETCH_ASSOC)?:[];
    nfd_report('FIRST code',$link['first_event_code']??'NOT LINKED');
    nfd_report('TBA discovery status',$link['tba_discovery_status']??'NOT RUN');
    nfd_report('TBA discovery time',$link['last_tba_discovery_at']??'NOT RUN');
    nfd_report('TBA discovery candidate',$link['tba_discovery_candidate']??'—');
    nfd_report('TBA discovery note',$link['tba_discovery_note']??'—');
}
nfd_report('TBA event key',$ev['tba_event_key']?:'NOT LINKED');
nfd_report('Current event',(int)$ev['is_current']?'YES':'NO');
$state=neptune_tba_live_state($pdo,$org);
nfd_report('At Event Live',$state['enabled']?'ON':'OFF');
nfd_report('Last live attempt',$state['last_live_attempt_at']??'—');
nfd_report('Last live success',$state['last_live_success_at']??'—');
nfd_report('Last live message',$state['last_live_message']??'—');
foreach(['cron_tba_discovery','cron_tba_live','cron_first_live'] as $action){
    $q=$pdo->prepare('SELECT created_at,metadata_json FROM audit_log WHERE organization_id=? AND action=? ORDER BY id DESC LIMIT 1');
    $q->execute([$org,$action]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $meta=$row?json_decode((string)$row['metadata_json'],true):[];
    nfd_report('Last '.$action,$row?($row['created_at'].' · '.(string)($meta['status']??'?').' · '.(string)($meta['summary']??'')):'NOT RUN');
}
if(nfd_table($pdo,'augur_first_event_ratings'))nfd_report('FIRST-rated teams',(int)nfd_one($pdo,'SELECT COUNT(*) FROM augur_first_event_ratings WHERE organization_id=? AND event_id=?',[$org,$eventId]));
if(nfd_table($pdo,'augur_first_match_ratings'))nfd_report('FIRST EPA snapshots',(int)nfd_one($pdo,'SELECT COUNT(*) FROM augur_first_match_ratings WHERE organization_id=? AND event_id=?',[$org,$eventId]));
$q=$pdo->prepare("SELECT COUNT(*) total, SUM(red_score IS NOT NULL AND blue_score IS NOT NULL) scored, COUNT(DISTINCT tba_match_key) tba_owned
    FROM matches WHERE organization_id=? AND event_id=? AND comp_level='qm'");
$q->execute([$org,$eventId]);$counts=$q->fetch(PDO::FETCH_ASSOC)?:[];
nfd_report('Qualification matches',(int)($counts['total']??0));
nfd_report('Scored qualifications',(int)($counts['scored']??0));
nfd_report('TBA-owned qualifications',(int)($counts['tba_owned']??0));
echo "NOTE: Recent heartbeat confirms scheduler execution, not which OS cron/systemd unit invokes it.\n";
