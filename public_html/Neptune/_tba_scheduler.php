<?php
/**
 * Neptune database-backed TBA scheduler settings.
 *
 * This file only reads/writes scheduler state. It never creates schema at
 * request time; install sql/2026-09-26_tba-scheduler.sql during deployment.
 */

function neptune_tba_scheduler_tables_ready(PDO $pdo): bool {
    static $cache=[];
    $key=spl_object_id($pdo);
    if(array_key_exists($key,$cache)) return $cache[$key];
    try{
        $q=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('neptune_tba_schedule_settings','neptune_tba_live_state')");
        $names=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));
        return $cache[$key]=in_array('neptune_tba_schedule_settings',$names,true)&&in_array('neptune_tba_live_state',$names,true);
    }catch(Throwable $e){
        return $cache[$key]=false;
    }
}

function neptune_tba_schedule_defaults(): array {
    return [
        'id'=>1,
        'live_interval_minutes'=>2,
        'weekly_enabled'=>1,
        'weekly_day'=>1, // ISO-8601: Monday=1 ... Sunday=7
        'weekly_hour'=>3,
        'weekly_minute'=>0,
        'last_weekly_attempt_at'=>null,
        'last_weekly_success_at'=>null,
        'last_weekly_message'=>null,
    ];
}

function neptune_tba_schedule_settings(PDO $pdo): array {
    $defaults=neptune_tba_schedule_defaults();
    if(!neptune_tba_scheduler_tables_ready($pdo)) return $defaults;
    try{
        $q=$pdo->query('SELECT * FROM neptune_tba_schedule_settings WHERE id=1 LIMIT 1');
        $row=$q->fetch()?:[];
        return array_replace($defaults,$row);
    }catch(Throwable $e){
        return $defaults;
    }
}

function neptune_tba_live_state(PDO $pdo,int $organizationId): array {
    $defaults=[
        'organization_id'=>$organizationId,
        'enabled'=>0,
        'last_live_attempt_at'=>null,
        'last_live_success_at'=>null,
        'last_live_message'=>null,
        'updated_by'=>null,
        'updated_at'=>null,
    ];
    if($organizationId<1||!neptune_tba_scheduler_tables_ready($pdo)) return $defaults;
    try{
        $s=$pdo->prepare('SELECT * FROM neptune_tba_live_state WHERE organization_id=? LIMIT 1');
        $s->execute([$organizationId]);
        return array_replace($defaults,$s->fetch()?:[]);
    }catch(Throwable $e){
        return $defaults;
    }
}

function neptune_tba_weekday_name(int $day): string {
    return match($day){1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday',default=>'Monday'};
}

/**
 * Lightweight scheduler heartbeat.
 *
 * The heartbeat is stored as ONE reusable row in audit_log rather than /tmp.
 * Apache commonly runs with systemd PrivateTmp, which means a cron process and
 * the web process can see different /tmp namespaces. Reusing one database row
 * keeps the heartbeat visible to both without creating one audit row per minute.
 */
function neptune_tba_scheduler_write_heartbeat(PDO $pdo,array $state): void {
    $payload=array_merge([
        'runner'=>'tba-scheduled-sync',
        'pid'=>getmypid(),
        'written_at'=>gmdate(DATE_ATOM),
    ],$state);
    $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)$json='{}';
    try{
        $q=$pdo->query("SELECT id FROM audit_log WHERE action='cron_heartbeat' AND entity_type='scheduled_job' AND entity_id='tba-scheduler' ORDER BY id DESC LIMIT 1");
        $id=(int)($q->fetchColumn()?:0);
        if($id>0){
            $u=$pdo->prepare("UPDATE audit_log SET organization_id=NULL,user_id=NULL,metadata_json=?,ip_address=NULL,created_at=UTC_TIMESTAMP() WHERE id=?");
            $u->execute([$json,$id]);
        }else{
            $i=$pdo->prepare("INSERT INTO audit_log (organization_id,user_id,action,entity_type,entity_id,metadata_json,ip_address,created_at) VALUES(NULL,NULL,'cron_heartbeat','scheduled_job','tba-scheduler',?,NULL,UTC_TIMESTAMP())");
            $i->execute([$json]);
        }
    }catch(Throwable $e){
        // Heartbeat telemetry must never stop the scheduler itself.
    }
}

function neptune_tba_scheduler_read_heartbeat(PDO $pdo): array {
    try{
        $q=$pdo->query("SELECT metadata_json,created_at FROM audit_log WHERE action='cron_heartbeat' AND entity_type='scheduled_job' AND entity_id='tba-scheduler' ORDER BY id DESC LIMIT 1");
        $row=$q->fetch();
        if(!$row)return [];
        $decoded=json_decode((string)($row['metadata_json']??''),true);
        if(!is_array($decoded))return [];
        if(empty($decoded['written_at'])&&!empty($row['created_at'])){
            $decoded['written_at']=(string)$row['created_at'].' UTC';
        }
        return $decoded;
    }catch(Throwable $e){
        return [];
    }
}

/**
 * Scheduled-job executions are written to the existing audit_log table. Cron
 * ticks that have nothing to do are represented only by the heartbeat above,
 * so the audit table does not grow by one row every minute.
 */
function neptune_tba_scheduler_audit_job(PDO $pdo,?int $organizationId,string $action,string $entityId,array $metadata): void {
    try{
        $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($json===false) $json='{}';
        $s=$pdo->prepare("INSERT INTO audit_log
            (organization_id,user_id,action,entity_type,entity_id,metadata_json,ip_address)
            VALUES(?,NULL,?,'scheduled_job',?,?,NULL)");
        $s->execute([$organizationId&&$organizationId>0?$organizationId:null,$action,$entityId,$json]);
    }catch(Throwable $e){
        // Scheduler telemetry must never stop the underlying refresh job.
    }
}
