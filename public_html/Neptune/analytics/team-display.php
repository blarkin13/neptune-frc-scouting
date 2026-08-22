<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
$u=require_login();$org=(int)$u['organization_id'];$tv=isset($_GET['tv'])&&$_GET['tv']=='1';
$events=neptune_event_list($pdo,$org);$eventId=(int)($_GET['event_id']??($events[0]['id']??0));
$s=$pdo->prepare('SELECT * FROM teams WHERE organization_id=? AND active=1 ORDER BY frc_team_number');$s->execute([$org]);$myTeams=$s->fetchAll();
$teamNumber=(int)($_GET['team']??($myTeams[0]['frc_team_number']??0));$stats=$eventId?neptune_event_robot_stats($pdo,$org,$eventId):[];$matches=[];
if($eventId&&$teamNumber){
    $s=$pdo->prepare("SELECT m.* FROM matches m JOIN match_teams own ON own.match_id=m.id AND own.frc_team_number=? WHERE m.organization_id=? AND m.event_id=? ORDER BY FIELD(m.comp_level,'qm','ef','qf','sf','f','legacy'),m.set_number,m.match_number");$s->execute([$teamNumber,$org,$eventId]);$matches=$s->fetchAll();
    $mt=$pdo->prepare("SELECT alliance,station,frc_team_number FROM match_teams WHERE match_id=? ORDER BY FIELD(alliance,'Red','Blue'),station");foreach($matches as &$m){$mt->execute([$m['id']]);$m['teams']=$mt->fetchAll();}unset($m);
}
$pageTitle='Pit Match Board';$hideChrome=$tv;$bodyClass=$tv?'tv-mode':'';include dirname(__DIR__).'/partials_header.php';
?>
<?php if($tv):?><div class="tv-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><div><div class="muted">PIT MATCH BOARD</div><div style="font-size:2rem;font-weight:950">#<?=e($teamNumber)?></div></div></div><?php else:?><div class="toolbar" style="justify-content:space-between"><div><h1 style="margin-bottom:4px">Pit Match Board</h1><div class="muted">Past and future matches for one of your teams. Click any robot card for full scouting and pit data.</div></div><a class="btn secondary" href="?event_id=<?=$eventId?>&team=<?=$teamNumber?>&tv=1" target="_blank"><i class="fa-solid fa-expand"></i> TV Mode</a></div><?php endif;?>
<?php if(!$tv):?><div class="card"><form method="get" class="analytics-toolbar"><div style="min-width:340px"><label>Event</label><select name="event_id"><?php foreach($events as $e):?><option value="<?=$e['id']?>" <?=$eventId===(int)$e['id']?'selected':''?>><?=e($e['name'])?></option><?php endforeach;?></select></div><div><label>Our team</label><select name="team"><?php foreach($myTeams as $t):?><option value="<?=$t['frc_team_number']?>" <?=$teamNumber===(int)$t['frc_team_number']?'selected':''?>>#<?=e($t['frc_team_number'].' '.($t['display_name']?:$t['nickname']))?></option><?php endforeach;?></select></div><button><i class="fa-solid fa-filter"></i> Display</button></form></div><?php endif;?>
<div class="schedule-board" style="margin-top:16px">
<?php foreach($matches as $m):$past=$m['state']==='ended';$current=in_array($m['state'],['running','ready','paused'],true);?>
<section class="schedule-match <?=$past?'past':''?> <?=$current?'current':''?>">
  <div><div class="muted"><?=e(strtoupper($m['state']))?><?=($m['run_number']??1)>1?' · RUN '.e($m['run_number']):''?></div><h2 style="margin:3px 0"><?=e(neptune_match_label($m))?></h2><?php if($m['scheduled_time']):?><div class="muted"><?=e($m['scheduled_time'])?> UTC</div><?php endif;?><?php if($m['red_score']!==null):?><div style="margin-top:8px"><b><?=e($m['red_score'])?> – <?=e($m['blue_score'])?></b> <span class="muted">TBA</span></div><?php endif;?></div>
  <div class="schedule-teams">
  <?php foreach($m['teams'] as $t):$r=$stats[(int)$t['frc_team_number']]??['ppm'=>0,'cycle_time'=>null,'defense_per_match'=>0,'success_rate'=>0];?>
    <button type="button" class="schedule-robot <?=strtolower($t['alliance'])?> robot-detail-trigger" data-event-id="<?=$eventId?>" data-team="<?=e($t['frc_team_number'])?>" style="<?=$t['frc_team_number']==$teamNumber?'outline:2px solid var(--c1-green)':''?>" aria-label="Open details for team <?=e($t['frc_team_number'])?>">
      <span class="muted"><?=e($t['alliance'].' '.$t['station'])?></span><strong>#<?=e($t['frc_team_number'])?></strong><small><?=number_format($r['ppm'],1)?> PPM<br><?=$r['cycle_time']!==null?number_format($r['cycle_time'],1).'s cycle':'— cycle'?><br><?=number_format($r['defense_per_match'],1)?> def/m</small><i class="fa-solid fa-circle-info schedule-robot-info" aria-hidden="true"></i>
    </button>
  <?php endforeach;?>
  </div>
</section>
<?php endforeach;?>
<?php if(!$matches):?><div class="notice">No matches are loaded for this team/event combination.</div><?php endif;?>
</div>
<?php include __DIR__.'/_robot_modal.php';?>
<?php if($tv):?><script>setInterval(()=>{if(!(window.neptuneRobotModalOpen&&window.neptuneRobotModalOpen()))location.reload()},60000);</script><?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php';
