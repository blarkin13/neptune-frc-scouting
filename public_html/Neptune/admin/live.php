<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);
$mid=(int)($_GET['match_id']??0);

$s=$pdo->prepare('SELECT m.*,e.name event_name,g.name game_name,g.config_json FROM matches m JOIN events e ON e.id=m.event_id JOIN games g ON g.id=m.game_id WHERE m.id=? AND m.organization_id=?');
$s->execute([$mid,$u['organization_id']]);
$match=$s->fetch();
if(!$match) exit('Match not found');

$s=$pdo->prepare('SELECT alliance,station,frc_team_number FROM match_teams WHERE match_id=? ORDER BY FIELD(alliance,\'Red\',\'Blue\'),station');
$s->execute([$mid]);
$matchTeams=$s->fetchAll();
$cfg=json_decode($match['config_json'],true)?:['buttons'=>[]];$timing=game_timing($cfg);
$buttons=array_values(array_filter($cfg['buttons']??[],fn($b)=>!empty($b['code'])));

$pageTitle='Live Monitor';
include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between">
  <div><h1 style="margin-bottom:4px">Live Scout Monitor</h1><div class="muted">Current scouting run: <?=e($match['run_number']??1)?></div></div>
  <button class="secondary" id="showAllActions" type="button"><i class="fa-solid fa-list"></i> Show All Actions</button>
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
    <div class="action-manager-filters">
      <input id="actionSearch" type="search" placeholder="Filter robot, scout, action, result…">
      <span class="pill" id="allActionCount">0 actions</span>
    </div>
    <div class="table-wrap action-manager-table"><table class="table"><thead><tr><th>Time</th><th>Robot</th><th>Scout</th><th>Action</th><th>Result</th><th>Pts</th><th>Source</th><th></th></tr></thead><tbody id="allActionsBody"></tbody></table></div>
  </div>
</dialog>
<script>
const mid=<?=$mid?>,CSRF=<?=json_encode(csrf_token())?>;
let allActionRows=[],lastAllRefresh=0,allLoading=false;
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
function actionRow(a,full=false){
  const resultClass=String(a.result||'').toLowerCase();
  return `<tr data-action-id="${Number(a.id)}" data-search="${esc([a.frc_team_number,a.scout_name,a.action_name||a.action_code,a.result,a.source].join(' ').toLowerCase())}">
    <td>${esc(a.match_time_sec??'')}</td><td>#${esc(a.frc_team_number)}</td><td>${esc(a.scout_name||'Admin')}</td><td>${esc(a.action_name||a.action_code)}</td>
    <td><span class="action-result ${esc(resultClass)}">${esc(a.result)}</span></td><td>${esc(a.points)}</td>${full?`<td>${esc(a.source||'')}</td>`:''}
    <td><button class="danger action-delete" type="button" data-action-id="${Number(a.id)}" title="Delete action" aria-label="Delete ${esc(a.action_name||a.action_code)}"><i class="fa-solid fa-trash"></i></button></td></tr>`;
}
function suggestedPoints(){
  const opt=aaction.options[aaction.selectedIndex]; if(!opt)return;
  const sec=Number(asec.value||0),result=aresult.value;
  const auto=sec<<?=json_encode($timing['auton'])?>;
  apoints.value=result==='Success'?Number(auto?opt.dataset.auton:opt.dataset.teleop):0;
}
aaction.addEventListener('change',suggestedPoints);asec.addEventListener('input',suggestedPoints);aresult.addEventListener('change',suggestedPoints);suggestedPoints();
async function poll(){
  try{
    const r=await fetch('../api/live-monitor.php?match_id='+mid,{cache:'no-store'}),d=await r.json();
    state.textContent=`${d.match.event_name||''} ${d.match.comp_level||''} ${d.match.match_number||''} — ${d.match.state||'unknown'} · Run ${d.match.run_number||1}`;
    const byStation={};(d.scouts||[]).forEach(s=>{byStation[`${s.alliance}-${s.station}`]=s});
    scouts.innerHTML=(d.match_teams||[]).map(t=>{const s=byStation[`${t.alliance}-${t.station}`];return `<div class="card station-live ${String(t.alliance).toLowerCase()}"><b>${esc(t.alliance)} ${Number(t.station)} · #${Number(t.frc_team_number)}</b><div>${s?esc(s.scout_name||'Scout'):'Not connected'}</div><span class="pill">${s?esc(s.status):'waiting'}</span><div class="muted">${s&&s.last_seen_at?'Last seen '+esc(s.last_seen_at):'No heartbeat yet'}</div></div>`}).join('');
    actions.innerHTML=(d.actions||[]).map(a=>actionRow(a,false)).join('')||'<tr><td colspan="7" class="muted">No actions recorded for this run yet.</td></tr>';
    if(allActionsDialog.open&&Date.now()-lastAllRefresh>2500)loadAllActions(true);
  }catch(e){state.textContent='Monitor connection problem — retrying';}
}
async function loadAllActions(silent=false){
  if(allLoading)return;allLoading=true;
  if(!silent)allActionsBody.innerHTML='<tr><td colspan="8" class="muted">Loading…</td></tr>';
  try{const r=await fetch('../api/live-monitor.php?match_id='+mid+'&all=1',{cache:'no-store'}),d=await r.json();allActionRows=d.actions||[];lastAllRefresh=Date.now();renderAllActions();}finally{allLoading=false}
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
  if(!confirm(`Delete ${label}?\n\nIt will stop counting in scouting totals and analytics, but Neptune will keep an audit record.`))return;
  const r=await fetch('../api/delete-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action_id:Number(actionId),match_id:mid,csrf:CSRF})}),d=await r.json();
  if(!r.ok||d.status!=='success'){alert(d.message||'Unable to delete action');return;}
  allActionRows=allActionRows.filter(x=>Number(x.id)!==Number(actionId));renderAllActions();poll();
}
document.addEventListener('click',e=>{const b=e.target.closest('.action-delete');if(b)deleteAction(Number(b.dataset.actionId))});
showAllActions.addEventListener('click',async()=>{allActionsDialog.showModal();await loadAllActions()});
closeAllActions.addEventListener('click',()=>allActionsDialog.close());
actionSearch.addEventListener('input',renderAllActions);
addAction.onclick=async()=>{
  const [alliance,station]=astation.value.split('-');
  const opt=aaction.options[aaction.selectedIndex];
  let body={match_id:mid,alliance,station:Number(station),action_code:opt.value,action_name:opt.dataset.name,action_type:opt.dataset.type,location:opt.dataset.location,result:aresult.value,points:Number(apoints.value),match_time_sec:Number(asec.value)};
  let r=await fetch('../api/admin-add-action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}),d=await r.json();
  if(d.status==='success'){poll();if(allActionsDialog.open)loadAllActions()}else alert(d.error||'Unable to add action');
};
poll();setInterval(poll,1200);
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
