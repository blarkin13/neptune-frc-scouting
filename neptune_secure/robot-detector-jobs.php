<?php
declare(strict_types=1);

/** Robot-detector work for connected helpers: prepare labelling frames, train the detector, share the adopted one.
 *
 * Kept apart from the action-model job tables on purpose. Files live under neptune_secure/robot-detector/<org>/:
 *   requests/<id>.json   queued / claimed / done requests
 *   current/             the adopted wide-shot bumper detector (manifest.json + weights) every helper downloads
 *   field-current/<game>/ each game's adopted field detector (scoring targets + field corners) and its reference marks
 *   prefill/<video>.json detector boxes for Robot labels frames
 */
final class RobotDetectorJobs {
 private string $root;
 public function __construct(string $secure,int $org){$this->root=$secure.'/robot-detector/'.$org;}
 private function dir(string $sub):string{$d=$this->root.'/'.$sub;if(!is_dir($d)&&!mkdir($d,0700,true))throw new RuntimeException('Detector storage unavailable');return $d;}
 private static function write(string $file,array $data):void{
  $tmp=tempnam(dirname($file),'pending-');if($tmp===false||file_put_contents($tmp,json_encode($data,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Could not save');
  chmod($tmp,0600);if(!rename($tmp,$file))throw new RuntimeException('Could not commit save');
 }
 private static function game(string $g):string{if(!preg_match('/^\d{4}-[a-z0-9-]{1,40}$/D',$g))throw new InvalidArgumentException('Invalid game');return $g;}
 /** The newest configured game: what helpers that predate per-game field detectors get. */
 public static function defaultGame():string{
  $f=__DIR__.'/community-learning/season_rules.json';$s=is_file($f)?(json_decode((string)file_get_contents($f),true)['seasons']??[]):[];krsort($s);
  foreach($s as $x)if(!empty($x['configured'])&&!empty($x['game_key']))return $x['game_key'];return '2026-rebuilt';
 }
 /** One folder per game. The single pre-season-split detector moves into its game's folder on first use. */
 private function fieldDir(string $game,bool $history=false):string{
  $root=$this->root.'/'.($history?'field-history':'field-current');$legacy=$root.'/manifest.json';
  if(!$history&&is_file($legacy)){$m=json_decode((string)file_get_contents($legacy),true);$to=$this->dir('field-current/'.self::game((string)($m['report']['game_key']??self::defaultGame())));foreach(glob($root.'/*',GLOB_NOSORT)?:[] as $f)if(is_file($f))rename($f,$to.'/'.basename($f));}
  if($history&&is_dir($root)){foreach(glob($root.'/*/manifest.json')?:[] as $f){$d=dirname($f);if(preg_match('/^\d{8}-\d{6}$/',basename($d)))rename($d,$this->dir('field-history/'.self::defaultGame()).'/'.basename($d));}}
  return $this->dir(($history?'field-history/':'field-current/').self::game($game));
 }
 private static function video(string $v):string{if(!preg_match('/^[A-Za-z0-9_-]{11}$/D',$v))throw new InvalidArgumentException('Invalid video');return $v;}

 /** Queue a request from the website. Re-queuing the same open request returns the existing one. */
 public function queue(string $kind,array $p,int $user):array{
  if(!in_array($kind,['prepare','train','train_field'],true))throw new InvalidArgumentException('Unknown request');
  $body=['kind'=>$kind];if($kind==='train_field')$body['game_key']=self::game((string)($p['game_key']??''));
  if(in_array($kind,['train','train_field'],true)&&preg_match('/^[A-Za-z0-9_-]{1,100}$/D',(string)($p['holdout']??'')))$body['holdout']=$p['holdout'];   // chosen by autoscout_holdout()
  if(preg_match('/^[a-f0-9]{32}$/D',(string)($p['computer']??'')))$body['target']=$p['computer'];   // only this helper may claim it
  if($kind==='prepare'){$body['source_stills']=true;$body['video_id']=self::video((string)($p['video_id']??''));$body['match_key']=(string)($p['match_key']??'');if(!preg_match('/^\d{4}[A-Za-z0-9_-]{1,95}$/D',$body['match_key']))throw new InvalidArgumentException('Invalid match');
   $times=$p['times_ms']??[];   // hard frames picked from tracking reviews
   if(!is_array($times)||count($times)>60)throw new InvalidArgumentException('Invalid frame times');
   foreach($times as $t)if(!is_int($t)||$t<0||$t>1800000)throw new InvalidArgumentException('Invalid frame times');
   if($times){$times=array_values(array_unique($times));sort($times);$body['times_ms']=$times;}}
  foreach($this->requests() as $r)if(in_array($r['state'],['queued','claimed'],true)&&$r['kind']===$kind&&($r['video_id']??null)===($body['video_id']??null)&&($r['game_key']??null)===($body['game_key']??null))return $r;
  $r=$body+['id'=>bin2hex(random_bytes(12)),'state'=>'queued','created_at'=>time(),'created_by'=>$user,'message'=>empty($body['target'])?'Waiting for a connected helper':'Waiting for the chosen computer'];
  self::write($this->dir('requests').'/'.$r['id'].'.json',$r);return $r;
 }
 public function requests():array{
  $out=[];foreach(glob($this->root.'/requests/*.json')?:[] as $f){$r=json_decode((string)file_get_contents($f),true);if(is_array($r))$out[]=$r;}
  usort($out,fn($a,$b)=>$b['created_at']<=>$a['created_at']);return array_slice($out,0,50);
 }
 /** A helper takes the oldest queued request; a claim older than 4 hours without progress is reclaimable. */
 public function claim(array $computer):?array{
  $all=array_reverse($this->requests());
  foreach($all as $r){
   $quiet=time()-(int)($r['heartbeat_at']??$r['claimed_at']??0);
   // Another helper takes over after 4 h of silence; the same helper takes its own run back at once (it restarted).
   $stale=$r['state']==='claimed'&&($quiet>4*3600||(($r['computer_id']??null)===$computer['id']&&$quiet>60));
   if($r['state']!=='queued'&&!$stale)continue;
   if(!empty($r['source_stills'])&&!$this->supportsSourceStills((string)$computer['id']))continue;
   if($r['kind']==='train'&&is_file(dirname($this->root,2).'/external-bumper-training/'.basename($this->root).'/6962-workshop-v1.json')&&!$this->supportsWorkshop((string)$computer['id']))continue;
   if($r['kind']==='train_field'&&!$this->supportsFieldReference((string)$computer['id']))continue;
   if($r['kind']==='train'&&!$this->supportsRobotValidation((string)$computer['id']))continue;
   if(!empty($r['times_ms'])&&!$this->supportsHardFrames((string)$computer['id']))continue;
   if(!empty($r['target'])&&$r['target']!==$computer['id'])continue;   // sent to another computer
   $r=array_replace($r,['state'=>'claimed','claimed_at'=>time(),'heartbeat_at'=>time(),'computer_id'=>$computer['id'],'lease'=>bin2hex(random_bytes(16)),'message'=>($r['state']==='claimed'?'Restarted on the helper':'Started on a helper')]);
   self::write($this->dir('requests').'/'.$r['id'].'.json',$r);return $r;
  }
  return null;
 }
 /** Old helpers upload guesses only; source-still requests must wait for a compatible release. */
 private function supportsSourceStills(string $computer):bool{
  $v=(string)($this->status($computer)['version']??'');
  if(preg_match('/^engine (\d+\.\d+\.\d+)/D',$v,$m))return version_compare($m[1],'2.7.28','>=');
  if(preg_match('/^3\./',$v))return version_compare($v,'3.2.43','>=');
  if(preg_match('/^6\.5\./',$v))return version_compare($v,'6.5.16','>=');
  if(preg_match('/^6\.6\./',$v))return version_compare($v,'6.6.8','>=');
  return false;
 }
 private function supportsWorkshop(string $computer):bool{
  $v=(string)($this->status($computer)['version']??'');
  if(preg_match('/^engine (\d+\.\d+\.\d+)/D',$v,$m))return version_compare($m[1],'2.7.32','>=');
  if(preg_match('/^3\./',$v))return version_compare($v,'3.2.47','>=');
  if(preg_match('/^6\.5\./',$v))return version_compare($v,'6.5.18','>=');
  if(preg_match('/^6\.6\./',$v))return version_compare($v,'6.6.10','>=');
  return false;
 }
 private function supportsFieldReference(string $computer):bool{
  $v=(string)($this->status($computer)['version']??'');
  // Field runs need helpers that accept polygon boundaries and separate confirmed/inferred corners; retrain at the checked
  // picture size and keep the held-out match out of training.
  if(preg_match('/^engine (\d+\.\d+\.\d+)/D',$v,$m))return version_compare($m[1],'2.7.65','>=');
  if(preg_match('/^3\./',$v))return version_compare($v,'3.2.80','>=');
  if(preg_match('/^6\.5\./',$v))return version_compare($v,'6.5.51','>=');
  if(preg_match('/^6\.6\./',$v))return version_compare($v,'6.6.42','>=');
  return false;
 }
 private function supportsRobotValidation(string $computer):bool{
  $v=(string)($this->status($computer)['version']??'');
  // Robot runs need helpers that keep the held-out match out of YOLO's validation set and score it after
  // the same nested-box cleanup tracking uses, so every candidate and the detector in use are checked alike,
  // and that train the YOLO11 Small detector (an older helper would keep fine-tuning Nano).
  if(preg_match('/^engine (\d+\.\d+\.\d+)/D',$v,$m))return version_compare($m[1],'2.7.63','>=');
  if(preg_match('/^3\./',$v))return version_compare($v,'3.2.78','>=');
  if(preg_match('/^6\.5\./',$v))return version_compare($v,'6.5.49','>=');
  if(preg_match('/^6\.6\./',$v))return version_compare($v,'6.6.40','>=');
  return false;
 }
 private function supportsHardFrames(string $computer):bool{
  $v=(string)($this->status($computer)['version']??'');
  // Older helpers would prepare only the regular grid and drop the picked hard frames. Set to the release that ships them.
  if(preg_match('/^engine (\d+\.\d+\.\d+)/D',$v,$m))return version_compare($m[1],'2.7.42','>=');
  if(preg_match('/^3\./',$v))return version_compare($v,'3.2.57','>=');
  if(preg_match('/^6\.5\./',$v))return version_compare($v,'6.5.28','>=');
  if(preg_match('/^6\.6\./',$v))return version_compare($v,'6.6.19','>=');
  return false;
 }
 private function owned(array $computer,array $p):array{
  $id=(string)($p['request_id']??'');if(!preg_match('/^[a-f0-9]{24}$/D',$id))throw new InvalidArgumentException('Invalid request');
  $f=$this->root.'/requests/'.$id.'.json';$r=is_file($f)?json_decode((string)file_get_contents($f),true):null;
  if(!$r||($r['computer_id']??null)!==$computer['id']||!hash_equals((string)($r['lease']??''),(string)($p['lease']??'')))throw new InvalidArgumentException('This request belongs to another helper');
  return $r;
 }
 /** Cancel from the website. A running helper is told on its next progress report and stops. */
 public function cancel(string $id):array{
  if(!preg_match('/^[a-f0-9]{24}$/D',$id))throw new InvalidArgumentException('Invalid request');
  $f=$this->root.'/requests/'.$id.'.json';$r=is_file($f)?json_decode((string)file_get_contents($f),true):null;
  if(!$r||!in_array($r['state'],['queued','claimed'],true))throw new InvalidArgumentException('This run is not waiting or running.');
  $r=array_replace($r,['state'=>'cancelled','finished_at'=>time(),'message'=>'Cancelled on Neptune']);self::write($f,$r);return ['ok'=>true];
 }
 public function progress(array $computer,array $p):array{
  $r=$this->owned($computer,$p);if($r['state']==='cancelled')return ['ok'=>true,'cancelled'=>true];$r['heartbeat_at']=time();$r['message']=mb_substr((string)($p['message']??''),0,600);
  self::write($this->root.'/requests/'.$r['id'].'.json',$r);return ['ok'=>true];
 }
 public function finish(array $computer,array $p):array{
  $r=$this->owned($computer,$p);if($r['state']==='cancelled')return ['ok'=>true,'cancelled'=>true];$ok=!empty($p['ok']);
  $r=array_replace($r,['state'=>$ok?'done':'failed','finished_at'=>time(),'message'=>mb_substr((string)($p['message']??''),0,500),'report'=>is_array($p['report']??null)?$p['report']:null]);
  unset($r['lease']);self::write($this->root.'/requests/'.$r['id'].'.json',$r);return ['ok'=>true];
 }
 /** Save a review video (last 10 kept) and upload it to YouTube when connected. Returns the review record. */
 private function storeVideo(string $name,string $b64,string $title,string $desc,?YouTubeUploader $youtube):array{
  $raw=base64_decode($b64,true);if($raw===false||strlen($raw)<1000||strlen($raw)>48*1024*1024)throw new InvalidArgumentException('Invalid review video');
  $dir=$this->dir('review');$file=$dir.'/'.$name.'.mp4';file_put_contents($file,$raw,LOCK_EX);chmod($file,0600);
  $old=glob($dir.'/*.mp4')?:[];usort($old,fn($a,$b)=>filemtime($b)<=>filemtime($a));foreach(array_slice($old,10) as $f)unlink($f);
  $title=mb_substr(trim($title)?:'Auto Scout review',0,100);$review=['status'=>'saved','title'=>$title,'saved_at'=>time()];
  if($youtube&&$youtube->status()['connected']){
   set_time_limit(900);   // the upload to YouTube runs inside this request
   try{$review=$youtube->upload($file,$title,mb_substr($desc,0,4900))+$review;$review['status']='uploaded';}
   catch(Throwable $e){$review['status']='upload_failed';$review['error']=mb_substr($e->getMessage(),0,200);}
  }else $review['status']='youtube_not_connected';
  return $review;
 }
 /** A match test's video (test jobs live in Neptune's database, not this queue): recorded under test-videos/. */
 public function putTestVideo(array $computer,array $p,?YouTubeUploader $youtube):array{
  $id=(string)($p['job_id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid test');
  $review=$this->storeVideo('test-'.$id,(string)($p['video_b64']??''),(string)($p['title']??''),(string)($p['description']??''),$youtube);
  $rec=$review+['job_id'=>$id,'match_key'=>preg_match('/^\d{4}[A-Za-z0-9_-]{1,95}$/D',(string)($p['match_key']??''))?$p['match_key']:null,'computer_id'=>$computer['id']];
  self::write($this->dir('test-videos').'/'.$id.'.json',$rec);return ['review'=>$review];
 }
 public function testVideos():array{
  $out=[];foreach(glob($this->root.'/test-videos/*.json')?:[] as $f){$d=json_decode((string)file_get_contents($f),true);if(is_array($d))$out[]=$d;}
  usort($out,fn($a,$b)=>($b['saved_at']??0)<=>($a['saved_at']??0));return array_slice($out,0,10);
 }
 /** A run's review video: kept on Neptune (last 10), uploaded to YouTube when connected; the result goes on the request. */
 public function putReview(array $computer,array $p,?YouTubeUploader $youtube):array{
  $r=$this->owned($computer,$p);if($r['state']==='cancelled')throw new InvalidArgumentException('This run was cancelled');if(!in_array($r['kind'],['train','train_field'],true))throw new InvalidArgumentException('Only training runs have review videos');
  $raw=base64_decode((string)($p['video_b64']??''),true);if($raw===false||strlen($raw)<1000||strlen($raw)>48*1024*1024)throw new InvalidArgumentException('Invalid review video');
  $dir=$this->dir('review');$file=$dir.'/'.$r['id'].'.mp4';file_put_contents($file,$raw,LOCK_EX);chmod($file,0600);
  $old=glob($dir.'/*.mp4')?:[];usort($old,fn($a,$b)=>filemtime($b)<=>filemtime($a));foreach(array_slice($old,10) as $f)unlink($f);
  $title=mb_substr(trim((string)($p['title']??'Auto Scout review')),0,100);$desc=mb_substr((string)($p['description']??''),0,4900);
  $review=['status'=>'saved','title'=>$title,'saved_at'=>time()];
  if($youtube&&$youtube->status()['connected']){
   set_time_limit(900);   // the upload to YouTube runs inside this request
   try{$review=$youtube->upload($file,$title,$desc)+$review;$review['status']='uploaded';}
   catch(Throwable $e){$review['status']='upload_failed';$review['error']=mb_substr($e->getMessage(),0,200);}
  }else $review['status']='youtube_not_connected';
  $r['review']=$review;self::write($this->root.'/requests/'.$r['id'].'.json',$r);
  return ['review'=>$review];
 }
 /** Per-computer settings set on Neptune (My computers): keep awake 'jobs' (default), 'always' or 'off'. */
 private static function cid(string $id):string{if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid computer');return $id;}
 public function settings(string $computer):array{$f=$this->root.'/computers/'.self::cid($computer).'.json';$d=is_file($f)?json_decode((string)file_get_contents($f),true):[];
  return ['keep_awake'=>in_array($d['keep_awake']??'',['jobs','always','off'],true)?$d['keep_awake']:'jobs','auto_update'=>!empty($d['auto_update']),'update_requested'=>(int)($d['update_requested']??0)?:null];}
 /** Owner choices for one computer: keep_awake, auto_update (install new helper versions when idle), update ('request' or 'cancel' a one-time update). */
 public function setSettings(string $computer,array $p):array{
  $s=$this->settings($computer);if(isset($p['keep_awake'])){if(!in_array($p['keep_awake'],['jobs','always','off'],true))throw new InvalidArgumentException('Choose when to keep this computer awake.');$s['keep_awake']=$p['keep_awake'];}
  if(array_key_exists('auto_update',$p))$s['auto_update']=(bool)$p['auto_update'];
  if(isset($p['update'])){if(!in_array($p['update'],['request','cancel'],true))throw new InvalidArgumentException('Choose request or cancel.');$s['update_requested']=$p['update']==='request'?time():null;}
  self::write($this->dir('computers').'/'.self::cid($computer).'.json',$s+['updated_at'=>time()]);return $s;
 }
 /** A helper checks in every ~30 s while idle and with each job update: its status line and whether it is keeping the computer awake. */
 public function poll(array $computer,array $p):array{
  $cid=(string)$computer['id'];$version=mb_substr((string)($p['version']??''),0,40);$platform=mb_substr((string)($p['platform']??''),0,80);
  self::write($this->dir('computers').'/'.self::cid($cid).'.status.json',['at'=>time(),'busy'=>!empty($p['busy']),'awake'=>!empty($p['awake']),'message'=>mb_substr((string)($p['message']??''),0,600),'version'=>$version,'platform'=>$platform,
   'stats'=>self::numbers($p['stats']??null,2),'counters'=>self::numbers($p['counters']??null,1),'remote_update'=>!empty($p['remote_update']),'form'=>in_array($p['form']??null,['laptop','desktop','server'],true)?$p['form']:null,'update'=>mb_substr((string)($p['update']??''),0,200),
   'log'=>array_values(array_map(fn($l)=>[(int)($l[0]??0),mb_substr((string)($l[1]??''),0,220)],array_slice(is_array($p['log']??null)?array_filter($p['log'],'is_array'):[],-14)))]);
  // A requested update is done once this computer reports the newest published version for its platform; a day-old request lapses.
  $s=$this->settings($cid);$latest=helper_latest_version($platform);
  if($s['update_requested']&&(($latest!==null&&$version!==''&&version_compare($version,$latest,'>='))||time()-$s['update_requested']>86400))$s=$this->setSettings($cid,['update'=>'cancel']);
  return ['settings'=>$s+['latest'=>$latest]];
 }
 /** A removed computer's settings and last check-in. */
 public function forget(string $computer):void{foreach(['.json','.status.json'] as $x){$f=$this->root.'/computers/'.self::cid($computer).$x;if(is_file($f))unlink($f);}$f=$this->root.'/selfchecks/'.self::cid($computer).'.json';if(is_file($f))unlink($f);}
 /** Live readout values: short strings and numbers only, nested one level (stats.gpu). */
 private static function numbers(mixed $v,int $depth):array{
  if(!is_array($v))return [];$out=[];
  foreach(array_slice($v,0,16,true) as $k=>$x){if(!is_string($k)||!preg_match('/^[a-z_]{1,20}$/D',$k))continue;
   if(is_int($x)||is_float($x))$out[$k]=$x;elseif(is_string($x))$out[$k]=mb_substr($x,0,60);elseif(is_array($x)&&$depth>1)$out[$k]=self::numbers($x,$depth-1);elseif($x===null)$out[$k]=null;}
  return $out;
 }
 public function status(string $computer):?array{$f=$this->root.'/computers/'.self::cid($computer).'.status.json';return is_file($f)?json_decode((string)file_get_contents($f),true):null;}
 /** A helper's GPU self-check (neptune_selfcheck), one file per helper. */
 public function putSelfcheck(array $computer,array $p):array{
  $keep=['version'=>(string)($p['version']??''),'checked_at'=>time(),'ok'=>!empty($p['ok']),'platform'=>mb_substr((string)($p['platform']??''),0,60),
   'summary'=>array_map(fn($v)=>is_scalar($v)||$v===null?(is_string($v)?mb_substr($v,0,200):$v):null,array_slice(is_array($p['summary']??null)?$p['summary']:[],0,12)),
   'checks'=>array_map(fn($c)=>['check'=>mb_substr((string)($c['check']??''),0,60),'ok'=>!empty($c['ok']),'detail'=>mb_substr((string)($c['detail']??''),0,300)],array_slice(is_array($p['checks']??null)?$p['checks']:[],0,10))];
  self::write($this->dir('selfchecks').'/'.preg_replace('/[^A-Za-z0-9_-]/','',(string)$computer['id']).'.json',$keep);return ['ok'=>true];
 }
 public function selfcheck(string $computer):?array{$f=$this->root.'/selfchecks/'.preg_replace('/[^A-Za-z0-9_-]/','',$computer).'.json';return is_file($f)?json_decode((string)file_get_contents($f),true):null;}
 /** Detector guesses for Robot labels frames: {t_ms: [{alliance,x,y,w,h}]} plus the frame times. */
 public function putLabelFrame(array $computer,array $p):array{
  $r=$this->owned($computer,$p);$v=self::video((string)($p['video_id']??''));
  if(($r['kind']??'')!=='prepare'||($r['state']??'')!=='claimed'||($r['video_id']??'')!==$v)throw new InvalidArgumentException('Frame does not belong to this preparation request');
  return (new RobotLabelFrames(dirname($this->root,2),(int)basename($this->root)))->put($v,(int)($p['t_ms']??-1),(string)($p['image_b64']??''),(string)($p['source_sha256']??''));
 }
 public function putPrefill(array $computer,array $p):array{
  $this->owned($computer,$p);$v=self::video((string)($p['video_id']??''));$frames=[];
  foreach(($p['frames']??[]) as $t=>$boxes){
   if(!ctype_digit((string)$t)||!is_array($boxes)||count($boxes)>80)throw new InvalidArgumentException('Invalid frame');
   $frames[(string)$t]=array_values(array_filter(array_map(fn($b)=>is_array($b)&&in_array($b['alliance']??'',['red','blue'],true)?['alliance'=>$b['alliance'],'x'=>(float)$b['x'],'y'=>(float)$b['y'],'w'=>(float)$b['w'],'h'=>(float)$b['h']]:null,$boxes)));
  }
  if(count($frames)>2000)throw new InvalidArgumentException('Too many frames');
  self::write($this->dir('prefill').'/'.$v.'.json',['video_id'=>$v,'frames'=>$frames,'detector'=>(string)($p['detector']??''),'created_at'=>time()]);
  return ['ok'=>true,'frames'=>count($frames)];
 }
 public function prefill(string $video):?array{$f=$this->root.'/prefill/'.self::video($video).'.json';return is_file($f)?json_decode((string)file_get_contents($f),true):null;}
 /** The adopted detector: manifest always; weights only when the helper's copy differs. */
 public function current(?string $have=null):array{
  $m=$this->root.'/current/manifest.json';if(!is_file($m))return ['detector'=>null];
  $manifest=json_decode((string)file_get_contents($m),true);$out=['detector'=>$manifest];
  if($have!==($manifest['by_camera']['wide']['sha256']??null)){$w=$this->root.'/current/'.($manifest['by_camera']['wide']['file']??'');if(is_file($w))$out['weights_b64']=base64_encode((string)file_get_contents($w));}
  return $out;
 }
 /** A game's adopted field detector and reference marks; weights only when the helper's copy differs. */
 public function currentField(?string $have=null,?string $game=null):array{
  $dir=$this->fieldDir($game??self::defaultGame());$m=$dir.'/manifest.json';if(!is_file($m))return ['detector'=>null];
  $manifest=json_decode((string)file_get_contents($m),true);$out=['detector'=>$manifest];
  if($have!==($manifest['sha256']??null)){$w=$dir.'/'.($manifest['file']??'');if(is_file($w))$out['weights_b64']=base64_encode((string)file_get_contents($w));$r=$dir.'/reference.json';if(is_file($r))$out['reference']=json_decode((string)file_get_contents($r),true);}
  return $out;
 }
 /** Adopt a field detector that placed targets and corners better on a held-out match of its game. Previous one is kept. */
 public function adoptField(array $computer,array $p):array{
  $r=$this->owned($computer,$p);if($r['state']==='cancelled')throw new InvalidArgumentException('This run was cancelled');if($r['kind']!=='train_field')throw new InvalidArgumentException('Only a field training request can adopt a field detector');
  $raw=base64_decode((string)($p['weights_b64']??''),true);if($raw===false||strlen($raw)<1000||strlen($raw)>60*1024*1024)throw new InvalidArgumentException('Invalid detector weights');
  $game=self::game((string)($r['game_key']??self::defaultGame()));$sha=hash('sha256',$raw);$cur=$this->fieldDir($game);$old=$cur.'/manifest.json';
  if(is_file($old)){$hist=$this->dir('field-history/'.$game.'/'.date('Ymd-His'));foreach(glob($cur.'/*')?:[] as $f)copy($f,$hist.'/'.basename($f));}
  $file='field_'.substr($sha,0,12).'.pt';file_put_contents($cur.'/'.$file,$raw,LOCK_EX);chmod($cur.'/'.$file,0600);
  foreach(glob($cur.'/field_*.pt')?:[] as $f)if(basename($f)!==$file)unlink($f);
  if(is_array($p['reference']??null))self::write($cur.'/reference.json',$p['reference']);
  self::write($old,['schema_version'=>2,'game_key'=>$game,'file'=>$file,'sha256'=>$sha,'adopted_at'=>time(),'request_id'=>$r['id'],'report'=>is_array($p['report']??null)?$p['report']:[]]);
  return ['ok'=>true,'sha256'=>$sha];
 }
 /** Earlier adopted detectors, newest first. The field detector's history is per game. */
 public function history(string $which,?string $game=null):array{
  $dir=$which==='field'?$this->fieldDir($game??self::defaultGame(),true):$this->root.'/history';
  $out=[];foreach(array_reverse(glob($dir.'/*/manifest.json')?:[]) as $m){$d=json_decode((string)file_get_contents($m),true);if(is_array($d))$out[]=['stamp'=>basename(dirname($m)),'manifest'=>$d];}
  return $out;
 }
 /** Go back to the previous adopted detector. The one replaced is set aside, not deleted. Helpers pick it up on their next hourly sync. */
 public function rollback(string $which,?string $game=null):array{
  if(!in_array($which,['robot','field'],true))throw new InvalidArgumentException('Unknown detector');
  $game=$which==='field'?self::game($game??self::defaultGame()):null;
  $hist=$which==='field'?$this->fieldDir($game,true):$this->root.'/history';$cur=$which==='field'?$this->fieldDir($game):$this->dir('current');
  $dirs=glob($hist.'/*',GLOB_ONLYDIR)?:[];if(!$dirs)throw new InvalidArgumentException('There is no earlier detector to go back to.');
  $prev=end($dirs);$aside=$this->dir(($which==='field'?'field-undone/'.$game.'/':'undone/').date('Ymd-His'));
  foreach(glob($cur.'/*')?:[] as $f)rename($f,$aside.'/'.basename($f));
  foreach(glob($prev.'/*')?:[] as $f)rename($f,$cur.'/'.basename($f));
  rmdir($prev);return ['ok'=>true,'restored'=>basename($prev)];
 }
 /** Adopt a detector the helper trained and showed better on a held-out match. Previous one is kept. */
 public function adopt(array $computer,array $p):array{
  $r=$this->owned($computer,$p);if($r['state']==='cancelled')throw new InvalidArgumentException('This run was cancelled');if($r['kind']!=='train')throw new InvalidArgumentException('Only a training request can adopt a detector');
  $raw=base64_decode((string)($p['weights_b64']??''),true);if($raw===false||strlen($raw)<1000||strlen($raw)>60*1024*1024)throw new InvalidArgumentException('Invalid detector weights');
  $sha=hash('sha256',$raw);$report=is_array($p['report']??null)?$p['report']:[];
  $cur=$this->dir('current');$old=$cur.'/manifest.json';
  if(is_file($old)){$hist=$this->dir('history/'.date('Ymd-His'));foreach(glob($cur.'/*')?:[] as $f)copy($f,$hist.'/'.basename($f));}
  $file='bumper_wide_'.substr($sha,0,12).'.pt';file_put_contents($cur.'/'.$file,$raw,LOCK_EX);chmod($cur.'/'.$file,0600);
  foreach(glob($cur.'/bumper_wide_*.pt')?:[] as $f)if(basename($f)!==$file)unlink($f);
  self::write($old,['schema_version'=>1,'by_camera'=>['wide'=>['file'=>$file,'sha256'=>$sha]],'adopted_at'=>time(),'request_id'=>$r['id'],'report'=>$report]);
  return ['ok'=>true,'sha256'=>$sha];
 }
}

/** Newest published helper version for a check-in's platform ("Mac · …", "Windows · …", "Linux · …"), read from the public update feeds. */
function helper_latest_version(string $platform):?string{
 static $cache=[];$os=strtok($platform,' ');if(!in_array($os,['Mac','Windows','Linux'],true))return null;
 $d=($GLOBALS['neptune_downloads_dir']??dirname(__DIR__).'/public_html/Neptune/community/downloads').'/';if(array_key_exists($d.$os,$cache))return $cache[$d.$os];$v=null;
 if($os==='Mac'){$x=@file_get_contents($d.'mac/appcast.xml');if($x&&preg_match('/<sparkle:shortVersionString>([0-9.]{1,20})</',$x,$m))$v=$m[1];}
 else{$j=json_decode((string)@file_get_contents($d.strtolower($os).'/latest.json'),true);if(is_array($j)&&is_string($j['version']??null)&&preg_match('/^\d+(\.\d+){1,2}$/D',$j['version']))$v=$j['version'];}
 return $cache[$d.$os]=$v;
}

