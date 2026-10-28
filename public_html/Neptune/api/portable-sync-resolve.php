<?php
declare(strict_types=1);
/**
 * Organization-token-scoped resolution of a SINGLE existing Match Tag Scouting
 * record. This endpoint never creates a match observation or accepts an ID
 * outside the authenticated event. Keep-Portable is optimistic and atomic.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function npr_reply(array $payload, int $status=200): never {
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST') npr_reply(['ok'=>false,'error'=>'POST required'],405);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/portable.php';
require_once dirname(__DIR__,3).'/neptune_secure/portable-match-tag-hash.php';
if(!isset($pdo)||!($pdo instanceof PDO))npr_reply(['ok'=>false,'error'=>'Database unavailable'],503);
$raw=file_get_contents('php://input');
if(!is_string($raw)||strlen($raw)>20000)npr_reply(['ok'=>false,'error'=>'Request too large'],413);
$d=json_decode($raw,true);
if(!is_array($d))npr_reply(['ok'=>false,'error'=>'Invalid JSON'],400);
$org=(int)($d['organization_id']??0);$event=(int)($d['event_id']??0);
$uid=(int)($d['user_id']??0);$team=(int)($d['frc_team_number']??0);
$level=(string)($d['match_comp_level']??'');$set=(int)($d['match_set_number']??0);
$num=(int)($d['match_number']??0);$field=(int)($d['match_field_id']??0);
$action=(string)($d['action']??'');
$token=(string)($_SERVER['HTTP_X_NEPTUNE_PORTABLE_TOKEN']??'');
if($org<1||$event<1||$uid<1||$team<1||$num<1||$set<1||$field<1
  ||!in_array($level,['qm','ef','qf','sf','f'],true)
  ||!in_array($action,['inspect','keep_portable'],true)) npr_reply(['ok'=>false,'error'=>'Invalid match identity'],422);
try { neptune_portable_authenticate_token($pdo,$token,$org,$event); }
catch(Throwable $e){npr_reply(['ok'=>false,'error'=>'Unauthorized'],401);}
try {
    if($action==='keep_portable')$pdo->exec('CREATE TABLE IF NOT EXISTS portable_match_tag_resolution_audit (
       id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,organization_id BIGINT UNSIGNED NOT NULL,
       event_id BIGINT UNSIGNED NOT NULL,match_id BIGINT UNSIGNED NOT NULL,scout_user_id BIGINT UNSIGNED NOT NULL,
       frc_team_number INT NOT NULL,decision VARCHAR(24) NOT NULL,before_hash CHAR(64) NOT NULL,
       after_hash CHAR(64) NOT NULL,before_snapshot_json LONGTEXT NOT NULL,after_snapshot_json LONGTEXT NOT NULL,actor_local_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       INDEX idx_event (organization_id,event_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $s=$pdo->prepare('SELECT d.* FROM tag_scouting_match_data d
       JOIN matches m ON m.id=d.match_id AND m.organization_id=d.organization_id AND m.event_id=d.event_id
       WHERE d.organization_id=? AND d.event_id=? AND d.user_id=? AND d.frc_team_number=?
         AND m.comp_level=? AND m.set_number=? AND m.match_number=? AND m.field_id=? LIMIT 2');
    $args=[$org,$event,$uid,$team,$level,$set,$num,$field];
    if($action==='keep_portable')$pdo->beginTransaction();
    if($action==='keep_portable'){
        // Transactional compare-and-swap: hold a lock before validating hash.
        $s=$pdo->prepare('SELECT d.* FROM tag_scouting_match_data d
         JOIN matches m ON m.id=d.match_id AND m.organization_id=d.organization_id AND m.event_id=d.event_id
         WHERE d.organization_id=? AND d.event_id=? AND d.user_id=? AND d.frc_team_number=?
           AND m.comp_level=? AND m.set_number=? AND m.match_number=? AND m.field_id=? LIMIT 2 FOR UPDATE');
    }
    $s->execute($args);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1)throw new RuntimeException('Cloud Match Tag identity missing or ambiguous; no data changed.');
    $row=$rows[0];$current=npr_match_payload_hash($row);
    if($action==='inspect')npr_reply(['ok'=>true,'organization_id'=>$org,'event_id'=>$event,'hash'=>$current,
        'record'=>array_intersect_key($row,array_flip(['alliance','station','scoring_contribution','tags_json','tag_weights_json','note','updated_at']))]);
    $expected=(string)($d['expected_cloud_hash']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||!hash_equals($current,$expected)){
        $pdo->rollBack();npr_reply(['ok'=>false,'error'=>'Cloud version changed since the resolution began. Retry Download and inspect the conflict.'],409);
    }
    $incoming=$d['record']??null;
    if(!is_array($incoming))throw new RuntimeException('Invalid portable record.');
    // Identity and station remain immutable. No arbitrary columns or IDs allowed.
    if((string)($incoming['alliance']??'')!==(string)$row['alliance']
      || (string)($incoming['station']??'')!==(string)($row['station']??''))
      throw new RuntimeException('Alliance/station differs; use a separate correction workflow.');
    $tags=json_decode((string)($incoming['tags_json']??''),true);
    $weights=json_decode((string)($incoming['tag_weights_json']??''),true);
    $note=$incoming['note']??null;$scoring=$incoming['scoring_contribution']??null;
    if(!is_array($tags)||!array_is_list($tags)||count($tags)>100||!is_array($weights)||count($weights)>100
      ||($note!==null&&!is_string($note))||mb_strlen((string)$note)>500
      ||($scoring!==null&&$scoring!==''&&(!is_numeric($scoring)||(int)$scoring<0||(int)$scoring>100)))
        throw new RuntimeException('Portable match-tag payload fails validation.');
    foreach($tags as $tag)if(!is_string($tag)||strlen($tag)>100||!preg_match('/^[a-zA-Z0-9_:-]+$/D',$tag))throw new RuntimeException('Invalid tag code.');
    $newRow=$row;
    foreach(['scoring_contribution','tags_json','tag_weights_json','note'] as $key)$newRow[$key]=$incoming[$key]??null;
    $newHash=npr_match_payload_hash($newRow);
    $auditBefore=$current;
    $originalRow=$row;
    $pdo->prepare('UPDATE tag_scouting_match_data SET scoring_contribution=?,tags_json=?,tag_weights_json=?,note=?,updated_at=UTC_TIMESTAMP()
          WHERE id=? AND organization_id=? AND event_id=?')
       ->execute([$scoring===''?null:$scoring,(string)$incoming['tags_json'],(string)$incoming['tag_weights_json'],$note,(int)$row['id'],$org,$event]);
    $reload=$pdo->prepare('SELECT * FROM tag_scouting_match_data WHERE id=? AND organization_id=? FOR UPDATE');
    $reload->execute([(int)$row['id'],$org]);$after=$reload->fetch(PDO::FETCH_ASSOC);
    if(!$after||!hash_equals($newHash,npr_match_payload_hash($after)))throw new RuntimeException('Post-write hash mismatch; cloud changes rolled back.');
    require_once dirname(__DIR__).'/scout/_tag_scouting.php';
    tag_unified_sync_match_observation($pdo,$after,$tags,$weights);
    $audit=$pdo->prepare('INSERT INTO portable_match_tag_resolution_audit
        (organization_id,event_id,match_id,scout_user_id,frc_team_number,decision,before_hash,after_hash,before_snapshot_json,after_snapshot_json,actor_local_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $actor=(int)($d['actor_local_user_id']??0);
    $audit->execute([$org,$event,(int)$row['match_id'],$uid,$team,'keep_portable',$auditBefore,$newHash,
        json_encode(array_intersect_key($originalRow,array_flip(['scoring_contribution','tags_json','tag_weights_json','note'])),JSON_THROW_ON_ERROR),
        json_encode(array_intersect_key($after,array_flip(['scoring_contribution','tags_json','tag_weights_json','note'])),JSON_THROW_ON_ERROR),
        $actor>0?$actor:null]);
    $pdo->commit();
    npr_reply(['ok'=>true,'organization_id'=>$org,'event_id'=>$event,'hash'=>$newHash,'decision'=>'keep_portable']);
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[Neptune match tag resolution] '.$e->getMessage());
    npr_reply(['ok'=>false,'error'=>$e->getMessage()],409);
}
