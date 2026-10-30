<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
require_once dirname(__DIR__,3).'/neptune_secure/platform-security.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis-install.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-video-tags.php';
require_once dirname(__DIR__,3).'/neptune_secure/robot-detector-jobs.php';
require_once dirname(__DIR__,3).'/neptune_secure/robot-label-frames.php';
$u=require_role(['owner','admin']);
if((int)$u['organization_id']!==(int)($config['app']['platform_organization_id']??1))access_denied('Auto Scout is for admins of the owner organization.');

header('Cache-Control: private, no-store');
$_SESSION['robot_labels_csrf']??=bin2hex(random_bytes(32));
$csrf=$_SESSION['robot_labels_csrf'];
// Labels: one file per YouTube video, frames keyed by video time; read by the helper to train the robot detector.
$store=dirname(__DIR__,3).'/neptune_secure/robot-labels/'.(int)$u['organization_id'];
function robot_reply(int $code,array $data):never{http_response_code($code);header('Content-Type: application/json');echo json_encode($data,JSON_THROW_ON_ERROR);exit;}
function robot_video(string $v):string{if(!preg_match('/^[A-Za-z0-9_-]{11}$/D',$v))robot_reply(422,['error'=>'Invalid video.']);return $v;}
function robot_doc(string $store,string $video):array{
 $f=$store.'/'.$video.'.json';$d=is_file($f)?json_decode((string)file_get_contents($f),true,32,JSON_THROW_ON_ERROR):['schema_version'=>1,'video_id'=>$video,'frames'=>[]];
 // Frames labelled before this page existed (local labeller) are seeds; anything saved here takes precedence.
 $seed=dirname($store).'/seed/'.$video.'.json';
 if(is_file($seed)){$s=json_decode((string)file_get_contents($seed),true,32,JSON_THROW_ON_ERROR);$d['frames']=($d['frames']??[])+($s['frames']??[]);$d['match_key']??=$s['match_key']??null;}
 return $d;
}
// Boxes the detector found at this moment, from the helper's tracking review backed up for this video.
function robot_prefill(PDO $pdo,int $org,string $video,int $tms):array{
 try{$q=$pdo->prepare('SELECT review_gzip FROM community_identity_reviews WHERE organization_id=? AND video_id=? ORDER BY updated_at DESC LIMIT 1');$q->execute([$org,$video]);$gz=$q->fetchColumn();}
 catch(Throwable $e){return ['boxes'=>[],'times'=>[],'source'=>'none'];}
 if(!$gz){
  // No tracking review: use frames a helper prepared with the current detector, if any.
  $pre=$GLOBALS['robotJobs']->prefill($video);if(!$pre)return ['boxes'=>[],'times'=>[],'source'=>'none'];
  $times=array_map('intval',array_keys($pre['frames']??[]));sort($times);$out=[];
  foreach($pre['frames'] as $t=>$boxes)if(abs((int)$t-$tms)<=90)foreach($boxes as $b)$out[]=$b+['source'=>'detector'];
  return ['boxes'=>$out,'times'=>$times,'source'=>'prepared_frames'];
 }
 $review=json_decode((string)gzdecode((string)$gz),true,64);$points=is_array($review['points']??null)?$review['points']:[];
 $times=[];$out=[];
 foreach($points as $p){
  if(!is_array($p['box']??null)||count($p['box'])!==4||!in_array($p['alliance']??'',['red','blue'],true)||($p['box_source']??'')==='optical_flow')continue;
  $t=(int)round(((float)$p['t'])*1000);$times[$t]=true;
  if(abs($t-$tms)>90)continue;
  [$x,$y,$w,$h]=array_map('floatval',$p['box']);$bw=$w/1.6;$bh=$h/3.4; // undo the tracker's chassis crop around the bumper
  $out[]=['alliance'=>$p['alliance'],'x'=>$x+.3*$bw,'y'=>$y+2*$bh,'w'=>$bw,'h'=>$bh,'source'=>'detector'];
 }
 $times=array_keys($times);sort($times);
 return ['boxes'=>$out,'times'=>$times,'source'=>'tracking_review'];
}
$robotJobs=new RobotDetectorJobs(dirname(__DIR__,3).'/neptune_secure',(int)$u['organization_id']);
$labelFrames=new RobotLabelFrames(dirname(__DIR__,3).'/neptune_secure',(int)$u['organization_id']);
if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['frame_index']))robot_reply(200,['frames'=>$labelFrames->index(robot_video((string)$_GET['frame_index']))]);
if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['frame_image'])){
 try{$image=$labelFrames->get(robot_video((string)$_GET['frame_image']),(int)($_GET['t_ms']??-1),isset($_GET['sha256'])?(string)$_GET['sha256']:null);}
 catch(InvalidArgumentException){robot_reply(422,['error'=>'Invalid frame']);}
 if(!$image)robot_reply(404,['error'=>'Prepare source frames for this match first.']);
 header('Content-Type: image/jpeg');header('X-Content-Type-Options: nosniff');echo $image['bytes'];exit;
}
if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['detector_status'])){
 $cur=$robotJobs->current('skip-weights');robot_reply(200,['requests'=>array_map(fn($r)=>array_diff_key($r,['lease'=>1]),array_slice($robotJobs->requests(),0,8)),'current'=>$cur['detector']]);
}
if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['labels'])){$v=robot_video((string)$_GET['labels']);robot_reply(200,robot_doc($store,$v));}
if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['prefill'])){$v=robot_video((string)$_GET['prefill']);robot_reply(200,robot_prefill($pdo,(int)$u['organization_id'],$v,max(0,(int)($_GET['t_ms']??0))));}
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($csrf,(string)($_SERVER['HTTP_X_ROBOT_LABELS_CSRF']??'')))robot_reply(403,['error'=>'Refresh this page before saving']);
 $limit=isset($_GET['import_frames'])?64*1024*1024:262144;
 $raw=file_get_contents('php://input',false,null,0,$limit+1);if(strlen($raw)>$limit)robot_reply(413,['error'=>'Too large']);
 try{
  $p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
  if(isset($_GET['import_frames'])&&($p['action']??'')==='import_frames'){
   $v=robot_video((string)($p['video_id']??''));$rows=$p['frames']??[];if(!is_array($rows)||count($rows)>180)throw new RuntimeException('Too many source frames');
   foreach($rows as $row){if(!is_int($row['t_ms']??null))throw new RuntimeException('Invalid timestamp');$labelFrames->put($v,$row['t_ms'],(string)($row['image_b64']??''),(string)($row['source_sha256']??''));}
   robot_reply(200,['ok'=>true,'frames'=>count($rows)]);
  }

  if(in_array($p['action']??'',['queue_prepare','queue_train'],true))robot_reply(200,['request'=>$robotJobs->queue($p['action']==='queue_prepare'?'prepare':'train',$p,(int)$u['id'])]);
  $v=robot_video((string)($p['video_id']??''));$tms=$p['t_ms']??null;
  if(!is_int($tms)||$tms<0||$tms>36000000)throw new RuntimeException('Invalid video time');
  $match=(string)($p['match_key']??'');if(!preg_match('/^\d{4}[A-Za-z0-9_-]{1,95}$/D',$match))throw new RuntimeException('Invalid match');
  $boxes=[];
  foreach(($p['boxes']??[]) as $b){
   if(!in_array($b['alliance']??'',['red','blue'],true))throw new RuntimeException('Each box needs red or blue');
   $vals=[];foreach(['x','y','w','h'] as $k){if(!is_numeric($b[$k]??null)||!is_finite((float)$b[$k]))throw new RuntimeException('Invalid box');$vals[$k]=(float)$b[$k];}
   if($vals['w']<=0||$vals['h']<=0||$vals['w']>1||$vals['h']>1)continue;
   $boxes[]=['alliance'=>$b['alliance']]+$vals;
  }
  $frameSha=(string)($p['frame_sha256']??'');
  if(!empty($p['source_still'])&&empty($p['delete'])&&empty($p['skipped'])){
   if($frameSha===''||!$labelFrames->get($v,$tms,$frameSha))throw new RuntimeException('Load the exact source frame before saving boxes.');
  }
  if(count($boxes)>60)throw new RuntimeException('Too many boxes in one frame');
  if(!is_dir($store)&&!mkdir($store,0700,true))throw new RuntimeException('Label storage unavailable');
  $doc=robot_doc($store,$v);$doc['match_key']=$match;
  if(!empty($p['delete']))unset($doc['frames'][(string)$tms]);
  else $doc['frames'][(string)$tms]=['boxes'=>$boxes,'complete'=>!empty($p['complete'])&&empty($p['skipped']),'skipped'=>!empty($p['skipped']),'saved_at'=>time(),'saved_by'=>(int)$u['id'],'frame_sha256'=>$frameSha];
  $tmp=tempnam($store,'pending-');if($tmp===false||file_put_contents($tmp,json_encode($doc,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Could not save');
  chmod($tmp,0600);if(!rename($tmp,$store.'/'.$v.'.json'))throw new RuntimeException('Could not commit save');
  robot_reply(200,['ok'=>true,'frames'=>count($doc['frames']),'complete'=>count(array_filter($doc['frames'],fn($f)=>!empty($f['complete'])))]);
 }catch(Throwable $e){robot_reply(422,['error'=>$e->getMessage()]);}
}
// Owner maintenance import: no training, labels or model changes.
if(isset($_GET['import_frames'])){header('Content-Type: text/html; charset=utf-8');?>
<!doctype html><html lang="en"><meta charset="utf-8"><title>Import prepared source stills</title><h1>Import prepared source stills</h1>
<p>Imports caption-free source images into private preview storage. Existing labels remain unchanged.</p>
<input type="file" id="source-file" accept="application/json"><button id="import-source">Import source stills</button><p id="import-status" role="status">Choose prepared source-frame JSON.</p>
<script>
document.getElementById('import-source').onclick=async()=>{const out=document.getElementById('import-status');try{const f=document.getElementById('source-file').files[0];if(!f)throw Error('Choose a file first');if(f.size>64*1024*1024)throw Error('File too large');const d=JSON.parse(await f.text());d.action='import_frames';out.textContent='Importing source stills…';const r=await fetch('robot-labels.php?import_frames=1',{method:'POST',headers:{'Content-Type':'application/json','X-Robot-Labels-CSRF':<?=json_encode($csrf)?>},body:JSON.stringify(d)});const reply=await r.json();if(!r.ok)throw Error(reply.error||'Import failed');out.textContent='Imported '+reply.frames+' source stills. Existing labels preserved.'}catch(e){out.textContent=e.message}};
</script></html>
<?php exit;}
// Year -> event -> match, from the same catalog as Train & test (events by start date).
if($_SERVER['REQUEST_METHOD']==='GET'&&(isset($_GET['events'])||isset($_GET['event_matches']))){
 require_once dirname(__DIR__,3).'/neptune_secure/community-catalog.php';
 header('Content-Type: application/json');
 try{
  community_catalog_schema($pdo);$catalog=new CommunityCatalog($pdo);session_write_close();
  if(isset($_GET['events']))echo json_encode(['events'=>$catalog->events((int)$_GET['events'])],JSON_THROW_ON_ERROR);
  else echo json_encode(['matches'=>array_map(fn($m)=>['id'=>$m['id'],'match_key'=>$m['match_key'],'title'=>$m['title'],'video_url'=>$m['video_url']],$catalog->matches((string)$_GET['event_matches']))],JSON_THROW_ON_ERROR);
 }catch(CommunityAnalysisError $e){http_response_code($e->httpStatus);echo json_encode(['error'=>$e->getMessage()]);}
 catch(Throwable $e){error_log('Match picker: '.$e->getMessage());http_response_code(503);echo json_encode(['error'=>'Events are unavailable right now.']);}
 exit;
}
$selected=null;
if((int)($_GET['match_id']??0)>0){
 $q=$pdo->prepare('SELECT id,match_key,title,source_url FROM community_matches WHERE id=?');$q->execute([(int)$_GET['match_id']]);$m=$q->fetch(PDO::FETCH_ASSOC);
 if($m&&($video=CommunityVideoTags::videoId((string)$m['source_url'])))$selected=$m+['video_id'=>$video];
}
$sourceMode=$selected&&count($labelFrames->index($selected['video_id']))>0;
// Progress across all labelled videos.
$counts=[];foreach(glob($store.'/*.json')?:[] as $f){$d=json_decode((string)file_get_contents($f),true);$counts[basename($f,'.json')]=count(array_filter($d['frames']??[],fn($x)=>!empty($x['complete'])));}
$total=array_sum($counts);
require_once dirname(__DIR__).'/community/layout.php';require_once dirname(__DIR__).'/community/pillar.php';community_page_start('1 · Robots','Teach Auto Scout what a robot is: box bumpers, train the robot detector, and see what it learned.','robots','AUTO SCOUT · PILLAR 1');
pillar_open((int)gmdate('Y'));
pillar_summary('robot','Finds every robot’s bumpers in a frame, in wide and close-up shots. Tracking and number reading depend on it, so this comes first. Box bumpers below, then press Train: your helper trains a new detector, checks both the new and the current detector on one held-out match it never trains on (the same match every run, so scores compare), and only switches if the new one finds bumpers more accurately. Bumpers look alike every season, so one robot detector serves all games.','Train robot detector');
?>
<link rel="stylesheet" href="../community/assets/match-picker/match-picker.css?v=1">
<link rel="stylesheet" href="../community/assets/field-regions/style.css?v=4-site-layout">
<link rel="stylesheet" href="../community/assets/robot-labels/<?= $sourceMode?'robot-labels-stills.css?v=4-source-stills':'robot-labels.css?v=1' ?>">
<div class="field-regions robot-labels" id="robot-labels-app" data-csrf="<?=e($csrf)?>"<?php if($selected):?> data-youtube="<?=e($selected['video_id'])?>" data-match-key="<?=e($selected['match_key'])?>"<?php endif?>>
<div class="fr-actions"><p>Training data for the robot detector: box <strong>every</strong> visible bumper, including robots the current detector misses. <?=$total?> frames complete across <?=count(array_filter($counts))?> matches.</p></div>
<div class="workspace"><section class="viewer"><div class="toolbar"><div class="step">01 <b>Choose a match</b></div><div class="sources"><div class="match-picker-v2" data-match-picker data-endpoint="robot-labels.php" data-selected="<?=e($selected['match_key']??'')?>"></div></div></div>
<div id="frame" class="rl-frame"><div id="zoomer" class="rl-zoomer"><div id="stage" class="stage"><video id="video" playsinline></video><img id="training-frame" alt="Exact source image used for training" hidden><canvas id="canvas" aria-label="Bumper boxes. Drag to draw; click to select."></canvas></div></div><div id="empty"><div class="empty-icon">▣</div><h2>Choose a match to start labeling.</h2><p>Label exact source stills without YouTube captions or playback overlays.</p></div><div class="framebadge" id="framebadge">NO MATCH</div></div>
<div class="transport"><button id="prevframe" title="Previous frame to label (←)">◀ Frame</button><button id="play">Play</button><button id="nextframe" title="Next frame to label (→)">Frame ▶</button><span id="clock">00:00.00</span><button id="mono" aria-pressed="false" title="Show the picture in black and white so red and blue boxes stand out (G)">Black &amp; white</button><button id="cc" aria-pressed="false" title="Turn YouTube captions on or off (C)">Captions: off</button><button id="fitview" title="Zoom back out and center the picture (0)">Fit</button><button id="fullscreen" title="Fill the screen (F); Esc to leave">Full screen</button><select id="framepick" aria-label="Go to frame" hidden></select><span id="savestate" role="status"></span><span id="framecount" class="rl-count"></span></div>
<div id="frame-source-status" role="status"<?=$sourceMode?'':' hidden'?>>Loading source-frame availability…</div><div class="tips"><span>PAUSE → BOX EVERY BUMPER → ENTER</span><p>Dashed boxes are the detector's guesses (from your tracking review); fix, delete or keep them and draw the ones it missed. Scroll to zoom, Space+drag to pan, 0 to fit.</p></div></section>
<aside><div class="step">02 <b>Box the bumpers</b></div><div class="shape-picker"><button id="red" class="rl-red active" aria-pressed="true">Red (1)</button><button id="blue" class="rl-blue" aria-pressed="false">Blue (2)</button></div>
<p class="hint">Drag on empty space: new box · Click: select · Drag: move · Corner: resize · <b>X</b> flips color · <b>Delete</b> removes · <b>U</b> undoes.</p>
<div class="rl-stats"><div><span id="boxcount">0</span><small>boxes in this frame</small></div><div><span id="donecount">0</span><small>frames complete in this match</small></div></div>
<button id="complete" class="primary save">All bumpers boxed ↵</button><button id="skip" class="save rl-secondary">Skip frame (replay, graphic, blurry)</button>
<div id="status" role="status" aria-live="polite">Choose a match.</div>
<div class="note rl-detector"><b>Robot detector</b><p>Prepare frames: your updated helper supplies clear source images and bumper guesses. When preparation finishes, refresh this page to label without YouTube captions. Play is only for watching context.</p><button id="prepare" class="save rl-secondary"<?=$selected?'':' disabled'?>>Prepare frames for this match</button><div id="requests" class="rl-requests"></div><div id="current" class="rl-current"></div></div><div class="note"><b>What makes good labels</b><p>Every bumper you can see, red or blue, even partly hidden or far away. Box just the bumper, not the whole robot. Skip frames that aren't live match footage.</p></div></aside></div></div>
<script src="../community/assets/match-picker/match-picker.js?v=1"></script><script src="../community/assets/field-regions/youtube-source.js?v=5-captions-toggle"></script><script src="../community/assets/robot-labels/<?= $sourceMode?'robot-labels-stills.js?v=7-undo':'robot-labels.js?v=3-undo' ?>"></script>
<style>.workspace:fullscreen{background:#05090d;overflow:auto;padding:10px;grid-template-columns:1fr;gap:6px;align-content:start}.workspace:fullscreen .viewer>.toolbar,.workspace:fullscreen .viewer>.tips,.workspace:fullscreen aside .step,.workspace:fullscreen aside .hint,.workspace:fullscreen aside .note,.workspace:fullscreen aside .rl-stats small{display:none}.workspace:fullscreen .rl-frame{width:min(100%,calc((100vh - 130px)*16/9));margin:0 auto}.workspace:fullscreen aside{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px;padding:0;border:0;background:none}.workspace:fullscreen aside .shape-picker{display:flex;gap:8px;margin:0}.workspace:fullscreen aside button{width:auto;margin:0;padding:8px 16px}.workspace:fullscreen aside .rl-stats{display:flex;gap:8px;margin:0}.workspace:fullscreen aside .rl-stats div{padding:4px 10px}.workspace:fullscreen aside .rl-stats span{font-size:16px}.workspace:fullscreen aside #boxcount::after{content:' boxes';font-size:12px;font-weight:400;color:#9aa7b8}.workspace:fullscreen aside #donecount::after{content:' done';font-size:12px;font-weight:400;color:#9aa7b8}.workspace:fullscreen aside #status{margin:0;font-size:13px}.workspace:fullscreen .viewer{overflow:visible;border:0;background:none;border-radius:0}.workspace:fullscreen .rl-frame{width:min(100%,calc((100vh - 150px)*16/9))}.workspace:fullscreen .transport{padding:8px 0 0;justify-content:center;gap:8px}.workspace:fullscreen .transport .rl-count{margin-left:0}.workspace:fullscreen #framepick{font-size:13px;padding:6px 8px;max-width:220px;margin-left:0}.workspace:fullscreen #frame-source-status{display:none}.workspace:fullscreen aside button{white-space:nowrap}.workspace:fullscreen aside #status{max-width:420px;font-size:12px}</style><style>.workspace:-webkit-full-screen{background:#05090d;overflow:auto;padding:10px;grid-template-columns:1fr;gap:6px;align-content:start}.workspace:-webkit-full-screen .viewer>.toolbar,.workspace:-webkit-full-screen .viewer>.tips,.workspace:-webkit-full-screen aside .step,.workspace:-webkit-full-screen aside .hint,.workspace:-webkit-full-screen aside .note,.workspace:-webkit-full-screen aside .rl-stats small{display:none}.workspace:-webkit-full-screen .rl-frame{width:min(100%,calc((100vh - 130px)*16/9));margin:0 auto}.workspace:-webkit-full-screen aside{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px;padding:0;border:0;background:none}.workspace:-webkit-full-screen aside .shape-picker{display:flex;gap:8px;margin:0}.workspace:-webkit-full-screen aside button{width:auto;margin:0;padding:8px 16px}.workspace:-webkit-full-screen aside .rl-stats{display:flex;gap:8px;margin:0}.workspace:-webkit-full-screen aside .rl-stats div{padding:4px 10px}.workspace:-webkit-full-screen aside .rl-stats span{font-size:16px}.workspace:-webkit-full-screen aside #boxcount::after{content:' boxes';font-size:12px;font-weight:400;color:#9aa7b8}.workspace:-webkit-full-screen aside #donecount::after{content:' done';font-size:12px;font-weight:400;color:#9aa7b8}.workspace:-webkit-full-screen aside #status{margin:0;font-size:13px}.workspace:-webkit-full-screen .viewer{overflow:visible;border:0;background:none;border-radius:0}.workspace:-webkit-full-screen .rl-frame{width:min(100%,calc((100vh - 150px)*16/9))}.workspace:-webkit-full-screen .transport{padding:8px 0 0;justify-content:center;gap:8px}.workspace:-webkit-full-screen .transport .rl-count{margin-left:0}.workspace:-webkit-full-screen #framepick{font-size:13px;padding:6px 8px;max-width:220px;margin-left:0}.workspace:-webkit-full-screen #frame-source-status{display:none}.workspace:-webkit-full-screen aside button{white-space:nowrap}.workspace:-webkit-full-screen aside #status{max-width:420px;font-size:12px}</style>
<style>#framepick{max-width:260px}#savestate{font-size:12px;color:#9aa7b8}#savestate[data-state=dirty]{color:#ffb84d;font-weight:700}#savestate[data-state=saved]{color:#4fd18b}</style>
<style>.stage.mono video,.stage.mono img,.stage.mono iframe,.stage.mono #ytplayer{filter:grayscale(1)}</style>
<script>(()=>{// Picture only: the boxes are drawn on the canvas above it and keep their colors.
const b=document.getElementById('mono'),stage=document.getElementById('stage');
const set=on=>{stage.classList.toggle('mono',on);b.setAttribute('aria-pressed',on);b.textContent=on?'Color':'Black & white';try{localStorage.setItem('rl-mono',on?'1':'')}catch(e){}};
try{set(localStorage.getItem('rl-mono')==='1')}catch(e){}
b.addEventListener('click',()=>set(!stage.classList.contains('mono')));
addEventListener('keydown',e=>{if(['INPUT','SELECT','TEXTAREA'].includes(document.activeElement.tagName)||e.ctrlKey||e.metaKey)return;if(e.key==='g'||e.key==='G')set(!stage.classList.contains('mono'))});})();</script>
<script>(()=>{// Fit sends the labeler's own 0 key; full screen keeps the color buttons and Done beside the picture.
const ws=document.querySelector('.workspace'),fb=document.getElementById('fullscreen');
document.getElementById('fitview').addEventListener('click',()=>dispatchEvent(new KeyboardEvent('keydown',{key:'0',code:'Digit0'})));
const isFull=()=>document.fullscreenElement||document.webkitFullscreenElement;
const toggle=()=>isFull()?(document.exitFullscreen||document.webkitExitFullscreen).call(document):(ws.requestFullscreen||ws.webkitRequestFullscreen).call(ws);
// Size the picture to whatever height the two button rows leave, so every control stays on screen however they wrap.
const fr=document.getElementById('frame'),tr=document.querySelector('.transport'),side=ws.querySelector('aside');
const fitFrame=()=>{if(!isFull()){fr.style.width='';return}const left=innerHeight-tr.offsetHeight-side.offsetHeight-40;fr.style.width=Math.max(320,Math.min(ws.clientWidth-24,left*16/9))+'px'};
const sync=()=>{fb.textContent=isFull()?'Exit full screen':'Full screen';fitFrame();dispatchEvent(new Event('resize'))};addEventListener('resize',()=>{if(isFull())requestAnimationFrame(fitFrame)});
fb.addEventListener('click',toggle);document.addEventListener('fullscreenchange',sync);document.addEventListener('webkitfullscreenchange',sync);
addEventListener('keydown',e=>{if(['INPUT','SELECT','TEXTAREA'].includes(document.activeElement.tagName)||e.ctrlKey||e.metaKey||e.altKey)return;if(e.key==='f'||e.key==='F')toggle()});})();</script>
<script>(()=>{// Captions are off by default and every time the page loads; this button turns them on for the current visit only.
const b=document.getElementById('cc');const set=on=>{if(window.NeptuneVideo)NeptuneVideo.captions=on;b.setAttribute('aria-pressed',on);b.textContent=on?'Captions: on':'Captions: off'};
b.addEventListener('click',()=>set(b.getAttribute('aria-pressed')!=='true'));
addEventListener('keydown',e=>{if(['INPUT','SELECT','TEXTAREA'].includes(document.activeElement.tagName)||e.ctrlKey||e.metaKey||e.altKey)return;if(e.key==='c'||e.key==='C')b.click()});})();</script>
<?php pillar_close();community_page_end(); ?>
