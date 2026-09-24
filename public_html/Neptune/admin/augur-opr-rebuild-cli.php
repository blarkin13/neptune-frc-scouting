<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_augur_opr.php';

@set_time_limit(0);
$current=(int)date('Y');
$start=isset($argv[1])?(int)$argv[1]:$current;
$end=isset($argv[2])?(int)$argv[2]:$start;
if($start>$end)[$start,$end]=[$end,$start];

$lockPath=sys_get_temp_dir().'/neptune_augur_opr_rebuild.lock';
$lock=fopen($lockPath,'c+');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){
    fwrite(STDERR,"Another AUGUR OPR rebuild appears to already be running.\n");
    exit(3);
}

$summary=[
    'type'=>'opr',
    'status'=>'success',
    'start_year'=>$start,
    'end_year'=>$end,
    'opr'=>['events'=>0,'team_rows'=>0,'alliance_rows'=>0],
    'errors'=>0,
];

try{
    augur_opr_install_tables($pdo);
    echo "Neptune AUGUR OPR rebuild {$start}-{$end}\n";
    echo "Source: local augur_epa_alliance_samples (qualification matches only)\n\n";

    for($year=$start;$year<=$end;$year++){
        try{
            echo "[{$year}] Calculating traditional event OPR...\n";
            $t0=microtime(true);
            $r=augur_opr_recalculate_year($pdo,$year);

            $summary['opr']['events']+=(int)($r['events']??0);
            $summary['opr']['team_rows']+=(int)($r['team_rows']??0);
            $summary['opr']['alliance_rows']+=(int)($r['alliance_rows']??0);

            printf("[%d] %d events, %d team/event ratings, %d alliance equations in %.2fs\n\n",
                $year,$r['events'],$r['team_rows'],$r['alliance_rows'],microtime(true)-$t0);
        }catch(Throwable $e){
            $summary['errors']++;
            $summary['status']='partial';
            fwrite(STDERR,"[{$year}] ERROR: {$e->getMessage()}\n\n");
        }
    }

    echo "Done.\n";
    echo "NEPTUNE_RESULT_JSON:".json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
}finally{
    flock($lock,LOCK_UN);
    fclose($lock);
}
exit($summary['errors']>0?1:0);
