<style>
.robot-detail-dialog{width:min(1180px,96vw)}
.robot-dialog-body{padding:0 20px 20px}
.robot-modal-hero{display:grid;grid-template-columns:96px minmax(0,1fr) auto;gap:16px;align-items:center;padding:20px 0 17px}
.robot-modal-logo{display:grid;place-items:center;width:96px;height:96px;border:1px solid var(--line);border-radius:16px;background:var(--panel2);overflow:hidden}
.robot-modal-logo img{display:block;width:84%;height:84%;object-fit:contain}
.robot-modal-logo i{font-size:2.3rem;color:var(--muted)}
.robot-modal-kicker{color:var(--muted);font-size:.68rem;font-weight:900;text-transform:uppercase;letter-spacing:.07em}
.robot-modal-identity h2{margin:4px 0 2px;font-size:clamp(1.8rem,4vw,3rem);line-height:1.02}
.robot-modal-location{margin-top:5px;color:var(--muted);font-size:.78rem;font-weight:750}
.robot-modal-location i{margin-right:5px;color:var(--accent)}
.robot-modal-hero-stats{display:grid;grid-template-columns:repeat(2,minmax(78px,1fr));gap:8px}
.robot-modal-hero-stats>div{padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--panel2);text-align:center}
.robot-modal-hero-stats span,.robot-overview-kpi span,.robot-overview-neptune>span,.robot-defense-dashboard span,.robot-auto-rating-strip span,.robot-match-card summary>div>span,.robot-match-detail-grid span,.robot-pit-summary-grid span{display:block;color:var(--muted);font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.045em}
.robot-modal-hero-stats b{display:block;margin-top:3px;font-size:1.2rem}
.robot-modal-tabs{position:static;display:flex;gap:5px;overflow-x:auto;margin:0 -20px;padding:10px 20px;border-top:1px solid var(--line);border-bottom:1px solid var(--line);background:var(--panel)}
.robot-modal-tab{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;padding:8px 12px;border:1px solid transparent;border-radius:999px;background:transparent;color:var(--muted);font-size:.76rem;font-weight:900;text-transform:none}
.robot-modal-tab:hover{filter:none;background:var(--panel2);color:var(--text)}
.robot-modal-tab.active{border-color:color-mix(in srgb,var(--accent) 46%,var(--line));background:color-mix(in srgb,var(--accent) 12%,var(--panel2));color:var(--text)}
.robot-modal-tab i{color:var(--accent)}
.robot-tab-panel{min-height:220px}
.robot-tab-panel[hidden]{display:none!important}
.robot-modal-section{padding:18px 0;border-bottom:1px solid var(--line)}
.robot-intel-section-title{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px}
.robot-intel-section-title h3{display:flex;align-items:center;gap:8px;margin:0}
.robot-intel-section-title h3 i{color:var(--accent)}
.robot-intel-section-title .pill{white-space:nowrap}
.robot-overview-hero-grid{display:grid;grid-template-columns:1.45fr repeat(3,minmax(0,1fr));gap:9px}
.robot-overview-neptune,.robot-overview-kpi{min-width:0;padding:13px;border:1px solid var(--line);border-radius:12px;background:var(--panel2)}
.robot-overview-neptune{border-color:color-mix(in srgb,var(--module-augur,var(--accent)) 48%,var(--line));background:color-mix(in srgb,var(--module-augur,var(--accent)) 9%,var(--panel2))}
.robot-overview-neptune>b{display:block;margin-top:4px;color:var(--module-augur,var(--accent));font-size:2.25rem;line-height:1;letter-spacing:-.04em}
.robot-overview-neptune small,.robot-overview-kpi small{display:block;margin-top:6px;color:var(--muted);font-size:.67rem;line-height:1.35}
.robot-overview-neptune small.positive,.robot-overview-kpi b.up,.robot-match-card b.positive{color:var(--good)}
.robot-overview-neptune small.negative,.robot-overview-kpi b.down,.robot-match-card b.negative{color:var(--bad)}
.robot-overview-kpi>b{display:block;margin-top:5px;font-size:1.28rem;line-height:1.1}
.robot-overview-kpi b.flat{color:var(--muted)}
.robot-intel-rating-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:10px}
.robot-intel-rating{border:1px solid var(--line);border-radius:9px;background:var(--panel2);padding:10px;min-width:0}
.robot-intel-rating.primary{border-color:color-mix(in srgb,var(--accent) 45%,var(--line));background:color-mix(in srgb,var(--accent) 7%,var(--panel2))}
.robot-intel-rating span{display:block;color:var(--muted);font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;font-weight:850}
.robot-intel-rating b{display:block;margin-top:3px;font-size:1.18rem;line-height:1.1}
.robot-intel-rating small{display:block;margin-top:4px;color:var(--muted);font-size:.62rem;line-height:1.3}
.robot-intel-model-note{margin-top:9px;color:var(--muted);font-size:.7rem;line-height:1.45}
.robot-defense-dashboard{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
.robot-defense-dashboard>div,.robot-auto-rating-strip>div{padding:11px;border:1px solid var(--line);border-radius:9px;background:var(--panel2)}
.robot-defense-dashboard b,.robot-auto-rating-strip b{display:block;margin-top:4px;font-size:1.25rem}
.robot-warning-pill{color:var(--bad)!important}
.robot-jump-tab{margin-top:8px}
.robot-observed-grid{grid-template-columns:repeat(6,minmax(0,1fr))}
.robot-pit-summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.robot-pit-summary-grid>div{padding:11px;border:1px solid var(--line);border-radius:9px;background:var(--panel2)}
.robot-pit-summary-grid b{display:block;margin-top:4px;font-size:.96rem;line-height:1.35;overflow-wrap:anywhere}
.robot-pit-meta{margin-top:10px}
.robot-pit-full-report{border:1px solid var(--line);border-radius:10px;background:var(--panel2)}
.robot-pit-full-report>summary{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 13px;cursor:pointer;font-weight:900;list-style:none}
.robot-pit-full-report>summary::-webkit-details-marker{display:none}
.robot-pit-full-report>summary>span{display:flex;align-items:center;gap:8px}
.robot-pit-full-report>summary>span i{color:var(--accent)}
.robot-pit-full-report[open]>summary>i{transform:rotate(180deg)}
.robot-pit-full-body{padding:0 13px 13px;border-top:1px solid var(--line)}
.robot-auton-preview{position:relative;width:100%;overflow:hidden;border:1px solid var(--line);border-radius:10px;background:var(--panel2)}
.robot-auton-preview>img{position:absolute;inset:0;width:100%;height:100%;object-fit:fill;display:block;z-index:1}
.robot-auton-grid{position:absolute;inset:0;z-index:1;background-color:#111827;background-image:linear-gradient(rgba(255,255,255,.10) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.10) 1px,transparent 1px);background-size:8.333% 16.666%}
.robot-auton-preview svg{position:absolute;inset:0;width:100%;height:100%;display:block;z-index:2;pointer-events:none}
.robot-auton-meta{display:flex;gap:7px;align-items:center;flex-wrap:wrap;margin-top:8px}
.robot-auto-rating-strip{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:12px}
.robot-photo-strip{display:flex;gap:9px;overflow-x:auto;padding-bottom:5px;scroll-snap-type:x proximity}
.robot-photo-strip a{flex:0 0 190px;scroll-snap-align:start}
.robot-performance-chart{padding:12px;border:1px solid var(--line);border-radius:10px;background:var(--panel2)}
.robot-performance-chart svg{display:block;width:100%;height:150px;overflow:visible}
.robot-performance-chart polyline{fill:none;stroke:var(--module-augur,var(--accent));stroke-width:4;stroke-linecap:round;stroke-linejoin:round;vector-effect:non-scaling-stroke}
.robot-performance-chart .axis{stroke:var(--line);stroke-width:1;vector-effect:non-scaling-stroke}
.robot-performance-chart-labels{display:flex;justify-content:space-between;gap:12px;margin-top:6px;color:var(--muted);font-size:.68rem;font-weight:800}
.robot-match-history-v2{display:grid;gap:8px}
.robot-match-card{border:1px solid var(--line);border-radius:10px;background:var(--panel2);overflow:hidden}
.robot-match-card>summary{display:grid;grid-template-columns:minmax(175px,1.35fr) repeat(4,minmax(76px,.7fr)) 18px;gap:9px;align-items:center;padding:10px 12px;cursor:pointer;list-style:none}
.robot-match-card>summary::-webkit-details-marker{display:none}
.robot-match-card>summary>div>span{margin-bottom:2px}
.robot-match-card>summary>div>b{font-size:.94rem}
.robot-match-name{display:flex;align-items:center;gap:9px;min-width:0}
.robot-match-name b,.robot-match-name small{display:block}
.robot-match-name small{margin-top:2px;color:var(--muted);font-size:.68rem;font-weight:750}
.robot-result-badge{display:flex!important;align-items:center!important;justify-content:center!important;box-sizing:border-box;width:31px;height:31px;padding:0!important;margin:0;line-height:1!important;text-align:center;border-radius:8px;border:1px solid var(--line);background:var(--panel);font-size:.74rem;font-weight:950;color:var(--muted)}
.robot-result-badge.win{color:var(--good);border-color:color-mix(in srgb,var(--good) 55%,var(--line));background:color-mix(in srgb,var(--good) 9%,var(--panel))}
.robot-result-badge.loss{color:var(--bad);border-color:color-mix(in srgb,var(--bad) 50%,var(--line));background:color-mix(in srgb,var(--bad) 8%,var(--panel))}
.robot-result-badge.tie{color:var(--muted)}
.robot-match-chevron{color:var(--muted);transition:transform .18s ease}
.robot-match-card[open] .robot-match-chevron{transform:rotate(180deg)}
.robot-match-detail-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;padding:10px 12px 12px;border-top:1px solid var(--line);background:color-mix(in srgb,var(--panel) 55%,var(--panel2))}
.robot-match-detail-grid>div{min-width:0;padding:8px;border:1px solid var(--line);border-radius:8px;background:var(--panel2)}
.robot-match-detail-grid b{display:block;margin-top:3px;font-size:.85rem}
.robot-match-video-cell .btn{margin-top:3px;padding:5px 7px;font-size:.68rem}
.robot-intel-spot-summary{display:flex;gap:7px;flex-wrap:wrap;margin:9px 0}
.robot-intel-spot-chip{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--line);border-radius:999px;padding:5px 8px;background:var(--panel2);font-size:.68rem;font-weight:800}
.robot-intel-spot-chip.positive{color:var(--good)}
.robot-intel-spot-chip.warning{color:#d59b2b}
.robot-intel-spot-chip.critical{color:var(--bad)}
.robot-intel-spot-feed{display:grid;gap:8px}
.robot-intel-spot-item{border:1px solid var(--line);border-left:3px solid var(--line);border-radius:9px;background:var(--panel2);padding:9px}
.robot-intel-spot-item.warning{border-left-color:#d59b2b}.robot-intel-spot-item.critical{border-left-color:var(--bad)}.robot-intel-spot-item.positive{border-left-color:var(--good)}
.robot-intel-spot-head{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}.robot-intel-spot-head b{font-size:.8rem}.robot-intel-spot-head span{font-size:.65rem;color:var(--muted)}
.robot-intel-spot-tags{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}.robot-intel-spot-tag{display:inline-flex;align-items:center;gap:4px;border:1px solid var(--line);border-radius:999px;padding:4px 6px;font-size:.63rem}
.robot-intel-spot-note{margin:7px 0 0;font-size:.76rem;line-height:1.4;white-space:pre-wrap}.robot-intel-spot-meta{display:flex;gap:9px;flex-wrap:wrap;margin-top:7px;color:var(--muted);font-size:.62rem}
.robot-intel-spot-media{display:grid;grid-template-columns:repeat(auto-fill,minmax(115px,1fr));gap:7px;margin-top:8px}.robot-intel-spot-media a,.robot-intel-spot-media .video{display:block;overflow:hidden;border:1px solid var(--line);border-radius:8px;background:var(--panel)}
.robot-intel-spot-media img,.robot-intel-spot-media video{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#05070a}.robot-intel-spot-media .video-label{display:flex;align-items:center;gap:5px;padding:6px;color:var(--muted);font-size:.62rem}
.robot-intel-empty{padding:11px;border:1px dashed var(--line);border-radius:8px;color:var(--muted);font-size:.75rem}
@media(max-width:900px){.robot-modal-hero{grid-template-columns:82px minmax(0,1fr)}.robot-modal-logo{width:82px;height:82px}.robot-modal-hero-stats{grid-column:1/-1;grid-template-columns:repeat(2,1fr)}.robot-overview-hero-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.robot-overview-neptune{grid-column:1/-1}.robot-observed-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.robot-pit-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.robot-match-card>summary{grid-template-columns:minmax(150px,1.4fr) repeat(2,minmax(70px,.7fr)) 18px}.robot-match-card>summary>div:nth-of-type(4),.robot-match-card>summary>div:nth-of-type(5){display:none}.robot-match-detail-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:700px){.robot-dialog-body{padding:0 14px 14px}.robot-modal-tabs{margin:0 -14px;padding:8px 14px}.robot-intel-rating-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.robot-defense-dashboard{grid-template-columns:repeat(2,minmax(0,1fr))}.robot-auto-rating-strip{grid-template-columns:repeat(3,minmax(0,1fr))}.robot-photo-strip a{flex-basis:160px}.robot-intel-spot-media{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:520px){.robot-modal-hero{grid-template-columns:64px minmax(0,1fr);gap:11px}.robot-modal-logo{width:64px;height:64px;border-radius:12px}.robot-modal-logo i{font-size:1.7rem}.robot-modal-identity h2{font-size:1.65rem}.robot-modal-kicker{font-size:.58rem}.robot-modal-location{font-size:.7rem}.robot-modal-pills{grid-column:1/-1}.robot-overview-hero-grid{grid-template-columns:1fr}.robot-overview-neptune{grid-column:auto}.robot-observed-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.robot-pit-summary-grid{grid-template-columns:1fr}.robot-auto-rating-strip{grid-template-columns:1fr}.robot-match-card>summary{grid-template-columns:minmax(140px,1fr) minmax(64px,.55fr) 18px}.robot-match-card>summary>div:nth-of-type(3){display:none}.robot-match-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<dialog id="robotDetailDialog" class="robot-detail-dialog" aria-labelledby="robotDetailTitle">
  <div class="robot-dialog-shell">
    <div class="robot-dialog-toolbar">
      <span class="muted"><i class="fa-solid fa-robot"></i> ROBOT INTELLIGENCE</span>
      <button type="button" class="secondary robot-dialog-close" aria-label="Close robot details"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="robotDetailBody" class="robot-dialog-body"><div class="robot-dialog-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading robot data…</div></div>
  </div>
</dialog>
<script>
(()=>{
  const dialog=document.getElementById('robotDetailDialog');
  const body=document.getElementById('robotDetailBody');
  if(!dialog||!body||dialog.dataset.ready==='1')return;
  dialog.dataset.ready='1';
  const endpoint=<?=json_encode(base_url('api/robot-detail.php'))?>;
  const intelEndpoint=<?=json_encode(base_url('api/robot-modal-intel.php'))?>;
  const videoEndpoint=<?=json_encode(base_url('api/tba-match-videos.php'))?>;
  const logoEndpoint=<?=json_encode(base_url('api/team-logo.php'))?>;
  const logoCsrf=<?=json_encode(csrf_token())?>;
  let controller=null;

  function closeDialog(){if(dialog.open)dialog.close();if(controller)controller.abort();}
  dialog.querySelector('.robot-dialog-close').addEventListener('click',closeDialog);
  dialog.addEventListener('click',e=>{if(e.target===dialog)closeDialog();});
  dialog.addEventListener('close',()=>{if(controller)controller.abort();});

  function activateTab(root,name){
    root.querySelectorAll('[data-robot-tab]').forEach(btn=>{const active=btn.dataset.robotTab===name;btn.classList.toggle('active',active);btn.setAttribute('aria-selected',active?'true':'false');});
    root.querySelectorAll('[data-robot-panel]').forEach(panel=>{const active=panel.dataset.robotPanel===name;panel.hidden=!active;panel.classList.toggle('active',active);});
  }
  function wireTabs(root){
    root.querySelectorAll('[data-robot-tab]').forEach(btn=>{if(btn.dataset.robotWired==='1')return;btn.dataset.robotWired='1';btn.addEventListener('click',()=>activateTab(root,btn.dataset.robotTab||'overview'));});
    root.querySelectorAll('[data-robot-jump-tab]').forEach(btn=>{if(btn.dataset.robotWired==='1')return;btn.dataset.robotWired='1';btn.addEventListener('click',()=>{activateTab(root,btn.dataset.robotJumpTab||'overview');root.querySelector('.robot-modal-tabs')?.scrollIntoView({block:'nearest'});});});
  }
  function placeIntel(root,html){
    const tmp=document.createElement('div');tmp.innerHTML=html;
    tmp.querySelectorAll('[data-robot-intel-target]').forEach(fragment=>{
      const target=root.querySelector('[data-robot-intel-slot="'+CSS.escape(fragment.dataset.robotIntelTarget||'')+'"]');
      if(!target)return;
      target.replaceChildren(...fragment.childNodes);
    });
  }
  async function hydrateMatchVideos(root,signal){
    const cells=[...root.querySelectorAll('.robot-match-video-cell[data-tba-match-key]')];
    if(!cells.length)return;
    const byKey=new Map();
    for(const cell of cells){const key=cell.dataset.tbaMatchKey||'';if(key&&!byKey.has(key))byKey.set(key,[]);if(key)byKey.get(key).push(cell);}
    const keys=[...byKey.keys()];
    for(let i=0;i<keys.length;i+=30){
      const batch=keys.slice(i,i+30);
      try{
        const res=await fetch(videoEndpoint+'?matches='+encodeURIComponent(batch.join(',')),{credentials:'same-origin',signal,headers:{'X-Requested-With':'XMLHttpRequest'}});
        if(!res.ok)continue;const payload=await res.json();
        for(const key of batch){const url=payload?.matches?.[key]?.youtube_url||'';if(!url)continue;for(const cell of byKey.get(key)||[]){const link=cell.querySelector('.robot-match-video');if(!link)continue;link.href=url;cell.hidden=false;}}
      }catch(err){if(err?.name==='AbortError')throw err;}
    }
  }
  async function hydrateModalLogo(root,team,eventId,signal){
    const slot=root.querySelector('[data-modal-logo-slot]');if(!slot||slot.dataset.logoCached==='1')return;
    const fd=new FormData();fd.append('csrf',logoCsrf);fd.append('event_id',eventId);fd.append('team',team);fd.append('action','auto_fetch_tba');
    try{
      const response=await fetch(logoEndpoint,{method:'POST',credentials:'same-origin',signal,headers:{Accept:'application/json'},body:fd});
      const data=await response.json().catch(()=>null);if(!data?.ok||!data?.url)return;
      const img=slot.querySelector('.robot-modal-logo-img'),fallback=slot.querySelector('.robot-modal-logo-fallback');if(img){img.src=data.url;img.hidden=false;}if(fallback)fallback.hidden=true;slot.dataset.logoCached='1';
    }catch(err){if(err?.name==='AbortError')throw err;}
  }

  async function openRobot(team,eventId){
    if(!team||!eventId)return;
    body.innerHTML='<div class="robot-dialog-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading robot data…</div>';
    if(!dialog.open)dialog.showModal();
    if(controller)controller.abort();controller=new AbortController();
    try{
      const opts={credentials:'same-origin',signal:controller.signal,headers:{'X-Requested-With':'XMLHttpRequest'}};
      const query='?event_id='+encodeURIComponent(eventId)+'&team='+encodeURIComponent(team);
      const [detailResult,intelResult]=await Promise.allSettled([fetch(endpoint+query,opts),fetch(intelEndpoint+query,opts)]);
      if(detailResult.status!=='fulfilled')throw detailResult.reason;
      const res=detailResult.value;const html=await res.text();if(!res.ok)throw new Error(html.replace(/<[^>]*>/g,' ').trim()||'Could not load robot details.');
      body.innerHTML=html;wireTabs(body);body.scrollTop=0;
      const videoPromise=hydrateMatchVideos(body,controller.signal);
      const logoPromise=hydrateModalLogo(body,team,eventId,controller.signal);
      if(intelResult.status==='fulfilled'){
        const intelRes=intelResult.value;const intelHtml=await intelRes.text();
        if(intelRes.ok&&intelHtml.trim()){placeIntel(body,intelHtml);wireTabs(body);}
        else body.querySelectorAll('[data-robot-intel-slot]').forEach(slot=>{if(slot.textContent.includes('Loading'))slot.innerHTML='<div class="robot-intel-empty">Additional AUGUR intelligence is unavailable.</div>';});
      }else{
        body.querySelectorAll('[data-robot-intel-slot]').forEach(slot=>{if(slot.textContent.includes('Loading'))slot.innerHTML='<div class="robot-intel-empty">Additional AUGUR intelligence is unavailable.</div>';});
      }
      await Promise.allSettled([videoPromise,logoPromise]);
    }catch(err){if(err.name!=='AbortError')body.innerHTML='<div class="notice bad">'+String(err.message||'Could not load robot details.').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))+'</div>';}
  }
  document.addEventListener('click',e=>{const trigger=e.target.closest('.robot-detail-trigger');if(!trigger)return;e.preventDefault();openRobot(trigger.dataset.team,trigger.dataset.eventId);});
  window.neptuneRobotModalOpen=()=>dialog.open;
})();
</script>
