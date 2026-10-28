<?php
declare(strict_types=1);
require_once __DIR__.'/robot-detector-jobs.php';

/** The held-out match for the next detector run. Never one the detector in use trained on (its score would not be
 *  a fair comparison); among the rest, the previous one while still eligible (comparable run to run), else the last by
 *  name. The helper uses the choice stored on the request, so the page and the helper always name the same match. */
function autoscout_holdout(array $keys,?string $previous,array $trainedOn):?string {
 sort($keys);$fair=array_values(array_diff($keys,$trainedOn));if(!$fair)$fair=$keys;
 return in_array($previous,$fair,true)?$previous:($fair?end($fair):null);
}
/** Matches a detector learned from: its report, else (for the detector shipped with the engine) its manifest. */
function autoscout_trained_on(?array $current,array $shipped):array {
 if(!$current)return $shipped;$r=$current['report']??[];
 return $r['trained_on']??array_values(array_diff($r['matches']??[],[$r['holdout_match']??'']));
}

/** One status read for the Train page: helpers, what is running and waiting, and each trainable part's model and data. */
/** My computers: each connected computer, what it is doing now and its keep-awake setting. */
function autoscout_computers(PDO $pdo,RobotDetectorJobs $jobs,array $computers,array $requests):array {
 $lease=$pdo->prepare("SELECT j.id,j.state,j.message,j.started_at FROM community_training_leases l JOIN community_learning_jobs j ON j.id=l.job_id WHERE l.computer_id=? AND l.expires_at>? AND j.state IN ('remote_running','running','test_running','evaluation_running') LIMIT 1");
 $out=[];
 foreach($computers as $c){
  if($c['revoked'])continue;$st=$jobs->status((string)$c['id']);$job=null;
  foreach($requests as $r)if($r['state']==='claimed'&&($r['computer_id']??null)===$c['id']&&time()-(int)($r['heartbeat_at']??0)<900)$job=['kind'=>$r['kind']==='train_field'?'field':($r['kind']==='train'?'robot':'prepare'),'request'=>$r['id'],'message'=>$r['message']??'','started'=>$r['claimed_at']??null];
  if(!$job){$lease->execute([$c['id'],time()]);if($j=$lease->fetch(PDO::FETCH_ASSOC))$job=['kind'=>str_starts_with($j['state'],'test_')?'test':(str_starts_with($j['state'],'evaluation_')?'evaluation':'actions'),'job'=>$j['id'],'message'=>$j['message']??'','started'=>$j['started_at']];}
  $waiting=array_values(array_map(fn($r)=>['kind'=>$r['kind']==='train_field'?'field':'robot','request'=>$r['id']],array_filter($requests,fn($r)=>$r['state']==='queued'&&($r['target']??null)===$c['id'])));
  $seen=max((int)$c['last_seen'],(int)($st['at']??0));$online=!$c['revoked']&&(int)$c['expires_at']>time()&&$seen>time()-90;
  $out[]=['id'=>$c['id'],'name'=>$c['name'],'device'=>$c['device'],'online'=>$online||(bool)$job,'ready'=>$c['ready'],'status'=>$job?'Working':($online?'Idle':$c['status']),'last_seen'=>$seen?:null,
   'platform'=>$st['platform']??null,'stats'=>($online||$job)?($st['stats']??null):null,'counters'=>$job?($st['counters']??null):null,'log'=>$st['log']??[],'reported_at'=>$st['at']??null,'version'=>$st['version']??null,'form'=>$st['form']??null,'awake'=>!empty($st['awake']),'polls'=>$st!==null,'job'=>$job,'waiting'=>$waiting,'settings'=>$settings=$jobs->settings((string)$c['id']),'selfcheck'=>$jobs->selfcheck((string)$c['id']),
   'update'=>autoscout_update_state($st,$settings)];
 }
 return $out;
}
/** Update status for one computer: newest published version, whether it is behind, an open request, auto-update, and the helper's last update message. */
function autoscout_update_state(?array $st,array $settings):array{
 $version=(string)($st['version']??'');$latest=helper_latest_version((string)($st['platform']??''));
 return ['latest'=>$latest,'behind'=>$latest!==null&&$version!==''&&version_compare($version,$latest,'<'),'remote'=>!empty($st['remote_update']),
  'requested'=>$settings['update_requested'],'auto'=>$settings['auto_update'],'message'=>(string)($st['update']??'')?:null];
}
function autoscout_train_overview(PDO $pdo,array $panel,int $org,int $user,string $game):array {
 $secure=__DIR__;$jobs=new RobotDetectorJobs($secure,$org);
 $requests=array_map(fn($r)=>array_intersect_key($r,array_flip(['id','kind','state','computer_id','holdout','created_at','claimed_at','heartbeat_at','finished_at','message','report','review','video_id','match_key'])),$jobs->requests());
 // Robot labels: complete frames per match (site saves take precedence over the local seed frames).
 $videos=[];
 foreach(array_merge(glob($secure.'/robot-labels/seed/*.json')?:[],glob($secure.'/robot-labels/'.$org.'/*.json')?:[]) as $f){
  $d=json_decode((string)file_get_contents($f),true);if(!is_array($d)||empty($d['video_id']))continue;$v=$d['video_id'];
  $videos[$v]['frames']=($d['frames']??[])+($videos[$v]['frames']??[]);$videos[$v]['match_key']=$d['match_key']??($videos[$v]['match_key']??null);
 }
 // One row per labelled video for the Robots page table (fewest completed frames first), linked to its match.
 $robotRows=[];$find=$pdo->prepare('SELECT id FROM community_matches WHERE source_url LIKE ? LIMIT 1');
 foreach($videos as $vid=>$v){$find->execute(['%'.$vid.'%']);$robotRows[]=['video_id'=>$vid,'match_key'=>$v['match_key'],'match_id'=>($id=$find->fetchColumn())?(int)$id:null,'frames'=>count(array_filter($v['frames'],fn($fr)=>!empty($fr['complete'])))];}
 usort($robotRows,fn($a,$b)=>[$a['frames'],$a['match_key']]<=>[$b['frames'],$b['match_key']]);
 $robotMatches=[];foreach($videos as $v){$n=count(array_filter($v['frames'],fn($fr)=>!empty($fr['complete'])));if($n&&$v['match_key'])$robotMatches[$v['match_key']]=($robotMatches[$v['match_key']]??0)+$n;}
 // Scoring areas: this game's matches with scoring targets and the four field corners marked.
 $fieldMatches=[];$fieldVideo=[];
 foreach(array_merge(glob($secure.'/field-regions/seed-documents/*.json')?:[],glob($secure.'/field-regions/'.$user.'/*.json')?:[]) as $f){
  if(filesize($f)>2097152)continue;$d=json_decode((string)file_get_contents($f),true);if(!is_array($d))continue;$kinds=array_column(array_filter($d['regions']??[],fn($r)=>($r['game_key']??'')===$game),'kind');if(!$kinds)continue;
  // Documents name their video (YouTube id or a cached file named after it); find its match.
  $src=$d['source']??[];$vid=(string)($src['video_id']??preg_replace('/\.[a-z0-9]+$/i','',(string)($src['name']??'')));$key=(string)($src['match_key']??'');
  if($key===''&&preg_match('/^[A-Za-z0-9_-]{11}$/D',$vid)){$q=$pdo->prepare('SELECT match_key FROM community_matches WHERE source_url LIKE ? LIMIT 1');$q->execute(['%'.$vid.'%']);$key=(string)($q->fetchColumn()?:'');}
  if($key==='')$key=$vid?:basename($f,'.json');
  if($vid!=='')$fieldVideo[$vid]=$key;
  $regions=array_filter($d['regions']??[],fn($r)=>($r['game_key']??'')===$game);$hasCorners=false;foreach($regions as $r)if(in_array($r['kind']??'', ['field_calibration','field_boundary'],true)&&($r['camera_view']??'')==='wide'&&count($r['points']??[])===4)$hasCorners=true;
  $fieldMatches[$key]=['targets'=>count(array_keys($kinds,'scoring_target')),'corners'=>$hasCorners];
 }
 $event=fn($k)=>preg_match('/^(\d{4}[a-z0-9]+)_/',(string)$k,$m)?$m[1]:'';
 $robot=$jobs->current('skip-weights')['detector'];$field=$jobs->currentField('skip-weights',$game)['detector'];
 $latest=null;foreach($panel['jobs'] as $j)if($j['state']==='candidate'){$latest=$j;break;}
 $active=['remote_queued','remote_running','queued','running','test_queued','test_running','evaluation_queued','evaluation_running'];
 $queue=[];
 foreach(array_reverse($requests) as $r)if(in_array($r['state'],['queued','claimed'],true))$queue[]=['request'=>$r['id'],'target'=>$r['target']??null,'part'=>$r['kind']==='train_field'?'field':($r['kind']==='train'?'robot':'prepare'),'holdout'=>$r['holdout']??null,'started'=>$r['claimed_at']??null,'state'=>$r['state']==='claimed'?'running':'waiting','title'=>['train'=>'Train robot detector','train_field'=>'Train field detector · '.($r['game_key']??''),'prepare'=>'Prepare frames · '.($r['match_key']??'')][$r['kind']]??$r['kind'],'message'=>$r['message']??'','since'=>$r['created_at']];
 foreach(array_reverse($panel['jobs']) as $j)if(in_array($j['state'],$active,true))$queue[]=['job'=>$j['id'],'part'=>str_starts_with($j['state'],'test_')||str_starts_with($j['state'],'evaluation_')?'test':'actions','state'=>str_ends_with($j['state'],'_queued')||$j['state']==='queued'?'waiting':'running','title'=>str_starts_with($j['state'],'test_')?'Test a match':(str_starts_with($j['state'],'evaluation_')?'Independent evaluation':'Train action models & scoring'),'message'=>$j['message']??'','since'=>$j['created_at']];
 // Say which pillar each job belongs to and what it is learning from (the same matches the helper picks).
 $usable=array_keys(array_filter($robotMatches,fn($n)=>$n>=8));sort($usable);
 $marked=array_keys(array_filter($fieldMatches,fn($v)=>$v['targets']&&$v['corners']));sort($marked);
 $engine=$secure.'/community-learning';$name=fn($k)=>$fieldVideo[$k]??$k;   // older field reports name the video
 $shippedRobot=json_decode((string)@file_get_contents($engine.'/robot-detector/manifest.json'),true)['trained_on']??[];
 $shippedField=json_decode((string)@file_get_contents($engine.'/field-detector/trained_on.json'),true)[$game]??[];
 $heldRobot=autoscout_holdout($usable,$robot['report']['holdout_match']??null,autoscout_trained_on($robot,$shippedRobot));
 $heldField=autoscout_holdout($marked,$name($field['report']['holdout_match']??''),array_map($name,autoscout_trained_on($field,$shippedField)));
 $about=['robot'=>['1 · Robots — robot detector',sprintf('Learning from %d labelled frames in %d matches%s',array_sum(array_intersect_key($robotMatches,array_flip($usable))),count($usable),$heldRobot?' · checking on '.$heldRobot.' (held out, same every run)':'')],
  'field'=>['2 · Field — field detector',sprintf('Learning from %d marked matches%s',count($marked),$heldField?' · checking on '.$heldField.' (held out, same every run)':'')],
  'actions'=>['3 · Actions — action & scoring models',sprintf('Learning from %d tagged matches (%d tags, %d imported actions)',$panel['counts']['matches']??0,$panel['counts']['tags']??0,$panel['counts']['external']??0)],
  'test'=>['Test & release','']];
 foreach($queue as &$q){[$q['label'],$q['about']]=$about[$q['part']]??['1 · Robots — '.$q['title'],''];if(!empty($q['holdout']))$q['about']=preg_replace('/checking on \S+/','checking on '.$q['holdout'],$q['about']);}unset($q);
 usort($queue,fn($a,$b)=>[$a['state']!=='running',$a['since']]<=>[$b['state']!=='running',$b['since']]);
 $externalFile=$secure.'/external-bumper-training/'.$org.'/_summary.json';$externalSummary=is_file($externalFile)&&filesize($externalFile)<8192?json_decode((string)file_get_contents($externalFile),true):null;
 return [
  // A helper on a detector job reports to that queue, not the action-training one, so its last_seen goes stale.
  'helpers'=>array_map(function($c)use($requests,$jobs){
   $job=null;foreach($requests as $r)if($r['state']==='claimed'&&($r['computer_id']??null)===$c['id']&&time()-(int)($r['heartbeat_at']??0)<900)$job=$r;
   $check=$jobs->selfcheck((string)$c['id']);
   return ($job?['name'=>$c['name'],'status'=>'working','online'=>true,'ready'=>false,'working'=>true]:['name'=>$c['name'],'status'=>$c['status'],'online'=>$c['online'],'ready'=>$c['ready']])+['selfcheck'=>$check];
  },array_values(array_filter($panel['computers'],fn($c)=>!$c['revoked']))),
  'computers'=>autoscout_computers($pdo,$jobs,$panel['computers'],$requests),
  'queue'=>$queue,
  'test_videos'=>$jobs->testVideos(),
  'robot'=>['external'=>$externalSummary,'rows'=>$robotRows,'holdout'=>$heldRobot,'current'=>$robot,'history'=>count($jobs->history('robot')),'matches'=>$robotMatches,'events'=>array_values(array_unique(array_filter(array_map($event,array_keys($robotMatches))))),'min_frames'=>8,'runs'=>array_values(array_filter($requests,fn($r)=>$r['kind']==='train'))],
  'field'=>['baseline'=>($shippedField&&is_file($engine.'/field-detector/model.pt'))?['kind'=>'bundled','trained_on'=>$shippedField]:null,'holdout'=>$heldField,'game_key'=>$game,'current'=>$field,'history'=>count($jobs->history('field',$game)),'matches'=>$fieldMatches,'events'=>array_values(array_unique(array_filter(array_map($event,array_keys($fieldMatches))))),'runs'=>array_values(array_filter($requests,fn($r)=>$r['kind']==='train_field'&&($r['game_key']??RobotDetectorJobs::defaultGame())===$game))],
  'actions'=>['models'=>$panel['models'],'latest'=>$latest?['id'=>$latest['id'],'created_at'=>$latest['created_at']]:null,'released'=>$panel['released'],'counts'=>$panel['counts']],
 ];
}

/** The Train page overview trimmed for a connected helper's model pages: each model's status, data and last runs. */
function autoscout_helper_models(array $o):array {
 $runs=fn(array $rs)=>array_map(fn($r)=>array_intersect_key($r,array_flip(['id','state','created_at','finished_at','message','holdout','computer_id']))
  +['new'=>$r['report']['new']??null,'current'=>$r['report']['current']??null,'adopted'=>$r['report']['adopted']??null,'review'=>is_array($r['review']??null)?array_intersect_key($r['review'],array_flip(['youtube_id','status'])):null],
  array_slice($rs,0,8)); // requests() is already newest first
 $current=fn($c)=>$c?['adopted_at'=>$c['adopted_at']??null,'holdout'=>$c['report']['holdout_match']??null,'new'=>$c['report']['new']??null]:null;
 $robot=$o['robot']??[];$field=$o['field']??[];$actions=$o['actions']??[];
 $usable=array_filter($robot['matches']??[],fn($n)=>$n>=($robot['min_frames']??8));
 $marked=array_filter($field['matches']??[],fn($m)=>!empty($m['targets'])&&!empty($m['corners']));
 return [
  'robot'=>['current'=>$current($robot['current']??null),'holdout'=>$robot['holdout']??null,'matches'=>count($usable),'frames'=>array_sum($usable),'events'=>$robot['events']??[],'ready'=>count($usable)>=2,'runs'=>$runs($robot['runs']??[])],
  'field'=>['current'=>$current($field['current']??null),'baseline'=>!empty($field['baseline']),'game_key'=>$field['game_key']??null,'holdout'=>$field['holdout']??null,'marked'=>count($marked),'events'=>$field['events']??[],'ready'=>count($marked)>=2,'runs'=>$runs($field['runs']??[])],
  'actions'=>['latest'=>$actions['latest']??null,'released'=>$actions['released']??null,'models'=>count($actions['models']??[]),'counts'=>$actions['counts']??[]],
 ];
}
