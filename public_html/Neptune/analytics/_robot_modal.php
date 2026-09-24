<style>
.robot-auton-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:12px 0 8px}
.robot-auton-head h4{margin:0}
.robot-auton-preview{position:relative;width:100%;overflow:hidden;border:1px solid var(--line);border-radius:8px;background:var(--panel2)}
.robot-auton-preview>img{position:absolute;inset:0;width:100%;height:100%;object-fit:fill;display:block;z-index:1}
.robot-auton-grid{position:absolute;inset:0;z-index:1;background-color:#111827;background-image:linear-gradient(rgba(255,255,255,.10) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.10) 1px,transparent 1px);background-size:8.333% 16.666%}
.robot-auton-preview svg{position:absolute;inset:0;width:100%;height:100%;display:block;z-index:2;pointer-events:none}
.robot-auton-meta{display:flex;gap:7px;align-items:center;flex-wrap:wrap;margin-top:8px}
.robot-auton-empty{padding:12px;border:1px dashed var(--line);border-radius:7px;color:var(--muted);font-size:.84rem}
@media(max-width:700px){.robot-auton-preview{min-height:180px}.robot-auton-head{align-items:flex-start}}

.robot-intel-rating-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:10px}
.robot-intel-rating{border:1px solid var(--line);border-radius:9px;background:var(--panel2);padding:10px;min-width:0}
.robot-intel-rating.primary{border-color:color-mix(in srgb,var(--accent) 45%,var(--line));background:color-mix(in srgb,var(--accent) 7%,var(--panel2))}
.robot-intel-rating.augur{border-color:color-mix(in srgb,var(--module-augur,var(--accent)) 45%,var(--line))}
.robot-intel-rating span{display:block;color:var(--muted);font-size:.66rem;text-transform:uppercase;letter-spacing:.045em;font-weight:850}
.robot-intel-rating b{display:block;margin-top:3px;font-size:1.18rem;line-height:1.1}
.robot-intel-rating small{display:block;margin-top:4px;color:var(--muted);font-size:.62rem;line-height:1.3}
.robot-intel-model-note{margin-top:9px;color:var(--muted);font-size:.7rem;line-height:1.45}
.robot-intel-model-note b{color:var(--text)}
.robot-intel-spot-summary{display:flex;gap:7px;flex-wrap:wrap;margin:9px 0}
.robot-intel-spot-chip{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--line);border-radius:999px;padding:5px 8px;background:var(--panel2);font-size:.68rem;font-weight:800}
.robot-intel-spot-chip.positive{color:var(--good)}
.robot-intel-spot-chip.warning{color:#d59b2b}
.robot-intel-spot-chip.critical{color:var(--bad)}
.robot-intel-spot-feed{display:grid;gap:8px}
.robot-intel-spot-item{border:1px solid var(--line);border-left:3px solid var(--line);border-radius:9px;background:var(--panel2);padding:9px}
.robot-intel-spot-item.warning{border-left-color:#d59b2b}
.robot-intel-spot-item.critical{border-left-color:var(--bad)}
.robot-intel-spot-item.positive{border-left-color:var(--good)}
.robot-intel-spot-head{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}
.robot-intel-spot-head b{font-size:.8rem}
.robot-intel-spot-head span{font-size:.65rem;color:var(--muted)}
.robot-intel-spot-tags{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}
.robot-intel-spot-tag{display:inline-flex;align-items:center;gap:4px;border:1px solid var(--line);border-radius:999px;padding:4px 6px;font-size:.63rem}
.robot-intel-spot-note{margin:7px 0 0;font-size:.76rem;line-height:1.4;white-space:pre-wrap}
.robot-intel-spot-meta{display:flex;gap:9px;flex-wrap:wrap;margin-top:7px;color:var(--muted);font-size:.62rem}
.robot-intel-spot-media{display:grid;grid-template-columns:repeat(auto-fill,minmax(95px,1fr));gap:7px;margin-top:8px}
.robot-intel-spot-media a,.robot-intel-spot-media .video{display:block;overflow:hidden;border:1px solid var(--line);border-radius:8px;background:var(--panel)}
.robot-intel-spot-media img,.robot-intel-spot-media video{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#05070a}
.robot-intel-spot-media .video-label{display:flex;align-items:center;gap:5px;padding:6px;color:var(--muted);font-size:.62rem}
.robot-intel-empty{padding:11px;border:1px dashed var(--line);border-radius:8px;color:var(--muted);font-size:.75rem}
.robot-intel-section-title{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.robot-intel-section-title h3{margin:0}
.robot-intel-section-title .pill{white-space:nowrap}
@media(max-width:700px){
  .robot-intel-rating-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .robot-intel-rating{padding:9px}
  .robot-intel-rating b{font-size:1.05rem}
  .robot-intel-spot-media{grid-template-columns:repeat(2,minmax(0,1fr))}
}

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
  let controller=null;
  function closeDialog(){if(dialog.open)dialog.close();if(controller)controller.abort();}
  dialog.querySelector('.robot-dialog-close').addEventListener('click',closeDialog);
  dialog.addEventListener('click',e=>{if(e.target===dialog)closeDialog();});
  dialog.addEventListener('close',()=>{if(controller)controller.abort();});
  async function openRobot(team,eventId){
    if(!team||!eventId)return;
    body.innerHTML='<div class="robot-dialog-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading robot data…</div>';
    if(!dialog.open)dialog.showModal();
    if(controller)controller.abort();controller=new AbortController();
    try{
      const opts={credentials:'same-origin',signal:controller.signal,headers:{'X-Requested-With':'XMLHttpRequest'}};
      const query='?event_id='+encodeURIComponent(eventId)+'&team='+encodeURIComponent(team);
      const [detailResult,intelResult]=await Promise.allSettled([
        fetch(endpoint+query,opts),
        fetch(intelEndpoint+query,opts)
      ]);

      if(detailResult.status!=='fulfilled')throw detailResult.reason;
      const res=detailResult.value;
      const html=await res.text();
      if(!res.ok)throw new Error(html.replace(/<[^>]*>/g,' ').trim()||'Could not load robot details.');
      body.innerHTML=html;

      if(intelResult.status==='fulfilled'){
        const intelRes=intelResult.value;
        const intelHtml=await intelRes.text();
        if(intelRes.ok&&intelHtml.trim()){
          const head=body.querySelector('.robot-modal-head');
          if(head)head.insertAdjacentHTML('afterend',intelHtml);
          else body.insertAdjacentHTML('afterbegin',intelHtml);
        }
      }

      const first=body.querySelector('h2');if(first)first.focus?.();
    }catch(err){if(err.name!=='AbortError')body.innerHTML='<div class="notice bad">'+String(err.message||'Could not load robot details.').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))+'</div>';}
  }
  document.addEventListener('click',e=>{const trigger=e.target.closest('.robot-detail-trigger');if(!trigger)return;e.preventDefault();openRobot(trigger.dataset.team,trigger.dataset.eventId);});
  window.neptuneRobotModalOpen=()=>dialog.open;
})();
</script>
