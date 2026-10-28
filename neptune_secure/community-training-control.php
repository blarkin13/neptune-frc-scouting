<?php
declare(strict_types=1);
require_once __DIR__.'/community-model-json.php';
require_once __DIR__.'/community-scouting-trials.php';
require_once __DIR__.'/community-model-management.php';

/** Browser-owned training commands. Source labels are never changed by this service. */
function community_training_control_schema(PDO $db):void {
 community_learning_schema($db);community_trainers_schema($db);community_external_scouting_schema($db);
 neptune_migration_apply($db,'community.learning.page-control.v1','Neptune_Training_Control_v1_5.zip',function()use($db):void{
  if(!neptune_platform_column_exists($db,'community_learning_jobs','control_json'))$db->exec('ALTER TABLE community_learning_jobs ADD COLUMN control_json LONGTEXT NULL');
 });
}
function community_training_state_name(string $state):string {
 return ['remote_queued'=>'Waiting for the helper','remote_running'=>'Training on the helper','remote_paused'=>'Paused — progress saved','queued'=>'Waiting for Neptune','running'=>'Training on Neptune','server_paused'=>'Paused — progress saved','test_queued'=>'Waiting to test the match','test_running'=>'Testing the match','test_complete'=>'Match predictions saved','test_failed'=>'Match test stopped','evaluation_queued'=>'Waiting to evaluate','evaluation_running'=>'Evaluating on separate matches','evaluation_complete'=>'Evaluation finished','candidate'=>'Done — model saved','needs_data'=>'No model is ready — see the evidence report','failed'=>'Needs attention','cancelled'=>'Cancelled','model_deleted'=>'Model deleted — report kept'][$state]??$state;
}
function community_training_order(array $dataset,string $seed):array {
 if(!preg_match('/^[a-f0-9]{32}$/D',$seed))throw new CommunityAnalysisError(422,'Refresh the training selection and try again.');
 $dataset=array_values(array_filter($dataset,fn($row)=>$row['key']!=='2026txcmp1_qm7'));
 usort($dataset,fn($a,$b)=>strcmp(hash('sha256',$seed.'|'.$a['key']),hash('sha256',$seed.'|'.$b['key']))?:strcmp($a['key'],$b['key']));
 return $dataset;
}
/** Human-labelled matches form one pool; imported-only matches form the other.
 * Mixed-source matches count once in the human pool; every label still exports.
 */
function community_training_count(mixed $value):int {
 if(!is_scalar($value)||!preg_match('/^(0|[1-9][0-9]*)$/D',(string)$value)||strlen((string)$value)>6)throw new CommunityAnalysisError(422,'Match counts must be whole numbers, zero or greater.');
 return (int)$value;
}
function community_training_pins(mixed $value):array {
 if(!is_string($value))throw new CommunityAnalysisError(422,'Choose human-tagged matches from the list.');
 try{$pins=json_decode($value,true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new CommunityAnalysisError(422,'Choose human-tagged matches from the list.');}
 if(!is_array($pins)||!array_is_list($pins)||count($pins)>1000)throw new CommunityAnalysisError(422,'Choose human-tagged matches from the list.');
 foreach($pins as $pin)if(!is_string($pin)||strlen($pin)>100)throw new CommunityAnalysisError(422,'Choose human-tagged matches from the list.');
 return $pins;
}
function community_training_is_human(array $row):bool {
 return (int)($row['tags']??0)>0||(int)($row['human_scores']??0)>0;
}
function community_training_select(array $dataset,string $seed,int $total,?int $human=null,?int $scouted=null,array $pinned=[]):array {
 $ranked=community_training_order($dataset,$seed);
 if($total<1||$total>count($ranked))throw new CommunityAnalysisError(422,'Choose a total between 1 and '.count($ranked).' available training matches.');
 if($human===null&&$scouted===null&&!$pinned)return array_slice($ranked,0,$total);
 if($human===null||$scouted===null||$human<0||$scouted<0||$human+$scouted!==$total)throw new CommunityAnalysisError(422,'Human-tagged matches plus imported scouting matches must equal the total.');
 $h=array_values(array_filter($ranked,'community_training_is_human'));$i=array_values(array_filter($ranked,fn($m)=>!community_training_is_human($m)));
 if($human>count($h)||$scouted>count($i))throw new CommunityAnalysisError(422,'Not enough matches for this mix. Available: '.count($h).' human-tagged and '.count($i).' imported-only scouting matches.');
 if(count($pinned)!==count(array_unique($pinned))||count($pinned)>$human||array_diff($pinned,array_column($h,'key')))throw new CommunityAnalysisError(422,'Choose available human-tagged matches to include, within the human-tagged count.');
 $chosen=array_values(array_filter($h,fn($m)=>in_array($m['key'],$pinned,true)));
 foreach($h as $m)if(count($chosen)<$human&&!in_array($m['key'],$pinned,true))$chosen[]=$m;
 $ids=array_column(array_merge($chosen,array_slice($i,0,$scouted)),'id');
 return array_values(array_filter($ranked,fn($m)=>in_array($m['id'],$ids,true)));
}
function community_training_engine_ready(array $hardware):bool {
 $version=explode('-',(string)($hardware['engine_version']??''),2)[0];
 return preg_match('/^\d+\.\d+\.\d+$/D',$version)&&version_compare($version,'2.2.1','>=');
}
final class CommunityTrainingControl {
 public function __construct(private PDO $db,private mixed $scoreFetch=null){community_training_progress_schema($db);}
 private function q(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 private function lock():string{return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
 private function tx(callable $fn):mixed{$mysql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';if($mysql&&!(int)$this->db->query("SELECT GET_LOCK('community-learning-queue',3)")->fetchColumn())throw new CommunityAnalysisError(409,'Neptune is updating this run. Try again.');try{$this->db->beginTransaction();try{$out=$fn();$this->db->commit();return $out;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}}finally{if($mysql)$this->db->query("SELECT RELEASE_LOCK('community-learning-queue')");}}
 private function decode(array $row):array{$row=community_training_progress_overlay($this->db,$row);return $row['control_json']?json_decode($row['control_json'],true,128,JSON_THROW_ON_ERROR):[];}
 private function setControl(string $id,array $control):void{$this->q('UPDATE community_learning_jobs SET control_json=? WHERE id=?',[json_encode($control,JSON_THROW_ON_ERROR),$id]);}
 public function dataset(int $season):array {
  $rows=[];foreach(explode("\n",community_learning_export($this->db)) as $line){if(!$line)continue;$r=json_decode($line,true);if(($r['match_key']??'')==='2026txcmp1_qm7')continue;if((int)$r['season']!==$season)continue;$id=(int)$r['match_id'];$rows[$id]??=['id'=>$id,'key'=>$r['match_key'],'tags'=>0,'scores'=>0,'external'=>0,'human_scores'=>0,'imported_scores'=>0];$rows[$id][($r['scoring']['mode']??'')==='alliance_share'?'scores':(($r['evidence_type']??'')==='external_scouting_label'?'external':'tags')]++;if(($r['scoring']['mode']??'')==='alliance_share')$rows[$id][($r['evidence_type']??'')==='external_scouting_label'?'imported_scores':'human_scores']++;}
  uasort($rows,fn($a,$b)=>strnatcmp($a['key'],$b['key']));return array_values($rows);
 }
 public function computers(int $user):array {
  $q=$this->q('SELECT id,name,expires_at,revoked,last_seen,hardware_json FROM community_training_computers WHERE user_id=? ORDER BY last_seen DESC,created_at DESC',[$user]);$out=[];
  foreach($q as $r){$h=$r['hardware_json']?json_decode($r['hardware_json'],true):[];$r['online']=!$r['revoked']&&(int)$r['expires_at']>time()&&(int)$r['last_seen']>time()-75;$r['ready']=$r['online']&&in_array(($h['training_protocol']??''),['page-v1','page-v2'],true)&&($h['robot_tracking']??'')==='yolo-botsort-1'&&($h['action_features']??'')==='robot-r3d18-clip-v1'&&community_training_engine_ready($h);$r['status']=$r['revoked']?'Disconnected':((int)$r['expires_at']<=time()?'Connection expired':(!$r['online']?'Open the helper':(!$r['ready']?(($h['training_protocol']??'')==='page-setup-v1'?'Connected — checking tools':'Open the updated Video Actions helper'):'Connected')));$r['protocol']=$h['training_protocol']??'';$r['device']=$h['name']??'Computer';$r['robot_tracking']=$h['robot_tracking']??null;$r['engine_version']=$h['engine_version']??null;$r['engine_sha256']=$h['engine_sha256']??null;$r['imported_scores']=in_array('viper-2026-points-v1',$h['outside_adapters']??[],true);$r['game_actions']=in_array('game-builder-moments-v1',$h['label_contracts']??[],true);$r['mapped_imports']=in_array('mapped-actions-v1',$h['outside_adapters']??[],true);unset($r['hardware_json']);$out[]=$r;}return $out;
 }
 private function executor(string $executor,?string $computer,int $user):array {
  if(!in_array($executor,['helper','server'],true))throw new CommunityAnalysisError(422,'Choose a helper computer or Neptune.');
  if($executor==='server'){if(is_file(dirname(__DIR__).'/maintenance-uploads/community-server-disabled.json'))throw new CommunityAnalysisError(409,'Training runs on helper computers. Server training is turned off.');return ['executor'=>'server','computer_id'=>null];}
  foreach($this->computers($user) as $r)if($r['id']===$computer&&$r['ready'])return ['executor'=>'helper','computer_id'=>$computer];
  throw new CommunityAnalysisError(409,'Open Training Helper v2.2.1 and wait for Connected, then try again.');
 }
 private function active(int $season,bool $includePaused=true):?array{$r=$this->q("SELECT * FROM community_learning_jobs WHERE season=? AND state IN ('remote_queued','remote_running','remote_paused','queued','running','server_paused','test_queued','test_running','evaluation_queued','evaluation_running')".($includePaused?'':" AND state NOT IN ('remote_paused','server_paused')")." ORDER BY created_at,id LIMIT 1",[$season])->fetch(PDO::FETCH_ASSOC);return $r?:null;}
 public function queue(int $season,int $user,string $executor,?string $computer,int $limit,string $seed='',?int $human=null,?int $scouted=null,array $pinned=[]):array {
  $pack=community_learning_games()[(string)$season]??null;if(!$pack||empty($pack['configured']))throw new CommunityAnalysisError(422,'Choose a configured game.');$where=$this->executor($executor,$computer,$user);
  if($existing=$this->active($season))return ['id'=>$existing['id'],'existing'=>true];
  $dataset=$this->dataset($season);$limit=$limit===0?count($dataset):$limit;if($limit<1||$limit>count($dataset))throw new CommunityAnalysisError(422,'Choose between 1 and '.count($dataset).' tagged matches.');
  $seed=$seed!==''?$seed:bin2hex(random_bytes(16));
  $selected=community_training_select($dataset,$seed,$limit,$human,$scouted,$pinned);$ids=array_column($selected,'id');$lines=[];
  foreach(explode("\n",community_learning_export($this->db)) as $line){$r=$line?json_decode($line,true):null;if($r&&in_array((int)$r['match_id'],$ids,true))$lines[]=$line;}$rank=array_flip(array_column($selected,'id'));usort($lines,fn($a,$b)=>$rank[(int)json_decode($a,true)['match_id']]<=>$rank[(int)json_decode($b,true)['match_id']]);$snapshot=implode("\n",$lines)."\n";
  if($executor==='helper'&&community_snapshot_has_game_actions($snapshot)){foreach($this->computers($user) as $device)if($device['id']===$computer&&!$device['game_actions'])throw new CommunityAnalysisError(422,'Update this helper to v3.0.2 to use Game Builder action labels. Your saved work is kept.');}
  if($executor==='helper'&&str_contains($snapshot,'external_scouting_label')){foreach($this->computers($user) as $device)if($device['id']===$computer&&$device['protocol']!=='page-v2')throw new CommunityAnalysisError(422,'Update this helper to v1.6 to include outside scouting. Its connection and cached videos can be reused.');}
  if($executor==='helper'&&str_contains($snapshot,'mapped-actions-v1')){foreach($this->computers($user) as $device)if($device['id']===$computer&&!$device['mapped_imports'])throw new CommunityAnalysisError(422,'Download the updated training helper to use mapped CSV/JSON imports. Your saved work can be reused.');}
  if($executor==='helper'&&str_contains($snapshot,'viper-2026-points-v1')){foreach($this->computers($user) as $device)if($device['id']===$computer&&!$device['imported_scores'])throw new CommunityAnalysisError(422,'Download the updated training helper to learn imported robot points. Saved videos can be reused.');}
  if(strlen(json_encode(['snapshot'=>$snapshot],JSON_THROW_ON_ERROR))>31*1024*1024)throw new CommunityAnalysisError(422,'This snapshot is too large for one helper transfer. Choose a smaller match batch; the source records are kept.');
  $check=community_learning_baseline_preflight($snapshot,$season);if(!$check['can_run_baseline'])throw new CommunityAnalysisError(422,$check['message']);
  return $this->tx(function()use($season,$user,$where,$selected,$snapshot,$seed,$human,$scouted,$pinned):array{
   if($existing=$this->active($season))return ['id'=>$existing['id'],'existing'=>true];
   $id=bin2hex(random_bytes(16));$control=$where+['version'=>1,'kind'=>'training','selection_seed'=>$seed,'selection_method'=>$human===null?'sha256-permutation-v1':'source-quota-sha256-v1','source_counts'=>['human_tagged'=>count(array_filter($selected,'community_training_is_human')),'imported_scouting'=>count(array_filter($selected,fn($m)=>!community_training_is_human($m)))],'pinned_human_matches'=>$pinned,'matches'=>$selected,'matches_total'=>count($selected),'matches_done'=>0,'stop_requested'=>false,'run_requested'=>true];
   $this->q('INSERT INTO community_learning_jobs(id,season,state,created_by,created_at,message,snapshot_sha256,snapshot_ndjson,control_json) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$season,$where['executor']==='helper'?'remote_queued':'queued',$user,time(),'Waiting to prepare '.count($selected).' training matches',hash('sha256',$snapshot),$snapshot,json_encode($control,JSON_THROW_ON_ERROR)]);
   return ['id'=>$id,'existing'=>false];
  });
 }
 public function resume(string $id,int $user,string $executor,?string $computer):array {
  $where=$this->executor($executor,$computer,$user);
  return $this->tx(function()use($id,$user,$where):array{
   $r=$this->q('SELECT * FROM community_learning_jobs WHERE id=?'.$this->lock(),[$id])->fetch(PDO::FETCH_ASSOC);
   if(!$r||(int)$r['created_by']!==$user||!in_array($r['state'],['remote_paused','server_paused'],true))throw new CommunityAnalysisError(409,'This training batch is no longer paused. Refresh the page.');
   $old=$this->decode($r);
   $control=array_replace($old,$where,['version'=>1,'kind'=>'training','stop_requested'=>false,'run_requested'=>true]);$this->setControl($id,$control);$this->q('UPDATE community_learning_jobs SET state=?,message=? WHERE id=?',[$where['executor']==='helper'?'remote_queued':'queued','Ready to resume saved match preparation',$id]);return ['id'=>$id];
  });
 }
 public function stop(string $id,int $user):array {
  return $this->tx(function()use($id,$user):array{$r=$this->q('SELECT * FROM community_learning_jobs WHERE id=?'.$this->lock(),[$id])->fetch(PDO::FETCH_ASSOC);if(!$r||(int)$r['created_by']!==$user)throw new CommunityAnalysisError(404,'Training run not found.');$c=$this->decode($r);if(!$c)throw new CommunityAnalysisError(409,'This older run must be stopped in its trainer window. Resume it here with v1.5 to use page controls.');$c['stop_requested']=true;$c['run_requested']=false;$this->setControl($id,$c);
   if(in_array($r['state'],['remote_queued','queued','test_queued','evaluation_queued'],true)){$state=$c['kind']==='training'?($c['executor']==='server'?'server_paused':'remote_paused'):'cancelled';$this->q('UPDATE community_learning_jobs SET state=?,message=? WHERE id=?',[$state,'Stopped before starting. Saved files and human labels are kept.',$id]);}
   return ['id'=>$id];});
 }
 public function claim(array $computer,array $hardware):array {
  $hardware=community_training_hardware($hardware);$hardware['training_protocol']=($hardware['training_protocol']??'')==='page-v2'?'page-v2':'page-v1';
  $this->q('UPDATE community_training_computers SET last_seen=?,hardware_json=? WHERE id=?',[time(),json_encode($hardware,JSON_THROW_ON_ERROR),$computer['id']]);
  return $this->tx(function()use($computer,$hardware):array{
   $q=$this->q("SELECT * FROM community_learning_jobs WHERE created_by=? AND control_json IS NOT NULL AND (state IN ('remote_queued','test_queued','evaluation_queued') OR (state IN ('remote_running','test_running','evaluation_running') AND ".community_training_progress_heartbeat_sql()."<?)) ORDER BY created_at,id".$this->lock(),[$computer['user_id'],time()-600]);
   foreach($q as $r){$c=$this->decode($r);if($c['executor']!=='helper'||$c['computer_id']!==$computer['id']||empty($c['run_requested']))continue;community_require_game_action_helper($r['snapshot_ndjson'],$hardware);if($r['model_json'])community_require_game_action_model_helper(json_decode($r['model_json'],true),$hardware);if(($hardware['robot_tracking']??'')!=='yolo-botsort-1'||($hardware['action_features']??'')!=='robot-r3d18-clip-v1'||!community_training_engine_ready($hardware))throw new CommunityAnalysisError(422,'Open the updated v2.2.1 Clock & Camera training helper before starting new work. Existing saved data is kept.');if($c['kind']==='training'&&str_contains((string)$r['snapshot_ndjson'],'external_scouting_label')&&$hardware['training_protocol']!=='page-v2')throw new CommunityAnalysisError(422,'Outside scouting requires helper v1.6. Update this helper; saved work is kept.');if($c['kind']==='training'&&str_contains((string)$r['snapshot_ndjson'],'viper-2026-points-v1')&&!in_array('viper-2026-points-v1',$hardware['outside_adapters']??[],true))throw new CommunityAnalysisError(422,'This helper does not support imported robot points. Open the updated helper.');if($c['kind']==='training'&&str_contains((string)$r['snapshot_ndjson'],'mapped-actions-v1')&&!in_array('mapped-actions-v1',$hardware['outside_adapters']??[],true))throw new CommunityAnalysisError(422,'This helper does not support mapped scouting imports. Open the updated helper.');return ['job'=>$this->reserve($r,$c,$computer['id'])];}return ['job'=>null];
  });
 }
 public function reserveServer():?array {
  return $this->tx(function():?array{
   // Only one server worker is allowed by its separate MySQL advisory lock.
   foreach($this->q("SELECT * FROM community_learning_jobs WHERE control_json IS NOT NULL AND state IN ('queued','test_queued','evaluation_queued') ORDER BY created_at,id".$this->lock()) as $r){$c=$this->decode($r);if($c['executor']==='server'&&!empty($c['run_requested']))return $this->reserve($r,$c,'neptune-server');}return null;
  });
 }
 private function reserve(array $r,array $control,string $computer):array {
  $game=community_learning_games()[(string)$r['season']];$lease=bin2hex(random_bytes(32));$kind=$control['kind'];$state=$kind==='training'?($control['executor']==='server'?'running':'remote_running'):($kind==='trial'?'test_running':'evaluation_running');
  $old=$this->q('SELECT game_json FROM community_training_leases WHERE job_id=?',[$r['id']])->fetchColumn();if($old)$game=json_decode($old,true);
  $this->q('DELETE FROM community_training_leases WHERE job_id=?',[$r['id']]);$this->q('INSERT INTO community_training_leases(job_id,computer_id,lease_hash,expires_at,game_json) VALUES(?,?,?,?,?)',[$r['id'],$computer,hash('sha256',$lease),time()+600,json_encode($game,JSON_THROW_ON_ERROR)]);
  $this->q('UPDATE community_learning_jobs SET state=?,started_at=COALESCE(started_at,?),heartbeat_at=?,message=? WHERE id=?',[$state,time(),time(),'Starting '.$kind.' on '.($control['executor']==='server'?'Neptune':'the connected helper'),$r['id']]);
  $job=['id'=>$r['id'],'season'=>(int)$r['season'],'lease'=>$lease,'kind'=>$kind,'game_pack'=>$game,'control'=>$control];
  if($kind==='training')$job+=['snapshot'=>$r['snapshot_ndjson'],'snapshot_sha256'=>$r['snapshot_sha256']];else $job+=['request'=>json_decode($r['snapshot_ndjson'],true),'model'=>json_decode($r['model_json'],true)];return $job;
 }
 public function heartbeat(array $computer,array $p):array {
  return $this->tx(function()use($computer,$p):array{$r=$this->work($computer,$p);$c=$this->decode($r);
   community_training_progress_write($this->db,$r['id'],(string)($p['message']??'Working'),is_array($p['checkpoint']??null)?$p['checkpoint']:null);
   $this->q('UPDATE community_training_leases SET expires_at=? WHERE job_id=?',[time()+600,$r['id']]);$this->q('UPDATE community_training_computers SET last_seen=? WHERE id=?',[time(),$computer['id']]);return ['ok'=>true,'stop_requested'=>!empty($c['stop_requested'])];});
 }
 private function work(array $computer,array $p):array {$r=$this->q('SELECT j.*,l.computer_id,l.lease_hash,l.expires_at FROM community_learning_jobs j JOIN community_training_leases l ON l.job_id=j.id WHERE j.id=?'.$this->lock(),[(string)($p['job_id']??'')])->fetch(PDO::FETCH_ASSOC);if(!$r||$r['computer_id']!==$computer['id']||(int)$r['expires_at']<=time()||!hash_equals($r['lease_hash'],hash('sha256',(string)($p['lease']??'')))||!in_array($r['state'],['remote_running','running','test_running','evaluation_running'],true))throw new CommunityAnalysisError(409,'This run is no longer assigned to this computer. Saved work is kept.');return $r;}
 public function pauseServer(array $p):array{$r=$this->work(['id'=>'neptune-server'],$p);$this->q("UPDATE community_learning_jobs SET state='server_paused',message=? WHERE id=?",[mb_substr((string)($p['message']??'Paused after the current match. Resume from this page.'),0,900),$r['id']]);if(isset($p['message'])){$c=$this->decode($r);$c['stop_requested']=true;$this->setControl($r['id'],$c);}return ['ok'=>true,'state'=>'server_paused'];}
 public function trial(int $season,int $user,string $executor,?string $computer,int $match,string $modelId):array {
  $where=$this->executor($executor,$computer,$user);$modelRow=$this->model($season,$modelId);$m=(new CommunityVideoTags($this->db))->match($match);if(!str_starts_with($m['match_key'],(string)$season))throw new CommunityAnalysisError(422,'Choose a match from this game.');$totals=community_scoring_alliance_totals($m,$this->scoreFetch);
  $request=['kind'=>'scouting_trial','match_id'=>$match,'match_key'=>$m['match_key'],'title'=>$m['title'],'roster'=>$m['roster'],'source_url'=>$m['source_url'],'model_job'=>$modelRow['id'],'model_id'=>$modelRow['model']['model_id'],'alliance_scores'=>$totals?['red'=>$totals['red'],'blue'=>$totals['blue']]:[]];
  return $this->queueModelWork($season,$user,$where,'trial',$request,$modelRow['model']);
 }
 public function model(int $season,string $id=''):array {
  $args=[$season];$filter='';if($id!==''){$filter=' AND id=?';$args[]=$id;}
  $q=$this->q("SELECT id,model_json FROM community_learning_jobs WHERE season=? AND state='candidate' AND model_json IS NOT NULL".$filter.' ORDER BY created_at DESC,id DESC',$args);
  foreach($q as $r){$model=json_decode($r['model_json'],true);if(!empty($model['heads'])||!empty($model['scoring_head']))return ['id'=>$r['id'],'model'=>$model];}throw new CommunityAnalysisError(422,'Train a model for this game first.');
 }
 private function queueModelWork(int $season,int $user,array $where,string $kind,array $request,array $model):array {
  if($where['executor']==='helper')foreach($this->computers($user) as $device)if($device['id']===$where['computer_id'])community_require_game_action_model_helper($model,['label_contracts'=>$device['game_actions']?['game-builder-moments-v1']:[]]);
  return $this->tx(function()use($season,$user,$where,$kind,$request,$model):array{$live=$this->q("SELECT model_json FROM community_learning_jobs WHERE id=? AND season=? AND state='candidate'".$this->lock(),[$request['model_job'],$season])->fetchColumn();if(!$live||(json_decode($live,true)['model_id']??null)!==($model['model_id']??null))throw new CommunityAnalysisError(409,'This model was deleted or changed. Choose another saved model.');if($existing=$this->active($season,false))return ['id'=>$existing['id'],'existing'=>true];$id=bin2hex(random_bytes(16));$c=$where+['version'=>1,'kind'=>$kind,'run_requested'=>true,'stop_requested'=>false,'matches_done'=>0,'matches_total'=>$kind==='trial'?1:count($request['matches'])];
   $this->q('INSERT INTO community_learning_jobs(id,season,state,created_by,created_at,message,snapshot_ndjson,model_json,control_json) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$season,$kind==='trial'?'test_queued':'evaluation_queued',$user,time(),'Waiting for '.($kind==='trial'?'the selected match test':'independent evaluation'),json_encode($request,JSON_THROW_ON_ERROR),community_model_document($model),json_encode($c,JSON_THROW_ON_ERROR)]);return ['id'=>$id,'existing'=>false];});
 }
 public function evaluate(int $season,int $user,string $executor,?string $computer,string $modelId,array $reference):array {
  $where=$this->executor($executor,$computer,$user);$m=$this->model($season,$modelId);$model=$m['model'];
  if(($reference['season']??null)!==$season||($reference['game_key']??'')!==$model['game_key']||!is_array($reference['matches']??null)||!$reference['matches']||count($reference['matches'])>500||array_diff(array_keys($model['heads']),$reference['tags']??[])||array_diff($reference['tags']??[],array_keys($model['heads'])))throw new CommunityAnalysisError(422,'The reference set must match this game and all trained action tags.');
  $seen=[];foreach($reference['matches'] as &$ref){$key=(string)($ref['match_key']??'');if(isset($seen[$key])||!str_starts_with($key,(string)$season))throw new CommunityAnalysisError(422,'Use unique reference matches from this game.');$seen[$key]=true;$q=$this->q('SELECT id FROM community_matches WHERE match_key=?',[$key]);$id=$q->fetchColumn();if(!$id)throw new CommunityAnalysisError(422,'Reference match '.$key.' must be in Neptune’s match catalog.');$match=(new CommunityVideoTags($this->db))->match((int)$id);$teams=array_merge($match['roster']['red'],$match['roster']['blue']);if(($ref['roster']??null)!==$match['roster']||count($ref['complete_robot_labels']??[])!==6||array_diff(array_map('strval',$teams),array_keys($ref['complete_robot_labels']))||in_array(false,array_map(fn($v)=>$v===true,$ref['complete_robot_labels']),true)||!preg_match('/^[a-f0-9]{64}$/D',(string)($ref['source_sha256']??''))||!is_int($ref['duration_ms']??null)||$ref['duration_ms']<1000||$ref['duration_ms']>600000||!is_array($ref['events']??null)||count($ref['events'])>10000)throw new CommunityAnalysisError(422,'Each reference needs a matching roster, complete six-robot labels, video fingerprint and duration.');
   foreach($ref['events'] as $event)if(!in_array($event['team']??null,$teams,true)||!isset($model['heads'][$event['tag_key']??''])||!is_int($event['start_ms']??null)||$event['start_ms']<0||$event['start_ms']>$ref['duration_ms'])throw new CommunityAnalysisError(422,'A reference action has an invalid robot, tag or time.');
   if(!empty($model['scoring_head'])){foreach($match['roster'] as $alliance=>$roster){$total=$ref['alliance_scores'][$alliance]??null;if(!is_int($total)||$total<0||$total>100000||($ref['score_basis']??'')!=='full_final_alliance_score')throw new CommunityAnalysisError(422,'Scoring evaluation needs full final alliance totals.');$sum=0;foreach($roster as $team){$score=$ref['robot_scores'][(string)$team]??[];if(($score['method']??'')!=='counted'||($score['complete']??false)!==true||!is_int($score['robot_points']??null)||$score['robot_points']<0||$score['robot_points']>$total)throw new CommunityAnalysisError(422,'Scoring evaluation needs complete independently counted points for all six robots. Ordinary estimates are not accuracy references.');$sum+=$score['robot_points'];}if($sum>$total)throw new CommunityAnalysisError(422,'Reference robot points exceed their alliance total.');}}
   $ref['source_url']=$match['source_url'];unset($ref['video'],$ref['facts']);
  }unset($ref);$reference['model_job']=$m['id'];return $this->queueModelWork($season,$user,$where,'evaluation',$reference,$model);
 }
 public function finishEvaluation(array $computer,array $p):array {
  return $this->tx(function()use($computer,$p):array{$r=$this->work($computer,$p);$c=$this->decode($r);if($c['kind']!=='evaluation')throw new CommunityAnalysisError(422,'This is not an evaluation run.');$ref=json_decode($r['snapshot_ndjson'],true);$model=json_decode($r['model_json'],true);$bench=$p['benchmark']??null;if(!is_array($bench)||($bench['model_id']??'')!==$model['model_id']||count($bench['matches']??[])!==count($ref['matches']))throw new CommunityAnalysisError(422,'Evaluation report does not match this model or reference set.');
   $pass=!empty($bench['release_ready'])&&count($bench['matches'])>=10&&($bench['events']??0)>=2&&!empty($bench['all_six_robot_gate']);foreach($model['heads']??[] as $tag=>$head){$t=$bench['by_tag'][$tag]??[];if(($t['precision']??0)<.9||($t['recall']??0)<.8||($t['tp']??0)+($t['fn']??0)<10)$pass=false;}if(!empty($model['scoring_head'])&&!community_score_benchmark_pass($bench['scoring']??null))$pass=false;$model['release_ready']=$pass;$bench['release_ready']=$pass;
   $candidate=$this->q("SELECT model_json FROM community_learning_jobs WHERE id=? AND state='candidate'".$this->lock(),[$ref['model_job']])->fetchColumn();if(!$candidate||(json_decode($candidate,true)['model_id']??'')!==$model['model_id'])throw new CommunityAnalysisError(409,'The candidate changed while evaluation was running. Your local report is kept.');$this->q('UPDATE community_learning_jobs SET model_json=?,benchmark_json=? WHERE id=?',[community_model_document($model),json_encode($bench,JSON_THROW_ON_ERROR),$ref['model_job']]);$report=['kind'=>'evaluation','model_job'=>$ref['model_job'],'benchmark'=>$bench,'release_ready'=>$pass];$this->q("UPDATE community_learning_jobs SET state='evaluation_complete',finished_at=?,heartbeat_at=?,report_json=?,benchmark_json=?,message=? WHERE id=?",[time(),time(),json_encode($report,JSON_THROW_ON_ERROR),json_encode($bench,JSON_THROW_ON_ERROR),$pass?'Done — independent checks passed. You can review and release the model.':'Done — evaluation saved. The model has not passed release checks.',$r['id']]);return ['ok'=>true,'state'=>'evaluation_complete'];});
 }
 public function fail(array $computer,array $p):array{return $this->tx(function()use($computer,$p):array{$r=$this->work($computer,$p);$this->q("UPDATE community_learning_jobs SET state='failed',finished_at=?,message=? WHERE id=?",[time(),mb_substr((string)($p['message']??'The computer could not finish. Saved files are kept.'),0,900),$r['id']]);return ['ok'=>true,'state'=>'failed'];});}
 public function panel(int $season,int $user,?string $selected=null):array {
  $rows=$this->q('SELECT id,season,state,created_at,created_by,heartbeat_at,message,report_json,benchmark_json,control_json,snapshot_ndjson FROM community_learning_jobs WHERE season=? ORDER BY created_at DESC,id DESC LIMIT 100',[$season])->fetchAll(PDO::FETCH_ASSOC);$jobs=[];$chosen=null;$latestTraining=null;$models=[];
  foreach($rows as $r){$r=community_training_progress_overlay($this->db,$r);$c=$r['control_json']?json_decode($r['control_json'],true,128,JSON_THROW_ON_ERROR):[];$report=$r['report_json']?json_decode($r['report_json'],true):null;$bench=$r['benchmark_json']?json_decode($r['benchmark_json'],true):null;$job=['id'=>$r['id'],'state'=>$r['state'],'state_label'=>community_training_state_name($r['state']),'created_at'=>(int)$r['created_at'],'heartbeat_at'=>$r['heartbeat_at']?(int)$r['heartbeat_at']:null,'message'=>$r['message'],'control'=>$c,'report'=>$report,'benchmark'=>$bench];if(($c['kind']??'')==='trial'){$request=json_decode($r['snapshot_ndjson']??'',true);if(is_array($request)&&preg_match('~^https://www\.youtube\.com/watch\?v=[A-Za-z0-9_-]{11}$~D',(string)($request['source_url']??'')))$job['video']=array_intersect_key($request,array_flip(['match_id','match_key','title','source_url']));}$jobs[]=$job;if($r['id']===$selected)$chosen=$job;if(!$latestTraining&&!str_starts_with($r['state'],'test_')&&!str_starts_with($r['state'],'evaluation_'))$latestTraining=$job;if($r['state']==='candidate')$models[]=['id'=>$r['id'],'created_at'=>(int)$r['created_at'],'evaluated'=>!empty($bench['release_ready'])];}
  $dataset=$this->dataset($season);return ['season'=>$season,'computers'=>$this->computers($user),'dataset'=>$dataset,'counts'=>['matches'=>count($dataset),'tags'=>array_sum(array_column($dataset,'tags')),'scores'=>array_sum(array_column($dataset,'scores')),'external'=>array_sum(array_column($dataset,'external'))],'imports'=>(new CommunityExternalScouting($this->db))->imports($season),'external_problems'=>(new CommunityExternalScouting($this->db))->resolved($season)['problems'],'jobs'=>$jobs,'selected'=>$chosen??($jobs[0]??null),'latest_training'=>$latestTraining,'models'=>$models,'saved_models'=>(new CommunityModelManagement($this->db))->saved($season),'released'=>community_learning_public($this->db,$season)['model']['model_id']??null];
 }
}
