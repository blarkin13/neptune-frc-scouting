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
    let opened=false;
    let transportOpen=false;
    let currentStatus='connecting';
    let lastMessageAt=0;
    let lastPayloadType='';
    let reconnects=0;
    let everConnected=false;

    const diagnostics=()=>({
      status:currentStatus,
      room,
      transportOpen,
      realtimeReady:opened,
      lastMessageAt,
      lastPayloadType,
      reconnects,
      readyState:socket?.readyState??WebSocket.CLOSED
    });

    const notifyStatus=(next=currentStatus)=>{
      currentStatus=next;
      try{options.onStatus?.(currentStatus,diagnostics());}catch(_){}
    };

    const clearHandshake=()=>{clearTimeout(handshakeTimer);handshakeTimer=null;};

    function markRealtimeReady(payload){
      lastMessageAt=Date.now();
      lastPayloadType=String(payload?.type||'message');
      clearHandshake();
      if(!opened){opened=true;everConnected=true;reconnectDelay=350;notifyStatus('socket');}
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
        opened=false;
        if(!everConnected)notifyStatus('connecting');
        try{socket.send(JSON.stringify({type:'sync'}));}catch(_){}
        clearHandshake();
        handshakeTimer=setTimeout(()=>{
          if(stopped||connectedRoom!==room||opened)return;
          notifyStatus('polling');
          try{socket?.close();}catch(_){}
        },2500);
      });
      socket.addEventListener('message',event=>{
        let payload={type:'poke'};
        try{payload=JSON.parse(String(event.data||'{}'))||payload;}catch(_){}
        markRealtimeReady(payload);
        if(payload.type==='hello')return;
        try{options.onPoke?.(payload);}catch(_){}
      });
      socket.addEventListener('close',()=>{
        clearHandshake();transportOpen=false;opened=false;
        if(!stopped)notifyStatus('polling');
        if(connectedRoom===room)scheduleReconnect();
      });
      socket.addEventListener('error',()=>{
        clearHandshake();transportOpen=false;opened=false;
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
      if(!opened||!socket||socket.readyState!==WebSocket.OPEN)return false;
      try{socket.send(JSON.stringify({type,...extra}));return true;}catch(_){return false;}
    }

    function setRoom(nextRoom){
      const next=String(nextRoom||'');
      if(next===room)return;
      room=next;
      clearHandshake();
      try{socket?.close();}catch(_){}
      socket=null;opened=false;transportOpen=false;reconnectDelay=200;everConnected=false;
      notifyStatus(room?'connecting':'polling');
      connect();
    }

    function close(){
      stopped=true;clearTimeout(reconnectTimer);clearHandshake();
      try{socket?.close();}catch(_){}
      socket=null;opened=false;transportOpen=false;
    }

    notifyStatus('connecting');
    connect();
    return {poke,setRoom,close,isOpen:()=>opened,status:()=>currentStatus,diagnostics};
  }

  function bindStatus(target){
    const el=typeof target==='string'?document.getElementById(target):target;
    if(!el)return {set:()=>{},traffic:()=>{}};
    let trafficTimer=null;
    const label=el.querySelector('[data-rr-realtime-label]')||el.querySelector('b');
    const copy={connecting:'CONNECTING',socket:'SOCKET',polling:'POLLING'};
    const descriptions={
      connecting:'Connecting to Neptune realtime. HTTP polling remains available.',
      socket:'WebSocket round-trip confirmed. Realtime room updates are active.',
      polling:'WebSocket is unavailable. Fast HTTP polling is active while Neptune retries automatically.'
    };
    const set=(status,diag={})=>{
      const next=['connecting','socket','polling'].includes(status)?status:'polling';
      el.dataset.state=next;
      if(label)label.textContent=copy[next];
      const age=diag.lastMessageAt?Math.max(0,Math.round((Date.now()-diag.lastMessageAt)/1000)):null;
      const extra=age!==null?` Last socket message ${age}s ago.`:'';
      el.title=(descriptions[next]||'')+extra;
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

  function pixelUrl(raw){
    const source=String(raw||'');
    if(!source)return '';
    try{
      const u=new URL(source,location.href);
      if(/robot-recall-pixel-logo\.php$/i.test(u.pathname))return u.toString();
      let team=0;
      for(const key of ['team','team_number','frc_team_number','frc']){
        const n=parseInt(u.searchParams.get(key)||'0',10);if(n>0){team=n;break;}
      }
      if(!team){const m=u.pathname.match(/frc(\d{1,5})/i);if(m)team=parseInt(m[1],10);}
      if(!team)return u.toString();
      let org=0;
      for(const key of ['org','organization_id']){
        const n=parseInt(u.searchParams.get(key)||'0',10);if(n>0){org=n;break;}
      }
      if(!org){const m=u.pathname.match(/\/org-(\d+)\//i);if(m)org=parseInt(m[1],10);}
      let prefix='';
      const lower=u.pathname.toLowerCase();
      const apiAt=lower.indexOf('/api/');
      const uploadsAt=lower.indexOf('/uploads/');
      if(apiAt>=0)prefix=u.pathname.slice(0,apiAt);
      else if(uploadsAt>=0)prefix=u.pathname.slice(0,uploadsAt);
      const out=new URL(prefix+'/api/robot-recall-pixel-logo.php',location.origin);
      out.searchParams.set('team',String(team));
      out.searchParams.set('size','768');
      out.searchParams.set('grid','18');
      if(org>0)out.searchParams.set('org',String(org));
      return out.toString();
    }catch(_){return source;}
  }

  window.NeptuneRobotRecallRealtime={create,bindStatus,pixelUrl};
})();
