<?php
declare(strict_types=1);
/**
 * FIRST-to-Neptune links are NOT stored in events.event_code. That column is
 * owned by the TBA/manual event importer. This additive table survives a
 * later TBA link and is scoped to each organization's existing event ID.
 */
function neptune_first_links_install(PDO $pdo): void {
    static $installed=null;$installed??=new WeakMap();
    if(isset($installed[$pdo]))return;
    $exists=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='neptune_first_event_links'")->fetchColumn()===1;
    if(!$exists&&$pdo->inTransaction())throw new RuntimeException('Initialize FIRST event links before starting a transaction.');
    if(!$exists){
    $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_first_event_links (
        event_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        first_event_code VARCHAR(12) NOT NULL,
        linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_tba_discovery_at DATETIME NULL,
        tba_discovery_status VARCHAR(25) NULL,
        tba_discovery_note VARCHAR(240) NULL,
        tba_discovery_candidate VARCHAR(40) NULL,
        KEY idx_first_org_event (organization_id,event_id),
        KEY idx_first_discovery (last_tba_discovery_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // v0.1-v0.3 used events.event_code for FIRST, but only when TBA was absent.
    // Preserve those confirmed codes before a later TBA sync rewrites event_code.
    // No existing event rows, scouting records, or TBA keys are modified.
    neptune_migration_apply($pdo,'first.event-links.legacy-backfill.v1','Neptune_Database_Efficiency_v1.zip',function()use($pdo):void {
    $exist=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='augur_first_event_ratings'");
    if((int)$exist->fetchColumn()>0)$pdo->exec("INSERT IGNORE INTO neptune_first_event_links
        (event_id,organization_id,season_year,first_event_code)
        SELECT e.id,e.organization_id,g.season_year,UPPER(TRIM(e.event_code))
        FROM events e JOIN games g ON g.id=e.game_id
        WHERE (e.tba_event_key IS NULL OR e.tba_event_key='')
          AND e.event_code REGEXP '^[A-Za-z0-9]{3,12}$'
          AND EXISTS (SELECT 1 FROM augur_first_event_ratings ar
                      WHERE ar.event_id=e.id AND ar.organization_id=e.organization_id)");
    });
    $installed[$pdo]=true;
}
function neptune_first_link_code(PDO $pdo,int $org,int $eventId): string {
    neptune_first_links_install($pdo);
    $q=$pdo->prepare('SELECT first_event_code FROM neptune_first_event_links WHERE event_id=? AND organization_id=? LIMIT 1');
    $q->execute([$eventId,$org]);
    return strtoupper(trim((string)($q->fetchColumn()?:'')));
}
function neptune_first_link_save(PDO $pdo,int $org,int $eventId,int $year,string $code): void {
    neptune_first_links_install($pdo); // DDL outside transactions
    $code=strtoupper(trim($code));
    if(!preg_match('/^[A-Z0-9]{3,12}$/',$code))throw new InvalidArgumentException('Invalid FIRST event code.');
    $q=$pdo->prepare('SELECT first_event_code,season_year FROM neptune_first_event_links WHERE event_id=? AND organization_id=? LIMIT 1');
    $q->execute([$eventId,$org]);$existing=$q->fetch(PDO::FETCH_ASSOC);
    if($existing&&((string)$existing['first_event_code']!==$code||(int)$existing['season_year']!==$year)){
        throw new RuntimeException('This Neptune event already has a different FIRST link. Review the event mapping before changing it.');
    }
    $q=$pdo->prepare('INSERT INTO neptune_first_event_links (event_id,organization_id,season_year,first_event_code) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE first_event_code=VALUES(first_event_code)');
    $q->execute([$eventId,$org,$year,$code]);
}
function neptune_first_discovery_record(PDO $pdo,int $org,int $eventId,string $status,string $note,string $candidate=''): void {
    $q=$pdo->prepare('UPDATE neptune_first_event_links SET last_tba_discovery_at=UTC_TIMESTAMP(),tba_discovery_status=?,tba_discovery_note=?,tba_discovery_candidate=? WHERE organization_id=? AND event_id=?');
    $q->execute([substr($status,0,25),substr($note,0,240),substr($candidate,0,40),$org,$eventId]);
}
function neptune_first_discovery_due(?string $value,int $minutes=30,int $now=0): bool {
    if(!$value)return true;
    $ts=strtotime($value.' UTC');
    return !$ts||($now?:time())-$ts>=max(30,$minutes)*60;
}
