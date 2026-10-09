</main>
<?php if(!$hideChrome && ($u || ($isPublicHome??false))): ?>
<?php
$neptuneFooterCanDownloadServer = !empty($u) && in_array((string)($u['role'] ?? ''), ['owner', 'admin', 'strategy'], true);
?>
<footer class="neptune-site-footer" aria-label="Neptune footer">
  <div class="neptune-site-footer-inner">
    <div class="neptune-site-footer-brand">
      <i class="fa-solid fa-compass" aria-hidden="true"></i>
      <span><strong>Neptune</strong><small>FRC Scouting Platform</small></span>
    </div>
    <div class="neptune-site-footer-meta">
      <?php if($u): ?>
        <button type="button" class="neptune-footer-pill" id="neptunePhoneOpen" aria-haspopup="dialog" aria-controls="neptunePhoneModal">
          <i class="fa-solid fa-qrcode" aria-hidden="true"></i>
          <span>Open on Phone</span>
        </button>
        <?php if($neptuneFooterCanDownloadServer): ?>
          <a class="neptune-footer-pill" href="<?=e(base_url('admin/portable.php'))?>" title="Portable Organization Server">
            <i class="fa-solid fa-download" aria-hidden="true"></i>
            <span>Download Server</span>
          </a>
        <?php endif; ?>
        <button type="button" class="neptune-footer-pill" id="neptuneWalkthroughOpen" data-neptune-walkthrough-open aria-haspopup="dialog" aria-controls="neptuneWalkthroughModal">
          <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
          <span>Walkthrough</span>
        </button>
      <?php endif; ?>
      <span class="neptune-site-footer-info"><span>McKinney STEAM Academy</span><span class="neptune-site-footer-separator" aria-hidden="true">&middot;</span><span>&copy; <?=date('Y')?></span></span>
    </div>
  </div>
</footer>

<div class="neptune-share-modal" id="neptunePhoneModal" aria-hidden="true">
  <div class="neptune-share-backdrop" data-neptune-phone-close></div>
  <section class="neptune-share-dialog" role="dialog" aria-modal="true" aria-labelledby="neptunePhoneTitle" aria-describedby="neptunePhoneCopy" tabindex="-1">
    <button type="button" class="neptune-share-close" data-neptune-phone-close aria-label="Close Open on Phone dialog">
      <i class="fa-solid fa-xmark" aria-hidden="true"></i>
    </button>
    <div class="neptune-share-mark" aria-hidden="true"><i class="fa-solid fa-mobile-screen-button"></i></div>
    <div class="neptune-share-kicker">NEPTUNE · SCOUT DEVICE</div>
    <h2 id="neptunePhoneTitle">Open Neptune on your phone</h2>
    <p id="neptunePhoneCopy">Scan this code from a phone or tablet. The QR follows this Neptune server, so it works with hosted Neptune and the portable local server.</p>
    <div class="neptune-share-qr-wrap">
      <canvas id="neptunePhoneQr" width="328" height="328" aria-label="QR code for Neptune"></canvas>
    </div>
    <div class="neptune-share-url" id="neptunePhoneUrl"></div>
    <div class="neptune-share-actions">
      <button type="button" class="secondary" id="neptunePhoneCopyLink"><i class="fa-solid fa-link" aria-hidden="true"></i> Copy link</button>
      <a class="neptune-share-open-link" id="neptunePhoneOpenLink" href="<?=e(base_url('index.php'))?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Open Neptune</a>
    </div>
  </section>
</div>

<div class="neptune-walkthrough-modal" id="neptuneWalkthroughModal" aria-hidden="true">
  <div class="neptune-walkthrough-backdrop" data-neptune-walkthrough-close></div>
  <section class="neptune-walkthrough-dialog" role="dialog" aria-modal="true" aria-labelledby="neptuneWalkthroughTitle" tabindex="-1">
    <button type="button" class="neptune-walkthrough-close" data-neptune-walkthrough-close aria-label="Close Neptune walkthrough">
      <i class="fa-solid fa-xmark" aria-hidden="true"></i>
    </button>
    <div class="neptune-walkthrough-head">
      <div class="neptune-walkthrough-mark" aria-hidden="true"><i class="fa-solid fa-compass"></i></div>
      <div>
        <div class="neptune-walkthrough-kicker">NEPTUNE · TRAINING</div>
        <h2 id="neptuneWalkthroughTitle">Quick Walkthrough</h2>
        <p>Use this seven-step overview for the big picture, then open the full User Guide or role-based CBT training when you need more depth.</p>
      </div>
    </div>
    <div class="neptune-walkthrough-steps neptune-walkthrough-steps-visual">
      <div class="neptune-walkthrough-step">
        <span>1</span><div><b>VULCAN · Build the game</b><p>Configure match actions, scoring values, button layout, timing, and scouting forms.</p><button type="button" class="neptune-walkthrough-shot" data-neptune-walkthrough-image="<?=e(base_url('assets/help/training/game-builder.jpg'))?>?v=5" data-neptune-walkthrough-title="Game Builder"><img src="<?=e(base_url('assets/help/training/game-builder.jpg'))?>?v=5" alt="Neptune Game Builder" loading="lazy"><small><i class="fa-solid fa-image"></i> Game Builder</small></button></div>
      </div>
      <div class="neptune-walkthrough-step">
        <span>2</span><div><b>SATURN · Open the event</b><p>Create the event, load the roster, and make it Neptune's current event.</p><button type="button" class="neptune-walkthrough-shot" data-neptune-walkthrough-image="<?=e(base_url('assets/help/training/event-setup.jpg'))?>?v=5" data-neptune-walkthrough-title="Event Setup"><img src="<?=e(base_url('assets/help/training/event-setup.jpg'))?>?v=5" alt="Neptune Event Setup" loading="lazy"><small><i class="fa-solid fa-image"></i> Event Setup</small></button></div>
      </div>
      <div class="neptune-walkthrough-step">
        <span>3</span><div><b>TRIDENT · Pre-scout</b><p>Research teams before the event and carry season knowledge forward.</p><button type="button" class="neptune-walkthrough-shot" data-neptune-walkthrough-image="<?=e(base_url('assets/help/training/pre-scout-team.jpg'))?>?v=5" data-neptune-walkthrough-title="Pre-Scouting"><img src="<?=e(base_url('assets/help/training/pre-scout-team.jpg'))?>?v=5" alt="Neptune Pre-Scouting" loading="lazy"><small><i class="fa-solid fa-image"></i> Pre-Scouting</small></button></div>
      </div>
      <div class="neptune-walkthrough-step">
        <span>4</span><div><b>TRIDENT · Pit scout</b><p>Confirm capabilities, robot details, reliability notes, autonomous routes, and photos at the event.</p><button type="button" class="neptune-walkthrough-shot neptune-walkthrough-shot-phone" data-neptune-walkthrough-image="<?=e(base_url('assets/help/training/pit-auton-path.jpg'))?>?v=5" data-neptune-walkthrough-title="Pit Scouting"><img src="<?=e(base_url('assets/help/training/pit-auton-path.jpg'))?>?v=5" alt="Neptune Pit Scouting autonomous path" loading="lazy"><small><i class="fa-solid fa-image"></i> Pit Scouting</small></button></div>
      </div>
      <div class="neptune-walkthrough-step">
        <span>5</span><div><b>SATURN · Sync the schedule</b><p>Load official team and match data from The Blue Alliance when it becomes available.</p><button type="button" class="neptune-walkthrough-shot" data-neptune-walkthrough-image="<?=e(base_url('assets/help/training/tba-sync.jpg'))?>?v=5" data-neptune-walkthrough-title="The Blue Alliance Sync"><img src="<?=e(base_url('assets/help/training/tba-sync.jpg'))?>?v=5" alt="Neptune The Blue Alliance Sync" loading="lazy"><small><i class="fa-solid fa-image"></i> TBA Sync</small></button></div>
      </div>
      <div class="neptune-walkthrough-step">
        <span>6</span><div><b>SATURN + TRIDENT · Scout live</b><p>Command runs the match while scouts record robot actions from their assigned stations. Tap an action, then swipe left for failure or right for success.</p><button type="button" class="neptune-walkthrough-shot neptune-walkthrough-shot-phone" data-neptune-walkthrough-image="<?=e(base_url('assets/help/training/action-swipe.jpg'))?>?v=5" data-neptune-walkthrough-title="Live Match Scouting"><img src="<?=e(base_url('assets/help/training/action-swipe.jpg'))?>?v=5" alt="Neptune live scouting success and failure swipe" loading="lazy"><small><i class="fa-solid fa-image"></i> Live Match Scouting</small></button></div>
      </div>
      <div class="neptune-walkthrough-step">
        <span>7</span><div><b>AUGUR · Turn data into strategy</b><p>Compare robots, combine public performance data with your scouting, and use Neptune EPA, trends, cycle data, Match Strategy, predictions, and Alliance Selection to make informed decisions.</p><button type="button" class="neptune-walkthrough-shot" data-neptune-walkthrough-image="<?=e(base_url('assets/help/training/robot-cards.jpg'))?>?v=11" data-neptune-walkthrough-title="AUGUR Robot Cards"><img src="<?=e(base_url('assets/help/training/robot-cards.jpg'))?>?v=11" alt="AUGUR Robot Cards comparing event robots and Neptune EPA" loading="lazy"><small><i class="fa-solid fa-image"></i> AUGUR Robot Cards</small></button></div>
      </div>
    </div>
    <div class="neptune-walkthrough-image-viewer" id="neptuneWalkthroughImageViewer" aria-hidden="true">
      <div class="neptune-walkthrough-image-backdrop" data-neptune-walkthrough-image-close></div>
      <div class="neptune-walkthrough-image-dialog" role="dialog" aria-modal="true" aria-labelledby="neptuneWalkthroughImageTitle">
        <div class="neptune-walkthrough-image-head"><b id="neptuneWalkthroughImageTitle">Training screenshot</b><button type="button" data-neptune-walkthrough-image-close aria-label="Close screenshot"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="neptune-walkthrough-image-stage"><img id="neptuneWalkthroughImage" alt=""></div>
      </div>
    </div>
    <div class="neptune-walkthrough-actions">
      <a class="neptune-footer-pill" href="<?=e(base_url('help/'))?>"><i class="fa-solid fa-book-open" aria-hidden="true"></i> Full User Guide</a>
      <a class="neptune-footer-pill" href="<?=e(base_url('help/training/#cbt-courses'))?>"><i class="fa-solid fa-laptop-file" aria-hidden="true"></i> CBT Training</a>
      <button type="button" class="neptune-footer-pill" data-neptune-walkthrough-close><i class="fa-solid fa-check" aria-hidden="true"></i> Done</button>
    </div>
  </section>
</div>
<style>
.neptune-share-qr-wrap canvas{display:block;width:100%;height:auto;image-rendering:pixelated}

/* Sticky footer: flush with the bottom on short pages, normal flow on long pages. */
html{min-height:100%}
body{min-height:100vh;min-height:100dvh;display:flex;flex-direction:column}
body>main.wrap{flex:1 0 auto;width:100%;margin-top:0;margin-bottom:0}
.neptune-site-footer{flex:0 0 auto;margin-top:auto!important;margin-bottom:0!important}

/* Footer refinement: desktop stays compact; mobile becomes a clean stacked block. */
.neptune-site-footer-info{display:inline-flex;align-items:center;gap:7px;white-space:nowrap}
.neptune-site-footer-separator{opacity:.55}
@media(max-width:760px){
  .neptune-site-footer{
    width:calc(100% - 32px)!important;
    margin-left:auto!important;
    margin-right:auto!important;
    padding:0!important;
  }
  .neptune-site-footer-inner{
    align-items:stretch!important;
    gap:10px!important;
    min-height:0!important;
    padding:14px 0 calc(12px + env(safe-area-inset-bottom,0px))!important;
  }
  .neptune-site-footer-brand{
    justify-content:center;
    gap:8px;
  }
  .neptune-site-footer-brand>span{
    justify-content:center;
  }
  .neptune-site-footer-brand strong{font-size:.76rem}
  .neptune-site-footer-brand small{display:none!important}
  .neptune-site-footer-meta{
    width:100%;
    display:flex!important;
    flex-direction:column;
    align-items:stretch!important;
    justify-content:center!important;
    gap:9px!important;
  }
  .neptune-site-footer-meta>.neptune-footer-pill{
    width:100%;
    min-height:42px;
    font-size:.82rem;
  }
  .neptune-site-footer-info{
    justify-content:center;
    flex-wrap:wrap;
    gap:5px 7px;
    color:var(--muted);
    font-size:.68rem;
    line-height:1.35;
    text-align:center;
    white-space:normal;
  }
}

.neptune-walkthrough-steps-visual{grid-template-columns:1fr 1fr}.neptune-walkthrough-steps-visual>.neptune-walkthrough-step{align-items:start}.neptune-walkthrough-shot{display:block;width:100%;margin-top:10px;padding:0;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#02070b;color:var(--text);text-align:left;cursor:zoom-in}.neptune-walkthrough-shot img{display:block;width:100%;height:120px;object-fit:cover;object-position:top center;background:#02070b}.neptune-walkthrough-shot-phone img{object-fit:contain}.neptune-walkthrough-shot small{display:flex;align-items:center;gap:6px;padding:7px 9px;background:var(--panel);color:var(--muted);font-weight:850}.neptune-walkthrough-shot:hover{border-color:color-mix(in srgb,var(--accent) 55%,var(--line))}.neptune-walkthrough-image-viewer{position:fixed;inset:0;z-index:11000;display:none;place-items:center;padding:18px}.neptune-walkthrough-image-viewer.is-open{display:grid}.neptune-walkthrough-image-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.86);backdrop-filter:blur(6px)}.neptune-walkthrough-image-dialog{position:relative;z-index:1;width:min(96vw,1700px);max-height:94vh;border:1px solid var(--line);border-radius:16px;background:var(--panel);overflow:hidden;box-shadow:0 28px 90px rgba(0,0,0,.62)}.neptune-walkthrough-image-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 13px;border-bottom:1px solid var(--line)}.neptune-walkthrough-image-head button{width:38px;height:38px;padding:0}.neptune-walkthrough-image-stage{max-height:calc(94vh - 60px);overflow:auto;padding:8px;background:#02070b;text-align:center}.neptune-walkthrough-image-stage img{max-width:100%;height:auto}.neptune-modal-open-image{overflow:hidden}@media(max-width:700px){.neptune-walkthrough-steps-visual{grid-template-columns:1fr}.neptune-walkthrough-shot img{height:150px}}
</style>

<?php endif; ?>
<script src="<?=e(base_url('assets/js/qrcode-v4l.js'))?>"></script>
<script>
(function(){
  const btn=document.getElementById('themeToggle');
  function syncIcon(){
    if(!btn)return;
    const light=document.documentElement.dataset.theme==='light';
    const icon=btn.querySelector('i');
    const label=btn.querySelector('.theme-label');
    if(icon) icon.className='fa-solid '+(light?'fa-sun':'fa-moon');
    if(label) label.textContent=light?'Day mode':'Night mode';
    btn.title=light?'Switch to night mode':'Switch to day mode';
  }
  if(btn){btn.addEventListener('click',()=>{
    const next=document.documentElement.dataset.theme==='light'?'dark':'light';
    document.documentElement.dataset.theme=next;
    try{localStorage.setItem('neptune-theme',next)}catch(e){}
    syncIcon();
  });syncIcon();}
})();

(function(){
  const toggle=document.getElementById('mobileMenuToggle');
  const nav=document.getElementById('mainNav');
  if(!toggle||!nav)return;
  const icon=toggle.querySelector('i');
  function setOpen(open){
    nav.classList.toggle('mobile-open',open);
    toggle.setAttribute('aria-expanded',open?'true':'false');
    toggle.setAttribute('aria-label',open?'Close navigation menu':'Open navigation menu');
    if(icon) icon.className='fa-solid '+(open?'fa-xmark':'fa-bars');
  }
  toggle.addEventListener('click',()=>setOpen(!nav.classList.contains('mobile-open')));
  nav.querySelectorAll('a').forEach(link=>link.addEventListener('click',()=>setOpen(false)));
  document.addEventListener('keydown',e=>{if(e.key==='Escape')setOpen(false)});
  document.addEventListener('click',e=>{
    if(window.innerWidth>760)return;
    if(nav.classList.contains('mobile-open')&&!nav.contains(e.target)&&!toggle.contains(e.target))setOpen(false);
  });
  window.addEventListener('resize',()=>{if(window.innerWidth>760)setOpen(false)});
})();

(function(){
  function sizeGrid(el){const w=el.clientWidth;if(!w)return;el.style.setProperty('--neptune-grid-cell',Math.max(46,(w-30)/4)+'px')}
  const grids=[...document.querySelectorAll('.scout-grid,.button-preview-grid')];
  grids.forEach(sizeGrid);
  if('ResizeObserver' in window){const ro=new ResizeObserver(entries=>entries.forEach(x=>sizeGrid(x.target)));grids.forEach(x=>ro.observe(x));}
  else window.addEventListener('resize',()=>grids.forEach(sizeGrid));
})();

(function(){
  const openBtn=document.getElementById('neptunePhoneOpen');
  const modal=document.getElementById('neptunePhoneModal');
  if(!openBtn||!modal)return;
  const dialog=modal.querySelector('.neptune-share-dialog');
  const closeButtons=modal.querySelectorAll('[data-neptune-phone-close]');
  const canvas=document.getElementById('neptunePhoneQr');
  const urlEl=document.getElementById('neptunePhoneUrl');
  const copyBtn=document.getElementById('neptunePhoneCopyLink');
  const openLink=document.getElementById('neptunePhoneOpenLink');
  const basePath=<?=json_encode(base_url('index.php'), JSON_UNESCAPED_SLASHES)?>;
  let phoneUrl='';
  let returnFocus=null;
  function resolveUrl(){
    try{return new URL(basePath,window.location.origin).href;}catch(e){return window.location.origin+String(basePath||'/');}
  }
  function renderQr(){
    phoneUrl=resolveUrl();
    if(urlEl)urlEl.textContent=phoneUrl;
    if(openLink)openLink.href=phoneUrl;
    if(canvas&&window.NeptuneQR){
      try{window.NeptuneQR.draw(canvas,phoneUrl,{scale:8,quiet:4,dark:'#000',light:'#fff'});}catch(e){}
    }
  }
  function setOpen(open){
    modal.classList.toggle('is-open',open);
    modal.setAttribute('aria-hidden',open?'false':'true');
    document.body.classList.toggle('neptune-modal-open',open);
    if(open){returnFocus=document.activeElement;renderQr();window.requestAnimationFrame(()=>dialog?.focus({preventScroll:true}));}
    else if(returnFocus&&typeof returnFocus.focus==='function')returnFocus.focus({preventScroll:true});
  }
  openBtn.addEventListener('click',()=>setOpen(true));
  closeButtons.forEach(btn=>btn.addEventListener('click',()=>setOpen(false)));
  copyBtn?.addEventListener('click',async()=>{
    if(!phoneUrl)renderQr();
    try{await navigator.clipboard.writeText(phoneUrl);const old=copyBtn.innerHTML;copyBtn.innerHTML='<i class="fa-solid fa-check"></i> Copied';setTimeout(()=>copyBtn.innerHTML=old,1400);}catch(e){}
  });
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('is-open'))setOpen(false)});
})();

(function(){
  const openButtons=[...document.querySelectorAll('[data-neptune-walkthrough-open]')];
  const modal=document.getElementById('neptuneWalkthroughModal');
  if(!openButtons.length||!modal)return;
  const dialog=modal.querySelector('.neptune-walkthrough-dialog');
  const closeButtons=modal.querySelectorAll('[data-neptune-walkthrough-close]');
  let returnFocus=null;
  function setOpen(open){
    modal.classList.toggle('is-open',open);
    modal.setAttribute('aria-hidden',open?'false':'true');
    document.body.classList.toggle('neptune-modal-open',open);
    if(open){
      returnFocus=document.activeElement;
      window.requestAnimationFrame(()=>dialog?.focus({preventScroll:true}));
    }else if(returnFocus && typeof returnFocus.focus==='function'){
      returnFocus.focus({preventScroll:true});
    }
  }
  openButtons.forEach(btn=>btn.addEventListener('click',()=>setOpen(true)));
  closeButtons.forEach(btn=>btn.addEventListener('click',()=>setOpen(false)));
  document.addEventListener('keydown',event=>{
    if(event.key==='Escape'&&modal.classList.contains('is-open')) setOpen(false);
  });

  const imageViewer=document.getElementById('neptuneWalkthroughImageViewer');
  const imageEl=document.getElementById('neptuneWalkthroughImage');
  const imageTitle=document.getElementById('neptuneWalkthroughImageTitle');
  function setImageOpen(open,src='',title='Training screenshot'){
    if(!imageViewer||!imageEl)return;
    imageViewer.classList.toggle('is-open',open);
    imageViewer.setAttribute('aria-hidden',open?'false':'true');
    document.body.classList.toggle('neptune-modal-open-image',open);
    if(open){imageEl.src=src;imageEl.alt=title;if(imageTitle)imageTitle.textContent=title;}else{imageEl.removeAttribute('src');}
  }
  modal.querySelectorAll('[data-neptune-walkthrough-image]').forEach(button=>button.addEventListener('click',()=>setImageOpen(true,button.dataset.neptuneWalkthroughImage||'',button.dataset.neptuneWalkthroughTitle||'Training screenshot')));
  imageViewer?.querySelectorAll('[data-neptune-walkthrough-image-close]').forEach(button=>button.addEventListener('click',()=>setImageOpen(false)));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&imageViewer?.classList.contains('is-open'))setImageOpen(false);});
})();

</script>
</body></html>
