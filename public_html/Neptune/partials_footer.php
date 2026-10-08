</main>
<?php
$neptunePortableMode=!empty($config['app']['portable_mode']);
$neptuneShowPortableLink=$u && (
    ($neptunePortableMode && in_array((string)($u['role']??''),['owner','admin'],true))
    || (!$neptunePortableMode && (string)($u['role']??'')==='owner')
);
?>
<?php if(!$hideChrome && ($u || ($isPublicHome??false))): ?>
<footer class="neptune-site-footer" aria-label="Neptune footer">
  <div class="neptune-site-footer-inner">
    <div class="neptune-site-footer-brand">
      <i class="fa-solid fa-compass" aria-hidden="true"></i>
      <span><strong>Neptune</strong><small>FRC Scouting Platform</small></span>
    </div>
    <div class="neptune-site-footer-meta">
      <?php if($u): ?>
        <button type="button" class="neptune-footer-pill" id="neptuneWalkthroughOpen" data-neptune-walkthrough-open aria-haspopup="dialog" aria-controls="neptuneWalkthroughModal">
          <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
          <span>Walkthrough</span>
        </button>
      <?php endif; ?>
      <?php if($neptuneShowPortableLink): ?>
        <a class="neptune-footer-pill" href="<?=e(base_url('admin/portable.php'))?>">
          <i class="fa-solid fa-laptop" aria-hidden="true"></i>
          <span><?=$neptunePortableMode?'Offline Server':'Portable Server'?></span>
        </a>
      <?php endif; ?>
      <button type="button" class="neptune-footer-pill" id="neptuneIndexQrOpen" data-neptune-index-qr-open aria-haspopup="dialog" aria-controls="neptuneIndexQrModal">
        <i class="fa-solid fa-qrcode" aria-hidden="true"></i>
        <span>Open on Phone</span>
      </button>
      <span class="neptune-site-footer-info"><span>McKinney STEM Academy</span><span class="neptune-site-footer-separator" aria-hidden="true">&middot;</span><span>&copy; <?=date('Y')?></span></span>
    </div>
  </div>
</footer>

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
        <span>7</span><div><b>AUGUR · Turn data into strategy</b><p>Use Robot Intelligence, Match Strategy, predictions, and Alliance Selection to make decisions. We can add dedicated AUGUR screenshots in the next image pass.</p><button type="button" class="neptune-walkthrough-shot" data-neptune-walkthrough-image="<?=e(base_url('assets/help/training/command-center.jpg'))?>?v=5" data-neptune-walkthrough-title="Neptune workflow"><img src="<?=e(base_url('assets/help/training/command-center.jpg'))?>?v=5" alt="Neptune Command Center" loading="lazy"><small><i class="fa-solid fa-image"></i> Neptune Workflow</small></button></div>
      </div>
    </div>
    <div class="neptune-walkthrough-image-viewer" id="neptuneWalkthroughImageViewer" aria-hidden="true">
      <div class="neptune-walkthrough-image-backdrop" data-neptune-walkthrough-image-close></div>
      <div class="neptune-walkthrough-image-dialog" role="dialog" aria-modal="true" aria-label="Training screenshot">
        <button type="button" class="neptune-walkthrough-image-close" data-neptune-walkthrough-image-close aria-label="Close screenshot">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
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

<div class="neptune-index-qr-modal" id="neptuneIndexQrModal" aria-hidden="true">
  <div class="neptune-index-qr-backdrop" data-neptune-index-qr-close></div>
  <section class="neptune-index-qr-dialog" role="dialog" aria-modal="true" aria-labelledby="neptuneIndexQrTitle" tabindex="-1">
    <button type="button" class="neptune-index-qr-close" data-neptune-index-qr-close aria-label="Close QR code">
      <i class="fa-solid fa-xmark" aria-hidden="true"></i>
    </button>
    <div class="neptune-index-qr-head">
      <div class="neptune-index-qr-mark" aria-hidden="true"><i class="fa-solid fa-qrcode"></i></div>
      <div>
        <div class="neptune-walkthrough-kicker">NEPTUNE · QUICK ACCESS</div>
        <h2 id="neptuneIndexQrTitle">Open Neptune on your phone</h2>
        <p>Scan this QR code to open the Neptune home page.</p>
      </div>
    </div>
    <div class="neptune-index-qr-body">
      <div class="neptune-index-qr-code" id="neptuneIndexQrCode" aria-label="QR code for Neptune home page"></div>
      <a class="neptune-index-qr-url" id="neptuneIndexQrUrl" href="<?=e(base_url('index.php'))?>" target="_blank" rel="noopener">
        <?=e(base_url('index.php'))?>
      </a>
    </div>
    <div class="neptune-walkthrough-actions">
      <a class="neptune-footer-pill" id="neptuneIndexQrOpenLink" href="<?=e(base_url('index.php'))?>" target="_blank" rel="noopener">
        <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Open Neptune
      </a>
      <button type="button" class="neptune-footer-pill" data-neptune-index-qr-close>
        <i class="fa-solid fa-check" aria-hidden="true"></i> Done
      </button>
    </div>
  </section>
</div>

<style>
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


.neptune-index-qr-modal{position:fixed;inset:0;z-index:10850;display:none;place-items:center;padding:18px}
.neptune-index-qr-modal.is-open{display:grid}
.neptune-index-qr-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.82);backdrop-filter:blur(6px)}
.neptune-index-qr-dialog{position:relative;z-index:1;width:min(92vw,460px);max-height:90vh;overflow:auto;border:1px solid var(--line);border-radius:18px;background:var(--panel);box-shadow:0 28px 90px rgba(0,0,0,.62);padding:22px}
.neptune-index-qr-close{position:absolute;top:12px;right:12px;z-index:3;display:grid;place-items:center;width:40px;height:40px;padding:0;border:1px solid var(--line);border-radius:999px;background:var(--panel2);color:var(--text);cursor:pointer}
.neptune-index-qr-head{display:grid;grid-template-columns:auto minmax(0,1fr);gap:13px;align-items:start;padding-right:42px}
.neptune-index-qr-head h2{margin:2px 0 6px}
.neptune-index-qr-head p{margin:0;color:var(--muted)}
.neptune-index-qr-mark{display:grid;place-items:center;width:46px;height:46px;border:1px solid var(--line);border-radius:14px;background:var(--panel2);font-size:1.25rem}
.neptune-index-qr-body{display:grid;justify-items:center;gap:12px;margin:20px 0 4px}
.neptune-index-qr-code{display:grid;place-items:center;width:264px;min-height:264px;padding:12px;border-radius:16px;background:#fff}
.neptune-index-qr-code img,.neptune-index-qr-code canvas{display:block;max-width:240px!important;width:240px!important;height:240px!important}
.neptune-index-qr-url{display:block;max-width:100%;overflow-wrap:anywhere;text-align:center;color:var(--accent);font-size:.82rem}
.neptune-index-qr-error{color:#111;text-align:center;font-size:.82rem;line-height:1.4}
@media(max-width:520px){
  .neptune-index-qr-dialog{padding:18px}
  .neptune-index-qr-code{width:232px;min-height:232px}
  .neptune-index-qr-code img,.neptune-index-qr-code canvas{max-width:208px!important;width:208px!important;height:208px!important}
}

.neptune-walkthrough-steps-visual{grid-template-columns:1fr 1fr}.neptune-walkthrough-steps-visual>.neptune-walkthrough-step{align-items:start}.neptune-walkthrough-shot{display:block;width:100%;margin-top:10px;padding:0;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#02070b;color:var(--text);text-align:left;cursor:zoom-in}.neptune-walkthrough-shot img{display:block;width:100%;height:120px;object-fit:cover;object-position:top center;background:#02070b}.neptune-walkthrough-shot-phone img{object-fit:contain}.neptune-walkthrough-shot small{display:flex;align-items:center;gap:6px;padding:7px 9px;background:var(--panel);color:var(--muted);font-weight:850}.neptune-walkthrough-shot:hover{border-color:color-mix(in srgb,var(--accent) 55%,var(--line))}.neptune-walkthrough-image-viewer{position:fixed;inset:0;z-index:11000;display:none;place-items:center;padding:18px}
.neptune-walkthrough-image-viewer.is-open{display:grid}
.neptune-walkthrough-image-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.86);backdrop-filter:blur(6px)}
#neptuneWalkthroughImageViewer .neptune-walkthrough-image-dialog{position:relative!important;z-index:1!important;width:min(72vw,820px)!important;max-width:820px!important;max-height:78vh!important;border:1px solid var(--line)!important;border-radius:16px!important;background:#02070b!important;overflow:hidden!important;box-shadow:0 28px 90px rgba(0,0,0,.62)!important}
#neptuneWalkthroughImageViewer .neptune-walkthrough-image-stage{display:grid!important;place-items:center!important;width:100%!important;max-height:78vh!important;padding:0!important;overflow:hidden!important;background:#02070b!important;text-align:center!important}
#neptuneWalkthroughImageViewer .neptune-walkthrough-image-stage img{display:block!important;width:100%!important;height:auto!important;max-width:100%!important;max-height:78vh!important;object-fit:contain!important;object-position:center!important}
#neptuneWalkthroughImageViewer .neptune-walkthrough-image-close{position:absolute!important;top:12px!important;right:12px!important;z-index:10!important;display:grid!important;place-items:center!important;width:42px!important;height:42px!important;min-width:42px!important;padding:0!important;margin:0!important;border:1px solid rgba(255,255,255,.28)!important;border-radius:999px!important;background:rgba(6,11,18,.92)!important;color:#fff!important;box-shadow:0 4px 18px rgba(0,0,0,.45)!important;cursor:pointer!important}
#neptuneWalkthroughImageViewer .neptune-walkthrough-image-close:hover{background:rgba(21,35,52,.98)!important;border-color:rgba(255,255,255,.48)!important}
#neptuneWalkthroughImageViewer .neptune-walkthrough-image-close i{font-size:1.1rem!important;line-height:1!important;color:#fff!important}
.neptune-modal-open-image{overflow:hidden}
@media(min-width:1400px){#neptuneWalkthroughImageViewer .neptune-walkthrough-image-dialog{width:min(58vw,820px)!important}}
@media(max-width:700px){.neptune-walkthrough-steps-visual{grid-template-columns:1fr}.neptune-walkthrough-shot img{height:150px}}
</style>

<?php endif; ?>
<?php if(!$hideChrome && ($u || ($isPublicHome??false)) && !$neptunePortableMode): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<?php endif; ?>
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
  const openButton=document.querySelector('[data-neptune-index-qr-open]');
  const modal=document.getElementById('neptuneIndexQrModal');
  if(!openButton||!modal)return;

  const dialog=modal.querySelector('.neptune-index-qr-dialog');
  const closeButtons=modal.querySelectorAll('[data-neptune-index-qr-close]');
  const qrHost=document.getElementById('neptuneIndexQrCode');
  const urlLink=document.getElementById('neptuneIndexQrUrl');
  const openLink=document.getElementById('neptuneIndexQrOpenLink');
  const targetUrl=new URL(<?=json_encode(base_url('index.php'))?>,window.location.origin).href;
  const portableQrUrl=<?=json_encode($neptunePortableMode?base_url('assets/portable/neptune-lan-qr.png'):'')?>;
  let qrBuilt=false;
  let returnFocus=null;

  if(urlLink){
    urlLink.href=targetUrl;
    urlLink.textContent=targetUrl;
  }
  if(openLink) openLink.href=targetUrl;

  function buildQr(){
    if(qrBuilt||!qrHost)return;
    qrHost.innerHTML='';
    if(portableQrUrl){
      const img=document.createElement('img');
      img.src=portableQrUrl+'?v='+Date.now();
      img.alt='QR code for this Neptune event server';
      qrHost.appendChild(img);
      qrBuilt=true;
      return;
    }
    if(typeof window.QRCode!=='function'){
      const fallback=document.createElement('div');
      fallback.className='neptune-index-qr-error';
      fallback.textContent='QR rendering is unavailable. Use the link below to open Neptune.';
      qrHost.appendChild(fallback);
      return;
    }
    new QRCode(qrHost,{
      text:targetUrl,
      width:240,
      height:240,
      colorDark:'#000000',
      colorLight:'#ffffff',
      correctLevel:QRCode.CorrectLevel.M
    });
    qrBuilt=true;
  }

  function setOpen(open){
    modal.classList.toggle('is-open',open);
    modal.setAttribute('aria-hidden',open?'false':'true');
    document.body.classList.toggle('neptune-modal-open',open);
    if(open){
      returnFocus=document.activeElement;
      buildQr();
      window.requestAnimationFrame(()=>dialog?.focus({preventScroll:true}));
    }else if(returnFocus && typeof returnFocus.focus==='function'){
      returnFocus.focus({preventScroll:true});
    }
  }

  openButton.addEventListener('click',()=>setOpen(true));
  closeButtons.forEach(button=>button.addEventListener('click',()=>setOpen(false)));
  document.addEventListener('keydown',event=>{
    if(event.key==='Escape'&&modal.classList.contains('is-open'))setOpen(false);
  });
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
  function setImageOpen(open,src='',title='Training screenshot'){
    if(!imageViewer||!imageEl)return;
    imageViewer.classList.toggle('is-open',open);
    imageViewer.setAttribute('aria-hidden',open?'false':'true');
    document.body.classList.toggle('neptune-modal-open-image',open);
    if(open){imageEl.src=src;imageEl.alt=title;}else{imageEl.removeAttribute('src');}
  }
  modal.querySelectorAll('[data-neptune-walkthrough-image]').forEach(button=>button.addEventListener('click',()=>setImageOpen(true,button.dataset.neptuneWalkthroughImage||'',button.dataset.neptuneWalkthroughTitle||'Training screenshot')));
  imageViewer?.querySelectorAll('[data-neptune-walkthrough-image-close]').forEach(button=>button.addEventListener('click',()=>setImageOpen(false)));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&imageViewer?.classList.contains('is-open'))setImageOpen(false);});
})();

</script>
</body></html>
