(()=>{
 'use strict';
 const tokenUrl=window.NeptunePresenceConfig?.tokenUrl;
 if(!tokenUrl||typeof WebSocket==='undefined')return;
 const listeners=new Set();let ws=null,closing=false,retry=null,attempt=0,live=false;
 const urlParams=new URLSearchParams(location.search);
 // The admin POST redirects only after the transaction has committed.
 const notifyOnSave=/\/admin\/assignment-center\.php$/i.test(location.pathname)&&urlParams.get('saved')==='1';
 let signalSent=false;
 const current=()=>window.NeptunePresenceConfig?.pageKind?.()||'other';
 function emit(data){for(const cb of [...listeners])try{cb(data);}catch(_){}}
 function send(){if(ws?.readyState===WebSocket.OPEN)ws.send(JSON.stringify({type:'presence-state',page:current(),visible:document.visibilityState==='visible'}));}
 function offline(){live=false;emit({type:'presence-offline'});}
 async function connect(){
   if(closing)return;
   try{
     const r=await fetch(tokenUrl,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
     if(!r.ok)throw new Error('auth failed');
     const a=await r.json();if(a.status!=='success'||!a.room||!a.token)throw new Error('credentials unavailable');
     const url=new URL('/rr-realtime',location.origin);
     url.protocol=location.protocol==='https:'?'wss:':'ws:';
     for(const k of ['room','scope','user_id','expires','token'])url.searchParams.set(k,String(a[k]));
     ws=new WebSocket(url);
     ws.onopen=()=>{
       attempt=0;send();
       if(notifyOnSave&&!signalSent){
         try{ws.send(JSON.stringify({type:'notification-poke'}));signalSent=true;}catch(_){}
       }
     };
     ws.onmessage=e=>{let data;try{data=JSON.parse(e.data);}catch(_){return;}if(data.type==='presence-snapshot'){live=true;emit(data);}else if(data.type==='notification-poke'){emit(data);}};
     ws.onclose=()=>{offline();if(!closing)schedule();};
     ws.onerror=()=>{};
   }catch(_){offline();schedule();}
 }
 function schedule(){if(retry||closing)return;const delay=Math.min(15000,700*Math.pow(1.6,attempt++));retry=setTimeout(()=>{retry=null;connect();},delay);}
 document.addEventListener('visibilitychange',send);
 window.addEventListener('pageshow',send);
 window.addEventListener('focus',send);
 window.addEventListener('pagehide',()=>{try{ws?.close();}catch(_){}});
 setInterval(send,15000);
 window.NeptunePresence={subscribe(fn){listeners.add(fn);return()=>listeners.delete(fn);},isLive(){return live;}};
 connect();
})();
