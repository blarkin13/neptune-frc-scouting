<?php

declare(strict_types=1);

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';

$u=require_login();
$org=(int)$u['organization_id'];
$eventId=(int)($_GET['event_id']??0);
$team=(int)($_GET['team']??0);

if($eventId<=0||$team<=0){
    http_response_code(400);
    exit('Missing event or team.');
}

function tag_modal_table_exists(PDO $pdo,string $table): bool {
    $s=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
    $s->execute([$table]);
    return (bool)$s->fetchColumn();
}
function tag_modal_num(mixed $v,int $d=1): string {
    return $v===null||$v===''?'—':number_format((float)$v,$d);
}
function tag_modal_signed(mixed $v,int $d=1): string {
    if($v===null||$v==='')return '—';
    $x=(float)$v;
    return ($x>0?'+':'').number_format($x,$d);
}

$eventStmt=$pdo->prepare("SELECT e.id,e.name,e.tba_event_key,g.season_year
    FROM events e JOIN games g ON g.id=e.game_id
    WHERE e.id=? AND e.organization_id=? LIMIT 1");
$eventStmt->execute([$eventId,$org]);
$event=$eventStmt->fetch();
if(!$event){
    http_response_code(404);
    exit('Event not found.');
}

if(!tag_modal_table_exists($pdo,'tag_scouting_match_metrics')||!tag_modal_table_exists($pdo,'augur_tag_event_ratings')){
    http_response_code(204);
    exit;
}

$ratingStmt=$pdo->prepare("SELECT * FROM augur_tag_event_ratings
    WHERE organization_id=? AND event_id=? AND frc_team_number=? LIMIT 1");
$ratingStmt->execute([$org,$eventId,$team]);
$rating=$ratingStmt->fetch()?:null;

$metricsStmt=$pdo->prepare("SELECT tm.*,m.set_number,m.scheduled_time,m.state
    FROM tag_scouting_match_metrics tm
    JOIN matches m ON m.id=tm.match_id AND m.organization_id=tm.organization_id
    WHERE tm.organization_id=? AND tm.event_id=? AND tm.frc_team_number=?
    ORDER BY FIELD(tm.comp_level,'qm','ef','qf','sf','f','legacy'),m.set_number,tm.match_number,tm.match_id");
$metricsStmt->execute([$org,$eventId,$team]);
$metrics=$metricsStmt->fetchAll();

if(!$rating&&!$metrics){
    http_response_code(204);
    exit;
}

$nameStmt=$pdo->prepare("SELECT nickname FROM event_teams WHERE event_id=? AND frc_team_number=? LIMIT 1");
$nameStmt->execute([$eventId,$team]);
$nickname=trim((string)($nameStmt->fetchColumn()?:''));

$tagLabels=[
    'impressive_auton'=>'Impressive Auton','consistent_auton'=>'Consistent Auton','weak_auton'=>'Weak Auton','no_auton'=>'No Auton',
    'scoring_a_lot'=>'Scoring a Lot','fast_cycles'=>'Fast Cycles','hard_to_defend'=>'Hard to Defend','struggles_defense'=>'Struggles Under Defense',
    'good_defense'=>'Good Defense','elite_defense'=>'Elite Defense','counter_defense'=>'Good Counter-Defense','no_defense'=>'No Defense',
    'smart_driver'=>'Smart Driver','smooth_driver'=>'Smooth Driver','great_partner'=>'Great Alliance Partner','feeder_support'=>'Strong Feeder / Support',
    'strong_endgame'=>'Strong Endgame','reliable_endgame'=>'Reliable Endgame','slow_endgame'=>'Slow Endgame','failed_endgame'=>'Failed Endgame',
    'clutch'=>'Clutch','consistent'=>'Consistent','versatile'=>'Versatile','inconsistent'=>'Inconsistent','penalty_risk'=>'Penalty Risk',
    'mechanical_issues'=>'Mechanical Issues','disabled'=>'Disabled / Dead',
];

$eventTags=[];
$estimated=[];
foreach($metrics as &$m){
    if($m['estimated_points']!==null)$estimated[]=(float)$m['estimated_points'];
    $tags=json_decode((string)($m['tags_consensus_json']??'{}'),true);
    $weights=json_decode((string)($m['tag_weights_consensus_json']??'{}'),true);
    if(!is_array($tags))$tags=[];
    if(!is_array($weights))$weights=[];
    $m['_tags']=$tags;
    $m['_weights']=$weights;
    foreach($tags as $code=>$info){
        if(!is_array($info))$info=['count'=>(int)$info];
        $count=(int)($info['count']??0);
        if(!isset($eventTags[$code]))$eventTags[$code]=['count'=>0,'weighted_sum'=>0.0,'weighted_count'=>0];
        $eventTags[$code]['count']+=$count;
        $avg=$weights[$code]['avg_weight']??null;
        $wc=(int)($weights[$code]['weighted_count']??0);
        if(is_numeric($avg)&&$wc>0){
            $eventTags[$code]['weighted_sum']+=(float)$avg*$wc;
            $eventTags[$code]['weighted_count']+=$wc;
        }
    }
}
unset($m);

uasort($eventTags,static fn($a,$b)=>((int)$b['count'])<=>((int)$a['count']));
$avgEst=$estimated?array_sum($estimated)/count($estimated):null;
$nepEpa=$rating['neptune_epa']??null;
$nepDepa=$rating['neptune_depa']??null;
$offConf=$rating['offense_confidence']??null;
$defConf=$rating['defense_confidence']??null;
?>

<section class="robot-tag-source-panel" data-tag-epa-ratings>
  <div class="robot-intel-section-title">
    <div>
      <div class="module-eyebrow"><span>TAG SCOUTING</span><small><?=e((string)$event['name'])?></small></div>
      <h3 style="margin:3px 0 0">EPA Ratings</h3>
    </div>
    <span class="pill"><i class="fa-solid fa-hashtag"></i> Tag model</span>
  </div>

  <div class="robot-intel-rating-grid">
    <div class="robot-intel-rating augur"><span>Public EPA</span><b><?=tag_modal_num($rating['public_epa']??null,1)?></b><small>Public AUGUR baseline</small></div>
    <div class="robot-intel-rating primary"><span>Nep. EPA</span><b><?=tag_modal_num($nepEpa,1)?></b><small>Tag Scouting offense model</small></div>
    <div class="robot-intel-rating augur"><span>Public D-EPA</span><b><?=tag_modal_signed($rating['public_depa']??null,1)?></b><small>All-match public suppression</small></div>
    <div class="robot-intel-rating primary"><span>Nep. D-EPA</span><b><?=tag_modal_signed($nepDepa,1)?></b><small>Tag-verified defense only</small></div>
  </div>

  <div class="robot-tag-rating-meta">
    <span><b><?=$avgEst===null?'—':number_format($avgEst,0)?></b> est. pts / match</span>
    <span><b><?=tag_modal_num($offConf,0)?>%</b> offense confidence</span>
    <span><b><?=tag_modal_num($defConf,0)?>%</b> defense confidence</span>
  </div>

  <?php if($eventTags):?>
  <div class="robot-tag-consensus">
    <b>Consensus tags</b>
    <div class="robot-tag-chip-grid">
      <?php $shown=0;foreach($eventTags as $code=>$info):if($shown>=10)break;$shown++;$label=$tagLabels[$code]??ucwords(str_replace('_',' ',$code));$w=$info['weighted_count']>0?$info['weighted_sum']/$info['weighted_count']:null;?>
        <span class="robot-tag-chip"><b><?=e($label)?></b><small>×<?=e((int)$info['count'])?><?=$w!==null?' · '.number_format($w,1).'/5':''?></small></span>
      <?php endforeach;?>
    </div>
  </div>
  <?php endif;?>
</section>

<section class="robot-tag-section robot-tag-event-matches" data-tag-event-matches>
  <div class="robot-intel-section-title">
    <div>
      <div class="module-eyebrow"><span>MATCH DATA</span><small>Tag Scouting</small></div>
      <h3 style="margin:3px 0 0">Event Matches</h3>
    </div>
    <span class="pill"><?=count($metrics)?> match<?=count($metrics)===1?'':'es'?></span>
  </div>
  <?php if(!$metrics):?>
    <div class="robot-intel-empty">No match-level Tag Scouting estimates are available yet.</div>
  <?php else:?>
    <div class="table-wrap robot-tag-table-wrap">
      <table class="table robot-tag-table">
        <thead><tr><th>Match</th><th>Est. pts</th><th>Share</th><th>Defense</th><th>Raw D-EPA</th><th>Tags</th></tr></thead>
        <tbody>
        <?php foreach($metrics as $m):
            $matchLabel=(string)$m['comp_level']==='qm'?'Q'.(int)$m['match_number']:strtoupper((string)$m['comp_level']).' '.(int)$m['match_number'];
            $share=$m['effective_share']!==null?(float)$m['effective_share']:($m['raw_share_avg']!==null?(float)$m['raw_share_avg']:null);
            $parts=[];$i=0;
            foreach($m['_tags'] as $code=>$info){if($i>=3)break;$parts[]=$tagLabels[$code]??ucwords(str_replace('_',' ',$code));$i++;}
        ?>
          <tr>
            <td><b><?=e($matchLabel)?></b><br><small class="muted"><?=e((string)$m['alliance'])?> <?=e((int)$m['station'])?></small></td>
            <td><b><?=$m['estimated_points']===null?'—':number_format((float)$m['estimated_points'],0)?></b></td>
            <td><?=$share===null?'—':number_format($share,0).'%'?></td>
            <td><?=e((string)$m['defense_status'])?></td>
            <td><?=tag_modal_signed($m['raw_depa'],1)?></td>
            <td class="robot-tag-table-tags"><?=e($parts?implode(' · ',$parts):'—')?></td>
          </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
  <?php endif;?>
  <div class="robot-intel-model-note"><b>Tag Scouting match data.</b> Estimated points are consensus scoring share × alliance score. These rows replace Traditional action-by-action match data when the modal is opened from Tag Scouting.</div>
</section>
