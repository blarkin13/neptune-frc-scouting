(()=>{
  'use strict';
  const cfg=window.NeptuneQaTracker;
  if(!cfg||!Number.isInteger(cfg.runId)||cfg.runId<1)return;
  let last=0;
  const send=()=>{
    if(document.visibilityState!=='visible'||!navigator.onLine||Date.now()-last<12000)return;
    last=Date.now();
    const form=new URLSearchParams({csrf:cfg.csrf,run_id:String(cfg.runId),page_kind:cfg.kind,page_path:location.pathname,match_id:String(cfg.matchId),robot:String(cfg.robot)});
    fetch(cfg.url,{method:'POST',body:form,credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},keepalive:true}).catch(()=>{});
  };
  send();setInterval(send,15000);
  window.addEventListener('focus',send);
  document.addEventListener('visibilitychange',send);
})();
