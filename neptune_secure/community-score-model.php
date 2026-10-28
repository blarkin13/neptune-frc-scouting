<?php
declare(strict_types=1);
function community_score_target(array $row):bool {
 $s=$row['scoring']??null;if(!is_array($s)||($s['mode']??'')!=='alliance_share'||($row['tag_key']??'')!=='scoring'||($row['label_scope']??'')!=='match'||($row['polarity']??'present')!=='present')return false;
 $points=$s['robot_points']??null;$total=$s['alliance_score']??null;
 return ($s['source']??'')==='human_video_contribution'&&($s['score_basis']??'')==='full_final_alliance_score'&&is_int($points)&&is_int($total)&&$total>0&&$total<=100000&&$points>=0&&$points<=$total&&((($s['method']??'')==='estimated'&&($row['certainty']??'')==='uncertain')||(($s['method']??'')==='counted'&&($row['certainty']??'')==='observed'));
}
function community_score_model_valid(array $head):bool {
 if(!in_array([$head['version']??'',$head['target']??''],[['robot-score-kernel-v1','share_of_full_final_alliance_score'],['robot-score-kernel-v2','individual_robot_points']],true)||($head['feature_dimension']??0)!==288||($head['basis']??'')!=='includes_penalties')return false;
 foreach(['mean','scale'] as $key)if(!is_array($head[$key]??null)||count($head[$key])!==288)return false;
 if(!is_array($head['centers']??null)||count($head['centers'])<2||count($head['centers'])>256||!is_array($head['coefficients']??null)||count($head['coefficients'])!==count($head['centers'])||!is_array($head['used_label_ids']??null)||!$head['used_label_ids'])return false;
 foreach(['used_label_ids','training_sources','training_groups'] as $key)if(!is_array($head[$key]??null)||!$head[$key]||array_filter($head[$key],fn($v)=>!is_string($v)||$v===''))return false;
 $values=array_merge($head['mean'],$head['scale'],$head['coefficients'],[$head['bias']??null]);
 foreach($head['centers'] as $v){if(!is_array($v)||count($v)!==288)return false;$values=array_merge($values,$v);}
 foreach($values as $v)if(!is_int($v)&&!is_float($v)||!is_finite((float)$v))return false;
 foreach($head['scale'] as $v)if($v<=0)return false;
 return true;
}
function community_score_benchmark_pass(mixed $bench):bool {
 if(!is_array($bench)||empty($bench['release_ready'])||empty($bench['complete_reference']))return false;
 foreach(['point_mae','share_mae','prediction_coverage'] as $key)if(!isset($bench[$key])||!is_numeric($bench[$key])||!is_finite((float)$bench[$key]))return false;
 return ($bench['matches']??0)>=10&&($bench['events']??0)>=2&&($bench['reference_robots']??0)>=60&&($bench['predicted_robots']??0)>=.9*$bench['reference_robots']&&$bench['prediction_coverage']>=.9&&$bench['prediction_coverage']<=1&&$bench['point_mae']>=0&&$bench['point_mae']<=20&&$bench['share_mae']>=0&&$bench['share_mae']<=.1;
}

function community_import_score_target(array $r):bool {
 $s=$r['scoring']??[];$e=$r['external']??[];$c=$e['components']??[];
 foreach(['auto_fuel_score','tele_fuel_score','auto_climb_level','tele_climb_level'] as $k)if(!isset($c[$k])||!is_int($c[$k])||$c[$k]<0)return false;
 if($c['auto_climb_level']>1||$c['tele_climb_level']>3||max($c['auto_fuel_score'],$c['tele_fuel_score'])>10000)return false;
 $points=$c['auto_fuel_score']+$c['tele_fuel_score']+15*$c['auto_climb_level']+[0,10,20,30][$c['tele_climb_level']];
 return ($r['evidence_type']??'')==='external_scouting_label'&&($r['definition_version']??'')==='viper-2026-points-v1'&&($r['label_scope']??'')==='match'&&($r['certainty']??'')==='uncertain'&&($s['source']??'')==='external_scouting_estimate'&&($s['robot_points']??null)===$points&&($e['provider']??'')==='viperscout'&&($e['weak_supervision']??false)===true;
}
