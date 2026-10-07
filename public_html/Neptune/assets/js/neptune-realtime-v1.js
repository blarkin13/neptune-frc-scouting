(()=>{
  'use strict';

  function normalizeWsUrl(raw,room){
    try{
      const u=new URL(raw||'/rr-realtime',location.href);
      u.protocol=location.protocol==='https:'?'wss:':'ws:';
      u.searchParams.set('room',String(room||''));
      return u.toString();
    }catch(_){return '';}
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

    function connect(){
      if(stopped||!room)return;
      clearTimeout(reconnectTimer);
      const url=normalizeWsUrl(options.url,room);
      if(!url){notifyStatus('polling');return;}
      const connectedRoom=room;
      if(!everConnected&&currentStatus!=='polling')notifyStatus('connecting');
      try{socket=new WebSocket(url);}catch(_){notifyStatus('polling');scheduleReconnect();return;}

      socket.addEventListener('open',()=>{
        transportOpen=true;
        ready=false;
        if(!everConnected)notifyStatus('connecting');
        try{socket.send(JSON.stringify({type:'sync'}));}catch(_){}
        clearHandshake();
        handshakeTimer=setTimeout(()=>{
          if(stopped||connectedRoom!==room||ready)return;
          notifyStatus('polling');
          try{socket?.close();}catch(_){}
        },2500);
      });

      socket.addEventListener('message',event=>{
        let payload={type:'poke'};
        try{payload=JSON.parse(String(event.data||'{}'))||payload;}catch(_){}
        markReady(payload);
        if(payload.type==='hello')return;
        try{options.onPoke?.(payload);}catch(_){}
      });

      socket.addEventListener('close',()=>{
        clearHandshake();transportOpen=false;ready=false;
        if(!stopped)notifyStatus('polling');
        if(connectedRoom===room)scheduleReconnect();
      });
      socket.addEventListener('error',()=>{
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
      if(next===room)return;
      room=next;
      clearHandshake();
      try{socket?.close();}catch(_){}
      socket=null;ready=false;transportOpen=false;reconnectDelay=200;everConnected=false;
      notifyStatus(room?'connecting':'polling');
      connect();
    }

    function close(){
      stopped=true;clearTimeout(reconnectTimer);clearHandshake();
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
      socket:'MERCURY WebSocket round-trip confirmed. Realtime notifications are active.',
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
