<?php
require_once __DIR__.'/_scoring_source.php';
/** Read-only match Tag Scouting estimates for Robot Cards. Does not create scouting actions. */
function robot_tag_match_cards(PDO $pdo,int $org,int $eventId): array {
    if($org<1 || $eventId<1) return [];
    $exists=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tag_scouting_match_data'");
    $exists->execute();
    if(!(int)$exists->fetchColumn()) return [];

    // Join the official event/match and the robot's ACTUAL alliance assignment.
    // Never trust an observation's alliance or event ID in isolation.
    $q=$pdo->prepare("SELECT d.id,d.frc_team_number,d.match_id,d.user_id,d.note,d.updated_at,
            d.scoring_contribution,d.tags_json,d.tag_weights_json,
            mt.alliance,m.red_score,m.blue_score,m.match_number,m.comp_level,
            (SELECT COUNT(DISTINCT mt2.frc_team_number) FROM match_teams mt2
             WHERE mt2.match_id=m.id AND mt2.alliance=d.alliance) AS alliance_team_count
        FROM tag_scouting_match_data d
        JOIN matches m ON m.id=d.match_id AND m.organization_id=d.organization_id AND m.event_id=d.event_id
        JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=d.frc_team_number
                            AND mt.alliance=d.alliance
        WHERE d.organization_id=? AND d.event_id=?
        ORDER BY d.frc_team_number,d.match_id,d.user_id");
    $q->execute([$org,$eventId]);
    $rows=$q->fetchAll();
    if(!$rows) return [];

    // Resolve existing Tag Scouting names without importing tags from other orgs.
    $labels=[];
    try{
        $t=$pdo->prepare("SELECT t.seed_key,t.slug,t.label
            FROM tag_scouting_tags t
            LEFT JOIN tag_scouting_tag_visibility v ON v.tag_id=t.id AND v.organization_id=?
            WHERE (t.organization_id IS NULL OR t.organization_id=?)
              AND t.active=1 AND COALESCE(v.active,1)=1
            ORDER BY (t.organization_id=? ) DESC,t.id");
        $t->execute([$org,$org,$org]);
        foreach($t->fetchAll() as $tag){
            foreach(['seed_key','slug'] as $col){
                $key=trim((string)($tag[$col]??''));
                if($key!=='' && !isset($labels[$key])) $labels[$key]=(string)$tag['label'];
            }
        }
    }catch(Throwable $ignored){/* Human-readable tag key fallback remains available. */}
    return robot_tag_summarize_match_rows($rows,$labels);
}

/** Pure aggregation, kept separate from SQL so estimated-score behavior is testable. */
function robot_tag_summarize_match_rows(array $rows,array $labels=[]): array {
    $scoreMaps=neptune_scoring_tag_rows($rows);
    $latest=[];
    foreach($rows as $r){
        $team=(int)($r['frc_team_number']??0);$match=(int)($r['match_id']??0);
        $observer=(int)($r['user_id']??0);
        if($team<1||$match<1)continue;
        $key=$team.':'.$match.':'.$observer;
        if(isset($latest[$key])){
            $old=$latest[$key];
            $cmp=strcmp((string)($r['updated_at']??''),(string)($old['updated_at']??''));
            if($cmp<0||($cmp===0&&(int)($r['id']??0)<=(int)($old['id']??0)))continue;
        }
        $latest[$key]=$r;
    }
    $grouped=[];
    foreach($latest as $r){
        $team=(int)$r['frc_team_number'];$match=(int)$r['match_id'];
        $grouped[$team][$match][]=$r;
    }
    $out=[];
    foreach($grouped as $team=>$byMatch){
        $tags=[];$tagWeights=[];$obsCount=0;$realCount=0;$syntheticCount=0;
        foreach($byMatch as $matchRows){
            $realRows=array_values(array_filter($matchRows,static fn($r)=>
                (int)($r['user_id']??0)!==900000001
                && !str_starts_with((string)($r['note']??''),'Synthetic Tag Scouting estimate')));
            // Never show synthetic imports as independently scouted top tags.
            $selected=$realRows;
            $obsCount+=count($realRows);$realCount+=count($realRows);
            $syntheticCount+=count($matchRows)-count($realRows);
            foreach($selected as $row){
                $codes=json_decode((string)($row['tags_json']??'[]'),true);
                if(!is_array($codes))continue;
                $weights=json_decode((string)($row['tag_weights_json']??'{}'),true);
                if(!is_array($weights))$weights=[];
                foreach(array_unique(array_filter($codes,'is_string')) as $code){
                    $code=trim($code);
                    if($code===''||strlen($code)>120)continue;
                    $tags[$code]=($tags[$code]??0)+1;
                    $weight=$weights[$code]??null;
                    if(is_numeric($weight)&&(float)$weight>=1&&(float)$weight<=5)$tagWeights[$code][]=(float)$weight;
                }
            }
        }
        $ranked=[];
        foreach($tags as $code=>$count){
            $w=$tagWeights[$code]??[];
            $ranked[]=['code'=>$code,'label'=>$labels[$code]??ucwords(str_replace('_',' ',$code)),
                'count'=>$count,'weight'=>$w?array_sum($w)/count($w):0];
        }
        usort($ranked,static fn($a,$b)=>($b['count']<=>$a['count'])?:($b['weight']<=>$a['weight'])?:strcmp($a['code'],$b['code']));
        $summary=neptune_scoring_tag_summary($scoreMaps[$team]??[]);
        $out[$team]=array_merge($summary,[
            'top_tags'=>array_slice($ranked,0,3),
            'observations'=>$obsCount,
            'has_human_observations'=>$realCount>0,
            'synthetic_only'=>$realCount===0&&$syntheticCount>0,
            'has_synthetic'=>$syntheticCount>0,
        ]);
    }
    return $out;
}

/** Compact hashtag for scouting chips; preserve full human label in title/tooltip. */
function neptune_robot_tag_hashtag(string $label): string {
    $label = trim($label);
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $label);
    if ($slug === null) $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $label);
    $slug = trim((string)$slug, '-');
    return '#'.strtolower($slug === '' ? 'tag' : $slug);
}
