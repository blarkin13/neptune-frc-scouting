<?php
declare(strict_types=1);
/** Recorded-scoring allocation, separate from share of the final scoreboard. */
function community_recorded_contributions(array $scores,array $tags):array {
 $counts=[];foreach($scores as $row)$counts[$row['team']]=0;
 foreach($tags as $tag){$key=$tag['tag_key']??'';$scoring=in_array($key,['scoring','end_completed'],true)||(is_string($key)&&preg_match('/^gb:(?:score_[A-Za-z0-9_.:-]+|climb_lv_[1-3])$/D',$key)&&!preg_match('/attempt|miss|fail/i',$key));if($scoring&&isset($counts[$tag['team']??0]))$counts[$tag['team']]++;}
 foreach($scores as &$row){$row['recorded_scoring_events']=$counts[$row['team']];if(!$row['recorded_scoring_events']){$row['status']='predicted';$row['robot_points']=0;$row['share_percent']=($row['alliance_score']??0)>0?0:null;$row['reason']='no_recorded_scoring';$row['observed_points']=null;$row['zero_by_policy']=true;}}unset($row);
 foreach(['red','blue'] as $alliance){$indices=array_keys(array_filter($scores,fn($row)=>$row['alliance']===$alliance));$active=array_filter($indices,fn($i)=>$scores[$i]['recorded_scoring_events']>0);$points=(bool)$active;foreach($active as $i)if(!is_int($scores[$i]['robot_points'])||$scores[$i]['robot_points']<=0)$points=false;
  $weights=[];foreach($indices as $i)$weights[$i]=$scores[$i]['recorded_scoring_events']?($points?$scores[$i]['robot_points']:$scores[$i]['recorded_scoring_events']):0;$total=array_sum($weights);$parts=[];$fraction=[];foreach($weights as $i=>$weight){$raw=$total>0?100000*$weight/$total:0;$parts[$i]=(int)floor($raw);$fraction[$i]=$raw-$parts[$i];}arsort($fraction,SORT_NUMERIC);$left=$total>0?100000-array_sum($parts):0;foreach($fraction as $i=>$f){if($left--<=0)break;$parts[$i]++;}
  foreach($indices as $i){$scores[$i]['contribution_percent']=$parts[$i]/1000;$scores[$i]['contribution_basis']=$points?'recorded_scoring_point_estimates':'recorded_scoring_event_count';$scores[$i]['contribution_status']=$total>0?'normalized':'no_scoring_recorded';}
 }return $scores;
}
