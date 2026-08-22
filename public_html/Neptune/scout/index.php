<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$org=(int)$u['organization_id'];

$s=$pdo->prepare("SELECT m.id,m.match_number,m.set_number,m.comp_level,m.field_id,m.state,m.run_number,e.name event_name,g.name game_name
  FROM matches m
  JOIN events e ON e.id=m.event_id
  JOIN games g ON g.id=m.game_id
  WHERE m.organization_id=? AND m.state IN ('ready','running','paused')
  ORDER BY FIELD(m.state,'running','paused','ready'),m.id DESC");
$s->execute([$org]);
$matches=$s->fetchAll();

$teamStmt=$pdo->prepare('SELECT alliance,station,frc_team_number FROM match_teams WHERE match_id=? ORDER BY FIELD(alliance,\'Red\',\'Blue\'),station');
foreach($matches as &$m){
    $teamStmt->execute([(int)$m['id']]);
    $m['teams']=$teamStmt->fetchAll();
}
unset($m);

function scout_match_label(array $m): string {
    $level=['qm'=>'Qual','ef'=>'Eighth','qf'=>'Quarter','sf'=>'Semi','f'=>'Final'][$m['comp_level']]??strtoupper($m['comp_level']);
    return $m['comp_level']==='qm' ? $level.' '.$m['match_number'] : $level.' '.$m['set_number'].'-'.$m['match_number'];
}

$pageTitle='Scout';
include dirname(__DIR__).'/partials_header.php';
?>
<h1>Neptune Agent</h1>
<p class="muted">Select the field station you are assigned to. Neptune gets the robot number directly from the imported match schedule.</p>

<?php if(!$matches):?>
  <div class="notice">Command has not made a match ready yet.</div>
<?php endif;?>

<?php foreach($matches as $m):?>
  <div class="card" style="margin-bottom:16px">
    <div class="toolbar" style="justify-content:space-between">
      <div><h2 style="margin:0"><?=e(scout_match_label($m))?></h2><div class="muted"><?=e($m['event_name'])?></div></div>
      <div class="toolbar" style="margin:0"><span class="pill"><?=e($m['state'])?></span><?php if(($m['run_number']??1)>1):?><span class="pill"><i class="fa-solid fa-rotate-right"></i> Run <?=e($m['run_number'])?></span><?php endif;?></div>
    </div>
    <?php if(!$m['teams']):?>
      <div class="notice">No robots are attached to this match. Ask Command to refresh the TBA schedule.</div>
    <?php else:?>
      <div class="station-grid">
        <?php foreach($m['teams'] as $t):
          $alliance=$t['alliance']==='Blue'?'Blue':'Red';
          $url='match.php?match_id='.(int)$m['id'].'&alliance='.rawurlencode($alliance).'&station='.(int)$t['station'];
        ?>
          <a class="station-card <?=strtolower($alliance)?>" href="<?=e($url)?>">
            <span class="station-name"><?=e($alliance.' '.$t['station'])?></span>
            <strong>#<?=e($t['frc_team_number'])?></strong>
            <span class="muted">Scout this robot</span>
          </a>
        <?php endforeach;?>
      </div>
    <?php endif;?>
  </div>
<?php endforeach;?>
<?php include dirname(__DIR__).'/partials_footer.php';
