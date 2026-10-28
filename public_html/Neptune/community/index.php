<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-owner-only.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis-install.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-video-tags.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-coverage.php';
$settings=community_analysis_settings($pdo,$config);if(empty($settings['enabled'])){http_response_code(404);exit('Community scouting is not enabled.');}
header('Cache-Control: no-store');
$mode=(string)($_GET['view']??'catalog');
if(in_array($mode,['setup','review','public'],true)||isset($_GET['match_id'])){header('Location: '.((int)($_GET['match_id']??0)>0?'scouting-results.php?match_id='.(int)$_GET['match_id']:'?view=catalog'),true,303);exit;}
community_video_tags_schema($pdo);
require_once __DIR__.'/layout.php';community_page_start('Community Tag Scouting',$mode==='results'?'See the robot actions and scoring estimates people have saved.':'Watch a match. Tag a robot’s actions. Estimate its scoring contribution.',$mode==='results'?'results':'catalog');
if($mode==='results'):
$matches=$pdo->query("SELECT m.id,m.title,m.match_key,m.roster_json FROM community_matches m WHERE EXISTS(SELECT 1 FROM community_video_labels l WHERE l.match_id=m.id AND l.source_url=m.source_url AND l.review_state IN ('contributed','verified') AND l.polarity='present') ORDER BY m.match_key DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
?>
<section><h2>Saved robot scouting</h2><p>Open a match to see timestamped tags and scoring estimates for each robot. Contributions appear as soon as they save.</p><p><a class="community-button" href="?view=catalog">Tag another match →</a></p></section>
<?php if(!$matches):?><section><p>No robot tags or scoring estimates have been saved yet.</p></section><?php endif ?>
<?php foreach($matches as $match):?><article><h2><?=e($match['title'])?></h2><p><?=e($match['match_key'])?></p><a class="community-button" href="scouting-results.php?match_id=<?=(int)$match['id']?>">View tags &amp; scores →</a><a href="tag-video.php?match_id=<?=(int)$match['id']?>">Add observations</a></article><?php endforeach ?>
<?php else:
$year=(int)($_GET['season']??gmdate('Y'));$event=(string)($_GET['event']??'');$catalogEvents=[];$catalogMatches=[];$catalogError='';
try{
 require_once dirname(__DIR__,3).'/neptune_secure/community-catalog.php';community_catalog_schema($pdo);
 $catalog=new CommunityCatalog($pdo);$catalogEvents=$catalog->events($year);
 if($event!==''){
  if(!str_starts_with($event,(string)$year))throw new CommunityAnalysisError(422,'Choose an event in the selected season.');
  $catalogMatches=$catalog->matches($event);
 }
}catch(CommunityAnalysisError $ex){$catalogError=$ex->getMessage();}catch(Throwable $ex){error_log('Community catalog: '.get_class($ex));$catalogError='Match data could not be loaded. Try again shortly.';}
?>
<section aria-labelledby="tagging-purpose"><span class="stage-label">WATCH &amp; TAG</span><h2 id="tagging-purpose">Tag one robot with its game actions.</h2><p>Choose a match below, select a robot, and click the actions you see. Neptune loads the matching game actions automatically. You can do just one robot or a few clear moments. <strong>No helper app is needed.</strong> Access is currently limited to the platform owner while Auto Scout is being built.</p><div class="workflow-grid"><div><h3>1. Choose</h3><p>Select a season, event and match. Open Watch &amp; tag, then click a robot’s number.</p></div><div><h3>2. Tag</h3><p>Click the matching action. Pause, rewind or zoom whenever you need. Tags save automatically.</p></div><div><h3>3. Estimate</h3><p>Use Score estimate to enter robot points or move the percentage slider. The other value fills in automatically.</p></div></div><p><a class="community-button" href="#choose-match">Choose a match →</a> <a href="?view=results">View saved tags &amp; scores</a></p><details id="tagging-guide"><summary>Quick tips and keyboard shortcuts</summary><ul><li><strong>Scoring:</strong> click the exact game action once for each observed scoring sequence (for example, Score 10 fuel).</li><li><strong>Score estimate:</strong> estimate the robot’s whole-match points. The percentage uses its alliance’s full final score, including penalties. Leave it blank if you are unsure.</li><li><strong>Take your time:</strong> pause, replay, slow down or zoom up to 5×. Drag or use arrow keys to pan while zoomed.</li><li><strong>Keyboard:</strong> Space plays/pauses, R rewinds, 1–6 changes robot, O opens Score estimate, and U undoes. Each tag shows its own key.</li><li><strong>Skip anything unclear.</strong> You do not need to tag all six robots. Replaying an action does not mean you need to tag it twice.</li></ul></details></section>
<section id="choose-match" aria-labelledby="choose-match-title"><h2 id="choose-match-title">Choose a match · 2025 onward</h2><p>Choose a season and event below, then click <strong>Watch &amp; tag this match</strong>. You can see which robots already have contributions before choosing. More observations are welcome.</p>
<form method="get"><input type="hidden" name="view" value="catalog"><label>Season <select name="season"><?php for($y=2025;$y<=(int)gmdate('Y')+1;$y++):?><option value="<?=$y?>" <?=$year===$y?'selected':''?>><?=$y?></option><?php endfor ?></select></label><button>Load events</button></form>
<?php if($catalogEvents):?><form method="get"><input type="hidden" name="view" value="catalog"><input type="hidden" name="season" value="<?=$year?>"><label>Event <select name="event" required><option value="">Choose an event</option><?php foreach($catalogEvents as $ev):?><option value="<?=e($ev['key'])?>" <?=$event===$ev['key']?'selected':''?>><?=e(($ev['start_date']??'Date TBD').' · '.$ev['name'].' · '.$ev['key'])?></option><?php endforeach ?></select></label><button>Load matches</button></form><?php elseif(!$catalogError):?><p>No events have been published for this season yet.</p><?php endif ?>
<?php if($catalogError):?><p role="alert"><?=e($catalogError)?></p><?php endif ?>
</section>
<?php if($event!==''&&!$catalogMatches&&!$catalogError):?><p>The event has no published match schedule yet. Check back later.</p><?php endif ?>
<?php $statusLabels=['available'=>'Available','video_needed'=>'Video needed','roster_needed'=>'Roster needed','in_progress'=>'Being processed','awaiting_review'=>'Awaiting review','completed'=>'Completed'];foreach($catalogMatches as $r):?><article><h2><?=e($r['title'])?></h2><p><?=e($r['match_key'])?></p><p>Red: <?=e(implode(', ',$r['roster']['red']))?> · Blue: <?=e(implode(', ',$r['roster']['blue']))?></p><?php community_coverage_html($r['coverage']); ?><?php if($r['video_url']):?><a href="<?=e($r['video_url'])?>" target="_blank" rel="noopener noreferrer">Watch match video</a> <a class="helper-download" href="tag-video.php?match_id=<?=(int)$r['id']?>">Watch &amp; tag this match →</a><?php else:?><p>No match video is linked yet.</p><?php endif ?><p><a href="scouting-results.php?match_id=<?=(int)$r['id']?>">View saved tags &amp; scores →</a></p></article><?php endforeach ?>

<?php endif;community_page_end(); ?>
