<?php
declare(strict_types=1);

/** Manual final alliance results. These never overwrite FIRST/TBA matches. */
function neptune_manual_scores_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tag_manual_alliance_scores (
      organization_id BIGINT UNSIGNED NOT NULL,
      event_id BIGINT UNSIGNED NOT NULL,
      match_id BIGINT UNSIGNED NOT NULL,
      red_score SMALLINT UNSIGNED NOT NULL,
      blue_score SMALLINT UNSIGNED NOT NULL,
      revision INT UNSIGNED NOT NULL DEFAULT 1,
      updated_by BIGINT UNSIGNED NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (organization_id, match_id),
      KEY idx_tag_manual_scores_event (organization_id,event_id,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tag_manual_alliance_score_history (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      organization_id BIGINT UNSIGNED NOT NULL,
      event_id BIGINT UNSIGNED NOT NULL,
      match_id BIGINT UNSIGNED NOT NULL,
      revision INT UNSIGNED NOT NULL,
      red_score SMALLINT UNSIGNED NOT NULL,
      blue_score SMALLINT UNSIGNED NOT NULL,
      changed_by BIGINT UNSIGNED NULL,
      changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_tag_manual_history_match (organization_id,match_id,revision)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function neptune_manual_scores_table_exists(PDO $pdo): bool {
    $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute(['tag_manual_alliance_scores']);
    return (int)$s->fetchColumn()===1;
}
function neptune_manual_scores_row(PDO $pdo,int $org,int $event,int $match): ?array {
    if(!neptune_manual_scores_table_exists($pdo))return null;
    $s=$pdo->prepare('SELECT red_score,blue_score,revision,updated_by,updated_at FROM tag_manual_alliance_scores WHERE organization_id=? AND event_id=? AND match_id=?');
    $s->execute([$org,$event,$match]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}
/**
 * Display metadata for final scores already imported into `matches`.
 * Neptune currently stores the score itself without a per-score provider field.
 * An event linked to both providers is therefore labeled FIRST/TBA rather
 * than attributing the score to the wrong provider.
 */
function neptune_manual_scores_official_display(PDO $pdo,int $org,int $eventId,array $match): array {
    $red=$match['red_score']??null;
    $blue=$match['blue_score']??null;
    $out=[
        'red'=>$red!==null && is_numeric($red)?(int)$red:null,
        'blue'=>$blue!==null && is_numeric($blue)?(int)$blue:null,
        'provider'=>'Official',
    ];
    $s=$pdo->prepare('SELECT tba_event_key,event_code FROM events WHERE id=? AND organization_id=? LIMIT 1');
    $s->execute([$eventId,$org]);
    $event=$s->fetch(PDO::FETCH_ASSOC)?:[];
    $hasTba=trim((string)($event['tba_event_key']??''))!==''
        || trim((string)($match['tba_match_key']??''))!=='';
    $hasFirst=false;
    // A linked FIRST event is stronger evidence than a generic event_code.
    try {
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='neptune_first_event_links'");
        $q->execute();
        if((int)$q->fetchColumn()>0){
            $q=$pdo->prepare('SELECT 1 FROM neptune_first_event_links WHERE event_id=? AND organization_id=? LIMIT 1');
            $q->execute([$eventId,$org]);
            $hasFirst=(bool)$q->fetchColumn();
        }
    } catch(Throwable $ignored) {
        // Provider cannot be determined safely, so keep the generic label.
    }
    if($hasFirst && $hasTba)$out['provider']='FIRST/TBA';
    elseif($hasFirst)$out['provider']='FIRST';
    elseif($hasTba)$out['provider']='TBA';
    return $out;
}

/** Official FIRST/TBA score wins independently for each alliance. */
function neptune_manual_scores_effective(array $match,?array $manual): array {
    $out=[];
    foreach(['Red'=>'red','Blue'=>'blue'] as $name=>$prefix){
        $official=$match[$prefix.'_score']??null;
        if($official!==null && is_numeric($official)){
            $out[$name]=['score'=>(int)$official,'source'=>'official'];
        }elseif($manual!==null && isset($manual[$prefix.'_score'])){
            $out[$name]=['score'=>(int)$manual[$prefix.'_score'],'source'=>'manual'];
        }else{
            $out[$name]=['score'=>null,'source'=>'none'];
        }
    }
    return $out;
}
