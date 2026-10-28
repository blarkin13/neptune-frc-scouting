<?php
declare(strict_types=1);
/** Explicit QA-only administrator resolution of an identified match-tag conflict.
 * One row, one choice, durable audit. No automatic conflict overwrite.
 */
require_once __DIR__.'/portable-sync-pull-client.php';

function npp_resolution_schema(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS portable_match_tag_resolution_audit (
       id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,organization_id BIGINT UNSIGNED NOT NULL,
       event_id BIGINT UNSIGNED NOT NULL,match_id BIGINT UNSIGNED NOT NULL,scout_user_id BIGINT UNSIGNED NOT NULL,
       frc_team_number INT NOT NULL,decision VARCHAR(24) NOT NULL,before_hash CHAR(64) NOT NULL,
       after_hash CHAR(64) NOT NULL,before_snapshot_json LONGTEXT NOT NULL,after_snapshot_json LONGTEXT NOT NULL,actor_local_id BIGINT UNSIGNED NOT NULL,
       created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       INDEX idx_event (organization_id,event_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS portable_cloud_match_tag_baselines (
       organization_id BIGINT UNSIGNED NOT NULL,event_id BIGINT UNSIGNED NOT NULL,
       identity_hash CHAR(64) NOT NULL,common_hash CHAR(64) NOT NULL,
       last_agreed_utc DATETIME NOT NULL, PRIMARY KEY(organization_id,event_id,identity_hash)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
function npp_resolution_local_row(PDO $pdo,int $org,int $event,int $match,int $uid,int $team,bool $lock=false): array {
    $s=$pdo->prepare('SELECT * FROM tag_scouting_match_data WHERE organization_id=? AND event_id=? AND match_id=? AND user_id=? AND frc_team_number=? LIMIT 2'.($lock?' FOR UPDATE':''));
    $s->execute([$org,$event,$match,$uid,$team]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1)throw new RuntimeException('Local Match Tag row missing or ambiguous; no changes made.');
    return $rows[0];
}
function npp_resolution_cloud_identity(PDO $pdo,int $org,int $event,int $match): array {
    $s=$pdo->prepare('SELECT comp_level,set_number,match_number,field_id FROM matches WHERE id=? AND organization_id=? AND event_id=?');
    $s->execute([$match,$org,$event]);$m=$s->fetch(PDO::FETCH_ASSOC);
    if(!$m)throw new RuntimeException('Match identity not available in local organization.');
    return ['match_comp_level'=>(string)$m['comp_level'],'match_set_number'=>(int)$m['set_number'],
            'match_number'=>(int)$m['match_number'],'match_field_id'=>(int)$m['field_id']];
}
/** Requires trusted owner/admin context from the calling controller. */
function npp_resolve_match_tag_conflict(PDO $pdo,array $config,int $actor,int $event,int $match,int $user,int $team,string $choice): array {
    if(!in_array($choice,['keep_aws','keep_portable'],true))throw new RuntimeException('Invalid conflict resolution choice.');
    $settings=neptune_portable_local_settings($config);
    $org=(int)$settings['organization_id'];
    if(empty($config['app']['portable_mode'])||$org<=0||$actor<=0||$event<=0||$match<=0||$user<=0||$team<=0)
        throw new RuntimeException('Match conflict resolution requires a local organization owner/admin.');
    if(!str_starts_with($settings['cloud_url'],'https://')||$settings['sync_token']==='')throw new RuntimeException('Cloud sync credentials missing.');
    npp_resolution_schema($pdo);
    $local=npp_resolution_local_row($pdo,$org,$event,$match,$user,$team);
    $localHash=npp_match_tags_payload_hash($local);
    $identity=npp_resolution_cloud_identity($pdo,$org,$event,$match);
    $payload=array_merge(['organization_id'=>$org,'event_id'=>$event,'user_id'=>$user,'frc_team_number'=>$team],$identity);
    $url=$settings['cloud_url'].'/api/portable-sync-resolve.php';
    $cloud=neptune_portable_http_json($url,$settings['sync_token'],array_merge($payload,['action'=>'inspect']));
    if((int)($cloud['organization_id']??0)!==$org||(int)($cloud['event_id']??0)!==$event||!is_array($cloud['record']??null))
        throw new RuntimeException('Cloud inspection returned an incorrect organization/event.');
    $cloudRow=$cloud['record'];$cloudHash=(string)($cloud['hash']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$cloudHash)||!hash_equals($cloudHash,npp_match_tags_payload_hash($cloudRow)))
        throw new RuntimeException('Cloud hash verification failed; no changes made.');
    if(hash_equals($localHash,$cloudHash))throw new RuntimeException('These two records already agree. Run Download again to refresh the baseline.');
    if((string)($local['alliance']??'')!==(string)($cloudRow['alliance']??'')
       ||(string)($local['station']??'')!==(string)($cloudRow['station']??''))
        throw new RuntimeException('Alliance or station differs. This requires a separate audited correction.');
    $identityHash=hash('sha256',implode(':',[$match,$user,$team]));
    if($choice==='keep_portable'){
        // Atomic cloud compare-and-swap is performed at the authenticated AWS endpoint.
        $out=neptune_portable_http_json($url,$settings['sync_token'],array_merge($payload,[
           'action'=>'keep_portable','expected_cloud_hash'=>$cloudHash,'actor_local_user_id'=>$actor,
           'record'=>array_intersect_key($local,array_flip(['alliance','station','scoring_contribution','tags_json','tag_weights_json','note']))
        ]));
        if(!hash_equals($localHash,(string)($out['hash']??'')))throw new RuntimeException('Cloud did not confirm the chosen portable content. Download and inspect.');
        $pdo->beginTransaction();
        try {
            $after=npp_resolution_local_row($pdo,$org,$event,$match,$user,$team,true);
            if(!hash_equals($localHash,npp_match_tags_payload_hash($after)))throw new RuntimeException('Portable record changed during cloud update. Do not sync until you review this record.');
            npp_resolution_record_local($pdo,$org,$event,$match,$user,$team,$actor,$identityHash,'keep_portable',$cloudHash,$localHash,$cloudRow,$after);
            $pdo->commit();
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return ['choice'=>'keep_portable','event_id'=>$event,'match_id'=>$match,'scout_id'=>$user,'team'=>$team,'hash'=>$localHash];
    }
    // Keep AWS is a local atomic update. Never apply a cloud row if the
    // original local payload changed while the remote inspection was running.
    $pdo->beginTransaction();
    try {
        $before=npp_resolution_local_row($pdo,$org,$event,$match,$user,$team,true);
        if(!hash_equals($localHash,npp_match_tags_payload_hash($before)))throw new RuntimeException('Portable row changed while resolving conflict. Nothing overwritten.');
        $pdo->prepare('UPDATE tag_scouting_match_data SET scoring_contribution=?,tags_json=?,tag_weights_json=?,note=?,updated_at=UTC_TIMESTAMP()
               WHERE id=? AND organization_id=? AND event_id=?')
            ->execute([$cloudRow['scoring_contribution']??null,(string)($cloudRow['tags_json']??'[]'),
               (string)($cloudRow['tag_weights_json']??'{}'),$cloudRow['note']??null,(int)$before['id'],$org,$event]);
        $saved=npp_resolution_local_row($pdo,$org,$event,$match,$user,$team,true);
        if(!hash_equals($cloudHash,npp_match_tags_payload_hash($saved)))throw new RuntimeException('Local update did not match AWS. Changes rolled back.');
        require_once dirname((string)($_SERVER['SCRIPT_FILENAME']??'')).'/../scout/_tag_scouting.php';
        $tags=json_decode((string)$saved['tags_json'],true);
        $weights=json_decode((string)$saved['tag_weights_json'],true);
        if(!is_array($tags)||!is_array($weights))throw new RuntimeException('Invalid cloud tag data.');
        tag_unified_sync_match_observation($pdo,$saved,$tags,$weights);
        npp_resolution_record_local($pdo,$org,$event,$match,$user,$team,$actor,$identityHash,'keep_aws',$localHash,$cloudHash,$before,$saved);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    // Detect a cloud edit during resolution rather than claiming full agreement.
    $verify=neptune_portable_http_json($url,$settings['sync_token'],array_merge($payload,['action'=>'inspect']));
    if(!hash_equals($cloudHash,(string)($verify['hash']??'')))throw new RuntimeException('Portable accepted AWS snapshot, but AWS changed again during resolution. Run Download and review; do not upload.');
    return ['choice'=>'keep_aws','event_id'=>$event,'match_id'=>$match,'scout_id'=>$user,'team'=>$team,'hash'=>$cloudHash];
}
function npp_resolution_record_local(PDO $pdo,int $org,int $event,int $match,int $user,int $team,int $actor,string $identity,string $decision,string $before,string $after,array $beforeRow,array $afterRow):void {
    $pdo->prepare('INSERT INTO portable_cloud_match_tag_baselines (organization_id,event_id,identity_hash,common_hash,last_agreed_utc)
        VALUES (?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE common_hash=VALUES(common_hash),last_agreed_utc=VALUES(last_agreed_utc)')
       ->execute([$org,$event,$identity,$after]);
    $pdo->prepare('INSERT INTO portable_match_tag_resolution_audit
        (organization_id,event_id,match_id,scout_user_id,frc_team_number,decision,before_hash,after_hash,before_snapshot_json,after_snapshot_json,actor_local_id)
        VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$org,$event,$match,$user,$team,$decision,$before,$after,
        json_encode(array_intersect_key($beforeRow,array_flip(['scoring_contribution','tags_json','tag_weights_json','note'])),JSON_THROW_ON_ERROR),
        json_encode(array_intersect_key($afterRow,array_flip(['scoring_contribution','tags_json','tag_weights_json','note'])),JSON_THROW_ON_ERROR),
        $actor]);
}
