'use strict';
// Scoring areas: queue field-detector training on the connected helper and show its state.
(()=>{
 const app=document.getElementById('field-regions-app'),button=document.getElementById('trainfield');if(!app||!button)return;
 const list=document.getElementById('fieldrequests'),current=document.getElementById('fieldcurrent'),status=document.getElementById('status');
 async function refresh(){try{const d=await (await fetch('scoring-areas.php?field_status=1')).json();list.innerHTML='';
  for(const q of (d.requests||[]).slice(0,4)){const row=document.createElement('div');row.className='fr-request '+q.state;
   row.textContent=`Train field detector — ${q.state==='queued'?'waiting for a helper':q.state==='claimed'?'running':q.state}${q.message?': '+q.message:''}`;list.append(row)}
  const c=d.current,r=c&&c.report;current.textContent=c?`Current field detector adopted ${new Date(c.adopted_at*1000).toLocaleDateString()}${r&&r.new?` · held-out ${r.holdout_match}: hubs/corners within ${(r.new.mean_error*100).toFixed(1)}% of frame width, ${r.new.found}/${r.new.expected} found`:''}`:'Using the field detector shipped with the helper.'}catch(e){}}
 button.addEventListener('click',async()=>{try{const r=await fetch('scoring-areas.php?queue=train_field',{method:'POST',headers:{'X-Field-CSRF':app.dataset.cloudCsrf}});const d=await r.json();
  if(!r.ok)throw Error(d.error||'Could not queue');status.textContent='Field detector training queued for your helper.';refresh()}catch(e){status.textContent=e.message}});
 refresh();setInterval(refresh,15000);
})();
