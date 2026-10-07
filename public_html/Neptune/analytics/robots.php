<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
require_once __DIR__.'/_depa_metrics.php';
require_once __DIR__.'/_augur_prediction_model.php';
require_once __DIR__.'/_team_logo_cache.php';
$u=require_login();
$org=(int)$u['organization_id'];
$tv=isset($_GET['tv'])&&$_GET['tv']==='1';
$events=neptune_event_list($pdo,$org);
$eventId=(int)($_GET['event_id']??($events[0]['id']??0));
$stats=$eventId?neptune_event_robot_stats($pdo,$org,$eventId):[];

$event=$eventId?alliance_event($pdo,$org,$eventId):null;
$teamNumbers=array_values(array_map('intval',array_keys($stats)));
$epaMap=[];
$augurMap=[];
$depaMap=[];
$logoMap=[];
$recentPointMap=[];
$tagMap=[];

if($event&&$teamNumbers){
    try{
        // Use the same organization-private AUGUR model as Match Strategy and
        // Alliance Selection. Disable live TBA rankings so Robot Cards reads
        // only Neptune's local match/scouting/EPA data during page load.
        $settings=augur_prediction_settings();
        if(alliance_schema_ready($pdo)){
            $workspace=alliance_load_workspace($pdo,$org,$eventId,false);
            $settings=augur_prediction_settings($workspace['workspace']['settings']['matchup_model']??[]);
        }

        if(count($teamNumbers)>=2){
            $cut=(int)ceil(count($teamNumbers)/2);
            $sideA=array_slice($teamNumbers,0,$cut);
            $sideB=array_slice($teamNumbers,$cut);
            if(!$sideB)$sideB=[$sideA[0]];

            $prediction=augur_prediction_run(
                $pdo,$org,$event,$sideA,$sideB,$settings,
                ['use_tba_rankings'=>false,'use_epa'=>true]
            );

            foreach(array_merge($prediction['a']['teams']??[],$prediction['b']['teams']??[]) as $row){
                $n=(int)($row['team']??0);
                $m=is_array($row['metrics']??null)?$row['metrics']:[];
                if($n<1)continue;
                $augurMap[$n]=$m;
                if(isset($m['epa'])&&is_numeric($m['epa'])){
                    $epaMap[$n]=[
                        'epa'=>(float)$m['epa'],
                        'auto'=>$m['auto_epa']??null,
                        'teleop'=>$m['teleop_epa']??null,
                        'endgame'=>$m['endgame_epa']??null,
                        'confidence'=>$m['epa_confidence']??null,
                    ];
                }
            }
        }
    }catch(Throwable $ignored){
        $augurMap=[];
    }

    // EPA should still display even if the private AUGUR model has no usable
    // scouting sample or cannot be calculated for this event.
    if(!$epaMap){
        try{$epaMap=augur_epa_rating_map($pdo,$org,$event,$teamNumbers,null);}
        catch(Throwable $ignored){$epaMap=[];}
    }
    try{$depaMap=neptune_depa_event_map_with_season_fallback($pdo,$event,$teamNumbers);}
    catch(Throwable $ignored){$depaMap=[];}

    // Team logos are local, organization/season-scoped files. Robot Cards will
    // automatically fetch only missing logos from TBA after the page renders,
    // then reuse those local files offline on future loads.
    $logoMap=neptune_team_logo_map($org,(int)($event['season_year']??date('Y')),$teamNumbers);

    // Match-by-match scouting points for the small recent-form sparkline.
    try{
        $s=$pdo->prepare("SELECT sa.frc_team_number,m.comp_level,m.set_number,m.match_number,sa.match_run_number,
                   SUM(sa.points) AS scout_points
            FROM scouting_actions sa
            JOIN matches m ON m.id=sa.match_id AND m.organization_id=sa.organization_id AND m.event_id=sa.event_id
            WHERE sa.organization_id=? AND sa.event_id=? AND sa.deleted_at IS NULL
            GROUP BY sa.frc_team_number,m.comp_level,m.set_number,m.match_number,sa.match_run_number
            ORDER BY FIELD(m.comp_level,'qm','ef','qf','sf','f','legacy'),m.set_number,m.match_number,sa.match_run_number");
        $s->execute([$org,$eventId]);
        foreach($s->fetchAll() as $row){
            $n=(int)$row['frc_team_number'];
            $recentPointMap[$n][]=(float)$row['scout_points'];
        }
        foreach($recentPointMap as $n=>$values)$recentPointMap[$n]=array_slice($values,-8);
    }catch(Throwable $ignored){$recentPointMap=[];}

    // Surface a few high-signal Tag Scouting observations directly on the card.
    try{
        $s=$pdo->prepare("SELECT o.frc_team_number,t.label,t.icon,t.severity,COUNT(*) AS mentions,MAX(o.created_at) AS latest_at
            FROM tag_scouting_observations o
            JOIN tag_scouting_observation_tags ot ON ot.observation_id=o.id
            JOIN tag_scouting_tags t ON t.id=ot.tag_id
            LEFT JOIN tag_scouting_tag_visibility tv ON tv.organization_id=o.organization_id AND tv.tag_id=t.id
            WHERE o.organization_id=? AND o.event_id=? AND o.status='open' AND t.active=1 AND COALESCE(tv.active,1)=1
            GROUP BY o.frc_team_number,t.id,t.label,t.icon,t.severity
            ORDER BY o.frc_team_number,mentions DESC,latest_at DESC");
        $s->execute([$org,$eventId]);
        foreach($s->fetchAll() as $row){
            $n=(int)$row['frc_team_number'];
            if(count($tagMap[$n]??[])<3)$tagMap[$n][]=$row;
        }
    }catch(Throwable $ignored){$tagMap=[];}
}

function robot_card_rank_map(array $values): array {
    arsort($values,SORT_NUMERIC);
    $out=[];$rank=0;$last=null;$position=0;
    foreach($values as $team=>$value){
        $position++;
        if($last===null||(float)$value!==(float)$last)$rank=$position;
        $out[(int)$team]=$rank;$last=$value;
    }
    return $out;
}
function robot_card_spark_points(array $values,int $width=132,int $height=34): string {
    $values=array_values(array_filter($values,'is_numeric'));
    $count=count($values);if($count<2)return '';
    $min=min($values);$max=max($values);$range=max(1.0,$max-$min);
    $points=[];
    foreach($values as $i=>$value){
        $x=$count===1?0:($i/($count-1))*$width;
        $y=$height-2-(((float)$value-$min)/$range)*($height-4);
        $points[]=number_format($x,1,'.','').','.number_format($y,1,'.','');
    }
    return implode(' ',$points);
}
function robot_card_phase_pct(mixed $value,float $max): float {
    if(!is_numeric($value)||$max<=0)return 0.0;
    return max(4.0,min(100.0,((float)$value/$max)*100.0));
}

$augurRankValues=[];$ppmRankValues=[];
foreach($stats as $team=>$r){
    $n=(int)$team;
    $a=$augurMap[$n]['neptune_epa']??$augurMap[$n]['augur_epa']??null;
    if(is_numeric($a))$augurRankValues[$n]=(float)$a;
    $ppmRankValues[$n]=(float)($r['ppm']??0);
}
$augurRanks=robot_card_rank_map($augurRankValues);
$ppmRanks=robot_card_rank_map($ppmRankValues);
$totalRankedAugur=count($augurRankValues);
$totalRobots=count($stats);

$pageTitle='Robot Intelligence';
$moduleName='AUGUR';
$hideChrome=$tv;$bodyClass=$tv?'tv-mode':'';
include dirname(__DIR__).'/partials_header.php';
?>
<?php if($tv):?>
<div class="tv-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><div><div class="muted">AUGUR · ROBOT INTELLIGENCE</div><div style="font-size:2rem;font-weight:950">Robot Cards</div></div></div>
<?php else:?>
<div class="toolbar robot-page-titlebar" style="justify-content:space-between">
  <div>
    <div class="module-eyebrow"><span>AUGUR</span><small>Robot Intelligence</small></div>
    <h1 style="margin-bottom:4px">Robot Cards</h1>
    <div class="muted">Fast event-level robot intelligence. Click anywhere on a robot card to open the full Robot Intelligence details view.</div>
  </div>
  <div class="toolbar" style="margin:0">
    <?php if(in_array((string)$u['role'],['owner','admin','strategy'],true)):?><a class="btn secondary" href="<?=e(base_url('analytics/team-logos.php').'?'.http_build_query(['event_id'=>$eventId]))?>"><i class="fa-solid fa-images"></i> Team Logos</a><?php endif;?>
    <a class="btn secondary" href="?<?=e(http_build_query(['event_id'=>$eventId,'tv'=>1]))?>" target="_blank"><i class="fa-solid fa-expand"></i> TV Mode</a>
  </div>
</div>
<div class="card robot-controls-card">
  <form method="get" class="analytics-toolbar robot-controls">
    <div class="robot-control-event"><label>Event</label><select name="event_id" onchange="this.form.submit()"><?=neptune_event_options_html($events,$eventId)?></select></div>
    <div class="robot-history-toggle"><?=neptune_history_toggle_html(neptune_selector_show_history(),'events')?></div>
    <div><label for="robotSearch">Find robot</label><div class="robot-search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input id="robotSearch" type="search" placeholder="Team or name" autocomplete="off"></div></div>
    <div><label for="robotFilter">Filter</label><select id="robotFilter"><option value="all">All robots</option><option value="pit">Pit complete</option><option value="trend">Trending up</option><option value="defense">Defense evidence</option><option value="tags">Has scout tags</option></select></div>
    <div><label for="sortBy">Sort robots by</label><select id="sortBy"><option value="augur">Neptune EPA</option><option value="ppm">Points / match</option><option value="recent">Recent average</option><option value="confidence">Model confidence</option><option value="epa">Public EPA</option><option value="auto">Auto EPA</option><option value="teleop">Teleop EPA</option><option value="endgame">Endgame EPA</option><option value="trend">Recent trend</option><option value="cycle">Cycle time (fastest)</option><option value="depa">D-EPA</option><option value="nepdepa">Nep. D-EPA</option><option value="defense">Defense / match</option><option value="success">Offense success %</option><option value="matches">Matches scouted</option><option value="team">Team number</option></select></div>
  </form>
  <div class="robot-result-line"><span id="robotResultCount"><?=e($totalRobots)?> robots</span><span class="muted">Neptune EPA blends public EPA with your current-event scouting and recent form.</span></div>
</div>
<?php endif;?>
<style>
.robot-page-titlebar{align-items:flex-start;gap:18px}
.robot-controls-card{padding:14px 16px}
.robot-controls{display:grid;grid-template-columns:minmax(330px,1.6fr) auto minmax(190px,.8fr) minmax(175px,.7fr) minmax(220px,.85fr);gap:10px;align-items:end}
.robot-controls>div{min-width:0}
.robot-history-toggle{align-self:end}
.robot-search-wrap{position:relative}.robot-search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none}.robot-search-wrap input{padding-left:34px}
.robot-result-line{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:10px;padding-top:10px;border-top:1px solid var(--line);font-size:.76rem;font-weight:800}

.robot-card-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(420px,1fr));gap:14px;align-items:stretch}
.robot-card-grid .robot-card{position:relative;display:flex;flex-direction:column;height:100%;min-height:0;text-align:left;padding:0;overflow:hidden;border-radius:10px;transition:transform .14s ease,border-color .14s ease,box-shadow .14s ease;background:var(--panel)}
.robot-card-grid .robot-card::before{content:"";display:block;height:4px;background:linear-gradient(90deg,var(--module-augur,var(--accent)),color-mix(in srgb,var(--module-augur,var(--accent)) 28%,transparent))}
.robot-card-inner{display:flex;flex-direction:column;flex:1;width:100%;max-width:none;margin:0;align-self:stretch;box-sizing:border-box;padding:14px 15px 13px}
.robot-card-inner>.robot-card-hero,.robot-card-inner>.robot-primary,.robot-card-inner>.robot-phase-block,.robot-card-inner>.robot-form-block,.robot-card-inner>.robot-defense-group,.robot-card-inner>.robot-tag-strip,.robot-card-inner>.robot-card-foot{width:100%;max-width:none;box-sizing:border-box;align-self:stretch}
.robot-card-grid .robot-card:hover,.robot-card-grid .robot-card:focus-visible{transform:translateY(-3px);box-shadow:0 14px 34px var(--shadow)}

.robot-card-hero{display:grid;grid-template-columns:82px minmax(0,1fr) auto;gap:12px;align-items:start}
.robot-card-photo{width:82px;height:82px;border-radius:9px;border:1px solid var(--line);background:var(--panel2);overflow:hidden;display:grid;place-items:center;color:var(--muted);font-size:1.55rem}
.robot-card-photo img{width:100%;height:100%;object-fit:contain;display:block;background:#fff;padding:6px}
.robot-card-identity{min-width:0;padding-top:1px}.robot-card-grid .robot-number{font-size:2.15rem;line-height:.98;letter-spacing:-.045em}.robot-card-name{margin-top:5px;line-height:1.2;font-size:.96rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.robot-rank-chip{display:flex;flex-direction:column;align-items:flex-end;gap:2px;padding:6px 8px;border:1px solid color-mix(in srgb,var(--module-augur,var(--accent)) 42%,var(--line));border-radius:7px;background:color-mix(in srgb,var(--module-augur,var(--accent)) 7%,var(--panel2));white-space:nowrap}.robot-rank-chip span{font-size:.59rem;font-weight:900;letter-spacing:.065em;text-transform:uppercase;color:var(--muted)}.robot-rank-chip b{font-size:.96rem;color:var(--module-augur,var(--accent))}
.robot-card-badges{display:flex;flex-wrap:wrap;gap:5px;margin-top:9px}.robot-card-badges .pill{margin:0;font-size:.64rem;padding:4px 7px}.robot-card-info{position:absolute;top:13px;right:13px;display:none}

.robot-primary{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,.8fr);gap:10px;margin-top:13px}
.robot-primary-hero{position:relative;padding:12px;border:1px solid color-mix(in srgb,var(--module-augur,var(--accent)) 46%,var(--line));border-radius:9px;background:linear-gradient(135deg,color-mix(in srgb,var(--module-augur,var(--accent)) 10%,var(--panel2)),var(--panel2))}
.robot-primary-label{display:flex;align-items:center;justify-content:space-between;gap:8px;color:var(--muted);font-size:.65rem;font-weight:900;text-transform:uppercase;letter-spacing:.055em}.robot-primary-value{display:flex;align-items:baseline;gap:9px;margin-top:4px}.robot-primary-value b{font-size:2rem;line-height:1;color:var(--module-augur,var(--accent));letter-spacing:-.045em}.robot-epa-delta{font-size:.73rem;font-weight:900}.robot-epa-delta.up{color:var(--good)}.robot-epa-delta.down{color:var(--bad)}.robot-epa-delta.flat{color:var(--muted)}
.robot-trend-line{display:flex;align-items:center;gap:7px;margin-top:8px;font-size:.7rem;font-weight:800;color:var(--muted)}.robot-trend-line .up{color:var(--good)}.robot-trend-line .down{color:var(--bad)}
.robot-primary-side{display:grid;grid-template-columns:1fr 1fr;gap:7px}.robot-mini-stat{display:flex;flex-direction:column;justify-content:space-between;min-width:0;padding:9px;border:1px solid var(--line);border-radius:7px;background:var(--panel2)}.robot-mini-stat span{font-size:.64rem;color:var(--muted);font-weight:800;line-height:1.15}.robot-mini-stat b{margin-top:5px;font-size:1rem;line-height:1}.robot-mini-stat.wide{grid-column:1/-1;flex-direction:row;align-items:center;gap:8px}.robot-mini-stat.wide b{margin-top:0}

.robot-phase-block{margin-top:10px;padding:10px 11px;border:1px solid var(--line);border-radius:9px;background:var(--panel2)}
.robot-section-kicker{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;font-size:.62rem;font-weight:900;letter-spacing:.065em;text-transform:uppercase;color:var(--muted)}
.robot-phase-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.robot-phase{min-width:0}.robot-phase-head{display:flex;justify-content:space-between;align-items:baseline;gap:5px;font-size:.67rem;color:var(--muted)}.robot-phase-head b{font-size:.86rem;color:var(--text)}.robot-phase-track{height:5px;margin-top:5px;border-radius:999px;background:color-mix(in srgb,var(--line) 78%,transparent);overflow:hidden}.robot-phase-fill{height:100%;width:var(--phase-width,0%);background:var(--module-augur,var(--accent));border-radius:inherit}

.robot-form-block{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(145px,.65fr);gap:10px;margin-top:10px}
.robot-form-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}.robot-form-stat{padding:8px;border:1px solid var(--line);border-radius:7px;background:var(--panel2);min-width:0}.robot-form-stat span{display:block;color:var(--muted);font-size:.61rem;font-weight:800;line-height:1.15}.robot-form-stat b{display:block;margin-top:4px;font-size:.9rem;line-height:1}
.robot-spark{display:flex;flex-direction:column;justify-content:space-between;padding:8px 9px;border:1px solid var(--line);border-radius:7px;background:var(--panel2);min-width:0}.robot-spark-head{display:flex;justify-content:space-between;gap:6px;color:var(--muted);font-size:.6rem;font-weight:850}.robot-spark svg{display:block;width:100%;height:36px;margin-top:3px}.robot-spark polyline{fill:none;stroke:var(--module-augur,var(--accent));stroke-width:2.3;stroke-linecap:round;stroke-linejoin:round}.robot-spark-empty{display:grid;place-items:center;height:36px;color:var(--muted);font-size:.65rem}

.robot-defense-group{margin-top:10px;padding:10px 11px;border:1px solid var(--line);border-radius:9px;background:var(--panel2)}
.robot-defense-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}.robot-defense-label{display:flex;align-items:center;gap:6px;color:var(--muted);font-size:.62rem;font-weight:900;letter-spacing:.065em;text-transform:uppercase}.robot-defense-confidence{font-size:.62rem;color:var(--muted);font-weight:800}
.robot-defense-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px}.robot-defense-stat{min-width:0}.robot-defense-stat span{display:block;color:var(--muted);font-size:.6rem;font-weight:800;line-height:1.15}.robot-defense-stat b{display:block;margin-top:4px;font-size:.88rem;line-height:1}

.robot-tag-strip{display:flex;gap:5px;flex-wrap:wrap;margin-top:9px}.robot-tag-chip{display:inline-flex;align-items:center;gap:5px;max-width:100%;padding:4px 7px;border:1px solid var(--line);border-radius:999px;background:var(--panel2);font-size:.62rem;font-weight:850;line-height:1.2}.robot-tag-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.robot-tag-chip.positive{color:var(--good)}.robot-tag-chip.warning{color:#c68a18}.robot-tag-chip.critical{color:var(--bad)}

.robot-card-grid .robot-card-foot{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-top:auto;padding-top:11px;font-size:.72rem}.robot-card-foot .robot-open-details{display:inline-flex;align-items:center;gap:5px;color:var(--accent);font-weight:900;white-space:nowrap}

@media(max-width:1180px){.robot-controls{grid-template-columns:minmax(300px,1.4fr) auto 1fr 1fr}.robot-controls>div:last-child{grid-column:3/-1}.robot-card-grid{grid-template-columns:repeat(auto-fill,minmax(380px,1fr))}}
@media(max-width:760px){.robot-page-titlebar{align-items:stretch;flex-direction:column}.robot-page-titlebar .btn{align-self:flex-start}.robot-controls{grid-template-columns:1fr 1fr}.robot-control-event{grid-column:1/-1}.robot-history-toggle{grid-column:1/-1}.robot-controls>div:last-child{grid-column:auto}.robot-result-line{align-items:flex-start;flex-direction:column}.robot-card-grid{grid-template-columns:1fr;width:calc(100vw - 4px);max-width:none;margin-left:calc(50% - 50vw + 2px);margin-right:calc(50% - 50vw + 2px);gap:12px}.robot-card-grid .robot-card{width:100%;max-width:none;align-items:stretch}.robot-card-inner{width:100%!important;max-width:none!important;margin:0!important;align-self:stretch!important;padding:10px 8px 11px}.robot-card-inner>*{max-width:none}.robot-card-hero{width:100%;grid-template-columns:76px minmax(0,1fr) auto}.robot-card-photo{width:76px;height:76px}.robot-card-grid .robot-number{font-size:2rem}.robot-primary{width:100%;grid-template-columns:1fr}.robot-primary-side{width:100%;grid-template-columns:repeat(3,minmax(0,1fr))}.robot-mini-stat.wide{grid-column:auto;display:flex;flex-direction:column;align-items:flex-start}.robot-phase-block,.robot-form-block,.robot-defense-group,.robot-tag-strip,.robot-card-foot{width:100%}.robot-form-block{grid-template-columns:1fr}.robot-defense-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:500px){.robot-controls{grid-template-columns:1fr}.robot-control-event,.robot-history-toggle,.robot-controls>div:last-child{grid-column:auto}.robot-card-grid{width:calc(100vw - 4px);margin-left:calc(50% - 50vw + 2px);margin-right:calc(50% - 50vw + 2px);gap:10px}.robot-card-inner{width:100%!important;max-width:none!important;margin:0!important;padding:8px 6px 10px!important}.robot-card-hero{grid-template-columns:78px minmax(0,1fr);column-gap:10px}.robot-card-photo{width:78px;height:78px}.robot-rank-chip{grid-column:1/-1;grid-row:2;justify-self:start;align-items:flex-start;flex-direction:row;gap:6px}.robot-card-badges{grid-column:1/-1}.robot-primary-hero,.robot-phase-block,.robot-defense-group{padding-left:10px;padding-right:10px}.robot-primary-side{grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.robot-mini-stat{padding:8px 7px}.robot-phase-grid{gap:7px}.robot-form-metrics{grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.robot-form-stat{padding:8px 7px}.robot-card-grid .robot-card-foot{align-items:flex-start;flex-direction:column}}
</style>

<div id="robotGrid" class="robot-card-grid" style="margin-top:16px">
<?php foreach($stats as $r):
  $team=(int)$r['team'];
  $epa=$epaMap[$team]['epa']??null;
  $metrics=$augurMap[$team]??[];
  $augur=$metrics['neptune_epa']??$metrics['augur_epa']??null;
  $auto=$metrics['auto_epa']??$epaMap[$team]['auto']??null;
  $teleop=$metrics['teleop_epa']??$epaMap[$team]['teleop']??null;
  $endgame=$metrics['endgame_epa']??$epaMap[$team]['endgame']??null;
  $confidence=$metrics['confidence']??null;
  $eventAvg=$metrics['event_avg']??$r['ppm'];
  $recentAvg=$metrics['recent_avg']??$r['ppm'];
  $p75=$metrics['p75']??null;
  $trend=$metrics['trend_adjustment']??0.0;
  $volatility=$metrics['volatility']??null;
  $depaRow=$depaMap[$team]??[];
  $depa=$depaRow['depa']??null;
  $nepDepa=$depaRow['neptune_depa']??null;
  $verifiedDefense=(int)($depaRow['verified_defense_matches']??0);
  $possibleDefense=(int)($depaRow['possible_defense_matches']??0);
  $depaConfidence=is_numeric($depaRow['confidence_score']??null)?(float)$depaRow['confidence_score']:null;
  $depaValidation=trim((string)($depaRow['validation_label']??''));
  $delta=($augur!==null&&$epa!==null)?((float)$augur-(float)$epa):null;
  $deltaClass=$delta===null||abs($delta)<0.05?'flat':($delta>0?'up':'down');
  $trendClass=abs((float)$trend)<0.05?'flat':((float)$trend>0?'up':'down');
  $trendLabel=$trendClass==='up'?'Trending up':($trendClass==='down'?'Trending down':'Stable trend');
  $phaseNumeric=array_values(array_filter([$auto,$teleop,$endgame],fn($v)=>is_numeric($v)));
  $phaseMax=$phaseNumeric?max(array_map('floatval',$phaseNumeric)):1.0;
  $sparkValues=$recentPointMap[$team]??[];
  $sparkPoints=robot_card_spark_points($sparkValues);
  $logoInfo=$logoMap[$team]??['exists'=>false,'path'=>'','version'=>''];
  $logo=!empty($logoInfo['exists'])?(string)$logoInfo['path']:'';
  $rank=$augurRanks[$team]??($ppmRanks[$team]??null);
  $rankTotal=isset($augurRanks[$team])?$totalRankedAugur:$totalRobots;
  $rankLabel=isset($augurRanks[$team])?'N-EPA':'PPM';
  $pitComplete=($r['pit_status']??'')==='complete';
  $defenseEvidence=$verifiedDefense>0||$possibleDefense>0||(float)$r['defense_per_match']>0;
  $hasTags=!empty($tagMap[$team]);
  $searchText=strtolower($team.' '.($r['nickname']?:'FRC Team '.$team));
?>
  <button
    type="button"
    class="robot-card robot-detail-trigger"
    data-event-id="<?=$eventId?>"
    data-team="<?=$team?>"
    data-name="<?=e($searchText)?>"
    data-ppm="<?=$r['ppm']?>"
    data-epa="<?=$epa!==null?e((string)$epa):-999999?>"
    data-augur="<?=$augur!==null?e((string)$augur):-999999?>"
    data-recent="<?=is_numeric($recentAvg)?e((string)$recentAvg):-999999?>"
    data-confidence="<?=is_numeric($confidence)?e((string)$confidence):-999999?>"
    data-auto="<?=is_numeric($auto)?e((string)$auto):-999999?>"
    data-teleop="<?=is_numeric($teleop)?e((string)$teleop):-999999?>"
    data-endgame="<?=is_numeric($endgame)?e((string)$endgame):-999999?>"
    data-trend="<?=is_numeric($trend)?e((string)$trend):-999999?>"
    data-depa="<?=$depa!==null?e((string)$depa):-999999?>"
    data-nepdepa="<?=$nepDepa!==null?e((string)$nepDepa):-999999?>"
    data-cycle="<?=$r['cycle_time']??99999?>"
    data-defense="<?=$r['defense_per_match']?>"
    data-success="<?=$r['success_rate']?>"
    data-matches="<?=$r['matches']?>"
    data-pit="<?=$pitComplete?'1':'0'?>"
    data-defense-evidence="<?=$defenseEvidence?'1':'0'?>"
    data-has-tags="<?=$hasTags?'1':'0'?>"
    aria-label="Open full Robot Intelligence details for team <?=$team?>"
  >
    <div class="robot-card-inner">
      <div class="robot-card-hero">
        <div class="robot-card-photo" aria-hidden="true" data-logo-slot="1" data-logo-cached="<?=$logo!==''?'1':'0'?>" title="<?=$logo!==''?'Cached team logo':'Fetching team logo when online'?>">
          <img class="robot-team-logo" <?=$logo===''?'hidden':''?> src="<?=$logo!==''?e(base_url($logo).'?v='.rawurlencode((string)($logoInfo['version']??''))):''?>" alt="">
          <i class="fa-solid fa-robot robot-team-logo-fallback" <?=$logo!==''?'hidden':''?>></i>
        </div>
        <div class="robot-card-identity">
          <div class="robot-number">#<?=e($team)?></div>
          <div class="muted robot-card-name"><?=e($r['nickname']?:'FRC Team '.$team)?></div>
          <div class="robot-card-badges">
            <?php if($r['pit_status']):?>
              <span class="pill"><i class="fa-solid fa-clipboard-check"></i> Pit <?=e($pitComplete?'complete':'in progress')?></span>
            <?php endif;?>
            <?php if(!empty($r['pit_data']['drivetrain'])):?><span class="pill"><i class="fa-solid fa-gears"></i> <?=e((string)$r['pit_data']['drivetrain'])?></span><?php endif;?>
            <?php if(!empty($r['pit_data']['endgame_capability'])):?><span class="pill"><i class="fa-solid fa-flag-checkered"></i> <?=e((string)$r['pit_data']['endgame_capability'])?></span><?php endif;?>
          </div>
        </div>
        <?php if($rank!==null):?><div class="robot-rank-chip"><span><?=e($rankLabel)?> rank</span><b>#<?=e($rank)?> / <?=e($rankTotal)?></b></div><?php endif;?>
      </div>

      <div class="robot-primary">
        <div class="robot-primary-hero">
          <div class="robot-primary-label"><span>Neptune EPA</span><?php if(is_numeric($confidence)):?><span><?=number_format((float)$confidence,0)?>% confidence</span><?php endif;?></div>
          <div class="robot-primary-value">
            <b><?=$augur!==null?number_format((float)$augur,1):'—'?></b>
            <?php if($delta!==null):?><span class="robot-epa-delta <?=$deltaClass?>"><?=$delta>0?'+':''?><?=number_format($delta,1)?> vs public</span><?php endif;?>
          </div>
          <div class="robot-trend-line">
            <i class="fa-solid <?=$trendClass==='up'?'fa-arrow-trend-up':($trendClass==='down'?'fa-arrow-trend-down':'fa-minus')?> <?=$trendClass?>"></i>
            <span><?=$trendLabel?><?php if(is_numeric($trend)&&abs((float)$trend)>=0.05):?> · <?=$trend>0?'+':''?><?=number_format((float)$trend,1)?> EPA<?php endif;?></span>
            <?php if(is_numeric($volatility)):?><span title="Recent scoring volatility">· σ <?=number_format((float)$volatility,1)?></span><?php endif;?>
          </div>
        </div>
        <div class="robot-primary-side">
          <div class="robot-mini-stat"><span>Public EPA</span><b><?=$epa!==null?number_format((float)$epa,1):'—'?></b></div>
          <div class="robot-mini-stat"><span>Points / match</span><b><?=number_format((float)$r['ppm'],1)?></b></div>
          <div class="robot-mini-stat wide"><span>Cycle / success</span><b><?=$r['cycle_time']!==null?number_format((float)$r['cycle_time'],1).'s':'—'?> · <?=number_format((float)$r['success_rate'],0)?>%</b></div>
        </div>
      </div>

      <div class="robot-phase-block">
        <div class="robot-section-kicker"><span><i class="fa-solid fa-layer-group"></i> EPA phase profile</span><span>Auto · Teleop · Endgame</span></div>
        <div class="robot-phase-grid">
          <?php foreach([['Auto',$auto],['Teleop',$teleop],['Endgame',$endgame]] as [$label,$value]):?>
            <div class="robot-phase">
              <div class="robot-phase-head"><span><?=e($label)?></span><b><?=is_numeric($value)?number_format((float)$value,1):'—'?></b></div>
              <div class="robot-phase-track"><div class="robot-phase-fill" style="--phase-width:<?=number_format(robot_card_phase_pct($value,$phaseMax),1,'.','')?>%"></div></div>
            </div>
          <?php endforeach;?>
        </div>
      </div>

      <div class="robot-form-block">
        <div class="robot-form-metrics">
          <div class="robot-form-stat"><span>Event avg</span><b><?=is_numeric($eventAvg)?number_format((float)$eventAvg,1):'—'?></b></div>
          <div class="robot-form-stat"><span>Recent avg</span><b><?=is_numeric($recentAvg)?number_format((float)$recentAvg,1):'—'?></b></div>
          <div class="robot-form-stat"><span>P75 ceiling</span><b><?=is_numeric($p75)?number_format((float)$p75,1):'—'?></b></div>
        </div>
        <div class="robot-spark">
          <div class="robot-spark-head"><span>Recent form</span><span><?=count($sparkValues)?> run<?=count($sparkValues)===1?'':'s'?></span></div>
          <?php if($sparkPoints!==''):?><svg viewBox="0 0 132 34" preserveAspectRatio="none" aria-hidden="true"><polyline points="<?=e($sparkPoints)?>"></polyline></svg><?php else:?><div class="robot-spark-empty">Not enough match data</div><?php endif;?>
        </div>
      </div>

      <div class="robot-defense-group">
        <div class="robot-defense-head">
          <div class="robot-defense-label"><i class="fa-solid fa-shield-halved"></i> Defense</div>
          <div class="robot-defense-confidence"><?php if($depaConfidence!==null):?><?=number_format($depaConfidence,0)?>% confidence<?php elseif($depaValidation!==''):?><?=e($depaValidation)?><?php endif;?></div>
        </div>
        <div class="robot-defense-stats">
          <div class="robot-defense-stat"><span>Defense / match</span><b><?=number_format((float)$r['defense_per_match'],1)?></b></div>
          <div class="robot-defense-stat"><span>D-EPA</span><b><?=$depa!==null?number_format((float)$depa,1):'—'?></b></div>
          <div class="robot-defense-stat"><span>Nep. D-EPA</span><b><?=$nepDepa!==null?number_format((float)$nepDepa,1):'—'?></b></div>
          <div class="robot-defense-stat"><span>Verified</span><b><?=e($verifiedDefense)?></b></div>
        </div>
      </div>

      <?php if(!empty($tagMap[$team])):?><div class="robot-tag-strip" aria-label="Scout tags">
        <?php foreach($tagMap[$team] as $tag):
          $sev=in_array($tag['severity']??'',['positive','warning','critical'],true)?$tag['severity']:'info';
          $icon=trim((string)($tag['icon']??''));
        ?><span class="robot-tag-chip <?=e($sev)?>"><?php if($icon!==''):?><i class="<?=e($icon)?>"></i><?php endif;?><span><?=e((string)$tag['label'])?></span><?php if((int)$tag['mentions']>1):?><small>×<?=e((int)$tag['mentions'])?></small><?php endif;?></span><?php endforeach;?>
      </div><?php endif;?>

      <div class="robot-card-foot">
        <span class="muted"><?=$r['matches']?> match run<?=$r['matches']==1?'':'s'?> · <?=$r['actions']?> actions<?php if($possibleDefense>0):?> · <?=$possibleDefense?> possible defense<?php endif;?></span>
        <span class="robot-open-details"><i class="fa-solid fa-up-right-and-down-left-from-center"></i> Full details</span>
      </div>
    </div>
  </button>
<?php endforeach;?>
</div>
<script>
(()=>{
  const grid=document.getElementById('robotGrid');
  const sort=document.getElementById('sortBy');
  const search=document.getElementById('robotSearch');
  const filter=document.getElementById('robotFilter');
  const count=document.getElementById('robotResultCount');
  if(!grid)return;
  const cards=[...grid.children];
  function matchesFilter(card){
    const q=(search?.value||'').trim().toLowerCase();
    if(q&&!String(card.dataset.name||'').includes(q))return false;
    const f=filter?.value||'all';
    if(f==='pit'&&card.dataset.pit!=='1')return false;
    if(f==='trend'&&Number(card.dataset.trend||0)<=0.05)return false;
    if(f==='defense'&&card.dataset.defenseEvidence!=='1')return false;
    if(f==='tags'&&card.dataset.hasTags!=='1')return false;
    return true;
  }
  function resort(){
    const k=sort?sort.value:'augur';
    cards.sort((a,b)=>{
      if(k==='team')return +a.dataset.team-+b.dataset.team;
      if(k==='cycle')return +a.dataset.cycle-+b.dataset.cycle;
      const av=Number(a.dataset[k]??-999999),bv=Number(b.dataset[k]??-999999);
      return bv-av;
    });
    let visible=0;
    cards.forEach(card=>{
      const show=matchesFilter(card);card.hidden=!show;if(show)visible++;
      grid.appendChild(card);
    });
    if(count)count.textContent=visible+' robot'+(visible===1?'':'s')+(visible!==cards.length?' shown':'');
  }
  sort?.addEventListener('change',resort);
  filter?.addEventListener('change',resort);
  search?.addEventListener('input',resort);
  resort();

  // Fill only missing team-logo cache entries after the page is already usable.
  // Existing cached/custom logos are never touched here. The first successful
  // fetch is saved by Neptune and is therefore available on future offline loads.
  const logoApi=<?=json_encode(base_url('api/team-logo.php'))?>;
  const logoCsrf=<?=json_encode(csrf_token())?>;
  const eventId=<?=json_encode($eventId)?>;
  const missingLogoCards=cards.filter(card=>card.querySelector('[data-logo-slot]')?.dataset.logoCached!=='1');
  let logoSyncStopped=false;
  async function fetchMissingLogo(card){
    if(logoSyncStopped)return;
    const slot=card.querySelector('[data-logo-slot]');
    if(!slot||slot.dataset.logoCached==='1')return;
    const fd=new FormData();
    fd.append('csrf',logoCsrf);
    fd.append('event_id',eventId);
    fd.append('team',card.dataset.team||'');
    fd.append('action','auto_fetch_tba');
    try{
      const response=await fetch(logoApi,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:fd});
      const data=await response.json().catch(()=>({ok:false,message:'Invalid server response.'}));
      if(data?.ok&&data?.url){
        const img=slot.querySelector('.robot-team-logo');
        const fallback=slot.querySelector('.robot-team-logo-fallback');
        if(img){img.src=data.url;img.hidden=false;}
        if(fallback)fallback.hidden=true;
        slot.dataset.logoCached='1';
        slot.title='Cached team logo';
      }else if(!data?.ok&&String(data?.message||'').toLowerCase().includes('blue alliance')){
        logoSyncStopped=true;
      }
    }catch(e){
      // A field server can be fully functional while the Internet/TBA is not.
      // Keep the placeholder and stop background attempts for this page load.
      logoSyncStopped=true;
    }
  }
  async function autoFetchMissingLogos(){
    if(!missingLogoCards.length)return;
    // Two lightweight workers keep the page responsive and avoid hammering TBA.
    let next=0;
    async function worker(){
      while(!logoSyncStopped&&next<missingLogoCards.length){
        const card=missingLogoCards[next++];
        await fetchMissingLogo(card);
      }
    }
    await Promise.all([worker(),worker()]);
  }
  if(missingLogoCards.length)setTimeout(autoFetchMissingLogos,150);
})();
</script>
<?php include __DIR__.'/_robot_modal.php';?>
<?php if($tv):?><script>setInterval(()=>{if(!(window.neptuneRobotModalOpen&&window.neptuneRobotModalOpen()))location.reload()},60000);</script><?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php';
