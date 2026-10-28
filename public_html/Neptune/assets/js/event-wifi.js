/* Shared display QR rendering. Wi-Fi credentials stay in the authorized page;
   no QR service, external API, or network request is used. */
(function(root,factory){const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;else{root.NeptuneDisplayQR=api;api.init();}})(typeof globalThis!=='undefined'?globalThis:this,function(){
'use strict';
function escapeWifi(value){return String(value).replace(/[\\;,:\"]/g,'\\$&');}
function wifiPayload(ssid,password,security){return 'WIFI:T:'+(security==='open'?'nopass':security)+';S:'+escapeWifi(ssid)+';'+(security==='open'?'':'P:'+escapeWifi(password)+';')+';';}
function draw(canvas,text){
 const qr=qrcodegen.QrCode.encodeText(text,qrcodegen.QrCode.Ecc.MEDIUM),quiet=4,scale=10,size=(qr.size+quiet*2)*scale;
 canvas.width=size;canvas.height=size;const ctx=canvas.getContext('2d');ctx.imageSmoothingEnabled=false;ctx.fillStyle='#fff';ctx.fillRect(0,0,size,size);ctx.fillStyle='#000';
 for(let y=0;y<qr.size;y++)for(let x=0;x<qr.size;x++)if(qr.getModule(x,y))ctx.fillRect((x+quiet)*scale,(y+quiet)*scale,scale,scale);
}
function init(){
 const security=document.getElementById('network-security'),entry=document.getElementById('network-password');
 function sync(){if(!security||!entry)return;entry.required=security.value!=='open';entry.disabled=security.value==='open';}
 security?.addEventListener('change',sync);sync();
 document.getElementById('show-entry-password')?.addEventListener('change',e=>{if(entry)entry.type=e.target.checked?'text':'password';});
 const wifi=document.getElementById('wifiConnectionQr');if(wifi){try{draw(wifi,wifiPayload(wifi.dataset.ssid,wifi.dataset.password,wifi.dataset.security));}catch(error){wifi.hidden=true;document.getElementById('wifiQrError').hidden=false;}}
 const join=document.getElementById('neptuneJoinQr');if(join){try{draw(join,new URL(join.dataset.url,location.origin).href);}catch(error){join.hidden=true;}}
}
return {wifiPayload,draw,init};
});
