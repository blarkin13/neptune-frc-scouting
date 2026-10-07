(()=>{
  'use strict';

  function normalizeWsUrl(raw,room,params={}){
    try{
      const u=new URL(raw||'/rr-realtime',location.href);
      u.protocol=location.protocol==='https:'?'wss:':'ws:';
      u.searchParams.set('room',String(room||''));
      Object.entries(params||{}).forEach(([key,value])=>{
        if(value===undefined||value===null||value==='')u.searchParams.delete(key);
        else u.searchParams.set(key,String(value));
      });
      return u.toString();
    }catch(_){return '';}
  }

  function normalizeRelayPayload(payload){
    if(payload&&payload.type==='poke'&&payload.source){
      return {...payload,relayType:'poke',type:String(payload.source)};
    }
    return payload||{type:'poke'};
  }

  function create(options={}){
    let room=String(options.room||'');
    let socket=null;
    let stopped=false;
    let reconnectTimer=null;
    let handshakeTimer=null;
    let reconnectDelay=350;
    let ready=false;
    let transportOpen=false;
    let currentStatus='connecting';
    let lastMessageAt=0;
    let lastPayloadType='';
    let reconnects=0;
    let everConnected=false;
    let connectGeneration=0;

    const diagnostics=()=>({
      status:currentStatus,room,transportOpen,realtimeReady:ready,lastMessageAt,
      lastPayloadType,reconnects,readyState:socket?.readyState??WebSocket.CLOSED
    });

    const notifyStatus=(next=currentStatus)=>{
      currentStatus=next;
      try{options.onStatus?.(currentStatus,diagnostics());}catch(_){}
    };
    const clearHandshake=()=>{clearTimeout(handshakeTimer);handshakeTimer=null;};

    function markReady(payload){
      lastMessageAt=Date.now();
      lastPayloadType=String(payload?.type||'message');
      clearHandshake();
      if(!ready){
        ready=true;
        everConnected=true;
        reconnectDelay=350;
        notifyStatus('socket');
      }
      try{options.onTraffic?.(payload,diagnostics());}catch(_){}
    }

    async function connectionDetails(generation){
      let nextRoom=room;
      const params={};
      if(options.authUrl){
        const response=await fetch(options.authUrl,{
          method:'GET',credentials:'same-origin',cache:'no-store',
          headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
        });
        const data=await response.json().catch(()=>({}));
        if(!response.ok||data.status!=='success'||!data.room||!data.token){
          throw new Error(data.message||'Realtime authorization unavailable');
        }
        if(stopped||generation!==connectGeneration)throw new Error('stale connection attempt');
        nextRoom=String(data.room);
        params.scope=String(data.scope||'scouting');
        params.expires=String(data.expires||'');
        params.token=String(data.token||'');
      }
      const url=normalizeWsUrl(options.url,nextRoom,params);
      if(!url)throw new Error('Realtime URL unavailable');
      return {url,room:nextRoom};
    }

    async function connect(){
      if(stopped||(!room&&!options.authUrl))return;
      clearTimeout(reconnectTimer);
      const generation=++connectGeneration;
      if(!everConnected&&currentStatus!=='polling')notifyStatus('connecting');

      let details;
      try{
        details=await connectionDetails(generation);
      }catch(_){
        if(stopped||generation!==connectGeneration)return;
        notifyStatus('polling');
        scheduleReconnect();
        return;
      }
      if(stopped||generation!==connectGeneration)return;
      room=details.room;
      const connectedRoom=room;
      try{socket=new WebSocket(details.url);}catch(_){notifyStatus('polling');scheduleReconnect();return;}

      socket.addEventListener('open',()=>{
        if(stopped||generation!==connectGeneration)return;
        transportOpen=true;
        ready=false;
        if(!everConnected)notifyStatus('connecting');
        try{socket.send(JSON.stringify({type:'sync'}));}catch(_){}
        clearHandshake();
        handshakeTimer=setTimeout(()=>{
          if(stopped||generation!==connectGeneration||connectedRoom!==room||ready)return;
          notifyStatus('polling');
          try{socket?.close();}catch(_){}
        },2500);
      });

      socket.addEventListener('message',event=>{
        if(stopped||generation!==connectGeneration)return;
        let raw={type:'poke'};
        try{raw=JSON.parse(String(event.data||'{}'))||raw;}catch(_){}
        markReady(raw);
        if(raw.type==='hello')return;
        const payload=normalizeRelayPayload(raw);
        try{options.onPoke?.(payload);}catch(_){}
      });

      socket.addEventListener('close',()=>{
        if(generation!==connectGeneration)return;
        clearHandshake();transportOpen=false;ready=false;
        if(!stopped)notifyStatus('polling');
        if(connectedRoom===room)scheduleReconnect();
      });
      socket.addEventListener('error',()=>{
        if(generation!==connectGeneration)return;
        clearHandshake();transportOpen=false;ready=false;
        if(!stopped)notifyStatus('polling');
      });
    }

    function scheduleReconnect(){
      if(stopped)return;
      clearTimeout(reconnectTimer);
      reconnects++;
      reconnectTimer=setTimeout(connect,reconnectDelay);
      reconnectDelay=Math.min(4000,Math.round(reconnectDelay*1.6));
    }

    function poke(type='state',extra={}){
      if(!ready||!socket||socket.readyState!==WebSocket.OPEN)return false;
      try{socket.send(JSON.stringify({type,...extra}));return true;}catch(_){return false;}
    }

    function setRoom(nextRoom){
      const next=String(nextRoom||'');
      if(next===room&&!options.authUrl)return;
      room=next;
      ++connectGeneration;
      clearHandshake();
      try{socket?.close();}catch(_){}
      socket=null;ready=false;transportOpen=false;reconnectDelay=200;everConnected=false;
      notifyStatus((room||options.authUrl)?'connecting':'polling');
      connect();
    }

    function close(){
      stopped=true;++connectGeneration;clearTimeout(reconnectTimer);clearHandshake();
      try{socket?.close();}catch(_){}
      socket=null;ready=false;transportOpen=false;
    }

    notifyStatus('connecting');
    connect();
    return {poke,setRoom,close,isOpen:()=>ready,status:()=>currentStatus,diagnostics};
  }

  function bindStatus(target){
    const el=typeof target==='string'?document.getElementById(target):target;
    if(!el)return {set:()=>{},traffic:()=>{}};
    let trafficTimer=null;
    const label=el.querySelector('[data-realtime-label]')||el.querySelector('b')||el;
    const copy={connecting:'CONNECTING',socket:'SOCKET',polling:'POLLING',offline:'OFFLINE'};
    const descriptions={
      connecting:'Connecting to MERCURY realtime. HTTP fallback remains available.',
      socket:'Authenticated MERCURY WebSocket round-trip confirmed. Realtime notifications are active.',
      polling:'MERCURY WebSocket unavailable. Neptune is using HTTP polling and retrying automatically.',
      offline:'Neptune could not reach the realtime service or the authenticated HTTP state endpoint.'
    };
    const set=(status,diag={})=>{
      const next=['connecting','socket','polling','offline'].includes(status)?status:'polling';
      el.dataset.state=next;
      if(label)label.textContent=copy[next];
      const age=diag.lastMessageAt?Math.max(0,Math.round((Date.now()-diag.lastMessageAt)/1000)):null;
      el.title=(descriptions[next]||'')+(age!==null?` Last socket message ${age}s ago.`:'');
      el.setAttribute('aria-label','Realtime: '+copy[next]);
    };
    const traffic=(_payload,diag={})=>{
      set('socket',diag);
      el.classList.remove('is-traffic');
      void el.offsetWidth;
      el.classList.add('is-traffic');
      clearTimeout(trafficTimer);
      trafficTimer=setTimeout(()=>el.classList.remove('is-traffic'),650);
    };
    set('connecting');
    return {set,traffic};
  }

  window.NeptuneRealtime={create,bindStatus};
})();
