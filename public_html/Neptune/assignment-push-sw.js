/* Neptune assignment push v4: never navigate to the broken dashboard account URL. */
'use strict';
const appBase = new URL(self.registration.scope);
const scopePath = appBase.pathname.endsWith('/') ? appBase.pathname : appBase.pathname+'/';
const logo = new URL('images/logo.png',appBase).href;
self.addEventListener('install',event=>event.waitUntil(self.skipWaiting()));
self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));
self.addEventListener('push',event=>{
  let d={};try{d=event.data?event.data.json():{};}catch(_){}
  const title=typeof d.title==='string'?d.title.slice(0,120):'Neptune assignment';
  const body=typeof d.body==='string'?d.body.slice(0,400):'Open Neptune to view your assignments.';
  event.waitUntil(self.registration.showNotification(title,{
    body,tag:'neptune-assignment-'+String(d.id||'update'),icon:logo,badge:logo,
    data:{view:'assignments'}
  }));
});
self.addEventListener('notificationclick',event=>{
  event.notification.close();
  event.waitUntil((async()=>{
    const windows=await clients.matchAll({type:'window',includeUncontrolled:true});
    for(const client of windows){
      try{
        const url=new URL(client.url);
        if(url.origin===appBase.origin && url.pathname.startsWith(scopePath)){
          await client.focus();
          client.postMessage({type:'neptune-open-assignment-inbox'});
          return;
        }
      }catch(_){}
    }
    // Scouting is an existing authenticated page with the assignment panel;
    // do not use dashboard/index.php or a stale URL from notification.data.
    const target=new URL('scouting.php?neptune_assignments=1',appBase);
    await clients.openWindow(target.href);
  })());
});
