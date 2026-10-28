<?php
declare(strict_types=1);
/** Human scoring evidence; no attribution from alliance score changes. */
function community_scoring_rules(int $season):?array {
 $shared=['version'=>'community-scoring-v1','season'=>$season,'components'=>[]];
 if($season===2026)return $shared+['game'=>'REBUILT','source'=>'https://firstfrc.blob.core.windows.net/frc2026/Manual/HTML/2026GameManual.htm'];
 if($season===2025)return $shared+['game'=>'REEFSCAPE','source'=>'https://firstfrc.blob.core.windows.net/frc2025/Manual/2025GameManual.pdf'];
 return null;
}
function community_scoring_profile(int $season):?array {
 $r=community_scoring_rules($season);if(!$r)return null;
 // [caption, AUTO points/unit, TELEOP points/unit, maximum successful units/entry]
 $r['components']=$season===2026?[
  'fuel'=>['Fuel scored in hub',1,1,1000],
  'tower_l1'=>['Tower Level 1 completed',15,10,1],
  'tower_l2'=>['Tower Level 2 completed',null,20,1],
  'tower_l3'=>['Tower Level 3 completed',null,30,1],
 ]:[
  'coral_l1'=>['Coral L1',3,2,1000], 'coral_l2'=>['Coral L2',4,3,1000],
  'coral_l3'=>['Coral L3',6,4,1000], 'coral_l4'=>['Coral L4',7,5,1000],
  'algae_processor'=>['Algae in processor',6,6,1000], 'algae_net'=>['Algae in net',4,4,1000],
  'leave'=>['AUTO leave completed',3,null,1], 'park'=>['Endgame park',null,2,1],
  'shallow_cage'=>['Shallow cage completed',null,6,1], 'deep_cage'=>['Deep cage completed',null,12,1],
 ];return $r;
}
function community_scoring_validate(mixed $input,array $match,array $label):?array {
 if($input===null)return null;
 if(is_array($input)&&($input['mode']??'')==='alliance_share')return community_scoring_share_validate($input,$match,$label);
 if(!is_array($input)||($label['tag']['key']??'')!=='scoring'||$label['polarity']!=='present'||!in_array($label['scope'],['moment','interval'],true))throw new CommunityAnalysisError(422,'Scoring amounts need a positive scoring entry with a video timestamp.');
 $season=(int)substr((string)$match['match_key'],0,4);$rules=community_scoring_profile($season);
 if(!$rules)throw new CommunityAnalysisError(422,'Scoring rules are not configured for this season. You can still save ordinary tags.');
 $component=$input['component']??'';$method=$input['method']??'';$phase=$input['phase']??'';
 if(!is_string($component)||!isset($rules['components'][$component])||!in_array($method,['counted','estimated','unknown'],true)||!in_array($phase,['auto','teleop','unknown'],true))throw new CommunityAnalysisError(422,'Choose a scoring type, match period and amount evidence.');
 $def=$rules['components'][$component];$rate=$phase==='auto'?$def[1]:($phase==='teleop'?$def[2]:null);
 if($phase!=='unknown'&&$rate===null)throw new CommunityAnalysisError(422,'This scoring outcome is not available in that match period.');
 $hub=$season===2026&&$component==='fuel'?($input['hub_state']??'unknown'):'not_applicable';
 if(!in_array($hub,['active','inactive','unknown','not_applicable'],true)||($season===2026&&$component==='fuel'&&$hub==='not_applicable'))throw new CommunityAnalysisError(422,'Choose whether the hub was active.');
 $minimum=$maximum=null;
 if($method!=='unknown'){
  $minimum=$input['count_min']??null;$maximum=$input['count_max']??null;
  if(!is_int($minimum)||!is_int($maximum)||$minimum<0||$maximum<$minimum||$maximum>$def[3]||($method==='counted'&&$minimum!==$maximum))throw new CommunityAnalysisError(422,'Enter a whole-number successful count, or a valid estimated range.');
  if($method==='estimated'&&$minimum===$maximum)throw new CommunityAnalysisError(422,'Use a range for an estimate, or choose Counted for an exact count.');
 }
 $pointsMin=$pointsMax=null;
 if($minimum!==null&&$rate!==null&&$hub!=='unknown'){
  if($hub==='inactive')$rate=0;
  $pointsMin=$minimum*$rate;$pointsMax=$maximum*$rate;
 }
 // AUTO tower awards have an alliance cap. A visible climb alone does not prove it received credit.
 $qualification=$season===2026&&$phase==='auto'&&$component==='tower_l1'?'alliance_cap_requires_confirmation':'individual_rules_value';
 if($qualification==='alliance_cap_requires_confirmation')$pointsMin=$pointsMax=null;
 return ['schema_version'=>1,'season'=>$season,'game'=>$rules['game'],'rules_version'=>$rules['version'],'rules_source'=>$rules['source'],
 'component'=>$component,'component_label'=>$def[0],'phase'=>$phase,'method'=>$method,'count_min'=>$minimum,'count_max'=>$maximum,'hub_state'=>$hub,
 'points_min'=>$pointsMin,'points_max'=>$pointsMax,'points_basis'=>$qualification,'source'=>'human_video_contribution'];
}
function community_scoring_decode(array $row):array {
 $row['scoring']=isset($row['scoring_json'])?json_decode($row['scoring_json'],true,32,JSON_THROW_ON_ERROR):null;unset($row['scoring_json']);return $row;
}

function community_scoring_alliance_totals(array $match,?callable $fetch=null):?array {
 try {
  if(!$fetch){require_once __DIR__.'/tba.php';$fetch=fn(string $path)=>tba_get($path,900);}
  $key=(string)$match['match_key'];$data=$fetch('match/'.$key);
  if(($data['key']??null)!==$key||!(($data['actual_time']??0)>0||($data['post_result_time']??0)>0))return null;
  $totals=[];
  foreach(['red','blue'] as $alliance){
   $score=$data['alliances'][$alliance]['score']??null;
   if(!is_int($score)||$score<0||$score>100000)return null;
   $teams=array_map(fn($n)=>'frc'.$n,$match['roster'][$alliance]);$actual=$data['alliances'][$alliance]['team_keys']??[];sort($teams);sort($actual);if($teams!==$actual)return null;
   $totals[$alliance]=$score;
  }
  return ['red'=>$totals['red'],'blue'=>$totals['blue'],'basis'=>'full_final_alliance_score','penalties_included'=>true,'source'=>'the_blue_alliance','source_url'=>'https://www.thebluealliance.com/match/'.$key,'fetched_at'=>time()];
 }catch(Throwable $e){error_log('Community alliance scores: '.get_class($e));return null;}
}
function community_scoring_share_validate(array $input,array $match,array $label):array {
 if($label['tag']['key']!=='scoring'||$label['polarity']!=='present'||$label['scope']!=='match')throw new CommunityAnalysisError(422,'A contribution percentage describes this robot’s whole match.');
 $points=$input['robot_points']??null;$method=$input['method']??'estimated';
 if(!is_int($points)||$points<0||$points>100000||!in_array($method,['estimated','counted'],true))throw new CommunityAnalysisError(422,'Enter a whole-number robot score and choose whether it is estimated or counted.');
 if($method==='estimated'&&$label['certainty']!=='uncertain')throw new CommunityAnalysisError(422,'A whole-match estimate must retain uncertain evidence.');
 $alliance=in_array($label['team'],$match['roster']['red'],true)?'red':'blue';$totals=$match['alliance_totals']??null;$total=$totals[$alliance]??null;
 if($total!==null&&$points>$total)throw new CommunityAnalysisError(422,'The robot score cannot exceed its alliance’s full final score.');
 $season=(int)substr((string)$match['match_key'],0,4);
 return ['schema_version'=>1,'mode'=>'alliance_share','source'=>'human_video_contribution','season'=>$season,'game'=>community_scoring_profile($season)['game']??'Season '.$season,
 'rules_version'=>'alliance-share-v1','component'=>'alliance_total_share','component_label'=>'Whole-match contribution','phase'=>'whole_match','method'=>$method,
 'count_min'=>null,'count_max'=>null,'robot_points'=>$points,'points_min'=>$points,'points_max'=>$points,'alliance'=>$alliance,'alliance_score'=>$total,
 'share_percent'=>$total>0?round(100*$points/$total,3):null,'score_basis'=>'full_final_alliance_score','penalties_included'=>true,
 'alliance_score_source'=>$totals['source']??null,'alliance_score_source_url'=>$totals['source_url']??null,'alliance_score_fetched_at'=>$totals['fetched_at']??null,
 'points_basis'=>'human_whole_match_contribution'];
}
