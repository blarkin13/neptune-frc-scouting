<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
require_once dirname(__DIR__,3).'/neptune_secure/platform-security.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis-install.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-video-tags.php';
require_once dirname(__DIR__,3).'/neptune_secure/robot-detector-jobs.php';
require_once dirname(__DIR__,3).'/neptune_secure/robot-label-frames.php';
require_once dirname(__DIR__,3).'/neptune_secure/youtube-uploader.php';
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
if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['robot_test_video'])){
 try{$mp4=$robotJobs->robotTestVideoPath((string)$_GET['robot_test_video']);}catch(InvalidArgumentException){robot_reply(422,['error'=>'Invalid test']);}
 if(!$mp4)robot_reply(404,['error'=>'Test video not found.']);
 $size=filesize($mp4);$start=0;$end=$size-1;$status=200;
 if(!empty($_SERVER['HTTP_RANGE'])&&preg_match('/^bytes=(\d+)-(\d*)$/D',(string)$_SERVER['HTTP_RANGE'],$m)){
  $start=(int)$m[1];$end=$m[2]!==''?min($size-1,(int)$m[2]):$size-1;$status=206;
  if($start>$end||$start>=$size){http_response_code(416);header("Content-Range: bytes */$size");exit;}
 }
 http_response_code($status);header('Content-Type: video/mp4');header('Accept-Ranges: bytes');header('X-Content-Type-Options: nosniff');
 header('Content-Length: '.($end-$start+1));if($status===206)header("Content-Range: bytes $start-$end/$size");
 $fh=fopen($mp4,'rb');if($fh){fseek($fh,$start);$left=$end-$start+1;while($left>0&&!feof($fh)){$chunk=fread($fh,min(65536,$left));if($chunk===false||$chunk==='')break;echo $chunk;$left-=strlen($chunk);}fclose($fh);}
 exit;
}
if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['detector_status'])){
 $cur=$robotJobs->current('skip-weights');$yt=(new YouTubeUploader(dirname(__DIR__,3).'/neptune_secure'))->status();
 $computers=[];
 try{
  $cq=$pdo->prepare('SELECT id,name,expires_at,revoked,last_seen FROM community_training_computers WHERE user_id=? AND revoked=0 ORDER BY last_seen DESC,created_at DESC');
  $cq->execute([(int)$u['id']]);
  foreach($cq->fetchAll(PDO::FETCH_ASSOC) as $c){
   $cid=(string)$c['id'];$st=$robotJobs->status($cid)?:[];
   $online=((int)$c['expires_at']>time())&&((int)$c['last_seen']>time()-75||((int)($st['at']??0)>time()-75));
   $computers[]=['id'=>$cid,'name'=>(string)($c['name']?:'Computer'),'online'=>$online,'busy'=>!empty($st['busy']),'version'=>(string)($st['version']??'')];
  }
 }catch(Throwable $e){}
 robot_reply(200,['requests'=>array_map(fn($r)=>array_diff_key($r,['lease'=>1]),array_slice($robotJobs->requests(),0,12)),'current'=>$cur['detector'],'robot_tests'=>$robotJobs->robotTests(),'youtube'=>$yt,'computers'=>$computers]);
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
  if(($p['action']??'')==='queue_test_robot'){
   $mid=(int)($p['match_id']??0);if($mid<=0)throw new RuntimeException('Choose a match to test first.');
   $comp=trim((string)($p['computer']??''));
   if($comp!==''){
    $cq=$pdo->prepare('SELECT id FROM community_training_computers WHERE id=? AND user_id=? AND revoked=0');
    $cq->execute([$comp,(int)$u['id']]);
    if(!$cq->fetchColumn())throw new RuntimeException('Choose one of your connected helper computers.');
   }
   $q=$pdo->prepare('SELECT id,match_key,title,roster_json,source_url FROM community_matches WHERE id=?');$q->execute([$mid]);$m=$q->fetch(PDO::FETCH_ASSOC);
   if(!$m||!($vid=CommunityVideoTags::videoId((string)$m['source_url'])))throw new RuntimeException('This match does not have a linked YouTube video.');
   $roster=json_decode((string)($m['roster_json']??''),true);
   robot_reply(200,['request'=>$robotJobs->queue('test_robot',['match_id'=>(int)$m['id'],'match_key'=>(string)$m['match_key'],'title'=>(string)($m['title']?:$m['match_key']),'video_id'=>$vid,'roster'=>is_array($roster)?$roster:null,'computer'=>$comp],(int)$u['id'])]);
  }
  if(($p['action']??'')==='upload_robot_test_youtube'){
   $rec=$robotJobs->uploadRobotTestToYouTube((string)($p['request_id']??''),(string)($p['title']??''),(string)($p['description']??''),new YouTubeUploader(dirname(__DIR__,3).'/neptune_secure'));
   robot_reply(200,['ok'=>true,'test'=>$rec]);
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
<div class="note rl-detector"><b>Robot detector</b><p>Prepare frames: your updated helper supplies clear source images and bumper guesses. When preparation finishes, refresh this page to label without YouTube captions. Play is only for watching context.</p><button id="prepare" class="save rl-secondary"<?=$selected?'':' disabled'?>>Prepare frames for this match</button><div id="requests" class="rl-requests"></div><div id="current" class="rl-current"></div></div><div class="note"><b>What makes good labels</b><p>Every bumper you can see, red or blue, even partly hidden or far away. Box just the bumper, not the whole robot. Skip frames that aren't live match footage.</p></div></aside></div>

<section class="tt-card rl-test-card" id="robot-test-section">
 <span class="stage-label">TEST CONSTANT ROBOT DETECTION</span>
 <h2>Test a match</h2>
 <p>Runs the current robot detector, tracker, bumper OCR, and constant identity on the match video—drawing colored bounding boxes and bumper reads on the original view (green = OCR verified, cyan = constant/inferred identity, amber = unresolved) alongside a top-down homography field view.</p>
 <div class="rl-test-bar">
  <div class="match-picker-v2" id="robot-test-picker" data-match-picker data-endpoint="robot-labels.php" data-select-only="1" data-selected="<?=e($selected['match_key']??'')?>"></div>
  <label class="rl-test-helper"><span>Helper</span><select id="robot-test-computer"><option value="">First available helper</option></select></label>
  <button type="button" id="robot-test-btn" class="primary save" disabled>Test</button>
 </div>
 <p id="robot-test-status" class="training-note" role="status" aria-live="polite">Choose a match above and press Test.</p>
 <div id="robot-test-viewer" class="rl-test-viewer" hidden>
  <div class="rl-test-head">
   <label>Saved match tests <select id="robot-test-select"></select></label>
   <span id="robot-test-summary" class="training-note"></span>
  </div>
  <div class="rl-test-grid">
   <div class="rl-test-player-wrap">
    <video id="robot-test-video" class="rl-test-video" controls preload="metadata" playsinline></video>
   </div>
   <div class="rl-test-yt">
    <h3>Upload to YouTube</h3>
    <p class="training-note">Review and edit the pre-filled title and description before uploading to YouTube.</p>
    <label class="rl-test-field"><span>Title</span><input type="text" id="robot-test-title" maxlength="100"></label>
    <label class="rl-test-field"><span>Description</span><textarea id="robot-test-desc" rows="7" maxlength="4900"></textarea></label>
    <div class="rl-test-yt-actions">
     <button type="button" id="robot-test-upload" class="primary save">Upload to YouTube</button>
     <span id="robot-test-yt-status" class="training-note" role="status"></span>
    </div>
   </div>
  </div>
 </div>
</section>
</div>
<script src="../community/assets/match-picker/match-picker.js?v=2-multi"></script><script src="../community/assets/field-regions/youtube-source.js?v=5-captions-toggle"></script><script src="../community/assets/robot-labels/<?= $sourceMode?'robot-labels-stills.js?v=8-test':'robot-labels.js?v=4-test' ?>"></script>
<style>
.rl-test-card{margin-top:20px;padding:18px;border:1px solid #1e2b3c;border-radius:12px;background:#0b121c}
.rl-test-card h2{margin:4px 0 8px}
.rl-test-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:12px 0}
.rl-test-bar .match-picker-v2{flex:1 1 520px}
.rl-test-helper{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#9aa7b8;font-weight:600;min-width:210px}
.rl-test-helper select{background:#070d15;color:#eaf2ff;border:1px solid #28384f;border-radius:6px;padding:8px 10px;font:13px/1.4 system-ui,sans-serif}
.rl-test-bar #robot-test-btn{width:auto;min-width:120px;margin:0;padding:10px 20px}
.rl-test-viewer{margin-top:14px;padding-top:14px;border-top:1px solid #1e2b3c}
.rl-test-head{display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between;margin-bottom:12px}
.rl-test-head select{margin-left:8px;max-width:360px}
.rl-test-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(300px,1fr);gap:18px;align-items:start}
@media(max-width:960px){.rl-test-grid{grid-template-columns:1fr}}
.rl-test-video{width:100%;max-height:640px;background:#000;border-radius:8px;border:1px solid #1e2b3c;display:block}
.rl-test-yt{background:#0f1826;border:1px solid #1e2b3c;border-radius:10px;padding:14px}
.rl-test-yt h3{margin:0 0 6px;font-size:16px}
.rl-test-field{display:block;margin:10px 0}
.rl-test-field span{display:block;font-size:12px;color:#9aa7b8;margin-bottom:4px;font-weight:600}
.rl-test-field input,.rl-test-field textarea{width:100%;box-sizing:border-box;background:#070d15;color:#eaf2ff;border:1px solid #28384f;border-radius:6px;padding:8px 10px;font:13px/1.45 system-ui,sans-serif}
.rl-test-field textarea{resize:vertical;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
.rl-test-yt-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:12px}
.rl-test-yt-actions button{width:auto;margin:0;padding:9px 16px}
</style>
<script>
(()=>{
 const app=document.getElementById('robot-labels-app'),picker=document.getElementById('robot-test-picker'),
       compSel=document.getElementById('robot-test-computer'),
       btn=document.getElementById('robot-test-btn'),status=document.getElementById('robot-test-status'),
       viewer=document.getElementById('robot-test-viewer'),sel=document.getElementById('robot-test-select'),
       vid=document.getElementById('robot-test-video'),sum=document.getElementById('robot-test-summary'),
       titleIn=document.getElementById('robot-test-title'),descIn=document.getElementById('robot-test-desc'),
       upBtn=document.getElementById('robot-test-upload'),ytStatus=document.getElementById('robot-test-yt-status');
 if(!app||!picker||!btn)return;
 const endpoint=location.pathname.endsWith('robot-labels-preview.php')?'robot-labels-preview.php':'robot-labels.php';
 let tests=[],computers=[],currentId='',edited=false,ytConnected=false;
 picker.addEventListener('matchselect',e=>{
  const d=e.detail||{};
  btn.disabled=!d.id;
  if(d.id)status.textContent=`Ready to test ${d.title||d.match_key} with the current robot detector, bumper OCR, and homography map.`;
 });
 titleIn.addEventListener('input',()=>{edited=true});
 descIn.addEventListener('input',()=>{edited=true});
 function renderTest(id,forceFields){
  const t=tests.find(x=>x.id===id)||tests[0];
  if(!t){viewer.hidden=true;return;}
  viewer.hidden=false;
  const changed=currentId!==t.id;
  currentId=t.id;
  sel.value=t.id;
  if(changed){
   vid.src=t.has_video?`${endpoint}?robot_test_video=${encodeURIComponent(t.id)}`:'';
  }
  if(changed||forceFields||!edited){
   titleIn.value=t.title||`Robot detection test - ${t.match_key||''}`;
   descIn.value=t.description||'';
   edited=false;
  }
  const s=t.summary||{};
  const parts=[
   s.verified_points!=null?`OCR verified: ${s.verified_points}`:'',
   s.constant_points!=null?`Constant ID: ${s.constant_points}`:'',
   s.inferred_points!=null?`Inferred: ${s.inferred_points}`:'',
   s.unknown_points!=null?`Unresolved: ${s.unknown_points}`:'',
   s.homography_status?`Top-down map: ${s.homography_status}`:''
  ].filter(Boolean);
  sum.textContent=parts.join(' · ');
  if(t.youtube_id){
   ytStatus.innerHTML=`Uploaded: <a href="https://youtu.be/${encodeURIComponent(t.youtube_id)}" target="_blank" rel="noopener">▶ Watch on YouTube</a>${t.privacy==='private'?' <small>(private)</small>':' <small>(unlisted)</small>'}`;
   upBtn.textContent='Upload again to YouTube';
  }else if(!ytConnected){
   ytStatus.innerHTML='<a href="/autoscout/youtube.php">Connect YouTube</a> to upload this test video.';
   upBtn.textContent='Upload to YouTube';
  }else{
   ytStatus.textContent='Ready to upload to YouTube as unlisted.';
   upBtn.textContent='Upload to YouTube';
  }
  upBtn.disabled=!t.has_video;
 }
 async function refreshTests(){
  try{
   const r=await fetch(endpoint+'?detector_status=1');if(!r.ok)return;
   const d=await r.json();
   ytConnected=!!(d.youtube&&d.youtube.connected);
   if(compSel&&Array.isArray(d.computers)){
    computers=d.computers;
    const prevComp=compSel.value;
    compSel.replaceChildren(
     new Option('First available helper',''),
     ...computers.map(c=>new Option(`${c.name} (${c.online?(c.busy?'online · busy':'online · idle'):'offline'})`,c.id))
    );
    if(prevComp&&computers.some(c=>c.id===prevComp))compSel.value=prevComp;
   }
   const active=(d.requests||[]).find(q=>q.kind==='test_robot'&&['queued','claimed'].includes(q.state));
   if(active){
    const targetName=active.target&&(computers.find(c=>c.id===active.target)?.name||'chosen helper');
    status.textContent=`Test match (${active.match_key||''}): ${active.state==='queued'?(targetName?`waiting for ${targetName}`:'waiting for a connected helper'):(active.message||'running on helper')}`;
   }
   const list=d.robot_tests||[];
   const prevNewest=tests[0]?.id;
   tests=list;
   if(!list.length){viewer.hidden=true;return;}
   sel.replaceChildren(...list.map(t=>{
    const dstr=t.saved_at?new Date(t.saved_at*1000).toLocaleString(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}):'';
    return new Option(`${t.match_title||t.match_key||t.id} (${dstr})${t.youtube_id?' · YouTube ✓':''}`,t.id);
   }));
   if(prevNewest&&list[0].id!==prevNewest){currentId='';edited=false;status.textContent=`Finished testing ${list[0].match_title||list[0].match_key}. Watch the video below or upload to YouTube.`;}
   renderTest(currentId&&list.some(x=>x.id===currentId)?currentId:list[0].id,false);
  }catch(e){}
 }
 sel.addEventListener('change',()=>{edited=false;renderTest(sel.value,true)});
 btn.addEventListener('click',async()=>{
  const mid=Number(picker.dataset.matchId||0);if(!mid)return;
  const comp=compSel?compSel.value:'';
  btn.disabled=true;status.textContent='Queuing robot detection match test for your helper…';
  try{
   const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-Robot-Labels-CSRF':app.dataset.csrf},body:JSON.stringify({action:'queue_test_robot',match_id:mid,computer:comp})});
   const d=await r.json();if(!r.ok)throw Error(d.error||'Could not queue match test');
   const who=comp?(computers.find(c=>c.id===comp)?.name||'your chosen helper'):'the first available helper';
   status.textContent=`Match test queued (${d.request?.match_key||''}) for ${who}. It will track robots, read bumpers, map top-down homography, and send the video here.`;
   await refreshTests();
  }catch(e){status.textContent=e.message}finally{btn.disabled=!Number(picker.dataset.matchId||0)}
 });
 upBtn.addEventListener('click',async()=>{
  if(!currentId)return;
  upBtn.disabled=true;ytStatus.textContent='Uploading video to YouTube…';
  try{
   const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-Robot-Labels-CSRF':app.dataset.csrf},body:JSON.stringify({action:'upload_robot_test_youtube',request_id:currentId,title:titleIn.value,description:descIn.value})});
   const d=await r.json();if(!r.ok)throw Error(d.error||'YouTube upload failed');
   const idx=tests.findIndex(x=>x.id===currentId);if(idx>=0&&d.test)tests[idx]=d.test;
   edited=false;renderTest(currentId,true);
  }catch(e){ytStatus.textContent=e.message;upBtn.disabled=false}
 });
 refreshTests();setInterval(refreshTests,10000);
})();
</script>
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
