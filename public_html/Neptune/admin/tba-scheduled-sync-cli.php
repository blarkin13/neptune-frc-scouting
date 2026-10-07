<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_tba_scheduler.php';
require_once __DIR__.'/_tba_live_sync.php';
require_once dirname(__DIR__).'/analytics/_augur_opr.php';
require_once dirname(__DIR__).'/analytics/_augur_team_directory.php';

@set_time_limit(0);
if(!neptune_tba_scheduler_tables_ready($pdo)){
    fwrite(STDERR,"TBA scheduler tables are not installed. Import sql/2026-09-26_tba-scheduler.sql.\n");exit(2);
}

$lock=fopen(sys_get_temp_dir().'/neptune_tba_scheduler.lock','c+');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){exit(0);} // another minute is still running
ftruncate($lock,0);fwrite($lock,(string)getmypid());fflush($lock);

$runnerStarted=microtime(true);
$runnerStartedIso=gmdate(DATE_ATOM);
$runnerStatus='healthy';
$runnerMessage='Scheduler tick completed; no jobs were due.';
$runnerJobs=0;
$runnerFailures=0;
neptune_tba_scheduler_write_heartbeat($pdo,[
    'status'=>'running',
    'started_at'=>$runnerStartedIso,
    'finished_at'=>null,
    'message'=>'Scheduler tick started.',
    'jobs_run'=>0,
    'jobs_failed'=>0,
]);

function nts_clip(string $s,int $max=480): string{return substr(trim((string)preg_replace('/\s+/',' ',$s)),0,$max);}
function nts_utc_timestamp(?string $value): int {
    if(!$value)return 0;
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$value,new DateTimeZone('UTC'));
    return $dt?$dt->getTimestamp():0;
}
function nts_due(?string $last,int $minutes): bool {return nts_utc_timestamp($last)<=time()-max(1,$minutes)*60;}
function nts_utc_to_local(?string $value): ?DateTimeImmutable {
    $ts=nts_utc_timestamp($value);
    if($ts<1)return null;
    return (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone(date_default_timezone_get()));
}
function nts_job_entity(string $key,?int $org=null): string {
    return $key.($org?'@'.$org:'').'@'.gmdate('YmdHis').'-'.substr(bin2hex(random_bytes(3)),0,6);
}
function nts_job_meta(string $jobKey,string $label,string $status,float $started,string $summary,array $details=[]): array {
    $finished=microtime(true);
    return [
        'job_key'=>$jobKey,
        'label'=>$label,
        'status'=>$status,
        'started_at'=>gmdate(DATE_ATOM,(int)$started),
        'finished_at'=>gmdate(DATE_ATOM,(int)$finished),
        'duration_ms'=>(int)round(($finished-$started)*1000),
        'summary'=>$summary,
        'details'=>$details,
    ];
}

function nts_weekly_refresh(PDO $pdo,int $year): array {
    if(!augur_epa_tables_ready($pdo))throw new RuntimeException('Public EPA Archive tables are not installed.');
    $directory=augur_team_directory_sync_year($pdo,$year,true);
    $discovery=augur_epa_discover_year($pdo,$year,true);
    $s=$pdo->prepare("SELECT tba_event_key FROM augur_epa_archive_events WHERE season_year=? ORDER BY COALESCE(start_date,end_date),tba_event_key");
    $s->execute([$year]);$keys=array_map('strval',$s->fetchAll(PDO::FETCH_COLUMN));
    $changed=0;$errors=[];$checked=0;
    foreach($keys as $key){
        try{$r=augur_epa_ingest_event($pdo,$key,true);$checked++;if(!empty($r['changed']))$changed++;}
        catch(Throwable $e){$errors[]=$key.': '.$e->getMessage();}
        usleep(200000);
    }
    $epa=augur_epa_recalculate_year($pdo,$year);
    $opr=augur_opr_recalculate_year($pdo,$year);
    $depa=augur_depa_tables_ready($pdo)?augur_depa_rebuild_year($pdo,$year):null;
    return ['year'=>$year,'directory'=>$directory,'discovery'=>$discovery,'events_checked'=>$checked,'events_changed'=>$changed,'event_errors'=>$errors,'epa'=>$epa,'opr'=>$opr,'depa'=>$depa];
}

try{
    // Do not mutate AUGUR archive/rating tables while the platform-owner
    // maintenance page has a background AUGUR job running. The scheduler will
    // simply try again on the next minute.
    $manualJobFile=sys_get_temp_dir().'/neptune-augur-maintenance/job.json';
    if(is_file($manualJobFile)){
        $job=json_decode((string)@file_get_contents($manualJobFile),true);
        $pid=(int)($job['pid']??0);$script=(string)($job['script']??'');
        if($pid>0&&is_dir('/proc/'.$pid)){
            $cmd=(string)@file_get_contents('/proc/'.$pid.'/cmdline');
            if($cmd!==''&&($script===''||str_contains($cmd,basename($script)))){
                $runnerStatus='deferred';
                $runnerMessage='AUGUR maintenance is running; scheduled TBA refresh deferred.';
                echo $runnerMessage."\n";
                return;
            }
        }
    }

    $settings=neptune_tba_schedule_settings($pdo);
    $interval=max(1,min(60,(int)$settings['live_interval_minutes']));

    // Per-organization live refresh. The toggle is intentionally tenant-scoped:
    // one team's header cannot switch another organization into event-live mode.
    $orgs=$pdo->query("SELECT organization_id,last_live_attempt_at FROM neptune_tba_live_state WHERE enabled=1 ORDER BY organization_id")->fetchAll();
    foreach($orgs as $state){
        $org=(int)$state['organization_id'];
        if(!nts_due($state['last_live_attempt_at']??null,$interval))continue;
        $runnerJobs++;
        $jobStarted=microtime(true);
        $entityId=nts_job_entity('tba-live',$org);
        $pdo->prepare('UPDATE neptune_tba_live_state SET last_live_attempt_at=UTC_TIMESTAMP() WHERE organization_id=?')->execute([$org]);
        try{
            $q=$pdo->prepare("SELECT e.id,e.name,e.tba_event_key,g.season_year FROM events e JOIN games g ON g.id=e.game_id WHERE e.organization_id=? AND e.active=1 AND e.is_current=1 ORDER BY COALESCE(e.start_date,e.end_date,'1900-01-01') DESC,e.id DESC LIMIT 1");
            $q->execute([$org]);$event=$q->fetch();
            if(!$event)throw new RuntimeException('No current event is selected for this organization.');
            if(trim((string)($event['tba_event_key']??''))===''){
                $msg=(string)$event['name'].': manual event; live TBA refresh skipped.';
                $pdo->prepare('UPDATE neptune_tba_live_state SET last_live_success_at=UTC_TIMESTAMP(),last_live_message=? WHERE organization_id=?')->execute([nts_clip($msg),$org]);
                neptune_tba_scheduler_audit_job($pdo,$org,'cron_tba_live',$entityId,nts_job_meta(
                    'tba_live_refresh','At Event Live refresh','success',$jobStarted,$msg,[
                        'event_id'=>(int)$event['id'],'event_name'=>(string)$event['name'],'tba_event_key'=>'',
                        'season_year'=>(int)($event['season_year']??date('Y')),'skipped_manual_event'=>true,
                    ]
                ));
                echo '[live] org '.$org.' '.$msg."\n";
                continue;
            }
            $r=neptune_tba_refresh_linked_event($pdo,$org,(int)$event['id']);
            $msg=sprintf('%s: %d teams, %d matches%s',(string)$event['name'],(int)$r['teams'],(int)$r['matches'],!empty($r['epa_source_changed'])?' · EPA/OPR/D-EPA refreshed':' · no new scored TBA data');
            $pdo->prepare('UPDATE neptune_tba_live_state SET last_live_success_at=UTC_TIMESTAMP(),last_live_message=? WHERE organization_id=?')->execute([nts_clip($msg),$org]);
            neptune_tba_scheduler_audit_job($pdo,$org,'cron_tba_live',$entityId,nts_job_meta(
                'tba_live_refresh','At Event Live refresh','success',$jobStarted,$msg,[
                    'event_id'=>(int)$event['id'],'event_name'=>(string)$event['name'],'tba_event_key'=>(string)$event['tba_event_key'],
                    'season_year'=>(int)($event['season_year']??date('Y')),'teams'=>(int)($r['teams']??0),'matches'=>(int)($r['matches']??0),
                    'epa_source_changed'=>!empty($r['epa_source_changed']),
                ]
            ));
            echo '[live] org '.$org.' '.$msg."\n";
        }catch(Throwable $e){
            $runnerFailures++;
            $pdo->prepare('UPDATE neptune_tba_live_state SET last_live_message=? WHERE organization_id=?')->execute([nts_clip('ERROR: '.$e->getMessage()),$org]);
            neptune_tba_scheduler_audit_job($pdo,$org,'cron_tba_live',$entityId,nts_job_meta(
                'tba_live_refresh','At Event Live refresh','error',$jobStarted,'ERROR: '.$e->getMessage(),['error'=>$e->getMessage()]
            ));
            fwrite(STDERR,'[live] org '.$org.' ERROR: '.$e->getMessage()."\n");
        }
    }

    // Weekly public-network refresh. Running the whole current-season archive is
    // deliberate: it includes every event Neptune did not attend and keeps the
    // cross-event EPA/D-EPA baseline coherent.
    if(!empty($settings['weekly_enabled'])){
        $now=new DateTimeImmutable('now');
        $day=(int)$settings['weekly_day'];$hour=(int)$settings['weekly_hour'];$minute=(int)$settings['weekly_minute'];
        $scheduledToday=((int)$now->format('N')===$day)&&((int)$now->format('G')===$hour)&&((int)$now->format('i')===$minute);
        $last=$settings['last_weekly_attempt_at']??null;
        $lastLocal=nts_utc_to_local($last);
        $sameIsoWeek=$lastLocal&&$lastLocal->format('o-W')===$now->format('o-W');
        if($scheduledToday&&!$sameIsoWeek){
            $runnerJobs++;
            $jobStarted=microtime(true);
            $entityId=nts_job_entity('tba-weekly');
            neptune_tba_scheduler_write_heartbeat($pdo,['status'=>'running','started_at'=>$runnerStartedIso,'finished_at'=>null,'message'=>'Weekly public TBA refresh is running.','jobs_run'=>$runnerJobs,'jobs_failed'=>$runnerFailures]);
            $pdo->prepare('UPDATE neptune_tba_schedule_settings SET last_weekly_attempt_at=UTC_TIMESTAMP(),last_weekly_message=? WHERE id=1')->execute(['Weekly TBA backfill started']);
            try{
                $year=(int)date('Y');$r=nts_weekly_refresh($pdo,$year);
                $msg=sprintf('%d: %d TBA events checked, %d changed, %d EPA teams',$year,(int)$r['events_checked'],(int)$r['events_changed'],(int)($r['epa']['teams']??0));
                if($r['event_errors'])$msg.=' · '.count($r['event_errors']).' event errors';
                $pdo->prepare('UPDATE neptune_tba_schedule_settings SET last_weekly_success_at=UTC_TIMESTAMP(),last_weekly_message=? WHERE id=1')->execute([nts_clip($msg)]);
                $details=[
                    'season_year'=>$year,
                    'events_checked'=>(int)$r['events_checked'],
                    'events_changed'=>(int)$r['events_changed'],
                    'event_error_count'=>count($r['event_errors']??[]),
                    'event_errors'=>array_slice($r['event_errors']??[],0,20),
                    'directory'=>$r['directory']??null,
                    'discovery'=>$r['discovery']??null,
                    'epa'=>$r['epa']??null,
                    'opr'=>$r['opr']??null,
                    'depa'=>$r['depa']??null,
                ];
                neptune_tba_scheduler_audit_job($pdo,null,'cron_tba_weekly',$entityId,nts_job_meta('tba_weekly_refresh','Weekly public TBA refresh','success',$jobStarted,$msg,$details));
                echo '[weekly] '.$msg."\n";
            }catch(Throwable $e){
                $runnerFailures++;
                $pdo->prepare('UPDATE neptune_tba_schedule_settings SET last_weekly_message=? WHERE id=1')->execute([nts_clip('ERROR: '.$e->getMessage())]);
                neptune_tba_scheduler_audit_job($pdo,null,'cron_tba_weekly',$entityId,nts_job_meta('tba_weekly_refresh','Weekly public TBA refresh','error',$jobStarted,'ERROR: '.$e->getMessage(),['season_year'=>(int)date('Y'),'error'=>$e->getMessage()]));
                fwrite(STDERR,'[weekly] ERROR: '.$e->getMessage()."\n");
            }
        }
    }

    if($runnerJobs>0){
        $runnerStatus=$runnerFailures>0?'warning':'healthy';
        $runnerMessage=$runnerJobs.' scheduled job'.($runnerJobs===1?'':'s').' ran'.($runnerFailures>0?' · '.$runnerFailures.' failed':' successfully').'.';
    }
}catch(Throwable $e){
    $runnerStatus='error';
    $runnerMessage='Scheduler error: '.$e->getMessage();
    fwrite(STDERR,$runnerMessage."\n");
    throw $e;
}finally{
    neptune_tba_scheduler_write_heartbeat($pdo,[
        'status'=>$runnerStatus,
        'started_at'=>$runnerStartedIso,
        'finished_at'=>gmdate(DATE_ATOM),
        'duration_ms'=>(int)round((microtime(true)-$runnerStarted)*1000),
        'message'=>$runnerMessage,
        'jobs_run'=>$runnerJobs,
        'jobs_failed'=>$runnerFailures,
    ]);
    flock($lock,LOCK_UN);fclose($lock);
}
