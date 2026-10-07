<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
require_once __DIR__.'/_depa_metrics.php';
require_once __DIR__.'/_augur_prediction_model.php';
if(is_file(__DIR__.'/_team_logo_cache.php')) require_once __DIR__.'/_team_logo_cache.php';

$u=require_login();
$org=(int)$u['organization_id'];
$tv=isset($_GET['tv'])&&$_GET['tv']=='1';

$events=neptune_event_list($pdo,$org);
$eventId=(int)($_GET['event_id']??($events[0]['id']??0));

$s=$pdo->prepare('SELECT * FROM teams WHERE organization_id=? AND active=1 ORDER BY frc_team_number');
$s->execute([$org]);
$myTeams=$s->fetchAll();

$teamNumber=(int)($_GET['team']??($myTeams[0]['frc_team_number']??0));
$matches=[];
$matchActuals=[];
$eventStats=[];
$augurMap=[];
$depaMap=[];
$tagMap=[];
$logoMap=[];
$event=null;
$seasonYear=(int)date('Y');

if($eventId){
    try{$eventStats=neptune_event_robot_stats($pdo,$org,$eventId);}catch(Throwable $ignored){$eventStats=[];}
    try{$event=alliance_event($pdo,$org,$eventId);}catch(Throwable $ignored){$event=null;}
    if($event)$seasonYear=(int)($event['season_year']??$seasonYear);

    $teamNumbers=array_values(array_unique(array_filter(array_map('intval',array_keys($eventStats)),fn($n)=>$n>0)));

    // Use the same private Neptune EPA model as Robot Intelligence so the
    // schedule cards and detail modal tell the same story.
    if($event&&count($teamNumbers)>=2){
        try{
            $settings=augur_prediction_settings();
            if(alliance_schema_ready($pdo)){
                $workspace=alliance_load_workspace($pdo,$org,$eventId,false);
                $settings=augur_prediction_settings($workspace['workspace']['settings']['matchup_model']??[]);
            }
            $cut=(int)ceil(count($teamNumbers)/2);
            $sideA=array_slice($teamNumbers,0,$cut);
            $sideB=array_slice($teamNumbers,$cut);
            if(!$sideB)$sideB=[$sideA[0]];
            $prediction=augur_prediction_run($pdo,$org,$event,$sideA,$sideB,$settings,['use_tba_rankings'=>false,'use_epa'=>true]);
            foreach(array_merge($prediction['a']['teams']??[],$prediction['b']['teams']??[]) as $row){
                $n=(int)($row['team']??0);
                if($n<1)continue;
                $m=is_array($row['metrics']??null)?$row['metrics']:[];
                $augurMap[$n]=$m;
            }
        }catch(Throwable $ignored){$augurMap=[];}

        try{$depaMap=neptune_depa_event_map_with_season_fallback($pdo,$event,$teamNumbers);}catch(Throwable $ignored){$depaMap=[];}

        // Keep the board terse: surface only the two strongest/current scout
        // signals for each team. Full tag history remains in Robot Intelligence.
        try{
            $q=$pdo->prepare("SELECT o.frc_team_number,t.label,t.icon,t.severity,COUNT(*) AS mentions,MAX(o.created_at) AS latest_at
                FROM tag_scouting_observations o
                JOIN tag_scouting_observation_tags ot ON ot.observation_id=o.id
                JOIN tag_scouting_tags t ON t.id=ot.tag_id
                LEFT JOIN tag_scouting_tag_visibility tv ON tv.organization_id=o.organization_id AND tv.tag_id=t.id
                WHERE o.organization_id=? AND o.event_id=? AND o.status='open' AND t.active=1 AND COALESCE(tv.active,1)=1
                GROUP BY o.frc_team_number,t.id,t.label,t.icon,t.severity
                ORDER BY o.frc_team_number,mentions DESC,latest_at DESC");
            $q->execute([$org,$eventId]);
            foreach($q->fetchAll() as $row){
                $n=(int)$row['frc_team_number'];
                if(count($tagMap[$n]??[])<2)$tagMap[$n][]=$row;
            }
        }catch(Throwable $ignored){$tagMap=[];}

        if(function_exists('neptune_team_logo_map')){
            try{$logoMap=neptune_team_logo_map($org,$seasonYear,$teamNumbers);}catch(Throwable $ignored){$logoMap=[];}
        }
    }
}

if($eventId&&$teamNumber){
    $s=$pdo->prepare("SELECT m.*
        FROM matches m
        JOIN match_teams own ON own.match_id=m.id AND own.frc_team_number=?
        WHERE m.organization_id=? AND m.event_id=?
        ORDER BY FIELD(m.comp_level,'qm','ef','qf','sf','f','legacy'),m.set_number,m.match_number");
    $s->execute([$teamNumber,$org,$eventId]);
    $matches=$s->fetchAll();

    $mt=$pdo->prepare("SELECT alliance,station,frc_team_number
        FROM match_teams
        WHERE match_id=?
        ORDER BY FIELD(alliance,'Red','Blue'),station");
    foreach($matches as &$m){
        $mt->execute([$m['id']]);
        $m['teams']=$mt->fetchAll();
    }
    unset($m);

    // Keep one historical value from the original Match Board: the points this
    // robot actually produced in that specific scouted match run.
    $a=$pdo->prepare("SELECT sa.match_id,sa.frc_team_number,SUM(sa.points) AS scout_points
        FROM scouting_actions sa
        JOIN matches m ON m.id=sa.match_id AND m.organization_id=sa.organization_id AND m.event_id=sa.event_id
        WHERE sa.organization_id=? AND sa.event_id=? AND sa.deleted_at IS NULL
          AND sa.match_run_number=m.run_number
        GROUP BY sa.match_id,sa.frc_team_number");
    $a->execute([$org,$eventId]);
    foreach($a->fetchAll() as $r)$matchActuals[(int)$r['match_id']][(int)$r['frc_team_number']]=(float)$r['scout_points'];
}

function mb_fmt_metric(mixed $v,int $dec=1,string $suffix=''): string {
    return is_numeric($v)?number_format((float)$v,$dec).$suffix:'—';
}
function mb_tag_class(string $severity): string {
    return in_array($severity,['positive','warning','critical'],true)?$severity:'info';
}

$pageTitle='Match Board';
$moduleName='AUGUR';
$hideChrome=$tv;
$bodyClass=$tv?'tv-mode':'';
include dirname(__DIR__).'/partials_header.php';
?>
<style>
/* Match Board v4 — keep the familiar schedule, but make each robot tile a
   compact scouting snapshot instead of a mostly-empty score box. */
.mb-titlebar{align-items:flex-end}.mb-subtitle{max-width:900px}
.mb-key{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}.mb-key span{display:inline-flex;align-items:center;gap:5px;padding:4px 7px;border:1px solid var(--line);border-radius:999px;background:var(--panel2);font-size:.66rem;font-weight:850;color:var(--muted)}

.schedule-board{gap:14px}
.schedule-match{grid-template-columns:176px minmax(0,1fr);gap:14px;padding:0;overflow:hidden;border-radius:10px;box-shadow:0 5px 18px color-mix(in srgb,#000 6%,transparent);opacity:1!important}
.schedule-match.current{border:2px solid var(--c1-green)}
.mb-match-meta{padding:15px 14px;background:linear-gradient(180deg,var(--panel2),var(--panel));border-right:1px solid var(--line);display:flex;flex-direction:column;justify-content:center;min-width:0}
.mb-match-state{font-size:.64rem;font-weight:950;letter-spacing:.09em;text-transform:uppercase;color:var(--muted)}
.mb-match-label{font-size:1.55rem;line-height:1.05;margin:4px 0 7px;letter-spacing:-.03em}
.mb-match-time{font-size:.72rem;line-height:1.25;color:var(--muted)}
.mb-score{margin-top:10px;display:flex;align-items:baseline;gap:6px}.mb-score b{font-size:1rem}.mb-score span{font-size:.68rem;color:var(--muted);font-weight:800}
.match-result-badge{display:inline-flex;align-items:center;justify-content:center;align-self:flex-start;margin-top:8px;padding:4px 9px;border:1px solid var(--line);border-radius:999px;font-size:.67rem;font-weight:950;letter-spacing:.06em}
.match-result-badge.win{color:var(--good);border-color:color-mix(in srgb,var(--good) 50%,var(--line));background:color-mix(in srgb,var(--good) 8%,var(--panel2))}
.match-result-badge.loss{color:var(--bad);border-color:color-mix(in srgb,var(--bad) 50%,var(--line));background:color-mix(in srgb,var(--bad) 8%,var(--panel2))}
.match-result-badge.tie{color:var(--warn);border-color:color-mix(in srgb,var(--warn) 50%,var(--line));background:color-mix(in srgb,var(--warn) 8%,var(--panel2))}

.schedule-teams{padding:12px;gap:8px;align-items:stretch}
.schedule-robot.robot-detail-trigger{position:relative;display:flex;flex-direction:column;align-items:stretch;width:100%;min-width:0;min-height:176px;padding:10px;border-radius:8px;box-sizing:border-box;background:linear-gradient(180deg,var(--panel2),color-mix(in srgb,var(--panel2) 88%,var(--panel)));text-align:left;overflow:hidden;transition:transform .14s ease,border-color .14s ease,box-shadow .14s ease}
.schedule-robot.robot-detail-trigger.red{border:1px solid var(--line);border-top:4px solid var(--c1-red)}
.schedule-robot.robot-detail-trigger.blue{border:1px solid var(--line);border-top:4px solid var(--ready)}
.schedule-robot.robot-detail-trigger:hover,.schedule-robot.robot-detail-trigger:focus-visible{transform:translateY(-2px);border-color:var(--accent);box-shadow:0 8px 18px color-mix(in srgb,#000 10%,transparent)}
.schedule-robot.is-our-team{box-shadow:inset 0 0 0 2px var(--c1-green)}
.schedule-robot.is-our-team::after{content:'OUR TEAM';position:absolute;right:7px;top:7px;padding:2px 5px;border-radius:999px;background:color-mix(in srgb,var(--c1-green) 13%,var(--panel));border:1px solid color-mix(in srgb,var(--c1-green) 50%,var(--line));color:var(--c1-green);font-size:.48rem;font-weight:950;letter-spacing:.05em}
.mb-team-head{display:grid;grid-template-columns:34px minmax(0,1fr);gap:8px;align-items:center;width:100%;padding-right:18px;box-sizing:border-box}
.mb-logo{width:34px;height:34px;border:1px solid var(--line);border-radius:7px;background:#fff;display:grid;place-items:center;overflow:hidden;color:var(--muted)}.mb-logo img{width:100%;height:100%;object-fit:contain;padding:3px}.mb-logo i{font-size:.95rem}
.mb-team-copy{min-width:0}.mb-station{display:block;color:var(--muted);font-size:.59rem;font-weight:900;text-transform:uppercase;letter-spacing:.055em}.mb-team-number{display:block;font-size:1.24rem!important;line-height:1.05;margin-top:2px;letter-spacing:-.02em}.mb-team-name{display:block;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--muted);font-size:.59rem;line-height:1.1}
.schedule-robot-info{right:6px;bottom:6px;top:auto;font-size:.65rem}

.mb-metrics{display:grid;grid-template-columns:1fr 1fr;gap:5px;width:100%;margin-top:9px}.mb-metric{min-width:0;padding:6px 7px;border:1px solid color-mix(in srgb,var(--line) 85%,transparent);border-radius:6px;background:color-mix(in srgb,var(--panel) 52%,transparent)}.mb-metric span{display:block;font-size:.49rem;line-height:1;text-transform:uppercase;letter-spacing:.055em;font-weight:900;color:var(--muted)}.mb-metric b{display:block;margin-top:4px;font-size:.82rem;line-height:1;color:var(--text)}.mb-metric.epa b{color:var(--module-augur,var(--accent))}
.mb-defense-line{display:flex;width:100%;box-sizing:border-box;align-items:center;gap:5px;margin-top:6px;padding-top:6px;border-top:1px solid var(--line);font-size:.58rem;font-weight:850;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mb-defense-line i{font-size:.57rem}
.mb-tag-strip{display:flex;width:100%;box-sizing:border-box;gap:4px;min-height:21px;margin-top:6px;overflow:hidden}.mb-tag{display:inline-flex;align-items:center;gap:3px;max-width:50%;padding:3px 5px;border:1px solid var(--line);border-radius:999px;background:var(--panel);font-size:.49rem;font-weight:850;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mb-tag i{font-size:.48rem}.mb-tag.positive{color:var(--good)}.mb-tag.warning{color:#b88116}.mb-tag.critical{color:var(--bad)}.mb-tag.info{color:var(--muted)}
.mb-match-actual{width:100%;box-sizing:border-box;margin-top:auto;padding-top:6px;font-size:.53rem;font-weight:850;color:var(--muted)}.mb-match-actual b{color:var(--text)}
.mb-empty-note{width:100%;box-sizing:border-box;margin-top:auto;padding-top:7px;font-size:.55rem;color:var(--muted);font-weight:750}

.tv-mode .schedule-match{grid-template-columns:190px minmax(0,1fr)}
.tv-mode .schedule-robot.robot-detail-trigger{min-height:188px}
.tv-mode .mb-team-number{font-size:1.4rem!important}
.tv-mode .mb-metric b{font-size:.92rem}

@media(max-width:1320px){
  .schedule-match{grid-template-columns:150px minmax(0,1fr)}
  .schedule-teams{grid-template-columns:repeat(3,minmax(0,1fr))}
  .schedule-robot.robot-detail-trigger{min-height:190px;padding:12px}
  .mb-team-head{grid-template-columns:42px minmax(0,1fr);gap:10px}
  .mb-logo{width:42px;height:42px}
  .mb-station{font-size:.66rem}.mb-team-number{font-size:1.38rem!important}.mb-team-name{font-size:.67rem}
  .mb-metrics{gap:7px;margin-top:11px}.mb-metric{padding:8px 9px}.mb-metric span{font-size:.57rem}.mb-metric b{font-size:.96rem}
  .mb-defense-line{font-size:.66rem;padding-top:8px;margin-top:8px}.mb-tag{font-size:.57rem;padding:4px 7px}.mb-match-actual,.mb-empty-note{font-size:.62rem}
}
@media(max-width:900px){
  .schedule-match{grid-template-columns:1fr}
  .mb-match-meta{border-right:0;border-bottom:1px solid var(--line);display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:center}
  .mb-match-label{margin:0}.mb-score{margin:0}.match-result-badge{margin:0}
  .schedule-teams{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:14px}
  .schedule-robot.robot-detail-trigger{min-height:220px;padding:14px}
  .mb-team-head{grid-template-columns:52px minmax(0,1fr);gap:12px;padding-right:24px}
  .mb-logo{width:52px;height:52px;border-radius:9px}.mb-logo i{font-size:1.2rem}
  .mb-station{font-size:.72rem}.mb-team-number{font-size:1.6rem!important}.mb-team-name{font-size:.76rem;margin-top:3px}
  .mb-metrics{gap:8px;margin-top:13px}.mb-metric{padding:10px 11px;border-radius:8px}.mb-metric span{font-size:.64rem}.mb-metric b{font-size:1.08rem;margin-top:5px}
  .mb-defense-line{font-size:.74rem;gap:7px;padding-top:9px;margin-top:9px}.mb-defense-line i{font-size:.72rem}
  .mb-tag-strip{gap:6px;min-height:28px;margin-top:8px}.mb-tag{max-width:none;font-size:.65rem;padding:5px 8px}.mb-tag i{font-size:.62rem}
  .mb-match-actual,.mb-empty-note{font-size:.7rem;padding-top:8px}.schedule-robot-info{font-size:.78rem;right:8px;bottom:8px}
}
@media(max-width:560px){
  .schedule-teams{grid-template-columns:1fr;gap:12px;padding:10px}
  .mb-match-meta{grid-template-columns:1fr;padding:15px 14px}
  .schedule-robot.robot-detail-trigger{min-height:0;padding:16px 14px 15px;border-radius:10px}
  .mb-team-head{grid-template-columns:58px minmax(0,1fr);gap:13px;padding-right:34px}
  .mb-logo{width:58px;height:58px;border-radius:10px}.mb-logo img{padding:4px}.mb-logo i{font-size:1.3rem}
  .mb-station{font-size:.78rem}.mb-team-number{font-size:1.78rem!important;line-height:1}.mb-team-name{font-size:.82rem;line-height:1.2;margin-top:5px}
  .schedule-robot.is-our-team::after{right:9px;top:9px;font-size:.58rem;padding:3px 7px}
  .mb-metrics{gap:9px;margin-top:15px}.mb-metric{padding:11px 12px;border-radius:9px}.mb-metric span{font-size:.69rem;line-height:1.05}.mb-metric b{font-size:1.18rem;margin-top:6px}
  .mb-defense-line{font-size:.78rem;gap:7px;padding-top:11px;margin-top:11px;white-space:normal}.mb-defense-line i{font-size:.76rem}
  .mb-tag-strip{flex-wrap:wrap;gap:7px;min-height:30px;margin-top:10px}.mb-tag{max-width:100%;font-size:.7rem;padding:6px 9px}.mb-tag i{font-size:.67rem}
  .mb-match-actual,.mb-empty-note{font-size:.75rem;padding-top:10px}
  .schedule-robot-info{font-size:.84rem;right:9px;bottom:9px}
}
</style>

<?php if($tv):?>
<div class="tv-brand">
  <img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune">
  <div>
    <div class="muted">AUGUR · MATCH BOARD</div>
    <div style="font-size:2rem;font-weight:950">#<?=e($teamNumber)?></div>
  </div>
</div>
<?php else:?>
<div class="toolbar mb-titlebar" style="justify-content:space-between">
  <div>
    <div class="module-eyebrow"><span>AUGUR</span><small>Schedule Intelligence</small></div>
    <h1 style="margin-bottom:4px">Match Board</h1>
    <div class="muted mb-subtitle">The familiar six-robot schedule board, now with the event metrics you actually use between matches. Click any robot for full Robot Intelligence.</div>
    <div class="mb-key" aria-label="Robot card metrics">
      <span><i class="fa-solid fa-chart-simple"></i> Points / match</span>
      <span><i class="fa-solid fa-stopwatch"></i> Cycle time</span>
      <span><i class="fa-solid fa-wave-square"></i> Neptune EPA</span>
      <span><i class="fa-solid fa-shield-halved"></i> Neptune D-EPA</span>
      <span><i class="fa-solid fa-tags"></i> Scout tags</span>
    </div>
  </div>
  <div class="toolbar" style="gap:8px;margin:0">
    <a class="btn secondary" href="<?=e(base_url('analytics/pit-operations.php').'?'.http_build_query(['event_id'=>$eventId,'team'=>$teamNumber]))?>"><i class="fa-solid fa-screwdriver-wrench"></i> Pit Operations</a>
    <a class="btn secondary" href="?event_id=<?=$eventId?>&team=<?=$teamNumber?>&tv=1" target="_blank"><i class="fa-solid fa-expand"></i> TV Mode</a>
  </div>
</div>
<?php endif;?>

<?php if(!$tv):?>
<div class="card">
  <form method="get" class="analytics-toolbar">
    <div style="min-width:340px">
      <label>Event</label>
      <select name="event_id" onchange="this.form.submit()"><?=neptune_event_options_html($events,$eventId)?></select>
    </div>
    <div style="align-self:end"><?=neptune_history_toggle_html(neptune_selector_show_history(),'events')?></div>
    <div>
      <label>Our team</label>
      <select name="team" onchange="this.form.submit()">
        <?php foreach($myTeams as $t):?>
          <option value="<?=$t['frc_team_number']?>" <?=$teamNumber===(int)$t['frc_team_number']?'selected':''?>>#<?=e($t['frc_team_number'].' '.($t['display_name']?:$t['nickname']))?></option>
        <?php endforeach;?>
      </select>
    </div>
  </form>
</div>
<?php endif;?>

<div class="schedule-board" style="margin-top:16px">
<?php foreach($matches as $m):
  $past=$m['state']==='ended';
  $current=in_array($m['state'],['running','ready','paused'],true);
  $teamAlliance='';
  foreach($m['teams'] as $mt){
      if((int)$mt['frc_team_number']===$teamNumber){$teamAlliance=(string)$mt['alliance'];break;}
  }
  $result='';
  if($teamAlliance!==''&&$m['red_score']!==null&&$m['blue_score']!==null){
      $red=(int)$m['red_score'];$blue=(int)$m['blue_score'];
      if($red===$blue)$result='TIE';
      else{$winner=$red>$blue?'Red':'Blue';$result=$winner===$teamAlliance?'WIN':'LOSS';}
  }
?>
<section class="schedule-match <?=$past?'past':''?> <?=$current?'current':''?>">
  <div class="mb-match-meta">
    <div>
      <div class="mb-match-state"><?=e(strtoupper($m['state']))?><?=($m['run_number']??1)>1?' · RUN '.e($m['run_number']):''?></div>
      <div class="mb-match-label"><?=e(neptune_match_label($m))?></div>
    </div>
    <?php if($m['scheduled_time']):?><div class="mb-match-time" data-utc-time="<?=e($m['scheduled_time'])?>"><?=e($m['scheduled_time'])?> UTC</div><?php endif;?>
    <?php if($m['red_score']!==null&&$m['blue_score']!==null):?><div class="mb-score"><b><?=e($m['red_score'])?> – <?=e($m['blue_score'])?></b><span>TBA</span></div><?php endif;?>
    <?php if($result!==''):?><span class="match-result-badge <?=strtolower($result)?>"><?=e($result)?></span><?php endif;?>
  </div>

  <div class="schedule-teams">
  <?php foreach($m['teams'] as $t):
      $n=(int)$t['frc_team_number'];
      $mid=(int)$m['id'];
      $stats=$eventStats[$n]??['ppm'=>0,'cycle_time'=>null,'defense'=>0,'defense_per_match'=>0,'nickname'=>''];
      $metrics=$augurMap[$n]??[];
      $nepEpa=$metrics['neptune_epa']??$metrics['augur_epa']??null;
      $depaRow=$depaMap[$n]??[];
      $nepDepa=$depaRow['neptune_depa']??null;
      $defActions=is_numeric($depaRow['defense_actions']??null)?(int)$depaRow['defense_actions']:(int)($stats['defense']??0);
      $defPerMatch=(float)($stats['defense_per_match']??0);
      $tags=$tagMap[$n]??[];
      $logo=$logoMap[$n]??['exists'=>false,'path'=>'','version'=>''];
      $logoUrl=!empty($logo['exists'])?base_url((string)$logo['path']).(!empty($logo['version'])?'?v='.rawurlencode((string)$logo['version']):''):'';
      $matchPoints=$matchActuals[$mid][$n]??null;
      $isOur=$n===$teamNumber;
  ?>
    <button type="button" class="schedule-robot <?=strtolower($t['alliance'])?> robot-detail-trigger <?=$isOur?'is-our-team':''?>"
      data-event-id="<?=$eventId?>" data-team="<?=e($n)?>"
      aria-label="Open details for team <?=e($n)?>">
      <div class="mb-team-head">
        <span class="mb-logo" data-team-logo-slot data-logo-cached="<?=!empty($logo['exists'])?'1':'0'?>">
          <img class="mb-team-logo" src="<?=e($logoUrl)?>" alt="" <?=empty($logo['exists'])?'hidden':''?>>
          <i class="fa-solid fa-robot mb-team-logo-fallback" <?=!empty($logo['exists'])?'hidden':''?>></i>
        </span>
        <span class="mb-team-copy">
          <span class="mb-station"><?=e($t['alliance'].' '.$t['station'])?></span>
          <strong class="mb-team-number">#<?=e($n)?></strong>
          <span class="mb-team-name"><?=e((string)($stats['nickname']??''))?></span>
        </span>
      </div>

      <span class="mb-metrics">
        <span class="mb-metric"><span>Points / match</span><b><?=mb_fmt_metric($stats['ppm']??null,1)?></b></span>
        <span class="mb-metric"><span>Cycle time</span><b><?=mb_fmt_metric($stats['cycle_time']??null,1,'s')?></b></span>
        <span class="mb-metric epa"><span>Nep. EPA</span><b><?=mb_fmt_metric($nepEpa,1)?></b></span>
        <span class="mb-metric epa"><span>Nep. D-EPA</span><b><?=mb_fmt_metric($nepDepa,1)?></b></span>
      </span>

      <span class="mb-defense-line" title="Event defensive scouting activity"><i class="fa-solid fa-shield-halved"></i> DEF <?=e($defActions)?> action<?=$defActions===1?'':'s'?> · <?=number_format($defPerMatch,1)?>/match</span>

      <?php if($tags):?><span class="mb-tag-strip" aria-label="Scout tags">
        <?php foreach($tags as $tag):$sev=mb_tag_class((string)($tag['severity']??''));$icon=trim((string)($tag['icon']??''));?>
          <span class="mb-tag <?=e($sev)?>" title="<?=e((string)$tag['label'])?><?=(int)$tag['mentions']>1?' · '.e((int)$tag['mentions']).' mentions':''?>"><?php if($icon!==''):?><i class="<?=e($icon)?>"></i><?php endif;?><?=e((string)$tag['label'])?><?php if((int)$tag['mentions']>1):?> ×<?=e((int)$tag['mentions'])?><?php endif;?></span>
        <?php endforeach;?>
      </span><?php else:?><span class="mb-tag-strip"><span class="mb-tag info"><i class="fa-solid fa-tag"></i> No active tags</span></span><?php endif;?>

      <?php if($matchPoints!==null):?><span class="mb-match-actual">This match: <b><?=number_format((float)$matchPoints,1)?> scout pts</b></span>
      <?php elseif(!$past&&!$current):?><span class="mb-empty-note">Event scouting snapshot</span><?php endif;?>
      <i class="fa-solid fa-circle-info schedule-robot-info" aria-hidden="true"></i>
    </button>
  <?php endforeach;?>
  </div>
</section>
<?php endforeach;?>
<?php if(!$matches):?><div class="notice">No matches are loaded for this team/event combination.</div><?php endif;?>
</div>

<script>
(()=>{
  // Render schedule times in the browser's local timezone while preserving the
  // exact server value in the title for troubleshooting.
  document.querySelectorAll('[data-utc-time]').forEach(el=>{
    const raw=(el.dataset.utcTime||'').trim();
    if(!raw)return;
    const iso=raw.replace(' ','T')+'Z';
    const d=new Date(iso);
    if(Number.isNaN(d.getTime()))return;
    el.title=raw+' UTC';
    el.textContent=new Intl.DateTimeFormat(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(d);
  });

  // Reuse the persistent logo cache added to Robot Intelligence. Missing logos
  // are fetched only after the Match Board is already rendered and then remain
  // available offline.
  const logoApi=<?=json_encode(base_url('api/team-logo.php'))?>;
  const logoCsrf=<?=json_encode(csrf_token())?>;
  const eventId=<?=json_encode($eventId)?>;
  const slots=[...document.querySelectorAll('[data-team-logo-slot][data-logo-cached="0"]')];
  let stopped=false;
  async function fetchLogo(slot){
    if(stopped)return;
    const card=slot.closest('[data-team]');if(!card)return;
    const fd=new FormData();fd.append('csrf',logoCsrf);fd.append('event_id',eventId);fd.append('team',card.dataset.team||'');fd.append('action','auto_fetch_tba');
    try{
      const res=await fetch(logoApi,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:fd});
      const data=await res.json().catch(()=>({ok:false}));
      if(data?.ok&&data?.url){
        const img=slot.querySelector('.mb-team-logo'),fallback=slot.querySelector('.mb-team-logo-fallback');
        if(img){img.src=data.url;img.hidden=false;}if(fallback)fallback.hidden=true;slot.dataset.logoCached='1';
      }else if(!data?.ok&&String(data?.message||'').toLowerCase().includes('blue alliance'))stopped=true;
    }catch(e){stopped=true;}
  }
  if(slots.length)setTimeout(async()=>{let next=0;async function worker(){while(!stopped&&next<slots.length)await fetchLogo(slots[next++]);}await Promise.all([worker(),worker()]);},150);
})();
</script>

<?php include __DIR__.'/_robot_modal.php';?>
<?php if($tv):?><script>setInterval(()=>{if(!(window.neptuneRobotModalOpen&&window.neptuneRobotModalOpen()))location.reload()},60000);</script><?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php';
