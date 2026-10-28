<?php
/**
 * Event-day health: one read-only snapshot of the things that fail quietly on a
 * competition day. Every check returns
 *   ['key','label','state'=>'good|warn|bad|info','value','detail']
 * and never throws; a failed probe is itself reported as a bad check.
 */
declare(strict_types=1);

require_once dirname(__DIR__).'/_tba_scheduler.php';
require_once dirname(__DIR__).'/scout/_tag_synthetic.php';

const NEH_BACKUP_STATUS = '/var/lib/neptune-backup/status.json';

function neh_check(string $key,string $label,string $state,string $value,string $detail=''): array {
    return ['key'=>$key,'label'=>$label,'state'=>$state,'value'=>$value,'detail'=>$detail];
}

function neh_age(?int $epoch): string {
    if(!$epoch)return 'never';
    $s=max(0,time()-$epoch);
    if($s<90)return $s.' s ago';
    if($s<5400)return round($s/60).' min ago';
    if($s<172800)return round($s/3600,1).' h ago';
    return round($s/86400,1).' days ago';
}

function neh_bytes(float $b): string {
    $u=['B','KB','MB','GB','TB'];$i=0;
    while($b>=1024&&$i<count($u)-1){$b/=1024;$i++;}
    return number_format($b,$i===0?0:1).' '.$u[$i];
}

/** 'portable' (organization laptop), 'field' (field server) or 'cloud'. */
function neh_host_mode(array $config): string {
    $secure=dirname(__DIR__,3).'/neptune_secure/portable.php';
    try{
        if(is_file($secure)){
            require_once $secure;
            if(function_exists('neptune_portable_is_local')&&neptune_portable_is_local($config))return 'portable';
        }
    }catch(Throwable $e){}
    return getenv('NEPTUNE_FIELD_MODE')==='1'?'field':'cloud';
}

function neh_check_backup(string $mode): array {
    $label='Production backup';
    if(!is_readable(NEH_BACKUP_STATUS)){
        return $mode==='cloud'
            ? neh_check('backup',$label,'bad','Not configured','No readable '.NEH_BACKUP_STATUS.'. Nightly backups are not reporting.')
            : neh_check('backup',$label,'info','Not on this host','Backups run on the cloud server; sync this '.$mode.' host to protect its data.');
    }
    $data=json_decode((string)@file_get_contents(NEH_BACKUP_STATUS),true);
    if(!is_array($data))return neh_check('backup',$label,'bad','Unreadable status','Backup status file is not valid JSON.');
    $last=strtotime((string)($data['last_success_at']??''))?:null;
    $age=$last?time()-$last:null;
    $state=$age===null?'bad':($age<=26*3600?'good':($age<=50*3600?'warn':'bad'));
    if(($data['status']??'')==='failed'&&$state==='good')$state='warn';
    $detail='Status: '.(string)($data['status']??'unknown');
    if(!empty($data['last_success_size_bytes']))$detail.=' · '.neh_bytes((float)$data['last_success_size_bytes']);
    if(!empty($data['last_error']))$detail.=' · Last error: '.substr((string)$data['last_error'],0,160);
    return neh_check('backup',$label,$state,'Last good '.neh_age($last),$detail);
}

function neh_check_scheduler(PDO $pdo,string $mode): array {
    $label='Scheduler heartbeat';
    $hb=neptune_tba_scheduler_read_heartbeat($pdo);
    if(!$hb){
        return $mode==='cloud'
            ? neh_check('scheduler',$label,'bad','No heartbeat','The once-a-minute TBA scheduler has never reported. Check the cron entry for tba-scheduled-sync-cli.php.')
            : neh_check('scheduler',$label,'info','Not running here','The TBA scheduler runs on the cloud server.');
    }
    $ts=strtotime((string)($hb['written_at']??''))?:null;
    $age=$ts?time()-$ts:null;
    $state=$age===null?'bad':($age<=180?'good':($age<=600?'warn':'bad'));
    $run=(string)($hb['status']??'');
    if($run==='error')$state='bad';
    elseif($run==='warning'&&$state==='good')$state='warn';
    $detail=trim((string)($hb['message']??''));
    if(isset($hb['jobs_failed'])&&(int)$hb['jobs_failed']>0)$detail.=' · '.(int)$hb['jobs_failed'].' job(s) failed';
    return neh_check('scheduler',$label,$state,'Last tick '.neh_age($ts),$detail);
}

function neh_check_disk(PDO $pdo): array {
    $paths=['App' => dirname(__DIR__,3)];
    try{
        $datadir=(string)$pdo->query('SELECT @@datadir')->fetchColumn();
        if($datadir!==''&&@is_dir($datadir))$paths['Database']=$datadir;
    }catch(Throwable $e){}
    $worst='good';$parts=[];$seen=[];
    foreach($paths as $name=>$path){
        $free=@disk_free_space($path);$total=@disk_total_space($path);
        if($free===false||!$total){$parts[]=$name.': unavailable';$worst='warn';continue;}
        $key=$total.':'.round($free/1e8);
        if(isset($seen[$key]))continue; // same volume
        $seen[$key]=true;
        $pct=$free/$total*100;
        $state=($pct>15&&$free>2*1024**3)?'good':($pct>8?'warn':'bad');
        if($state==='bad'||($state==='warn'&&$worst==='good'))$worst=$state;
        $parts[]=$name.': '.neh_bytes((float)$free).' free ('.number_format($pct,1).'%)';
    }
    return neh_check('disk','Disk space',$worst,$parts[0]??'Unavailable',implode(' · ',array_slice($parts,1)));
}

function neh_check_database(PDO $pdo): array {
    try{
        $t=microtime(true);
        $row=$pdo->query('SELECT UNIX_TIMESTAMP() db_epoch,@@session.time_zone tz,VERSION() v')->fetch(PDO::FETCH_ASSOC);
        $ms=(microtime(true)-$t)*1000;
        $skew=abs((int)$row['db_epoch']-time());
        $state=$ms<=250&&$skew<=30?'good':($ms<=1000&&$skew<=120?'warn':'bad');
        $detail='Session time zone '.(string)$row['tz'].' · clock skew '.$skew.' s · MySQL '.(string)$row['v'];
        return neh_check('database','Database',$state,'Responding in '.number_format($ms,0).' ms',$detail);
    }catch(Throwable $e){
        return neh_check('database','Database','bad','Not responding',substr($e->getMessage(),0,200));
    }
}

/** Rows changed on this host after the last successful upload to the cloud. */
function neh_check_unsynced(PDO $pdo,string $mode,int $org): array {
    $label='Unsynced field data';
    if($mode==='cloud')return neh_check('unsynced',$label,'info','Cloud host','Field and portable hosts report their unsynced rows here.');
    $last=null;
    try{
        if($mode==='portable'){
            $s=$pdo->prepare('SELECT last_success_utc FROM portable_sync_tracking WHERE organization_id=?');
            $s->execute([$org]);$v=$s->fetchColumn();
        }else{
            $s=$pdo->prepare("SELECT COALESCE(completed_at,started_at) FROM offline_sync_runs WHERE organization_id=? AND status='complete' ORDER BY id DESC LIMIT 1");
            $s->execute([$org]);$v=$s->fetchColumn();
        }
        if($v)$last=strtotime($v.' UTC')?:null;
    }catch(Throwable $e){} // tracking table appears after the first successful sync
    $since=$last??0;
    // [table, column, is TIMESTAMP (else UTC DATETIME), extra predicate]
    $sources=[
        'match actions'=>['scouting_actions','recorded_at',false,'deleted_at IS NULL'],
        'pit reports'=>['pit_scouting','updated_at',true,'1=1'],
        'Tag match reports'=>['tag_scouting_match_data','updated_at',true,neptune_tag_human_match_data_sql('tag_scouting_match_data')],
        'Tag observations'=>['tag_scouting_observations','updated_at',true,neptune_tag_human_observation_sql('tag_scouting_observations')],
    ];
    $counts=[];$total=0;
    foreach($sources as $name=>[$table,$col,$isTs,$extra]){
        try{
            $cmp=$isTs?"UNIX_TIMESTAMP($col)>?":"$col>?";
            $s=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE organization_id=? AND $cmp AND $extra");
            $s->execute([$org,$isTs?$since:gmdate('Y-m-d H:i:s',$since)]);
            $n=(int)$s->fetchColumn();
        }catch(Throwable $e){continue;}
        if($n>0)$counts[]=number_format($n).' '.$name;
        $total+=$n;
    }
    $lastText='Last successful upload '.neh_age($last);
    if($total===0)return neh_check('unsynced',$label,'good','All uploaded',$lastText);
    $stale=$last===null||time()-$last>2*3600;
    return neh_check('unsynced',$label,$stale?'bad':'warn',number_format($total).' row'.($total===1?'':'s').' not uploaded',implode(' · ',$counts).' · '.$lastText);
}

function neptune_event_health(PDO $pdo,array $config,int $org): array {
    $mode=neh_host_mode($config);
    $checks=[
        neh_check_database($pdo),
        neh_check_backup($mode),
        neh_check_scheduler($pdo,$mode),
        neh_check_disk($pdo),
        neh_check_unsynced($pdo,$mode,$org),
    ];
    $rank=['good'=>0,'info'=>0,'warn'=>1,'bad'=>2];
    $overall='good';
    foreach($checks as $c)if($rank[$c['state']]>$rank[$overall])$overall=$c['state'];
    return ['generated_at'=>gmdate(DATE_ATOM),'host_mode'=>$mode,'overall'=>$overall,'checks'=>$checks];
}
