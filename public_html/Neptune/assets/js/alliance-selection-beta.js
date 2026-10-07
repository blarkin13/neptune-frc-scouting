(()=>{'use strict';

const cfg=window.NeptuneAllianceBeta||{};
const $=s=>document.querySelector(s);
const $$=s=>Array.from(document.querySelectorAll(s));
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
let data=null,csrf='',chooser=null,activeTier='1',poolFilter='available',detailTeam=0;

const el={
  status:$('#asbStatus'),board:$('#asbAllianceBoard'),picks:$('#asbPickLists'),pool:$('#asbTeamPool'),
  search:$('#asbSearch'),sort:$('#asbPoolSort'),chooser:$('#asbChooser'),chooserList:$('#asbChooserList'),
  chooserSearch:$('#asbChooserSearch'),recommend:$('#asbRecommendations'),guideWarning:$('#asbGuideWarning'),
  detail:$('#asbTeamDetail'),detailBody:$('#asbDetailBody'),detailActions:$('#asbDetailActions')
};

const tagLabels={
  captain_material:'Captain material',first_pick:'1st Pick',second_pick:'2nd Pick',third_pick:'3rd Pick',
  defense:'Defense',offense:'Offense',flex:'Flex',auto:'Auto',endgame:'Endgame',reliable:'Reliable',
  high_ceiling:'High ceiling',consistent:'Consistent',avoid:'Avoid'
};
const scenarioLabels={captain:'Captain',first_pick:'First Pick',second_pick:'Second Pick',bubble:'Bubble / Dual Prep'};
const needLabels={auto:'Autonomous',teleop:'Teleop Scoring',defense:'Defense',endgame:'Endgame',reliability:'Reliability'};
const needIcons={auto:'fa-solid fa-robot',teleop:'fa-solid fa-bullseye',defense:'fa-solid fa-shield-halved',endgame:'fa-solid fa-flag-checkered',reliability:'fa-solid fa-heart-pulse'};

function rankingLabel(){return data?.ranking?.label||'Ranking Score';}
function teamMap(){const m={};(data?.roster||[]).forEach(r=>m[Number(r.team||r.frc_team_number)]=r);return m;}
function trow(t){return teamMap()[Number(t)]||null;}
function nameOf(t){const r=trow(t);return r?`#${t}${r.nickname?' · '+r.nickname:''}`:`#${t}`;}
function privateState(){return data?.private?.state||{};}
function sharedState(){return data?.shared?.state||{};}
function planning(){return privateState().planning||{scenario:'auto',needs:{auto:2,teleop:2,defense:2,endgame:2,reliability:3}};}
function activeScenario(){const s=planning().scenario||'auto';return s==='auto'?(data?.projection?.recommended||'captain'):s;}
function draftedInfo(t){return data?.drafted?.[String(t)]||null;}
function isDeclined(t){return !!sharedState().declined?.[String(t)];}
function isBroken(t){return !!sharedState().broken?.[String(t)];}
function isUnavailable(t){return !!privateState().unavailable?.[String(t)];}
function isDnp(t){return !!privateState().do_not_pick?.[String(t)];}
function isFavorite(t){return !!privateState().favorites?.[String(t)];}
function defenseEvidence(t){return data?.defense_evidence?.[String(t)]||data?.defense_evidence?.[Number(t)]||null;}

function captainAlliance(t){
  for(let a=1;a<=8;a++)if(Number(sharedState().alliances?.[String(a)]?.captain||0)===Number(t))return a;
  return 0;
}
function captainEligible(t){
  const ca=captainAlliance(t);if(!ca)return true;
  const ours=captainAlliance(Number(data?.strategy_team?.frc_team_number||0));
  const allowed=data?.production_reference?.captain_picks_allowed!==false;
  if(!allowed)return false;
  if(!ours)return true;
  if(ca===ours||ca<ours)return false;
  return true;
}
function teamStatus(t){
  if(draftedInfo(t))return'drafted';
  if(isBroken(t))return'broken';
  if(isDeclined(t))return'declined';
  if(isDnp(t))return'dnp';
  if(isUnavailable(t))return'unavailable';
  return'available';
}
function record(r){
  if(!r)return'—';
  const w=Number(r.wins||0),l=Number(r.losses||0),ti=Number(r.ties||0);
  return `${w}-${l}${ti?'-'+ti:''}`;
}
function num(v,d=1){return v==null||v===''||!Number.isFinite(Number(v))?'—':Number(v).toFixed(d);}
function fmtPoints(r){return num(r?.points,Number(data?.ranking?.precision??2));}
function mergedTags(r){
  const p=privateState().alliance_tags?.[String(r.team)]||privateState().alliance_tags?.[r.team]||[];
  return p.length?p:(r.tags||[]);
}
function tagChips(r,max=3){
  const tags=mergedTags(r);if(!tags.length)return'';
  const shown=tags.slice(0,max).map(t=>`<span class="asb-tag">${esc(tagLabels[t]||String(t).replaceAll('_',' '))}</span>`).join('');
  return shown+(tags.length>max?`<span class="asb-tag more">+${tags.length-max}</span>`:'');
}
function sortTeams(rows,mode='best'){
  const arr=[...rows];
  arr.sort((a,b)=>{
    if(mode==='team')return Number(a.team)-Number(b.team);
    if(mode==='rank'){
      const ar=Number(a.rank||99999),br=Number(b.rank||99999);
      return ar-br||Number(b.neptune_epa??-1e9)-Number(a.neptune_epa??-1e9);
    }
    if(mode==='epa')return Number(b.neptune_epa??-1e9)-Number(a.neptune_epa??-1e9)||Number(a.rank||99999)-Number(b.rank||99999);
    return Number(b.points||0)-Number(a.points||0)||Number(b.neptune_epa??-1e9)-Number(a.neptune_epa??-1e9)||Number(a.rank||99999)-Number(b.rank||99999);
  });
  return arr;
}
function trulyAvailable(r){
  const t=Number(r.team);
  return !draftedInfo(t)&&!isDeclined(t)&&!isBroken(t)&&!isDnp(t)&&!isUnavailable(t)&&captainEligible(t);
}
function sharedAvailable(r){
  const t=Number(r.team);
  return !draftedInfo(t)&&!isDeclined(t)&&!isBroken(t)&&captainEligible(t);
}
function metricStrip(r){
  return `<div class="asb-metrics">
    <span><small>Q Rank</small><b>${r.rank?'#'+r.rank:'—'}</b></span>
    <span><small>${esc(rankingLabel())}</small><b>${fmtPoints(r)}</b></span>
    <span><small>Record</small><b>${record(r)}</b></span>
    <span class="nep"><small>Nep. EPA</small><b>${num(r.neptune_epa,1)}</b></span>
    <span><small>Public EPA</small><b>${num(r.epa,1)}</b></span>
    <span><small>D-EPA</small><b>${num(r.depa,1)}</b></span>
    <span class="nep"><small>Nep. D-EPA</small><b>${num(r.neptune_depa,1)}</b></span>
  </div>`;
}
function statusBadges(t){
  const out=[];const ca=captainAlliance(t);
  if(ca)out.push(`<em class="captain"><i class="fa-solid fa-anchor"></i> Captain A${ca}</em>`);
  const d=draftedInfo(t);if(d)out.push(`<em class="drafted">Drafted${d.alliance?' · A'+d.alliance:''}</em>`);
  if(isBroken(t))out.push('<em class="broken">Broken</em>');
  if(isDeclined(t))out.push('<em class="declined">Declined</em>');
  if(isUnavailable(t))out.push('<em class="unavailable">Unavailable to us</em>');
  if(isDnp(t))out.push('<em class="dnp">Do Not Pick</em>');
  if(isFavorite(t))out.push('<em class="favorite"><i class="fa-solid fa-star"></i> Favorite</em>');
  const de=defenseEvidence(t);if(de?.count)out.push(`<em class="defense"><i class="fa-solid fa-shield-halved"></i> Defense evidence ${de.count}</em>`);
  return out.join('');
}
function robotCard(r,{compact=false,clickable=true,drag=true}={}){
  const t=Number(r.team),st=teamStatus(t);
  return `<article class="asb-robot-card ${st} ${compact?'compact':''}" ${drag?'draggable="true" data-drag-team="'+t+'"':''} ${clickable?'data-open-team="'+t+'" tabindex="0" role="button"':''}>
    <div class="asb-robot-head"><div><b>#${t}</b><span>${esc(r.nickname||'')}</span></div>${isFavorite(t)?'<i class="fa-solid fa-star asb-favorite-star"></i>':''}</div>
    ${metricStrip(r)}
    <div class="asb-tag-row">${tagChips(r,compact?2:3)}</div>
    <div class="asb-badges">${statusBadges(t)}</div>
  </article>`;
}

function normalizeMetric(rows,key){
  const vals=rows.map(r=>Number(r[key])).filter(Number.isFinite);
  if(!vals.length)return [0,1];
  return [Math.min(...vals),Math.max(...vals)];
}
function candidateFit(r){
  const rows=(data?.roster||[]).filter(x=>!isDnp(x.team)&&!isBroken(x.team)&&!isDeclined(x.team));
  const [emin,emax]=normalizeMetric(rows,'neptune_epa');
  const [pmin,pmax]=normalizeMetric(rows,'points');
  const e=Number.isFinite(Number(r.neptune_epa))?(Number(r.neptune_epa)-emin)/Math.max(.01,emax-emin):0;
  const q=Number.isFinite(Number(r.points))?(Number(r.points)-pmin)/Math.max(.01,pmax-pmin):0;
  let score=45*e+25*q;
  const tags=new Set(mergedTags(r));
  const needs=planning().needs||{};
  const boosts={
    auto:['auto'],teleop:['offense','flex','high_ceiling'],defense:['defense'],
    endgame:['endgame'],reliability:['reliable','consistent']
  };
  for(const [need,tagset] of Object.entries(boosts)){
    const weight=Number(needs[need]??0);
    if(tagset.some(t=>tags.has(t)))score+=weight*4;
  }
  const de=defenseEvidence(r.team);if(de?.count)score+=Number(needs.defense||0)*Math.min(8,de.count*1.5);
  if(isFavorite(r.team))score+=5;
  if(captainAlliance(r.team))score-=8;
  return Math.round(score);
}
function fitRows(limit=6){
  return (data?.roster||[]).filter(trulyAvailable).map(r=>({...r,fit:candidateFit(r)})).sort((a,b)=>b.fit-a.fit||Number(b.neptune_epa||0)-Number(a.neptune_epa||0)).slice(0,limit);
}

function renderScenarioAdvisor(){
  const p=data?.projection||{};
  const probs=p.probabilities||{};
  const conf=String(p.confidence||'low').toUpperCase();
  $('#asbProjectionConfidence').textContent=`${conf} CONFIDENCE`;
  $('#asbProjectionConfidence').className=`asb-projection-confidence ${String(p.confidence||'low')}`;
  $('#asbProjectionCard').innerHTML=`
    <span class="asb-kicker">Projected finish</span>
    <strong>#${p.projected_low||'—'}–#${p.projected_high||'—'}</strong>
    <div class="asb-projection-stats">
      <span><small>Current</small><b>#${p.current_rank||'—'}</b></span>
      <span><small>Matches left</small><b>${p.remaining_matches??'—'}</b></span>
      <span><small>EPA rank</small><b>#${p.epa_rank||'—'}</b></span>
      <span><small>Avg win chance</small><b>${p.avg_win_probability??'—'}%</b></span>
    </div>
    ${renderRemainingSchedule()}
  `;
  $('#asbRoleProbabilities').innerHTML=[
    ['captain','Captain',probs.captain||0],['first_pick','First Pick',probs.first_pick||0],['second_pick','Second Pick',probs.second_pick||0]
  ].map(([k,label,val])=>`<div class="asb-role-prob"><div><b>${label}</b><span>${val}%</span></div><div class="asb-prob-track"><i style="width:${clamp(val,0,100)}%"></i></div></div>`).join('');
  $('#asbProjectionWhy').innerHTML=(p.why||[]).map(x=>`<li>${esc(x)}</li>`).join('');
  const stored=planning().scenario||'auto';
  $$('[data-scenario]').forEach(b=>b.classList.toggle('active',b.dataset.scenario===stored));
  const key=`neptune-beta3-projection:${cfg.eventId}:${cfg.strategyTeam}`;
  try{
    const prev=localStorage.getItem(key),now=String(p.recommended||'');
    const box=$('#asbProjectionChange');
    if(prev&&now&&prev!==now){
      box.hidden=false;
      box.innerHTML=`<i class="fa-solid fa-arrows-rotate"></i><div><b>Projection changed</b><span>Neptune previously suggested ${esc(scenarioLabels[prev]||prev)} and now suggests ${esc(scenarioLabels[now]||now)}. Your saved planning mode was not changed.</span></div>`;
    }else box.hidden=true;
    if(now)localStorage.setItem(key,now);
  }catch(_){}
}
function renderRemainingSchedule(){
  const rows=(data?.remaining_schedule||[]).slice(0,3);
  if(!rows.length)return'<div class="asb-schedule-note"><i class="fa-solid fa-circle-check"></i> No remaining qualification matches loaded.</div>';
  return `<div class="asb-next-matches"><small>Remaining schedule</small>${rows.map(m=>`<span><b>Q${m.match_number}</b><em>${m.win_probability}% win</em></span>`).join('')}</div>`;
}
function needDots(key,value){
  return `<button type="button" class="asb-need-row" data-need="${key}" data-need-value="${value}" title="Click to change priority">
    <i class="${needIcons[key]}"></i><span><b>${needLabels[key]}</b><small>${['Not needed','Low','Medium','High'][value]||'Medium'}</small></span>
    <span class="asb-need-dots">${[1,2,3].map(n=>`<i class="${n<=value?'on':''}"></i>`).join('')}</span>
  </button>`;
}
function renderNeeds(){
  const needs=planning().needs||{};
  return `<div class="asb-panel-head"><span class="asb-kicker">What should the alliance add?</span><h3>Alliance Needs</h3><p>Neptune uses these priorities to re-rank candidate fit. Tap a row to cycle the priority.</p></div>
    <div class="asb-needs">${Object.keys(needLabels).map(k=>needDots(k,Number(needs[k]??2))).join('')}</div>`;
}
function projectedCaptainCards(limit=6){
  const own=Number(data?.strategy_team?.frc_team_number||0);
  return (data?.projected_captains||[]).filter(r=>Number(r.team)!==own).slice(0,limit).map(r=>`
    <button type="button" class="asb-mini-team" data-open-team="${r.team}">
      <span><b>#${r.team}</b><small>${esc(r.nickname||'')}</small></span>
      <em>#${r.rank||'—'} · Nep ${num(r.neptune_epa,1)}</em>
    </button>`).join('')||'<div class="asb-empty">Captain projection is not available yet.</div>';
}
function defenseCandidateCards(limit=4){
  const rows=(data?.roster||[]).filter(trulyAvailable).filter(r=>{
    const tags=new Set(mergedTags(r));return tags.has('defense')||Number(defenseEvidence(r.team)?.count||0)>0;
  }).map(r=>({...r,defCount:Number(defenseEvidence(r.team)?.count||0),fit:candidateFit(r)}))
    .sort((a,b)=>b.defCount-a.defCount||b.fit-a.fit).slice(0,limit);
  if(!rows.length)return'<div class="asb-empty">No defense evidence has been tagged yet. Spot Scouting defense tags will appear here automatically.</div>';
  return rows.map(r=>{
    const ev=defenseEvidence(r.team)||{};const vids=(ev.media||[]).filter(m=>m.type==='video').slice(0,2);
    return `<div class="asb-defense-candidate">
      <button type="button" data-open-team="${r.team}"><b>#${r.team} ${esc(r.nickname||'')}</b><span>${r.defCount} defense observation${r.defCount===1?'':'s'} · Nep D-EPA ${num(r.neptune_depa,1)} · Nep EPA ${num(r.neptune_epa,1)}</span></button>
      <div>${vids.length?vids.map(v=>`<a class="secondary compact" href="${esc(cfg.spotMedia)}?id=${v.id}" target="_blank"><i class="fa-solid fa-play"></i> ${esc(v.match||'Video')}</a>`).join(''):'<span class="muted">No tagged video yet</span>'}</div>
    </div>`;
  }).join('');
}
function fitCandidateCards(limit=4){
  const rows=fitRows(limit);
  if(!rows.length)return'<div class="asb-empty">No available candidates meet the current filters.</div>';
  return rows.map((r,i)=>`<button type="button" class="asb-fit-candidate" data-open-team="${r.team}">
    <span class="asb-fit-rank">${i+1}</span><span><b>#${r.team} ${esc(r.nickname||'')}</b><small>Fit ${r.fit} · Q#${r.rank||'—'} · Nep ${num(r.neptune_epa,1)}</small></span>
    <i class="fa-solid fa-chevron-right"></i></button>`).join('');
}
function ownPitch(){
  const own=trow(Number(data?.strategy_team?.frc_team_number||0));
  if(!own)return'<div class="asb-empty">This team is not in the event roster.</div>';
  return `<div class="asb-own-pitch">${metricStrip(own)}<div class="asb-tag-row large">${tagChips(own,8)||'<span class="muted">No role tags yet.</span>'}</div><p>Use this as the starting point for pit conversations: what your robot demonstrably contributes, and what an alliance still needs after selecting you.</p></div>`;
}
function renderScenarioPlan(){
  const mode=activeScenario();
  $('#asbModeBadge').textContent=(planning().scenario==='auto'?'AUTO · ':'')+(scenarioLabels[mode]||mode).toUpperCase();
  let title='',subtitle='',focus='',candidates='',check=[];
  if(mode==='captain'){
    title='Captain Planning';
    subtitle='Build around your own robot, estimate who else will captain, and rank complementary robots that are realistically available.';
    focus=`<div class="asb-panel-head"><span class="asb-kicker">Draft pressure</span><h3>Who else will probably captain?</h3><p>These teams are currently in the captain band. Robots above your projected slot create the most draft pressure on your first-choice candidates.</p></div><div class="asb-mini-list">${projectedCaptainCards()}</div>`;
    candidates=`<div class="asb-panel-head"><span class="asb-kicker">Best complement</span><h3>Top fit candidates</h3><p>Fit uses qualification performance, Neptune EPA, your editable alliance needs, tags, and defense evidence.</p></div>${fitCandidateCards()}<a class="secondary asb-wide-link" href="${esc(cfg.matchStrategy)}?event_id=${cfg.eventId}"><i class="fa-solid fa-chart-line"></i> Open Prediction / Match Strategy</a>`;
    check=['Confirm our likely captain position','Set alliance needs','Review top first-pick candidates','Watch defense evidence for specialist candidates','Test the top pairings in AUGUR','Rank first- and second-pick lists','Mark Do Not Pick / robot-health concerns'];
  }else if(mode==='first_pick'){
    title='First-Pick Planning';
    subtitle='Prepare for who is likely to select you, understand your own value proposition, and pre-plan the next robot that would complete each likely alliance.';
    focus=`<div class="asb-panel-head"><span class="asb-kicker">How captains see us</span><h3>#${data.strategy_team.frc_team_number} scouting card</h3><p>Know your own pitch before alliance discussions start.</p></div>${ownPitch()}`;
    candidates=`<div class="asb-panel-head"><span class="asb-kicker">Likely captains</span><h3>Who might select us?</h3><p>Start plans for the captains most likely to be above you in the final rankings.</p></div><div class="asb-mini-list">${projectedCaptainCards(8)}</div>`;
    check=['Identify the captains most likely to select us','Prepare a short value proposition for our robot','For each likely captain, identify what their alliance would still need','Build second-pick target lists for those combinations','Review defense/specialist candidates','Prepare alternatives if a preferred captain chooses someone else'];
  }else if(mode==='second_pick'){
    title='Second-Pick / Specialist Planning';
    subtitle='Emphasize the role that makes your robot valuable and identify specialist evidence that alliances will care about late in the draft.';
    focus=`<div class="asb-panel-head"><span class="asb-kicker">Our case</span><h3>What makes us selectable?</h3><p>Late-draft decisions are often about a missing specialty, reliability, or matchup role—not raw EPA alone.</p></div>${ownPitch()}`;
    candidates=`<div class="asb-panel-head"><span class="asb-kicker">Defense review</span><h3>Defense candidates & evidence</h3><p>Spot Scouting defense tags and attached video are surfaced here automatically.</p></div>${defenseCandidateCards()}`;
    check=['Identify the specialty we offer best','Review teams competing for that same role','Watch defense/specialist video evidence','Verify robot health and reliability','Know which projected alliances need our specialty','Prepare concise pit/field talking points'];
  }else{
    title='Bubble / Dual Planning';
    subtitle='The projected finish crosses a tier boundary. Keep two complete plans alive until the remaining qualification matches resolve it.';
    focus=`<div class="asb-dual-plan"><article><span>IF CAPTAIN</span><h3>Build our alliance</h3><p>Rank complementary first picks and specialist second picks.</p>${fitCandidateCards(3)}</article><article><span>IF PICKED</span><h3>Plan around likely captains</h3><p>Know who may choose us and what the alliance would still need.</p><div class="asb-mini-list">${projectedCaptainCards(4)}</div></article></div>`;
    candidates=`<div class="asb-panel-head"><span class="asb-kicker">Swing matches</span><h3>What could change our role?</h3><p>Watch the remaining matches and refresh rankings. Neptune will flag if its recommended scenario changes.</p></div>${renderRemainingSchedule()}<div class="asb-defense-shortcut"><b>Need a defensive fallback?</b>${defenseCandidateCards(2)}</div>`;
    check=['Maintain both Captain and Picked plans','Watch the remaining qualification matches that can move the rank','Do not discard either Pick List yet','Review likely captains and likely available first picks','Refresh rankings after each final-day match','Lock the actual scenario once qualifications end'];
  }
  $('#asbPlanTitle').textContent=title;
  $('#asbPlanSubtitle').textContent=subtitle;
  $('#asbScenarioFocus').innerHTML=focus;
  $('#asbAllianceNeeds').innerHTML=renderNeeds();
  $('#asbScenarioCandidates').innerHTML=candidates;
  $('#asbPlanningChecklist').innerHTML=check.map((x,i)=>`<label class="asb-check-item"><input type="checkbox"><span><b>${i+1}</b>${esc(x)}</span></label>`).join('');
}

function scenarioGuideRows(){
  const mode=activeScenario();
  let rows=fitRows(6);
  if(mode==='second_pick'){
    rows=rows.sort((a,b)=>Number(defenseEvidence(b.team)?.count||0)-Number(defenseEvidence(a.team)?.count||0)||b.fit-a.fit);
  }
  return rows.slice(0,3);
}
function renderGuide(){
  const mode=activeScenario(),available=scenarioGuideRows();
  const titles={
    captain:['Best candidates for our alliance','Ranked for complement and current alliance needs.'],
    first_pick:['Robots we should be ready to recommend','If a captain selects us, these are strong next-robot options to discuss.'],
    second_pick:['Specialists worth knowing','Late-draft value emphasizes defense, reliability, fit, and evidence—not just raw EPA.'],
    bubble:['Candidates that matter in either plan','Keep likely captain picks and specialist fallbacks visible until our final role is known.']
  };
  $('#asbGuideTitle').textContent=titles[mode]?.[0]||'Who should we consider?';
  $('#asbGuideSubtitle').textContent=titles[mode]?.[1]||'';
  $('#asbRankingLegend').textContent=rankingLabel();
  el.recommend.innerHTML=available.length?available.map((r,i)=>`
    <button type="button" class="asb-rec" data-open-team="${r.team}">
      <span class="asb-rec-rank">${i+1}</span>
      <div class="asb-rec-main"><small>${i===0?'Neptune recommendation':'Next option'}</small><b>#${r.team} ${esc(r.nickname||'')}</b>
      <span>Fit ${r.fit} · #${r.rank||'—'} qual · ${fmtPoints(r)} ${esc(rankingLabel())} · Nep. EPA ${num(r.neptune_epa,1)}</span>
      <div class="asb-tag-row">${tagChips(r,3)}</div></div><i class="fa-solid fa-chevron-right"></i>
    </button>`).join(''):'<div class="asb-empty">No currently available robots meet the active strategy filters.</div>';
  const msgs=[];
  if(!data?.ranking?.available)msgs.push(`Qualification rankings unavailable: ${data?.ranking?.error||'TBA data not loaded.'}`);
  if(data?.augur_error)msgs.push(data.augur_error);
  el.guideWarning.hidden=!msgs.length;
  el.guideWarning.innerHTML=msgs.map(m=>`<span><i class="fa-solid fa-triangle-exclamation"></i> ${esc(m)}</span>`).join('');
}

function boardSlot(alliance,slot,team){
  const label={captain:'Captain',pick1:'Pick 1',pick2:'Pick 2',backup:'Backup'}[slot];
  if(slot==='captain'){
    const r=trow(team);
    return `<div class="asb-board-slot captain"><small>${label}</small>${r?`<div class="asb-board-team"><b>#${r.team}</b><span>${esc(r.nickname||'')}</span><em>#${r.rank||'—'} qual · Nep ${num(r.neptune_epa,1)}</em></div>`:'<div class="asb-board-empty">Rankings unavailable</div>'}</div>`;
  }
  if(!team)return `<button type="button" class="asb-board-slot empty" data-shared-choose="1" data-alliance="${alliance}" data-slot="${slot}"><small>${label}</small><b>Choose robot</b><span>Click or drop</span></button>`;
  const r=trow(team);
  return `<div class="asb-board-slot filled" data-drop-shared="1" data-alliance="${alliance}" data-slot="${slot}">
    <small>${label}</small><button type="button" class="asb-board-team" data-open-team="${team}"><b>#${team}</b><span>${esc(r?.nickname||'')}</span><em>#${r?.rank||'—'} qual · Nep ${num(r?.neptune_epa,1)}</em></button>
    <button type="button" class="asb-clear" data-clear-shared="1" title="Clear"><i class="fa-solid fa-xmark"></i></button>
  </div>`;
}
function renderBoard(){
  const s=sharedState();
  el.board.innerHTML=Array.from({length:8},(_,i)=>{
    const a=i+1,row=s.alliances?.[String(a)]||{};
    return `<section class="asb-alliance"><header><span>${a}</span><div><small>Alliance</small><b>${a}</b></div></header>${boardSlot(a,'captain',row.captain)}${boardSlot(a,'pick1',row.pick1)}${boardSlot(a,'pick2',row.pick2)}${boardSlot(a,'backup',row.backup)}</section>`;
  }).join('');
  const sel=$('#asbDraftElsewhereTeam');
  const available=sortTeams((data.roster||[]).filter(sharedAvailable),'best');
  sel.innerHTML='<option value="">Choose event team…</option>'+available.map(r=>`<option value="${r.team}">#${r.team} · ${esc(r.nickname||'')} · #${r.rank||'—'} · Nep ${num(r.neptune_epa,1)}</option>`).join('');
  const ext=Object.keys(s.drafted_elsewhere||{}).filter(t=>s.drafted_elsewhere[t]);
  $('#asbDraftElsewhereList').innerHTML=ext.length?ext.map(t=>`<button type="button" class="asb-chip drafted" data-toggle-drafted="${t}"><i class="fa-solid fa-circle-check"></i> ${esc(nameOf(t))} <i class="fa-solid fa-xmark"></i></button>`).join(''):'<span class="muted">No teams marked drafted elsewhere.</span>';
}
function pickSlot(team,tier,index){
  if(!team)return `<button type="button" class="asb-pick-slot empty" data-pick-tier="${tier}" data-pick-index="${index}"><span>${index+1}</span><div><b>Choose robot</b><small>Click or drop a card here</small></div><i class="fa-solid fa-plus"></i></button>`;
  const r=trow(team);
  return `<div class="asb-pick-slot filled ${teamStatus(team)}" draggable="true" data-drag-team="${team}" data-pick-tier="${tier}" data-pick-index="${index}">
    <span>${index+1}</span><button type="button" class="asb-pick-team" data-open-team="${team}"><b>#${team} ${esc(r?.nickname||'')}</b><small>#${r?.rank||'—'} qual · ${fmtPoints(r)} ${esc(rankingLabel())} · Nep ${num(r?.neptune_epa,1)}</small><div class="asb-badges">${statusBadges(team)}</div></button>
    <button type="button" class="asb-clear" data-clear-pick="1"><i class="fa-solid fa-xmark"></i></button>
  </div>`;
}
function renderPicks(){
  const p=privateState().pick_lists||{};
  $('#asbPrivateTitle').textContent=`#${data.strategy_team.frc_team_number} Pick List`;
  el.picks.innerHTML=`<section class="asb-tier active"><header><span>${activeTier}</span><div><small>${activeTier==='1'?'First':activeTier==='2'?'Second':activeTier==='3'?'Third':'Fourth'} tier</small><h3>${activeTier==='1'?'1st':activeTier==='2'?'2nd':activeTier==='3'?'3rd':'4th'} Picks</h3></div></header><div>${(p[activeTier]||[]).map((team,i)=>pickSlot(team,activeTier,i)).join('')}</div></section>`;
  $$('[data-tier-tab]').forEach(b=>b.classList.toggle('active',b.dataset.tierTab===activeTier));
  const imp=$('#asbImportProduction');imp.hidden=!data?.production_reference?.available;
  imp.title=data?.production_reference?.available?`Import ${data.production_reference.draft_name||'current production strategy'} into #${data.strategy_team.frc_team_number}`:'';
}
function poolRows(){
  const q=(el.search?.value||'').trim().toLowerCase();
  let rows=(data?.roster||[]).filter(r=>!q||String(r.team).includes(q)||String(r.nickname||'').toLowerCase().includes(q));
  if(poolFilter==='available')rows=rows.filter(trulyAvailable);
  if(poolFilter==='favorite')rows=rows.filter(r=>isFavorite(r.team));
  return sortTeams(rows,el.sort?.value||'best');
}
function renderPool(){
  const rows=poolRows();
  $('#asbPoolSummary').textContent=`${rows.length} robot${rows.length===1?'':'s'} · ${rankingLabel()} + Neptune EPA + scouting tags`;
  el.pool.innerHTML=rows.length?rows.map(r=>robotCard(r)).join(''):'<div class="asb-empty">No robots match this view.</div>';
}
function render(){
  renderScenarioAdvisor();renderScenarioPlan();renderGuide();renderBoard();renderPicks();renderPool();
  el.status.innerHTML=`<i class="fa-solid fa-circle-check"></i> Event state v${data.shared.version} · #${data.strategy_team.frc_team_number} strategy v${data.private.version} · ${esc(scenarioLabels[activeScenario()]||activeScenario())} planning`;
  el.status.classList.add('ready');
}
async function load(){
  el.status.textContent='Loading event intelligence…';
  const r=await fetch(`${cfg.api}?event_id=${encodeURIComponent(cfg.eventId)}&strategy_team=${encodeURIComponent(cfg.strategyTeam)}`,{cache:'no-store'});
  const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'Unable to load beta workspace.');
  data=j;csrf=j.csrf;render();
}
async function act(action,payload={}){
  el.status.textContent='Saving…';
  const r=await fetch(cfg.api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,event_id:cfg.eventId,strategy_team:cfg.strategyTeam,action,...payload})});
  const j=await r.json();
  if(!r.ok||!j.ok){el.status.textContent=j.error||'Save failed';el.status.classList.remove('ready');throw new Error(j.error||'Save failed');}
  data.shared=j.shared;data.private=j.private;data.drafted=j.drafted;render();
}
function chooserRows(){
  const q=(el.chooserSearch?.value||'').trim().toLowerCase();
  let rows=(data.roster||[]).filter(r=>!q||String(r.team).includes(q)||String(r.nickname||'').toLowerCase().includes(q));
  if(chooser?.type==='shared')rows=rows.filter(sharedAvailable);
  return sortTeams(rows,'best');
}
function openChooser(type,meta){
  chooser={type,...meta};
  $('#asbChooserEyebrow').textContent=type==='shared'?`Alliance ${meta.alliance} · ${meta.slot==='pick1'?'Pick 1':meta.slot==='pick2'?'Pick 2':'Backup'}`:`#${data.strategy_team.frc_team_number} · ${meta.tier==='1'?'1st':meta.tier==='2'?'2nd':meta.tier==='3'?'3rd':'4th'} Picks`;
  $('#asbChooserTitle').textContent='Choose the robot';el.chooserSearch.value='';renderChooser();el.chooser.hidden=false;
  setTimeout(()=>el.chooserSearch.focus(),40);
}
function closeChooser(){el.chooser.hidden=true;chooser=null;}
function renderChooser(){
  el.chooserList.innerHTML=chooserRows().map(r=>{
    const disabled=chooser?.type==='shared'&&!sharedAvailable(r);
    return `<button type="button" class="asb-chooser-team ${teamStatus(r.team)}" data-choose-team="${r.team}" ${disabled?'disabled':''}>
      <div class="asb-chooser-id"><b>#${r.team}</b><span>${esc(r.nickname||'')}</span></div>${metricStrip(r)}
      <div class="asb-tag-row">${tagChips(r,3)}</div><div class="asb-badges">${statusBadges(r.team)}</div>
    </button>`;
  }).join('')||'<div class="asb-empty">No matching robots.</div>';
}
function openDetail(team){
  detailTeam=Number(team);const r=trow(detailTeam);if(!r)return;
  const de=defenseEvidence(r.team)||{};
  $('#asbDetailTitle').textContent=`#${r.team} ${r.nickname||''}`;
  $('#asbDetailSubtitle').textContent=[r.city,r.state_prov,r.country].filter(Boolean).join(', ');
  const defenseHtml=de.count?`<section class="asb-explain"><h3>Defense evidence</h3><p>${de.count} tagged Spot Scouting observation${de.count===1?'':'s'}${de.tags?.length?' · '+esc(de.tags.join(', ')):''}.</p>${(de.media||[]).filter(m=>m.type==='video').slice(0,3).map(v=>`<a class="secondary compact" href="${esc(cfg.spotMedia)}?id=${v.id}" target="_blank"><i class="fa-solid fa-play"></i> ${esc(v.match||'Defense video')}</a>`).join(' ')}</section>`:'';
  el.detailBody.innerHTML=`<section class="asb-detail-summary">${metricStrip(r)}<div class="asb-tag-row large">${tagChips(r,8)||'<span class="muted">No alliance-selection tags yet.</span>'}</div><div class="asb-badges">${statusBadges(r.team)}</div></section>
    <section class="asb-explain"><h3>Why Neptune is showing this team</h3><p>Scenario fit score: <b>${candidateFit(r)}</b>. Qualification performance and Neptune EPA establish the baseline; the active alliance-needs priorities, strategy tags, favorites, and defense evidence adjust the recommendation.</p></section>${defenseHtml}`;
  el.detailActions.innerHTML=`<a class="secondary" href="${esc(cfg.robotLookup||'robot-lookup.php')}?team=${r.team}"><i class="fa-solid fa-magnifying-glass-chart"></i> Full Robot Intelligence</a>
    <a class="secondary" href="${esc(cfg.matchStrategy||'match-strategy.php')}?event_id=${cfg.eventId}"><i class="fa-solid fa-chart-line"></i> Prediction Lab</a>
    <button type="button" class="secondary" data-toggle-favorite="${r.team}"><i class="${isFavorite(r.team)?'fa-solid':'fa-regular'} fa-star"></i> ${isFavorite(r.team)?'Unfavorite':'Favorite'}</button>
    <button type="button" class="secondary" data-toggle-unavailable="${r.team}">${isUnavailable(r.team)?'Mark Available':'Unavailable to Us'}</button>
    <button type="button" class="secondary" data-toggle-dnp="${r.team}">${isDnp(r.team)?'Allow Pick':'Do Not Pick'}</button>
    <button type="button" class="secondary" data-toggle-drafted="${r.team}">${draftedInfo(r.team)?'Undo Drafted':'Drafted Elsewhere'}</button>
    <button type="button" class="secondary" data-toggle-declined="${r.team}">${isDeclined(r.team)?'Undo Declined':'Declined'}</button>
    <button type="button" class="secondary" data-toggle-broken="${r.team}">${isBroken(r.team)?'Undo Broken':'Broken'}</button>`;
  el.detail.hidden=false;
}
function closeDetail(){el.detail.hidden=true;detailTeam=0;}
function refreshOpenDetail(){if(detailTeam&&trow(detailTeam))openDetail(detailTeam);}
function postThenRefresh(action,payload){return act(action,payload).then(()=>{refreshOpenDetail();if(chooser)renderChooser();});}

document.addEventListener('click',e=>{
  const scenario=e.target.closest('[data-scenario]');
  if(scenario){act('set_planning_scenario',{scenario:scenario.dataset.scenario}).catch(console.error);return;}
  const need=e.target.closest('[data-need]');
  if(need){const next=(Number(need.dataset.needValue||0)+1)%4;act('set_planning_need',{need:need.dataset.need,value:next}).catch(console.error);return;}
  const open=e.target.closest('[data-open-team]');if(open){openDetail(Number(open.dataset.openTeam));return;}
  if(e.target.closest('[data-close-detail]')){closeDetail();return;}
  const tier=e.target.closest('[data-tier-tab]');if(tier){activeTier=tier.dataset.tierTab;renderPicks();return;}
  const shared=e.target.closest('[data-shared-choose]');if(shared){openChooser('shared',{alliance:Number(shared.dataset.alliance),slot:shared.dataset.slot});return;}
  const empty=e.target.closest('.asb-pick-slot.empty');if(empty){openChooser('pick',{tier:empty.dataset.pickTier,index:Number(empty.dataset.pickIndex)});return;}
  const choose=e.target.closest('[data-choose-team]');
  if(choose&&chooser){const t=Number(choose.dataset.chooseTeam);const p=chooser.type==='shared'?act('set_shared_slot',{alliance:chooser.alliance,slot:chooser.slot,team:t}):act('set_pick_slot',{tier:chooser.tier,index:chooser.index,team:t});p.then(closeChooser).catch(console.error);return;}
  if(e.target.closest('[data-close-chooser]')){closeChooser();return;}
  const clearShared=e.target.closest('[data-clear-shared]');
  if(clearShared){const s=clearShared.closest('[data-alliance]');act('set_shared_slot',{alliance:Number(s.dataset.alliance),slot:s.dataset.slot,team:0}).catch(console.error);return;}
  const clearPick=e.target.closest('[data-clear-pick]');
  if(clearPick){const s=clearPick.closest('[data-pick-tier]');act('set_pick_slot',{tier:s.dataset.pickTier,index:Number(s.dataset.pickIndex),team:0}).catch(console.error);return;}
  for(const [attr,action] of [
    ['data-toggle-unavailable','toggle_unavailable'],['data-toggle-dnp','toggle_dnp'],['data-toggle-favorite','toggle_favorite'],
    ['data-toggle-drafted','toggle_drafted_elsewhere'],['data-toggle-declined','toggle_declined'],['data-toggle-broken','toggle_broken']
  ]){
    const b=e.target.closest(`[${attr}]`);
    if(b){postThenRefresh(action,{team:Number(b.getAttribute(attr))}).catch(console.error);return;}
  }
});
document.addEventListener('keydown',e=>{
  const c=e.target.closest('[data-open-team]');
  if(c&&(e.key==='Enter'||e.key===' ')){e.preventDefault();openDetail(Number(c.dataset.openTeam));}
});
document.addEventListener('dragstart',e=>{
  const c=e.target.closest('[data-drag-team]');
  if(c&&e.dataTransfer){e.dataTransfer.setData('text/neptune-team',String(c.dataset.dragTeam));e.dataTransfer.effectAllowed='copyMove';}
});
document.addEventListener('dragover',e=>{
  if(e.target.closest('[data-pick-tier], [data-shared-choose], [data-drop-shared]')){e.preventDefault();e.dataTransfer.dropEffect='copy';}
});
document.addEventListener('drop',e=>{
  const pick=e.target.closest('[data-pick-tier]'),shared=e.target.closest('[data-shared-choose], [data-drop-shared]');
  const t=Number(e.dataTransfer?.getData('text/neptune-team')||0);if(t<1)return;
  if(pick){e.preventDefault();act('set_pick_slot',{tier:pick.dataset.pickTier,index:Number(pick.dataset.pickIndex),team:t}).catch(console.error);}
  else if(shared){e.preventDefault();act('set_shared_slot',{alliance:Number(shared.dataset.alliance),slot:shared.dataset.slot,team:t}).catch(console.error);}
});

el.search?.addEventListener('input',renderPool);
el.sort?.addEventListener('change',renderPool);
el.chooserSearch?.addEventListener('input',renderChooser);
$('#asbFilterTabs')?.addEventListener('click',e=>{
  const b=e.target.closest('[data-filter]');if(!b)return;poolFilter=b.dataset.filter;
  $$('#asbFilterTabs [data-filter]').forEach(x=>x.classList.toggle('active',x===b));renderPool();
});
$('#asbDraftElsewhereBtn')?.addEventListener('click',()=>{
  const t=Number($('#asbDraftElsewhereTeam').value||0);if(t)act('toggle_drafted_elsewhere',{team:t}).catch(console.error);
});
$('#asbRefreshCaptains')?.addEventListener('click',()=>act('refresh_captains').catch(e=>window.NeptuneUI?.toast?.(e.message,'warn')));
$('#asbImportProduction')?.addEventListener('click',async()=>{
  if(!window.NeptuneUI?.confirm){console.error('Neptune shared UI is unavailable.');return;}
  const ok=await window.NeptuneUI.confirm(`Import the current production Alliance Selection strategy into #${cfg.strategyTeam}'s beta workspace? This replaces only that team's BETA pick list/flags/tags.`,{title:'Import current strategy',confirmText:'Import'});
  if(ok)act('import_production_strategy').catch(console.error);
});
async function ask(message,title){
  if(!window.NeptuneUI?.confirm){console.error('Neptune shared UI is unavailable.');return false;}
  return !!(await window.NeptuneUI.confirm(message,{title,confirmText:'Reset',danger:true}));
}
$('#asbResetTeam')?.addEventListener('click',async()=>{
  if(await ask(`Reset only #${cfg.strategyTeam}'s private BETA strategy?`,'Reset team beta'))act('reset_team').catch(console.error);
});

load().catch(e=>{el.status.textContent=e.message;el.status.classList.remove('ready');});
})();