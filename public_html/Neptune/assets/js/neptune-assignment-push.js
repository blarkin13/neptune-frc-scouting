(()=>{'use strict';
 const cfg=window.NeptuneAssignmentNotificationsConfig;
 if(!cfg?.csrf||!cfg?.userId)return;
 const area=document.querySelector('.neptune-alert-toolbar');if(!area)return;
 const button=document.createElement('button');button.type='button';button.className='neptune-alert-push';button.textContent='Enable phone notifications';area.append(button);
 const status=document.createElement('span');status.className='neptune-alert-push-status';status.setAttribute('aria-live','polite');area.append(status);
 const supported=window.isSecureContext&&'serviceWorker' in navigator&&'PushManager' in window&&'Notification' in window;
 if(!supported){button.disabled=true;status.textContent='Push unavailable in this browser';return;}
 // Keep the service worker scope narrow. Registration on click avoids unrelated-page conflicts.
 const currentScript=Array.from(document.scripts).find(s=>{
   try{return new URL(s.src,location.href).pathname.endsWith('/assets/js/neptune-assignment-push.js');}catch(_){return false;}
 });
 const assetPath=currentScript?new URL(currentScript.src,location.href).pathname:null;
 const appPath=assetPath?assetPath.slice(0,-'/assets/js/neptune-assignment-push.js'.length)+'/':new URL(cfg.pushBase,location.origin).pathname;
 const workerBase=new URL(appPath,location.origin);
 const swUrl=new URL('assignment-push-sw.js',workerBase).href;
 const keyUrl=cfg.pushEndpoint;
 // Refresh an existing subscribed worker on every authenticated page load.
 // Merely installing new JavaScript does not otherwise replace its old click handler.
 navigator.serviceWorker.getRegistrations()
   .then(regs=>Promise.all(regs.filter(reg=>{
     const script=reg.active?.scriptURL||reg.waiting?.scriptURL||reg.installing?.scriptURL||'';
     try{return new URL(script).pathname.endsWith('/assignment-push-sw.js');}catch(_){return false;}
   }).map(reg=>reg.update().catch(()=>{})))).catch(()=>{});
 function decodeKey(value){const pad='='.repeat((4-value.length%4)%4);const raw=atob((value.replace(/-/g,'+').replace(/_/g,'/'))+pad);return Uint8Array.from(raw,c=>c.charCodeAt(0));}
 async function registration(){
   const reg=await navigator.serviceWorker.register(swUrl,{scope:appPath});
   if(reg.active)return reg;
   const worker=reg.installing||reg.waiting;
   if(!worker)throw new Error('Service worker not active; reload Neptune and try again');
   await new Promise((resolve,reject)=>{
     const timeout=setTimeout(()=>{worker.removeEventListener('statechange',onState);reject(new Error('Service worker activation timed out'));},10000);
     function onState(){
       if(reg.active||worker.state==='activated'){clearTimeout(timeout);worker.removeEventListener('statechange',onState);resolve();}
       else if(worker.state==='redundant'){clearTimeout(timeout);worker.removeEventListener('statechange',onState);reject(new Error('Service worker failed to activate'));}
     }
     worker.addEventListener('statechange',onState);onState();
   });
   return reg;
 }
 async function stored(){const r=await navigator.serviceWorker.getRegistration(appPath);return r?await r.pushManager.getSubscription():null;}
 async function sync(){const s=await stored();button.textContent=s?'Disable phone notifications':'Enable phone notifications';}
 async function send(action,sub){const response=await fetch(keyUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:cfg.csrf,action,subscription:JSON.stringify(sub.toJSON())})});if(!response.ok)throw new Error('Could not update this device');}
 button.addEventListener('click',async()=>{button.disabled=true;status.textContent='';try{
   const existing=await stored();if(existing){await send('unsubscribe',existing);await existing.unsubscribe();status.textContent='Phone notifications disabled';}
   else{
     if(Notification.permission==='denied')throw new Error('Allow notifications in your browser settings');
     const keyResponse=await fetch(keyUrl,{credentials:'same-origin',cache:'no-store'});const config=await keyResponse.json();
     if(config.status!=='success')throw new Error(config.message||'Push server not configured');
     const permission=await Notification.requestPermission();if(permission!=='granted')throw new Error('Notification permission not granted');
     const reg=await registration();const sub=await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:decodeKey(config.publicKey)});
     try{await send('subscribe',sub);}catch(e){await sub.unsubscribe();throw e;}
     status.textContent='Phone notifications enabled';
   }
   await sync();
 }catch(e){status.textContent=e.message||'Unable to configure notifications';}finally{button.disabled=false;}});
 sync().catch(()=>{});
})();
