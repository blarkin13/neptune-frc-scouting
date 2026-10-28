<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-owner-only.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis-install.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-video-tags.php';
$settings=community_analysis_settings($pdo,$config);
if(empty($settings['enabled'])){http_response_code(404);exit('Community analysis is not enabled.');}
header('Cache-Control: no-store');header('Referrer-Policy: strict-origin-when-cross-origin');
// Public contributors need no account. This cookie identifies only their own browser's labels.
$cookie=$_COOKIE['neptune_video_contributor']??'';
if(!is_string($cookie)||!preg_match('/^[a-f0-9]{64}$/D',$cookie)){
    $cookie=bin2hex(random_bytes(32));setcookie('neptune_video_contributor',$cookie,['expires'=>time()+365*86400,'path'=>'/community/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
}
$actor=hash('sha256',$cookie);$user=current_user();
$ip=hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??''),(string)$settings['secret']);
require_once dirname(__DIR__,3).'/neptune_secure/community-coverage.php';
$tags=new CommunityVideoTags($pdo);$error='';$match=null;$rows=[];
try{
    community_video_tags_schema($pdo);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        header('Content-Type: application/json');
        if(!is_string($_POST['csrf']??null)||!hash_equals(csrf_token(),$_POST['csrf']))throw new CommunityAnalysisError(419,'Your page session expired. Reload before retrying; unsaved actions stay on this page until you close it.');
        $action=$_POST['action']??'';
        if($action==='save'){
            $in=json_decode((string)($_POST['label']??''),true,32,JSON_THROW_ON_ERROR);
            if(!is_array($in))throw new CommunityAnalysisError(422,'Invalid label.');
            $id=$tags->save($in,(int)($_POST['match_id']??0),$actor,isset($user['id'])?(int)$user['id']:null,$ip);
        }elseif($action==='undo'){$id=(string)($_POST['id']??'');$tags->withdraw($id,$actor);}
        else throw new CommunityAnalysisError(422,'Unknown action.');
        echo json_encode(['ok'=>true,'id'=>$id]);exit;
    }
    $match=$tags->match((int)($_GET['match_id']??0));$gameControls=community_video_game_controls($match);$rows=$tags->mine((int)$match['id'],$actor);
}catch(Throwable $ex){
    $status=$ex instanceof CommunityAnalysisError?$ex->httpStatus:503;http_response_code($status);
    $error=$ex instanceof CommunityAnalysisError?$ex->getMessage():'Video tagging is temporarily unavailable. Try again shortly.';
    if($_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>$error]);exit;}
}
require_once __DIR__.'/layout.php';
community_page_start('Watch and tag a match','Add human robot tags and scoring evidence for training. A few clear observations are useful; no helper app is needed.','catalog');
?>
<link rel="stylesheet" href="assets/video-tags.css?v=12-compact-dock">
<?php if($error):?><section role="alert"><?=e($error)?></section><?php else: ?>
<div class="tag-studio" id="tagStudio">
<div class="studio-heading"><div><a href="./?view=catalog">← Matches</a><h1><?=e($match['title'])?></h1><small class="studio-purpose">Human training examples · <?=e($gameControls['game']['name'])?> · One click per observed action</small></div><div class="studio-actions"><div class="studio-contributions" aria-label="Previously saved contributions"><?php $prior=community_match_coverage($pdo,[$match]);community_coverage_html($prior[(int)$match['id']]); ?></div><a href="training-labels.php">Training data</a><button type="button" id="focusMode">Full screen</button></div></div>
<div class="tag-player" id="videoViewport"><div class="video-surface" id="videoSurface"><div id="tagPlayer"></div></div><div class="zoom-pan" id="zoomPan" tabindex="0" hidden aria-label="Zoomed video: drag or use arrow keys to pan. Hold Shift for faster panning."></div></div>
<div class="studio-transport">
<div class="transport-group"><button type="button" id="rewind" title="Back 5 seconds (R)">−5s</button><button type="button" id="playPause" title="Play / pause (Space)">Play / pause</button><button type="button" id="forward" title="Forward 5 seconds">+5s</button><output id="videoTime">Loading video…</output></div>
<div class="transport-group"><label>Speed <select id="speed"><option value="0.5">0.5×</option><option value="0.75">0.75×</option><option value="1" selected>1×</option><option value="1.5">1.5×</option></select></label><label>Zoom <select id="videoZoom" title="Zoom the video, then use arrow keys to pan; Shift + arrows moves faster"><option value="1">1×</option><option value="1.5">1.5×</option><option value="2">2×</option><option value="3">3×</option><option value="4">4×</option><option value="5">5×</option></select></label><label>View <select id="videoView"><option value="center">Center</option><option value="top">Top</option><option value="left">Left</option><option value="right">Right</option><option value="bottom-left">Bottom left</option><option value="bottom-right">Bottom right</option></select></label><button id="resetZoom" type="button">Reset view</button></div></div>
<div class="studio-dock">
<div class="robot-row"><strong id="selectedRobot">Choose robot</strong><div id="robotButtons" class="robot-buttons" role="group" aria-label="Robot to tag"><?php $robotIndex=0;foreach(['red','blue'] as $alliance):foreach($match['roster'][$alliance] as $team):$robotIndex++;?><button type="button" class="robot-<?=$alliance?>" data-team="<?=$team?>" aria-pressed="false" title="<?=ucfirst($alliance)?> robot <?=$team?> · keyboard <?=$robotIndex?>"><?=$team?> <kbd><?=$robotIndex?></kbd></button><?php endforeach;endforeach ?></div><button type="button" id="addScoring" title="Estimate robot points and alliance share (O)" disabled>Score estimate <kbd>O</kbd></button><button type="button" id="undoLast" title="Undo last saved tag (U)">Undo last</button><button type="button" id="customKeys" class="custom-keys-toggle" aria-pressed="false" title="Customize action keyboard shortcuts">Custom keys</button></div>

<div class="all-tag-groups" data-group-count="<?=count($gameControls['groups'])?>" aria-label="Match game actions"><?php foreach($gameControls['groups'] as $group=>$controls):?><div class="tag-control-group" data-group="<?=e(strtolower($group))?>"><h2><?=e($group)?></h2><div class="tag-group-buttons"><?php foreach($controls as $key=>$control):?><button type="button" class="video-tag-button" data-tag="<?=e($key)?>" data-shortcut="<?=e(strtolower($control[1]))?>" data-caption="<?=e($control[0])?>" aria-keyshortcuts="<?=e($control[1])?>" title="<?=e($group.' · '.$control[0].' ('.$control[1].')')?>" disabled><span><?=e($control[0])?></span><kbd><?=e($control[1])?></kbd></button><?php endforeach ?></div></div><?php endforeach ?></div>
<div class="studio-status"><p id="tagStatus" role="status" aria-live="polite">Choose a robot. Tags save immediately—no review queue.</p><button id="cancelInterval" type="button" hidden>Cancel duration</button><details class="tag-options"><summary>Options &amp; help</summary><div class="options-panel"><label>Evidence <select id="certainty"><option value="observed">Clearly observed</option><option value="uncertain">Uncertain</option></select></label><button type="button" id="resetCustomKeys" class="custom-keys-reset">Reset action keys to game defaults</button><input type="checkbox" id="trainingNegative" hidden aria-hidden="true"><label>Optional public note <input id="tagNote" maxlength="1000" placeholder="What was visible?"></label><p>Each game action button saves one timestamped observation. Fuel and other scoring amounts come from the chosen game action (for example Score 25 fuel). Space: play/pause · R: back 5s · 1–6: robot · Every tag shows its key. Use Custom keys to select an action and press a replacement key; your choices stay in this browser for this published game revision. U: undo. Click outside the video to use shortcuts. At zoom above 1×, drag or use arrow keys to pan. Hold Shift for faster movement. Zoom supports up to 5×. Your labels are available immediately as human contributions; Neptune manages training centrally. Use Score estimate (O) for whole-match robot points and percentage of the full final alliance score. Specific scoring sequence counts are available inside its optional details. These are partial human observations, not official per-robot totals. Tag the actions you see. Missing tags stay unknown; you do not need to mark times when an action is not happening.</p></div></details></div>
<p id="videoError" role="alert"></p>
</div>
<div id="shareEditor" class="scoring-editor share-editor" role="dialog" aria-labelledby="shareTitle" hidden><form id="shareForm">
<h2 id="shareTitle">Robot scoring contribution</h2><p id="shareRobot"></p><p id="scoringTotals" class="scoring-totals" aria-live="polite"></p><p id="shareAlliance" class="score-preview"></p>
<p class="scoring-help">Move the slider or enter robot points; the other field updates automatically. The full alliance score includes penalties.</p>
<label for="sharePercent">Share of alliance score <output id="sharePercentText">No estimate yet</output></label><input id="sharePercent" type="range" min="0" max="100" step="0.1" value="0">
<label for="sharePoints">Robot score (points)</label><input id="sharePoints" type="number" min="0" max="100000" step="1" required inputmode="numeric" placeholder="Enter points or move the slider">
<label for="shareMethod">Evidence</label><select id="shareMethod"><option value="estimated">Estimated contribution</option><option value="counted">Counted from complete robot scouting</option></select>
<label for="shareNote">Optional public note</label><input id="shareNote" maxlength="1000" placeholder="What supports this contribution?">
<p id="shareSummary" role="status">No estimate saved.</p><p id="shareError" role="alert"></p>
<p class="scoring-help">Whole-match human contribution, not an official robot score. Remaining points stay unassigned; sequence counts are separate.</p>
<div class="scoring-time-controls"><button type="submit">Save contribution</button><button type="button" id="shareCancel">Cancel</button></div>
<details><summary>Count a specific scoring sequence instead</summary><p class="scoring-help">Use successful piece counts or ranges tied to video time as separate evidence.</p><button type="button" id="openSequence">Count a scoring sequence</button></details>
</form></div>
<div id="scoringEditor" class="scoring-editor" role="dialog" aria-labelledby="scoringTitle" hidden>
<form id="scoringForm"><h2 id="scoringTitle">Scoring contribution</h2><p id="scoringRobot"></p>
<p class="scoring-help">Record only this sequence or outcome. Use Amount in history to add details to an existing tag. Count successful scores, not attempts or balls fired.</p>
<div class="scoring-fields"><label>Scoring type<select id="scoreComponent" required></select></label><label>Match period<select id="scorePhase"><option value="unknown">Not sure</option><option value="auto">Autonomous</option><option value="teleop">Teleop / endgame</option></select></label>
<label id="hubField">Hub state<select id="scoreHub"><option value="unknown">Not sure</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
<label>Amount evidence<select id="scoreMethod"><option value="counted">Counted — exact successful count</option><option value="estimated">Estimated — give a range</option><option value="unknown">Amount unknown</option></select></label>
<label id="countMinField"><span id="countMinCaption">Successful count</span><input type="number" id="scoreMin" min="0" max="1000" step="1" inputmode="numeric"></label><label id="countMaxField" hidden>At most<input type="number" id="scoreMax" min="0" max="1000" step="1" inputmode="numeric"></label>
<label>Video start<input id="scoreStart" placeholder="0:00.0" required></label><label>Video end<input id="scoreEnd" placeholder="0:00.0" required></label></div>
<div class="scoring-time-controls"><button type="button" id="scorePlay">Play / pause video</button><button type="button" id="scoreSetEnd">Use current time as end</button></div>
<label>Optional public note<input id="scoreNote" maxlength="1000" placeholder="What could you see clearly?"></label>
<p id="scorePreview" class="score-preview" role="status"></p><p id="scoreError" role="alert"></p>
<p class="scoring-help">Points are calculated from the selected game rules. Unknown amounts stay unknown. This is your contribution for this footage, not the robot’s complete match total. Other people’s entries are not added to your total.</p>
<div class="scoring-time-controls"><button type="submit">Save scoring contribution</button><button type="button" id="scoreCancel">Cancel</button></div></form></div>
<details class="studio-history"><summary>Your saved tags &amp; history</summary><p>Your human tags and scoring contributions save immediately; they do not update a model by themselves. Training and evaluation happen separately. Use Add amount or Edit amount on a Scoring entry to keep a correction without counting both versions. Retry any unsaved actions before closing the page. Undo withdraws a label and removes it from future dataset downloads. Your anonymous undo history belongs to this browser.</p><ul id="recentLabels" class="tag-history"></ul></details>
</div>
<script type="application/json" id="tagConfig"><?=json_encode(['match_id'=>(int)$match['id'],'roster'=>$match['roster'],'video_id'=>$match['video_id'],'alliance_totals'=>community_scoring_alliance_totals($match),'scoring_rules'=>community_scoring_profile((int)substr($match['match_key'],0,4)),'csrf'=>csrf_token(),'tags'=>community_video_tag_catalog($match),'game'=>['name'=>$gameControls['game']['name'],'season'=>$gameControls['game']['year'],'version'=>$gameControls['game']['version']],'rows'=>$rows],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?></script>
<script src="assets/video-tags.js?v=13-custom-keys" defer></script><script src="assets/score-moments.js?v=1" defer></script>
<?php endif;community_page_end(); ?>
