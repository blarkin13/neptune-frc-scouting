<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_helpers.php';
require_once dirname(__DIR__).'/pit/_helpers.php';

$u=require_login();
$org=(int)$u['organization_id'];
$eventId=(int)($_GET['event_id']??0);
$team=(int)($_GET['team']??0);
if($eventId<1||$team<1){http_response_code(400);exit('<div class="notice bad">Missing event or team.</div>');}

$s=$pdo->prepare("SELECT e.id,e.name,e.game_id,e.game_revision_id,g.name AS game_name,gr.pit_config_json,gr.field_image_path,gr.revision_number game_revision_number,et.nickname FROM events e JOIN games g ON g.id=e.game_id JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id JOIN event_teams et ON et.event_id=e.id AND et.frc_team_number=? WHERE e.id=? AND e.organization_id=? LIMIT 1");
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

function robot_auton_extract_path(mixed $payload): ?array {
    if(!is_array($payload))return null;
    $path=$payload['_auton_path']??null;
    if(!is_array($path)||!isset($path['strokes'])||!is_array($path['strokes']))return null;
    $safe=[];
    foreach($path['strokes'] as $stroke){
        if(!is_array($stroke))continue;
        $pts=[];
        foreach($stroke as $point){
            if(!is_array($point)||!isset($point['x'],$point['y'])||!is_numeric($point['x'])||!is_numeric($point['y']))continue;
            $pts[]=['x'=>max(0.0,min(1.0,(float)$point['x'])),'y'=>max(0.0,min(1.0,(float)$point['y']))];
        }
        if($pts)$safe[]=$pts;
    }
    return $safe?['version'=>1,'strokes'=>$safe]:null;
}
function robot_auton_field_info(array $revision,int $gameId,int $org): array {
    if($gameId<1)return ['url'=>'','width'=>1200,'height'=>600];
    $rel=neptune_revision_field_path($revision,$gameId,$org);
    if($rel==='')return ['url'=>'','width'=>1200,'height'=>600];
    $abs=neptune_public_root().'/'.$rel;
    $width=1200;$height=600;
    $size=@getimagesize($abs);
    if(is_array($size)&&!empty($size[0])&&!empty($size[1])){$width=(int)$size[0];$height=(int)$size[1];}
    return ['url'=>'/'.ltrim($rel,'/'),'width'=>$width,'height'=>$height];
}
function robot_auton_svg_points(array $stroke,int $width,int $height): string {
    $points=[];
    foreach($stroke as $point){
        if(!is_array($point)||!isset($point['x'],$point['y']))continue;
        $x=max(0.0,min(1.0,(float)$point['x']))*$width;
        $y=max(0.0,min(1.0,(float)$point['y']))*$height;
        $points[]=number_format($x,2,'.','').','.number_format($y,2,'.','');
    }
    return implode(' ',$points);
}

$autonPath=robot_auton_extract_path($pitData);
$autonSource=$autonPath?'Pit Scout':'';
if(!$autonPath){
    $s=$pdo->prepare('SELECT data_json FROM pre_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=? LIMIT 1');
    $s->execute([$org,$eventId,$team]);
    if($row=$s->fetch()){
        $candidate=json_decode((string)($row['data_json']??''),true);
        $autonPath=robot_auton_extract_path(is_array($candidate)?$candidate:[]);
        if($autonPath)$autonSource='Pre-Scout';
    }
}
if(!$autonPath){
    $s=$pdo->prepare('SELECT data_json FROM robot_season_profiles WHERE organization_id=? AND game_id=? AND frc_team_number=? LIMIT 1');
    $s->execute([$org,(int)$event['game_id'],$team]);
    if($row=$s->fetch()){
        $candidate=json_decode((string)($row['data_json']??''),true);
        $autonPath=robot_auton_extract_path(is_array($candidate)?$candidate:[]);
        if($autonPath)$autonSource='Season Profile';
    }
}
$autonField=robot_auton_field_info($event,(int)$event['game_id'],$org);
$autonWidth=max(1,(int)$autonField['width']);
$autonHeight=max(1,(int)$autonField['height']);
$autonAspect=$autonWidth.' / '.$autonHeight;
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
      foreach($pitData as $code=>$value){if(str_starts_with((string)$code,'_'))continue;$display=detail_value($value);if($display==='')continue;$q=$questionMap[$code]??[];$group=(string)($q['group']??'Other');$groups[$group][]=[$code,$display];}
    ?>
    <?php foreach($groups as $group=>$items):?>
      <div class="robot-pit-group">
        <h4><?=e($group)?></h4>
        <div class="robot-detail-list">
          <?php foreach($items as [$code,$display]):?><div><span><?=e(detail_label((string)$code,$questionMap))?></span><b><?=nl2br(e($display))?></b></div><?php endforeach;?>
        </div>
      </div>
    <?php endforeach;?>
    <?php if($autonPath):?>
      <div class="robot-pit-group robot-auton-block">
        <div class="robot-auton-head">
          <h4><i class="fa-solid fa-route"></i> Autonomous Path</h4>
          <span class="pill"><i class="fa-solid fa-database"></i> <?=e($autonSource)?></span>
        </div>
        <div class="robot-auton-preview" style="aspect-ratio:<?=e($autonAspect)?>">
          <?php if($autonField['url']!==''):?>
            <img src="<?=e($autonField['url'])?>" alt="<?=e($event['game_name'].' field')?>">
          <?php else:?>
            <div class="robot-auton-grid" aria-hidden="true"></div>
          <?php endif;?>
          <svg viewBox="0 0 <?=$autonWidth?> <?=$autonHeight?>" preserveAspectRatio="none" role="img" aria-label="Autonomous route for team <?=e($team)?>">
            <defs>
              <marker id="robotAutonArrow" markerWidth="12" markerHeight="12" refX="9" refY="4" orient="auto" markerUnits="strokeWidth">
                <path d="M0,0 L0,8 L10,4 z" fill="#ff3b30"></path>
              </marker>
            </defs>
            <?php foreach($autonPath['strokes'] as $stroke):
              $points=robot_auton_svg_points($stroke,$autonWidth,$autonHeight);
              if($points==='')continue;
              $first=$stroke[0]??null;
            ?>
              <polyline points="<?=e($points)?>" fill="none" stroke="rgba(255,255,255,.94)" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"></polyline>
              <polyline points="<?=e($points)?>" fill="none" stroke="#ff3b30" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" marker-end="url(#robotAutonArrow)"></polyline>
              <?php if(is_array($first)):?>
                <circle cx="<?=number_format((float)$first['x']*$autonWidth,2,'.','')?>" cy="<?=number_format((float)$first['y']*$autonHeight,2,'.','')?>" r="7" fill="#ff3b30" stroke="#fff" stroke-width="3"></circle>
              <?php endif;?>
            <?php endforeach;?>
          </svg>
        </div>
        <div class="robot-auton-meta muted">
          <span><i class="fa-solid fa-pen-ruler"></i> <?=count($autonPath['strokes'])?> stroke<?=count($autonPath['strokes'])===1?'':'s'?></span>
          <?php if($autonField['url']===''):?><span>· Field background has not been uploaded for this game.</span><?php endif;?>
        </div>
      </div>
    <?php endif;?>
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
