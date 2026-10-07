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
    let reconnectDelay=350;
    let opened=false;

    const notifyStatus=()=>{
      try{options.onStatus?.(opened?'socket':'polling');}catch(_){}
    };

    function connect(){
      if(stopped||!room)return;
      clearTimeout(reconnectTimer);
      const url=normalizeWsUrl(options.url,room);
      if(!url){notifyStatus();return;}
      const connectedRoom=room;
      try{socket=new WebSocket(url);}catch(_){scheduleReconnect();return;}
      socket.addEventListener('open',()=>{
        opened=true;reconnectDelay=350;notifyStatus();
        try{socket.send(JSON.stringify({type:'sync'}));}catch(_){}
      });
      socket.addEventListener('message',event=>{
        let payload={type:'poke'};
        try{payload=JSON.parse(String(event.data||'{}'))||payload;}catch(_){}
        if(payload.type==='hello')return;
        try{options.onPoke?.(payload);}catch(_){}
      });
      socket.addEventListener('close',()=>{opened=false;notifyStatus();if(connectedRoom===room)scheduleReconnect();});
      socket.addEventListener('error',()=>{opened=false;notifyStatus();});
    }

    function scheduleReconnect(){
      if(stopped)return;
      clearTimeout(reconnectTimer);
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
      try{socket?.close();}catch(_){}
      socket=null;opened=false;reconnectDelay=200;notifyStatus();connect();
    }

    function close(){
      stopped=true;clearTimeout(reconnectTimer);
      try{socket?.close();}catch(_){}
      socket=null;opened=false;
    }

    connect();
    return {poke,setRoom,close,isOpen:()=>opened};
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

  window.NeptuneRobotRecallRealtime={create,pixelUrl};
})();
