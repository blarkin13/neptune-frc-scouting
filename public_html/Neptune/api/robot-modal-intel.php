<?php
declare(strict_types=1);

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_augur_prediction_model.php';

$u=require_login();
$org=(int)$u['organization_id'];
$eventId=(int)($_GET['event_id']??0);
$team=(int)($_GET['team']??0);

if($eventId<1||$team<1){
    http_response_code(400);
    exit('<div class="notice bad">Missing event or team.</div>');
}

$event=alliance_event($pdo,$org,$eventId);
if(!$event){
    http_response_code(404);
    exit('<div class="notice bad">Event not found.</div>');
}

$s=$pdo->prepare('SELECT nickname FROM event_teams WHERE event_id=? AND frc_team_number=? LIMIT 1');
$s->execute([$eventId,$team]);
$nickname=$s->fetchColumn();
if($nickname===false){
    http_response_code(404);
    exit('<div class="notice bad">That robot is not on this event roster.</div>');
}

function rmi_num(mixed $value,int $dec=1): string {
    return ($value===null||$value==='')?'—':number_format((float)$value,$dec);
}
function rmi_e(mixed $value): string {
    return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
}
function rmi_match_label(array $row): string {
    if(!empty($row['match_label'])) return (string)$row['match_label'];
    if(empty($row['match_id'])) return (($row['context']??'general')==='pit')?'Pit':'Team Only';
    return neptune_match_label($row);
}

/*
|--------------------------------------------------------------------------
| Public EPA
|--------------------------------------------------------------------------
| Read only from Neptune's local TBA-derived archive.
*/
$epa=null;
try{
    if(augur_epa_tables_ready($pdo)){
        $epaMap=augur_epa_rating_map($pdo,$org,$event,[$team],null);
        $epa=$epaMap[$team]??null;
    }
}catch(Throwable $ignored){
    $epa=null;
}

/*
|--------------------------------------------------------------------------
| Private Neptune EPA
|--------------------------------------------------------------------------
| Use the same model as Match Strategy / Alliance Selection. The second team is
| only used to satisfy the shared model runner; the value displayed below is the
| selected robot's pre-opponent Neptune EPA. TBA rankings are disabled here so a
| modal open never causes a live external ranking request.
*/
$augur=null;
$augurError='';
try{
    $settings=augur_prediction_settings();
    if(alliance_schema_ready($pdo)){
        $workspace=alliance_load_workspace($pdo,$org,$eventId,false);
        $settings=augur_prediction_settings($workspace['workspace']['settings']['matchup_model']??[]);
    }

    $s=$pdo->prepare('SELECT frc_team_number FROM event_teams WHERE event_id=? AND frc_team_number<>? ORDER BY frc_team_number LIMIT 1');
    $s->execute([$eventId,$team]);
    $other=(int)($s->fetchColumn()?:$team);

    $prediction=augur_prediction_run(
        $pdo,
        $org,
        $event,
        [$team],
        [$other],
        $settings,
        [
            'use_tba_rankings'=>false,
            'use_epa'=>true,
            'side_a_number'=>'Selected robot',
            'side_b_number'=>'Reference',
        ]
    );
    $augur=$prediction['a']['teams'][0]['metrics']??null;
}catch(Throwable $ex){
    $augurError=$ex->getMessage();
}

/*
|--------------------------------------------------------------------------
| Spot Scouting
|--------------------------------------------------------------------------
*/
$spotAvailable=false;
$spotRows=[];
$spotMedia=[];
$spotTagCounts=[];
$spotWarnings=0;
$spotHelper=dirname(__DIR__).'/spot/_helpers.php';
if(is_file($spotHelper)){
    require_once $spotHelper;
    try{
        if(function_exists('spot_tables_ready')&&spot_tables_ready($pdo)){
            $spotAvailable=true;
            $candidate=spot_observation_rows($pdo,$org,null,$team,80,false);
            foreach($candidate as $row){
                $rowEvent=$row['event_id']===null?null:(int)$row['event_id'];
                if($rowEvent!==null&&$rowEvent!==$eventId)continue;
                $spotRows[]=$row;
                if(count($spotRows)>=20)break;
            }

            if($spotRows&&function_exists('spot_media_for_observations')){
                $spotMedia=spot_media_for_observations($pdo,$org,array_column($spotRows,'id'));
            }

            foreach($spotRows as $row){
                if((string)($row['status']??'open')==='open'&&in_array((string)($row['severity']??'info'),['warning','critical'],true)){
                    $spotWarnings++;
                }
                foreach((array)($row['tags']??[]) as $tag){
                    $key=(int)($tag['id']??0);
                    if($key<1)$key=crc32((string)($tag['label']??'tag'));
                    if(!isset($spotTagCounts[$key])){
                        $spotTagCounts[$key]=[
                            'label'=>(string)($tag['label']??'Tag'),
                            'icon'=>(string)($tag['icon']??'fa-solid fa-tag'),
                            'severity'=>(string)($tag['severity']??'info'),
                            'count'=>0,
                        ];
                    }
                    $spotTagCounts[$key]['count']++;
                }
            }

            uasort($spotTagCounts,static function(array $a,array $b): int {
                $cmp=((int)$b['count'])<=>((int)$a['count']);
                return $cmp!==0?$cmp:strcmp((string)$a['label'],(string)$b['label']);
            });
        }
    }catch(Throwable $ignored){
        $spotAvailable=true;
        $spotRows=[];
        $spotMedia=[];
        $spotTagCounts=[];
    }
}
?>
<section class="robot-modal-section robot-intel-ratings">
  <div class="robot-intel-section-title">
    <h3><i class="fa-solid fa-chart-line"></i> EPA Ratings</h3>
    <span class="pill"><i class="fa-solid fa-database"></i> <?=rmi_e((int)($event['season_year']??0))?></span>
  </div>

  <div class="robot-intel-rating-grid">
    <div class="robot-intel-rating primary">
      <span>Public EPA</span>
      <b><?=rmi_num($epa['epa']??null,1)?></b>
      <small>Public TBA-derived rating</small>
    </div>
    <div class="robot-intel-rating">
      <span>Auto EPA</span>
      <b><?=rmi_num($epa['auto']??null,1)?></b>
      <small>Public archive</small>
    </div>
    <div class="robot-intel-rating">
      <span>Teleop EPA</span>
      <b><?=rmi_num($epa['teleop']??null,1)?></b>
      <small>Public archive</small>
    </div>
    <div class="robot-intel-rating">
      <span>Endgame EPA</span>
      <b><?=rmi_num($epa['endgame']??null,1)?></b>
      <small>Public archive</small>
    </div>

    <div class="robot-intel-rating augur">
      <span>Neptune EPA</span>
      <b><?=rmi_num($augur['neptune_epa']??$augur['augur_epa']??null,1)?></b>
      <small>EPA + Neptune scouting</small>
    </div>
    <div class="robot-intel-rating">
      <span>Event Avg</span>
      <b><?=rmi_num($augur['event_avg']??null,1)?></b>
      <small>Scouted points / match</small>
    </div>
    <div class="robot-intel-rating">
      <span>Recent Avg</span>
      <b><?=rmi_num($augur['recent_avg']??null,1)?></b>
      <small>Weighted recent form</small>
    </div>
    <div class="robot-intel-rating">
      <span>Neptune EPA Confidence</span>
      <b><?=isset($augur['confidence'])?rmi_num($augur['confidence'],0).'%':'—'?></b>
      <small><?=rmi_e($augur['baseline_source']??'No model baseline')?></small>
    </div>
  </div>

  <div class="robot-intel-model-note">
    <b>Public EPA</b> is Neptune's public TBA-derived rating. <b>Neptune EPA</b> is private to your organization and blends that Public EPA baseline with Neptune scouting, recent form, stabilized trend, and available prior evidence. Spot Scouting observations are shown separately and do not directly alter public EPA.
    <?php if($augurError!==''):?><span style="display:block;margin-top:4px">AUGUR model detail unavailable: <?=rmi_e($augurError)?></span><?php endif;?>
  </div>
</section>

<section class="robot-modal-section robot-intel-spot">
  <div class="robot-intel-section-title">
    <h3><i class="fa-solid fa-binoculars"></i> Spot Scouting</h3>
    <?php if($spotWarnings>0):?>
      <span class="pill" style="color:var(--bad)"><i class="fa-solid fa-triangle-exclamation"></i> <?=$spotWarnings?> open warning<?=$spotWarnings===1?'':'s'?></span>
    <?php else:?>
      <span class="pill"><i class="fa-solid fa-check"></i> No open warnings</span>
    <?php endif;?>
  </div>

  <?php if(!$spotAvailable):?>
    <div class="robot-intel-empty">Spot Scouting is not installed on this Neptune server.</div>
  <?php elseif(!$spotRows):?>
    <div class="robot-intel-empty">No Spot Scouting observations have been recorded for team #<?=rmi_e($team)?> at this event.</div>
  <?php else:?>
    <?php if($spotTagCounts):?>
      <div class="robot-intel-spot-summary">
        <?php foreach(array_slice(array_values($spotTagCounts),0,10) as $tag):
          $severity=in_array($tag['severity'],['positive','warning','critical'],true)?$tag['severity']:'';
        ?>
          <span class="robot-intel-spot-chip <?=rmi_e($severity)?>">
            <i class="<?=rmi_e($tag['icon'])?>"></i>
            <?=rmi_e($tag['label'])?>
            <b>×<?=rmi_e($tag['count'])?></b>
          </span>
        <?php endforeach;?>
      </div>
    <?php endif;?>

    <div class="robot-intel-spot-feed">
      <?php foreach(array_slice($spotRows,0,8) as $row):
        $severity=in_array((string)($row['severity']??''),['positive','warning','critical'],true)?(string)$row['severity']:'';
        $media=$spotMedia[(int)$row['id']]??[];
      ?>
        <article class="robot-intel-spot-item <?=rmi_e($severity)?>">
          <div class="robot-intel-spot-head">
            <div>
              <b><?=rmi_e(rmi_match_label($row))?></b>
              <?php if(!empty($row['event_name'])):?><span> · <?=rmi_e($row['event_name'])?></span><?php endif;?>
            </div>
            <span><?=rmi_e(($row['status']??'open')==='resolved'?'Resolved':'Open')?></span>
          </div>

          <?php if(!empty($row['tags'])):?>
            <div class="robot-intel-spot-tags">
              <?php foreach($row['tags'] as $tag):?>
                <span class="robot-intel-spot-tag"><i class="<?=rmi_e($tag['icon']??'fa-solid fa-tag')?>"></i><?=rmi_e($tag['label']??'Tag')?></span>
              <?php endforeach;?>
            </div>
          <?php endif;?>

          <?php if(trim((string)($row['note']??''))!==''):?>
            <p class="robot-intel-spot-note"><?=nl2br(rmi_e($row['note']))?></p>
          <?php endif;?>

          <?php if($media):?>
            <div class="robot-intel-spot-media">
              <?php foreach($media as $item):?>
                <?php if(($item['media_type']??'')==='photo'):?>
                  <a href="<?=rmi_e($item['url'])?>" target="_blank" rel="noopener">
                    <img src="<?=rmi_e($item['url'])?>" loading="lazy" alt="<?=rmi_e($item['original_filename']??'Spot scouting photo')?>">
                  </a>
                <?php else:?>
                  <div class="video">
                    <video controls preload="metadata" src="<?=rmi_e($item['url'])?>"></video>
                    <span class="video-label"><i class="fa-solid fa-video"></i><?=rmi_e($item['original_filename']??'Video')?></span>
                  </div>
                <?php endif;?>
              <?php endforeach;?>
            </div>
          <?php endif;?>

          <div class="robot-intel-spot-meta">
            <?php if(!empty($row['scout_name'])):?><span><i class="fa-solid fa-user"></i> <?=rmi_e($row['scout_name'])?></span><?php endif;?>
            <?php if(!empty($row['created_at'])):?><span><i class="fa-regular fa-clock"></i> <?=rmi_e($row['created_at'])?></span><?php endif;?>
            <?php if(!empty($row['resolution_note'])):?><span><i class="fa-solid fa-check"></i> <?=rmi_e($row['resolution_note'])?></span><?php endif;?>
          </div>
        </article>
      <?php endforeach;?>
    </div>
  <?php endif;?>
</section>
