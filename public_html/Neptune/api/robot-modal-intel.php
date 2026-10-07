<?php
declare(strict_types=1);

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_augur_prediction_model.php';
require_once dirname(__DIR__).'/analytics/_depa_metrics.php';

$u=require_login();
$org=(int)$u['organization_id'];
$eventId=(int)($_GET['event_id']??0);
$team=(int)($_GET['team']??0);
if($eventId<1||$team<1){http_response_code(400);exit('<div class="notice bad">Missing event or team.</div>');}

$event=alliance_event($pdo,$org,$eventId);
if(!$event){http_response_code(404);exit('<div class="notice bad">Event not found.</div>');}
$s=$pdo->prepare('SELECT nickname FROM event_teams WHERE event_id=? AND frc_team_number=? LIMIT 1');$s->execute([$eventId,$team]);$nickname=$s->fetchColumn();
if($nickname===false){http_response_code(404);exit('<div class="notice bad">That robot is not on this event roster.</div>');}

function rmi_num(mixed $value,int $dec=1): string {return ($value===null||$value==='')?'—':number_format((float)$value,$dec);}
function rmi_e(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function rmi_match_label(array $row): string {if(!empty($row['match_label']))return (string)$row['match_label'];if(empty($row['match_id']))return (($row['context']??'general')==='pit')?'Pit':'Team';return neptune_match_label($row);}
function rmi_spark_points(array $rows,int $width=760,int $height=150): string {
    $vals=[];foreach($rows as $row){if(isset($row['post_rating'])&&is_numeric($row['post_rating']))$vals[]=(float)$row['post_rating'];}
    $count=count($vals);if($count<2)return '';$min=min($vals);$max=max($vals);$range=max(1.0,$max-$min);$points=[];
    foreach($vals as $i=>$value){$x=18+($i/($count-1))*($width-36);$y=$height-18-(($value-$min)/$range)*($height-36);$points[]=number_format($x,1,'.','').','.number_format($y,1,'.','');}
    return implode(' ',$points);
}

$epa=null;
try{if(augur_epa_tables_ready($pdo)){$epaMap=augur_epa_rating_map($pdo,$org,$event,[$team],null);$epa=$epaMap[$team]??null;}}catch(Throwable $ignored){$epa=null;}

$depa=null;
try{$dm=neptune_depa_event_map_with_season_fallback($pdo,$event,[$team]);$depa=$dm[$team]??null;}catch(Throwable $ignored){$depa=null;}

$augur=null;$augurError='';
try{
    $settings=augur_prediction_settings();
    if(alliance_schema_ready($pdo)){$workspace=alliance_load_workspace($pdo,$org,$eventId,false);$settings=augur_prediction_settings($workspace['workspace']['settings']['matchup_model']??[]);}
    $s=$pdo->prepare('SELECT frc_team_number FROM event_teams WHERE event_id=? AND frc_team_number<>? ORDER BY frc_team_number LIMIT 1');$s->execute([$eventId,$team]);$other=(int)($s->fetchColumn()?:$team);
    $prediction=augur_prediction_run($pdo,$org,$event,[$team],[$other],$settings,['use_tba_rankings'=>false,'use_epa'=>true,'side_a_number'=>'Selected robot','side_b_number'=>'Reference']);
    $augur=$prediction['a']['teams'][0]['metrics']??null;
}catch(Throwable $ex){$augurError=$ex->getMessage();}

$publicRank=null;$publicCount=0;$eventRecord=null;
try{
    $eventKey=trim((string)($event['tba_event_key']??''));
    if($eventKey!==''){
        $s=$pdo->prepare('SELECT rating,wins,losses,ties FROM augur_epa_event_ratings WHERE tba_event_key=? AND frc_team_number=? LIMIT 1');$s->execute([$eventKey,$team]);$ratingRow=$s->fetch();
        if($ratingRow){
            $s=$pdo->prepare('SELECT COUNT(*) AS total,1+SUM(CASE WHEN rating>? THEN 1 ELSE 0 END) AS rank_num FROM augur_epa_event_ratings WHERE tba_event_key=?');$s->execute([(float)$ratingRow['rating'],$eventKey]);$rankRow=$s->fetch();
            $publicCount=(int)($rankRow['total']??0);$publicRank=$publicCount>0?(int)($rankRow['rank_num']??0):null;
            $eventRecord=['wins'=>(int)$ratingRow['wins'],'losses'=>(int)$ratingRow['losses'],'ties'=>(int)$ratingRow['ties']];
        }
    }
}catch(Throwable $ignored){}

$trajectory=[];
try{
    $eventKey=trim((string)($event['tba_event_key']??''));
    if($eventKey!==''){$s=$pdo->prepare('SELECT match_number,post_rating,rating_delta,post_auto_rating,post_teleop_rating,post_endgame_rating FROM augur_epa_match_ratings WHERE tba_event_key=? AND frc_team_number=? ORDER BY match_number');$s->execute([$eventKey,$team]);$trajectory=$s->fetchAll();}
}catch(Throwable $ignored){$trajectory=[];}
$trajectoryPoints=rmi_spark_points($trajectory);

$spotAvailable=false;$spotRows=[];$spotMedia=[];$spotTagCounts=[];$spotWarnings=0;
$spotHelper=dirname(__DIR__).'/scout/_tag_scouting.php';
if(is_file($spotHelper)){
    require_once $spotHelper;
    try{
        if(function_exists('tagobs_tables_ready')&&tagobs_tables_ready($pdo)){
            $spotAvailable=true;$candidate=tagobs_observation_rows($pdo,$org,null,$team,80,false);
            foreach($candidate as $row){$rowEvent=$row['event_id']===null?null:(int)$row['event_id'];if($rowEvent!==null&&$rowEvent!==$eventId)continue;$spotRows[]=$row;if(count($spotRows)>=20)break;}
            if($spotRows&&function_exists('tagobs_media_for_observations'))$spotMedia=tagobs_media_for_observations($pdo,$org,array_column($spotRows,'id'));
            foreach($spotRows as $row){
                if((string)($row['status']??'open')==='open'&&in_array((string)($row['severity']??'info'),['warning','critical'],true))$spotWarnings++;
                foreach((array)($row['tags']??[]) as $tag){$key=(int)($tag['id']??0);if($key<1)$key=crc32((string)($tag['label']??'tag'));if(!isset($spotTagCounts[$key]))$spotTagCounts[$key]=['label'=>(string)($tag['label']??'Tag'),'icon'=>(string)($tag['icon']??'fa-solid fa-tag'),'severity'=>(string)($tag['severity']??'info'),'count'=>0];$spotTagCounts[$key]['count']++;}
            }
            uasort($spotTagCounts,static function(array $a,array $b): int {$cmp=((int)$b['count'])<=>((int)$a['count']);return $cmp!==0?$cmp:strcmp((string)$a['label'],(string)$b['label']);});
        }
    }catch(Throwable $ignored){$spotAvailable=true;$spotRows=[];$spotMedia=[];$spotTagCounts=[];}
}

$public=$epa['epa']??null;$neptune=$augur['neptune_epa']??$augur['augur_epa']??null;$delta=(is_numeric($public)&&is_numeric($neptune))?((float)$neptune-(float)$public):null;
$trend=$augur['trend_adjustment']??0.0;$confidence=$augur['confidence']??null;$eventAvg=$augur['event_avg']??null;$recentAvg=$augur['recent_avg']??null;$p75=$augur['p75']??null;$volatility=$augur['volatility']??null;
$trendClass=abs((float)$trend)<0.05?'flat':((float)$trend>0?'up':'down');$trendLabel=$trendClass==='up'?'Trending up':($trendClass==='down'?'Trending down':'Stable');
$verified=(int)($depa['verified_defense_matches']??0);$possible=(int)($depa['possible_defense_matches']??0);$defConfidence=is_numeric($depa['confidence_score']??null)?(float)$depa['confidence_score']:null;$defValidation=trim((string)($depa['validation_label']??''));
?>
<div data-robot-intel-target="overview">
  <section class="robot-modal-section robot-intel-dashboard">
    <div class="robot-overview-hero-grid">
      <div class="robot-overview-neptune">
        <span>Neptune EPA</span>
        <b><?=rmi_num($neptune,1)?></b>
        <?php if($delta!==null):?><small class="<?=$delta>0?'positive':($delta<0?'negative':'')?>"><?=$delta>0?'+':''?><?=rmi_num($delta,1)?> vs Public EPA</small><?php else:?><small>Private AUGUR offense rating</small><?php endif;?>
      </div>
      <div class="robot-overview-kpi"><span>Public EPA rank</span><b><?=$publicRank!==null?'#'.rmi_e($publicRank).' / '.rmi_e($publicCount):'—'?></b><small>Event field</small></div>
      <div class="robot-overview-kpi"><span>Trend</span><b class="<?=rmi_e($trendClass)?>"><i class="fa-solid <?=$trendClass==='up'?'fa-arrow-trend-up':($trendClass==='down'?'fa-arrow-trend-down':'fa-minus')?>"></i> <?=rmi_num($trend,1)?></b><small><?=rmi_e($trendLabel)?><?php if(is_numeric($volatility)):?> · σ <?=rmi_num($volatility,1)?><?php endif;?></small></div>
      <div class="robot-overview-kpi"><span>Confidence</span><b><?=is_numeric($confidence)?rmi_num($confidence,0).'%':'—'?></b><small><?=rmi_e($augur['baseline_source']??'AUGUR model')?></small></div>
    </div>

    <div class="robot-intel-rating-grid robot-intel-rating-grid-v2">
      <div class="robot-intel-rating primary"><span>Public EPA</span><b><?=rmi_num($public,1)?></b><small>TBA-derived</small></div>
      <div class="robot-intel-rating"><span>Auto EPA</span><b><?=rmi_num($augur['auto_epa']??$epa['auto']??null,1)?></b><small>Autonomous</small></div>
      <div class="robot-intel-rating"><span>Teleop EPA</span><b><?=rmi_num($augur['teleop_epa']??$epa['teleop']??null,1)?></b><small>Teleoperated</small></div>
      <div class="robot-intel-rating"><span>Endgame EPA</span><b><?=rmi_num($augur['endgame_epa']??$epa['endgame']??null,1)?></b><small>Endgame</small></div>
      <div class="robot-intel-rating"><span>Event Avg</span><b><?=rmi_num($eventAvg,1)?></b><small>Scouted output</small></div>
      <div class="robot-intel-rating"><span>Recent Avg</span><b><?=rmi_num($recentAvg,1)?></b><small>Recent form</small></div>
      <div class="robot-intel-rating"><span>P75 Ceiling</span><b><?=rmi_num($p75,1)?></b><small>Upper quartile</small></div>
      <div class="robot-intel-rating"><span>Record</span><b><?=$eventRecord?rmi_e($eventRecord['wins'].'-'.$eventRecord['losses'].'-'.$eventRecord['ties']):'—'?></b><small><?=rmi_e($event['name']??'Event')?></small></div>
    </div>
  </section>

  <section class="robot-modal-section robot-defense-panel">
    <div class="robot-intel-section-title"><h3><i class="fa-solid fa-shield-halved"></i> Defense</h3><?php if($defConfidence!==null):?><span class="pill"><?=rmi_num($defConfidence,0)?>% confidence</span><?php elseif($defValidation!==''):?><span class="pill"><?=rmi_e($defValidation)?></span><?php endif;?></div>
    <div class="robot-defense-dashboard">
      <div><span>Neptune D-EPA</span><b><?=rmi_num($depa['neptune_depa']??null,1)?></b></div>
      <div><span>Public D-EPA</span><b><?=rmi_num($depa['depa']??null,1)?></b></div>
      <div><span>Verified matches</span><b><?=rmi_e($verified)?></b></div>
      <div><span>Possible matches</span><b><?=rmi_e($possible)?></b></div>
    </div>
  </section>

  <section class="robot-modal-section robot-scout-consensus-preview">
    <div class="robot-intel-section-title"><h3><i class="fa-solid fa-users-viewfinder"></i> Scout Consensus</h3><?php if($spotWarnings>0):?><span class="pill robot-warning-pill"><i class="fa-solid fa-triangle-exclamation"></i> <?=$spotWarnings?> open warning<?=$spotWarnings===1?'':'s'?></span><?php else:?><span class="pill"><i class="fa-solid fa-check"></i> No open warnings</span><?php endif;?></div>
    <?php if(!$spotAvailable):?><div class="robot-intel-empty">Tag Scouting is not installed on this Neptune server.</div>
    <?php elseif(!$spotRows):?><div class="robot-intel-empty">No Tag Scouting observations have been recorded for this robot at this event.</div>
    <?php else:?>
      <div class="robot-intel-spot-summary"><?php foreach(array_slice(array_values($spotTagCounts),0,5) as $tag):$severity=in_array($tag['severity'],['positive','warning','critical'],true)?$tag['severity']:'';?><span class="robot-intel-spot-chip <?=rmi_e($severity)?>"><i class="<?=rmi_e($tag['icon'])?>"></i><?=rmi_e($tag['label'])?><b>×<?=rmi_e($tag['count'])?></b></span><?php endforeach;?></div>
      <button type="button" class="btn secondary compact robot-jump-tab" data-robot-jump-tab="scout"><i class="fa-solid fa-binoculars"></i> View all scout intel</button>
    <?php endif;?>
    <?php if($augurError!==''):?><div class="robot-intel-model-note">AUGUR model detail unavailable: <?=rmi_e($augurError)?></div><?php endif;?>
  </section>
</div>

<div data-robot-intel-target="matches">
  <?php if($trajectory):?>
  <section class="robot-modal-section robot-form-chart-section">
    <div class="robot-intel-section-title"><h3><i class="fa-solid fa-wave-square"></i> EPA Progression</h3><span class="pill"><?=count($trajectory)?> rated match<?=count($trajectory)===1?'':'es'?></span></div>
    <?php if($trajectoryPoints!==''):?>
      <div class="robot-performance-chart">
        <svg viewBox="0 0 760 150" preserveAspectRatio="none" role="img" aria-label="EPA progression across event matches"><line x1="18" y1="132" x2="742" y2="132" class="axis"></line><polyline points="<?=rmi_e($trajectoryPoints)?>"></polyline></svg>
        <div class="robot-performance-chart-labels"><span>Q<?=rmi_e($trajectory[0]['match_number'])?> · <?=rmi_num($trajectory[0]['post_rating'],1)?></span><span>Q<?=rmi_e($trajectory[count($trajectory)-1]['match_number'])?> · <?=rmi_num($trajectory[count($trajectory)-1]['post_rating'],1)?></span></div>
      </div>
    <?php else:?><div class="robot-intel-empty">More rated matches are needed for an EPA trend line.</div><?php endif;?>
  </section>
  <?php endif;?>
</div>

<div data-robot-intel-target="auton">
  <div class="robot-auto-rating-strip"><div><span>Auto EPA</span><b><?=rmi_num($augur['auto_epa']??$epa['auto']??null,1)?></b></div><div><span>Neptune EPA</span><b><?=rmi_num($neptune,1)?></b></div><div><span>Confidence</span><b><?=is_numeric($confidence)?rmi_num($confidence,0).'%':'—'?></b></div></div>
</div>

<div data-robot-intel-target="scout">
  <section class="robot-modal-section robot-intel-spot">
    <div class="robot-intel-section-title"><h3><i class="fa-solid fa-binoculars"></i> Tag Scouting</h3><?php if($spotWarnings>0):?><span class="pill robot-warning-pill"><i class="fa-solid fa-triangle-exclamation"></i> <?=$spotWarnings?> open warning<?=$spotWarnings===1?'':'s'?></span><?php else:?><span class="pill"><i class="fa-solid fa-check"></i> No open warnings</span><?php endif;?></div>
    <?php if(!$spotAvailable):?><div class="robot-intel-empty">Tag Scouting is not installed on this Neptune server.</div>
    <?php elseif(!$spotRows):?><div class="robot-intel-empty">No Tag Scouting observations have been recorded for team #<?=rmi_e($team)?> at this event.</div>
    <?php else:?>
      <?php if($spotTagCounts):?><div class="robot-intel-spot-summary"><?php foreach(array_slice(array_values($spotTagCounts),0,10) as $tag):$severity=in_array($tag['severity'],['positive','warning','critical'],true)?$tag['severity']:'';?><span class="robot-intel-spot-chip <?=rmi_e($severity)?>"><i class="<?=rmi_e($tag['icon'])?>"></i><?=rmi_e($tag['label'])?><b>×<?=rmi_e($tag['count'])?></b></span><?php endforeach;?></div><?php endif;?>
      <div class="robot-intel-spot-feed">
        <?php foreach($spotRows as $row):$severity=in_array((string)($row['severity']??''),['positive','warning','critical'],true)?(string)$row['severity']:'';$media=$spotMedia[(int)$row['id']]??[];?>
        <article class="robot-intel-spot-item <?=rmi_e($severity)?>">
          <div class="robot-intel-spot-head"><div><b><?=rmi_e(rmi_match_label($row))?></b><?php if(!empty($row['event_name'])):?><span> · <?=rmi_e($row['event_name'])?></span><?php endif;?></div><span><?=rmi_e(($row['status']??'open')==='resolved'?'Resolved':'Open')?></span></div>
          <?php if(!empty($row['tags'])):?><div class="robot-intel-spot-tags"><?php foreach($row['tags'] as $tag):?><span class="robot-intel-spot-tag"><i class="<?=rmi_e($tag['icon']??'fa-solid fa-tag')?>"></i><?=rmi_e($tag['label']??'Tag')?></span><?php endforeach;?></div><?php endif;?>
          <?php if(trim((string)($row['note']??''))!==''):?><p class="robot-intel-spot-note"><?=nl2br(rmi_e($row['note']))?></p><?php endif;?>
          <?php if($media):?><div class="robot-intel-spot-media"><?php foreach($media as $item):?><?php if(($item['media_type']??'')==='photo'):?><a href="<?=rmi_e($item['url'])?>" target="_blank" rel="noopener"><img src="<?=rmi_e($item['url'])?>" loading="lazy" alt="<?=rmi_e($item['original_filename']??'Tag scouting photo')?>"></a><?php else:?><div class="video"><video controls preload="metadata" src="<?=rmi_e($item['url'])?>"></video><span class="video-label"><i class="fa-solid fa-video"></i><?=rmi_e($item['original_filename']??'Video')?></span></div><?php endif;?><?php endforeach;?></div><?php endif;?>
          <div class="robot-intel-spot-meta"><?php if(!empty($row['scout_name'])):?><span><i class="fa-solid fa-user"></i> <?=rmi_e($row['scout_name'])?></span><?php endif;?><?php if(!empty($row['created_at'])):?><span><i class="fa-regular fa-clock"></i> <?=rmi_e($row['created_at'])?></span><?php endif;?><?php if(!empty($row['resolution_note'])):?><span><i class="fa-solid fa-check"></i> <?=rmi_e($row['resolution_note'])?></span><?php endif;?></div>
        </article>
        <?php endforeach;?>
      </div>
    <?php endif;?>
  </section>
</div>
