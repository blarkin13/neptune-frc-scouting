<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
require_once __DIR__.'/_alliance_helpers.php';
require_once __DIR__.'/_team_logo_cache.php';

$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];
$events=neptune_event_list($pdo,$org);
$eventParam=array_key_exists('event_id',$_GET)?(int)$_GET['event_id']:null;
$eventId=$eventParam===null?(int)($events[0]['id']??0):max(0,$eventParam);
$event=$eventId?alliance_event($pdo,$org,$eventId):null;
$currentSeason=(int)date('Y');
$globalSeason=max(1992,min($currentSeason+1,(int)($_GET['season']??$currentSeason)));
$season=(int)($event['season_year']??$globalSeason);
$isGlobal=$eventId===0;
$teams=[];
if($event){
    $s=$pdo->prepare('SELECT frc_team_number,nickname FROM event_teams WHERE event_id=? ORDER BY frc_team_number');
    $s->execute([$eventId]);
    $teams=$s->fetchAll();
}
$numbers=array_map(static fn($r)=>(int)$r['frc_team_number'],$teams);
$logos=neptune_team_logo_map($org,$season,$numbers);
$cachedCount=count(array_filter($logos,static fn($x)=>!empty($x['exists'])));
$pageTitle='Team Logo Cache';
$moduleName='AUGUR';
include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between;align-items:flex-start">
  <div>
    <div class="module-eyebrow"><span>AUGUR</span><small>Robot Intelligence</small></div>
    <h1 style="margin-bottom:5px">Team Logo Cache</h1>
    <div class="muted">Team logos are saved as persistent local Neptune files and reused everywhere Neptune displays a robot. Download All fills missing logos for the selected event. Choose All TBA teams to build a season-wide local logo library without selecting an event, so Robot Cards, Robot Lookup, Alliance Selection, and offline workflows can reuse the same files.</div>
  </div>
  <a class="btn secondary" href="<?=e(base_url('analytics/robots.php').($eventId?'?'.http_build_query(['event_id'=>$eventId]):''))?>"><i class="fa-solid fa-arrow-left"></i> Robot Cards</a>
</div>

<div class="card logo-cache-toolbar">
  <form method="get" class="logo-event-select" id="logoScopeForm">
    <div><label>Logo scope</label><select name="event_id" id="logoScopeSelect" onchange="this.form.submit()">
      <option value="0" <?=$isGlobal?'selected':''?>>All TBA teams</option>
      <?=neptune_event_options_html($events,$eventId)?>
    </select></div>
    <?php if($isGlobal):?>
      <div><label>Season</label><select name="season" onchange="this.form.submit()">
        <?php for($y=$currentSeason+1;$y>=2018;$y--):?>
          <option value="<?=$y?>" <?=$globalSeason===$y?'selected':''?>><?=$y?></option>
        <?php endfor;?>
      </select></div>
    <?php endif;?>
  </form>
  <?php if($isGlobal):?>
    <div class="logo-cache-summary global-logo-summary"><b id="cachedCount">—</b><span id="globalSummaryText">season-wide cache</span><small><?=$globalSeason?> · persistent across events</small></div>
  <?php else:?>
    <div class="logo-cache-summary"><b id="cachedCount"><?=$cachedCount?></b><span>of <?=count($teams)?> cached</span><small>persistent across seasons</small></div>
  <?php endif;?>
  <div class="logo-cache-actions">
    <button type="button" class="secondary" id="downloadAll" title="<?=$isGlobal?'Download every TBA team avatar available for the selected season':'Download every missing logo for this event into Neptune\'s persistent cache'?>"><i class="fa-solid fa-cloud-arrow-down"></i> <?=$isGlobal?'Download All TBA Logos':'Download All'?></button>
    <?php if(!$isGlobal):?><button type="button" class="secondary" id="refreshAll"><i class="fa-solid fa-rotate"></i> Refresh All from TBA</button><?php endif;?>
    <button type="button" class="secondary" id="upscaleAll"><i class="fa-solid fa-up-right-and-down-left-from-center"></i> Upscale Cached to 1000px</button>
    <button type="button" class="secondary" id="stopGlobalSync" hidden><i class="fa-solid fa-stop"></i> Stop</button>
  </div>
</div>

<div id="logoSyncStatus" class="notice" hidden></div>

<?php if($isGlobal):?>
<div class="card global-logo-mode">
  <div class="global-logo-icon"><i class="fa-solid fa-earth-americas"></i></div>
  <div>
    <h2>All TBA teams · <?=$globalSeason?></h2>
    <p class="muted">Neptune will page through The Blue Alliance team directory for <?=$globalSeason?> and cache every team avatar TBA provides for that season. Existing custom or cached logos are skipped automatically, so restarting the download safely resumes by rechecking the library.</p>
    <div class="pill-row"><span class="pill"><i class="fa-solid fa-database"></i> Persistent local cache</span><span class="pill"><i class="fa-solid fa-shield-halved"></i> Custom logos preserved</span><span class="pill"><i class="fa-solid fa-wifi"></i> Keep this tab open while syncing</span></div>
  </div>
</div>
<?php endif;?>

<div class="team-logo-grid" id="teamLogoGrid">
<?php foreach($teams as $row):
  $team=(int)$row['frc_team_number'];$info=$logos[$team]??['exists'=>false];
  $source=(string)($info['source']??'');
  $sourceLabel=$source==='custom'?'Custom':($source==='tba'?'TBA'.(!empty($info['source_year'])?' '.(int)$info['source_year']:''):'Not cached');
?>
  <article class="team-logo-card" data-team="<?=$team?>" data-cached="<?=!empty($info['exists'])?'1':'0'?>" data-source="<?=e($source)?>">
    <div class="team-logo-preview">
      <img class="team-logo-img" <?=empty($info['exists'])?'hidden':''?> src="<?=!empty($info['exists'])?e(base_url($info['path']).'?v='.rawurlencode((string)$info['version'])):''?>" alt="Team <?=$team?> logo">
      <i class="fa-solid fa-robot team-logo-fallback" <?=!empty($info['exists'])?'hidden':''?>></i>
    </div>
    <div class="team-logo-copy">
      <div class="team-logo-title"><b>#<?=$team?></b><span><?=e((string)($row['nickname']?:'FRC Team '.$team))?></span></div>
      <div class="team-logo-source"><span class="pill logo-source-pill"><i class="fa-solid <?=($source==='custom'?'fa-pen-ruler':($source==='tba'?'fa-bolt':'fa-circle-question'))?>"></i> <span class="logo-source-text"><?=e($sourceLabel)?></span></span></div>
    </div>
    <div class="team-logo-buttons">
      <button type="button" class="secondary compact tba-refresh"><i class="fa-solid fa-rotate"></i> <?=!empty($info['exists'])?'Refresh TBA':'Get TBA Logo'?></button>
      <label class="btn secondary compact upload-label"><i class="fa-solid fa-upload"></i> Replace<input class="logo-upload" type="file" accept="image/jpeg,image/png,image/webp,image/avif,image/heic,image/heif" hidden></label>
      <?php if(!empty($info['exists'])):?><button type="button" class="secondary compact remove-logo" title="Remove the local logo"><i class="fa-solid fa-trash"></i></button><?php endif;?>
    </div>
    <div class="team-logo-message muted"></div>
  </article>
<?php endforeach;?>
</div>

<style>
.logo-cache-toolbar{display:grid;grid-template-columns:minmax(320px,1fr) auto auto;gap:18px;align-items:end}.logo-event-select{display:flex;gap:12px;align-items:end}.logo-event-select>div{min-width:min(360px,44vw)}.global-logo-mode{display:grid;grid-template-columns:auto 1fr;gap:16px;align-items:start;margin-top:16px}.global-logo-mode h2{margin:0 0 5px}.global-logo-mode p{margin:0}.global-logo-icon{width:54px;height:54px;border-radius:12px;display:grid;place-items:center;background:color-mix(in srgb,var(--accent) 12%,var(--panel));border:1px solid color-mix(in srgb,var(--accent) 35%,var(--line));font-size:1.4rem;color:var(--accent)}.pill-row{display:flex;gap:7px;flex-wrap:wrap;margin-top:12px}.logo-event-select label{margin-top:0}.logo-cache-summary{display:flex;align-items:baseline;gap:7px;white-space:nowrap}.logo-cache-summary b{font-size:1.7rem}.logo-cache-summary span{font-weight:850}.logo-cache-summary small{color:var(--muted);margin-left:5px}.logo-cache-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.team-logo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;margin-top:16px}.team-logo-card{background:var(--panel);border:1px solid var(--line);border-radius:9px;padding:14px;box-shadow:0 10px 24px var(--shadow);display:grid;grid-template-columns:82px minmax(0,1fr);gap:12px;align-items:center}.team-logo-preview{width:82px;height:82px;border:1px solid var(--line);border-radius:9px;background:#fff;display:grid;place-items:center;overflow:hidden;color:#687481;font-size:1.8rem}.team-logo-img{width:100%;height:100%;object-fit:contain;padding:6px}.team-logo-title{display:flex;align-items:baseline;gap:8px;min-width:0}.team-logo-title b{font-size:1.35rem}.team-logo-title span{font-weight:750;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.team-logo-source{margin-top:6px}.team-logo-buttons{grid-column:1/-1;display:flex;gap:7px;flex-wrap:wrap}.team-logo-buttons .compact{padding:8px 10px;font-size:.84rem}.upload-label{cursor:pointer}.team-logo-message{grid-column:1/-1;min-height:1.2em;font-size:.82rem}.team-logo-card.busy{opacity:.68;pointer-events:none}.team-logo-card.error{border-color:color-mix(in srgb,var(--bad) 62%,var(--line))}.team-logo-card.success{border-color:color-mix(in srgb,var(--good) 56%,var(--line))}
@media(max-width:850px){.logo-cache-toolbar{grid-template-columns:1fr}.logo-cache-actions{justify-content:flex-start}.logo-event-select{flex-wrap:wrap}.logo-event-select>div{min-width:min(100%,360px)}}
@media(max-width:480px){.global-logo-mode{grid-template-columns:1fr}.team-logo-grid{grid-template-columns:1fr}.team-logo-card{grid-template-columns:68px minmax(0,1fr)}.team-logo-preview{width:68px;height:68px}}
</style>

<script>
(()=>{
  const api=<?=json_encode(base_url('api/team-logo.php'))?>;
  const eventId=<?=json_encode($eventId)?>;
  const season=<?=json_encode($season)?>;
  const isGlobal=<?=json_encode($isGlobal)?>;
  const csrf=<?=json_encode(csrf_token())?>;
  const grid=document.getElementById('teamLogoGrid');
  const status=document.getElementById('logoSyncStatus');
  const countEl=document.getElementById('cachedCount');
  const summaryText=document.getElementById('globalSummaryText');
  const stopBtn=document.getElementById('stopGlobalSync');
  let globalStop=false;
  const cards=grid?[...grid.querySelectorAll('.team-logo-card')]:[];
  function setBusy(card,on){if(!card)return;card.classList.toggle('busy',on);card.classList.remove('error','success');}
  function updateCount(){if(countEl&&!isGlobal)countEl.textContent=cards.filter(c=>c.dataset.cached==='1').length;}
  function updateCard(card,data){
    if(!card)return;
    const img=card.querySelector('.team-logo-img'),fallback=card.querySelector('.team-logo-fallback');
    if(data?.url){img.src=data.url;img.hidden=false;fallback.hidden=true;card.dataset.cached='1';card.dataset.source=data?.logo?.source||'';}
    else if(data?.status==='removed'||data?.status==='missing'){img.hidden=true;img.removeAttribute('src');fallback.hidden=false;card.dataset.cached='0';card.dataset.source='';}
    const source=data?.logo?.source||'';
    const sy=data?.logo?.source_year||'';
    const label=source==='custom'?'Custom':source==='tba'?('TBA'+(sy?' '+sy:'')):'Not cached';
    const sourceText=card.querySelector('.logo-source-text');if(sourceText)sourceText.textContent=label;
    const msg=card.querySelector('.team-logo-message');if(msg)msg.textContent=data?.message||'';
    card.classList.add(data?.ok?'success':'error');
    updateCount();
  }
  async function apiPost(fields){
    const fd=new FormData();fd.append('csrf',csrf);
    Object.entries(fields).forEach(([k,v])=>fd.append(k,String(v)));
    const r=await fetch(api,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:fd});
    return await r.json().catch(()=>({ok:false,message:'Invalid server response.'}));
  }
  async function post(card,action,file=null){
    setBusy(card,true);const team=card.dataset.team;const fd=new FormData();
    fd.append('csrf',csrf);fd.append('event_id',eventId);fd.append('season',season);fd.append('team',team);fd.append('action',action);if(file)fd.append('logo',file);
    try{
      const r=await fetch(api,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:fd});
      const d=await r.json().catch(()=>({ok:false,message:'Invalid server response.'}));
      updateCard(card,d);return d;
    }catch(e){const d={ok:false,message:'Could not reach Neptune.'};updateCard(card,d);return d;}
    finally{setBusy(card,false);}
  }
  grid?.addEventListener('click',e=>{
    const card=e.target.closest('.team-logo-card');if(!card)return;
    if(e.target.closest('.tba-refresh'))post(card,'fetch_tba');
    if(e.target.closest('.remove-logo'))post(card,'remove');
  });
  grid?.addEventListener('change',e=>{
    const input=e.target.closest('.logo-upload');if(!input||!input.files?.[0])return;
    const card=input.closest('.team-logo-card');post(card,'upload',input.files[0]).finally(()=>{input.value='';});
  });
  async function bulk(cardsToRun,label){
    if(!cardsToRun.length){status.hidden=false;status.className='notice good';status.textContent='All event logos are already downloaded to Neptune and will be reused everywhere.';return;}
    status.hidden=false;status.className='notice';let done=0,cached=0,missing=0,failed=0;
    for(const card of cardsToRun){
      status.textContent=label+' '+(done+1)+' of '+cardsToRun.length+' · Team '+card.dataset.team;
      const d=await post(card,'fetch_tba');done++;
      if(d?.ok&&d?.url)cached++;else if(d?.ok&&d?.status==='missing')missing++;else {failed++;if((d?.message||'').includes('Blue Alliance'))break;}
    }
    status.className='notice '+(failed?'bad':'good');
    status.textContent='Logo download finished: '+cached+' cached, '+missing+' without a TBA avatar'+(failed?', '+failed+' failed.':'.');
  }
  async function discoverGlobalTeams(){
    const teams=[];let page=0;
    while(!globalStop){
      status.textContent='Loading TBA team directory · page '+(page+1)+'…';
      const d=await apiPost({action:'list_tba_teams',event_id:0,season,page});
      if(!d?.ok)throw new Error(d?.message||'Could not load TBA team directory.');
      const rows=Array.isArray(d.teams)?d.teams:[];
      if(!rows.length)break;
      for(const row of rows){const n=Number(row.team||0);if(n>0)teams.push(n);}
      page++;
      if(page>100)break;
    }
    return [...new Set(teams)];
  }
  async function fetchGlobalTeam(team){
    try{return await apiPost({action:'fetch_tba_global',event_id:0,season,team});}
    catch(e){return {ok:false,message:'Could not reach Neptune.'};}
  }
  async function runGlobalDownload(){
    globalStop=false;stopBtn.hidden=false;
    const button=document.getElementById('downloadAll');button.disabled=true;
    status.hidden=false;status.className='notice';
    let cached=0,missing=0,failed=0,done=0;
    try{
      const teams=await discoverGlobalTeams();
      if(globalStop)throw new Error('Stopped.');
      if(!teams.length)throw new Error('TBA did not return any teams for '+season+'.');
      if(countEl)countEl.textContent='0 / '+teams.length;
      if(summaryText)summaryText.textContent='processed';
      status.textContent='Found '+teams.length+' TBA teams for '+season+'. Starting logo download…';
      const concurrency=3;let cursor=0;
      const worker=async()=>{
        while(!globalStop){
          const idx=cursor++;if(idx>=teams.length)return;
          const team=teams[idx];
          const d=await fetchGlobalTeam(team);done++;
          if(d?.ok&&d?.url)cached++;else if(d?.ok&&d?.status==='missing')missing++;else failed++;
          if(countEl)countEl.textContent=done+' / '+teams.length;
          status.textContent='Caching TBA logos · '+done+' of '+teams.length+' · cached '+cached+' · no avatar '+missing+(failed?' · failed '+failed:'');
          if(!d?.ok&&(d?.message||'').includes('Blue Alliance'))globalStop=true;
        }
      };
      await Promise.all(Array.from({length:concurrency},()=>worker()));
      status.className='notice '+(failed?'bad':'good');
      if(globalStop){
        status.textContent='Global logo sync stopped at '+done+' of '+teams.length+'. Restarting Download All is safe: already-cached logos will be skipped.';
      }else{
        status.textContent='Global logo sync finished for '+season+': '+cached+' cached/reused, '+missing+' without a TBA avatar'+(failed?', '+failed+' failed.':'.');
      }
    }catch(e){
      status.className='notice '+(String(e?.message||'')==='Stopped.'?'':'bad');
      status.textContent=String(e?.message||'Global logo sync could not continue.');
    }finally{button.disabled=false;stopBtn.hidden=true;}
  }
  async function runUpscaleAll(){
    const btn=document.getElementById('upscaleAll');if(!btn)return;
    btn.disabled=true;globalStop=false;stopBtn.hidden=false;status.hidden=false;status.className='notice';
    let done=0,upscaled=0,large=0,unsupported=0,failed=0;
    try{
      const list=await apiPost({action:'list_cached_logos',event_id:0,season});
      if(!list?.ok)throw new Error(list?.message||'Could not read the cached logo library.');
      const teams=Array.isArray(list.teams)?list.teams:[];
      if(!teams.length)throw new Error('There are no cached logos to upscale yet.');
      status.textContent='Found '+teams.length+' cached logos. Upscaling anything under 1000px wide…';
      const concurrency=2;let cursor=0;
      const worker=async()=>{
        while(!globalStop){
          const idx=cursor++;if(idx>=teams.length)return;
          const team=teams[idx];
          const d=await apiPost({action:'upscale_cached_logo',event_id:0,season,team});done++;
          if(d?.status==='upscaled')upscaled++;else if(d?.status==='already_large')large++;else if(d?.status==='unsupported')unsupported++;else failed++;
          status.textContent='Upscaling cached logos · '+done+' of '+teams.length+' · '+upscaled+' upscaled · '+large+' already large'+(unsupported?' · '+unsupported+' unsupported':'')+(failed?' · '+failed+' failed':'');
        }
      };
      await Promise.all(Array.from({length:concurrency},()=>worker()));
      status.className='notice '+(failed?'bad':'good');
      status.textContent=(globalStop?'Upscale stopped. ':'Upscale finished. ')+upscaled+' enlarged to 1000px wide, '+large+' already at least 1000px'+(unsupported?', '+unsupported+' unsupported format':'')+(failed?', '+failed+' failed.':'.');
    }catch(e){status.className='notice bad';status.textContent=String(e?.message||'Logo upscale failed.');}
    finally{btn.disabled=false;stopBtn.hidden=true;}
  }
  document.getElementById('upscaleAll')?.addEventListener('click',runUpscaleAll);
  document.getElementById('downloadAll')?.addEventListener('click',()=>isGlobal?runGlobalDownload():bulk(cards.filter(c=>c.dataset.cached!=='1'),'Downloading event logos'));
  document.getElementById('refreshAll')?.addEventListener('click',()=>bulk(cards.filter(c=>c.dataset.source!=='custom'),'Refreshing TBA logos'));
  stopBtn?.addEventListener('click',()=>{globalStop=true;stopBtn.disabled=true;setTimeout(()=>{stopBtn.disabled=false;},800);});
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
