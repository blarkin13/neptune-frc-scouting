<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
require_once __DIR__.'/_augur_prediction_model.php';
require_once __DIR__.'/_team_logo_cache.php';
require_once __DIR__.'/_pit_match_board.php';

$u=require_login();
$org=(int)$u['organization_id'];
$tv=isset($_GET['tv'])&&$_GET['tv']=='1';

$events=neptune_event_list($pdo,$org);
$eventId=(int)($_GET['event_id']??($events[0]['id']??0));
$event=null;
foreach($events as $row){if((int)$row['id']===$eventId){$event=$row;break;}}

$s=$pdo->prepare('SELECT * FROM teams WHERE organization_id=? AND active=1 ORDER BY frc_team_number');
$s->execute([$org]);
$myTeams=$s->fetchAll();
$teamNumber=(int)($_GET['team']??($myTeams[0]['frc_team_number']??0));

$matches=[];$matchActuals=[];$eventTeams=[];$eventStats=[];$tagMap=[];$logoMap=[];
$nextMatch=null;$afterNext=null;$lastMatch=null;$nextPrediction=null;$predictionMetrics=[];
$pitOpsReady=false;$pitTeamState=[];$pitNextState=[];

if($eventId){
    $s=$pdo->prepare('SELECT frc_team_number,nickname,city,state_prov,country FROM event_teams WHERE event_id=? ORDER BY frc_team_number');
    $s->execute([$eventId]);
    foreach($s->fetchAll() as $r)$eventTeams[(int)$r['frc_team_number']]=$r;
}

if($eventId&&$teamNumber){
    $s=$pdo->prepare("SELECT m.*
        FROM matches m
        JOIN match_teams own ON own.match_id=m.id AND own.frc_team_number=?
        WHERE m.organization_id=? AND m.event_id=?
        ORDER BY FIELD(m.comp_level,'qm','ef','qf','sf','f','legacy'),m.set_number,m.match_number,m.field_id");
    $s->execute([$teamNumber,$org,$eventId]);
    $matches=$s->fetchAll();

    $mt=$pdo->prepare("SELECT alliance,station,frc_team_number FROM match_teams WHERE match_id=? ORDER BY FIELD(alliance,'Red','Blue'),station");
    foreach($matches as &$m){$mt->execute([$m['id']]);$m['teams']=$mt->fetchAll();}
    unset($m);

    // Traditional Match Scouting only: current run for each match.
    $a=$pdo->prepare("SELECT sa.match_id,sa.frc_team_number,sa.action_code,sa.action_type,sa.result,sa.points,sa.match_time_sec
        FROM scouting_actions sa
        JOIN matches m ON m.id=sa.match_id AND m.organization_id=sa.organization_id AND m.event_id=sa.event_id
        WHERE sa.organization_id=? AND sa.event_id=? AND sa.deleted_at IS NULL AND sa.match_run_number=m.run_number
        ORDER BY sa.match_id,sa.frc_team_number,sa.match_time_sec,sa.id");
    $a->execute([$org,$eventId]);
    foreach($a->fetchAll() as $r){
        $mid=(int)$r['match_id'];$n=(int)$r['frc_team_number'];
        if(!isset($matchActuals[$mid][$n]))$matchActuals[$mid][$n]=[
            'points'=>0.0,'actions'=>0,'offense_success'=>0,'offense_failure'=>0,
            'defense_success'=>0,'defense_failure'=>0,'score_times'=>[],
            'cycle_time'=>null,'success_rate'=>null,
        ];
        $x=&$matchActuals[$mid][$n];$x['actions']++;$x['points']+=(float)$r['points'];
        $type=strtolower(trim((string)($r['action_type']??'')));$code=strtolower(trim((string)($r['action_code']??'')));$result=(string)($r['result']??'');
        $isDefense=$type==='defense'||in_array($code,['defense','plays_defense','block','block_intake'],true);
        if($isDefense){if($result==='Success')$x['defense_success']++;elseif($result==='Failure')$x['defense_failure']++;}
        if($type==='offense'&&in_array($result,['Success','Failure'],true)){if($result==='Success')$x['offense_success']++;else$x['offense_failure']++;}
        if($result==='Success'&&(float)$r['points']>0&&$r['match_time_sec']!==null)$x['score_times'][]=(int)$r['match_time_sec'];
        unset($x);
    }
    foreach($matchActuals as &$teamsActual){
        foreach($teamsActual as &$x){
            $tries=(int)$x['offense_success']+(int)$x['offense_failure'];
            $x['success_rate']=$tries>0?((float)$x['offense_success']/$tries*100.0):null;
            $times=$x['score_times'];sort($times);$cycles=[];
            for($i=1,$c=count($times);$i<$c;$i++)if($times[$i]>$times[$i-1])$cycles[]=$times[$i]-$times[$i-1];
            $x['cycle_time']=$cycles?array_sum($cycles)/count($cycles):null;unset($x['score_times']);
        }unset($x);
    }unset($teamsActual);

    // Event-level stats for quick pit-board context.
    try{$eventStats=neptune_event_robot_stats($pdo,$org,$eventId);}catch(Throwable $ignored){$eventStats=[];}

    // Open Tag Scouting consensus: only the strongest current signal per robot is needed on this board.
    try{
        $s=$pdo->prepare("SELECT o.frc_team_number,t.label,t.icon,t.severity,COUNT(*) mentions,MAX(o.created_at) latest_at
            FROM tag_scouting_observations o
            JOIN tag_scouting_observation_tags ot ON ot.observation_id=o.id
            JOIN tag_scouting_tags t ON t.id=ot.tag_id
            LEFT JOIN tag_scouting_tag_visibility tv ON tv.organization_id=o.organization_id AND tv.tag_id=t.id
            WHERE o.organization_id=? AND o.event_id=? AND o.status='open' AND t.active=1 AND COALESCE(tv.active,1)=1
            GROUP BY o.frc_team_number,t.id,t.label,t.icon,t.severity
            ORDER BY o.frc_team_number,mentions DESC,latest_at DESC");
        $s->execute([$org,$eventId]);
        foreach($s->fetchAll() as $r){$n=(int)$r['frc_team_number'];if(!isset($tagMap[$n]))$tagMap[$n]=$r;}
    }catch(Throwable $ignored){$tagMap=[];}

    // Operational ordering: last completed, first live/upcoming, then the one after that.
    $future=[];
    foreach($matches as $m){
        if((string)$m['state']==='ended')$lastMatch=$m;
        else $future[]=$m;
    }
    $nextMatch=$future[0]??null;
    $afterNext=$future[1]??null;

    // Persistent TBA logos for every robot that appears on this team's schedule.
    $allTeamNumbers=[];
    foreach($matches as $m)foreach($m['teams'] as $t)$allTeamNumbers[]=(int)$t['frc_team_number'];
    $allTeamNumbers=array_values(array_unique(array_filter($allTeamNumbers)));
    if($event&&$allTeamNumbers)$logoMap=neptune_team_logo_map($org,(int)($event['season_year']??date('Y')),$allTeamNumbers);

    // Run the same AUGUR matchup model used by Match Strategy for the next match.
    if($event&&$nextMatch){
        $red=[];$blue=[];
        foreach($nextMatch['teams'] as $t){if($t['alliance']==='Red')$red[]=(int)$t['frc_team_number'];else$blue[]=(int)$t['frc_team_number'];}
        if($red&&$blue){
            try{
                $settings=augur_prediction_settings();
                if(alliance_schema_ready($pdo)){
                    $loaded=alliance_load_workspace($pdo,$org,$eventId,false);
                    $settings=augur_prediction_settings($loaded['workspace']['settings']['matchup_model']??[]);
                }
                $nextPrediction=augur_prediction_run($pdo,$org,$event,$red,$blue,$settings,[
                    'use_tba_rankings'=>true,'use_epa'=>true,'cutoff_match'=>$nextMatch,
                    'side_a_number'=>'Red','side_b_number'=>'Blue',
                ]);
                foreach(array_merge($nextPrediction['a']['teams']??[],$nextPrediction['b']['teams']??[]) as $r){
                    $n=(int)($r['team']??0);if($n>0)$predictionMetrics[$n]=is_array($r['metrics']??null)?$r['metrics']:[];
                }
            }catch(Throwable $ignored){$nextPrediction=null;$predictionMetrics=[];}
        }
    }

    $pitOpsReady=neptune_pit_board_ensure_schema($pdo);
    $pitTeamState=neptune_pit_board_team_state($pdo,$org,$eventId,$teamNumber);
    $pitNextState=$nextMatch?neptune_pit_board_match_state($pdo,$org,$eventId,$teamNumber,(int)$nextMatch['id']):[
        'queue_lead_minutes'=>10,'queued'=>0,'checklist'=>neptune_pit_board_default_checklist(),'match_note'=>'','updated_at'=>null,
    ];
}

function pit_board_match_result(array $m,int $team): string {
    if($m['red_score']===null||$m['blue_score']===null)return '';
    $alliance='';foreach($m['teams']??[] as $t)if((int)$t['frc_team_number']===$team){$alliance=(string)$t['alliance'];break;}
    if($alliance==='')return '';$red=(int)$m['red_score'];$blue=(int)$m['blue_score'];
    if($red===$blue)return 'TIE';$winner=$red>$blue?'Red':'Blue';return $winner===$alliance?'WIN':'LOSS';
}
function pit_board_team_alliance(array $m,int $team): array {
    foreach($m['teams']??[] as $t)if((int)$t['frc_team_number']===$team)return [(string)$t['alliance'],(int)$t['station']];
    return ['',0];
}
function pit_board_utc_iso(?string $dt): string {
    $dt=trim((string)$dt);if($dt==='')return '';$ts=strtotime($dt.' UTC');return $ts?gmdate('c',$ts):'';
}
function pit_board_metric(array $predictionMetrics,array $eventStats,int $team,string $key): mixed {
    if(isset($predictionMetrics[$team][$key])&&is_numeric($predictionMetrics[$team][$key]))return $predictionMetrics[$team][$key];
    if($key==='neptune_epa'&&isset($predictionMetrics[$team]['augur_epa'])&&is_numeric($predictionMetrics[$team]['augur_epa']))return $predictionMetrics[$team]['augur_epa'];
    if($key==='ppm'&&isset($eventStats[$team]['ppm']))return $eventStats[$team]['ppm'];
    return null;
}
function pit_board_robot_tile(array $t,int $ourTeam,array $eventTeams,array $logoMap,array $predictionMetrics,array $eventStats,array $tagMap,array $badges=[]): string {
    $n=(int)$t['frc_team_number'];$alliance=(string)$t['alliance'];$station=(int)$t['station'];
    $name=(string)($eventTeams[$n]['nickname']??'');$logo=$logoMap[$n]??[];$logoUrl='';
    if(!empty($logo['exists']))$logoUrl=base_url((string)$logo['path']).'?v='.rawurlencode((string)($logo['version']??''));
    $nep=pit_board_metric($predictionMetrics,$eventStats,$n,'neptune_epa');$ppm=(float)($eventStats[$n]['ppm']??0);$cycle=$eventStats[$n]['cycle_time']??null;
    $tag=$tagMap[$n]??null;$badge=$badges[$n]??($n===$ourTeam?'OUR BOT':'');
    ob_start();?>
    <button type="button" class="pit-robot-tile <?=strtolower(e($alliance))?> robot-detail-trigger<?=$n===$ourTeam?' ours':''?>" data-event-id="<?=e($GLOBALS['eventId'])?>" data-team="<?=$n?>" aria-label="Open Robot Intelligence for team <?=$n?>">
      <div class="pit-robot-tile-top"><span><?=e($alliance.' '.$station)?></span><?php if($badge!==''):?><b class="pit-role-badge"><?=e($badge)?></b><?php endif;?></div>
      <div class="pit-robot-logo" data-logo-team="<?=$n?>"><?php if($logoUrl!==''):?><img src="<?=e($logoUrl)?>" alt="Team <?=$n?> logo"><?php else:?><i class="fa-solid fa-robot"></i><?php endif;?></div>
      <strong>#<?=$n?></strong>
      <span class="pit-robot-name"><?=e($name!==''?$name:'Team '.$n)?></span>
      <div class="pit-robot-metrics">
        <span><small>N-EPA</small><b><?=$nep!==null?number_format((float)$nep,1):'—'?></b></span>
        <span><small>PPM</small><b><?=$ppm>0?number_format($ppm,0):'—'?></b></span>
        <span><small>Cycle</small><b><?=$cycle!==null?number_format((float)$cycle,1).'s':'—'?></b></span>
      </div>
      <?php if($tag):?><div class="pit-scout-tag <?=e((string)$tag['severity'])?>"><i class="<?=e((string)$tag['icon'])?>"></i> <?=e((string)$tag['label'])?></div><?php endif;?>
    </button>
    <?php return (string)ob_get_clean();
}

$pageTitle='Pit Operations Board';$moduleName='AUGUR';$hideChrome=$tv;$bodyClass=$tv?'tv-mode pit-board-tv':'';
include dirname(__DIR__).'/partials_header.php';

$nextAlliance='';$nextStation=0;if($nextMatch)[$nextAlliance,$nextStation]=pit_board_team_alliance($nextMatch,$teamNumber);
$teamName='';foreach($myTeams as $t)if((int)$t['frc_team_number']===$teamNumber){$teamName=(string)($t['display_name']?:$t['nickname']);break;}
$roleBadges=[];
if($nextMatch){
    $ally=[];$opp=[];
    foreach($nextMatch['teams'] as $t){$n=(int)$t['frc_team_number'];$score=pit_board_metric($predictionMetrics,$eventStats,$n,'neptune_epa');if($score===null)$score=(float)($eventStats[$n]['ppm']??0);if($n===$teamNumber){$roleBadges[$n]='OUR BOT';continue;}if($t['alliance']===$nextAlliance)$ally[$n]=(float)$score;else$opp[$n]=(float)$score;}
    arsort($ally,SORT_NUMERIC);arsort($opp,SORT_NUMERIC);$i=0;foreach(array_keys($ally) as $n){$roleBadges[$n]=$i++===0?'ALLY LEAD':'ALLY';}
    $i=0;foreach(array_keys($opp) as $n){$roleBadges[$n]='THREAT #'.(++$i);}
}
$status=(string)($pitTeamState['robot_status']??'unset');
$statusLabel=['unset'=>'NOT SET','ready'=>'READY','working'=>'WORKING','issue'=>'ISSUE','queued'=>'QUEUED'][$status]??'NOT SET';
$statusIcon=['unset'=>'fa-circle-question','ready'=>'fa-circle-check','working'=>'fa-screwdriver-wrench','issue'=>'fa-triangle-exclamation','queued'=>'fa-person-running'][$status]??'fa-circle-question';
$csrf=csrf_token();
?>
<style>
.pit-board-titlebar{align-items:flex-start;gap:18px}.pit-board-titlebar .toolbar{margin:0}.pit-board-controls{padding:14px 16px}.pit-board-controls .analytics-toolbar{display:grid;grid-template-columns:minmax(320px,1.4fr) auto minmax(280px,.8fr);gap:10px;align-items:end}.pit-board-controls .analytics-toolbar>div{min-width:0}
.pit-ops-shell{display:grid;gap:14px;margin-top:16px}.pit-status-card{display:grid;grid-template-columns:minmax(250px,.7fr) minmax(440px,1.3fr);gap:14px;padding:0;overflow:hidden}.pit-status-main{padding:18px 20px;background:linear-gradient(135deg,color-mix(in srgb,var(--panel2) 92%,var(--accent) 8%),var(--panel));border-right:1px solid var(--line)}.pit-status-kicker{font-size:.72rem;font-weight:950;letter-spacing:.11em;color:var(--muted);text-transform:uppercase}.pit-status-value{display:flex;align-items:center;gap:10px;margin:7px 0 4px;font-size:clamp(1.65rem,3vw,2.5rem);font-weight:950;letter-spacing:-.03em}.pit-status-value i{font-size:.8em}.pit-status-value.ready{color:var(--good)}.pit-status-value.working{color:var(--warn)}.pit-status-value.issue{color:var(--bad)}.pit-status-value.queued{color:var(--ready)}.pit-status-value.unset{color:var(--muted)}.pit-status-team{font-weight:900}.pit-status-updated{margin-top:8px;font-size:.74rem;color:var(--muted)}
.pit-status-editor{padding:15px 18px}.pit-status-buttons{display:grid;grid-template-columns:repeat(4,1fr);gap:7px}.pit-status-btn{padding:10px 8px;border:1px solid var(--line);background:var(--panel2);color:var(--text);border-radius:6px;font-weight:900}.pit-status-btn.selected[data-status=ready]{border-color:var(--good);box-shadow:inset 0 0 0 1px var(--good)}.pit-status-btn.selected[data-status=working]{border-color:var(--warn);box-shadow:inset 0 0 0 1px var(--warn)}.pit-status-btn.selected[data-status=issue]{border-color:var(--bad);box-shadow:inset 0 0 0 1px var(--bad)}.pit-status-btn.selected[data-status=queued]{border-color:var(--ready);box-shadow:inset 0 0 0 1px var(--ready)}.pit-status-fields{display:grid;grid-template-columns:minmax(150px,.8fr) minmax(190px,1fr) auto auto;gap:9px;align-items:end;margin-top:10px}.pit-inline-toggle{display:flex;align-items:center;gap:8px;min-height:44px;padding:9px 10px;border:1px solid var(--line);border-radius:5px;background:var(--panel2);font-size:.8rem;font-weight:850}.pit-inline-toggle input{width:auto;margin:0}.pit-bumper-buttons{display:grid;grid-template-columns:1fr 1fr;gap:5px}.pit-bumper-btn{padding:9px;border:1px solid var(--line);border-radius:5px;background:var(--panel2);font-weight:900}.pit-bumper-btn.red.selected{border-color:var(--c1-red);background:color-mix(in srgb,var(--c1-red) 13%,var(--panel2))}.pit-bumper-btn.blue.selected{border-color:var(--ready);background:color-mix(in srgb,var(--ready) 13%,var(--panel2))}.pit-note-row{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end;margin-top:10px}.pit-save-state{min-width:130px}.pit-save-feedback{font-size:.74rem;font-weight:800;color:var(--muted)}
.pit-three-up{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.pit-snapshot{position:relative;padding:14px 15px;min-height:116px;border:1px solid var(--line);border-radius:7px;background:var(--panel)}.pit-snapshot.next{border-color:var(--accent);box-shadow:0 8px 22px var(--shadow)}.pit-snapshot-label{display:flex;justify-content:space-between;gap:10px;color:var(--muted);font-size:.7rem;font-weight:950;letter-spacing:.1em;text-transform:uppercase}.pit-snapshot h3{margin:5px 0 3px;font-size:1.45rem}.pit-snapshot-time{font-weight:900}.pit-snapshot-meta{margin-top:7px;color:var(--muted);font-size:.8rem}.pit-snapshot-result{display:inline-flex;margin-top:8px;padding:4px 8px;border-radius:999px;border:1px solid var(--line);font-size:.68rem;font-weight:950}.pit-snapshot-result.win{color:var(--good);border-color:var(--good)}.pit-snapshot-result.loss{color:var(--bad);border-color:var(--bad)}.pit-snapshot-result.tie{color:var(--warn);border-color:var(--warn)}
.pit-next-card{padding:0;overflow:hidden}.pit-next-head{display:grid;grid-template-columns:minmax(260px,.8fr) minmax(260px,.65fr) minmax(260px,.75fr);gap:0;border-bottom:1px solid var(--line)}.pit-next-identity{padding:18px 20px}.pit-next-eyebrow{font-size:.72rem;font-weight:950;letter-spacing:.11em;color:var(--accent);text-transform:uppercase}.pit-next-title{font-size:clamp(2rem,4vw,3.6rem);font-weight:950;letter-spacing:-.05em;line-height:1;margin:5px 0}.pit-next-sub{color:var(--muted);font-weight:850}.pit-countdown{display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;padding:16px;border-left:1px solid var(--line);border-right:1px solid var(--line);background:var(--panel2)}.pit-countdown span{font-size:.68rem;font-weight:950;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}.pit-countdown strong{display:block;margin:4px 0;font-size:clamp(1.8rem,4vw,3.3rem);line-height:1;font-variant-numeric:tabular-nums}.pit-countdown small{font-weight:800;color:var(--muted)}.pit-next-prediction{padding:16px 18px;display:flex;flex-direction:column;justify-content:center}.pit-next-prediction-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px}.pit-predict-side{padding:9px;border-radius:5px;background:var(--panel2);border:1px solid var(--line)}.pit-predict-side.red{border-top:3px solid var(--c1-red)}.pit-predict-side.blue{border-top:3px solid var(--ready)}.pit-predict-side span{display:block;color:var(--muted);font-size:.68rem;font-weight:900}.pit-predict-side b{display:block;font-size:1.45rem}.pit-win-prob{margin-top:7px;font-size:.78rem;font-weight:900}.pit-next-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:9px}.pit-next-actions .btn{font-size:.78rem}
.pit-alliance-board{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:16px 18px}.pit-alliance-side{min-width:0}.pit-alliance-heading{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:.74rem;font-weight:950;letter-spacing:.09em;text-transform:uppercase}.pit-alliance-heading.red{color:var(--c1-red)}.pit-alliance-heading.blue{color:var(--ready)}.pit-alliance-robots{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.pit-robot-tile{position:relative;display:flex;flex-direction:column;align-items:center;min-width:0;padding:10px 8px 9px;border:1px solid var(--line);border-top:4px solid var(--line);border-radius:7px;background:var(--panel2);color:var(--text);text-align:center;transition:.12s}.pit-robot-tile:hover{transform:translateY(-2px);border-color:var(--accent)}.pit-robot-tile.red{border-top-color:var(--c1-red)}.pit-robot-tile.blue{border-top-color:var(--ready)}.pit-robot-tile.ours{box-shadow:inset 0 0 0 2px var(--good)}.pit-robot-tile-top{display:flex;align-items:center;justify-content:space-between;width:100%;gap:5px;color:var(--muted);font-size:.64rem;font-weight:900}.pit-role-badge{padding:3px 5px;border-radius:999px;background:color-mix(in srgb,var(--accent) 14%,var(--panel));color:var(--accent);font-size:.58rem;white-space:nowrap}.pit-robot-logo{display:flex;align-items:center;justify-content:center;width:58px;height:58px;margin:6px auto 4px;border-radius:8px;background:var(--panel);border:1px solid var(--line);overflow:hidden}.pit-robot-logo img{width:100%;height:100%;object-fit:contain;padding:4px}.pit-robot-logo i{font-size:1.7rem;color:var(--muted)}.pit-robot-tile>strong{font-size:1.42rem;line-height:1}.pit-robot-name{width:100%;margin:3px 0 7px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted);font-size:.7rem;font-weight:800}.pit-robot-metrics{display:grid;grid-template-columns:repeat(3,1fr);width:100%;gap:4px}.pit-robot-metrics span{padding:5px 3px;background:var(--panel);border-radius:4px}.pit-robot-metrics small{display:block;color:var(--muted);font-size:.54rem;font-weight:900}.pit-robot-metrics b{display:block;font-size:.78rem}.pit-scout-tag{width:100%;margin-top:6px;padding:4px 5px;border-radius:4px;background:var(--panel);font-size:.62rem;font-weight:850;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pit-scout-tag.warning,.pit-scout-tag.critical{color:var(--bad)}.pit-scout-tag.positive{color:var(--good)}
.pit-brief-checklist{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.72fr);gap:12px;padding:0 18px 18px}.pit-brief,.pit-checklist{padding:14px;border:1px solid var(--line);border-radius:7px;background:var(--panel2)}.pit-section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 10px;font-size:.86rem;font-weight:950;letter-spacing:.07em;text-transform:uppercase}.pit-section-title i{color:var(--accent)}.pit-brief-list{display:grid;gap:7px}.pit-brief-line{display:flex;gap:9px;align-items:flex-start;padding:8px;border-radius:5px;background:var(--panel)}.pit-brief-line i{width:18px;margin-top:2px;text-align:center;color:var(--accent)}.pit-brief-line b{font-size:.8rem}.pit-brief-line span{display:block;color:var(--muted);font-size:.72rem;margin-top:2px}.pit-match-note{margin-top:8px;padding:9px;border-left:3px solid var(--warn);background:var(--panel);font-size:.78rem}.pit-check-items{display:grid;gap:5px}.pit-check-item{display:grid;grid-template-columns:auto 1fr auto;gap:8px;align-items:center;padding:7px 8px;border:1px solid var(--line);border-radius:5px;background:var(--panel)}.pit-check-item input{width:auto;margin:0}.pit-check-item.done span{text-decoration:line-through;color:var(--muted)}.pit-check-delete{width:30px;height:30px;padding:0}.pit-check-add{display:grid;grid-template-columns:1fr auto;gap:6px;margin-top:7px}.pit-match-ops{display:grid;grid-template-columns:110px 1fr auto;gap:7px;align-items:end;margin-top:8px}.pit-queue-btn.queued{border-color:var(--ready);background:color-mix(in srgb,var(--ready) 12%,var(--panel2))}
.pit-lists{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,.95fr);gap:14px}.pit-list-card{padding:15px}.pit-list-card h2{margin:0 0 10px}.pit-upcoming-list,.pit-history-list{display:grid;gap:7px}.pit-match-row{display:grid;grid-template-columns:minmax(105px,.55fr) minmax(130px,.75fr) minmax(0,1.3fr) auto;gap:10px;align-items:center;padding:10px;border:1px solid var(--line);border-radius:6px;background:var(--panel2)}.pit-match-row:hover{border-color:var(--accent)}.pit-match-row h3{margin:0;font-size:1rem}.pit-match-row .muted{font-size:.72rem}.pit-match-row-teams{display:flex;align-items:center;gap:5px;flex-wrap:wrap}.pit-mini-team{display:inline-flex;padding:4px 6px;border-radius:4px;border:1px solid var(--line);font-size:.68rem;font-weight:900}.pit-mini-team.red{border-color:color-mix(in srgb,var(--c1-red) 55%,var(--line))}.pit-mini-team.blue{border-color:color-mix(in srgb,var(--ready) 55%,var(--line))}.pit-mini-team.ours{box-shadow:inset 0 0 0 1px var(--good)}.pit-row-score{font-weight:950}.pit-empty{padding:24px;text-align:center;color:var(--muted)}
.pit-board-tv .pit-board-controls,.pit-board-tv .pit-status-editor,.pit-board-tv .pit-check-add,.pit-board-tv .pit-check-delete,.pit-board-tv .pit-match-ops,.pit-board-tv .pit-next-actions{display:none!important}.pit-board-tv .pit-ops-shell{margin-top:0}.pit-board-tv .pit-status-card{grid-template-columns:1fr}.pit-board-tv .pit-status-main{display:flex;align-items:center;justify-content:space-between;gap:24px;border-right:0;padding:18px 24px}.pit-board-tv .pit-status-value{font-size:3rem}.pit-board-tv .pit-next-title{font-size:4.4rem}.pit-board-tv .pit-countdown strong{font-size:4.2rem}.pit-board-tv .pit-robot-logo{width:78px;height:78px}.pit-board-tv .pit-robot-tile>strong{font-size:1.8rem}.pit-board-tv .pit-brief-checklist{grid-template-columns:1fr .82fr}.pit-board-tv .pit-lists{display:none}
@media(max-width:1100px){.pit-status-card{grid-template-columns:1fr}.pit-status-main{border-right:0;border-bottom:1px solid var(--line)}.pit-next-head{grid-template-columns:1fr 1fr}.pit-next-prediction{grid-column:1/-1;border-top:1px solid var(--line)}.pit-countdown{border-right:0}.pit-brief-checklist,.pit-lists{grid-template-columns:1fr}.pit-board-tv .pit-brief-checklist{grid-template-columns:1fr}}
@media(max-width:800px){.pit-board-controls .analytics-toolbar{grid-template-columns:1fr}.pit-three-up{grid-template-columns:1fr}.pit-next-head{grid-template-columns:1fr}.pit-countdown{border:0;border-top:1px solid var(--line)}.pit-alliance-board{grid-template-columns:1fr}.pit-status-fields{grid-template-columns:1fr 1fr}.pit-note-row{grid-template-columns:1fr}.pit-alliance-robots{grid-template-columns:repeat(3,1fr)}.pit-match-row{grid-template-columns:1fr 1fr}.pit-match-row-teams{grid-column:1/-1}.pit-status-buttons{grid-template-columns:repeat(2,1fr)}}
@media(max-width:520px){.pit-status-fields{grid-template-columns:1fr}.pit-alliance-robots{grid-template-columns:1fr}.pit-robot-tile{display:grid;grid-template-columns:52px 1fr;column-gap:9px;text-align:left;align-items:center}.pit-robot-tile-top{grid-column:1/-1}.pit-robot-logo{grid-row:2/5;width:52px;height:52px;margin:0}.pit-robot-tile>strong,.pit-robot-name,.pit-robot-metrics,.pit-scout-tag{grid-column:2}.pit-robot-metrics{grid-template-columns:repeat(3,1fr)}.pit-match-row{grid-template-columns:1fr}.pit-match-row-teams{grid-column:auto}}
</style>

<?php if($tv):?>
<div class="tv-brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><div><div class="muted">AUGUR · PIT OPERATIONS BOARD</div><div style="font-size:2rem;font-weight:950">#<?=e($teamNumber)?> <?=e($teamName)?></div></div></div>
<?php else:?>
<div class="toolbar pit-board-titlebar" style="justify-content:space-between">
  <div><div class="module-eyebrow"><span>AUGUR</span><small>Pit Operations</small></div><h1 style="margin-bottom:4px">Pit Operations Board</h1><div class="muted">A pit-operations board for your next match: readiness, queue timing, alliance context, checklist, and recent results.</div></div>
  <div class="toolbar"><a class="btn secondary" href="<?=e(base_url('analytics/team-display.php').'?'.http_build_query(['event_id'=>$eventId,'team'=>$teamNumber]))?>"><i class="fa-solid fa-table-cells-large"></i> Match Board</a><a class="btn secondary" href="?<?=e(http_build_query(['event_id'=>$eventId,'team'=>$teamNumber,'tv'=>1]))?>" target="_blank"><i class="fa-solid fa-expand"></i> TV Mode</a></div>
</div>
<div class="card pit-board-controls">
  <form method="get" class="analytics-toolbar">
    <div><label>Event</label><select name="event_id" onchange="this.form.submit()"><?=neptune_event_options_html($events,$eventId)?></select></div>
    <div><?=neptune_history_toggle_html(neptune_selector_show_history(),'events')?></div>
    <div><label>Our team</label><select name="team" onchange="this.form.submit()"><?php foreach($myTeams as $t):?><option value="<?=$t['frc_team_number']?>" <?=$teamNumber===(int)$t['frc_team_number']?'selected':''?>>#<?=e($t['frc_team_number'].' '.($t['display_name']?:$t['nickname']))?></option><?php endforeach;?></select></div>
  </form>
</div>
<?php endif;?>

<div class="pit-ops-shell">
  <section class="card pit-status-card">
    <div class="pit-status-main">
      <div><div class="pit-status-kicker">Our Robot · #<?=e($teamNumber)?></div><div id="pitStatusValue" class="pit-status-value <?=e($status)?>"><i class="fa-solid <?=e($statusIcon)?>"></i><span><?=e($statusLabel)?></span></div><div class="pit-status-team"><?=e($teamName)?></div></div>
      <div class="pit-status-updated" id="pitStatusUpdated"><?php if(!empty($pitTeamState['updated_at'])):?>Updated <?=e($pitTeamState['updated_at'])?><?php else:?>Awaiting pit status update<?php endif;?></div>
    </div>
    <?php if(!$tv):?>
    <div class="pit-status-editor">
      <?php if(!$pitOpsReady):?><div class="notice">Pit operations storage could not be initialized. The schedule remains available, but manual status controls are disabled.</div><?php else:?>
      <input type="hidden" id="pitRobotStatus" value="<?=e($status)?>">
      <div class="pit-status-buttons" id="pitStatusButtons">
        <?php foreach(['ready'=>'READY','working'=>'WORKING','issue'=>'ISSUE','queued'=>'QUEUED'] as $k=>$label):?><button type="button" class="pit-status-btn<?=$status===$k?' selected':''?>" data-status="<?=$k?>"><?=$label?></button><?php endforeach;?>
      </div>
      <div class="pit-status-fields">
        <div><label for="pitBattery">Battery</label><input id="pitBattery" maxlength="80" value="<?=e((string)($pitTeamState['battery_label']??''))?>" placeholder="#7 / charged"></div>
        <div><label>Bumpers</label><input type="hidden" id="pitBumper" value="<?=e((string)($pitTeamState['bumper_color']??''))?>"><div class="pit-bumper-buttons"><button type="button" class="pit-bumper-btn red <?=($pitTeamState['bumper_color']??'')==='red'?'selected':''?>" data-bumper="red">RED</button><button type="button" class="pit-bumper-btn blue <?=($pitTeamState['bumper_color']??'')==='blue'?'selected':''?>" data-bumper="blue">BLUE</button></div></div>
        <label class="pit-inline-toggle"><input id="pitInspection" type="checkbox" <?=!empty($pitTeamState['inspection_ready'])?'checked':''?>> Inspection ✓</label>
        <label class="pit-inline-toggle"><input id="pitDrivers" type="checkbox" <?=!empty($pitTeamState['drive_team_ready'])?'checked':''?>> Drivers ready</label>
      </div>
      <div class="pit-note-row"><div><label for="pitNote">Pit note</label><input id="pitNote" maxlength="500" value="<?=e((string)($pitTeamState['pit_note']??''))?>" placeholder="Example: Recheck left intake roller before leaving"></div><button type="button" class="btn pit-save-state" id="pitSaveState"><i class="fa-solid fa-floppy-disk"></i> Save status</button></div>
      <div class="pit-save-feedback" id="pitSaveFeedback"></div>
      <?php endif;?>
    </div>
    <?php endif;?>
  </section>

  <div class="pit-three-up">
    <section class="pit-snapshot"><div class="pit-snapshot-label"><span>Last Match</span><?php if($lastMatch):?><span><?=e(strtoupper((string)$lastMatch['state']))?></span><?php endif;?></div><?php if($lastMatch):$res=pit_board_match_result($lastMatch,$teamNumber);?><h3><?=e(neptune_match_label($lastMatch))?></h3><div class="pit-snapshot-time pit-local-time" data-utc="<?=e(pit_board_utc_iso($lastMatch['scheduled_time']??null))?>">—</div><div class="pit-snapshot-meta"><?=(int)$lastMatch['red_score']?> – <?=(int)$lastMatch['blue_score']?> · TBA</div><?php if($res):?><span class="pit-snapshot-result <?=strtolower($res)?>"><?=$res?></span><?php endif;?><?php else:?><div class="pit-empty">No completed match yet.</div><?php endif;?></section>
    <section class="pit-snapshot next"><div class="pit-snapshot-label"><span>Next Match</span><span><?=e($nextAlliance!==''?$nextAlliance.' '.$nextStation:'—')?></span></div><?php if($nextMatch):?><h3><?=e(neptune_match_label($nextMatch))?></h3><div class="pit-snapshot-time pit-local-time" data-utc="<?=e(pit_board_utc_iso($nextMatch['scheduled_time']??null))?>">Time TBD</div><div class="pit-snapshot-meta" id="pitMiniCountdown">Preparing countdown…</div><?php else:?><div class="pit-empty">No upcoming match.</div><?php endif;?></section>
    <section class="pit-snapshot"><div class="pit-snapshot-label"><span>After Next</span><span><?php if($afterNext){[$a,$st]=pit_board_team_alliance($afterNext,$teamNumber);echo e($a.' '.$st);}else echo '—';?></span></div><?php if($afterNext):?><h3><?=e(neptune_match_label($afterNext))?></h3><div class="pit-snapshot-time pit-local-time" data-utc="<?=e(pit_board_utc_iso($afterNext['scheduled_time']??null))?>">Time TBD</div><div class="pit-snapshot-meta pit-relative-time" data-utc="<?=e(pit_board_utc_iso($afterNext['scheduled_time']??null))?>">—</div><?php else:?><div class="pit-empty">No later match loaded.</div><?php endif;?></section>
  </div>

  <?php if($nextMatch):
    $redPred=$nextPrediction['a']??null;$bluePred=$nextPrediction['b']??null;$ourPred=$nextAlliance==='Red'?$redPred:$bluePred;
    $nextIso=pit_board_utc_iso($nextMatch['scheduled_time']??null);
  ?>
  <section class="card pit-next-card">
    <div class="pit-next-head">
      <div class="pit-next-identity"><div class="pit-next-eyebrow">Next Match · <?=e(strtoupper((string)$nextMatch['state']))?></div><div class="pit-next-title"><?=e(neptune_match_label($nextMatch))?></div><div class="pit-next-sub">#<?=e($teamNumber)?> · <?=e($nextAlliance.' '.$nextStation)?> <span class="pit-local-time" data-utc="<?=e($nextIso)?>"></span></div><div class="pit-next-actions"><a class="btn secondary" href="<?=e(base_url('analytics/match-strategy.php').'?'.http_build_query(['event_id'=>$eventId,'team'=>$teamNumber,'match_id'=>(int)$nextMatch['id']]))?>"><i class="fa-solid fa-chess-board"></i> Match Strategy</a><?php if(!$tv&&$pitOpsReady):?><button type="button" class="btn secondary pit-queue-btn <?=!empty($pitNextState['queued'])?'queued':''?>" id="pitMarkQueued"><i class="fa-solid fa-person-running"></i> <span><?=!empty($pitNextState['queued'])?'Clear Queued':'Mark Queued'?></span></button><?php endif;?></div></div>
      <div class="pit-countdown" id="pitCountdown" data-match-time="<?=e($nextIso)?>" data-queue-minutes="<?=e((int)($pitNextState['queue_lead_minutes']??10))?>" data-match-state="<?=e((string)$nextMatch['state'])?>" data-queued="<?=!empty($pitNextState['queued'])?'1':'0'?>"><span>Queue Countdown</span><strong>—</strong><small><?=e((int)($pitNextState['queue_lead_minutes']??10))?> min before scheduled match</small></div>
      <div class="pit-next-prediction"><div class="pit-section-title"><span><i class="fa-solid fa-wand-magic-sparkles"></i> AUGUR Forecast</span><?php if($nextPrediction):?><small><?=number_format((float)($nextPrediction['confidence']??0),0)?>% confidence</small><?php endif;?></div><?php if($nextPrediction):?><div class="pit-next-prediction-grid"><div class="pit-predict-side red"><span>RED PREDICTED</span><b><?=number_format((float)($redPred['predicted_score']??0),0)?></b></div><div class="pit-predict-side blue"><span>BLUE PREDICTED</span><b><?=number_format((float)($bluePred['predicted_score']??0),0)?></b></div></div><div class="pit-win-prob"><?=e($nextAlliance)?> win probability: <?=number_format((float)($ourPred['win_probability']??50),0)?>%</div><?php else:?><div class="muted">Prediction unavailable for this matchup. The operations board still works normally.</div><?php endif;?></div>
    </div>

    <div class="pit-alliance-board">
      <div class="pit-alliance-side"><div class="pit-alliance-heading red"><i class="fa-solid fa-circle"></i> Red Alliance</div><div class="pit-alliance-robots"><?php foreach($nextMatch['teams'] as $t)if($t['alliance']==='Red')echo pit_board_robot_tile($t,$teamNumber,$eventTeams,$logoMap,$predictionMetrics,$eventStats,$tagMap,$roleBadges);?></div></div>
      <div class="pit-alliance-side"><div class="pit-alliance-heading blue"><i class="fa-solid fa-circle"></i> Blue Alliance</div><div class="pit-alliance-robots"><?php foreach($nextMatch['teams'] as $t)if($t['alliance']==='Blue')echo pit_board_robot_tile($t,$teamNumber,$eventTeams,$logoMap,$predictionMetrics,$eventStats,$tagMap,$roleBadges);?></div></div>
    </div>

    <div class="pit-brief-checklist">
      <div class="pit-brief"><div class="pit-section-title"><span><i class="fa-solid fa-bullseye"></i> Match Brief</span></div><div class="pit-brief-list">
        <?php
          $ourSide=[];$oppSide=[];foreach($nextMatch['teams'] as $t){$n=(int)$t['frc_team_number'];if($t['alliance']===$nextAlliance)$ourSide[]=$n;else$oppSide[]=$n;}
          $partnerScores=[];foreach($ourSide as $n)if($n!==$teamNumber){$v=pit_board_metric($predictionMetrics,$eventStats,$n,'neptune_epa');if($v===null)$v=(float)($eventStats[$n]['ppm']??0);$partnerScores[$n]=(float)$v;}arsort($partnerScores);
          $oppScores=[];foreach($oppSide as $n){$v=pit_board_metric($predictionMetrics,$eventStats,$n,'neptune_epa');if($v===null)$v=(float)($eventStats[$n]['ppm']??0);$oppScores[$n]=(float)$v;}arsort($oppScores);
          $bestPartner=(int)(array_key_first($partnerScores)??0);$topThreat=(int)(array_key_first($oppScores)??0);
        ?>
        <?php if($bestPartner):?><div class="pit-brief-line"><i class="fa-solid fa-handshake"></i><div><b>Alliance lead: #<?=$bestPartner?> <?=e((string)($eventTeams[$bestPartner]['nickname']??''))?></b><span>Best projected alliance partner by current Neptune intelligence.</span></div></div><?php endif;?>
        <?php if($topThreat):?><div class="pit-brief-line"><i class="fa-solid fa-crosshairs"></i><div><b>Primary threat: #<?=$topThreat?> <?=e((string)($eventTeams[$topThreat]['nickname']??''))?></b><span>Highest projected opponent on the next-match board.</span></div></div><?php endif;?>
        <?php if($nextPrediction):?><div class="pit-brief-line"><i class="fa-solid fa-chart-line"></i><div><b><?=e($nextAlliance)?> projected <?=number_format((float)($ourPred['predicted_score']??0),0)?> points</b><span><?=number_format((float)($ourPred['win_probability']??50),0)?>% modeled win probability · <?=number_format((float)($nextPrediction['confidence']??0),0)?>% model confidence.</span></div></div><?php endif;?>
        <?php if($pitTeamState['pit_note']??''):?><div class="pit-brief-line"><i class="fa-solid fa-wrench"></i><div><b>Pit note</b><span><?=e((string)$pitTeamState['pit_note'])?></span></div></div><?php endif;?>
      </div><?php if(!empty($pitNextState['match_note'])):?><div class="pit-match-note"><b>Match note:</b> <?=e((string)$pitNextState['match_note'])?></div><?php endif;?></div>

      <div class="pit-checklist"><div class="pit-section-title"><span><i class="fa-solid fa-list-check"></i> Before <?=e(neptune_match_label($nextMatch))?></span><small id="pitChecklistCount"></small></div><div class="pit-check-items" id="pitChecklistItems"><?php foreach($pitNextState['checklist'] as $item):?><label class="pit-check-item <?=!empty($item['done'])?'done':''?>" data-item-id="<?=e($item['id'])?>"><input type="checkbox" <?=!empty($item['done'])?'checked':''?> <?=$tv||!$pitOpsReady?'disabled':''?>><span><?=e($item['label'])?></span><?php if(!$tv&&!empty($item['custom'])&&$pitOpsReady):?><button type="button" class="btn secondary pit-check-delete" title="Remove custom item"><i class="fa-solid fa-xmark"></i></button><?php else:?><i></i><?php endif;?></label><?php endforeach;?></div>
        <?php if(!$tv&&$pitOpsReady):?><div class="pit-check-add"><input id="pitNewCheck" maxlength="120" placeholder="Add pit checklist item"><button class="btn secondary" type="button" id="pitAddCheck"><i class="fa-solid fa-plus"></i> Add</button></div><div class="pit-match-ops"><div><label for="pitQueueLead">Queue lead</label><select id="pitQueueLead"><?php foreach([5,8,10,12,15,20] as $m):?><option value="<?=$m?>" <?=(int)$pitNextState['queue_lead_minutes']===$m?'selected':''?>><?=$m?> min</option><?php endforeach;?></select></div><div><label for="pitMatchNote">Match note</label><input id="pitMatchNote" maxlength="500" value="<?=e((string)$pitNextState['match_note'])?>" placeholder="Short driver/pit reminder"></div><button type="button" class="btn secondary" id="pitSaveMatch"><i class="fa-solid fa-floppy-disk"></i> Save</button></div><?php endif;?>
      </div>
    </div>
  </section>
  <?php endif;?>

  <div class="pit-lists">
    <section class="card pit-list-card"><h2><i class="fa-solid fa-forward"></i> Upcoming</h2><div class="pit-upcoming-list">
      <?php $upCount=0;foreach($matches as $m)if((string)$m['state']!=='ended'){if($nextMatch&&(int)$m['id']===(int)$nextMatch['id'])continue;$upCount++;[$a,$st]=pit_board_team_alliance($m,$teamNumber);?>
      <div class="pit-match-row"><div><h3><?=e(neptune_match_label($m))?></h3><div class="muted"><?=e($a.' '.$st)?></div></div><div><b class="pit-local-time" data-utc="<?=e(pit_board_utc_iso($m['scheduled_time']??null))?>">Time TBD</b><div class="muted pit-relative-time" data-utc="<?=e(pit_board_utc_iso($m['scheduled_time']??null))?>"></div></div><div class="pit-match-row-teams"><?php foreach($m['teams'] as $t):?><span class="pit-mini-team <?=strtolower(e($t['alliance']))?><?=(int)$t['frc_team_number']===$teamNumber?' ours':''?>">#<?=e($t['frc_team_number'])?></span><?php endforeach;?></div><a class="btn secondary" href="<?=e(base_url('analytics/match-strategy.php').'?'.http_build_query(['event_id'=>$eventId,'team'=>$teamNumber,'match_id'=>(int)$m['id']]))?>">Strategy</a></div>
      <?php }if(!$upCount):?><div class="pit-empty">No additional upcoming matches loaded.</div><?php endif;?>
    </div></section>

    <section class="card pit-list-card"><h2><i class="fa-solid fa-clock-rotate-left"></i> Recent Match History</h2><div class="pit-history-list">
      <?php $history=array_values(array_filter($matches,fn($m)=>(string)$m['state']==='ended'));$history=array_reverse($history);$history=array_slice($history,0,8);foreach($history as $m):$res=pit_board_match_result($m,$teamNumber);$own=$matchActuals[(int)$m['id']][$teamNumber]??null;?>
      <div class="pit-match-row"><div><h3><?=e(neptune_match_label($m))?></h3><?php if($res):?><span class="pit-snapshot-result <?=strtolower($res)?>"><?=$res?></span><?php endif;?></div><div><b class="pit-row-score"><?=(int)$m['red_score']?> – <?=(int)$m['blue_score']?></b><div class="muted pit-local-time" data-utc="<?=e(pit_board_utc_iso($m['scheduled_time']??null))?>"></div></div><div class="pit-match-row-teams"><?php foreach($m['teams'] as $t):?><span class="pit-mini-team <?=strtolower(e($t['alliance']))?><?=(int)$t['frc_team_number']===$teamNumber?' ours':''?>">#<?=e($t['frc_team_number'])?></span><?php endforeach;?></div><div><?php if($own):?><b><?=number_format((float)$own['points'],0)?> scout pts</b><div class="muted">Off <?=$own['success_rate']!==null?number_format((float)$own['success_rate'],0).'%':'—'?></div><?php else:?><span class="muted">No scout data</span><?php endif;?></div></div>
      <?php endforeach;if(!$history):?><div class="pit-empty">No completed matches yet.</div><?php endif;?>
    </div></section>
  </div>
</div>

<?php include __DIR__.'/_robot_modal.php';?>
<script>
(()=>{
  const cfg={eventId:<?=json_encode($eventId)?>,team:<?=json_encode($teamNumber)?>,matchId:<?=json_encode((int)($nextMatch['id']??0))?>,csrf:<?=json_encode($csrf)?>,api:<?=json_encode(base_url('api/pit-match-board.php'))?>,logoApi:<?=json_encode(base_url('api/team-logo.php'))?>};
  const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const post=async(data)=>{const body=new FormData();Object.entries(data).forEach(([k,v])=>body.set(k,String(v??'')));body.set('csrf',cfg.csrf);body.set('event_id',String(cfg.eventId));body.set('team',String(cfg.team));if(cfg.matchId)body.set('match_id',String(cfg.matchId));const res=await fetch(cfg.api,{method:'POST',body,credentials:'same-origin'});let json={};try{json=await res.json()}catch(e){}if(!res.ok||!json.ok)throw new Error(json.message||'Update failed.');return json;};
  const feedback=(msg,ok=true)=>{const el=$('#pitSaveFeedback');if(el){el.textContent=msg;el.style.color=ok?'var(--good)':'var(--bad)';setTimeout(()=>{el.textContent='';},3500);}};

  function fmtTime(iso){if(!iso)return 'Time TBD';const d=new Date(iso);if(Number.isNaN(d.getTime()))return 'Time TBD';return d.toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});}
  function relative(iso){if(!iso)return '';const d=new Date(iso);if(Number.isNaN(d.getTime()))return '';let sec=Math.round((d-Date.now())/1000);const past=sec<0;sec=Math.abs(sec);const h=Math.floor(sec/3600),m=Math.floor((sec%3600)/60);if(past)return h>0?`${h}h ${m}m ago`:`${m}m ago`;return h>0?`in ${h}h ${m}m`:`in ${m}m`;}
  $$('.pit-local-time[data-utc]').forEach(el=>{if(el.dataset.utc)el.textContent=fmtTime(el.dataset.utc);});
  $$('.pit-relative-time[data-utc]').forEach(el=>{if(el.dataset.utc)el.textContent=relative(el.dataset.utc);});

  function updateCountdown(){const box=$('#pitCountdown');if(!box)return;const strong=$('strong',box),small=$('small',box),mini=$('#pitMiniCountdown');const iso=box.dataset.matchTime;if(!iso){strong.textContent='TBD';if(mini)mini.textContent='Schedule time not loaded';return;}const target=new Date(iso).getTime();if(!Number.isFinite(target))return;const lead=(parseInt(box.dataset.queueMinutes||'10',10)||10)*60000;const queue=target-lead;const now=Date.now();const state=box.dataset.matchState||'';const queued=box.dataset.queued==='1';let label='QUEUE IN',delta=queue-now;
    if(state==='running'||state==='ready'||state==='paused'){label=state==='running'?'ON FIELD':state.toUpperCase();delta=target-now;}else if(queued){label='QUEUED';delta=target-now;}else if(now>=queue){label='QUEUE NOW';delta=target-now;}if(now>target&&state!=='ended'){label='MATCH DUE';delta=now-target;}
    const s=Math.max(0,Math.floor(Math.abs(delta)/1000)),h=Math.floor(s/3600),m=Math.floor((s%3600)/60),ss=s%60;strong.textContent=h>0?`${h}:${String(m).padStart(2,'0')}:${String(ss).padStart(2,'0')}`:`${m}:${String(ss).padStart(2,'0')}`;const caption=$('span',box);if(caption)caption.textContent=label;small.textContent=`Scheduled ${fmtTime(iso)} · ${Math.round(lead/60000)} min queue lead`;if(mini)mini.textContent=label==='QUEUE IN'?`Queue in ${h?`${h}h `:''}${m}m`:label+(delta>0?` · match in ${h?`${h}h `:''}${m}m`:'');
  }
  updateCountdown();setInterval(updateCountdown,1000);setInterval(()=>$$('.pit-relative-time[data-utc]').forEach(el=>{if(el.dataset.utc)el.textContent=relative(el.dataset.utc);}),30000);

  const statusInput=$('#pitRobotStatus');
  function setStatusVisual(status){if(statusInput)statusInput.value=status;$$('.pit-status-btn').forEach(b=>b.classList.toggle('selected',b.dataset.status===status));const val=$('#pitStatusValue');if(val){val.className='pit-status-value '+status;const map={ready:['fa-circle-check','READY'],working:['fa-screwdriver-wrench','WORKING'],issue:['fa-triangle-exclamation','ISSUE'],queued:['fa-person-running','QUEUED'],unset:['fa-circle-question','NOT SET']};const item=map[status]||map.unset;val.innerHTML=`<i class="fa-solid ${item[0]}"></i><span>${item[1]}</span>`;}}
  $$('.pit-status-btn').forEach(btn=>btn.addEventListener('click',()=>setStatusVisual(btn.dataset.status||'unset')));
  $$('.pit-bumper-btn').forEach(btn=>btn.addEventListener('click',()=>{const v=btn.dataset.bumper||'';const input=$('#pitBumper');if(input)input.value=v;$$('.pit-bumper-btn').forEach(b=>b.classList.toggle('selected',b===btn));}));
  $('#pitSaveState')?.addEventListener('click',async()=>{try{const r=await post({action:'save_team',robot_status:statusInput?.value||'unset',battery_label:$('#pitBattery')?.value||'',bumper_color:$('#pitBumper')?.value||'',inspection_ready:$('#pitInspection')?.checked?1:0,drive_team_ready:$('#pitDrivers')?.checked?1:0,pit_note:$('#pitNote')?.value||''});feedback(r.message||'Saved.');setStatusVisual(r.state?.robot_status||statusInput?.value||'unset');const up=$('#pitStatusUpdated');if(up)up.textContent='Updated just now';}catch(e){feedback(e.message,false);}});

  function updateChecklistCount(){const items=$$('.pit-check-item');const done=items.filter(i=>$('input[type=checkbox]',i)?.checked).length;const el=$('#pitChecklistCount');if(el)el.textContent=`${done}/${items.length} ready`;items.forEach(i=>i.classList.toggle('done',!!$('input[type=checkbox]',i)?.checked));}
  updateChecklistCount();
  $('#pitChecklistItems')?.addEventListener('change',async e=>{const input=e.target.closest('input[type=checkbox]');if(!input)return;const row=input.closest('.pit-check-item');if(!row)return;updateChecklistCount();try{const r=await post({action:'toggle_check',item_id:row.dataset.itemId||'',done:input.checked?1:0});if(r.team_state?.robot_status)setStatusVisual(r.team_state.robot_status);const box=$('#pitCountdown');if(row.dataset.itemId==='queue'&&box)box.dataset.queued=input.checked?'1':'0';const q=$('#pitMarkQueued');if(q&&row.dataset.itemId==='queue'){q.classList.toggle('queued',input.checked);$('span',q).textContent=input.checked?'Clear Queued':'Mark Queued';}feedback('Checklist updated.');}catch(err){input.checked=!input.checked;updateChecklistCount();feedback(err.message,false);}});
  $('#pitChecklistItems')?.addEventListener('click',async e=>{const btn=e.target.closest('.pit-check-delete');if(!btn)return;e.preventDefault();const row=btn.closest('.pit-check-item');try{await post({action:'delete_check',item_id:row?.dataset.itemId||''});row?.remove();updateChecklistCount();feedback('Checklist item removed.');}catch(err){feedback(err.message,false);}});
  $('#pitAddCheck')?.addEventListener('click',async()=>{const input=$('#pitNewCheck');const label=input?.value.trim()||'';if(!label)return;try{const r=await post({action:'add_check',label});const list=r.state?.checklist||[];const item=list[list.length-1];if(item){const row=document.createElement('label');row.className='pit-check-item';row.dataset.itemId=item.id;row.innerHTML=`<input type="checkbox"><span></span><button type="button" class="btn secondary pit-check-delete" title="Remove custom item"><i class="fa-solid fa-xmark"></i></button>`;$('span',row).textContent=item.label;$('#pitChecklistItems')?.append(row);}if(input)input.value='';updateChecklistCount();feedback('Checklist item added.');}catch(err){feedback(err.message,false);}});
  $('#pitSaveMatch')?.addEventListener('click',async()=>{try{const lead=parseInt($('#pitQueueLead')?.value||'10',10)||10;const r=await post({action:'save_match',queue_lead_minutes:lead,match_note:$('#pitMatchNote')?.value||''});const box=$('#pitCountdown');if(box)box.dataset.queueMinutes=String(r.state?.queue_lead_minutes??lead);feedback(r.message||'Match setup saved.');updateCountdown();}catch(err){feedback(err.message,false);}});
  $('#pitMarkQueued')?.addEventListener('click',async()=>{const btn=$('#pitMarkQueued');const queued=!btn?.classList.contains('queued');try{const r=await post({action:'mark_queued',queued:queued?1:0});btn?.classList.toggle('queued',queued);const span=btn?$('span',btn):null;if(span)span.textContent=queued?'Clear Queued':'Mark Queued';const box=$('#pitCountdown');if(box)box.dataset.queued=queued?'1':'0';if(r.team_state?.robot_status)setStatusVisual(r.team_state.robot_status);const queueRow=$('.pit-check-item[data-item-id="queue"] input');if(queueRow){queueRow.checked=queued;updateChecklistCount();}feedback(r.message||'Queue status updated.');updateCountdown();}catch(err){feedback(err.message,false);}});

  // Fill missing persistent team logos in the background. Existing/custom logos are never overwritten.
  const logoTargets=$$('.pit-robot-logo[data-logo-team]').filter(el=>!$('img',el));let logoIdx=0,logoWorkers=0,logoStopped=false;
  async function fetchNextLogo(){if(logoStopped||logoIdx>=logoTargets.length)return;const el=logoTargets[logoIdx++],team=parseInt(el.dataset.logoTeam||'0',10);if(!team)return fetchNextLogo();logoWorkers++;try{const body=new FormData();body.set('csrf',cfg.csrf);body.set('event_id',String(cfg.eventId));body.set('team',String(team));body.set('action','auto_fetch_tba');const res=await fetch(cfg.logoApi,{method:'POST',body,credentials:'same-origin'});const json=await res.json();if(json.ok&&json.url){$$(`.pit-robot-logo[data-logo-team="${team}"]`).forEach(target=>{target.innerHTML='';const img=document.createElement('img');img.src=json.url;img.alt=`Team ${team} logo`;target.append(img);});}}catch(e){logoStopped=true;}finally{logoWorkers--;if(!logoStopped)fetchNextLogo();}}
  for(let i=0;i<Math.min(2,logoTargets.length);i++)fetchNextLogo();
})();
</script>
<?php if($tv):?><script>setInterval(()=>{if(!(window.neptuneRobotModalOpen&&window.neptuneRobotModalOpen()))location.reload()},30000);</script><?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php';
