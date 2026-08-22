<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
$u=require_login();
$org=(int)$u['organization_id'];
$events=neptune_event_list($pdo,$org);
$eventId=(int)($_GET['event_id']??($events[0]['id']??0));
$stats=$eventId?neptune_event_robot_stats($pdo,$org,$eventId):[];
$pageTitle='Robot Analytics';
include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between">
  <div><h1 style="margin-bottom:4px">Robot Cards</h1><div class="muted">Scouting-derived performance across each distinct match run. Click any robot for full scouting and pit data.</div></div>
  <a class="btn secondary" href="team-display.php"><i class="fa-solid fa-tv"></i> Pit Match Board</a>
</div>
<div class="card">
  <form method="get" class="analytics-toolbar">
    <div style="min-width:340px"><label>Event</label><select name="event_id" onchange="this.form.submit()"><?php foreach($events as $e):?><option value="<?=$e['id']?>" <?=$eventId===(int)$e['id']?'selected':''?>><?=e($e['name'])?></option><?php endforeach;?></select></div>
    <div><label>Sort robots by</label><select id="sortBy"><option value="ppm">Points / match</option><option value="cycle">Cycle time (fastest)</option><option value="defense">Defense / match</option><option value="success">Offense success %</option><option value="matches">Matches scouted</option><option value="team">Team number</option></select></div>
  </form>
</div>
<div id="robotGrid" class="robot-card-grid" style="margin-top:16px">
<?php foreach($stats as $r):?>
  <button type="button" class="robot-card robot-detail-trigger" data-event-id="<?=$eventId?>" data-team="<?=$r['team']?>" data-ppm="<?=$r['ppm']?>" data-cycle="<?=$r['cycle_time']??99999?>" data-defense="<?=$r['defense_per_match']?>" data-success="<?=$r['success_rate']?>" data-matches="<?=$r['matches']?>" aria-label="Open details for team <?=$r['team']?>">
    <div class="robot-card-head"><div><div class="robot-number">#<?=e($r['team'])?></div><div class="muted"><?=e($r['nickname']?:'FRC Team '.$r['team'])?></div></div><i class="fa-solid fa-circle-info robot-card-info" aria-hidden="true"></i></div>
    <?php if($r['pit_status']):?><div style="margin-top:7px"><span class="pill"><i class="fa-solid fa-clipboard-check"></i> Pit <?=e($r['pit_status']==='complete'?'complete':'in progress')?></span><?php if(!empty($r['pit_data']['drivetrain'])):?><span class="pill"><?=e($r['pit_data']['drivetrain'])?></span><?php endif;?><?php if(!empty($r['pit_data']['endgame_capability'])):?><span class="pill">Endgame: <?=e($r['pit_data']['endgame_capability'])?></span><?php endif;?></div><?php endif;?>
    <div class="robot-stats"><div class="stat-box"><span class="muted">Points / match</span><b><?=number_format($r['ppm'],1)?></b></div><div class="stat-box"><span class="muted">Cycle time</span><b><?=$r['cycle_time']!==null?number_format($r['cycle_time'],1).'s':'—'?></b></div><div class="stat-box"><span class="muted">Defense / match</span><b><?=number_format($r['defense_per_match'],1)?></b></div><div class="stat-box"><span class="muted">Offense success</span><b><?=number_format($r['success_rate'],0)?>%</b></div></div>
    <div class="robot-card-foot"><span class="muted"><?=$r['matches']?> match run<?=$r['matches']==1?'':'s'?> · <?=$r['actions']?> actions</span><span><i class="fa-solid fa-up-right-and-down-left-from-center"></i> Details</span></div>
  </button>
<?php endforeach;?>
</div>
<script>
const grid=document.getElementById('robotGrid'),sort=document.getElementById('sortBy');
function resort(){let cards=[...grid.children],k=sort.value;cards.sort((a,b)=>{if(k==='team')return +a.dataset.team-+b.dataset.team;if(k==='cycle')return +a.dataset.cycle-+b.dataset.cycle;return +b.dataset[k]-+a.dataset[k]});cards.forEach(x=>grid.appendChild(x))}
sort.addEventListener('change',resort);resort();
</script>
<?php include __DIR__.'/_robot_modal.php';?>
<?php include dirname(__DIR__).'/partials_footer.php';
