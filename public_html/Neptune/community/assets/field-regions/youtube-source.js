'use strict';
// Plays a catalog match from YouTube (as Community Tag Scouting does) behind the annotation canvas.
// Exposes the subset of HTMLVideoElement that app.js uses, so drawing, ranges and saves are unchanged.
(()=>{
 const picker=document.querySelector('.match-picker select');if(picker)picker.addEventListener('change',()=>picker.form.submit());
 const id=(document.querySelector('[data-youtube]')||document.body).dataset.youtube;if(!id||!/^[A-Za-z0-9_-]{11}$/.test(id))return;
 const handlers={};let player=null,ready=false,state=-1,time=0,seekTarget=null,rate=1,poll=null;
 const fire=name=>{try{handlers[name]&&handlers[name]()}catch(e){console.error(e)}};
 const v={
  get currentTime(){return seekTarget??(ready?player.getCurrentTime():time)},
  set currentTime(t){t=Math.max(0,Number(t)||0);seekTarget=t;if(ready){player.seekTo(t,true);setTimeout(()=>{seekTarget=null;fire('onseeked');fire('ontimeupdate')},250)}},
  get duration(){return ready?player.getDuration()||NaN:NaN},
  get paused(){return state!==1&&state!==3},
  get videoWidth(){return ready?1920:0},get videoHeight(){return ready?1080:0},
  get playbackRate(){return rate},set playbackRate(r){rate=r;if(ready)player.setPlaybackRate(r)},
  play(){if(!ready)return Promise.reject(Error('Video not ready'));player.playVideo();return Promise.resolve()},
  pause(){if(ready)player.pauseVideo()},
  set src(_){},
 };
 for(const name of ['onplay','onpause','onloadedmetadata','onseeked','ontimeupdate','onerror'])Object.defineProperty(v,name,{set(f){handlers[name]=f},get(){return handlers[name]}});
 window.NeptuneVideo=v;
 const host=document.getElementById('video');const mount=document.createElement('div');mount.id='ytplayer';host.replaceWith(mount);
 window.onYouTubeIframeAPIReady=()=>{player=new YT.Player('ytplayer',{host:'https://www.youtube-nocookie.com',videoId:id,width:'100%',height:'100%',
  playerVars:{playsinline:1,controls:0,disablekb:1,rel:0,modestbranding:1,origin:location.origin},
  events:{onReady:()=>{ready=true;player.setPlaybackRate(rate);fire('onloadedmetadata');fire('ontimeupdate')},
   onStateChange:e=>{state=e.data;if(state===1){fire('onplay');clearInterval(poll);poll=setInterval(()=>fire('ontimeupdate'),100)}else{clearInterval(poll);if(state===2||state===0)fire('onpause');fire('ontimeupdate')}},
   onError:()=>fire('onerror')}})};
 const s=document.createElement('script');s.src='https://www.youtube.com/iframe_api';s.onerror=()=>fire('onerror');document.head.append(s);
})();
