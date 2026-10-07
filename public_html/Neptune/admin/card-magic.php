<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_robot_recall.php';
require_once dirname(__DIR__).'/_event_selection.php';
$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];

function neptune_magic_selected_tricks(mixed $raw): array {
    $allowed=['vanish','force','1089','37'];
    if(is_array($raw))$values=$raw;
    else $values=preg_split('/\s*,\s*/',trim((string)$raw),-1,PREG_SPLIT_NO_EMPTY)?:[];
    $values=array_values(array_unique(array_filter(array_map('strval',$values),fn($v)=>in_array($v,$allowed,true))));
    return $values?:$allowed;
}
function neptune_magic_pool_rows(array $pool): array {
    $rows=[];
    foreach($pool as $row){
        $team=(int)($row['team']??0);if($team<=0)continue;
        $path=trim((string)($row['logo_path']??''));
        $rows[]=[
            'team'=>$team,
            'nickname'=>trim((string)($row['nickname']??'')),
            'logo'=>$path!==''?base_url(ltrim($path,'/')):'',
        ];
    }
    return $rows;
}

$error='';
try{robot_recall_logo_helper();}catch(Throwable $e){$error=$e->getMessage();}
$events=$error===''?neptune_event_selector_rows($pdo,$org,0,null):[];
$defaultEventId=0;
foreach($events as $ev){if((int)($ev['is_current']??0)===1){$defaultEventId=(int)$ev['id'];break;}}
if($defaultEventId<=0&&$events)$defaultEventId=(int)$events[0]['id'];

$source=(string)($_GET['source']??'event');
if(!in_array($source,['event','all_cached'],true))$source='event';
$eventId=(int)($_GET['event_id']??$defaultEventId);
$season=(int)($_GET['season_year']??date('Y'));
$run=!empty($_GET['run']);
$cycle=!empty($_GET['cycle']);
$selectedTricks=neptune_magic_selected_tricks($_GET['tricks']??null);
$pool=[];$poolLabel='';

if($run&&$error===''){
    try{
        if($source==='event'&&$eventId>0){
            $result=robot_recall_event_pool($pdo,$org,$eventId);
            $season=(int)$result['season'];
            $pool=neptune_magic_pool_rows($result['pool']);
            $poolLabel=(string)($result['event']['name']??'Current event');
            if(count($pool)<18){
                $extra=neptune_magic_pool_rows(robot_recall_all_cached_pool($pdo,$org,$season)['pool']);
                $seen=array_fill_keys(array_column($pool,'team'),true);
                foreach($extra as $row){if(!isset($seen[$row['team']])){$pool[]=$row;$seen[$row['team']]=true;}if(count($pool)>=40)break;}
            }
        }else{
            $result=robot_recall_all_cached_pool($pdo,$org,$season);
            $pool=neptune_magic_pool_rows($result['pool']);
            $poolLabel=$season.' cached teams';
        }
        if(count($pool)<12)throw new RuntimeException('Card Magic needs at least 12 cached/event teams. Open Robot Recall after logos have been cached, then try again.');
    }catch(Throwable $e){$error=$e->getMessage();}
}

$pageTitle='Card Magic';
$moduleName='SATURN';
$bodyClass=trim(($bodyClass??'').' pit-games-page card-magic-page'.($run?' card-magic-running':''));
$hideChrome=$run;
$pageStyles=['assets/css/pit-games.css','assets/css/card-magic.css'];
include dirname(__DIR__).'/partials_header.php';
?>
<?php if(!$run||$error):?>
<section class="module-page pit-games">
  <header class="module-page-header">
    <div><div class="module-code">SATURN · PIT GAMES · CARD MAGIC</div><h1>Card Magic</h1><p>Hands-free FRC card tricks designed to play like a video. Spectators never enter their choice.</p></div>
    <div class="toolbar"><a class="btn secondary" href="<?=e(base_url('admin/pit-games.php'))?>"><i class="fa-solid fa-arrow-left"></i> Pit Games</a></div>
  </header>
  <?php if($error):?><div class="notice bad"><i class="fa-solid fa-triangle-exclamation"></i> <?=e($error)?></div><?php endif;?>
  <section class="card magic-setup">
    <div class="magic-setup-copy"><span class="pit-game-eyebrow">PRESENTATION SETUP</span><h2>Choose the deck and tricks</h2><p class="muted">Each trick advances automatically once started. Cycle mode moves from one selected trick to the next and loops continuously.</p></div>
    <form method="get" class="magic-setup-form">
      <input type="hidden" name="run" value="1">
      <fieldset class="magic-field"><legend>Team deck</legend>
        <label class="magic-choice"><input type="radio" name="source" value="event" <?=$source==='event'?'checked':''?>><span><b>Current / selected event</b><small>Use event teams first and fill from the cached logo pool if needed.</small></span></label>
        <label class="magic-choice"><input type="radio" name="source" value="all_cached" <?=$source==='all_cached'?'checked':''?>><span><b>All cached teams</b><small>Shuffle from every team logo currently stored in Neptune.</small></span></label>
      </fieldset>
      <label class="magic-field"><span>Event</span><select name="event_id"><?=neptune_event_options_html($events,$eventId)?></select></label>
      <label class="magic-field"><span>Season for cached team names</span><input type="number" min="1992" max="<?=date('Y')+1?>" name="season_year" value="<?=$season?>"></label>

      <fieldset class="magic-field magic-tricks"><legend>Tricks</legend>
        <label class="magic-choice"><input type="checkbox" name="tricks[]" value="vanish" checked><span><b>Vanishing Team</b><small>Classic disappearing-card illusion using six FRC teams.</small></span></label>
        <label class="magic-choice"><input type="checkbox" name="tricks[]" value="force" checked><span><b>Neptune Number Force</b><small>A free mental number lands on Neptune's locked prediction.</small></span></label>
        <label class="magic-choice"><input type="checkbox" name="tricks[]" value="1089" checked><span><b>1089 Prediction</b><small>A freely created three-digit number collapses to a predicted team.</small></span></label>
        <label class="magic-choice"><input type="checkbox" name="tricks[]" value="37" checked><span><b>The 37 Engine</b><small>A repeated-digit calculation forces card number ten.</small></span></label>
      </fieldset>
      <div class="toolbar magic-launch">
        <button type="submit" name="cycle" value="0"><i class="fa-solid fa-wand-magic-sparkles"></i> Play Selected</button>
        <button type="submit" name="cycle" value="1"><i class="fa-solid fa-repeat"></i> Cycle Selected Tricks</button>
      </div>
    </form>
  </section>
</section>
<?php else:?>
<div class="magic-stage" id="magicStage">
  <header class="magic-stage-top">
    <div class="magic-brand"><i class="fa-solid fa-compass"></i><span><b>NEPTUNE</b><small>PIT MAGIC</small></span></div>
    <div class="magic-source"><span id="magicTrickName">Card Magic</span><small><?=e($poolLabel)?></small></div>
    <div class="magic-stage-actions">
      <button type="button" class="magic-icon-btn" id="magicPause" title="Pause"><i class="fa-solid fa-pause"></i></button>
      <button type="button" class="magic-icon-btn" id="magicNextStep" title="Next step"><i class="fa-solid fa-forward-step"></i></button>
      <button type="button" class="magic-icon-btn" id="magicNextTrick" title="Next trick"><i class="fa-solid fa-forward-fast"></i></button>
      <button type="button" class="magic-icon-btn" id="magicFullscreen" title="Fullscreen"><i class="fa-solid fa-expand"></i></button>
      <a class="magic-icon-btn" href="<?=e(base_url('admin/pit-games.php#card-magic'))?>" title="Exit to Pit Games"><i class="fa-solid fa-xmark"></i></a>
    </div>
  </header>
  <main class="magic-content">
    <div class="magic-kicker" id="magicKicker">NEPTUNE MAGIC</div>
    <h1 id="magicHeadline">Think of a team.</h1>
    <p id="magicInstruction">Do not touch the screen. Do not tell anyone.</p>
    <div class="magic-board" id="magicBoard"></div>
    <div class="magic-note" id="magicNote"></div>
  </main>
  <footer class="magic-stage-footer">
    <div class="magic-cycle-state"><?=$cycle?'AUTO CYCLE':'SINGLE TRICK'?> · <span id="magicPosition">1 / <?=count($selectedTricks)?></span></div>
    <div class="magic-progress"><span id="magicProgress"></span></div>
  </footer>
</div>
<script>
window.NEPTUNE_CARD_MAGIC=<?=json_encode([
    'pool'=>$pool,
    'tricks'=>$selectedTricks,
    'cycle'=>$cycle,
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
</script>
<script src="<?=e(base_url('assets/js/card-magic.js'))?>?v=<?=@filemtime(dirname(__DIR__).'/assets/js/card-magic.js')?:1?>"></script>
<?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
