<?php
/**
 * Purge long-expired tba_cache rows in small batches.
 *
 * Dry run (default) prints what would be removed. Deleting requires the owner
 * to pass back the exact row count from the dry run, so nothing is purged
 * without someone having looked at the plan first:
 *
 *   php admin/tba-cache-purge-cli.php                       # plan only
 *   php admin/tba-cache-purge-cli.php --execute --before='2026-10-09 19:00:00' --approve=12329
 *
 * The dry run prints that exact command. --before pins the cutoff, so the
 * approved count cannot grow between review and execution.
 *
 * Options: --grace-days=N (default 7; only rows expired more than N days ago)
 *          --batch=N (default 500) --sleep-ms=N (default 200) --optimize
 *
 * Expired rows are not useless: tba_get() can revalidate them by ETag, and
 * analytics/stats/team.php shows them as historical snapshots. The grace window
 * keeps recently expired rows for both. Deleting rows does not shrink the
 * InnoDB file; --optimize rebuilds the table afterwards (online, but it copies
 * the whole table, so run it outside event hours).
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';

$opts=getopt('',['execute','approve:','before:','grace-days:','batch:','sleep-ms:','optimize']);
$execute=isset($opts['execute']);
$grace=max(1,(int)($opts['grace-days']??7));
$batch=max(50,min(5000,(int)($opts['batch']??500)));
$sleepMs=max(0,(int)($opts['sleep-ms']??200));
$cutoff=gmdate('Y-m-d H:i:s',time()-$grace*86400);
if(isset($opts['before'])){
    $ts=strtotime((string)$opts['before'].' UTC');
    if($ts===false||$ts>time()-86400){fwrite(STDERR,"--before must be a UTC date-time at least one day in the past.\n");exit(2);}
    $cutoff=gmdate('Y-m-d H:i:s',$ts);
}elseif($execute){
    fwrite(STDERR,"--execute needs --before from the dry run, so the approved plan cannot change.\n");exit(2);
}

$plan=$pdo->prepare('SELECT COUNT(*) n,COALESCE(SUM(LENGTH(response_json)),0) bytes,MIN(expires_at) oldest FROM tba_cache WHERE expires_at<?');
$plan->execute([$cutoff]);
$p=$plan->fetch(PDO::FETCH_ASSOC);
$all=$pdo->query('SELECT COUNT(*) n,COALESCE(SUM(LENGTH(response_json)),0) bytes,SUM(expires_at<UTC_TIMESTAMP()) expired FROM tba_cache')->fetch(PDO::FETCH_ASSOC);
$mb=static fn($b)=>number_format((float)$b/1048576,1).' MB';

echo "tba_cache: ".number_format((int)$all['n'])." rows, ".$mb($all['bytes'])." of JSON, ".number_format((int)$all['expired'])." expired now.\n";
echo "Plan: delete ".number_format((int)$p['n'])." rows expired before $cutoff UTC, ".$mb($p['bytes'])." of JSON.";
echo $p['oldest']?" Oldest expiry {$p['oldest']}.\n":"\n";

if(!$execute){
    echo "Dry run only. To delete, re-run with:\n  php ".$argv[0]." --execute --before='$cutoff' --approve=".(int)$p['n']."\n";
    exit(0);
}
// Rows refreshed since the dry run only lower the count; anything above the approval is refused.
if(!isset($opts['approve'])||(int)$p['n']>(int)$opts['approve']||(int)$opts['approve']<1){
    fwrite(STDERR,"Refusing to delete: plan is ".(int)$p['n']." rows; --approve must be the dry-run count and at least that. Run the dry run again.\n");
    exit(2);
}
if((int)$p['n']===0){echo "Nothing to delete.\n";exit(0);}

$pick=$pdo->prepare('SELECT cache_key FROM tba_cache WHERE expires_at<? ORDER BY expires_at LIMIT '.$batch);
$deleted=0;$batches=0;
while(true){
    $pick->execute([$cutoff]);
    $keys=$pick->fetchAll(PDO::FETCH_COLUMN);
    if(!$keys)break;
    // Re-check expiry in the DELETE so a row refreshed meanwhile survives.
    $in=implode(',',array_fill(0,count($keys),'?'));
    $del=$pdo->prepare("DELETE FROM tba_cache WHERE cache_key IN ($in) AND expires_at<?");
    $del->execute([...$keys,$cutoff]);
    $deleted+=$del->rowCount();$batches++;
    if($batches%10===0)echo "  ".number_format($deleted)." deleted…\n";
    if($deleted>=(int)$opts['approve'])break; // never past the approved plan
    if($sleepMs)usleep($sleepMs*1000);
}
echo "Deleted ".number_format($deleted)." rows in $batches batch".($batches===1?'':'es').".\n";

try{
    $s=$pdo->prepare("INSERT INTO audit_log (organization_id,user_id,action,entity_type,entity_id,metadata_json,ip_address) VALUES(NULL,NULL,'tba_cache_purge','maintenance','tba_cache',?,NULL)");
    $s->execute([json_encode(['approved'=>(int)$opts['approve'],'deleted'=>$deleted,'cutoff_utc'=>$cutoff,'grace_days'=>$grace,'json_bytes'=>(int)$p['bytes']])]);
}catch(Throwable $e){fwrite(STDERR,"Audit entry not written: ".$e->getMessage()."\n");}

if(isset($opts['optimize'])){
    echo "Rebuilding tba_cache to return free space to the filesystem…\n";
    $pdo->query('OPTIMIZE TABLE tba_cache')->fetchAll();
    echo "Done.\n";
}else{
    echo "Space is reusable by InnoDB now; add --optimize (off-hours) to shrink the file.\n";
}
