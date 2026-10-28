<?php
/**
 * Human Tag Scouting activity evidence, separate from traditional action counts.
 * A Tag is a qualitative robot/match observation, NOT an individual action.
 * Never infer a successful defense action or a D-EPA rating from a Tag.
 */
function neptune_tag_activity_summarize_rows(array $rows): array {
    $byRobotMatch=[];
    $dedup=[];
    foreach($rows as $row){
        $org=(int)($row['organization_id']??0);
        $match=(int)($row['match_id']??0);
        $team=(int)($row['frc_team_number']??0);
        $user=(int)($row['user_id']??0);
        $note=(string)($row['note']??'');
        if($org<1||$match<1||$team<1||$user<1||$user===900000001
            ||str_starts_with($note,'Synthetic Tag Scouting estimate'))continue;
        $tags=json_decode((string)($row['tags_json']??'[]'),true);
        if(!is_array($tags))$tags=[];
        $filtered=[];
        foreach($tags as $tag){
            if(!is_string($tag)||!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$tag))continue;
            $filtered[$tag]=true;
        }
        // One observation per scout, robot and match. Guard against legacy duplicates.
        $key=$match.':'.$team.':'.$user;
        if(isset($dedup[$key])){
            $prev=$dedup[$key];
            if(strcmp((string)($row['updated_at']??''),(string)($prev['updated_at']??''))<0)continue;
        }
        $row['_tags']=array_keys($filtered);
        $dedup[$key]=$row;
    }
    foreach($dedup as $row){
        $match=(int)$row['match_id'];$team=(int)$row['frc_team_number'];
        $slot=&$byRobotMatch[$team][$match];
        if(!is_array($slot))$slot=['observers'=>0,'all_tags'=>[],
            'positive_defense_observers'=>0,'negative_defense_observers'=>0];
        $slot['observers']++;
        $tags=array_fill_keys($row['_tags'],true);
        foreach($tags as $tag=>$unused)$slot['all_tags'][$tag]=true;
        if(isset($tags['attempted_defense'])||isset($tags['good_defense'])||isset($tags['elite_defense']))
            $slot['positive_defense_observers']++;
        if(isset($tags['no_defense']))$slot['negative_defense_observers']++;
        unset($slot);
    }
    $nonActivity=array_fill_keys([
        'no_auton','auto_no_attempt','auto_failed','no_scoring','no_defense',
        'end_no_attempt','end_failed','failed_endgame','disabled','mechanical_issues',
        'recovered','penalty_risk','inconsistent','weak_auton','struggles_defense',
    ],true);
    foreach($byRobotMatch as &$matches){
        foreach($matches as &$r){
            $tags=array_keys($r['all_tags']);sort($tags,SORT_STRING);
            $r['tags']=$tags;
            $r['tag_count']=count($tags);
            $signals=array_values(array_filter($tags,static fn($t)=>
                !isset($nonActivity[$t])&&!str_starts_with($t,'no_')));
            $r['activity_signals']=$signals;
            $r['activity_signal_count']=count($signals);
            $yes=$r['positive_defense_observers']>0;
            $no=$r['negative_defense_observers']>0;
            $r['defense_status']=$yes&&$no?'mixed':($yes?
                (in_array('attempted_defense',$tags,true)?'attempted':'observed'):
                ($no?'none':'unknown'));
            unset($r['all_tags']);
        }
        unset($r);
    }
    unset($matches);
    return $byRobotMatch;
}

function neptune_tag_activity_matches(PDO $pdo,int $org,int $eventId,?array $matchIds=null): array {
    if($org<1||$eventId<1)return [];
    static $hasTable=[];
    $key=spl_object_id($pdo);
    if(!array_key_exists($key,$hasTable)){
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $q->execute(['tag_scouting_match_data']);
        $hasTable[$key]=(bool)$q->fetchColumn();
    }
    if(!$hasTable[$key])return [];
    $filter='';$params=[$org,$eventId];
    if($matchIds!==null){
        $matchIds=array_values(array_unique(array_filter(array_map('intval',$matchIds),static fn($n)=>$n>0)));
        if(!$matchIds)return [];
        $filter=' AND d.match_id IN ('.implode(',',array_fill(0,count($matchIds),'?')).')';
        $params=array_merge($params,$matchIds);
    }
    $q=$pdo->prepare("SELECT d.organization_id,d.match_id,d.frc_team_number,d.user_id,d.tags_json,d.note,d.updated_at
        FROM tag_scouting_match_data d
        JOIN events e ON e.id=d.event_id AND e.organization_id=d.organization_id
        JOIN matches m ON m.id=d.match_id AND m.organization_id=d.organization_id AND m.event_id=d.event_id
        JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=d.frc_team_number AND mt.alliance=d.alliance
        WHERE d.organization_id=? AND d.event_id=? AND d.user_id<>900000001
          AND (d.note IS NULL OR d.note NOT LIKE 'Synthetic Tag Scouting estimate%')
          $filter");
    $q->execute($params);
    return neptune_tag_activity_summarize_rows($q->fetchAll(PDO::FETCH_ASSOC));
}
