<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_augur_epa.php';
require_once dirname(__DIR__).'/analytics/_augur_opr.php';
require_once dirname(__DIR__).'/analytics/_augur_team_directory.php';

if(!augur_epa_tables_ready($pdo)){
    fwrite(STDERR,"EPA Archive tables are not installed. Run sql/2026-09-21_augur-epa-archive-v3.sql first.\n");
    exit(2);
}

@set_time_limit(0);

$current=(int)date('Y');
$start=isset($argv[1])?(int)$argv[1]:max(1992,$current-19);
$end=isset($argv[2])?(int)$argv[2]:$current;
if($start>$end)[$start,$end]=[$end,$start];

$mode='full';$forceSource=false;
foreach(array_slice($argv,3) as $arg){
    if($arg==='--fetch-only')$mode='fetch';
    if($arg==='--recalc-only')$mode='recalc';
    if($arg==='--force-source')$forceSource=true;
}

$lockPath=sys_get_temp_dir().'/neptune_augur_epa_backfill.lock';
$lock=fopen($lockPath,'c+');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){
    fwrite(STDERR,"Another AUGUR EPA backfill appears to already be running.\n");
    exit(3);
}
ftruncate($lock,0);
fwrite($lock,(string)getmypid());
fflush($lock);

function cli_elapsed(float $start): string {
    $s=max(0,(int)round(microtime(true)-$start));
    $h=intdiv($s,3600);$m=intdiv($s%3600,60);$sec=$s%60;
    return $h>0?sprintf('%d:%02d:%02d',$h,$m,$sec):sprintf('%d:%02d',$m,$sec);
}

function cli_event_source_needs_fetch(array $event): bool {
    $status=(string)($event['source_status']??'discovered');
    $historical=(int)($event['is_complete']??0)===1;
    $fetched=strtotime((string)($event['source_fetched_at']??''))?:0;

    if($status==='error'||$status==='discovered')return true;
    if($historical&&in_array($status,['ready','no_qual_data'],true))return false;
    if(!$historical){
        if(!in_array($status,['ready','waiting','no_qual_data'],true))return true;
        return $fetched<time()-AUGUR_EPA_LIVE_REFRESH_SECONDS;
    }
    return false;
}

$allStart=microtime(true);
echo "Neptune AUGUR EPA Archive optimized backfill {$start}-{$end}\n";
echo "Mode: {$mode}".($forceSource?" + force-source":"")."\n";
echo "PID: ".getmypid()."\n";
echo "Important: source ingestion happens first; EPA is recalculated ONCE per year.\n\n";

$summary=[
    'type'=>'backfill',
    'mode'=>$mode,
    'status'=>'success',
    'start_year'=>$start,
    'end_year'=>$end,
    'directory'=>['added'=>0,'updated'=>0,'skipped'=>0,'removed'=>0,'teams'=>0],
    'source'=>['checked'=>0,'changed'=>0,'cached'=>0,'skipped'=>0,'errors'=>0],
    'epa'=>['events'=>0,'teams'=>0,'matches'=>0],
    'opr'=>['events'=>0,'team_rows'=>0],
    'errors'=>0,
];

for($year=$start;$year<=$end;$year++){
    $yearStart=microtime(true);
    echo "[{$year}] ------------------------------------------------------------\n";

    try{
        if($mode!=='recalc'){
            echo "[{$year}] Refreshing local TBA team directory...\n";
            $td0=microtime(true);
            $td=augur_team_directory_sync_year($pdo,$year,$forceSource);
            $summary['directory']['added']+=(int)($td['added']??0);
            $summary['directory']['updated']+=(int)($td['updated']??0);
            $summary['directory']['skipped']+=(int)($td['unchanged']??0);
            $summary['directory']['removed']+=(int)($td['removed']??0);
            $summary['directory']['teams']+=(int)($td['teams']??0);
            echo "[{$year}] Team directory: {$td['teams']} teams · "
                .(int)($td['added']??0)." added · "
                .(int)($td['updated']??0)." updated · "
                .(int)($td['unchanged']??0)." unchanged · "
                .(int)($td['removed']??0)." removed · "
                .$td['pages']." TBA page(s) (".cli_elapsed($td0).").\n";

            echo "[{$year}] Discovering events...\n";
            $d=augur_epa_discover_year($pdo,$year,false);
            echo "[{$year}] {$d['events']} events known".(!empty($d['cached'])?' (directory cached)':'').".\n";

            $s=$pdo->prepare("SELECT *
                FROM augur_epa_archive_events
                WHERE season_year=?
                ORDER BY COALESCE(start_date,end_date),tba_event_key");
            $s->execute([$year]);
            $events=$s->fetchAll();

            $need=0;
            foreach($events as $event)if($forceSource||cli_event_source_needs_fetch($event))$need++;
            echo "[{$year}] {$need} event source".($need===1?'':'s')." need TBA data; ".(count($events)-$need)." already archived.\n";

            $done=0;$fetched=0;$skipped=0;$errors=0;$changedCount=0;$cachedCount=0;
            foreach($events as $event){
                $key=(string)$event['tba_event_key'];

                if(!$forceSource&&!cli_event_source_needs_fetch($event)){
                    $skipped++;
                    $summary['source']['skipped']++;
                    continue;
                }

                $done++;
                echo "[{$year}] [{$done}/{$need}] {$key} ".($event['name']??'')." ... ";
                $t0=microtime(true);

                try{
                    // IMPORTANT: ingest source only here. Do not calculate EPA for
                    // every event as it arrives; doing that caused each year to be
                    // recalculated repeatedly and was the main backfill slowdown.
                    $r=augur_epa_ingest_event($pdo,$key,$forceSource);
                    $status=(string)($r['status']??'unknown');
                    $matches=(int)($r['matches']??0);
                    $changed=!empty($r['changed'])?'changed':'cached';
                    if(!empty($r['changed'])){$changedCount++;$summary['source']['changed']++;}
                    else{$cachedCount++;$summary['source']['cached']++;}
                    $summary['source']['checked']++;
                    echo "{$status}, {$matches} quals, {$changed} (".cli_elapsed($t0).")\n";
                    $fetched++;
                }catch(Throwable $e){
                    $errors++;
                    $summary['source']['errors']++;
                    $summary['errors']++;
                    $summary['status']='partial';
                    echo "ERROR: {$e->getMessage()}\n";
                }

                // Small courtesy pause between actual external-event fetches.
                // The expensive per-event EPA calculation has been removed.
                usleep(200000);

                if(($done%25)===0){
                    gc_collect_cycles();
                    echo "[{$year}] Progress: {$done}/{$need} source events checked; elapsed ".cli_elapsed($yearStart).".\n";
                }
            }

            echo "[{$year}] Source pass complete: {$fetched} checked · {$changedCount} updated · {$cachedCount} unchanged · {$skipped} skipped · {$errors} errors.\n";
        }

        if($mode!=='fetch'){
            echo "[{$year}] Calculating EPA chronologically from LOCAL archived samples...\n";
            $t0=microtime(true);

            // Exactly one deterministic chronological rebuild for the season.
            // This preserves prior-event seeding while avoiding the old
            // calculate-each-event + calculate-the-entire-year-again workflow.
            $r=augur_epa_recalculate_year($pdo,$year);

            $summary['epa']['events']+=(int)($r['events']??0);
            $summary['epa']['teams']+=(int)($r['teams']??0);
            $summary['epa']['matches']+=(int)($r['matches']??0);
            echo "[{$year}] EPA complete: {$r['events']} rated events, {$r['teams']} teams, {$r['matches']} qualification matches"
                ." (".cli_elapsed($t0).").\n";

            // OPR uses the same already-local alliance archive as Public EPA,
            // so keep the OPR table synchronized whenever the season model is
            // rebuilt. No additional TBA request is made here.
            echo "[{$year}] Calculating traditional event OPR from LOCAL archived samples...\n";
            $opr0=microtime(true);
            $opr=augur_opr_recalculate_year($pdo,$year);
            $summary['opr']['events']+=(int)($opr['events']??0);
            $summary['opr']['team_rows']+=(int)($opr['team_rows']??0);
            echo "[{$year}] OPR complete: {$opr['events']} events, {$opr['team_rows']} team/event ratings"
                ." (".cli_elapsed($opr0).").\n";
        }

        echo "[{$year}] Finished in ".cli_elapsed($yearStart).".\n\n";
    }catch(Throwable $e){
        $summary['errors']++;
        $summary['status']='partial';
        fwrite(STDERR,"[{$year}] ERROR: {$e->getMessage()}\n\n");
    }

    gc_collect_cycles();
}

echo "Done. Total elapsed: ".cli_elapsed($allStart).".\n";
echo "NEPTUNE_RESULT_JSON:".json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";

flock($lock,LOCK_UN);
fclose($lock);
