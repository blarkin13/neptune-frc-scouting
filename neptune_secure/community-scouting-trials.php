<?php
declare(strict_types=1);
require_once __DIR__.'/community-model-json.php';
require_once __DIR__.'/community-contributions.php';
require_once __DIR__.'/community-trainers.php';
/** Owner-only experimental model runs. Results never become human scouting labels. */
final class CommunityScoutingTrials {
 public function __construct(private PDO $db,private mixed $scoreFetch=null){community_training_progress_schema($db);}
 private function q(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 public function matches(int $season):array{
  $q=$this->q("SELECT id,title,match_key FROM community_matches WHERE match_key LIKE ? AND source_url LIKE 'https://www.youtube.com/watch?v=%' ORDER BY match_key LIMIT 2000",[$season.'%']);return $q->fetchAll(PDO::FETCH_ASSOC);
 }
 public function start(array $computer,array $p):array {
  $season=(int)($p['season']??0);$game=community_learning_games()[(string)$season]??null;if(!$game||empty($game['configured']))throw new CommunityAnalysisError(422,'Choose a configured game.');
  $match=(new CommunityVideoTags($this->db))->match((int)($p['match_id']??0));if(!str_starts_with($match['match_key'],(string)$season))throw new CommunityAnalysisError(422,'Choose a match in this game.');
  $rows=$this->q("SELECT id,model_json FROM community_learning_jobs WHERE season=? AND state='candidate' AND model_json IS NOT NULL ORDER BY created_at DESC,id DESC LIMIT 30",[$season]);$model=null;$modelJob=null;
  foreach($rows as $row){$candidate=json_decode($row['model_json'],true);if(!empty($candidate['scoring_head'])&&community_score_model_valid($candidate['scoring_head'])){$model=$candidate;$modelJob=$row['id'];break;}}
  if(!$model)throw new CommunityAnalysisError(422,'Train with Trainer v1.4 first. A usable scoring candidate needs at least two robot score targets and identifiable full-match footage.');
  community_require_game_action_model_helper($model,json_decode($computer['hardware_json']??'{}',true)??[]);
  $totals=community_scoring_alliance_totals($match,$this->scoreFetch);$id=bin2hex(random_bytes(16));$lease=bin2hex(random_bytes(32));$now=time();
  $request=['kind'=>'scouting_trial','match_id'=>(int)$match['id'],'match_key'=>$match['match_key'],'title'=>$match['title'],'roster'=>$match['roster'],'source_url'=>$match['source_url'],'model_job'=>$modelJob,'model_id'=>$model['model_id'],'alliance_scores'=>$totals?['red'=>$totals['red'],'blue'=>$totals['blue']]:[]];
  $this->db->beginTransaction();try{
   $live=$this->q("SELECT model_json FROM community_learning_jobs WHERE id=? AND season=? AND state='candidate'".($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$modelJob,$season])->fetchColumn();if(!$live||(json_decode($live,true)['model_id']??null)!==$model['model_id'])throw new CommunityAnalysisError(409,'This model was deleted or changed. Choose another saved model.');
   $this->q("INSERT INTO community_learning_jobs(id,season,state,created_by,created_at,started_at,heartbeat_at,message,snapshot_ndjson,model_json) VALUES(?,?,'test_running',?,?,?,?,'Testing robot tags and scoring estimates',?,?)",[$id,$season,$computer['user_id'],$now,$now,$now,json_encode($request,JSON_THROW_ON_ERROR),community_model_document($model)]);
   $this->q('INSERT INTO community_training_leases(job_id,computer_id,lease_hash,expires_at,game_json) VALUES(?,?,?,?,?)',[$id,$computer['id'],hash('sha256',$lease),$now+600,json_encode($game,JSON_THROW_ON_ERROR)]);$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  return ['id'=>$id,'lease'=>$lease,'season'=>$season,'game_pack'=>$game,'model'=>$model,'request'=>$request];
 }
 private function work(array $computer,array $p):array{
  $r=$this->q('SELECT j.*,l.computer_id,l.lease_hash,l.expires_at FROM community_learning_jobs j JOIN community_training_leases l ON l.job_id=j.id WHERE j.id=?'.($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[(string)($p['job_id']??'')])->fetch(PDO::FETCH_ASSOC);
  if(!$r||$r['state']!=='test_running'||$r['computer_id']!==$computer['id']||(int)$r['expires_at']<=time()||!hash_equals($r['lease_hash'],hash('sha256',(string)($p['lease']??''))))throw new CommunityAnalysisError(409,'This test connection expired. The local result is retained. Start another test.');return $r;
 }
 public function heartbeat(array $computer,array $p):array{$r=$this->work($computer,$p);$this->q('UPDATE community_training_leases SET expires_at=? WHERE job_id=?',[time()+600,$r['id']]);community_training_progress_write($this->db,$r['id'],(string)($p['message']??'Testing robot video'));return ['ok'=>true];}
 public function finish(array $computer,array $p):array{
  $this->db->beginTransaction();try{$out=$this->finishLocked($computer,$p);$this->db->commit();return $out;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 private function finishLocked(array $computer,array $p):array{
  $r=$this->work($computer,$p);$request=json_decode($r['snapshot_ndjson'],true);$data=$p['result']??null;
  if(isset($p['error'])){$this->q("UPDATE community_learning_jobs SET state='test_failed',finished_at=?,message=? WHERE id=?",[time(),mb_substr((string)$p['error'],0,900),$r['id']]);return ['ok'=>true,'state'=>'test_failed'];}
  if(!is_array($data)||($data['model_id']??'')!==$request['model_id']||!preg_match('/^[a-f0-9]{64}$/D',(string)($data['source_sha256']??''))||!is_numeric($data['duration_s']??null)||$data['duration_s']<1||$data['duration_s']>600||!is_array($data['scores']??null)||count($data['scores'])!==6||!is_array($data['tags']??null)||count($data['tags'])>1000)throw new CommunityAnalysisError(422,'Invalid scouting test result.');
  $roster=$request['roster'];$seen=[];$scores=[];$sums=['red'=>0,'blue'=>0];
  foreach($data['scores'] as $score){$team=$score['team']??null;$alliance=in_array($team,$roster['red'],true)?'red':(in_array($team,$roster['blue'],true)?'blue':null);if(!$alliance||isset($seen[$team])||!in_array($score['status']??'',['unknown','predicted'],true))throw new CommunityAnalysisError(422,'Score result has an invalid or repeated robot.');$seen[$team]=true;$points=$score['robot_points']??null;$share=$score['share_percent']??null;$total=$request['alliance_scores'][$alliance]??null;
   if($score['status']==='predicted'){
    if(!is_int($points)||$points<0||$points>100000||($total===null&&$share!==null)||($total!==null&&(!is_int($total)||$points>$total))||($total>0&&(!is_numeric($share)||!is_finite((float)$share)||abs($share-100*$points/$total)>.02))||($total===0&&$share!==null))throw new CommunityAnalysisError(422,'Predicted points and alliance percentage disagree.');$sums[$alliance]+=$points;
   }elseif($points!==null||$share!==null)throw new CommunityAnalysisError(422,'Unknown scoring must keep null points and percentage.');
   $scores[]=['team'=>$team,'alliance'=>$alliance,'status'=>$score['status'],'robot_points'=>$points,'share_percent'=>$share,'certainty'=>'uncertain','evidence_type'=>'model_score_prediction','reason'=>mb_substr((string)($score['reason']??''),0,150),'alliance_score'=>$total,'score_basis'=>'full_final_alliance_score','observed_points'=>null,'alliance_constraint_applied'=>false];
  }
  foreach($sums as $a=>$sum)if(isset($request['alliance_scores'][$a])&&$sum>$request['alliance_scores'][$a])throw new CommunityAnalysisError(422,'Predicted robot points exceed the alliance score.');
  $model=json_decode($r['model_json'],true);
  $tagKeys=array_unique(array_merge(array_keys(community_video_tag_catalog()),array_keys($model['heads']??[]),['disabled']));
  $tags=[];foreach($data['tags'] as $tag){if(!in_array($tag['team']??null,array_merge($roster['red'],$roster['blue']),true)||!in_array($tag['tag_key']??'',$tagKeys,true)||!is_int($tag['start_ms']??null)||$tag['start_ms']<0||$tag['start_ms']>$data['duration_s']*1000)throw new CommunityAnalysisError(422,'Invalid predicted action tag.');$tags[]=['team'=>$tag['team'],'tag_key'=>$tag['tag_key'],'start_ms'=>$tag['start_ms'],'certainty'=>'uncertain','evidence_type'=>'model_prediction'];if(isset($model['action_definitions'][$tag['tag_key']])){$definition=$model['action_definitions'][$tag['tag_key']];$i=array_key_last($tags);$tags[$i]+=['schema_version'=>2,'game_key'=>$definition['game_key'],'action_code'=>$definition['action_code'],'label_scope'=>'moment','time_basis'=>'video_elapsed_ms','end_ms'=>$tag['start_ms'],'definition_variants'=>$definition['variants']];if(count($definition['variants'])===1)$tags[$i]+=$definition['variants'][0];}}
  foreach($data['tags'] as $j=>$source){if(($source['tag_key']??'')==='disabled'&&($source['rule']??'')==='teleop_stationary_over_5_seconds_v1'&&is_int($source['end_ms']??null)&&$source['end_ms']>$source['start_ms']+5000&&$source['end_ms']<=$data['duration_s']*1000){$tags[$j]['end_ms']=$source['end_ms'];$tags[$j]['rule']='teleop_stationary_over_5_seconds_v1';$tags[$j]['assessment']='owner_defined_stationary_rule';}}
  $scores=community_recorded_contributions($scores,$tags);
  $model=json_decode($r['model_json'],true);$overlap=in_array($data['source_sha256'],$model['scoring_head']['training_sources']??[],true);
  foreach($model['heads']??[] as $head)if(in_array($data['source_sha256'],$head['training_sources']??[],true))$overlap=true;
  $diagnostics=[];$raw=$data['diagnostics']??[];if(is_array($raw)){if(is_int($raw['action_heads_available']??null)&&$raw['action_heads_available']>=0&&$raw['action_heads_available']<=count($tagKeys))$diagnostics['action_heads_available']=$raw['action_heads_available'];if(is_bool($raw['scoring_video_validated']??null))$diagnostics['scoring_video_validated']=$raw['scoring_video_validated'];if(is_string($raw['engine']??null)&&preg_match('/^[a-zA-Z0-9.\-]{1,40}$/D',$raw['engine']))$diagnostics['engine']=$raw['engine'];$diagnostics['blocked_action_heads']=array_values(array_intersect($tagKeys,is_array($raw['blocked_action_heads']??null)?array_filter($raw['blocked_action_heads'],'is_string'):[]));foreach($raw['identity_coverage']??[] as $team=>$coverage)if(in_array((int)$team,array_merge($roster['red'],$roster['blue']),true)&&is_numeric($coverage)&&is_finite((float)$coverage)&&$coverage>=0&&$coverage<=1)$diagnostics['identity_coverage'][(string)$team]=(float)$coverage;}
  $report=['diagnostics'=>$diagnostics,'kind'=>'scouting_trial','match_id'=>$request['match_id'],'match_key'=>$request['match_key'],'title'=>$request['title'],'source_url'=>$request['source_url'],'model_id'=>$request['model_id'],'scores'=>$scores,'tags'=>$tags,'source_sha256'=>$data['source_sha256'],'duration_s'=>$data['duration_s'],'training_source_overlap'=>$overlap,'accuracy'=>null,'release_ready'=>false];
  $this->q("UPDATE community_learning_jobs SET state='test_complete',finished_at=?,heartbeat_at=?,report_json=?,message='Experimental robot tags and scoring estimates saved. These are not human labels or measured accuracy.' WHERE id=?",[time(),time(),json_encode($report,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),$r['id']]);return ['ok'=>true,'state'=>'test_complete','report_url'=>'/autoscout/train.php?season='.$r['season'].'#scoring-trials'];
 }
}
