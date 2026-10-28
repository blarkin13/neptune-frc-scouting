<?php
declare(strict_types=1);
// Public aggregates only. A contribution is never evidence of complete coverage.
function community_match_coverage(PDO $db,array $matches):array {
 $out=[];foreach($matches as $m){$robots=[];foreach(array_merge($m['roster']['red'],$m['roster']['blue']) as $team)$robots[(string)$team]=['tags'=>0,'tag_counts'=>[],'scoring_estimates'=>0];$out[(int)$m['id']]=['available'=>true,'labels'=>0,'robots_tagged'=>0,'complete'=>null,'robots'=>$robots];}
 if(!$out)return [];
 try{$q=$db->prepare("SELECT l.match_id,l.team_number,l.tag_key,l.scoring_json FROM community_video_labels l JOIN community_matches m ON m.id=l.match_id WHERE l.match_id IN (".implode(',',array_fill(0,count($out),'?')).") AND l.review_state IN ('contributed','verified') AND l.polarity='present' AND l.source_url=m.source_url");$q->execute(array_keys($out));
 foreach($q as $r){$id=(int)$r['match_id'];$team=(string)$r['team_number'];if(!isset($out[$id]['robots'][$team]))continue;$robot=&$out[$id]['robots'][$team];$robot['tags']++;$score=$r['scoring_json']?json_decode($r['scoring_json'],true):null;if(($score['mode']??'')==='alliance_share')$robot['scoring_estimates']++;else $robot['tag_counts'][$r['tag_key']]=($robot['tag_counts'][$r['tag_key']]??0)+1;$out[$id]['labels']++;unset($robot);}
 foreach($out as &$coverage)$coverage['robots_tagged']=count(array_filter($coverage['robots'],fn($r)=>$r['tags']>0));unset($coverage);
 }catch(PDOException $e){error_log('Community coverage unavailable: '.$e->getCode());foreach($out as &$c){$c['available']=false;$c['labels']=null;$c['robots_tagged']=null;}unset($c);}
 return $out;
}
function community_coverage_html(array $coverage):void {
 if(empty($coverage['available'])){echo '<p>Prior tagging information is temporarily unavailable.</p>';return;}
 echo '<details class="community-coverage"><summary>Prior contributions: '.(int)$coverage['labels'].' entries · '.(int)$coverage['robots_tagged'].' of 6 robots</summary><p>These are saved observations, not confirmation that a robot or match is fully scouted. More than one person may contribute.</p><ul>';
 foreach($coverage['robots'] as $team=>$r){echo '<li><strong>'.(int)$team.'</strong>: '.(int)$r['tags'].' entries';$parts=[];foreach($r['tag_counts'] as $tag=>$n)$parts[]=htmlspecialchars(ucwords(str_replace('_',' ',$tag)),ENT_QUOTES,'UTF-8').' × '.(int)$n;echo $parts?' — '.implode(', ',$parts):' — no contributions yet';if($r['scoring_estimates'])echo ' · '.(int)$r['scoring_estimates'].' scoring estimate(s)';echo '</li>';}
 echo '</ul></details>';
}
