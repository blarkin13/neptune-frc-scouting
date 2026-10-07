<?php
declare(strict_types=1);

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
require_once __DIR__.'/_augur_prediction_model.php';

$u=require_login();
$org=(int)$u['organization_id'];

function rst_table_exists(PDO $pdo,string $table): bool {
    $q=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
}
function rst_num($value,int $digits=1): string {
    return is_numeric($value)?number_format((float)$value,$digits):'—';
}
function rst_signed($value,int $digits=1): string {
    if(!is_numeric($value)) return '—';
    $v=(float)$value;
    return ($v>0?'+':'').number_format($v,$digits);
}
function rst_pct($value,int $digits=0): string {
    return is_numeric($value)?number_format((float)$value,$digits).'%':'—';
}
function rst_value_attr($value): string {
    return is_numeric($value)?e((string)(float)$value):'';
}
function rst_mean(array $values): ?float {
    $values=array_values(array_filter($values,'is_numeric'));
    return $values?array_sum(array_map('floatval',$values))/count($values):null;
}

$events=neptune_event_list($pdo,$org);
$eventId=(int)($_GET['event_id']??($events[0]['id']??0));
$event=$eventId?alliance_event($pdo,$org,$eventId):null;
$stats=$eventId?neptune_event_robot_stats($pdo,$org,$eventId):[];
$teams=array_values(array_map('intval',array_keys($stats)));

$predictionSettings=augur_prediction_settings();
if($event&&alliance_schema_ready($pdo)){
    try{
        $workspace=alliance_load_workspace($pdo,$org,$eventId,false);
        $predictionSettings=augur_prediction_settings($workspace['workspace']['settings']['matchup_model']??[]);
    }catch(Throwable $e){}
}

$epaMap=[];
if($event&&$teams){
    try{$epaMap=augur_epa_rating_map($pdo,$org,$event,$teams,null);}catch(Throwable $e){$epaMap=[];}
}

$traditional=[];
$depaRows=[];

if($eventId&&$teams){
    $m=$pdo->prepare("SELECT id,match_number FROM matches
        WHERE organization_id=? AND event_id=? AND comp_level='qm'
        ORDER BY match_number,id");
    $m->execute([$org,$eventId]);
    $qualMatches=[];
    foreach($m->fetchAll() as $row)$qualMatches[(int)$row['id']]=(int)$row['match_number'];

    $sessionRuns=[];
    $actionRuns=[];
    if($qualMatches){
        $ids=array_keys($qualMatches);
        $ph=implode(',',array_fill(0,count($ids),'?'));

        if(rst_table_exists($pdo,'scout_sessions')){
            $q=$pdo->prepare("SELECT match_id,match_run_number,frc_team_number
                FROM scout_sessions
                WHERE organization_id=? AND event_id=? AND match_id IN ($ph)
                GROUP BY match_id,match_run_number,frc_team_number");
            $q->execute(array_merge([$org,$eventId],$ids));
            foreach($q->fetchAll() as $row){
                $key=(int)$row['frc_team_number'].':'.(int)$row['match_id'];
                $sessionRuns[$key][(int)$row['match_run_number']]=true;
            }
        }

        $q=$pdo->prepare("SELECT sa.match_id,sa.match_run_number,sa.frc_team_number,
                COALESCE(SUM(sa.points),0) points,
                SUM(CASE WHEN sa.action_type='defense' AND sa.result='Success' THEN 1 ELSE 0 END) defense,
                SUM(CASE WHEN sa.action_type='offense' AND sa.result IN ('Success','Failure') THEN 1 ELSE 0 END) attempts,
                SUM(CASE WHEN sa.action_type='offense' AND sa.result='Success' THEN 1 ELSE 0 END) successes,
                COALESCE(SUM(CASE WHEN sa.phase='auton' THEN sa.points ELSE 0 END),0) auto_points,
                COALESCE(SUM(CASE WHEN sa.phase='teleop' THEN sa.points ELSE 0 END),0) teleop_points,
                COALESCE(SUM(CASE WHEN sa.phase='endgame' THEN sa.points ELSE 0 END),0) endgame_points
            FROM scouting_actions sa
            WHERE sa.organization_id=? AND sa.event_id=? AND sa.deleted_at IS NULL
              AND sa.match_id IN ($ph)
            GROUP BY sa.match_id,sa.match_run_number,sa.frc_team_number");
        $q->execute(array_merge([$org,$eventId],$ids));
        foreach($q->fetchAll() as $row){
            $team=(int)$row['frc_team_number'];
            $matchId=(int)$row['match_id'];
            $run=(int)$row['match_run_number'];
            $key=$team.':'.$matchId;
            $actionRuns[$key][$run]=[
                'points'=>(float)$row['points'],
                'defense'=>(int)$row['defense'],
                'attempts'=>(int)$row['attempts'],
                'successes'=>(int)$row['successes'],
                'auto_points'=>(float)$row['auto_points'],
                'teleop_points'=>(float)$row['teleop_points'],
                'endgame_points'=>(float)$row['endgame_points'],
            ];
        }

        $picked=augur_prediction_pick_active_run_observations($actionRuns,$sessionRuns);
        foreach($picked as $key=>$pick){
            [$teamText,$matchText]=explode(':',$key,2);
            $team=(int)$teamText;$matchId=(int)$matchText;$run=(int)$pick['run'];
            $src=$actionRuns[$key][$run]??[
                'points'=>0.0,'defense'=>0,'attempts'=>0,'successes'=>0,
                'auto_points'=>0.0,'teleop_points'=>0.0,'endgame_points'=>0.0
            ];
            $traditional[$team][]=[
                'match_id'=>$matchId,
                'match_number'=>$qualMatches[$matchId]??0,
                'points'=>(float)$src['points'],
                'defense'=>(int)$src['defense'],
                'attempts'=>(int)$src['attempts'],
                'successes'=>(int)$src['successes'],
                'auto_points'=>(float)$src['auto_points'],
                'teleop_points'=>(float)$src['teleop_points'],
                'endgame_points'=>(float)$src['endgame_points'],
            ];
        }
        foreach($traditional as &$rows)usort($rows,static fn($a,$b)=>$a['match_number']<=>$b['match_number']);
        unset($rows);
    }

    if(rst_table_exists($pdo,'augur_depa_event_ratings')){
        $q=$pdo->prepare("SELECT * FROM augur_depa_event_ratings
            WHERE organization_id=? AND event_id=?");
        $q->execute([$org,$eventId]);
        foreach($q->fetchAll() as $row)$depaRows[(int)$row['frc_team_number']]=$row;
    }
}

$rows=[];
foreach($stats as $team=>$base){
    $team=(int)$team;
    $publicEpa=isset($epaMap[$team]['epa'])&&is_numeric($epaMap[$team]['epa'])?(float)$epaMap[$team]['epa']:null;
    $obs=$traditional[$team]??[];
    $points=array_column($obs,'points');
    $blend=augur_prediction_offense_blend($points,$publicEpa,$predictionSettings);
    $attempts=array_sum(array_column($obs,'attempts'));
    $successes=array_sum(array_column($obs,'successes'));
    $d=$depaRows[$team]??[];

    $rows[]=[
        'team'=>$team,
        'nickname'=>(string)($base['nickname']??''),
        'pit'=>(string)($base['pit_status']??''),
        'matches'=>count($obs),
        'points_avg'=>$points?$blend['event_avg']:null,
        'recent'=>$points?$blend['recent_avg']:null,
        'high'=>$points?$blend['p75']:null,
        'auto_avg'=>$obs?rst_mean(array_column($obs,'auto_points')):null,
        'teleop_avg'=>$obs?rst_mean(array_column($obs,'teleop_points')):null,
        'endgame_avg'=>$obs?rst_mean(array_column($obs,'endgame_points')):null,
        'success_rate'=>$attempts?($successes/$attempts*100):null,
        'cycle_time'=>$base['cycle_time']??null,
        'public_epa'=>$publicEpa,
        'neptune_epa'=>($points||$publicEpa!==null)?$blend['augur_epa']:null,
        'defense_per_match'=>$obs?array_sum(array_column($obs,'defense'))/count($obs):null,
        'defense_actions'=>array_sum(array_column($obs,'defense')),
        'public_depa'=>$d['public_depa']??$d['macro_depa']??null,
        'defense_depa'=>$d['defense_depa']??$d['scouted_depa']??null,
        'neptune_depa'=>$d['neptune_depa']??null,
        'verified'=>(int)($d['verified_defense_matches']??0),
        'possible'=>(int)($d['possible_defense_matches']??0),
        'def_conf'=>$d['estimate_confidence_score']??$d['confidence_score']??null,
    ];
}

$pageTitle='Robot Stats Table';
$moduleName='AUGUR';
include dirname(__DIR__).'/partials_header.php';
?>
<style>
.rst-controls{display:flex;gap:12px;align-items:end;flex-wrap:wrap}
.rst-controls>label{min-width:240px}
.rst-table-wrap{overflow:auto;max-height:calc(100vh - 285px);border:1px solid var(--line);border-radius:12px;background:var(--panel)}
.rst-table{border-collapse:separate;border-spacing:0;width:100%;min-width:1550px;font-size:.78rem}
.rst-table th,.rst-table td{padding:8px 9px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);white-space:nowrap;text-align:right}
.rst-table th:last-child,.rst-table td:last-child{border-right:0}
.rst-table thead th{position:sticky;top:0;z-index:4;background:var(--panel2);font-size:.68rem;text-transform:uppercase;letter-spacing:.035em}
.rst-table thead tr:nth-child(2) th{top:34px}
.rst-table .rst-group{text-align:center;font-size:.66rem;letter-spacing:.08em}
.rst-table .rst-team,.rst-table .rst-name{text-align:left;position:sticky;z-index:3;background:var(--panel)}
.rst-table .rst-team{left:0;font-weight:950}.rst-table .rst-name{left:70px;max-width:180px;overflow:hidden;text-overflow:ellipsis}
.rst-table thead .rst-team,.rst-table thead .rst-name{z-index:6;background:var(--panel2)}
.rst-table tbody tr:hover td{background:color-mix(in srgb,var(--accent) 6%,var(--panel))}
.rst-table tbody tr:hover .rst-team,.rst-table tbody tr:hover .rst-name{background:color-mix(in srgb,var(--accent) 6%,var(--panel))}
.rst-sort{cursor:pointer;user-select:none}.rst-sort:hover{color:var(--text)}
.rst-sort i{font-size:.58rem;margin-left:3px;opacity:.65}
.rst-good{font-weight:900}.rst-details{padding:5px 8px!important;font-size:.68rem!important}
.rst-verified-btn{border:0;background:transparent;color:var(--accent);font:inherit;font-weight:950;padding:2px 5px;border-radius:6px;cursor:pointer;text-decoration:underline;text-underline-offset:2px}
.rst-verified-btn:hover{background:color-mix(in srgb,var(--accent) 10%,transparent)}
.rst-verified-btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.rst-verified-zero{color:var(--muted)}
.rst-defense-dialog{width:min(980px,calc(100vw - 28px));max-height:min(82vh,850px);padding:0;border:1px solid var(--line);border-radius:14px;background:var(--panel);color:var(--text);box-shadow:0 22px 70px rgba(0,0,0,.45)}
.rst-defense-dialog::backdrop{background:rgba(3,8,16,.76)}
.rst-defense-shell{display:flex;flex-direction:column;max-height:min(82vh,850px)}
.rst-defense-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;padding:16px 18px;border-bottom:1px solid var(--line);background:var(--panel2)}
.rst-defense-head h2{margin:2px 0 2px;font-size:1.25rem}.rst-defense-head p{margin:0;color:var(--muted);font-size:.78rem}
.rst-defense-close{padding:7px 9px!important}
.rst-defense-body{padding:16px 18px;overflow:auto}
.rst-defense-summary{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.rst-defense-note{margin-bottom:12px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--panel2);font-size:.76rem;color:var(--muted);line-height:1.45}
.rst-defense-note b{color:var(--text)}
.rst-defense-match-list{display:grid;gap:10px}
.rst-defense-match{border:1px solid var(--line);border-radius:10px;background:var(--panel2);padding:11px}
.rst-defense-match-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.rst-defense-match-head h3{margin:0;font-size:.95rem}.rst-defense-match-head small{color:var(--muted)}
.rst-defense-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;margin-top:9px}
.rst-defense-stat{border:1px solid var(--line);border-radius:8px;background:var(--panel);padding:8px;min-width:0}
.rst-defense-stat span{display:block;color:var(--muted);font-size:.62rem;text-transform:uppercase;letter-spacing:.035em;font-weight:850}
.rst-defense-stat b{display:block;margin-top:3px;font-size:.9rem}
.rst-defense-evidence{display:flex;gap:7px;flex-wrap:wrap;margin-top:9px}
.rst-defense-video{margin-left:auto;white-space:nowrap}
.rst-defense-loading,.rst-defense-empty{padding:20px;border:1px dashed var(--line);border-radius:9px;color:var(--muted);text-align:center}
@media(max-width:760px){.rst-defense-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.rst-defense-match-head{align-items:center}.rst-defense-video{margin-left:0}}
.rst-count{margin-left:auto;align-self:center}
@media(max-width:760px){.rst-controls>label,.rst-controls>div[style*="min-width"]{min-width:100%!important}.rst-table-wrap{max-height:none}}
</style>

<section class="module-page">
  <header class="module-page-header">
    <div>
      <div class="module-code">AUGUR · EVENT ANALYTICS</div>
      <h1>Robot Stats Table</h1>
      <p>Compare every event robot's offense, defense, EPA and scouting coverage in one table.</p>
    </div>
    <a class="btn secondary" href="<?=e(base_url('analytics/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Analytics &amp; Strategy</a>
  </header>

  <div class="card">
    <div class="rst-controls">
      <form method="get" class="rst-controls" style="flex:1">
        <div style="min-width:340px">
          <label>Event</label>
          <select name="event_id" onchange="this.form.submit()"><?=neptune_event_options_html($events,$eventId)?></select>
        </div>
        <div style="align-self:end"><?=neptune_history_toggle_html(neptune_selector_show_history(),'events')?></div>
        <label>Find robot
          <input type="search" id="rstSearch" placeholder="Team number or name">
        </label>
      </form>
      <span class="pill rst-count" id="rstCount"><?=count($rows)?> robots</span>
    </div>
  </div>

  <div class="card" style="margin-top:14px;padding:0">
    <div class="rst-table-wrap">
      <table class="rst-table" id="rstTable">
        <thead>
          <tr>
            <th colspan="3" class="rst-group">Robot</th>
            <th colspan="11" class="rst-group">Offense</th>
            <th colspan="7" class="rst-group">Defense</th>
            <th rowspan="2">Details</th>
          </tr>
          <tr>
            <th class="rst-team rst-sort">Team <i class="fa-solid fa-sort"></i></th>
            <th class="rst-name rst-sort">Name <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Pit <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Qual Data <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Pts/Match <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Recent <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">High (P75) <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Auto/Match <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Teleop/Match <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Endgame/Match <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Off. Success <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Cycle <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Public EPA <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Nep. EPA <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Defense/Match <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Defense Actions <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Public D-EPA <i class="fa-solid fa-sort"></i></th>
                        <th class="rst-sort">Nep. D-EPA <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Verified <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Possible <i class="fa-solid fa-sort"></i></th>
            <th class="rst-sort">Def. Conf. <i class="fa-solid fa-sort"></i></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r):?>
          <tr data-search="<?=e(strtolower('#'.$r['team'].' '.$r['nickname']))?>">
            <td class="rst-team" data-value="<?=$r['team']?>">#<?=e($r['team'])?></td>
            <td class="rst-name" data-value="<?=e(strtolower($r['nickname']))?>" title="<?=e($r['nickname'])?>"><?=e($r['nickname']?:'FRC Team '.$r['team'])?></td>
            <td data-value="<?=e($r['pit'])?>"><?=e($r['pit']?:'—')?></td>
            <td data-value="<?=$r['matches']?>"><?=$r['matches']?></td>
            <td class="rst-good" data-value="<?=rst_value_attr($r['points_avg'])?>"><?=rst_num($r['points_avg'],1)?></td>
            <td data-value="<?=rst_value_attr($r['recent'])?>"><?=rst_num($r['recent'],1)?></td>
            <td data-value="<?=rst_value_attr($r['high'])?>"><?=rst_num($r['high'],1)?></td>
            <td data-value="<?=rst_value_attr($r['auto_avg'])?>"><?=rst_num($r['auto_avg'],1)?></td>
            <td data-value="<?=rst_value_attr($r['teleop_avg'])?>"><?=rst_num($r['teleop_avg'],1)?></td>
            <td data-value="<?=rst_value_attr($r['endgame_avg'])?>"><?=rst_num($r['endgame_avg'],1)?></td>
            <td data-value="<?=rst_value_attr($r['success_rate'])?>"><?=rst_pct($r['success_rate'],0)?></td>
            <td data-value="<?=rst_value_attr($r['cycle_time'])?>"><?=$r['cycle_time']!==null?rst_num($r['cycle_time'],1).'s':'—'?></td>
            <td data-value="<?=rst_value_attr($r['public_epa'])?>"><?=rst_num($r['public_epa'],1)?></td>
            <td class="rst-good" data-value="<?=rst_value_attr($r['neptune_epa'])?>"><?=rst_num($r['neptune_epa'],1)?></td>
            <td data-value="<?=rst_value_attr($r['defense_per_match'])?>"><?=rst_num($r['defense_per_match'],1)?></td>
            <td data-value="<?=$r['defense_actions']?>"><?=$r['defense_actions']?></td>
            <td data-value="<?=rst_value_attr($r['public_depa'])?>"><?=rst_signed($r['public_depa'],1)?></td>
            <td class="rst-good" data-value="<?=rst_value_attr($r['neptune_depa'])?>"><?=rst_signed($r['neptune_depa'],1)?></td>
            <td data-value="<?=$r['verified']?>">
              <?php if($r['verified']>0):?>
                <button type="button" class="rst-verified-btn" data-defense-team="<?=$r['team']?>" data-defense-event="<?=$eventId?>" title="Open Verified defense matches"><?=$r['verified']?></button>
              <?php else:?><span class="rst-verified-zero">0</span><?php endif;?>
            </td>
            <td data-value="<?=$r['possible']?>"><?=$r['possible']?></td>
            <td data-value="<?=rst_value_attr($r['def_conf'])?>"><?=rst_pct($r['def_conf'],0)?></td>
            <td><button type="button" class="secondary rst-details robot-detail-trigger" data-event-id="<?=$eventId?>" data-team="<?=$r['team']?>"><i class="fa-solid fa-up-right-and-down-left-from-center"></i> Open</button></td>
          </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>
</section>


<dialog id="rstDefenseDialog" class="rst-defense-dialog" aria-labelledby="rstDefenseTitle">
  <div class="rst-defense-shell">
    <div class="rst-defense-head">
      <div>
        <div class="module-eyebrow"><span>AUGUR</span><small>Verified Defense</small></div>
        <h2 id="rstDefenseTitle">Verified Defense Matches</h2>
        <p id="rstDefenseSubtitle">Loading match evidence…</p>
      </div>
      <button type="button" class="secondary rst-defense-close" id="rstDefenseClose" aria-label="Close verified defense matches"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="rst-defense-body" id="rstDefenseBody">
      <div class="rst-defense-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading defense evidence…</div>
    </div>
  </div>
</dialog>

<script>
(()=>{
  const table=document.getElementById('rstTable');
  const search=document.getElementById('rstSearch');
  const count=document.getElementById('rstCount');
  if(!table)return;
  const body=table.tBodies[0];
  const headers=[...table.querySelectorAll('thead tr:last-child th.rst-sort')];
  let direction={};

  function visibleRows(){return [...body.rows].filter(r=>!r.hidden)}
  function refreshCount(){if(count)count.textContent=visibleRows().length+' robots'}

  search?.addEventListener('input',()=>{
    const q=search.value.trim().toLowerCase();
    [...body.rows].forEach(row=>row.hidden=q!==''&&!String(row.dataset.search||'').includes(q));
    refreshCount();
  });

  headers.forEach((th,index)=>{
    th.addEventListener('click',()=>{
      direction[index]=direction[index]!=='desc'?'desc':'asc';
      const desc=direction[index]==='desc';
      const rows=[...body.rows];
      rows.sort((a,b)=>{
        const av=a.cells[index]?.dataset.value??a.cells[index]?.textContent?.trim()??'';
        const bv=b.cells[index]?.dataset.value??b.cells[index]?.textContent?.trim()??'';
        const an=Number(av),bn=Number(bv);
        let cmp;
        if(av!==''&&bv!==''&&Number.isFinite(an)&&Number.isFinite(bn))cmp=an-bn;
        else if(av===''&&bv!=='')cmp=-1;
        else if(av!==''&&bv==='')cmp=1;
        else cmp=String(av).localeCompare(String(bv),undefined,{numeric:true,sensitivity:'base'});
        return desc?-cmp:cmp;
      });
      rows.forEach(r=>body.appendChild(r));
    });
  });


  const defenseDialog=document.getElementById('rstDefenseDialog');
  const defenseBody=document.getElementById('rstDefenseBody');
  const defenseTitle=document.getElementById('rstDefenseTitle');
  const defenseSubtitle=document.getElementById('rstDefenseSubtitle');
  const defenseClose=document.getElementById('rstDefenseClose');
  const defenseEndpoint=<?=json_encode(base_url('api/verified-defense-matches.php'))?>;
  const videoEndpoint=<?=json_encode(base_url('api/tba-match-videos.php'))?>;
  let defenseController=null;

  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const signed=value=>{
    const n=Number(value);
    if(!Number.isFinite(n))return '—';
    return (n>0?'+':'')+n.toFixed(1);
  };
  const number1=value=>{
    const n=Number(value);
    return Number.isFinite(n)?n.toFixed(1):'—';
  };

  function closeDefense(){
    if(defenseController)defenseController.abort();
    if(defenseDialog?.open)defenseDialog.close();
  }
  defenseClose?.addEventListener('click',closeDefense);
  defenseDialog?.addEventListener('click',event=>{if(event.target===defenseDialog)closeDefense();});
  defenseDialog?.addEventListener('close',()=>{if(defenseController)defenseController.abort();});

  async function hydrateDefenseVideos(matches,signal){
    const keys=[...new Set(matches.map(m=>m.tba_match_key).filter(Boolean))];
    if(!keys.length)return {};
    const out={};
    for(let i=0;i<keys.length;i+=30){
      const batch=keys.slice(i,i+30);
      try{
        const response=await fetch(videoEndpoint+'?matches='+encodeURIComponent(batch.join(',')),{
          credentials:'same-origin',signal,headers:{'X-Requested-With':'XMLHttpRequest'}
        });
        if(!response.ok)continue;
        const payload=await response.json();
        for(const key of batch)out[key]=payload?.matches?.[key]?.youtube_url||'';
      }catch(error){if(error?.name==='AbortError')throw error;}
    }
    return out;
  }

  function renderDefenseMatches(payload,videos){
    const matches=Array.isArray(payload.matches)?payload.matches:[];
    defenseTitle.textContent='#'+payload.team+' Verified Defense';
    defenseSubtitle.textContent=(payload.nickname||('FRC Team '+payload.team))+' · '+(payload.event_name||'Event');

    if(!matches.length){
      defenseBody.innerHTML='<div class="rst-defense-empty">No Verified defense matches are currently stored for this robot.</div>';
      return;
    }

    const cards=matches.map(m=>{
      const evidence=[];
      const actions=Number(m.defense_actions||0);
      const great=Number(m.spot_great_defense||0);
      const weak=Number(m.spot_weak_defense||0);
      if(actions)evidence.push('<span class="pill"><i class="fa-solid fa-shield-halved"></i> '+actions+' defense action'+(actions===1?'':'s')+'</span>');
      if(great)evidence.push('<span class="pill"><i class="fa-solid fa-binoculars"></i> '+great+' Great Defense Spot tag'+(great===1?'':'s')+'</span>');
      if(weak)evidence.push('<span class="pill"><i class="fa-solid fa-eye"></i> '+weak+' Weak Defense Spot tag'+(weak===1?'':'s')+'</span>');
      const video=videos[m.tba_match_key]||'';
      const videoButton=video
        ?'<a class="btn secondary rst-defense-video" href="'+esc(video)+'" target="_blank" rel="noopener"><i class="fa-brands fa-youtube"></i> Watch Video</a>'
        :'<span class="pill rst-defense-video"><i class="fa-solid fa-video-slash"></i> No video</span>';

      return '<article class="rst-defense-match">'+
        '<div class="rst-defense-match-head"><div><h3>'+esc(m.match_label)+'</h3><small>'+esc(m.alliance)+' alliance'+(m.final_score?' · Final '+esc(m.final_score):'')+'</small></div>'+videoButton+'</div>'+
        '<div class="rst-defense-grid">'+
          '<div class="rst-defense-stat"><span>Raw D-EPA</span><b>'+signed(m.raw_depa)+'</b></div>'+
          '<div class="rst-defense-stat"><span>Expected Opp.</span><b>'+number1(m.expected_opponent_score)+'</b></div>'+
          '<div class="rst-defense-stat"><span>Actual Opp.</span><b>'+number1(m.actual_opponent_score)+'</b></div>'+
          '<div class="rst-defense-stat"><span>Evidence Score</span><b>'+number1(m.evidence_score)+'</b></div>'+
          '<div class="rst-defense-stat"><span>Status</span><b>Verified</b></div>'+
        '</div>'+
        '<div class="rst-defense-evidence">'+(evidence.join('')||'<span class="pill">Verified evidence</span>')+'</div>'+
      '</article>';
    }).join('');

    defenseBody.innerHTML=
      '<div class="rst-defense-summary"><span class="pill"><i class="fa-solid fa-shield"></i> '+matches.length+' Verified match'+(matches.length===1?'':'es')+'</span>'+
      '<span class="pill">Nep. D-EPA '+signed(payload.nep_depa)+'</span></div>'+
      '<div class="rst-defense-note"><b>Verified</b> means Neptune observed at least two successful defense actions, or independent Tag Scouting defense evidence. Raw D-EPA is the opponent scoring suppression for that match: positive means the opponent scored below its expected baseline; negative means it scored above it.</div>'+
      '<div class="rst-defense-match-list">'+cards+'</div>';
  }

  async function openDefense(team,eventId){
    if(!defenseDialog||!defenseBody)return;
    if(defenseController)defenseController.abort();
    defenseController=new AbortController();
    defenseTitle.textContent='#'+team+' Verified Defense';
    defenseSubtitle.textContent='Loading match evidence…';
    defenseBody.innerHTML='<div class="rst-defense-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading defense evidence…</div>';
    if(!defenseDialog.open)defenseDialog.showModal();

    try{
      const query='?event_id='+encodeURIComponent(eventId)+'&team='+encodeURIComponent(team);
      const response=await fetch(defenseEndpoint+query,{
        credentials:'same-origin',
        signal:defenseController.signal,
        headers:{'X-Requested-With':'XMLHttpRequest'}
      });
      const payload=await response.json().catch(()=>({}));
      if(!response.ok||!payload.ok)throw new Error(payload.message||'Could not load Verified defense matches.');
      const videos=await hydrateDefenseVideos(payload.matches||[],defenseController.signal);
      renderDefenseMatches(payload,videos);
    }catch(error){
      if(error?.name==='AbortError')return;
      defenseBody.innerHTML='<div class="notice bad">'+esc(error?.message||'Could not load Verified defense matches.')+'</div>';
    }
  }

  document.addEventListener('click',event=>{
    const button=event.target.closest('.rst-verified-btn');
    if(!button)return;
    event.preventDefault();
    event.stopPropagation();
    openDefense(button.dataset.defenseTeam,button.dataset.defenseEvent);
  });
})();
</script>
<?php include __DIR__.'/_robot_modal.php';?>
<?php include dirname(__DIR__).'/partials_footer.php';?>
