<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();$org=(int)$u['organization_id'];
$eventId=(int)($_GET['event_id']??0);

if(!$eventId){
    $s=$pdo->prepare("SELECT id FROM events WHERE organization_id=? AND active=1 ORDER BY is_current DESC, CASE event_status WHEN 'running' THEN 0 WHEN 'schedule_ready' THEN 1 WHEN 'pit_open' THEN 2 WHEN 'planned' THEN 3 ELSE 4 END, COALESCE(start_date,'9999-12-31'), id DESC LIMIT 1");
    $s->execute([$org]);$eventId=(int)$s->fetchColumn();
}

$s=$pdo->prepare("SELECT e.*,g.name game_name,g.season_year FROM events e JOIN games g ON g.id=e.game_id WHERE e.organization_id=? AND e.active=1 ORDER BY is_current DESC,COALESCE(e.start_date,'9999-12-31'),e.name");
$s->execute([$org]);$events=$s->fetchAll();
$event=null;$teams=[];$complete=0;$progress=0;
if($eventId){
    $s=$pdo->prepare("SELECT e.*,g.name game_name,g.season_year FROM events e JOIN games g ON g.id=e.game_id WHERE e.id=? AND e.organization_id=?");$s->execute([$eventId,$org]);$event=$s->fetch()?:null;
    if($event){
        $s=$pdo->prepare("SELECT et.frc_team_number,et.nickname,ps.status,ps.updated_at,u.display_name scout_name,(SELECT COUNT(*) FROM pit_scouting_photos pp WHERE pp.pit_scouting_id=ps.id) photo_count FROM event_teams et LEFT JOIN pit_scouting ps ON ps.organization_id=? AND ps.event_id=et.event_id AND ps.frc_team_number=et.frc_team_number LEFT JOIN users u ON u.id=ps.submitted_by WHERE et.event_id=? ORDER BY et.frc_team_number");
        $s->execute([$org,$eventId]);$teams=$s->fetchAll();
        foreach($teams as $t){if(($t['status']??'')==='complete')$complete++;elseif(($t['status']??'')==='in_progress')$progress++;}
    }
}
$total=count($teams);$remaining=max(0,$total-$complete);$pct=$total?round($complete*100/$total):0;
$pageTitle='Pit Scouting';include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between">
  <div><h1 style="margin-bottom:4px">Pit Scouting</h1><div class="muted">Scout the event roster before the match schedule exists. Team numbers come from the event roster, not manual entry.</div></div>
  <?php if(in_array($u['role'],['owner','admin','strategy'],true)):?><a class="btn secondary" href="<?=e(base_url('admin/events.php'))?>"><i class="fa-solid fa-calendar-plus"></i> Event Setup</a><?php endif;?>
</div>

<?php if($events):?>
<div class="card"><form method="get" class="analytics-toolbar"><div style="min-width:320px"><label style="margin-top:0">Event</label><select name="event_id" onchange="this.form.submit()"><?php foreach($events as $ev):?><option value="<?=$ev['id']?>" <?=$eventId===(int)$ev['id']?'selected':''?>><?=e(($ev['is_current']?'★ ':'').$ev['name'].' · '.$ev['game_name'])?></option><?php endforeach;?></select></div></form></div>
<?php endif;?>

<?php if(!$event):?>
<div class="notice" style="margin-top:16px">No event is available yet. An administrator can create the event and paste/import its team roster before the schedule is published.</div>
<?php else:?>
<div class="pit-progress card" style="margin-top:16px">
  <div><span class="muted">Current event</span><h2 style="margin:3px 0"><?=e($event['name'])?></h2><div class="muted"><?=e($event['game_name'])?> · <?=e(str_replace('_',' ',strtoupper($event['event_status'])))?></div></div>
  <div class="pit-progress-numbers"><div><b><?=$complete?></b><span>Complete</span></div><div><b><?=$progress?></b><span>In progress</span></div><div><b><?=$remaining?></b><span>Remaining</span></div></div>
  <div class="progress-track"><span style="width:<?=$pct?>%"></span></div>
</div>

<?php if(!$teams):?>
<div class="notice" style="margin-top:16px">This event does not have a team roster yet. Command can paste a roster in Event Setup or import the roster from The Blue Alliance.</div>
<?php else:?>
<div class="card" style="margin-top:16px"><div class="pit-list-toolbar"><div><label style="margin-top:0">Find team</label><input id="pitSearch" placeholder="Team number or nickname"></div><div><label style="margin-top:0">Show</label><select id="pitStatus"><option value="all">All teams</option><option value="not_started">Not scouted</option><option value="in_progress">In progress</option><option value="complete">Complete</option></select></div></div></div>
<div class="pit-team-grid" id="pitTeamGrid">
<?php foreach($teams as $t):$status=$t['status']?:'not_started';?>
<a class="pit-team-card pit-status-<?=e($status)?>" data-status="<?=e($status)?>" data-search="<?=e(strtolower($t['frc_team_number'].' '.($t['nickname']??'')))?>" href="scout.php?event_id=<?=$eventId?>&team=<?=e($t['frc_team_number'])?>">
  <div class="pit-card-top"><span class="pit-team-number">#<?=e($t['frc_team_number'])?></span><span class="pill"><?php if($status==='complete'):?><i class="fa-solid fa-circle-check"></i> COMPLETE<?php elseif($status==='in_progress'):?><i class="fa-solid fa-pen"></i> IN PROGRESS<?php else:?><i class="fa-regular fa-circle"></i> NOT SCOUTED<?php endif;?></span></div>
  <b class="pit-team-name"><?=e($t['nickname']?:'FRC Team '.$t['frc_team_number'])?></b>
  <div class="muted pit-card-meta"><?php if($t['scout_name']):?>Last by <?=e($t['scout_name'])?><?php else:?>Tap to begin pit scouting<?php endif;?><?php if((int)$t['photo_count']>0):?> · <i class="fa-solid fa-camera"></i> <?=e($t['photo_count'])?><?php endif;?></div>
</a>
<?php endforeach;?>
</div>
<script>
const q=document.getElementById('pitSearch'),st=document.getElementById('pitStatus'),cards=[...document.querySelectorAll('.pit-team-card')];
function filterPit(){const x=(q?.value||'').trim().toLowerCase(),s=st?.value||'all';cards.forEach(c=>{c.style.display=((!x||c.dataset.search.includes(x))&&(s==='all'||c.dataset.status===s))?'':'none';});}
q?.addEventListener('input',filterPit);st?.addEventListener('change',filterPit);
</script>
<?php endif;?>
<?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php';
