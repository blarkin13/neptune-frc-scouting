<?php
declare(strict_types=1);

function tag_unified_table_exists(PDO $pdo,string $table): bool {
    $q=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
}
function tag_unified_column_exists(PDO $pdo,string $table,string $column): bool {
    $q=$pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $q->execute([$table,$column]);
    return (bool)$q->fetchColumn();
}
function tag_unified_index_exists(PDO $pdo,string $table,string $index): bool {
    $q=$pdo->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1");
    $q->execute([$table,$index]);
    return (bool)$q->fetchColumn();
}

function tag_unified_valid_user_id(PDO $pdo,int $organizationId,int $userId): ?int {
    if($organizationId<1||$userId<1)return null;
    $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND organization_id=? LIMIT 1');
    $q->execute([$userId,$organizationId]);
    $id=$q->fetchColumn();
    return $id!==false?(int)$id:null;
}
function tag_unified_match_tag_seed_catalog(): array {
    return [
      'scoring_a_lot'=>['Scoring a Lot','Offense','fa-solid fa-bolt','positive',1,0,1,10],
      'fast_cycles'=>['Fast Cycles','Offense','fa-solid fa-gauge-high','positive',1,0,1,20],
      'hard_to_defend'=>['Hard to Defend','Offense','fa-solid fa-shield-halved','positive',1,0,1,30],
      'struggles_defense'=>['Struggles Under Defense','Offense','fa-solid fa-shield-virus','warning',1,0,1,40],
      'impressive_auton'=>['Impressive Auton','Auton','fa-solid fa-rocket','positive',1,0,1,50],
      'consistent_auton'=>['Consistent Auton','Auton','fa-solid fa-repeat','positive',1,0,1,60],
      'weak_auton'=>['Weak Auton','Auton','fa-solid fa-forward-step','warning',1,0,1,70],
      'no_auton'=>['No Auton','Auton','fa-solid fa-circle-xmark','warning',1,0,1,80],
      'good_defense'=>['Good Defense','Defense','fa-solid fa-shield','positive',1,0,1,90],
      'elite_defense'=>['Elite Defense','Defense','fa-solid fa-user-shield','positive',1,0,1,100],
      'counter_defense'=>['Good Counter-Defense','Defense','fa-solid fa-person-running','positive',1,0,1,110],
      'no_defense'=>['No Defense','Defense','fa-solid fa-circle-xmark','info',1,0,1,120],
      'smart_driver'=>['Smart Driver','Driver','fa-solid fa-brain','positive',1,0,1,130],
      'smooth_driver'=>['Smooth Driver','Driver','fa-solid fa-route','positive',1,0,1,140],
      'great_alliance_partner'=>['Great Alliance Partner','Strategy','fa-solid fa-people-group','positive',1,0,1,150],
      'feeder_support'=>['Strong Feeder / Support','Strategy','fa-solid fa-arrows-turn-to-dots','positive',1,0,1,160],
      'strong_endgame'=>['Strong Endgame','Endgame','fa-solid fa-trophy','positive',1,0,1,170],
      'reliable_endgame'=>['Reliable Endgame','Endgame','fa-solid fa-flag-checkered','positive',1,0,1,180],
      'slow_endgame'=>['Slow Endgame','Endgame','fa-solid fa-hourglass-half','warning',1,0,1,190],
      'failed_endgame'=>['Failed Endgame','Endgame','fa-solid fa-circle-xmark','warning',1,0,1,200],
      'clutch'=>['Clutch','General','fa-solid fa-fire','positive',1,0,1,210],
      'consistent'=>['Consistent','General','fa-solid fa-circle-check','positive',1,1,1,220],
      'versatile'=>['Versatile','General','fa-solid fa-shuffle','positive',1,1,1,230],
      'inconsistent'=>['Inconsistent','Reliability','fa-solid fa-wave-square','warning',1,1,1,240],
      'penalty_risk'=>['Penalty Risk','Discipline','fa-solid fa-triangle-exclamation','warning',1,0,1,250],
      'mechanical_issues'=>['Mechanical Issues','Reliability','fa-solid fa-screwdriver-wrench','critical',1,1,1,260],
      'disabled'=>['Disabled / Dead','Reliability','fa-solid fa-power-off','critical',1,1,1,270],
    ];
}
function tag_unified_ensure_schema(PDO $pdo): void {
    static $done=false;
    if($done)return;
    $lockName='neptune_tag_scouting_unified_v1';
    $q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lockName]);
    if((int)$q->fetchColumn()!==1)throw new RuntimeException('Could not acquire Tag Scouting schema lock.');
    try{
        // Move the original structured Match Tag table out of the common observation name first.
        if(tag_unified_table_exists($pdo,'tag_scouting_observations')
            && tag_unified_column_exists($pdo,'tag_scouting_observations','scoring_contribution')
            && !tag_unified_table_exists($pdo,'tag_scouting_match_data')){
            $pdo->exec('RENAME TABLE tag_scouting_observations TO tag_scouting_match_data');
        }
        if(tag_unified_table_exists($pdo,'impact_scouting_observations')&&!tag_unified_table_exists($pdo,'tag_scouting_match_data')){
            $pdo->exec('RENAME TABLE impact_scouting_observations TO tag_scouting_match_data');
        }
        foreach([
            'spot_observations'=>'tag_scouting_observations',
            'spot_observation_media'=>'tag_scouting_media',
            'spot_observation_tags'=>'tag_scouting_observation_tags',
            'spot_scout_preferences'=>'tag_scouting_preferences',
            'spot_tags'=>'tag_scouting_tags',
            'spot_tag_visibility'=>'tag_scouting_tag_visibility',
        ] as $old=>$new){
            if(tag_unified_table_exists($pdo,$old)&&!tag_unified_table_exists($pdo,$new))$pdo->exec("RENAME TABLE `{$old}` TO `{$new}`");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS tag_scouting_observations (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,uuid CHAR(36) NOT NULL,organization_id BIGINT UNSIGNED NOT NULL,
          event_id BIGINT UNSIGNED NULL,match_id BIGINT UNSIGNED NULL,field_id SMALLINT UNSIGNED NULL,frc_team_number INT UNSIGNED NOT NULL,
          context ENUM('match','pit','team') NOT NULL DEFAULT 'team',entry_mode ENUM('auto','manual') NOT NULL DEFAULT 'manual',source_key VARCHAR(190) NULL,
          note TEXT NULL,severity ENUM('positive','info','warning','critical') NOT NULL DEFAULT 'info',status ENUM('open','resolved') NOT NULL DEFAULT 'open',
          created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          resolved_by BIGINT UNSIGNED NULL,resolved_at DATETIME NULL,resolution_note TEXT NULL,
          PRIMARY KEY(id),UNIQUE KEY uq_tag_observation_uuid(uuid),UNIQUE KEY uq_tag_observation_source(organization_id,source_key),
          KEY idx_tag_obs_org_recent(organization_id,created_at),KEY idx_tag_obs_org_team(organization_id,frc_team_number,created_at),
          KEY idx_tag_obs_org_event(organization_id,event_id,created_at),KEY idx_tag_obs_review(organization_id,status,severity,created_at),KEY idx_tag_obs_match(match_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tag_scouting_match_data (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,observation_id BIGINT UNSIGNED NULL,organization_id BIGINT UNSIGNED NOT NULL,event_id BIGINT UNSIGNED NOT NULL,
          match_id BIGINT UNSIGNED NOT NULL,game_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,frc_team_number INT UNSIGNED NOT NULL,
          alliance ENUM('Red','Blue') NOT NULL,station TINYINT UNSIGNED NULL,scoring_contribution TINYINT UNSIGNED NULL,tags_json LONGTEXT NOT NULL,tag_weights_json LONGTEXT NULL,
          note VARCHAR(500) NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY(id),UNIQUE KEY uq_tag_match_observer_robot(organization_id,match_id,user_id,frc_team_number),UNIQUE KEY uq_tag_match_observation(observation_id),
          KEY idx_tag_match_robot(organization_id,event_id,frc_team_number),KEY idx_tag_match_match(organization_id,match_id),KEY idx_tag_match_user(organization_id,user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tag_scouting_tags (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,organization_id BIGINT UNSIGNED NULL,seed_key VARCHAR(100) NULL,label VARCHAR(120) NOT NULL,slug VARCHAR(120) NOT NULL,
          category VARCHAR(80) NOT NULL DEFAULT 'General',icon VARCHAR(120) NOT NULL DEFAULT 'fa-solid fa-tag',severity ENUM('positive','info','warning','critical') NOT NULL DEFAULT 'info',
          match_enabled TINYINT(1) NOT NULL DEFAULT 1,pit_enabled TINYINT(1) NOT NULL DEFAULT 1,team_enabled TINYINT(1) NOT NULL DEFAULT 1,active TINYINT(1) NOT NULL DEFAULT 1,
          sort_order INT NOT NULL DEFAULT 100,created_by BIGINT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY(id),UNIQUE KEY uq_tag_seed(seed_key),UNIQUE KEY uq_tag_org_slug(organization_id,slug),KEY idx_tag_visible(organization_id,active,category,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tag_scouting_tag_visibility (organization_id BIGINT UNSIGNED NOT NULL,tag_id BIGINT UNSIGNED NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(organization_id,tag_id),KEY idx_tag_vis_tag(tag_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tag_scouting_observation_tags (observation_id BIGINT UNSIGNED NOT NULL,tag_id BIGINT UNSIGNED NOT NULL,weight TINYINT UNSIGNED NULL,PRIMARY KEY(observation_id,tag_id),KEY idx_tag_ot_tag(tag_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tag_scouting_media (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,observation_id BIGINT UNSIGNED NOT NULL,organization_id BIGINT UNSIGNED NOT NULL,media_type ENUM('photo','video') NOT NULL,storage_relpath VARCHAR(500) NOT NULL,original_filename VARCHAR(255) NULL,mime_type VARCHAR(100) NOT NULL,file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,width INT UNSIGNED NULL,height INT UNSIGNED NULL,duration_seconds DECIMAL(8,2) NULL,uploaded_by BIGINT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_tag_media_obs(observation_id),KEY idx_tag_media_org(organization_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tag_scouting_preferences (organization_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,event_id BIGINT UNSIGNED NULL,field_id SMALLINT UNSIGNED NULL,auto_follow TINYINT(1) NOT NULL DEFAULT 1,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(organization_id,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Upgrade renamed legacy tables to the unified schema.
        if(!tag_unified_column_exists($pdo,'tag_scouting_observations','source_key'))$pdo->exec("ALTER TABLE tag_scouting_observations ADD COLUMN source_key VARCHAR(190) NULL AFTER entry_mode");
        if(!tag_unified_column_exists($pdo,'tag_scouting_observations','updated_at'))$pdo->exec("ALTER TABLE tag_scouting_observations ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        if(!tag_unified_index_exists($pdo,'tag_scouting_observations','uq_tag_observation_source'))$pdo->exec("ALTER TABLE tag_scouting_observations ADD UNIQUE KEY uq_tag_observation_source(organization_id,source_key)");
        $ct=(string)$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tag_scouting_observations' AND COLUMN_NAME='context' LIMIT 1")->fetchColumn();
        if(str_contains($ct,"'general'")||!str_contains($ct,"'team'")){
            $pdo->exec("ALTER TABLE tag_scouting_observations MODIFY context ENUM('match','pit','general','team') NOT NULL DEFAULT 'team'");
            $pdo->exec("UPDATE tag_scouting_observations SET context='team' WHERE context='general'");
            $pdo->exec("ALTER TABLE tag_scouting_observations MODIFY context ENUM('match','pit','team') NOT NULL DEFAULT 'team'");
        }
        if(tag_unified_column_exists($pdo,'tag_scouting_tags','general_enabled')&&!tag_unified_column_exists($pdo,'tag_scouting_tags','team_enabled'))$pdo->exec("ALTER TABLE tag_scouting_tags CHANGE COLUMN general_enabled team_enabled TINYINT(1) NOT NULL DEFAULT 1");
        if(!tag_unified_column_exists($pdo,'tag_scouting_tags','team_enabled'))$pdo->exec("ALTER TABLE tag_scouting_tags ADD COLUMN team_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER pit_enabled");
        if(!tag_unified_column_exists($pdo,'tag_scouting_observation_tags','weight'))$pdo->exec("ALTER TABLE tag_scouting_observation_tags ADD COLUMN weight TINYINT UNSIGNED NULL AFTER tag_id");
        if(!tag_unified_column_exists($pdo,'tag_scouting_match_data','observation_id'))$pdo->exec("ALTER TABLE tag_scouting_match_data ADD COLUMN observation_id BIGINT UNSIGNED NULL AFTER id");
        if(!tag_unified_index_exists($pdo,'tag_scouting_match_data','uq_tag_match_observation'))$pdo->exec("ALTER TABLE tag_scouting_match_data ADD UNIQUE KEY uq_tag_match_observation(observation_id)");

        // Reuse an existing global Tag with the same slug when the former Spot
        // catalog already contains an equivalent definition. This prevents the
        // unified Pit/Team pickers from showing duplicate labels such as Fast Cycles.
        $findSeed=$pdo->prepare("SELECT id,seed_key FROM tag_scouting_tags WHERE organization_id IS NULL AND (seed_key=? OR slug=?) ORDER BY CASE WHEN seed_key=? THEN 0 ELSE 1 END,id LIMIT 1");
        $insertSeed=$pdo->prepare("INSERT INTO tag_scouting_tags (organization_id,seed_key,label,slug,category,icon,severity,match_enabled,pit_enabled,team_enabled,active,sort_order) VALUES(NULL,?,?,?,?,?,?,?,?,?,1,?)");
        $touchSeed=$pdo->prepare("UPDATE tag_scouting_tags SET match_enabled=1,active=1 WHERE id=?");
        foreach(tag_unified_match_tag_seed_catalog() as $code=>$cfg){
            [$label,$cat,$icon,$sev,$match,$pit,$team,$sort]=$cfg;
            $seedKey='match:'.$code;$slug=str_replace('_','-',$code);
            $findSeed->execute([$seedKey,$slug,$seedKey]);$existing=$findSeed->fetch();
            if($existing){$touchSeed->execute([(int)$existing['id']]);continue;}
            $insertSeed->execute([$seedKey,$label,$slug,$cat,$icon,$sev,$match,$pit,$team,$sort]);
        }

        // Backfill the original structured Match Tag rows into the common observation stream.
        $rows=$pdo->query("SELECT * FROM tag_scouting_match_data WHERE observation_id IS NULL ORDER BY id")->fetchAll();
        foreach($rows as $row)tag_unified_sync_match_observation($pdo,$row,json_decode((string)($row['tags_json']??'[]'),true)?:[],json_decode((string)($row['tag_weights_json']??'{}'),true)?:[]);
        $done=true;
    } finally {
        try{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}catch(Throwable){}
    }
}

function tag_unified_sync_match_observation(PDO $pdo,array $row,array $selectedTags,array $weights): int {
    $id=(int)($row['id']??0);$org=(int)($row['organization_id']??0);if($id<1||$org<1)throw new RuntimeException('Invalid Match Tag row.');
    $source='match-data:'.$id;$observationId=(int)($row['observation_id']??0);
    if($observationId){$q=$pdo->prepare('SELECT id FROM tag_scouting_observations WHERE id=? AND organization_id=? LIMIT 1');$q->execute([$observationId,$org]);if(!(int)$q->fetchColumn())$observationId=0;}
    if(!$observationId){$q=$pdo->prepare('SELECT id FROM tag_scouting_observations WHERE organization_id=? AND source_key=? LIMIT 1');$q->execute([$org,$source]);$observationId=(int)($q->fetchColumn()?:0);}
    $fieldId=null;$q=$pdo->prepare('SELECT field_id FROM matches WHERE id=? AND organization_id=? LIMIT 1');$q->execute([(int)$row['match_id'],$org]);$fv=$q->fetchColumn();if($fv!==false)$fieldId=(int)$fv;
    $tagMap=[];$severity='info';$rank=['info'=>1,'positive'=>2,'warning'=>3,'critical'=>4];
    if($selectedTags){
        $keys=array_map(static fn($v)=>'match:'.(string)$v,$selectedTags);
        $slugs=array_map(static fn($v)=>str_replace('_','-',(string)$v),$selectedTags);
        $phKeys=implode(',',array_fill(0,count($keys),'?'));$phSlugs=implode(',',array_fill(0,count($slugs),'?'));
        $q=$pdo->prepare("SELECT id,seed_key,slug,severity FROM tag_scouting_tags WHERE seed_key IN ($phKeys) OR (organization_id IS NULL AND slug IN ($phSlugs)) ORDER BY CASE WHEN seed_key LIKE 'match:%' THEN 0 ELSE 1 END,id");
        $q->execute(array_merge($keys,$slugs));
        $selectedSet=array_fill_keys(array_map('strval',$selectedTags),true);
        foreach($q->fetchAll() as $t){
            $seedKey=(string)($t['seed_key']??'');
            $code=str_starts_with($seedKey,'match:')?substr($seedKey,6):str_replace('-','_',(string)$t['slug']);
            if(!isset($selectedSet[$code])||isset($tagMap[$code]))continue;
            $tagMap[$code]=(int)$t['id'];$sev=(string)$t['severity'];if(($rank[$sev]??1)>($rank[$severity]??1))$severity=$sev;
        }
    }
    $note=trim((string)($row['note']??''));
    // Historical/synthetic Match Tag rows can use observer ids that never existed
    // in Neptune's users table. The unified observation table has a foreign key
    // on created_by, so preserve real users and store NULL for synthetic/deleted ones.
    $createdBy=tag_unified_valid_user_id($pdo,$org,(int)($row['user_id']??0));
    if($observationId){
        $q=$pdo->prepare("UPDATE tag_scouting_observations SET event_id=?,match_id=?,field_id=?,frc_team_number=?,context='match',entry_mode='auto',source_key=?,note=?,severity=?,created_by=? WHERE id=? AND organization_id=?");
        $q->execute([(int)$row['event_id'],(int)$row['match_id'],$fieldId,(int)$row['frc_team_number'],$source,$note!==''?$note:null,$severity,$createdBy,$observationId,$org]);
    }else{
        $uuid=function_exists('uuidv4')?uuidv4():sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',random_int(0,65535),random_int(0,65535),random_int(0,65535),random_int(16384,20479),random_int(32768,49151),random_int(0,65535),random_int(0,65535),random_int(0,65535));
        $q=$pdo->prepare("INSERT INTO tag_scouting_observations (uuid,organization_id,event_id,match_id,field_id,frc_team_number,context,entry_mode,source_key,note,severity,status,created_by,created_at) VALUES(?,?,?,?,?,?,'match','auto',?,?,?,'open',?,?)");
        $created=(string)($row['created_at']??'');$q->execute([$uuid,$org,(int)$row['event_id'],(int)$row['match_id'],$fieldId,(int)$row['frc_team_number'],$source,$note!==''?$note:null,$severity,$createdBy,$created!==''?$created:gmdate('Y-m-d H:i:s')]);$observationId=(int)$pdo->lastInsertId();
    }
    $pdo->prepare('UPDATE tag_scouting_match_data SET observation_id=? WHERE id=?')->execute([$observationId,$id]);
    $pdo->prepare('DELETE FROM tag_scouting_observation_tags WHERE observation_id=?')->execute([$observationId]);
    if($tagMap){$ins=$pdo->prepare('INSERT INTO tag_scouting_observation_tags (observation_id,tag_id,weight) VALUES(?,?,?)');foreach($selectedTags as $code){if(!isset($tagMap[$code]))continue;$w=isset($weights[$code])&&is_numeric($weights[$code])?(int)$weights[$code]:null;if($w!==null&&($w<1||$w>5))$w=null;$ins->execute([$observationId,$tagMap[$code],$w]);}}
    return $observationId;
}

function tag_unified_tabs_html(string $active,int $eventId=0,int $team=0): string {
    $tabs=['match'=>['Match','fa-solid fa-hashtag'],'pit'=>['Pit','fa-solid fa-screwdriver-wrench'],'team'=>['Team','fa-solid fa-binoculars']];
    $html='<nav class="tag-unified-tabs" aria-label="Tag Scouting context">';
    foreach($tabs as $key=>[$label,$icon]){$q=['tab'=>$key];if($eventId>0)$q['event_id']=$eventId;if($team>0)$q['team']=$team;$url=base_url('scout/tag.php?'.http_build_query($q));$html.='<a class="tag-unified-tab'.($active===$key?' active':'').'" href="'.e($url).'"'.($active===$key?' aria-current="page"':'').'><i class="'.e($icon).'"></i> '.e($label).'</a>';}
    return $html.'</nav>';
}
