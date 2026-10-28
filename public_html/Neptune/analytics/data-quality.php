<?php
declare(strict_types=1);
/**
 * AUGUR Scouting Data Quality — organization/event scoped read-only evidence analysis.
 * Only review decisions/audit events may be written. No simulated/demo data.
 */
require_once dirname(__DIR__, 3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
require_once __DIR__.'/_scout_accuracy.php';
$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id']; $actor=(int)$u['id'];
$events=neptune_event_list($pdo,$org);
$eventId=(int)($_POST['event_id']??$_GET['event_id']??($events[0]['id']??0));
$event=null;
foreach($events as $ev)if((int)$ev['id']===$eventId){$event=$ev;break;}
if($eventId>0 && !$event){http_response_code(403);exit('Event is not available to your organization.');}
$pageTitle='Scouting Data Quality'; $moduleName='AUGUR';
function dq_e(mixed $s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function dq_key(string $kind,array $values): string{return substr(hash('sha256',$kind.':'.implode(':',array_map('strval',$values))),0,48);}
function dq_link(array $q): string {return '?'.http_build_query($q);}
function dq_table(PDO $pdo,string $name): bool {
    $s=$pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
    $s->execute([$name]);return (bool)$s->fetchColumn();
}
function dq_median(array $v): ?float {
    if(!$v)return null;sort($v,SORT_NUMERIC);$n=count($v);$i=intdiv($n,2);
    return $n%2?(float)$v[$i]:((float)$v[$i-1]+(float)$v[$i])/2;
}
function dq_num(float $n): string{return number_format($n,($n===(float)(int)$n?0:1));}
function dq_label(array $m): string {
    $level=(string)($m['comp_level']??'qm');$num=(int)($m['match_number']??0);$set=(int)($m['set_number']??1);
    return match($level){'qm'=>'Qual '.$num,'ef'=>'Eighth '.$set.'-'.$num,'qf'=>'Quarter '.$set.'-'.$num,'sf'=>'Semi '.$set.'-'.$num,'f'=>'Final '.$set.'-'.$num,default=>strtoupper($level).' '.$num};
}
function dq_add(array &$flags,string $kind,int $priority,int $mid,int $team,array $identity,string $title,string $why,string $next,array $evidence=[]): void {
    $key=dq_key($kind,$identity);
    $flags[$key]=compact('key','kind','priority','mid','team','title','why','next','evidence');
}
function dq_opposite_pairs(array $tags): array {
    $set=array_fill_keys($tags,true);
    $pairs=[
      ['no_scoring',['scoring','scoring_a_lot','fast_cycles']],
      ['slow_cycles',['fast_cycles']],
      ['no_auton',['impressive_auton','consistent_auton','auto_completed','auto_partial']],
      ['auto_no_attempt',['auto_completed','auto_partial']],
      ['auto_failed',['auto_completed']],
      ['no_defense',['good_defense','elite_defense','attempted_defense']],
      ['end_no_attempt',['end_completed','end_partial','strong_endgame','reliable_endgame']],
      ['end_failed',['end_completed']],
    ];
    $found=[];
    foreach($pairs as [$one,$others]){
        if(!isset($set[$one]))continue;
        foreach($others as $other)if(isset($set[$other]))$found[]=$one.' + '.$other;
    }
    return $found;
}
$kinds=[
 'coverage'=>'Incomplete match', 'duplicate'=>'Duplicate sessions', 'empty'=>'Empty session',
 'traditional'=>'Unusual traditional score', 'tag-agreement'=>'Tag estimate disagreement',
 'tag-trend'=>'Robot trend change', 'tag-conflict'=>'Conflicting tags',
 'tag-points'=>'Score-source disagreement', 'tag-unrated'=>'No score estimate',
 'tag-alliance'=>'Alliance estimate balance', 'sample'=>'Small sample'
];
$flags=[];$reviews=[];$notice='';$error='';$schemaReady=false;$schemaError='';$scanComplete=true;
$matches=[];$coverage=[];$actions=[];$sessions=[];$rawTags=[];$tagObservations=[];$tagMetrics=[];$extraTagKeys=[];
$sourceCount=['actions'=>0,'tag_human'=>0,'tag_notes'=>0,'tag_synthetic'=>0,'tag_estimates'=>0];
$partial=0;$zero=0;$endedCount=0;$withEvidence=0;$badScope=0;$missingTables=[];
try{
    // Idempotent review tables. Findings themselves are computed fresh on every request.
    $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_quality_reviews (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      organization_id INT NOT NULL,event_id INT NOT NULL,flag_key VARCHAR(48) NOT NULL,
      status VARCHAR(24) NOT NULL,note TEXT NOT NULL,reviewed_by INT NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_scope_flag (organization_id,event_id,flag_key),KEY idx_scope_status (organization_id,event_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_quality_review_audit (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      organization_id INT NOT NULL,event_id INT NOT NULL,flag_key VARCHAR(48) NOT NULL,
      old_status VARCHAR(24) NOT NULL,new_status VARCHAR(24) NOT NULL,note TEXT NOT NULL,
      actor_user_id INT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_scope_flag (organization_id,event_id,flag_key,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $schemaReady=true;
}catch(Throwable $e){$schemaError='Review tracking is unavailable; findings remain read-only.';error_log('DQC schema: '.$e->getMessage());}
if($eventId){
 try{
    // One canonical list: ended matches, their actual assigned teams, the same org/event, and roster membership.
    $s=$pdo->prepare("SELECT m.id,m.organization_id,m.event_id,m.comp_level,m.set_number,m.match_number,m.run_number,
       mt.frc_team_number,mt.alliance,mt.station
       FROM matches m JOIN events ev ON ev.id=m.event_id AND ev.organization_id=m.organization_id
       JOIN match_teams mt ON mt.match_id=m.id
       JOIN event_teams et ON et.event_id=m.event_id AND et.frc_team_number=mt.frc_team_number
       WHERE m.organization_id=? AND m.event_id=? AND m.state='ended'
       ORDER BY FIELD(m.comp_level,'qm','ef','qf','sf','f','legacy'),m.match_number,m.set_number,m.id
       LIMIT 12001");
    $s->execute([$org,$eventId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>12000)throw new RuntimeException('Event assignment scan exceeded its safe size limit.');
    foreach($rows as $r){$mid=(int)$r['id'];$team=(int)$r['frc_team_number'];
       if(!isset($matches[$mid]))$matches[$mid]=['info'=>$r,'robots'=>[]];
       $matches[$mid]['robots'][$team]=$r;
    }
    $endedCount=count($matches);
    $q=$pdo->prepare("SELECT COUNT(*) FROM matches m JOIN match_teams mt ON mt.match_id=m.id
        WHERE m.organization_id=? AND m.event_id=? AND m.state='ended'
        AND NOT EXISTS(SELECT 1 FROM event_teams et WHERE et.event_id=m.event_id AND et.frc_team_number=mt.frc_team_number)");
    $q->execute([$org,$eventId]);$badScope=(int)$q->fetchColumn();
    // Scouting actions: count only current run, non-voided actions for verified robot assignments.
    $q=$pdo->prepare("SELECT a.match_id,a.frc_team_number,COUNT(*) AS actions,COALESCE(SUM(a.points),0) AS points,
            SUM(CASE WHEN a.action_type='offense' AND a.result IN ('Success','Failure') THEN 1 ELSE 0 END) offense_attempts
       FROM scouting_actions a JOIN matches m ON m.id=a.match_id AND m.organization_id=a.organization_id AND m.event_id=a.event_id
       JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=a.frc_team_number
       JOIN event_teams et ON et.event_id=m.event_id AND et.frc_team_number=a.frc_team_number
       WHERE a.organization_id=? AND a.event_id=? AND m.state='ended' AND a.deleted_at IS NULL
         AND a.match_run_number=m.run_number
       GROUP BY a.match_id,a.frc_team_number");
    $q->execute([$org,$eventId]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$mid=(int)$r['match_id'];$team=(int)$r['frc_team_number'];
       if(!isset($matches[$mid]['robots'][$team]))continue;
       $actions[$team][$mid]=$r;$coverage[$mid][$team]['traditional']=true;$sourceCount['actions']+=(int)$r['actions'];
    }
    // Genuine closed sessions are kept distinct from action records and tagged observations.
    $q=$pdo->prepare("SELECT s.id,s.match_id,s.frc_team_number,s.user_id,s.match_run_number,s.status,
           COUNT(a.id) AS actions
       FROM scout_sessions s JOIN matches m ON m.id=s.match_id AND m.event_id=s.event_id AND m.organization_id=s.organization_id
       JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=s.frc_team_number
       JOIN event_teams et ON et.event_id=m.event_id AND et.frc_team_number=s.frc_team_number
       LEFT JOIN scouting_actions a ON a.scout_session_id=s.id AND a.organization_id=s.organization_id
           AND a.match_id=s.match_id AND a.event_id=s.event_id AND a.frc_team_number=s.frc_team_number
           AND a.match_run_number=s.match_run_number AND a.deleted_at IS NULL
       WHERE s.organization_id=? AND s.event_id=? AND m.state='ended' AND s.match_run_number=m.run_number
       GROUP BY s.id,s.match_id,s.frc_team_number,s.user_id,s.match_run_number,s.status
       LIMIT 20001");
    $q->execute([$org,$eventId]);$sessions=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($sessions)>20000)throw new RuntimeException('Scout session scan exceeded its safe size limit.');
    // Structured Match Tag observations. Synthetic model rows never count as human scouting coverage.
    if(dq_table($pdo,'tag_scouting_match_data')){
       $q=$pdo->prepare("SELECT t.id,t.user_id,t.match_id,t.frc_team_number,t.scoring_contribution,t.tags_json,t.note,
          t.updated_at
          FROM tag_scouting_match_data t JOIN matches m ON m.id=t.match_id
             AND m.organization_id=t.organization_id AND m.event_id=t.event_id
          JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=t.frc_team_number
          JOIN event_teams et ON et.event_id=m.event_id AND et.frc_team_number=t.frc_team_number
          WHERE t.organization_id=? AND t.event_id=? AND m.state='ended'");
       $q->execute([$org,$eventId]);
       foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$mid=(int)$r['match_id'];$team=(int)$r['frc_team_number'];
          $tags=json_decode((string)$r['tags_json'],true);$tags=is_array($tags)?array_values(array_unique(array_filter(array_map('strval',$tags)))):[];
          $r['tags']=$tags;$r['human']=((int)$r['user_id']!==900000001)&&!str_starts_with((string)($r['note']??''),'Synthetic Tag Scouting estimate');
          $r['meaningful']=($r['scoring_contribution']!==null || count($tags)>0 || trim((string)($r['note']??''))!=='');
          if(!$r['human']){$sourceCount['tag_synthetic']++;continue;}
          if(!$r['meaningful'])continue;
          $rawTags[$team][$mid][]=$r;$coverage[$mid][$team]['tag']=true;$sourceCount['tag_human']++;
          if($r['scoring_contribution']!==null)$sourceCount['tag_estimates']++;
       }
    }else{$missingTables[]='structured Match Tag data';$scanComplete=false;}
    // Other Match Tag observations (notes/custom tags) share the unified stream but are not all in Match Tag data.
    if(dq_table($pdo,'tag_scouting_observations')){
       $q=$pdo->prepare("SELECT o.id,o.match_id,o.frc_team_number,o.note,o.source_key,o.entry_mode,o.created_by
          FROM tag_scouting_observations o JOIN matches m ON m.id=o.match_id
            AND m.organization_id=o.organization_id AND m.event_id=o.event_id
          JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=o.frc_team_number
          JOIN event_teams et ON et.event_id=m.event_id AND et.frc_team_number=o.frc_team_number
          WHERE o.organization_id=? AND o.event_id=? AND o.context='match' AND m.state='ended'
            AND o.entry_mode='manual' AND o.created_by IS NOT NULL");
       $q->execute([$org,$eventId]);
       foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
          if(str_starts_with((string)($r['source_key']??''),'match-data:'))continue; // already counted above
          $mid=(int)$r['match_id'];$team=(int)$r['frc_team_number'];
          $tagObservations[$team][$mid][]=$r;$coverage[$mid][$team]['tag-note']=true;$sourceCount['tag_notes']++;
       }
       // Inspect tags attached to standalone human Match Tag observations as well.
       if($tagObservations && dq_table($pdo,'tag_scouting_observation_tags') && dq_table($pdo,'tag_scouting_tags')){
          $q=$pdo->prepare("SELECT o.match_id,o.frc_team_number,t.seed_key,t.slug
             FROM tag_scouting_observations o
             JOIN tag_scouting_observation_tags ot ON ot.observation_id=o.id
             JOIN tag_scouting_tags t ON t.id=ot.tag_id
             JOIN matches m ON m.id=o.match_id AND m.organization_id=o.organization_id AND m.event_id=o.event_id
             JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=o.frc_team_number
             JOIN event_teams et ON et.event_id=m.event_id AND et.frc_team_number=o.frc_team_number
             WHERE o.organization_id=? AND o.event_id=? AND o.context='match' AND m.state='ended'
                AND o.entry_mode='manual' AND o.created_by IS NOT NULL
                AND (o.source_key IS NULL OR o.source_key NOT LIKE 'match-data:%')");
          $q->execute([$org,$eventId]);
          foreach($q->fetchAll(PDO::FETCH_ASSOC) as $t){
             $code=(string)($t['seed_key']??'');
             if(str_starts_with($code,'match:'))$code=substr($code,6);
             elseif($code==='')$code=str_replace('-','_',(string)($t['slug']??''));
             else continue;
             if($code!=='')$extraTagKeys[(int)$t['frc_team_number']][(int)$t['match_id']][$code]=true;
          }
       }
    }else{$missingTables[]='unified Tag observations';$scanComplete=false;}
    // Derived Tag estimates are a separate, possibly stale source, never used to assert coverage.
    if(dq_table($pdo,'tag_scouting_match_metrics')){
       $q=$pdo->prepare("SELECT d.match_id,d.frc_team_number,d.estimated_points,d.point_source,
               d.confidence_score,d.observer_count,d.synthetic_observer_count,d.score_estimate_count,
               d.calculated_at,d.latest_observation_at
          FROM tag_scouting_match_metrics d JOIN matches m ON m.id=d.match_id
                AND m.event_id=d.event_id AND m.organization_id=d.organization_id
          JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=d.frc_team_number
          JOIN event_teams et ON et.event_id=m.event_id AND et.frc_team_number=d.frc_team_number
          WHERE d.organization_id=? AND d.event_id=? AND m.state='ended'");
       $q->execute([$org,$eventId]);
       foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$tagMetrics[(int)$r['frc_team_number']][(int)$r['match_id']]=$r;
    }
    // Coverage is one match with some recorded scouting vs none; partial means 1..N-1 robot slots.
    foreach($matches as $mid=>$m){
       $teams=array_keys($m['robots']);$covered=array_values(array_filter($teams,static fn($t)=>!empty($coverage[$mid][$t])));
       $count=count($covered);$total=count($teams);
       if($count>0)$withEvidence++;
       if(!$count){$zero++;continue;} // Historical imported / unscouted matches are not 6 separate errors.
       if($count===$total)continue;
       $partial++;$missing=array_values(array_diff($teams,$covered));
       if($scanComplete)dq_add($flags,'coverage',1,(int)$mid,0,[$mid],
         'Incomplete '.dq_label($m['info']).' · '.$count.'/'.$total.' robots covered',
         'Scouting exists for #'.implode(', #',$covered).'. No current-run actions or human Tag Scouting observations were found for #'.implode(', #',$missing).'.',
         'Check the missing robot assignments and ask those scouts for their observations. Do not invent scores.',
         ['match'=>dq_label($m['info']),'covered'=>implode(', #',$covered),'missing'=>implode(', #',$missing)]);
    }
    // Sessions: ignore reconnection-only duplicates and tag-covered empty sessions.
    $byScout=[];
    foreach($sessions as $s){$mid=(int)$s['match_id'];$team=(int)$s['frc_team_number'];
       $byScout[implode(':',[$mid,$team,(int)$s['user_id'],(int)$s['match_run_number']])][]=$s;
       if((int)$s['actions']===0 && in_array((string)$s['status'],['closed','completed','finished'],true)
          && !isset($coverage[$mid][$team]) && $withEvidence>0){
          // Only meaningful if another robot in the same match has real data.
          if(empty($coverage[$mid]))continue;
          dq_add($flags,'empty',2,$mid,$team,[(int)$s['id']],
           'Closed session without observations · #'.$team.' · '.dq_label($matches[$mid]['info']),
           'Scout session '.(int)$s['id'].' is closed but has no active actions; no human Tag Scouting was found for this robot/match.',
           'Verify whether the scout watched the match, then review the match actions or Tag Scouting notes.',
           ['session'=>(string)$s['id'],'scout'=>(string)$s['user_id']]);
       }
    }
    foreach($byScout as $group){$active=array_values(array_filter($group,static fn($s)=>(int)$s['actions']>0));
       if(count($active)<2)continue;
       $s=$active[0];$mid=(int)$s['match_id'];$team=(int)$s['frc_team_number'];
       dq_add($flags,'duplicate',2,$mid,$team,[$mid,$team,(int)$s['user_id'],(int)$s['match_run_number']],
         'Two sessions recorded actions · #'.$team.' · '.dq_label($matches[$mid]['info']),
         count($active).' separate sessions for the same scout and robot each contain actions. This may be valid reconnection data.',
         'Compare timestamps and individual actions before deleting anything.',
         ['session_ids'=>implode(', ',array_map(static fn($x)=>(string)$x['id'],$active))]);
    }
    // Traditional scores: compare each robot only with other matches of that same robot.
    foreach($actions as $team=>$byMatch){
      foreach($byMatch as $mid=>$r){$others=[];
         foreach($byMatch as $otherMid=>$other)if($otherMid!==$mid && (int)$other['offense_attempts']>0)$others[]=(float)$other['points'];
         if(count($others)<3 || (int)$r['offense_attempts']===0)continue;
         $med=dq_median($others);if($med===null)continue;
         $mad=dq_median(array_map(static fn($v)=>abs($v-$med),$others))??0;
         $value=(float)$r['points'];$difference=abs($value-$med);
         $threshold=max(22.0,2.8*$mad,0.65*max(1,abs($med)));
         if($difference<=$threshold)continue;
         dq_add($flags,'traditional',2,(int)$mid,(int)$team,[$mid,$team],
           'Scoring change · #'.$team.' · '.dq_label($matches[$mid]['info']),
           'Traditional scouting recorded '.dq_num($value).' points; other matches for #'.$team.' have a median of '.dq_num($med).' ('.count($others).' comparisons).',
           'Check its match action list. A strong opponent, failure, or game change can explain this result.',
           ['recorded'=>dq_num($value).' pts','robot_median'=>dq_num($med).' pts','comparison_matches'=>(string)count($others)]);
      }
    }
    // Tag analyses: real observer shares are PERCENTAGE contributions, not raw match points.
    $tagShares=[];$tagPresent=[];$unratedByTeam=[];
    foreach($rawTags as $team=>$byMatch)foreach($byMatch as $mid=>$rows){
      $shares=[];$union=[];
      foreach($rows as $r){
         foreach($r['tags'] as $tag)$union[$tag]=($union[$tag]??0)+1;
         if($r['scoring_contribution']!==null)$shares[]=(float)$r['scoring_contribution'];
         $contradictions=dq_opposite_pairs($r['tags']);
         if($contradictions)dq_add($flags,'tag-conflict',1,(int)$mid,(int)$team,[(int)$r['id']],
            'Conflicting tags in one report · #'.$team.' · '.dq_label($matches[$mid]['info']),
            'One human Match Tag observation contains both sides of: '.implode('; ',$contradictions).'.',
            'Open Match Tag Scouting and clarify the observation with its author.',
            ['observation_id'=>(string)$r['id'],'contradictions'=>implode('; ',$contradictions)]);
      }
      $tagPresent[$team][$mid]=array_values(array_unique(array_merge(array_keys($union),array_keys($extraTagKeys[$team][$mid]??[]))));
      if($shares){$tagShares[$team][$mid]=dq_median($shares);}
      else{$unratedByTeam[$team][]=(int)$mid;}
      // Contradictory tags across two human reports are a disagreement, not proof either is wrong.
      if(count($rows)>=2){
          $conflicts=dq_opposite_pairs(array_keys($union));
          if($conflicts){
             dq_add($flags,'tag-conflict',2,(int)$mid,(int)$team,[$mid,$team,'observers'],
               'Scouts disagree on tags · #'.$team.' · '.dq_label($matches[$mid]['info']),
               'Different observers applied competing tags: '.implode('; ',$conflicts).'. A robot may genuinely change behavior during the match.',
               'Compare the independent reports and match footage; keep both observations unless one is verified incorrect.',
               ['opposing_tags'=>implode('; ',$conflicts),'human_reports'=>(string)count($rows)]);
          }
      }
      if(count($shares)>=2 && max($shares)-min($shares)>=40){
          dq_add($flags,'tag-agreement',2,(int)$mid,(int)$team,[$mid,$team],
           'Scouts disagree on estimated contribution · #'.$team.' · '.dq_label($matches[$mid]['info']),
           count($shares).' human estimates range from '.dq_num(min($shares)).'% to '.dq_num(max($shares)).'% of the alliance score.',
           'Review the different reports and the match footage. These percentages are estimates, not actual points.',
           ['human_estimates'=>implode('%, ',array_map('dq_num',$shares)).'%']);
      }
    }
    // Missing contribution estimates: aggregate by robot to avoid 1 alert per old match.
    foreach($unratedByTeam as $team=>$ids){
        $labels=[];foreach(array_slice($ids,0,6) as $sampleMid)$labels[]=dq_label($matches[$sampleMid]['info']);
        $mid=(int)$ids[0];
        dq_add($flags,'tag-unrated',3,$mid,(int)$team,[$eventId,$team],
           'Tag reports without contribution estimate · #'.$team,
           count($ids).' tagged match(es) for this robot have no human percentage-of-alliance-scoring estimate. This may be intentional.',
           'Check whether the scout could make a defensible estimate. Do not fill in numbers without evidence.',
           ['matches'=>implode(', ',$labels).(count($ids)>6?' …':''),'without_estimate'=>(string)count($ids)]);
    }
    // A fully estimated alliance should be close to 100%; incomplete alliances are not judged.
    foreach($matches as $mid=>$m){
       $allianceShares=['Red'=>[],'Blue'=>[]];$allianceTeams=['Red'=>[],'Blue'=>[]];
       foreach($m['robots'] as $team=>$robot){$a=(string)$robot['alliance'];
          if(!isset($allianceTeams[$a]))continue;
          $allianceTeams[$a][]=(int)$team;
          if(isset($tagShares[$team][$mid]))$allianceShares[$a][$team]=$tagShares[$team][$mid];
       }
       foreach(['Red','Blue'] as $a){
          if(count($allianceTeams[$a])!==3 || count($allianceShares[$a])!==3)continue;
          $total=array_sum($allianceShares[$a]);
          if($total>=70 && $total<=130)continue;
          dq_add($flags,'tag-alliance',2,(int)$mid,0,[$mid,$a],
             $a.' alliance estimates total '.dq_num($total).'% · '.dq_label($m['info']),
             'All three robots have human contribution estimates, but their medians add up to '.dq_num($total).'% instead of roughly 100%. Estimates are subjective and may be independent.',
             'Review each robot’s estimated share and the actual alliance scoring before changing a report.',
             ['robot_estimates'=>implode(' · ',array_map(static fn($t,$v)=>'#'.$t.' '.dq_num($v).'%',array_keys($allianceShares[$a]),array_values($allianceShares[$a])))]);
       }
    }
    foreach($extraTagKeys as $team=>$byMatch)foreach($byMatch as $mid=>$keys){
        $tagPresent[$team][$mid]=array_values(array_unique(array_merge($tagPresent[$team][$mid]??[],array_keys($keys))));
    }
    foreach($tagShares as $team=>$byMatch)foreach($byMatch as $mid=>$share){
        $others=[];foreach($byMatch as $om=>$v)if($om!==$mid)$others[]=$v;
        if(count($others)<3)continue;
        $median=dq_median($others);$mad=dq_median(array_map(static fn($v)=>abs($v-$median),$others))??0;
        if(abs($share-$median)<=max(35.0,2.8*$mad))continue;
        dq_add($flags,'tag-trend',2,(int)$mid,(int)$team,[$mid,$team,'share'],
           'Contribution estimate differs from this robot’s history · #'.$team,
           'For '.dq_label($matches[$mid]['info']).', the human Tag Scouting median is '.dq_num($share).'% of alliance scoring; across '.count($others).' other matches for this robot the median is '.dq_num($median).'%.',
           'Check whether the robot changed roles, had a breakdown, or whether the scout meant a different percentage.',
           ['this_match'=>dq_num($share).'%','robot_median'=>dq_num($median).'%','other_matches'=>(string)count($others)]);
    }
    // Role-tag trend: compare with three earlier matches of THIS SAME team; no cross-team assumptions.
    foreach($tagPresent as $team=>$byMatch){
       $ranked=[];
       foreach($byMatch as $mid=>$tags){$m=$matches[$mid]['info'];if((string)$m['comp_level']!=='qm')continue;
          $ranked[]=['mid'=>$mid,'no'=>(int)$m['match_number'],'tags'=>$tags];}
       usort($ranked,static fn($a,$b)=>($a['no']<=>$b['no'])?:($a['mid']<=>$b['mid']));
       foreach($ranked as $i=>$cur){
          if($i<3)continue;
          $previous=array_slice($ranked,0,$i);$three=array_slice($previous,-3);
          $directions=[['no_auton','auto_completed'],['no_scoring','scoring'],['slow_cycles','fast_cycles'],['end_failed','end_completed']];
          foreach($directions as [$bad,$good])foreach([[$bad,$good],[$good,$bad]] as [$from,$to]){
             if(!in_array($to,$cur['tags'],true))continue;
             if(count(array_filter($three,static fn($p)=>in_array($from,$p['tags'],true)))!==3)continue;
             $mid=(int)$cur['mid'];
             dq_add($flags,'tag-trend',3,$mid,(int)$team,[$mid,$team,$from,$to],
                'Tag pattern changed · #'.$team.' · '.dq_label($matches[$mid]['info']),
                'This match was tagged “'.$to.'” after the preceding three tagged matches used “'.$from.'”. This could reflect a real performance change.',
                'Check the match or scout notes before using earlier match history to predict this robot.',
                ['current_tag'=>$to,'preceding_tag'=>$from]);
          }
       }
    }
    // Compare official/manual-alliance-derived Tag point estimates to traditional robot scoring.
    foreach($tagMetrics as $team=>$byMatch)foreach($byMatch as $mid=>$r){
       if(!isset($actions[$team][$mid]) || empty($rawTags[$team][$mid]))continue;
       if((int)$r['observer_count']<=(int)$r['synthetic_observer_count'] || (int)$r['score_estimate_count']<1)continue;
       if(!in_array((string)$r['point_source'],['official','manual'],true)||$r['estimated_points']===null)continue;
       if((string)$r['latest_observation_at']!=='' && (string)$r['calculated_at']<(string)$r['latest_observation_at'])continue;
       if((int)$actions[$team][$mid]['offense_attempts']===0)continue;
       $tag=(float)$r['estimated_points'];$traditional=(float)$actions[$team][$mid]['points'];
       $difference=abs($tag-$traditional);
       if($difference<20 || $difference/max(1,abs($tag),abs($traditional))<0.45)continue;
       dq_add($flags,'tag-points',2,(int)$mid,(int)$team,[$mid,$team],
          'Tag estimate and recorded actions differ · #'.$team.' · '.dq_label($matches[$mid]['info']),
          'Traditional actions sum to '.dq_num($traditional).' points; the '.(string)$r['point_source'].'-alliance-based Tag estimate is '.dq_num($tag).' points (model confidence '.dq_num((float)$r['confidence_score']).'%). These are different methods and may legitimately disagree.',
          'Compare the actions, percentage contribution, and official alliance score. Do not automatically replace either value.',
          ['traditional'=>dq_num($traditional).' pts','tag_estimated'=>dq_num($tag).' pts','estimate_source'=>(string)$r['point_source']]);
    }
    // Low samples: at least three completed match opportunities and 1–2 covered.
    $robotOpportunities=[];
    foreach($matches as $mid=>$m)foreach($m['robots'] as $team=>$r)$robotOpportunities[$team][]=$mid;
    if($scanComplete)foreach($robotOpportunities as $team=>$ids){
      if(count($ids)<3)continue;$observed=0;
      foreach($ids as $mid)if(!empty($coverage[$mid][$team]))$observed++;
      if($observed<1 || $observed>=3)continue;
      dq_add($flags,'sample',3,0,(int)$team,[$eventId,$team],
        'Limited scouting for robot #'.$team,
        'Only '.$observed.' of '.count($ids).' completed appearances have human scouting or actions. That is a weak sample for ranking this robot.',
        'Prioritize this robot for scouting at its next match; treat estimated rankings as uncertain.',
        ['covered'=>(string)$observed,'ended_appearances'=>(string)count($ids)]);
    }
 }catch(Throwable $e){$scanComplete=false;$error='The event scan was incomplete; do not use these counts for decisions.';error_log('DQC event scan: '.$e->getMessage());$flags=[];}
}
if($schemaReady && $eventId){
 try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
       verify_csrf();$key=(string)($_POST['flag_key']??'');$status=(string)($_POST['status']??'');
       $note=trim((string)($_POST['note']??''));
       if(!$scanComplete||!isset($flags[$key]))throw new RuntimeException('Finding changed or data scan incomplete. Refresh before saving.');
       if(!in_array($status,['needs-correction','reviewed','dismissed'],true))throw new RuntimeException('Invalid review status.');
       if(strlen($note)>2000)throw new RuntimeException('Review note must be shorter than 2,000 bytes.');
       if($status==='needs-correction' && $note==='')throw new RuntimeException('Add a specific follow-up note.');
       $pdo->beginTransaction();
       $q=$pdo->prepare('SELECT status FROM neptune_quality_reviews WHERE organization_id=? AND event_id=? AND flag_key=? FOR UPDATE');
       $q->execute([$org,$eventId,$key]);$previous=(string)($q->fetchColumn()?:'open');
       $q=$pdo->prepare('INSERT INTO neptune_quality_reviews (organization_id,event_id,flag_key,status,note,reviewed_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),note=VALUES(note),reviewed_by=VALUES(reviewed_by),updated_at=CURRENT_TIMESTAMP');
       $q->execute([$org,$eventId,$key,$status,$note,$actor]);
       $q=$pdo->prepare('INSERT INTO neptune_quality_review_audit (organization_id,event_id,flag_key,old_status,new_status,note,actor_user_id) VALUES(?,?,?,?,?,?,?)');
       $q->execute([$org,$eventId,$key,$previous,$status,$note,$actor]);
       $pdo->commit();header('Location: '.base_url('analytics/data-quality.php').'?'.http_build_query(['event_id'=>$eventId,'saved'=>1]));exit;
    }
    $q=$pdo->prepare('SELECT flag_key,status,note,reviewed_by,updated_at FROM neptune_quality_reviews WHERE organization_id=? AND event_id=?');
    $q->execute([$org,$eventId]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$reviews[(string)$r['flag_key']]=$r;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
$filter=(string)($_GET['type']??'all');if($filter!=='all'&&!isset($kinds[$filter]))$filter='all';
$state=(string)($_GET['state']??'open');if(!in_array($state,['open','closed','all'],true))$state='open';
$counts=array_fill_keys(array_keys($kinds),0);$attention=0;
foreach($flags as $f){$counts[$f['kind']]++;$status=(string)($reviews[$f['key']]['status']??'open');if(!in_array($status,['reviewed','dismissed'],true))$attention++;}
$display=array_filter($flags,static function($f)use($filter,$state,$reviews){
    if($filter!=='all'&&$f['kind']!==$filter)return false;
    $closed=in_array((string)($reviews[$f['key']]['status']??'open'),['reviewed','dismissed'],true);
    return $state==='all'||($state==='closed'?$closed:!$closed);
});
uasort($display,static fn($a,$b)=>($a['priority']<=>$b['priority'])?:($a['mid']<=>$b['mid'])?:($a['team']<=>$b['team']));
$inspected=(string)($_GET['inspect']??'');$history=[];
if($schemaReady&&isset($flags[$inspected])){
 try{$q=$pdo->prepare('SELECT old_status,new_status,note,actor_user_id,created_at FROM neptune_quality_review_audit WHERE organization_id=? AND event_id=? AND flag_key=? ORDER BY id DESC LIMIT 20');
     $q->execute([$org,$eventId,$inspected]);$history=$q->fetchAll(PDO::FETCH_ASSOC);
 }catch(Throwable $e){error_log('DQC history: '.$e->getMessage());}
}
include dirname(__DIR__).'/partials_header.php';
?>
<style>
/* AUGUR layout: use Neptune's shared module-page, module header, toolbar, card, btn, pill, and notice. */
.dq-page{width:100%;min-width:0;max-width:none}.dq-page .dq-lead{max-width:74ch;color:var(--muted);font-size:.88rem;line-height:1.5}
.dq-page .dq-toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin:15px 0 17px}.dq-page .dq-event-picker{display:grid;gap:5px;font-size:.8rem;font-weight:750;color:var(--muted)}
.dq-page .dq-event-picker select{font-size:.9rem!important;line-height:1.25;width:min(330px,75vw)!important;max-width:100%;padding:9px 12px!important;min-height:0!important;box-sizing:border-box}
.dq-page .dq-toolbar .btn{font-size:.84rem!important;min-height:0!important;padding:10px 14px!important;width:auto!important}
.dq-page .dq-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.dq-page .dq-card{padding:14px;min-width:0}
.dq-page .dq-stat small{color:var(--muted);display:block;font-size:.74rem;font-weight:700}.dq-page .dq-stat strong{font-size:1.55rem;line-height:1.3;display:block;margin-top:4px}
.dq-page .dq-source{margin-bottom:16px}.dq-page .dq-source strong{font-size:.9rem}.dq-page .dq-source p{margin:7px 0 0;color:var(--muted);font-size:.8rem;line-height:1.5}
.dq-page .dq-source-facts{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}.dq-page .dq-source-facts .pill{font-size:.72rem}
.dq-page .dq-filters{display:flex;flex-wrap:wrap;gap:7px;margin:13px 0}.dq-page .dq-filters .btn{font-size:.75rem!important;padding:7px 11px!important;min-height:0!important;width:auto!important}
.dq-page .dq-filters .dq-selected{border-color:var(--accent);color:var(--text);background:color-mix(in srgb,var(--accent) 12%,var(--panel))}
.dq-page .dq-issue{border-left:3px solid var(--warn,#cd913a);margin-bottom:12px}.dq-page .dq-issue[data-priority="1"]{border-left-color:var(--bad,#da5d5d)}
.dq-page .dq-issue-head{display:flex;justify-content:space-between;align-items:start;gap:12px;flex-wrap:wrap}
.dq-page .dq-issue h3{font-size:1rem;margin:7px 0 4px}.dq-page .dq-issue .dq-status{font-size:.69rem;margin-left:6px;color:var(--muted)}
.dq-page .dq-reason{font-size:.81rem;color:var(--text);line-height:1.5;margin:8px 0 10px}.dq-page .dq-next{color:var(--muted);font-size:.8rem;line-height:1.5;margin:0 0 12px}
.dq-page .dq-actions{display:flex;flex-wrap:wrap;gap:8px}.dq-page .dq-actions .btn,.dq-page .dq-issue-head .btn{font-size:.76rem!important;padding:7px 11px!important;min-height:0!important;width:auto!important}
.dq-page .dq-details{margin-top:12px;padding:13px;background:var(--panel2,var(--card));border:1px solid var(--line);border-radius:9px}
.dq-page .dq-evidence{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:8px;margin:10px 0}.dq-page .dq-evidence>div{border:1px solid var(--line);padding:8px;border-radius:7px;font-size:.74rem;overflow-wrap:anywhere}
.dq-page .dq-evidence b{display:block;font-size:.7rem;color:var(--muted);margin-bottom:4px}.dq-page .dq-review{border-top:1px solid var(--line);margin-top:12px;padding-top:12px;display:grid;gap:9px}
.dq-page .dq-review label{display:grid;gap:6px;font-size:.76rem;color:var(--muted)}.dq-page .dq-review select,.dq-page .dq-review textarea{font-size:.85rem!important;max-width:100%;box-sizing:border-box;background:var(--panel,var(--card));color:var(--text);border:1px solid var(--line);border-radius:8px;padding:9px 11px!important}
.dq-page .dq-review textarea{width:100%;min-height:65px;resize:vertical}.dq-page .dq-review button{justify-self:start}
.dq-page .dq-history{padding-left:18px;margin:8px 0;font-size:.73rem;color:var(--muted)}.dq-page .dq-history li{margin:4px 0}.dq-page .dq-small{color:var(--muted);font-size:.76rem;line-height:1.5}
.dq-page .dq-empty{padding:22px;text-align:center}.dq-page .dq-source details{margin-top:8px}.dq-page .dq-source summary{cursor:pointer;font-size:.78rem}
@media(max-width:900px){.dq-page .dq-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.dq-page .dq-grid{grid-template-columns:1fr 1fr;gap:8px}.dq-page .dq-stat strong{font-size:1.25rem}.dq-page .dq-card{padding:11px}.dq-page .dq-event-picker select{width:100%!important}.dq-page .dq-toolbar{align-items:stretch}.dq-page .dq-toolbar form{width:100%}}
</style>
<?php
// Scout accuracy vs official scores. Weights are cached for analytics; scouting rows are never edited.
$accuracy=null;
if($eventId>0){
    try{$accuracy=neptune_scout_accuracy($pdo,$org,$eventId);neptune_scout_accuracy_store($pdo,$org,$eventId,$accuracy);}
    catch(Throwable $e){error_log('[Neptune scout accuracy] '.$e->getMessage());$accuracy=['available'=>false,'reason'=>'Scout accuracy could not be calculated for this event.'];}
}
$pct=static fn(?float $v):string=>$v===null?'n/a':number_format($v*100,0).'%';
$gradeLabel=['unreliable'=>'Unreliable','check'=>'Check','ok'=>'OK','insufficient'=>'Not enough data','imported'=>'Imported'];
?>
<style>
.dq-page .dq-acc table{width:100%;border-collapse:collapse;font-size:.8rem;margin-top:10px}
.dq-page .dq-acc th,.dq-page .dq-acc td{text-align:left;padding:7px 8px;border-bottom:1px solid var(--line);vertical-align:top}
.dq-page .dq-acc th{font-size:.72rem;color:var(--muted);font-weight:800}
.dq-page .dq-acc .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.dq-page .dq-grade{font-size:.7rem;font-weight:800;padding:3px 8px;border-radius:999px;border:1px solid var(--line);white-space:nowrap}
.dq-page .dq-grade.unreliable{border-color:var(--bad,#da5d5d);color:var(--bad,#da5d5d)}
.dq-page .dq-grade.check{border-color:var(--warn,#cd913a);color:var(--warn,#cd913a)}
.dq-page .dq-grade.ok{border-color:var(--good,#45A77D);color:var(--good,#45A77D)}
.dq-page .dq-acc-wrap{overflow-x:auto}
</style>
<section class="module-page dq-page">
 <header class="module-page-header">
  <div><div class="module-code">AUGUR · EVENT ANALYTICS</div>
  <h1>Scouting Data Quality</h1><p class="dq-lead">Identify incomplete coverage and questionable observations using your organization's real match actions and human Tag Scouting records.</p></div>
  <a class="btn secondary" href="<?=dq_e(base_url('analytics/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Analytics &amp; Strategy</a>
 </header>
 <?php if($error):?><div class="notice bad" role="alert"><?=dq_e($error)?></div><?php endif;?>
 <?php if($schemaError):?><div class="notice" role="status"><?=dq_e($schemaError)?></div><?php endif;?>
 <?php if(isset($_GET['saved'])):?><div class="notice good">Review decision saved to the audit log.</div><?php endif;?>
 <form class="dq-toolbar" method="get"><label class="dq-event-picker">Event
   <select name="event_id"><?=neptune_event_options_html($events,$eventId)?></select></label>
   <button class="btn secondary" type="submit">View event</button><?=neptune_history_toggle_html(neptune_selector_show_history(),'events')?>
 </form>
 <div class="dq-grid" aria-label="Data quality overview">
   <div class="card dq-card dq-stat"><small>Completed matches</small><strong><?=number_format($endedCount)?></strong></div>
   <div class="card dq-card dq-stat"><small>Matches with scouting</small><strong><?=number_format($withEvidence)?></strong></div>
   <div class="card dq-card dq-stat"><small>Partially covered matches</small><strong><?=number_format($partial)?></strong></div>
   <div class="card dq-card dq-stat"><small>Open findings</small><strong><?=number_format($attention)?></strong></div>
 </div>
 <section class="card dq-card dq-source" aria-label="Sources and counting rules">
  <strong>Scouting sources</strong>
  <div class="dq-source-facts"><span class="pill"><?=number_format($sourceCount['actions'])?> traditional actions</span><span class="pill"><?=number_format($sourceCount['tag_human'])?> human Match Tag reports</span><span class="pill"><?=number_format($sourceCount['tag_notes'])?> other match observations</span><span class="pill"><?=number_format($sourceCount['tag_estimates'])?> contribution estimates</span></div>
  <?php if($zero):?><p><?=number_format($zero)?> ended match(es) have no recorded scouting for any assigned robot. These are shown as unscouted event history, <b>not</b> <?=number_format($zero*6)?> separate data errors.</p><?php endif;?>
  <?php if($badScope):?><p><b>Roster/schedule mismatch:</b> <?=number_format($badScope)?> ended-match team assignment(s) lack a corresponding event-roster entry and are excluded. Verify Event Setup before relying on the report.</p><?php endif;?>
  <?php if($sourceCount['tag_synthetic']):?><p><?=number_format($sourceCount['tag_synthetic'])?> synthetic Tag Scouting entries are excluded from human evidence and scoring comparisons.</p><?php endif;?>
  <?php if(!$scanComplete):?><div class="notice bad" role="alert">Tag Scouting sources could not all be verified<?=($missingTables?': '.dq_e(implode(', ',$missingTables)):'')?>. Coverage flags and review submissions are disabled until the source is available.</div><?php endif;?>
  <details><summary>How the checks work</summary><p>Counts refer to actual matches and verified robot assignments, not all historical sessions. Incomplete means some robots in an ended match have observations while others do not. Contribution estimates are percentages of alliance scoring, not measured robot points. Derived Tag estimates are not evidence that a person scouted. Outliers are review suggestions, not confirmed errors. Decisions never change scoring data.</p></details>
 </section>
 <?php if($accuracy!==null):?>
 <section class="card dq-card dq-source dq-acc" id="scout-accuracy" aria-label="Scout accuracy">
  <strong>Scout accuracy vs official scores</strong>
  <?php if(empty($accuracy['available'])):?>
   <p><?=dq_e($accuracy['reason'])?></p>
  <?php else:?>
   <div class="dq-source-facts">
    <span class="pill"><?=number_format($accuracy['alliances'])?> fully scouted alliances compared with <?=dq_e($accuracy['event_key'])?></span>
    <span class="pill">Scouts captured <?=$pct($accuracy['capture'])?> of official points (penalties removed)</span>
    <span class="pill">Auto <?=$pct($accuracy['capture_auto'])?> · Teleop <?=$pct($accuracy['capture_teleop'])?> · Endgame <?=$pct($accuracy['capture_endgame'])?></span>
    <span class="pill">Typical alliance miss <?=dq_num(round($accuracy['mae_raw'],1))?> pts · <?=dq_num(round($accuracy['mae_calibrated'],1))?> after the event-wide gap</span>
   </div>
   <?php $phaseOff=array_filter(['auto'=>$accuracy['capture_auto'],'teleop'=>$accuracy['capture_teleop'],'endgame'=>$accuracy['capture_endgame']],static fn($v)=>$v!==null&&($v<0.8||$v>1.2));
   if($phaseOff):?><p><b>Phase mismatch:</b> scouting recorded <?=dq_e(implode(', ',array_map(static fn($k,$v)=>$k.' at '.number_format($v*100,0).'%',array_keys($phaseOff),$phaseOff)))?> of the official points. This is usually a game-configuration issue (action point values or auto/teleop timing), not one scout. Check the published game revision.</p><?php endif;?>
   <?php if(!$accuracy['identified_scouts']):?>
    <p>This event's actions were imported without individual scouts, so only the team-wide comparison above is available. Per-scout accuracy appears for scouting entered in Neptune by signed-in scouts.</p>
   <?php else:?>
   <div class="dq-acc-wrap"><table>
    <thead><tr><th>Scout</th><th class="num">Robot-matches</th><th class="num">Alliances compared</th><th class="num">Over / under per robot-match</th><th class="num">vs typical robot</th><th class="num">Confidence</th><th>Grade</th><th class="num">Weight</th></tr></thead>
    <tbody>
    <?php foreach($accuracy['scouts'] as $s):if(!$s['identified'])continue;?>
     <tr><td><?=dq_e($s['name'])?></td><td class="num"><?=number_format($s['robot_matches'])?></td><td class="num"><?=number_format($s['alliances'])?></td>
      <td class="num"><?=($s['bias_points']>0?'+':'').dq_num($s['bias_points'])?> pts</td><td class="num"><?=($s['relative_bias']>0?'+':'').number_format($s['relative_bias']*100,0)?>%</td>
      <td class="num">t = <?=number_format(abs((float)$s['t']),1)?></td><td><span class="dq-grade <?=dq_e($s['grade'])?>"><?=dq_e($gradeLabel[$s['grade']]??$s['grade'])?></span></td>
      <td class="num"><?=number_format((float)$s['weight'],2)?></td></tr>
    <?php endforeach;?>
    </tbody></table></div>
   <?php endif;?>
   <details><summary>How scout accuracy works</summary>
    <p>Only alliances where all three robots were scouted are compared, against TBA's official score with penalties removed. The event-wide capture rate is removed first, so the shared gap caused by point mapping is not blamed on anyone. Each alliance's remaining error is then split among the scouts who covered it; because scouts rotate between teammates, each scout's own tendency separates out. Scouts with fewer than <?=NSA_MIN_ALLIANCES?> compared alliances are shown as "Not enough data".</p>
    <p><b>Check</b> means the scout's totals run at least <?=NSA_CHECK*100?>% above or below a typical robot with confidence t ≥ <?=NSA_T_CHECK?>; review their matches. <b>Unreliable</b> means at least <?=NSA_UNRELIABLE*100?>% with t ≥ <?=NSA_T_UNRELIABLE?>; only then is the scout down-weighted (weight below 1) where another scout covered the same robot in the same match. Nothing is deleted or changed in the scouting records.</p>
   </details>
  <?php endif;?>
 </section>
 <?php endif;?>
 <div class="dq-filters" aria-label="Review status filters">
  <a class="btn secondary <?=$state==='open'?'dq-selected':''?>" href="<?=dq_e(dq_link(['event_id'=>$eventId,'type'=>$filter,'state'=>'open']))?>">Open</a>
  <a class="btn secondary <?=$state==='closed'?'dq-selected':''?>" href="<?=dq_e(dq_link(['event_id'=>$eventId,'type'=>$filter,'state'=>'closed']))?>">Reviewed / dismissed</a>
  <a class="btn secondary <?=$state==='all'?'dq-selected':''?>" href="<?=dq_e(dq_link(['event_id'=>$eventId,'type'=>$filter,'state'=>'all']))?>">All statuses</a>
 </div>
 <div class="dq-filters" aria-label="Finding type filters">
  <a class="btn secondary <?=$filter==='all'?'dq-selected':''?>" href="<?=dq_e(dq_link(['event_id'=>$eventId,'type'=>'all','state'=>$state]))?>">All findings · <?=count($flags)?></a>
  <?php foreach($kinds as $type=>$label):if(!$counts[$type])continue;?><a class="btn secondary <?=$filter===$type?'dq-selected':''?>" href="<?=dq_e(dq_link(['event_id'=>$eventId,'type'=>$type,'state'=>$state]))?>"><?=dq_e($label)?> · <?=$counts[$type]?></a><?php endforeach;?>
 </div>
 <?php if(!$display):?><div class="card dq-card dq-empty"><strong>No findings in this view.</strong><p class="dq-small"><?=($endedCount===0?'There are no ended matches with verified robot assignments in this event.':($zero===$endedCount?'This event has no recorded scouting sources; an empty event is not a claim that 169 scouting records are defective.':'Change the filters to see other findings.'))?></p></div><?php endif;?>
 <?php foreach($display as $f):$key=$f['key'];$review=$reviews[$key]??null;$status=(string)($review['status']??'open');$expanded=$inspected===$key;?>
 <article class="card dq-card dq-issue" data-priority="<?=$f['priority']?>" id="finding-<?=dq_e($key)?>">
   <div class="dq-issue-head"><div><span class="pill"><?=dq_e($kinds[$f['kind']])?></span><span class="dq-status"><?=dq_e($status)?></span><h3><?=dq_e($f['title'])?></h3></div>
    <a class="btn secondary" href="<?=dq_e(dq_link(['event_id'=>$eventId,'type'=>$filter,'state'=>$state,'inspect'=>$expanded?'':$key]))?>#finding-<?=dq_e($key)?>"><?=$expanded?'Hide evidence':'Inspect evidence'?></a></div>
   <p class="dq-reason"><?=dq_e($f['why'])?></p><p class="dq-next"><b>Next:</b> <?=dq_e($f['next'])?></p>
   <div class="dq-actions">
     <?php if($f['mid']):?><a class="btn secondary" href="<?=dq_e(base_url('admin/live.php').'?'.http_build_query(['match_id'=>$f['mid']]))?>"><i class="fa-solid fa-list-check"></i> Match actions</a><?php endif;?>
     <?php if($f['mid']):?><a class="btn secondary" href="<?=dq_e(base_url('scout/impact.php').'?'.http_build_query(['event_id'=>$eventId,'match_id'=>$f['mid'],'robot'=>$f['team']]))?>"><i class="fa-solid fa-tags"></i> Match Tag Scouting</a><?php endif;?>
     <?php if($f['team']):?><a class="btn secondary" href="<?=dq_e(base_url('analytics/robot-lookup.php').'?'.http_build_query(['event_id'=>$eventId,'team'=>$f['team']]))?>"><i class="fa-solid fa-robot"></i> Robot #<?=$f['team']?></a><?php endif;?>
   </div>
   <?php if($expanded):?><div class="dq-details"><strong>Recorded evidence</strong><div class="dq-evidence">
      <?php if(!$f['evidence']):?><div>Match and robot details are in the links above.</div><?php endif;?>
      <?php foreach($f['evidence'] as $lab=>$val):?><div><b><?=dq_e(str_replace('_',' ',ucwords((string)$lab,'_')))?></b><?=dq_e($val)?></div><?php endforeach;?></div>
      <?php if($history):?><strong>Review history</strong><ul class="dq-history"><?php foreach($history as $h):?><li><?=dq_e(neptune_time($h['created_at']))?> · User #<?=(int)$h['actor_user_id']?> · <?=dq_e($h['old_status'])?> → <?=dq_e($h['new_status'])?><?=((string)$h['note']!==''?' · '.dq_e($h['note']):'')?></li><?php endforeach;?></ul><?php endif;?>
     </div><?php endif;?>
   <?php if($schemaReady&&$scanComplete):?><form class="dq-review" method="post"><input type="hidden" name="csrf" value="<?=dq_e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="flag_key" value="<?=dq_e($key)?>">
     <label>Review decision<select name="status"><option value="needs-correction" <?=$status==='needs-correction'?'selected':''?>>Needs follow-up</option><option value="reviewed" <?=$status==='reviewed'?'selected':''?>>Reviewed — valid observation</option><option value="dismissed" <?=$status==='dismissed'?'selected':''?>>Dismissed — false alarm</option></select></label>
     <label>Reason / follow-up notes<textarea name="note" maxlength="2000" placeholder="What did you verify? What should be corrected?"><?=dq_e($review['note']??'')?></textarea></label>
     <button type="submit" class="btn secondary">Save review decision</button>
     <?php if($review):?><div class="dq-small">Last reviewed <?=dq_e(neptune_time($review['updated_at']))?> by user #<?=(int)$review['reviewed_by']?>. Reviewing does not edit scouting records.</div><?php endif;?>
   </form><?php endif;?>
 </article><?php endforeach;?>
 <p class="dq-small">This page uses Neptune's local event roster and schedule, not independent TBA verification. If either source contains a wrong team, correct it in Event Setup; the Data Quality Center will not invent or silently delete records.</p>
</section>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
