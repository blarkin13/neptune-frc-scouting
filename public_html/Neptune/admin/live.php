<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];
$requestedMid=(int)($_GET['match_id']??0);
$mid=$requestedMid;
$loadError='';

$choiceStmt=$pdo->prepare("SELECT m.*,e.name event_name,e.is_current,g.name game_name
  FROM matches m
  JOIN events e ON e.id=m.event_id
  JOIN games g ON g.id=m.game_id
  WHERE m.organization_id=?
  ORDER BY e.is_current DESC,
           FIELD(m.state,'running','paused','ready','scheduled','ended'),
           CASE m.comp_level WHEN 'qm' THEN 1 WHEN 'ef' THEN 2 WHEN 'qf' THEN 3 WHEN 'sf' THEN 4 WHEN 'f' THEN 5 ELSE 6 END,
           m.set_number,m.match_number,m.id DESC
  LIMIT 250");
$choiceStmt->execute([$org]);
$matchChoices=$choiceStmt->fetchAll();

if($mid<=0 && $matchChoices){
    foreach(['running','paused','ready','scheduled','ended'] as $preferredState){
        foreach($matchChoices as $choice){
            if((string)$choice['state']===$preferredState){$mid=(int)$choice['id'];break 2;}
        }
    }
}

$match=null;$matchTeams=[];$buttons=[];$timing=['duration'=>150,'auton'=>15];
if($mid>0){
    $s=$pdo->prepare('SELECT m.*,e.name event_name,g.name game_name,gr.match_config_json config_json,gr.revision_number game_revision_number FROM matches m JOIN events e ON e.id=m.event_id JOIN games g ON g.id=m.game_id JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=m.game_id WHERE m.id=? AND m.organization_id=?');
    $s->execute([$mid,$org]);
    $match=$s->fetch()?:null;
    if(!$match){
        $loadError='That match could not be found for your organization.';
        $mid=0;
    }
}

if($match){
    $s=$pdo->prepare('SELECT alliance,station,frc_team_number FROM match_teams WHERE match_id=? ORDER BY FIELD(alliance,\'Red\',\'Blue\'),station');
    $s->execute([$mid]);
    $matchTeams=$s->fetchAll();
    $cfg=json_decode($match['config_json'],true)?:['buttons'=>[]];
    $timing=game_timing($cfg);
    $buttons=array_values(array_filter($cfg['buttons']??[],fn($b)=>!empty($b['code'])));
}


$nextMatchId=0;
if($match){
    $s=$pdo->prepare("SELECT id
      FROM matches
      WHERE organization_id=?
        AND event_id=?
        AND id<>?
        AND state IN ('scheduled','ready')
      ORDER BY FIELD(comp_level,'qm','ef','qf','sf','f','legacy'),set_number,match_number,id
      LIMIT 1");
    $s->execute([$org,(int)$match['event_id'],$mid]);
    $nextMatchId=(int)($s->fetchColumn()?:0);
}

$pageTitle='Live Monitor';
$moduleName='SATURN';
include dirname(__DIR__).'/partials_header.php';
?>
<section class="module-page">
  <header class="module-page-header" style="margin-bottom:16px">
    <div>
      <h1>Live Monitor</h1>
      <p>Watch scouting live and control the active match without leaving this screen.</p>
    </div>
    <a class="btn secondary" href="<?=e(base_url('admin/match-control.php'.($match?'?event_id='.(int)$match['event_id'].'#match-'.$mid:'')))?>"><i class="fa-solid fa-circle-play"></i> Match Control</a>
  </header>

  <div class="card">
    <form method="get" class="live-monitor-picker">
      <div>
        <label style="margin-top:0">Match</label>
        <select name="match_id" <?=$matchChoices?'':'disabled'?>>
          <?php if(!$matchChoices):?><option>No matches loaded</option><?php endif;?>
          <?php foreach($matchChoices as $choice):?>
            <option value="<?=e($choice['id'])?>" <?=$mid===(int)$choice['id']?'selected':''?>><?=e(($choice['is_current']?'★ ':'').$choice['event_name'].' · '.neptune_match_label($choice).' · '.strtoupper((string)$choice['state']))?></option>
          <?php endforeach;?>
        </select>
      </div>
      <button type="submit" <?=$matchChoices?'':'disabled'?>><i class="fa-solid fa-eye"></i> Load Match</button>
      <a class="btn secondary" href="<?=e(base_url('admin/tba-sync.php'))?>"><i class="fa-solid fa-rotate"></i> TBA Sync</a>
    </form>
  </div>

  <?php if($loadError):?><div class="notice bad" style="margin-top:16px"><?=e($loadError)?></div><?php endif;?>

  <?php if(!$match):?>
    <div class="card live-monitor-empty" style="margin-top:16px">
      <div>
        <span class="empty-icon"><i class="fa-solid fa-satellite-dish"></i></span>
        <h2>No match available to monitor</h2>
        <p class="muted">Load an event schedule from TBA, or open Match Control and make a match ready.</p>
        <a class="btn" href="<?=e(base_url('admin/match-control.php'))?>"><i class="fa-solid fa-arrow-right"></i> Open Match Control</a>
      </div>
    </div>
  <?php else:?>
    <div class="toolbar" style="justify-content:space-between;margin-top:16px">
      <div><h2 style="margin:0"><?=e(neptune_match_label($match))?></h2><div class="muted"><?=e($match['event_name'])?> · Current scouting run <?=e($match['run_number']??1)?></div></div>
      <button class="secondary" id="showAllActions" type="button"><i class="fa-solid fa-list"></i> Show All Actions</button>
    </div>

    <div class="live-control-dock" id="liveControlDock" data-state="<?=e((string)$match['state'])?>">
      <div class="live-control-identity">
        <span class="live-control-kicker"><i class="fa-solid fa-tower-broadcast"></i> LIVE MATCH CONTROL</span>
        <strong><?=e(neptune_match_label($match))?></strong>
        <span class="muted"><?=e($match['event_name'])?> · Run <?=e($match['run_number']??1)?></span>
      </div>
      <div class="live-control-status">
        <span class="live-state-pill" id="liveStatePill"><?=e(strtoupper((string)$match['state']))?></span>
        <strong class="live-clock" id="liveClock">--:--</strong>
      </div>
      <div class="live-control-actions" id="liveControlActions"></div>
    </div>

    <div id="state" class="notice">Loading…</div>
    <div class="grid" id="scouts" style="margin-top:16px"></div>
    <div class="card" style="margin-top:16px">
      <div class="toolbar" style="justify-content:space-between"><h2 style="margin:0">Latest actions</h2><span class="muted">Current run only</span></div>
      <div class="table-wrap"><table class="table"><thead><tr><th>Time</th><th>Robot</th><th>Scout</th><th>Action</th><th>Result</th><th>Pts</th><th></th></tr></thead><tbody id="actions"></tbody></table></div>
    </div>

    <div class="card" style="margin-top:16px">
      <h2>Add / Correct Action</h2>
      <div class="grid">
        <div><label>Robot / station</label><select id="astation">
          <?php foreach($matchTeams as $t):?><option value="<?=e($t['alliance'].'-'.$t['station'])?>"><?=e($t['alliance'].' '.$t['station'].' · #'.$t['frc_team_number'])?></option><?php endforeach;?>
        </select></div>
        <div><label>Action</label><select id="aaction">
          <?php foreach($buttons as $b):?><option value="<?=e($b['code'])?>" data-name="<?=e($b['name']??$b['code'])?>" data-type="<?=e($b['type']??'')?>" data-location="<?=e($b['location']??'')?>" data-auton="<?=e($b['autonPoints']??0)?>" data-teleop="<?=e($b['teleopPoints']??0)?>"><?=e($b['name']??$b['code'])?></option><?php endforeach;?>
        </select></div>
        <div><label>Match second</label><input id="asec" type="number" min="0" max="<?=$timing['duration']?>" value="<?=$timing['duration']?>"></div>
        <div><label>Result</label><select id="aresult"><option>Success</option><option>Failure</option><option>Neutral</option></select></div>
        <div><label>Points</label><input id="apoints" type="number" step="0.1" value="0"></div>
      </div>
      <div class="toolbar"><button id="addAction" <?=(!$matchTeams||!$buttons)?'disabled':''?>><i class="fa-solid fa-plus"></i> Add action</button></div>
      <?php if(!$matchTeams):?><div class="notice">No imported robot stations are available for this match. Refresh its TBA schedule first.</div><?php endif;?>
    </div>

    <dialog class="action-manager-dialog" id="allActionsDialog">
      <div class="action-manager-shell">
        <div class="action-manager-toolbar">
          <div><b><?=e(neptune_match_label($match))?> · All Actions</b><div class="muted">Run <?=e($match['run_number']??1)?> · deleted actions are removed from analytics immediately</div></div>
          <button class="secondary" id="closeAllActions" type="button" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="action-manager-filters"><input id="actionSearch" type="search" placeholder="Filter robot, scout, action, result…"><span class="pill" id="allActionCount">0 actions</span></div>
        <div class="table-wrap action-manager-table"><table class="table"><thead><tr><th>Time</th><th>Robot</th><th>Scout</th><th>Action</th><th>Result</th><th>Pts</th><th>Source</th><th></th></tr></thead><tbody id="allActionsBody"></tbody></table></div>
      </div>
    </dialog>

<style>
.live-control-dock{
  position:sticky;
  top:82px;
  z-index:24;
  display:grid;
  grid-template-columns:minmax(220px,1fr) auto auto;
  align-items:center;
  gap:16px;
  margin:14px 0 12px;
  padding:13px 14px;
  border:1px solid var(--line);
  border-radius:12px;
  background:color-mix(in srgb,var(--panel) 94%,transparent);
  box-shadow:0 14px 34px var(--shadow);
  backdrop-filter:blur(16px);
  -webkit-backdrop-filter:blur(16px);
}
.live-control-identity{display:grid;gap:2px;min-width:0}
.live-control-identity strong{font-size:1.06rem}
.live-control-kicker{font-size:.68rem;font-weight:900;letter-spacing:.12em;color:var(--muted)}
.live-control-status{display:flex;align-items:center;gap:12px}
.live-state-pill{display:inline-flex;align-items:center;justify-content:center;min-width:92px;padding:8px 11px;border:1px solid var(--line);border-radius:999px;background:var(--panel2);font-size:.72rem;font-weight:900;letter-spacing:.08em}
.live-clock{min-width:74px;font:900 1.65rem/1 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;letter-spacing:-.05em;text-align:right}
.live-control-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.live-control-actions .btn,.live-control-actions button{white-space:nowrap}
.live-control-dock[data-state="running"]{border-color:color-mix(in srgb,var(--good) 68%,var(--line))}
.live-control-dock[data-state="paused"]{border-color:color-mix(in srgb,var(--warn) 72%,var(--line))}
.live-control-dock[data-state="ready"]{border-color:color-mix(in srgb,var(--ready) 72%,var(--line))}
.live-control-dock[data-state="ended"]{opacity:.94}
@media(max-width:900px){
  .live-control-dock{top:68px;grid-template-columns:1fr auto}
  .live-control-actions{grid-column:1/-1;justify-content:flex-start}
}
@media(max-width:620px){
  .live-control-dock{top:62px;display:flex;align-items:stretch;flex-direction:column;gap:10px}
  .live-control-status{justify-content:space-between}
  .live-clock{text-align:right}
  .live-control-actions{display:grid;grid-template-columns:1fr 1fr}
  .live-control-actions .btn,.live-control-actions button{width:100%;justify-content:center}
}
</style>

<script>
const mid=<?=$mid?>,
      EVENT_ID=<?=json_encode((int)$match['event_id'])?>,
      NEXT_MATCH_ID=<?=json_encode($nextMatchId)?>,
      MATCH_DURATION=<?=json_encode((int)$timing['duration'])?>,
      MATCH_AUTON=<?=json_encode((int)$timing['auton'])?>,
      MATCH_TRANSITION=<?=json_encode((int)$timing['transition'])?>,
      CSRF=<?=json_encode(csrf_token())?>,
      MATCH_CONTROL_URL=<?=json_encode(base_url('admin/match-control.php'))?>,
      LIVE_URL=<?=json_encode(base_url('admin/live.php'))?>;

let allActionRows=[],lastAllRefresh=0,allLoading=false,currentMatchState=<?=json_encode((string)$match['state'])?>,controlBusy=false;
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));

function actionRow(a,full=false){
  const resultClass=String(a.result||'').toLowerCase();
  return `<tr data-action-id="${Number(a.id)}" data-search="${esc([a.frc_team_number,a.scout_name,a.action_name||a.action_code,a.result,a.source].join(' ').toLowerCase())}"><td>${esc(a.match_time_sec??'')}</td><td>#${esc(a.frc_team_number)}</td><td>${esc(a.scout_name||'Admin')}</td><td>${esc(a.action_name||a.action_code)}</td><td><span class="action-result ${esc(resultClass)}">${esc(a.result)}</span></td><td>${esc(a.points)}</td>${full?`<td>${esc(a.source||'')}</td>`:''}<td><button class="danger action-delete" type="button" data-action-id="${Number(a.id)}" title="Delete action" aria-label="Delete ${esc(a.action_name||a.action_code)}"><i class="fa-solid fa-trash"></i></button></td></tr>`;
}

function suggestedPoints(){
  const opt=aaction.options[aaction.selectedIndex];
  if(!opt)return;
  const sec=Number(asec.value||0),result=aresult.value;
  const auto=sec<<?=json_encode($timing['auton'])?>;
  apoints.value=result==='Success'?Number(auto?opt.dataset.auton:opt.dataset.teleop):0;
}

aaction.addEventListener('change',suggestedPoints);
asec.addEventListener('input',suggestedPoints);
aresult.addEventListener('change',suggestedPoints);
suggestedPoints();

function parseUtc(value){
  if(!value)return NaN;
  const raw=String(value).trim();
  return Date.parse(/[zZ]|[+-]\d\d:\d\d$/.test(raw)?raw:raw.replace(' ','T')+'Z');
}

function updateClock(match){
  if(!match){liveClock.textContent='--:--';return;}
  const state=String(match.state||'scheduled');
  if(!['running','paused','ended'].includes(state) || !match.started_at){
    liveClock.textContent=state==='ready'?'READY':'--:--';
    return;
  }

  const started=parseUtc(match.started_at);
  if(!Number.isFinite(started)){liveClock.textContent='--:--';return;}

  let endpoint=Date.now();
  if(state==='paused'&&match.paused_at){
    const paused=parseUtc(match.paused_at);
    if(Number.isFinite(paused))endpoint=paused;
  }else if(state==='ended'&&match.ended_at){
    const ended=parseUtc(match.ended_at);
    if(Number.isFinite(ended))endpoint=ended;
  }

  const pauseMs=Math.max(0,Number(match.total_pause_seconds||0))*1000;
  const physicalElapsed=Math.max(0,(endpoint-started-pauseMs)/1000);

  // Mirror api/match-state.php exactly. The Auto -> Teleop transition consumes
  // wall-clock time, but the official game clock freezes at the end of Auto.
  let gameElapsed;
  if(physicalElapsed<MATCH_AUTON){
    gameElapsed=physicalElapsed;
  }else if(physicalElapsed<(MATCH_AUTON+MATCH_TRANSITION)){
    gameElapsed=MATCH_AUTON;
  }else{
    gameElapsed=Math.min(MATCH_DURATION,Math.max(0,physicalElapsed-MATCH_TRANSITION));
  }

  const remaining=Math.max(0,MATCH_DURATION-gameElapsed);
  const whole=Math.ceil(remaining);
  const mins=Math.floor(whole/60);
  const secs=whole%60;
  liveClock.textContent=`${String(mins).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;
}

function controlButtonsFor(state){
  const disabled=controlBusy?' disabled':'';
  const action=(op,label,icon,klass='')=>`<button type="button" class="${klass}" data-match-op="${op}"${disabled}><i class="fa-solid ${icon}"></i> ${label}</button>`;
  const link=(href,label,icon,klass='btn secondary')=>`<a class="${klass}" href="${esc(href)}"><i class="fa-solid ${icon}"></i> ${label}</a>`;

  if(state==='scheduled'){
    return action('ready','Make Ready','fa-circle-check');
  }
  if(state==='ready'){
    return action('start','Start Match','fa-play','good');
  }
  if(state==='running'){
    return action('pause','Pause','fa-pause','secondary')+action('end','End Match','fa-stop','danger');
  }
  if(state==='paused'){
    return action('resume','Resume','fa-play','good')+action('end','End Match','fa-stop','danger');
  }
  if(state==='ended'){
    let html='';
    if(NEXT_MATCH_ID>0){
      html+=link(`${LIVE_URL}?match_id=${encodeURIComponent(NEXT_MATCH_ID)}`,'Next Match','fa-forward-step','btn good');
    }
    html+=action('ready','Re-scout','fa-rotate-right','secondary');
    return html;
  }
  return '';
}

function renderControls(match){
  const stateName=String(match&&match.state||currentMatchState||'scheduled').toLowerCase();
  currentMatchState=stateName;
  liveControlDock.dataset.state=stateName;
  liveStatePill.textContent=stateName.toUpperCase();
  liveControlActions.innerHTML=controlButtonsFor(stateName);
  updateClock(match);
}

async function runMatchControl(op){
  if(controlBusy)return;

  if(op==='ready'&&currentMatchState==='ended'){
    const ok=window.NeptuneUI&&typeof NeptuneUI.confirm==='function'
      ? await NeptuneUI.confirm(
          'Re-scout this match?\n\nAll scouting actions from the current run will be voided and removed from analytics. A clean new run will start at 0 actions / 0 points.',
          {title:'Start a clean scouting run',confirmText:'Re-scout match',danger:true}
        )
      : window.confirm('Re-scout this match? The current scouting run will be voided.');
    if(!ok)return;
  }

  controlBusy=true;
  renderControls({state:currentMatchState});

  const body=new FormData();
  body.set('csrf',CSRF);
  body.set('match_id',String(mid));
  body.set('event_id',String(EVENT_ID));
  body.set('op',op);

  try{
    const r=await fetch(MATCH_CONTROL_URL+'?event_id='+encodeURIComponent(EVENT_ID),{
      method:'POST',
      body,
      credentials:'same-origin',
      cache:'no-store',
      headers:{'X-Requested-With':'XMLHttpRequest'}
    });
    if(!r.ok)throw new Error(`Match Control returned HTTP ${r.status}.`);

    const messages={
      ready:currentMatchState==='ended'?'Match reopened for re-scouting.':'Match is ready for scouts.',
      start:'Match started.',
      pause:'Match paused.',
      resume:'Match resumed.',
      end:'Match ended.'
    };
    if(window.NeptuneUI&&typeof NeptuneUI.toast==='function'){
      NeptuneUI.toast(messages[op]||'Match updated.','good',{title:'Live Match Control'});
    }

    // Pull the authoritative state immediately rather than waiting for the
    // regular 1.2-second monitor interval.
    await poll();
  }catch(e){
    if(window.NeptuneUI&&typeof NeptuneUI.toast==='function'){
      NeptuneUI.toast(e&&e.message?e.message:'Unable to control the match.','bad',{title:'Live Match Control'});
    }
  }finally{
    controlBusy=false;
    renderControls({state:currentMatchState});
  }
}

liveControlActions.addEventListener('click',e=>{
  const button=e.target.closest('[data-match-op]');
  if(button)runMatchControl(String(button.dataset.matchOp||''));
});

async function poll(){
  try{
    const r=await fetch('../api/live-monitor.php?match_id='+mid,{cache:'no-store'}),d=await r.json();
    if(!r.ok||d.error)throw new Error(d.error||'Monitor request failed');

    state.textContent=`${d.match.event_name||''} ${d.match.comp_level||''} ${d.match.match_number||''} — ${d.match.state||'unknown'} · Run ${d.match.run_number||1}`;
    renderControls(d.match);

    const byStation={};
    (d.scouts||[]).forEach(s=>{byStation[`${s.alliance}-${s.station}`]=s});
    scouts.innerHTML=(d.match_teams||[]).map(t=>{
      const s=byStation[`${t.alliance}-${t.station}`];
      return `<div class="card station-live ${String(t.alliance).toLowerCase()}"><b>${esc(t.alliance)} ${Number(t.station)} · #${Number(t.frc_team_number)}</b><div>${s?esc(s.scout_name||'Scout'):'Not connected'}</div><span class="pill">${s?esc(s.status):'waiting'}</span><div class="muted">${s&&s.last_seen_at?'Last seen '+esc(s.last_seen_at):'No heartbeat yet'}</div></div>`;
    }).join('');

    actions.innerHTML=(d.actions||[]).map(a=>actionRow(a,false)).join('')||'<tr><td colspan="7" class="muted">No actions recorded for this run yet.</td></tr>';
    if(allActionsDialog.open&&Date.now()-lastAllRefresh>2500)loadAllActions(true);
  }catch(e){
    state.textContent='Monitor connection problem — retrying';
  }
}

async function loadAllActions(silent=false){
  if(allLoading)return;
  allLoading=true;
  if(!silent)allActionsBody.innerHTML='<tr><td colspan="8" class="muted">Loading…</td></tr>';
  try{
    const r=await fetch('../api/live-monitor.php?match_id='+mid+'&all=1',{cache:'no-store'}),d=await r.json();
    if(!r.ok||d.error)throw new Error(d.error||'Unable to load actions');
    allActionRows=d.actions||[];
    lastAllRefresh=Date.now();
    renderAllActions();
  }catch(e){
    NeptuneUI.toast(e.message||'Unable to load actions','bad',{title:'Live Monitor'});
  }finally{
    allLoading=false;
  }
}

function renderAllActions(){
  const q=actionSearch.value.trim().toLowerCase();
  const rows=q?allActionRows.filter(a=>[a.frc_team_number,a.scout_name,a.action_name||a.action_code,a.result,a.source].join(' ').toLowerCase().includes(q)):allActionRows;
  allActionCount.textContent=`${rows.length} action${rows.length===1?'':'s'}`;
  allActionsBody.innerHTML=rows.map(a=>actionRow(a,true)).join('')||'<tr><td colspan="8" class="muted">No matching actions.</td></tr>';
}

async function deleteAction(actionId){
  const a=allActionRows.find(x=>Number(x.id)===Number(actionId));
  const label=a?`#${a.frc_team_number} · ${a.action_name||a.action_code} · ${a.result}`:'this action';
  if(!await NeptuneUI.confirm(`Delete ${label}?\n\nIt will stop counting in scouting totals and analytics, but Neptune will keep an audit record.`,{title:'Delete scouting action',confirmText:'Delete',danger:true}))return;
  const r=await fetch('../api/delete-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action_id:Number(actionId),match_id:mid,csrf:CSRF})}),d=await r.json();
  if(!r.ok||d.status!=='success'){
    NeptuneUI.toast(d.message||'Unable to delete action','bad',{title:'Delete failed'});
    return;
  }
  allActionRows=allActionRows.filter(x=>Number(x.id)!==Number(actionId));
  renderAllActions();
  poll();
}

document.addEventListener('click',e=>{
  const b=e.target.closest('.action-delete');
  if(b)deleteAction(Number(b.dataset.actionId));
});

showAllActions.addEventListener('click',async()=>{
  allActionsDialog.showModal();
  await loadAllActions();
});
closeAllActions.addEventListener('click',()=>allActionsDialog.close());
actionSearch.addEventListener('input',renderAllActions);

addAction.onclick=async()=>{
  const [alliance,station]=astation.value.split('-');
  const opt=aaction.options[aaction.selectedIndex];
  let body={
    match_id:mid,
    alliance,
    station:Number(station),
    action_code:opt.value,
    action_name:opt.dataset.name,
    action_type:opt.dataset.type,
    location:opt.dataset.location,
    result:aresult.value,
    points:Number(apoints.value),
    match_time_sec:Number(asec.value)
  };
  let r=await fetch('../api/admin-add-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}),d=await r.json();
  if(d.status==='success'){
    NeptuneUI.toast('Action added.','good');
    poll();
    if(allActionsDialog.open)loadAllActions();
  }else{
    NeptuneUI.toast(d.error||'Unable to add action','bad',{title:'Add action failed'});
  }
};

renderControls({state:currentMatchState});
poll();
setInterval(poll,1200);
</script>
  <?php endif;?>
</section>
<?php include dirname(__DIR__).'/partials_footer.php';
