<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_augur_depa.php';
@set_time_limit(0);
$current=(int)date('Y');$start=isset($argv[1])?(int)$argv[1]:$current;$end=isset($argv[2])?(int)$argv[2]:$start;if($start>$end)[$start,$end]=[$end,$start];
$lockPath=sys_get_temp_dir().'/neptune_augur_depa_beta.lock';$lock=fopen($lockPath,'c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Another AUGUR D-EPA rebuild appears to already be running.\n");exit(3);}
$summary=['type'=>'neptune_depa_network','status'=>'success','start_year'=>$start,'end_year'=>$end,'public'=>['events'=>0,'team_rows'=>0,'match_samples'=>0],'network'=>['canonical_evidence_rows'=>0,'event_rating_rows'=>0,'season_rating_rows'=>0,'contributing_orgs'=>0],'errors'=>0,'model_version'=>AUGUR_DEPA_MODEL_VERSION];
try{
    augur_depa_install_tables($pdo);
    echo "Neptune AUGUR public D-EPA network rebuild {$start}-{$end}\n";
    echo "Uses scouting from every Neptune organization. Duplicate coverage is canonicalized by TBA match key + FRC team.\n";
    echo "Cross-org defense button counts are never summed; the maximum independently observed count is retained for each canonical match/team.\n";
    echo "Neptune D-EPA = mean Raw D-EPA across canonical Verified defense matches.\n\n";
    for($year=$start;$year<=$end;$year++){
        try{
            echo "[{$year}] Rebuilding public samples + Neptune network D-EPA...\n";$t0=microtime(true);$r=augur_depa_rebuild_year($pdo,$year);
            foreach(['events','team_rows','match_samples'] as $k)$summary['public'][$k]+=(int)($r['public'][$k]??0);
            foreach(['canonical_evidence_rows','event_rating_rows','season_rating_rows'] as $k)$summary['network'][$k]+=(int)($r['network'][$k]??0);
            $summary['network']['contributing_orgs']=max((int)$summary['network']['contributing_orgs'],(int)($r['network']['contributing_orgs']??0));
            printf("[%d] public: %d events / %d baseline rows / %d match-alliance samples; network: %d canonical observations / %d event ratings / %d season ratings / %d contributing orgs in %.2fs\n\n",$year,(int)($r['public']['events']??0),(int)($r['public']['team_rows']??0),(int)($r['public']['match_samples']??0),(int)($r['network']['canonical_evidence_rows']??0),(int)($r['network']['event_rating_rows']??0),(int)($r['network']['season_rating_rows']??0),(int)($r['network']['contributing_orgs']??0),microtime(true)-$t0);
        }catch(Throwable $e){$summary['errors']++;$summary['status']='partial';fwrite(STDERR,"[{$year}] ERROR: {$e->getMessage()}\n\n");}
    }
    echo "Done.\n";echo "NEPTUNE_RESULT_JSON:".json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
}finally{flock($lock,LOCK_UN);fclose($lock);}exit($summary['errors']>0?1:0);
