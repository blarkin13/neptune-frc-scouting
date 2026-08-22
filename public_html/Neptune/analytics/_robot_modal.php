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
      const res=await fetch(endpoint+'?event_id='+encodeURIComponent(eventId)+'&team='+encodeURIComponent(team),{credentials:'same-origin',signal:controller.signal,headers:{'X-Requested-With':'XMLHttpRequest'}});
      const html=await res.text();
      if(!res.ok)throw new Error(html.replace(/<[^>]*>/g,' ').trim()||'Could not load robot details.');
      body.innerHTML=html;
      const first=body.querySelector('h2');if(first)first.focus?.();
    }catch(err){if(err.name!=='AbortError')body.innerHTML='<div class="notice bad">'+String(err.message||'Could not load robot details.').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))+'</div>';}
  }
  document.addEventListener('click',e=>{const trigger=e.target.closest('.robot-detail-trigger');if(!trigger)return;e.preventDefault();openRobot(trigger.dataset.team,trigger.dataset.eventId);});
  window.neptuneRobotModalOpen=()=>dialog.open;
})();
</script>
