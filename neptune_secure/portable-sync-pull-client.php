<?php
declare(strict_types=1);
/** Manual AWS -> portable insert-only synchronization. Never rewrites local scouting. */
require_once __DIR__.'/portable-sync-client.php';

function npp_local_user(PDO $pdo, int $org, int $userId, bool $required=false): ?int {
    if($userId<=0) {
        if($required) throw new RuntimeException('Cloud record has no required user ID.');
        return null;
    }
    $s=$pdo->prepare('SELECT id FROM users WHERE id=? AND organization_id=? LIMIT 1');
    $s->execute([$userId,$org]);
    if(!$s->fetchColumn()) throw new RuntimeException('Cloud user '.$userId.' is missing from the portable organization. Update users safely before importing this record.');
    return $userId;
}
function npp_local_team(PDO $pdo,int $org,int $id): ?int {
    if($id<=0)return null;
    $s=$pdo->prepare('SELECT id FROM teams WHERE id=? AND organization_id=? LIMIT 1');
    $s->execute([$id,$org]);
    if(!$s->fetchColumn())throw new RuntimeException('Team '.$id.' is missing from the portable organization.');
    return $id;
}
function npp_local_match(PDO $pdo,int $org,int $event,array $row,bool $required=true): ?int {
    if(empty($row['match_id']) && !$required)return null;
    $level=(string)($row['match_comp_level']??'');
    $num=(int)($row['match_number']??0);
    $set=(int)($row['match_set_number']??1);
    $field=(int)($row['match_field_id']??1);
    if($level===''||$num<=0)throw new RuntimeException('AWS record references a match without a portable match key.');
    $s=$pdo->prepare('SELECT id FROM matches WHERE organization_id=? AND event_id=? AND comp_level=? AND set_number=? AND match_number=? AND field_id=? LIMIT 2');
    $s->execute([$org,$event,$level,$set,$num,$field]);
    $ids=$s->fetchAll(PDO::FETCH_COLUMN);
    if(count($ids)!==1)throw new RuntimeException('Match '.$level.'-'.$num.' field '.$field.' is missing or ambiguous on portable server.');
    return (int)$ids[0];
}
function npp_uuid(string $value,bool $historical=false): string {
    $value=strtolower(trim($value));
    if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$value)
        || $value==='00000000-0000-0000-0000-000000000000') throw new RuntimeException('Cloud record contains an invalid UUID.');
    return $value;
}
function npp_enum(string $value,array $allowed,string $fallback): string {return in_array($value,$allowed,true)?$value:$fallback;}
function npp_tag_id(PDO $pdo,int $org,array $t): int {
    $scope=$t['tag_organization_id']??$t['organization_id']??null;
    if($scope!==null && (int)$scope!==$org)throw new RuntimeException('Foreign-organization tag reference.');
    $seed=trim((string)($t['seed_key']??''));$slug=trim((string)($t['slug']??''));
    $s=$pdo->prepare('SELECT id,organization_id FROM tag_scouting_tags WHERE seed_key=? LIMIT 2');
    if($seed!==''){$s->execute([$seed]);$row=$s->fetch(PDO::FETCH_ASSOC);if($row){
        if(($row['organization_id']===null)!==($scope===null)||($scope!==null && (int)$row['organization_id']!==$org))throw new RuntimeException('Tag seed scope collision: '.$seed);
        return (int)$row['id'];
    }}
    if($slug==='')throw new RuntimeException('Tag lacks seed key and slug.');
    $s=$pdo->prepare('SELECT id FROM tag_scouting_tags WHERE organization_id <=> ? AND slug=? LIMIT 2');
    $s->execute([$scope,$slug]);$matches=$s->fetchAll(PDO::FETCH_COLUMN);
    if(count($matches)>1)throw new RuntimeException('Ambiguous tag slug: '.$slug);
    if($matches)return (int)$matches[0];
    return 0;
}
/**
 * Accept an exact replay of a historical tag-match record without needing a
 * user that was already missing when both databases received the snapshot.
 * This does not authorize inserts, edits, or foreign-organization references.
 * The caller resolves local match, game, and observation identifiers first.
 */
function npp_tag_match_exact_replay(array $local,array $cloud,int $match,int $game,?int $observation): bool {
    $uid=$cloud['user_id']??null;
    if((!is_int($uid) && (!is_string($uid) || !preg_match('/^[0-9]+$/D',$uid))) || (int)$uid<=0)return false;
    $expected=[
        'observation_id'=>$observation,
        'match_id'=>$match,
        'game_id'=>$game,
        'user_id'=>(int)$uid,
        'frc_team_number'=>(int)($cloud['frc_team_number']??0),
        'alliance'=>(string)($cloud['alliance']??''),
        'station'=>isset($cloud['station'])?(int)$cloud['station']:null,
        'scoring_contribution'=>isset($cloud['scoring_contribution'])&&$cloud['scoring_contribution']!==''?(int)$cloud['scoring_contribution']:null,
        'tags_json'=>(string)($cloud['tags_json']??'[]'),
        'tag_weights_json'=>$cloud['tag_weights_json']??null,
        'note'=>$cloud['note']??null,
        'created_at'=>$cloud['created_at']??null,
        'updated_at'=>$cloud['updated_at']??null,
    ];
    foreach($expected as $field=>$value){
        $actual=$local[$field]??null;
        if($value===null || $actual===null){
            if($value!==$actual)return false;
        }elseif((string)$value!==(string)$actual){
            return false;
        }
    }
    return true;
}

function npp_apply_cloud_row(PDO $pdo,int $org,int $event,string $section,array $r): string {
    if(isset($r['organization_id']) && $r['organization_id']!==null && (int)$r['organization_id']!==$org)throw new RuntimeException('Cloud returned foreign organization data.');
    if($section!=='tag_definitions' && isset($r['event_id']) && $r['event_id']!==null && (int)$r['event_id']!==$event)throw new RuntimeException('Cloud returned foreign event data.');
    switch($section){
        case 'tag_definitions':
            if($r['organization_id']!==null && (int)$r['organization_id']!==$org)throw new RuntimeException('Foreign tag definition.');
            if(npp_tag_id($pdo,$org,$r))return 'existing';
            $ins=$pdo->prepare('INSERT INTO tag_scouting_tags (organization_id,seed_key,label,slug,category,icon,severity,match_enabled,pit_enabled,team_enabled,active,sort_order,created_by,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([$r['organization_id'], $r['seed_key'], $r['label'], $r['slug'], $r['category'], $r['icon'],
                $r['severity'],(int)$r['match_enabled'],(int)$r['pit_enabled'],(int)$r['team_enabled'],
                (int)$r['active'],(int)$r['sort_order'],($r['organization_id']===null ? null : npp_local_user($pdo,$org,(int)($r['created_by']??0))),
                $r['created_at'],$r['updated_at']]);
            return 'inserted';
        case 'scout_sessions':
            $uuid=npp_uuid((string)($r['uuid']??''));
            $s=$pdo->prepare('SELECT id FROM scout_sessions WHERE uuid=? AND organization_id=?');$s->execute([$uuid,$org]);
            if($s->fetchColumn())return 'existing';
            $match=npp_local_match($pdo,$org,$event,$r);
            $s=$pdo->prepare('INSERT INTO scout_sessions
              (uuid,organization_id,event_id,match_id,user_id,scout_name,frc_team_number,alliance,station,field_id,match_run_number,status,last_seen_at,created_at)
              VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$uuid,$org,$event,$match,npp_local_user($pdo,$org,(int)($r['user_id']??0)),
                $r['scout_name'],(int)$r['frc_team_number'],npp_enum((string)$r['alliance'],['Red','Blue'],'Red'),
                $r['station'],$r['field_id'],(int)$r['match_run_number'],$r['status'],$r['last_seen_at'],$r['created_at']]);
            return 'inserted';
        case 'scouting_actions':
            $uuid=npp_uuid((string)($r['uuid']??''));
            $s=$pdo->prepare('SELECT id FROM scouting_actions WHERE uuid=? AND organization_id=?');$s->execute([$uuid,$org]);
            if($s->fetchColumn())return 'existing';
            $match=npp_local_match($pdo,$org,$event,$r);
            $suuid=(string)($r['scout_session_uuid']??'');$session=null;
            if($suuid!==''){
                $s=$pdo->prepare('SELECT id FROM scout_sessions WHERE uuid=? AND organization_id=?');$s->execute([npp_uuid($suuid),$org]);
                $session=(int)($s->fetchColumn()?:0);
                if(!$session)throw new RuntimeException('Action session UUID not found on portable.');
            }
            $game=$pdo->prepare('SELECT game_id FROM events WHERE id=? AND organization_id=?');$game->execute([$event,$org]);$gameId=(int)$game->fetchColumn();
            $s=$pdo->prepare('INSERT INTO scouting_actions
              (uuid,organization_id,owner_team_id,event_id,match_id,scout_session_id,game_id,frc_team_number,alliance,
               action_code,action_name,action_type,location,result,points,match_time_sec,match_run_number,phase,source,
               source_ip,created_by,recorded_at,legacy_source,legacy_id,deleted_at,deleted_by,deletion_reason)
              VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$uuid,$org,npp_local_team($pdo,$org,(int)($r['owner_team_id']??0)),$event,$match,$session,$gameId,
               (int)$r['frc_team_number'],$r['alliance'],$r['action_code'],$r['action_name'],$r['action_type'],$r['location'],
               $r['result'],$r['points'],$r['match_time_sec'],$r['match_run_number'],$r['phase'],$r['source'],
               $r['source_ip'],npp_local_user($pdo,$org,(int)($r['created_by']??0)),$r['recorded_at'],
               $r['legacy_source'],$r['legacy_id'],$r['deleted_at'],npp_local_user($pdo,$org,(int)($r['deleted_by']??0)),$r['deletion_reason']]);
            return 'inserted';
        case 'tag_observations':
            $uuid=npp_uuid((string)($r['uuid']??''));
            $s=$pdo->prepare('SELECT id FROM tag_scouting_observations WHERE uuid=? AND organization_id=?');$s->execute([$uuid,$org]);
            if($s->fetchColumn())return 'existing';
            $match=npp_local_match($pdo,$org,$event,$r,false);
            $s=$pdo->prepare('INSERT INTO tag_scouting_observations
                (uuid,organization_id,event_id,match_id,field_id,frc_team_number,context,entry_mode,source_key,note,severity,status,created_by,created_at,updated_at,resolved_by,resolved_at,resolution_note)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$uuid,$org,$r['event_id'],$match,$r['field_id'],(int)$r['frc_team_number'],$r['context'],
                $r['entry_mode'],$r['source_key'],$r['note'],$r['severity'],$r['status'],
                npp_local_user($pdo,$org,(int)($r['created_by']??0)),$r['created_at'],$r['updated_at'],
                npp_local_user($pdo,$org,(int)($r['resolved_by']??0)),$r['resolved_at'],$r['resolution_note']]);
            $obs=(int)$pdo->lastInsertId();
            $tags=$pdo->prepare('INSERT INTO tag_scouting_observation_tags(observation_id,tag_id,weight) VALUES (?,?,?)');
            foreach(($r['tag_links']??[]) as $link){
                if(!is_array($link))throw new RuntimeException('Invalid observation tag link.');
                $tag=npp_tag_id($pdo,$org,$link);
                if($tag<=0)throw new RuntimeException('Cloud observation references an unknown tag: '.(string)($link['slug']??''));
                $tags->execute([$obs,$tag,$link['weight']]);
            }
            return 'inserted';
        case 'tag_match_data':
            $match=npp_local_match($pdo,$org,$event,$r);
            $team=(int)($r['frc_team_number']??0);
            $incomingUser=(int)($r['user_id']??0);
            $obs=null;
            if(!empty($r['observation_uuid'])){
                $uuid=npp_uuid((string)$r['observation_uuid']);
                $s=$pdo->prepare('SELECT id FROM tag_scouting_observations WHERE uuid=? AND organization_id=?');
                $s->execute([$uuid,$org]);$obs=(int)($s->fetchColumn()?:0);
                if(!$obs)throw new RuntimeException('Tag-match observation not yet imported: '.$uuid);
            }
            $game=$pdo->prepare('SELECT game_id FROM events WHERE id=? AND organization_id=?');
            $game->execute([$event,$org]);$gameId=(int)$game->fetchColumn();
            if($gameId<1)throw new RuntimeException('Portable event has no game.');

            // Look for the exact identity FIRST. Some historical Event 14 data
            // is identical on cloud + Mac but refers to a since-deleted user.
            // Never require that user for an exact no-write replay.
            $s=$pdo->prepare('SELECT observation_id,match_id,game_id,user_id,frc_team_number,alliance,station,scoring_contribution,tags_json,tag_weights_json,note,created_at,updated_at
                FROM tag_scouting_match_data WHERE organization_id=? AND event_id=? AND match_id=? AND user_id=? AND frc_team_number=? LIMIT 2');
            $s->execute([$org,$event,$match,$incomingUser,$team]);
            $existing=$s->fetchAll(PDO::FETCH_ASSOC);
            if(count($existing)>1)throw new RuntimeException('Ambiguous existing tag-match identity.');
            if($existing){
                if(npp_tag_match_exact_replay($existing[0],$r,$match,$gameId,$obs))return 'existing';
                // Keep existing local rows unchanged. But if attribution no
                // longer resolves, treat differences as an actual conflict.
                $userCheck=$pdo->prepare('SELECT id FROM users WHERE id=? AND organization_id=? LIMIT 1');
                $userCheck->execute([$incomingUser,$org]);
                if(!$userCheck->fetchColumn())throw new RuntimeException('Existing tag-match data differs from cloud and its historical user is missing. No data changed.');
                if($obs && !empty($existing[0]['observation_id']) && (int)$existing[0]['observation_id']!==$obs)
                    throw new RuntimeException('Conflicting tag-match observation for same scout/robot/match.');
                return 'existing'; // original insert-only behavior
            }
            // Truly new records MUST have a real organization-bound user.
            $user=npp_local_user($pdo,$org,$incomingUser,true);
            $s=$pdo->prepare('INSERT INTO tag_scouting_match_data
                (observation_id,organization_id,event_id,match_id,game_id,user_id,frc_team_number,alliance,station,scoring_contribution,tags_json,tag_weights_json,note,created_at,updated_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$obs,$org,$event,$match,$gameId,$user,$team,$r['alliance'],$r['station'],
                $r['scoring_contribution'],$r['tags_json'],$r['tag_weights_json'],$r['note'],$r['created_at'],$r['updated_at']]);
            return 'inserted';
    }
    throw new RuntimeException('Unsupported AWS download section.');
}
/**
 * Audit/reconcile existing cloud tag observations by UUID. The incremental ID
 * pull cannot see edits to older AWS IDs, so this is a deliberate full scan.
 * No change to scout actions, match state or any other local data.
 *
 * Baseline rule: once both sides agree, remember their content hash. On a
 * future run, accept an AWS change ONLY if portable still equals that baseline.
 * For pre-existing rows with no baseline, the newer timestamp is provisional
 * evidence; older/equal cloud timestamps are left untouched and reported.
 */
function npp_observation_fields(array $r): array {
    $fields=['event_id','field_id','frc_team_number','context','entry_mode','source_key','note','severity',
             'status','resolved_by','resolved_at','resolution_note'];
    $values=[];
    foreach($fields as $f) $values[$f] = isset($r[$f]) ? (string)$r[$f] : null;
    return $values;
}
function npp_observation_links(PDO $pdo,int $observation): array {
    $q=$pdo->prepare('SELECT t.organization_id AS tag_organization_id,t.seed_key,t.slug,ot.weight
        FROM tag_scouting_observation_tags ot JOIN tag_scouting_tags t ON t.id=ot.tag_id
        WHERE ot.observation_id=?');
    $q->execute([$observation]); return $q->fetchAll(PDO::FETCH_ASSOC);
}
function npp_observation_link_identity(array $links,int $org): array {
    $entries=[];
    foreach($links as $link){
        if(!is_array($link))throw new RuntimeException('Cloud returned an invalid observation tag link.');
        $scope=$link['tag_organization_id']??null;
        if($scope!==null && (int)$scope!==$org)throw new RuntimeException('Foreign organization observation tag link.');
        $seed=(string)($link['seed_key']??'');$slug=(string)($link['slug']??'');
        if($seed==='' && $slug==='')throw new RuntimeException('Tag reference has neither seed nor slug.');
        $key=($scope===null?'global':'org:'.$org).'|'.($seed!==''?'seed:'.$seed:'slug:'.$slug);
        if(array_key_exists($key,$entries))throw new RuntimeException('Duplicate cloud tag identity.');
        $entries[$key]=isset($link['weight'])?(string)$link['weight']:null;
    }
    ksort($entries,SORT_STRING);return $entries;
}
function npp_observation_hash(array $row,array $links,int $org,int $matchId): string {
    $encoded=json_encode([npp_observation_fields($row),$matchId,npp_observation_link_identity($links,$org)],
        JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    if($encoded===false)throw new RuntimeException('Could not fingerprint observation.');
    return hash('sha256',$encoded);
}
/** Pure revision decision; "conflict" never modifies local data. */
function npp_observation_revision_decision(string $localHash,string $cloudHash,?array $baseline,?string $localUpdated,?string $cloudUpdated): string {
    if(hash_equals($localHash,$cloudHash))return 'unchanged';
    if($baseline){
        $localClean=hash_equals((string)$baseline['local_hash'],$localHash);
        $remoteClean=hash_equals((string)$baseline['cloud_hash'],$cloudHash);
        if($remoteClean)return 'protected';
        return $localClean?'updated':'conflict';
    }
    $awsTs=strtotime((string)$cloudUpdated);$localTs=strtotime((string)$localUpdated);
    return ($awsTs!==false && $localTs!==false && $awsTs>$localTs)?'updated':'conflict';
}
function npp_apply_cloud_observation_revision(PDO $pdo,int $org,int $event,array $cloud): array {
    if((int)($cloud['organization_id']??0)!==$org)throw new RuntimeException('Cloud returned foreign-organization observation.');
    if($cloud['event_id']!==null && (int)$cloud['event_id']!==$event)throw new RuntimeException('Cloud returned foreign-event observation.');
    $uuid=npp_uuid((string)($cloud['uuid']??''));
    $query=$pdo->prepare('SELECT * FROM tag_scouting_observations WHERE organization_id=? AND uuid=? FOR UPDATE');
    $query->execute([$org,$uuid]); $local=$query->fetch(PDO::FETCH_ASSOC);
    if(!$local)return ['status'=>'missing','uuid'=>$uuid]; // Initial ID import owns new UUID insertion.
    if((string)($local['event_id']??'')!==(string)($cloud['event_id']??''))
        return ['status'=>'conflict','uuid'=>$uuid,'reason'=>'event_changed'];
    if((int)($cloud['match_id']??0)>0){
        if($cloud['event_id']===null)throw new RuntimeException('Organization-wide observation cannot claim a match.');
        $cloudMatch=npp_local_match($pdo,$org,$event,$cloud);
    }else{$cloudMatch=0;}
    $localMatch=(int)($local['match_id']??0);
    $localLinks=npp_observation_links($pdo,(int)$local['id']);
    $cloudLinks=$cloud['tag_links']??[];
    if(!is_array($cloudLinks))throw new RuntimeException('Cloud tag_links payload is not an array.');
    $cloudHash=npp_observation_hash($cloud,$cloudLinks,$org,$cloudMatch);
    $localHash=npp_observation_hash($local,$localLinks,$org,$localMatch);
    $lookup=$pdo->prepare('SELECT local_hash,cloud_hash FROM portable_cloud_observation_baselines
        WHERE organization_id=? AND uuid=? FOR UPDATE');
    $lookup->execute([$org,$uuid]);$baseline=$lookup->fetch(PDO::FETCH_ASSOC) ?: null;
    $save=$pdo->prepare('INSERT INTO portable_cloud_observation_baselines
        (organization_id,uuid,local_hash,cloud_hash,last_success_utc) VALUES(?,?,?,?,UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE local_hash=VALUES(local_hash),cloud_hash=VALUES(cloud_hash),last_success_utc=VALUES(last_success_utc)');
    $decision=npp_observation_revision_decision($localHash,$cloudHash,$baseline,
        $local['updated_at']??null,$cloud['updated_at']??null);
    if($decision==='unchanged'){
        $save->execute([$org,$uuid,$localHash,$cloudHash]);
        return ['status'=>'unchanged','uuid'=>$uuid];
    }
    if($decision==='protected')return ['status'=>'protected','uuid'=>$uuid];
    if($decision==='conflict')return ['status'=>'conflict','uuid'=>$uuid,'reason'=>$baseline?'both_changed':'no_baseline_or_not_newer'];
    // Resolve references *before* modifying any local row.
    $tags=[];
    foreach($cloudLinks as $link){
        $tag=npp_tag_id($pdo,$org,$link);
        if(!$tag)throw new RuntimeException('Cloud tag definition not available for '.(string)($link['slug']??''));
        $tags[]=['id'=>$tag,'weight'=>$link['weight']??null];
    }
    $resolver=(int)($cloud['resolved_by']??0);
    $resolvedBy=$resolver>0?npp_local_user($pdo,$org,$resolver):null;
    $s=$pdo->prepare('UPDATE tag_scouting_observations SET match_id=?,field_id=?,frc_team_number=?,
        context=?,entry_mode=?,source_key=?,note=?,severity=?,status=?,resolved_by=?,resolved_at=?,resolution_note=?,updated_at=?
        WHERE id=? AND organization_id=?');
    $s->execute([$cloudMatch?:null,$cloud['field_id']??null,(int)$cloud['frc_team_number'],
        $cloud['context'],$cloud['entry_mode'],$cloud['source_key'],$cloud['note'],$cloud['severity'],
        $cloud['status'],$resolvedBy,$cloud['resolved_at'],$cloud['resolution_note'],$cloud['updated_at'],(int)$local['id'],$org]);
    $pdo->prepare('DELETE FROM tag_scouting_observation_tags WHERE observation_id=?')->execute([(int)$local['id']]);
    $ins=$pdo->prepare('INSERT INTO tag_scouting_observation_tags(observation_id,tag_id,weight) VALUES(?,?,?)');
    foreach($tags as $tag)$ins->execute([(int)$local['id'],$tag['id'],$tag['weight']]);
    $save->execute([$org,$uuid,$cloudHash,$cloudHash]);
    return ['status'=>'updated','uuid'=>$uuid];
}
function npp_reconcile_cloud_observations(PDO $pdo,array $settings,array $events): array {
    $org=(int)$settings['organization_id'];
    $summary=['updated'=>0,'unchanged'=>0,'conflicts'=>0,'protected'=>0,'missing'=>0,'pages'=>0,'conflict_samples'=>[]];
    foreach($events as $event){
        $cursor=0;$pageCount=0;
        do {
            $response=neptune_portable_http_json($settings['cloud_url'].'/api/portable-sync-pull.php',$settings['sync_token'],[
                'organization_id'=>$org,'event_id'=>(int)$event,'section'=>'tag_observations','after_id'=>$cursor,'limit'=>150]);
            if((int)($response['organization_id']??0)!==$org || (int)($response['event_id']??0)!==(int)$event
                ||($response['section']??'')!=='tag_observations'||(int)($response['after_id']??-1)!==$cursor
                ||!is_array($response['rows']??null))throw new RuntimeException('Cloud observation revision scope mismatch.');
            $rows=$response['rows'];$next=(int)($response['next_id']??-1);
            if($rows && ($next<=$cursor||count($rows)>150))throw new RuntimeException('Invalid revision scan checkpoint.');
            if(!$rows && !empty($response['more']))throw new RuntimeException('Incomplete empty revision page.');
            $pdo->beginTransaction();
            try {
                $prev=$cursor;
                foreach($rows as $row){
                    if(!is_array($row)||(int)($row['id']??0)<=$prev)throw new RuntimeException('Out-of-order cloud observation.');
                    $prev=(int)$row['id'];
                    $out=npp_apply_cloud_observation_revision($pdo,$org,(int)$event,$row);
                    $status=$out['status']; $summary[$status]++;
                    if($status==='conflict'&&count($summary['conflict_samples'])<25)
                        $summary['conflict_samples'][]=['event_id'=>(int)$event,'uuid'=>substr($out['uuid'],0,13).'…','reason'=>$out['reason']??'conflict'];
                }
                if($rows && $prev!==$next)throw new RuntimeException('Revision scan cursor mismatch.');
                $pdo->commit();
            } catch(Throwable $e){$pdo->rollBack();throw new RuntimeException('Tag observation revision scan failed for event '.$event.' after AWS ID '.$cursor.': '.$e->getMessage(),0,$e);}
            if($rows)$cursor=$next;
            $summary['pages']++;
            if(++$pageCount>10000)throw new RuntimeException('Exceeded revision scan page safety limit.');
            $more=!empty($response['more']);
        } while($more);
    }
    return $summary;
}

/**
 * Match Tag Scouting uses tag_scouting_match_data (not the unified observation
 * table). Compare by org/event/match/scout/team, never cloud auto-increment ID.
 * This QA guard deliberately does not auto-resolve mismatched records.
 * A clean common version is remembered only when both payloads match.
 * A known portable-only change is allowed to upload; a changed cloud version
 * (or an ambiguous first-time mismatch) stops the upload.
 */
function npp_match_tags_payload_hash(array $row): string {
    $tags=json_decode((string)($row['tags_json']??'[]'),true);
    $weights=json_decode((string)($row['tag_weights_json']??'{}'),true);
    if(!is_array($tags)||!is_array($weights))throw new RuntimeException('Match Tag Scouting record has invalid JSON.');
    $tags=array_values(array_unique(array_map('strval',$tags)));sort($tags,SORT_STRING);
    $normalWeights=[];
    foreach($weights as $k=>$v)$normalWeights[(string)$k]=is_numeric($v)?(string)(int)$v:(string)$v;
    ksort($normalWeights,SORT_STRING);
    $share=($row['scoring_contribution']??null);
    $data=[
        'alliance'=>(string)($row['alliance']??''),
        'station'=>($row['station']??null)===null?null:(int)$row['station'],
        'scoring'=>($share===null||$share==='')?null:(int)$share,
        'note'=>trim((string)($row['note']??'')),
        'tags'=>$tags,
        'weights'=>$normalWeights,
    ];
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function npp_match_tags_revision_decision(string $local,string $cloud,?string $common): string {
    if(hash_equals($local,$cloud))return 'same';
    if($common!==null && hash_equals($common,$cloud))return 'local_only';
    if($common!==null && hash_equals($common,$local))return 'cloud_only';
    return 'conflict';
}
function npp_audit_match_tag_rows(PDO $pdo,array $settings,array $eventIds,bool $saveBaselines): array {
    $org=(int)$settings['organization_id'];
    $summary=['same'=>0,'local_only'=>0,'cloud_only'=>0,'conflicts'=>0,'missing_local'=>0,'pages'=>0,'conflict_samples'=>[]];
    if($saveBaselines) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS portable_cloud_match_tag_baselines (
            organization_id BIGINT UNSIGNED NOT NULL,event_id BIGINT UNSIGNED NOT NULL,
            identity_hash CHAR(64) NOT NULL,common_hash CHAR(64) NOT NULL,
            last_agreed_utc DATETIME NOT NULL,
            PRIMARY KEY(organization_id,event_id,identity_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    $baseline=$pdo->prepare('SELECT common_hash FROM portable_cloud_match_tag_baselines WHERE organization_id=? AND event_id=? AND identity_hash=?');
    $save=$saveBaselines?$pdo->prepare('INSERT INTO portable_cloud_match_tag_baselines
        (organization_id,event_id,identity_hash,common_hash,last_agreed_utc) VALUES(?,?,?,?,UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE common_hash=VALUES(common_hash),last_agreed_utc=VALUES(last_agreed_utc)'):null;
    $local=$pdo->prepare('SELECT * FROM tag_scouting_match_data
         WHERE organization_id=? AND event_id=? AND match_id=? AND user_id=? AND frc_team_number=? LIMIT 2');
    foreach($eventIds as $event){
        $event=(int)$event;$cursor=0;$pages=0;
        do{
            $reply=neptune_portable_http_json($settings['cloud_url'].'/api/portable-sync-pull.php',$settings['sync_token'],[
                'organization_id'=>$org,'event_id'=>$event,'section'=>'tag_match_data','after_id'=>$cursor,'limit'=>150]);
            if((int)($reply['organization_id']??0)!==$org || (int)($reply['event_id']??0)!==$event
              ||($reply['section']??'')!=='tag_match_data' || (int)($reply['after_id']??-1)!==$cursor
              || !is_array($reply['rows']??null))throw new RuntimeException('Match tag guard received unexpected scope.');
            $rows=$reply['rows'];$prev=$cursor;
            foreach($rows as $r){
                if(!is_array($r)||(int)($r['id']??0)<=$prev)throw new RuntimeException('Out-of-order match tag audit response.');
                $prev=(int)$r['id'];
                if((int)($r['organization_id']??0)!==$org||(int)($r['event_id']??0)!==$event)
                    throw new RuntimeException('Foreign match tag row.');
                $match=npp_local_match($pdo,$org,$event,$r);
                $uid=(int)($r['user_id']??0);$team=(int)($r['frc_team_number']??0);
                if($uid<=0||$team<=0)throw new RuntimeException('Match tag audit has invalid scout/team identity.');
                $key=hash('sha256',implode(':',[$match,$uid,$team]));
                $local->execute([$org,$event,$match,$uid,$team]);
                $found=$local->fetchAll(PDO::FETCH_ASSOC);
                if(count($found)>1)throw new RuntimeException('Ambiguous portable match tag identity.');
                $kind='';
                if(!$found){$kind='missing_local';}
                else{
                    $cloudHash=npp_match_tags_payload_hash($r);
                    $localHash=npp_match_tags_payload_hash($found[0]);
                    try{$baseline->execute([$org,$event,$key]);$common=$baseline->fetchColumn();}
                    catch(PDOException $e){
                        // Prior to the first *download*, the baseline table may not
                        // exist. In that case all differing versions are conflicts.
                        if(!str_contains($e->getMessage(),'1146'))throw $e;
                        $common=false;
                    }
                    $kind=npp_match_tags_revision_decision($localHash,$cloudHash,$common===false?null:(string)$common);
                    if($kind==='same'&&$save!==null)$save->execute([$org,$event,$key,$cloudHash]);
                }
                if($kind==='same')$summary['same']++;
                elseif($kind==='local_only')$summary['local_only']++;
                elseif($kind==='missing_local'){$summary['missing_local']++;$summary['conflicts']++;}
                elseif($kind==='conflict'){$summary['conflicts']++;}
                elseif($kind==='cloud_only'){$summary['cloud_only']++;$summary['conflicts']++;}
                else throw new RuntimeException('Unexpected match tag revision decision: '.$kind);
                if(($kind==='conflict'||$kind==='cloud_only'||$kind==='missing_local') && count($summary['conflict_samples'])<25){
                    $summary['conflict_samples'][]=['event_id'=>$event,'match_level'=>(string)($r['match_comp_level']??''),
                       'match_number'=>(int)($r['match_number']??0),'match_id'=>$match,'team'=>$team,'scout_id'=>$uid,'reason'=>$kind];
                }
            }
            $next=(int)($reply['next_id']??-1);
            if($rows&&$next!==$prev)throw new RuntimeException('Match tag audit cursor mismatch.');
            if(!$rows&&!empty($reply['more']))throw new RuntimeException('Match tag audit got empty incomplete page.');
            if($rows)$cursor=$next;
            $summary['pages']++;
            if(++$pages>10000)throw new RuntimeException('Match tag audit exceeded page safety limit.');
            $more=!empty($reply['more']);
        }while($more);
    }
    return $summary;
}

/** Import one atomic page and its cursor. Never advance cursor if any row fails. */
function neptune_portable_pull_cloud(PDO $pdo,array $config): array {
    $settings=neptune_portable_local_settings($config);
    if(empty($config['app']['portable_mode']))throw new RuntimeException('AWS download is available only on portable servers.');
    if(!preg_match('~^https://~i',$settings['cloud_url'])||$settings['organization_id']<1||$settings['sync_token']==='')
        throw new RuntimeException('Portable organization-bound cloud sync is not configured.');
    set_time_limit(0);
    $org=$settings['organization_id'];
    $pdo->exec('CREATE TABLE IF NOT EXISTS portable_cloud_pull_cursors (
        organization_id BIGINT UNSIGNED NOT NULL,event_id BIGINT UNSIGNED NOT NULL,section VARCHAR(40) NOT NULL,
        last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,last_success_utc DATETIME NULL,
        PRIMARY KEY(organization_id,event_id,section)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS portable_cloud_observation_baselines (
        organization_id BIGINT UNSIGNED NOT NULL, uuid CHAR(36) NOT NULL,
        local_hash CHAR(64) NOT NULL, cloud_hash CHAR(64) NOT NULL,
        last_success_utc DATETIME NOT NULL,
        PRIMARY KEY(organization_id,uuid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    // Prevent overlapping manual downloads from racing on checkpoints.
    $lockName='neptune_cloud_pull_'.(string)$org;
    $lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Another AWS download is already running.');
    try {
    $events=$pdo->prepare('SELECT id FROM events WHERE organization_id=? ORDER BY id');$events->execute([$org]);
    $eventIds=array_map('intval',$events->fetchAll(PDO::FETCH_COLUMN));
    if(!$eventIds)throw new RuntimeException('Portable organization contains no events.');
    $sections=['tag_definitions','scout_sessions','scouting_actions','tag_observations','tag_match_data'];
    $summary=['ok'=>true,'direction'=>'AWS -> Portable','events'=>count($eventIds),'sections'=>[],'inserted'=>0,'existing'=>0,'pages'=>0,'completed_at_utc'=>null];
    $get=$pdo->prepare('SELECT last_id FROM portable_cloud_pull_cursors WHERE organization_id=? AND event_id=? AND section=?');
    $save=$pdo->prepare('INSERT INTO portable_cloud_pull_cursors (organization_id,event_id,section,last_id,last_success_utc)
          VALUES (?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_id=VALUES(last_id),last_success_utc=VALUES(last_success_utc)');
    foreach($eventIds as $event){
        foreach($sections as $section){
            $get->execute([$org,$event,$section]);$cursor=(int)($get->fetchColumn()?:0);
            $part=['event_id'=>$event,'section'=>$section,'inserted'=>0,'existing'=>0,'pages'=>0,'last_cloud_id'=>$cursor];
            do {
                $response=neptune_portable_http_json($settings['cloud_url'].'/api/portable-sync-pull.php',$settings['sync_token'],[
                    'organization_id'=>$org,'event_id'=>$event,'section'=>$section,'after_id'=>$cursor,'limit'=>150
                ]);
                if((int)($response['organization_id']??0)!==$org||(int)($response['event_id']??0)!==$event||($response['section']??'')!==$section
                    ||(int)($response['after_id']??-1)!==$cursor||!isset($response['rows'])||!is_array($response['rows']))
                    throw new RuntimeException('Cloud pull response did not match requested organization/event/section.');
                $rows=$response['rows'];$next=(int)($response['next_id']??-1);
                if($rows && ($next<=$cursor || count($rows)>150))throw new RuntimeException('Invalid cloud pull cursor progression.');
                if(!$rows && !empty($response['more']))throw new RuntimeException('Cloud returned an empty incomplete page.');
                $pdo->beginTransaction();
                try {
                    $prev=$cursor;
                    $currentCloudId=null;
                    foreach($rows as $row){
                        if(!is_array($row)||!isset($row['id'])||(int)$row['id']<=$prev)throw new RuntimeException('Cloud page is out of order.');
                        $prev=(int)$row['id'];
                        $currentCloudId=$prev;
                        $result=npp_apply_cloud_row($pdo,$org,$event,$section,$row);
                        $part[$result]++;
                    }
                    if($rows && $prev!==$next)throw new RuntimeException('Cloud page cursor disagrees with returned records.');
                    if($rows)$save->execute([$org,$event,$section,$next]);
                    $pdo->commit();
                } catch(Throwable $e){$pdo->rollBack();throw new RuntimeException('AWS download stopped at event '.$event.', '.$section.', source row '.($currentCloudId??'unknown').' (page started after '.$cursor.'): '.$e->getMessage(),0,$e);}
                $cursor=$next;
                $part['last_cloud_id']=$cursor;
                if($rows)$part['pages']++;
                $more=!empty($response['more']);
            } while($more);
            $summary['sections'][]=$part;
            $summary['inserted']+=$part['inserted'];$summary['existing']+=$part['existing'];$summary['pages']+=$part['pages'];
        }
    }
    // Deliberately rescan old AWS IDs: their updated_at/tag links can change
    // without producing a newer AUTO_INCREMENT ID. All writes are scoped to
    // previously existing tag observations; other local data remains intact.
    $summary['observation_reconciliation']=npp_reconcile_cloud_observations($pdo,$settings,$eventIds);
    $summary['match_tag_reconciliation']=npp_audit_match_tag_rows($pdo,$settings,$eventIds,true);
    $summary['conflicts']=(int)$summary['observation_reconciliation']['conflicts']
        +(int)$summary['match_tag_reconciliation']['conflicts'];
    $summary['updated']=(int)$summary['observation_reconciliation']['updated'];
    $summary['completed_at_utc']=gmdate('c');
    return $summary;
    } finally {
        try {$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable $ignored){}
    }
}
