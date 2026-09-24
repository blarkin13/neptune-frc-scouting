<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
require_once __DIR__.'/_augur_prediction_model.php';
$u=require_login();
$org=(int)$u['organization_id'];
$events=neptune_event_list($pdo,$org);
$eventId=(int)($_GET['event_id']??($events[0]['id']??0));
$stats=$eventId?neptune_event_robot_stats($pdo,$org,$eventId):[];

$event=$eventId?alliance_event($pdo,$org,$eventId):null;
$teamNumbers=array_values(array_map('intval',array_keys($stats)));
$epaMap=[];
$augurMap=[];

if($event&&$teamNumbers){
    try{
        // Use the same organization-private AUGUR model as Match Strategy and
        // Alliance Selection. Disable live TBA rankings so Robot Cards reads
        // only Neptune's local match/scouting/EPA data during page load.
        $settings=augur_prediction_settings();
        if(alliance_schema_ready($pdo)){
            $workspace=alliance_load_workspace($pdo,$org,$eventId,false);
            $settings=augur_prediction_settings($workspace['workspace']['settings']['matchup_model']??[]);
        }

        if(count($teamNumbers)>=2){
            $cut=(int)ceil(count($teamNumbers)/2);
            $sideA=array_slice($teamNumbers,0,$cut);
            $sideB=array_slice($teamNumbers,$cut);
            if(!$sideB)$sideB=[$sideA[0]];

            $prediction=augur_prediction_run(
                $pdo,$org,$event,$sideA,$sideB,$settings,
                ['use_tba_rankings'=>false,'use_epa'=>true]
            );

            foreach(array_merge($prediction['a']['teams']??[],$prediction['b']['teams']??[]) as $row){
                $n=(int)($row['team']??0);
                $m=is_array($row['metrics']??null)?$row['metrics']:[];
                if($n<1)continue;
                $augurMap[$n]=$m;
                if(isset($m['epa'])&&is_numeric($m['epa'])){
                    $epaMap[$n]=[
                        'epa'=>(float)$m['epa'],
                        'auto'=>$m['auto_epa']??null,
                        'teleop'=>$m['teleop_epa']??null,
                        'endgame'=>$m['endgame_epa']??null,
                        'confidence'=>$m['epa_confidence']??null,
                    ];
                }
            }
        }
    }catch(Throwable $ignored){
        $augurMap=[];
    }

    // EPA should still display even if the private AUGUR model has no usable
    // scouting sample or cannot be calculated for this event.
    if(!$epaMap){
        try{$epaMap=augur_epa_rating_map($pdo,$org,$event,$teamNumbers,null);}
        catch(Throwable $ignored){$epaMap=[];}
    }
}

$pageTitle='Robot Intelligence';
$moduleName='AUGUR';
include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between">
  <div><div class="module-eyebrow"><span>AUGUR</span><small>Robot Intelligence</small></div><h1 style="margin-bottom:4px">Robot Cards</h1><div class="muted">Scouting-derived performance with Public EPA and private Neptune EPA. Click any robot for full scouting, pit, EPA, AUGUR, and Spot Scouting detail.</div></div>
  <a class="btn secondary" href="team-display.php"><i class="fa-solid fa-tv"></i> Pit Match Board</a>
</div>
<div class="card">
  <form method="get" class="analytics-toolbar">
    <div style="min-width:340px"><label>Event</label><select name="event_id" onchange="this.form.submit()"><?php foreach($events as $e):?><option value="<?=$e['id']?>" <?=$eventId===(int)$e['id']?'selected':''?>><?=e($e['name'])?></option><?php endforeach;?></select></div>
    <div><label>Sort robots by</label><select id="sortBy"><option value="ppm">Points / match</option><option value="augur">Neptune EPA</option><option value="epa">Public EPA</option><option value="cycle">Cycle time (fastest)</option><option value="defense">Defense / match</option><option value="success">Offense success %</option><option value="matches">Matches scouted</option><option value="team">Team number</option></select></div>
  </form>
</div>
<style>
.robot-card-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(270px,1fr));
  gap:14px;
  align-items:stretch;
}
.robot-card-grid .robot-card{
  display:flex;
  flex-direction:column;
  height:100%;
  min-height:390px;
  text-align:left;
  padding:14px;
}
.robot-card-grid .robot-card-head{
  min-height:62px;
  align-items:flex-start;
}
.robot-card-grid .robot-number{
  line-height:1;
}
.robot-card-name{
  margin-top:5px;
  line-height:1.25;
  min-height:1.25em;
}
.robot-card-badges{
  display:flex;
  flex-wrap:wrap;
  align-content:flex-start;
  gap:5px;
  min-height:54px;
  margin:7px 0 10px;
}
.robot-card-badges .pill{margin:0}
.robot-card-grid .robot-stats{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:7px;
  margin:0;
}
.robot-card-grid .stat-box{
  display:flex;
  flex-direction:column;
  justify-content:space-between;
  min-height:72px;
  padding:9px;
}
.robot-card-grid .stat-box span{
  display:block;
  line-height:1.16;
  min-height:2.3em;
}
.robot-card-grid .stat-box b{
  margin-top:4px;
  line-height:1;
}
.robot-card-grid .stat-box.rating-epa{
  border:1px solid color-mix(in srgb,var(--accent) 35%,var(--line));
}
.robot-card-grid .stat-box.rating-augur{
  border:1px solid color-mix(in srgb,var(--module-augur,var(--accent)) 45%,var(--line));
  background:color-mix(in srgb,var(--module-augur,var(--accent)) 6%,var(--panel2));
}
.robot-card-grid .stat-box.rating-augur b{
  color:var(--module-augur,var(--accent));
}
.robot-card-grid .robot-card-foot{
  margin-top:auto;
  padding-top:12px;
  min-height:38px;
  align-items:flex-end;
}
.robot-card-grid .robot-card-foot>span:first-child{
  line-height:1.25;
}
@media(max-width:700px){
  .robot-card-grid{grid-template-columns:1fr}
  .robot-card-grid .robot-card{min-height:0}
  .robot-card-grid .robot-card-head{min-height:0}
  .robot-card-badges{min-height:0;margin-bottom:9px}
  .robot-card-grid .stat-box{min-height:68px}
}
</style>

<div id="robotGrid" class="robot-card-grid" style="margin-top:16px">
<?php foreach($stats as $r):
  $team=(int)$r['team'];
  $epa=$epaMap[$team]['epa']??null;
  $augur=$augurMap[$team]['neptune_epa']??$augurMap[$team]['augur_epa']??null;
?>
  <button
    type="button"
    class="robot-card robot-detail-trigger"
    data-event-id="<?=$eventId?>"
    data-team="<?=$team?>"
    data-ppm="<?=$r['ppm']?>"
    data-epa="<?=$epa!==null?e((string)$epa):-999999?>"
    data-augur="<?=$augur!==null?e((string)$augur):-999999?>"
    data-cycle="<?=$r['cycle_time']??99999?>"
    data-defense="<?=$r['defense_per_match']?>"
    data-success="<?=$r['success_rate']?>"
    data-matches="<?=$r['matches']?>"
    aria-label="Open details for team <?=$team?>"
  >
    <div class="robot-card-head">
      <div>
        <div class="robot-number">#<?=e($team)?></div>
        <div class="muted robot-card-name"><?=e($r['nickname']?:'FRC Team '.$team)?></div>
      </div>
      <i class="fa-solid fa-circle-info robot-card-info" aria-hidden="true"></i>
    </div>

    <div class="robot-card-badges">
      <?php if($r['pit_status']):?>
        <span class="pill"><i class="fa-solid fa-clipboard-check"></i> Pit <?=e($r['pit_status']==='complete'?'complete':'in progress')?></span>
        <?php if(!empty($r['pit_data']['drivetrain'])):?><span class="pill"><?=e($r['pit_data']['drivetrain'])?></span><?php endif;?>
        <?php if(!empty($r['pit_data']['endgame_capability'])):?><span class="pill">Endgame: <?=e($r['pit_data']['endgame_capability'])?></span><?php endif;?>
      <?php endif;?>
    </div>

    <div class="robot-stats">
      <div class="stat-box rating-epa">
        <span class="muted">Public EPA</span>
        <b><?=$epa!==null?number_format((float)$epa,1):'—'?></b>
      </div>
      <div class="stat-box rating-augur">
        <span class="muted" title="Neptune EPA">Nep. EPA</span>
        <b><?=$augur!==null?number_format((float)$augur,1):'—'?></b>
      </div>
      <div class="stat-box">
        <span class="muted">Points / match</span>
        <b><?=number_format($r['ppm'],1)?></b>
      </div>
      <div class="stat-box">
        <span class="muted">Cycle time</span>
        <b><?=$r['cycle_time']!==null?number_format($r['cycle_time'],1).'s':'—'?></b>
      </div>
      <div class="stat-box">
        <span class="muted">Defense / match</span>
        <b><?=number_format($r['defense_per_match'],1)?></b>
      </div>
      <div class="stat-box">
        <span class="muted">Offense success</span>
        <b><?=number_format($r['success_rate'],0)?>%</b>
      </div>
    </div>

    <div class="robot-card-foot">
      <span class="muted"><?=$r['matches']?> match run<?=$r['matches']==1?'':'s'?> · <?=$r['actions']?> actions</span>
      <span><i class="fa-solid fa-up-right-and-down-left-from-center"></i> Details</span>
    </div>
  </button>
<?php endforeach;?>
</div>
<script>
const grid=document.getElementById('robotGrid'),sort=document.getElementById('sortBy');
function resort(){let cards=[...grid.children],k=sort.value;cards.sort((a,b)=>{if(k==='team')return +a.dataset.team-+b.dataset.team;if(k==='cycle')return +a.dataset.cycle-+b.dataset.cycle;const av=Number(a.dataset[k]??-999999),bv=Number(b.dataset[k]??-999999);return bv-av});cards.forEach(x=>grid.appendChild(x))}
sort.addEventListener('change',resort);resort();
</script>
<?php include __DIR__.'/_robot_modal.php';?>
<?php include dirname(__DIR__).'/partials_footer.php';
