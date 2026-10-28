<?php
declare(strict_types=1);
require_once __DIR__.'/community-game-actions.php';
require_once __DIR__.'/community-video-tags.php';
require_once __DIR__.'/community-score-model.php';
require_once __DIR__.'/community-external-scouting.php';

function community_learning_schema(PDO $db):void {
 neptune_migration_apply($db,'community.learning.server.v1','Neptune_Server_Learning_v6.zip',function()use($db):void {
  $db->exec("CREATE TABLE IF NOT EXISTS community_learning_jobs (id CHAR(32) PRIMARY KEY,season INT NOT NULL,state VARCHAR(24) NOT NULL,created_by BIGINT NOT NULL,created_at BIGINT NOT NULL,started_at BIGINT NULL,finished_at BIGINT NULL,heartbeat_at BIGINT NULL,message VARCHAR(1000) NOT NULL DEFAULT '',snapshot_sha256 CHAR(64) NULL,snapshot_ndjson LONGTEXT NULL,report_json LONGTEXT NULL,model_json LONGTEXT NULL,benchmark_json LONGTEXT NULL,KEY season_jobs(season,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->exec("CREATE TABLE IF NOT EXISTS community_learning_releases (season INT PRIMARY KEY,job_id CHAR(32) NOT NULL,released_by BIGINT NOT NULL,released_at BIGINT NOT NULL,FOREIGN KEY(job_id) REFERENCES community_learning_jobs(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 });
}
function community_learning_games():array {return json_decode((string)file_get_contents(__DIR__.'/community-learning/season_rules.json'),true,64,JSON_THROW_ON_ERROR)['seasons'];}
function community_learning_export(PDO $db):string {
 $rows=(new CommunityVideoTags($db))->training();$text='';
 while($r=$rows->fetch(PDO::FETCH_ASSOC)) {
  $r=community_scoring_decode($r);
  $r['schema_version']=2;$r['season']=(int)substr($r['match_key'],0,4);$r['game_key']=community_learning_games()[(string)$r['season']]['game_key']??null;
  $r['evidence_type']='human_video_label';$r['review_required']=false;$r['time_basis']='video_elapsed_ms';$r['source_sha256']=null;
  $r['roster']=json_decode($r['roster_json'],true);unset($r['roster_json']);
  foreach(['match_id','team_number','start_ms','end_ms','video_duration_ms','created_at'] as $k)if($r[$k]!==null)$r[$k]=(int)$r[$k];
  $text.=json_encode($r,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
 }
 if(community_external_scouting_available($db))foreach((new CommunityExternalScouting($db))->training(2026) as $external)$text.=json_encode($external,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
 return $text;
}
function community_learning_queue(PDO $db,int $season,int $user):string {
 $pack=community_learning_games()[(string)$season]??[];
 if(!$pack||!($pack['configured']??true))throw new InvalidArgumentException('This game has not been configured yet.');
 // The database lock serializes double clicks, including requests from different owners.
 $q=$db->query("SELECT GET_LOCK('community-learning-queue',3)");if(!(int)$q->fetchColumn())throw new RuntimeException('Training queue busy. Retry shortly.');
 try {
  $q=$db->prepare("SELECT id FROM community_learning_jobs WHERE season=? AND state IN ('queued','running','remote_queued','remote_running') ORDER BY created_at LIMIT 1");$q->execute([$season]);$id=$q->fetchColumn();if($id)return (string)$id;
  $id=bin2hex(random_bytes(16));$q=$db->prepare("INSERT INTO community_learning_jobs(id,season,state,created_by,created_at,message) VALUES(?,?,'queued',?,?,'Waiting for the server worker')");$q->execute([$id,$season,$user,time()]);return $id;
 }finally{$db->query("SELECT RELEASE_LOCK('community-learning-queue')");}
}
function community_learning_start():bool {
 $alive=dirname(__DIR__).'/maintenance-uploads/community-learning-work/worker-alive.json';
 if(!is_file($alive)||filemtime($alive)<=time()-90)return false;
 $state=json_decode((string)file_get_contents($alive),true);return !empty($state['managed']);
}

function community_learning_release_valid(PDO $db,array $model,array $benchmark):bool {
 if((empty($model['heads'])&&empty($model['scoring_head']))||empty($model['release_ready'])||empty($benchmark['release_ready'])||($model['model_id']??'')!==($benchmark['model_id']??null))return false;
 $ids=$model['used_label_ids']??[];if(!$ids)return false;
 if(!empty($model['scoring_head'])&&(!community_score_model_valid($model['scoring_head'])||!community_score_benchmark_pass($benchmark['scoring']??null)))return false;
 $scoreIds=array_fill_keys($model['scoring_head']['used_label_ids']??[],true);
 $q=$db->prepare("SELECT l.* FROM community_video_labels l JOIN community_matches m ON m.id=l.match_id WHERE l.id=? AND l.source_url=m.source_url AND l.review_state IN ('contributed','verified')");
 $external=[];if(community_external_scouting_available($db))foreach((new CommunityExternalScouting($db))->training((int)substr((string)($model['game_key']??''),0,4)) as $label)$external[$label['id']]=$label;
 foreach($ids as $id){if(isset($external[$id])){if(isset($scoreIds[$id])&&!community_import_score_target($external[$id]))return false;continue;}$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return false;$row=community_scoring_decode($row);if(isset($scoreIds[$id])?!community_score_target($row):$row['certainty']!=='observed')return false;}
 return true;
}
function community_learning_public(PDO $db,int $season):array {
 $pack=community_learning_games()[(string)$season]??null;
 $out=['schema_version'=>1,'season'=>$season,'game_pack'=>$pack,'model'=>null,'benchmark'=>null,'status'=>'No evaluated model is released for this game yet.'];
 $q=$db->prepare('SELECT j.model_json,j.benchmark_json FROM community_learning_releases r JOIN community_learning_jobs j ON j.id=r.job_id WHERE r.season=?');$q->execute([$season]);$r=$q->fetch(PDO::FETCH_ASSOC);
 if($r){$model=json_decode($r['model_json'],true);$bench=json_decode($r['benchmark_json'],true);
  if(is_array($model)&&is_array($bench)&&community_learning_release_valid($db,$model,$bench)){$out['model']=$model;$out['benchmark']=['model_id'=>$bench['model_id'],'release_ready'=>true,'overall'=>$bench['overall']??[]];$out['status']='Evaluated model released by Neptune.';}
  else $out['status']='The previous release is unavailable or uses a withdrawn label. Facts reading remains available.';
 }
 return $out;
}

/** Positive-label preflight; video localization and model evaluation remain separate.
 * Counts never certify model accuracy.
 */
function community_learning_baseline_preflight(string $snapshot,int $season):array {
 $positive=[];$negative=[];$actions=0;$estimates=0;$scoreRobots=[];$matches=[];$rejectedGameLabels=[];
 $absence=['no_scoring'=>['scoring'],'no_defense'=>['attempted_defense'],'auto_no_attempt'=>['auto_partial','auto_completed','auto_failed'],'end_no_attempt'=>['end_partial','end_completed','end_failed']];
 foreach(explode("\n",$snapshot) as $line){
  if($line==='')continue;$row=json_decode($line,true);
  if(is_array($row)&&str_starts_with((string)($row['tag_key']??''),'gb:')&&(!community_game_action_row($row,$season)||($row['game_key']??'')!==(community_learning_games()[(string)$season]['game_key']??null))){if(count($rejectedGameLabels)<200)$rejectedGameLabels[]=['label_id'=>$row['id']??null,'tag_key'=>$row['tag_key'],'reason'=>'Invalid Game Builder contract, game, definition revision, or moment timing.'];continue;}
  if(!is_array($row)||(int)($row['season']??substr($row['match_key']??'',0,4))!==$season||(!community_game_action_row($row,$season)&&!in_array(($row['definition_version']??''),['neptune-match-tags-v1',CommunityExternalScouting::ADAPTER,'mapped-actions-v1','viper-2026-points-v1'],true)))continue;
  if(($row['scoring']['mode']??'')==='alliance_share'){$estimates++;if(community_score_target($row)||community_import_score_target($row))$scoreRobots[($row['video_id']??$row['match_key']).':'.($row['team_number']??'')]=true;continue;}
  if(($row['certainty']??'')!=='observed')continue;
  $tag=$row['tag_key']??'';if(!isset(community_video_tag_catalog()[$tag])&&!community_game_action_row($row,$season))continue;
  $matches[$row['match_key']]=true;
  if(($row['scoring']['mode']??'')==='alliance_share'){$estimates++;continue;}
  if(($row['polarity']??'present')==='absent'){$negative[$tag]=($negative[$tag]??0)+1;continue;}
  $actions++;
  if(isset($absence[$tag])){foreach($absence[$tag] as $target)$negative[$target]=($negative[$target]??0)+1;}
  elseif(($row['label_scope']??'')!=='match')$positive[$tag]=($positive[$tag]??0)+1;
 }
 $eligible=[];foreach($positive as $tag=>$count)if($count>=2)$eligible[]=$tag;
 return ['can_run_baseline'=>(bool)$eligible||count($scoreRobots)>=2,'rejected_game_labels'=>$rejectedGameLabels,'usable_scoring_targets'=>count($scoreRobots),'learning_method'=>'positive-unlabeled-bagging-v1','actions'=>$actions,'scoring_estimates'=>$estimates,'matches'=>count($matches),
 'message'=>'Your tags are saved. Preparation needs two observed action examples or two robots with saved whole-match score estimates. Keep tagging normally; counterexamples are not required. Video verification and robot identification determine which examples can actually be used.'];
}
