<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_helpers.php';
require_once dirname(__DIR__).'/pit/_helpers.php';

$u=require_login();
$org=(int)$u['organization_id'];
$eventId=(int)($_GET['event_id']??0);
$team=(int)($_GET['team']??0);
if($eventId<1||$team<1){http_response_code(400);exit('<div class="notice bad">Missing event or team.</div>');}

$s=$pdo->prepare("SELECT e.id,e.name,e.game_id,g.name AS game_name,g.pit_config_json,et.nickname FROM events e JOIN games g ON g.id=e.game_id JOIN event_teams et ON et.event_id=e.id AND et.frc_team_number=? WHERE e.id=? AND e.organization_id=? LIMIT 1");
$s->execute([$team,$eventId,$org]);
$event=$s->fetch();
if(!$event){http_response_code(404);exit('<div class="notice bad">That robot is not on this organization\'s event roster.</div>');}

$allStats=neptune_event_robot_stats($pdo,$org,$eventId);
$r=$allStats[$team]??['team'=>$team,'nickname'=>$event['nickname']??'','matches'=>0,'points'=>0,'ppm'=>0,'successes'=>0,'failures'=>0,'success_rate'=>0,'defense'=>0,'defense_per_match'=>0,'cycle_time'=>null,'actions'=>0,'pit_status'=>null,'pit_data'=>[]];
$r['nickname']=$r['nickname']?:($event['nickname']??'');

$s=$pdo->prepare('SELECT * FROM pit_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=? LIMIT 1');
$s->execute([$org,$eventId,$team]);
$pit=$s->fetch()?:null;
$pitData=$pit?json_decode($pit['data_json'],true):[];if(!is_array($pitData))$pitData=[];
$questions=neptune_pit_all_questions($event['pit_config_json']??null);
$questionMap=[];foreach($questions as $q){$code=(string)($q['code']??'');if($code!=='')$questionMap[$code]=$q;}

$photos=[];
if($pit){$s=$pdo->prepare('SELECT category,file_path,caption,created_at FROM pit_scouting_photos WHERE pit_scouting_id=? AND organization_id=? ORDER BY FIELD(category,\'front\',\'back\',\'left\',\'right\',\'mechanism\',\'other\'),created_at DESC');$s->execute([(int)$pit['id'],$org]);$photos=$s->fetchAll();}

$s=$pdo->prepare("SELECT m.id,m.comp_level,m.set_number,m.match_number,m.state,m.run_number,m.red_score,m.blue_score,m.scheduled_time,mt.alliance,mt.station,
 COALESCE(SUM(CASE WHEN sa.match_run_number=m.run_number THEN sa.points ELSE 0 END),0) AS scout_points,
 COUNT(CASE WHEN sa.match_run_number=m.run_number THEN sa.id END) AS action_count,
 SUM(CASE WHEN sa.match_run_number=m.run_number AND sa.action_type='offense' AND sa.result='Success' THEN 1 ELSE 0 END) AS offense_success,
 SUM(CASE WHEN sa.match_run_number=m.run_number AND sa.action_type='offense' AND sa.result IN ('Success','Failure') THEN 1 ELSE 0 END) AS offense_attempts,
 SUM(CASE WHEN sa.match_run_number=m.run_number AND sa.action_type='defense' AND sa.result='Success' THEN 1 ELSE 0 END) AS defense_actions
 FROM matches m
 JOIN match_teams mt ON mt.match_id=m.id AND mt.frc_team_number=?
 LEFT JOIN scouting_actions sa ON sa.match_id=m.id AND sa.organization_id=? AND sa.event_id=? AND sa.frc_team_number=? AND sa.deleted_at IS NULL
 WHERE m.organization_id=? AND m.event_id=?
 GROUP BY m.id,m.comp_level,m.set_number,m.match_number,m.state,m.run_number,m.red_score,m.blue_score,m.scheduled_time,mt.alliance,mt.station
 ORDER BY FIELD(m.comp_level,'qm','ef','qf','sf','f','legacy'),m.set_number,m.match_number");
$s->execute([$team,$org,$eventId,$team,$org,$eventId]);$matches=$s->fetchAll();

function detail_value(mixed $v): string {
    if(is_array($v)) return implode(', ',array_map('strval',$v));
    $v=trim((string)$v);
    return $v;
}
function detail_label(string $code,array $questionMap): string {
    if(isset($questionMap[$code]['label']))return (string)$questionMap[$code]['label'];
    return ucwords(str_replace('_',' ',$code));
}
?>
<div class="robot-modal-head">
  <div>
    <div class="muted"><?=e($event['name'])?> · <?=e($event['game_name'])?></div>
    <h2 id="robotDetailTitle" style="margin:4px 0 2px">#<?=e($team)?> <?=e($r['nickname']?:'FRC Team '.$team)?></h2>
    <div class="robot-modal-pills">
      <span class="pill"><i class="fa-solid fa-clipboard-list"></i> Pit <?=e(!$pit?'not scouted':($pit['status']==='complete'?'complete':'in progress'))?></span>
      <?php if(!empty($pitData['drivetrain'])):?><span class="pill"><i class="fa-solid fa-gears"></i> <?=e(detail_value($pitData['drivetrain']))?></span><?php endif;?>
      <?php if(!empty($pitData['endgame_capability'])):?><span class="pill"><i class="fa-solid fa-flag-checkered"></i> <?=e(detail_value($pitData['endgame_capability']))?></span><?php endif;?>
    </div>
  </div>
</div>

<section class="robot-modal-section">
  <h3><i class="fa-solid fa-chart-line"></i> Observed Performance</h3>
  <div class="robot-detail-metrics">
    <div><span>Points / match</span><b><?=number_format((float)$r['ppm'],1)?></b></div>
    <div><span>Cycle time</span><b><?=$r['cycle_time']!==null?number_format((float)$r['cycle_time'],1).'s':'—'?></b></div>
    <div><span>Offense success</span><b><?=number_format((float)$r['success_rate'],0)?>%</b></div>
    <div><span>Defense / match</span><b><?=number_format((float)$r['defense_per_match'],1)?></b></div>
    <div><span>Matches scouted</span><b><?=e($r['matches'])?></b></div>
    <div><span>Total scout points</span><b><?=number_format((float)$r['points'],0)?></b></div>
    <div><span>Recorded actions</span><b><?=e($r['actions'])?></b></div>
    <div><span>Successful offense</span><b><?=e($r['successes'])?></b></div>
  </div>
</section>

<section class="robot-modal-section">
  <h3><i class="fa-solid fa-clipboard-check"></i> Pit Scouting</h3>
  <?php if(!$pit):?>
    <div class="notice">No pit scouting has been recorded for this robot at this event.</div>
  <?php else:?>
    <div class="robot-pit-meta muted"><?=e($pit['status']==='complete'?'Completed':'In progress')?><?=!empty($pit['updated_at'])?' · Updated '.e($pit['updated_at']).' UTC':''?></div>
    <?php
      $groups=[];
      foreach($pitData as $code=>$value){$display=detail_value($value);if($display==='')continue;$q=$questionMap[$code]??[];$group=(string)($q['group']??'Other');$groups[$group][]=[$code,$display];}
    ?>
    <?php foreach($groups as $group=>$items):?>
      <div class="robot-pit-group">
        <h4><?=e($group)?></h4>
        <div class="robot-detail-list">
          <?php foreach($items as [$code,$display]):?><div><span><?=e(detail_label((string)$code,$questionMap))?></span><b><?=nl2br(e($display))?></b></div><?php endforeach;?>
        </div>
      </div>
    <?php endforeach;?>
    <?php if(trim((string)($pit['notes']??''))!==''):?><div class="robot-notes"><span class="muted">Scout notes</span><p><?=nl2br(e($pit['notes']))?></p></div><?php endif;?>
  <?php endif;?>
</section>

<?php if($photos):?>
<section class="robot-modal-section">
  <h3><i class="fa-solid fa-camera"></i> Robot Photos</h3>
  <div class="robot-modal-photo-grid">
    <?php foreach($photos as $p):?><a href="<?=e(base_url($p['file_path']))?>" target="_blank" rel="noopener"><img src="<?=e(base_url($p['file_path']))?>" alt="<?=e(ucfirst($p['category']).' view of team '.$team)?>"><span><?=e(ucfirst($p['category']))?></span></a><?php endforeach;?>
  </div>
</section>
<?php endif;?>

<section class="robot-modal-section">
  <h3><i class="fa-solid fa-calendar-days"></i> Event Matches</h3>
  <?php if(!$matches):?><div class="notice">No matches are loaded for this robot at this event yet.</div><?php else:?>
  <div class="robot-match-history">
    <?php foreach($matches as $m):$attempts=(int)$m['offense_attempts'];$rate=$attempts?((int)$m['offense_success']/$attempts*100):null;?>
      <div class="robot-match-line <?=e($m['state'])?>">
        <div><b><?=e(neptune_match_label($m))?></b><span class="muted"><?=e($m['alliance'].' '.$m['station'])?><?=($m['run_number']??1)>1?' · Run '.e($m['run_number']):''?></span></div>
        <div><span class="muted">State</span><b><?=e(ucfirst($m['state']))?></b></div>
        <div><span class="muted">Scout pts</span><b><?=number_format((float)$m['scout_points'],0)?></b></div>
        <div><span class="muted">Actions</span><b><?=e($m['action_count'])?></b></div>
        <div><span class="muted">Offense</span><b><?=$rate!==null?number_format($rate,0).'%':'—'?></b></div>
        <div><span class="muted">Defense</span><b><?=e((int)$m['defense_actions'])?></b></div>
        <?php if($m['red_score']!==null):?><div><span class="muted">TBA score</span><b><?=e($m['red_score'])?>–<?=e($m['blue_score'])?></b></div><?php endif;?>
      </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>
</section>
