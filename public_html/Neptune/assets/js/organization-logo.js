/* Organization logo transparency & contrast — works for all orgs and themes.
 * No pixel changes, uploads, cookies or network calls are made here.
 * Reads local rendered image pixels and temporarily adds a contrasting backing
 * only if a substantially transparent, low-contrast logo needs one.
 */
(()=>{
  'use strict';
  const IMAGE_SELECTOR='img.neptune-org-logo-image';
  const FRAME_SELECTOR='.neptune-org-logo-frame, .ntu-modal-brand';
  const NEED='neptune-org-logo-contrast-needed';
  const inRange=(x,min,max)=>Math.min(max,Math.max(min,x));
  const luminance=(r,g,b)=>{
    const channel=v=>{v/=255;return v<=0.04045?v/12.92:((v+0.055)/1.055)**2.4;};
    return 0.2126*channel(r)+0.7152*channel(g)+0.0722*channel(b);
  };
  const ratio=(a,b)=>(Math.max(a,b)+0.05)/(Math.min(a,b)+0.05);
  function colorOf(s){
    const m=(s||'').match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+))?\s*\)$/i);
    if(!m)return null;
    return [Number(m[1]),Number(m[2]),Number(m[3]),m[4]===undefined?1:Number(m[4])];
  }
  // Composite the backgrounds of surrounding elements; the logo frame itself
  // is transparent by default. Use real CSS colors rather than assuming dark mode.
  function surroundings(node){
    let layers=[];
    for(let el=node.parentElement;el&&el.nodeType===1;el=el.parentElement){
      const css=getComputedStyle(el),color=colorOf(css.backgroundColor);
      if(color&&color[3]>0.015){layers.push(color);if(color[3]>=0.995)break;}
    }
    let rgb=[12,22,36];
    for(let i=layers.length-1;i>=0;i--){
      const c=layers[i],alpha=inRange(c[3],0,1);
      rgb=rgb.map((v,j)=>Math.round(v*(1-alpha)+c[j]*alpha));
    }
    return luminance(...rgb);
  }
  function sample(img){
    if(!img.complete||!img.naturalWidth||!img.naturalHeight)return null;
    const canvas=document.createElement('canvas'),size=48;
    canvas.width=size;canvas.height=size;
    let pixels;
    try{
      const ctx=canvas.getContext('2d',{willReadFrequently:true});
      if(!ctx)return null;
      ctx.clearRect(0,0,size,size);
      ctx.drawImage(img,0,0,size,size);
      pixels=ctx.getImageData(0,0,size,size).data;
    }catch(_){return null;}
    let opaque=0,total=0,visible=0,luma=0;
    const lightness=[];
    for(let i=0;i<pixels.length;i+=4){
      const a=pixels[i+3]/255;
      if(a>0.98)opaque++;
      if(a<0.14)continue;
      const w=a;
      const l=luminance(pixels[i],pixels[i+1],pixels[i+2]);
      visible+=w;luma+=w*l;
      lightness.push([l,w]);
    }
    total=pixels.length/4;
    if(visible<1)return null;
    return {avg:luma/visible,opaqueFraction:opaque/total,lightness};
  }
  function repaint(img){
    const frame=img.closest(FRAME_SELECTOR)||img;
    frame.classList.remove(NEED);
    frame.style.removeProperty('--neptune-org-logo-contrast-bg');
    const art=sample(img);
    // An opaque image already has its own colored backdrop; extra framing
    // cannot improve the interior. JPEGs cannot have an alpha channel.
    if(!art||art.opaqueFraction>0.96)return;
    const bg=surroundings(frame),pixels=art.lightness;
    let low=0,total=0;
    for(const [l,w] of pixels){total+=w;if(ratio(l,bg)<2.65)low+=w;}
    if(total===0||low/total<0.48)return;
    // Pick the surface that creates the strongest contrast for the visible ink.
    const white=luminance(252,253,255),navy=luminance(10,18,32);
    const pale=ratio(art.avg,white)>=ratio(art.avg,navy);
    frame.style.setProperty('--neptune-org-logo-contrast-bg',pale?'rgba(252,253,255,0.96)':'rgba(10,18,32,0.96)');
    frame.classList.add(NEED);
  }
  const observed=new WeakSet();
  function scan(recheck=false){
    document.querySelectorAll(IMAGE_SELECTOR).forEach(img=>{
      const fresh=!observed.has(img);
      if(fresh){
        observed.add(img);
        img.addEventListener('load',()=>repaint(img));
      }
      if(img.complete&&(fresh||recheck))repaint(img);
    });
  }
  let queued=false,needsRecheck=false;
  function queueScan(recheck=false){
    needsRecheck=needsRecheck||recheck;
    if(queued)return;queued=true;
    requestAnimationFrame(()=>{
      queued=false;const force=needsRecheck;needsRecheck=false;scan(force);
    });
  }
  document.addEventListener('DOMContentLoaded',()=>{
    scan();
    // Live scouting views frequently change their DOM. Only measure newly
    // introduced images, except when a theme actually changes.
    new MutationObserver(()=>queueScan()).observe(document.body,{childList:true,subtree:true});
    new MutationObserver(()=>queueScan(true)).observe(document.documentElement,{attributes:true,attributeFilter:['class','data-theme','style']});
    new MutationObserver(()=>queueScan(true)).observe(document.body,{attributes:true,attributeFilter:['class','style']});
    const dark=window.matchMedia?.('(prefers-color-scheme: dark)');
    dark?.addEventListener?.('change',()=>queueScan(true));
  });
  window.NeptuneOrganizationLogos={recheck:()=>queueScan(true)};
})();
