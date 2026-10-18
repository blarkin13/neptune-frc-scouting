</main>
<?php if(!$hideChrome && ($u || ($isPublicHome??false))): ?>
<?php
// Use the same portable-mode flag as admin/portable.php. The cloud builds
// packages; the local installation only provides manual synchronization.
$neptuneFooterPortableLocal = !empty($config['app']['portable_mode']);
$neptuneFooterCanDownloadServer = !empty($u) && in_array(
    (string)($u['role'] ?? ''),
    $neptuneFooterPortableLocal ? ['owner', 'admin'] : ['owner', 'admin', 'strategy'],
    true
);
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
          <a class="neptune-footer-pill" href="<?=e(base_url('admin/portable.php'))?>" title="<?=$neptuneFooterPortableLocal?'Sync Organization to AWS':'Download Portable Server'?>">
            <i class="fa-solid <?=$neptuneFooterPortableLocal?'fa-cloud-arrow-up':'fa-download'?>" aria-hidden="true"></i>
            <span><?=$neptuneFooterPortableLocal?'Sync Server':'Download Server'?></span>
          </a>
        <?php endif; ?>
        <a class="neptune-footer-pill" href="<?=e(base_url('event/'))?>"><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> Discover Neptune</a>
        <a class="neptune-footer-pill" href="<?=e(base_url('wifi/'))?>"><i class="fa-solid fa-wifi" aria-hidden="true"></i> Event Wi-Fi</a>
      <?php endif; ?>
      <span class="neptune-site-footer-info"><a href="https://www.mckinneysteamacademy.org" style="color:inherit;text-decoration:inherit">McKinney STEAM Academy</a><span class="neptune-site-footer-separator" aria-hidden="true">&middot;</span><span>&copy; <?=date('Y')?></span></span>
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


</script>
<?php if(!empty($u) && !($isPublicHome??false)): ?>
<script>
window.NeptunePresenceConfig={
 tokenUrl:<?=json_encode(base_url('api/presence-token.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,
 pageKind:()=>{
  const path=location.pathname.toLowerCase(),q=new URLSearchParams(location.search);
  if(/\/scout\/impact\.php$/.test(path))return 'tag-match';
  if(/\/scout\/tag-context\.php$/.test(path))return (q.get('tag_tab')||q.get('tab'))==='pit'?'tag-pit':'tag-team';
  if(/\/scout\/tag\.php$/.test(path)){const tab=q.get('tab')||'match';return tab==='match'?'tag-match':tab==='pit'?'tag-pit':'tag-team';}
  if(/\/scout\/(?:match|index)\.php$/.test(path))return 'traditional';
  return 'other';
 }
};
</script>
<script defer src="<?=e(base_url('assets/js/neptune-presence-ws.js'))?>?v=1"></script>
<script>
/* Online in Neptune means an authenticated foreground page checked in recently.
   It is independent of the match-scouting heartbeat. */
(()=>{
  const endpoint=<?=json_encode(base_url('api/user-presence.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
  const token=<?=json_encode(csrf_token(), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
  let lastAttempt=0;
  // This reports a visible page category, not evidence that scouting data was saved.
  function pageKind(){
    const path=location.pathname.toLowerCase();
    const query=new URLSearchParams(location.search);
    if(/\/scout\/impact\.php$/.test(path))return 'tag-match';
    if(/\/scout\/tag-context\.php$/.test(path)){
      const tab=query.get('tag_tab')||query.get('tab')||'team';
      return tab==='pit'?'tag-pit':'tag-team';
    }
    if(/\/scout\/tag\.php$/.test(path)){
      const tab=query.get('tab')||'match';
      return tab==='match'?'tag-match':tab==='pit'?'tag-pit':'tag-team';
    }
    if(/\/scout\/(?:match|index)\.php$/.test(path))return 'traditional';
    return 'other';
  }
  function pulse(){
    if(document.visibilityState!=='visible'||!navigator.onLine)return;
    const now=Date.now();if(now-lastAttempt<25000)return;
    lastAttempt=now;
    fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({csrf:token,page_kind:pageKind()}),keepalive:true}).catch(()=>{});
  }
  pulse();setInterval(pulse,45000);
  document.addEventListener('visibilitychange',pulse);
  window.addEventListener('pageshow',pulse);
  window.addEventListener('focus',pulse);
})();
</script>
<?php endif; ?>
<?php if(!empty($u) && !($isPublicHome??false)): ?>
<script>
window.NeptuneAssignmentNotificationsConfig={
 endpoint:<?=json_encode(base_url('api/assignment-notifications.php'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,
 csrf:<?=json_encode(csrf_token(),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,
 accountUrl:<?=json_encode(base_url('dashboard/index.php').'?view=account#account-assignments',JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>,
 organizationId:<?=(int)$u['organization_id']?>,
 userId:<?=(int)$u['id']?>
};
</script>
<link rel="stylesheet" href="<?=e(base_url('assets/css/neptune-assignment-notifications.css'))?>?v=4">
<script defer src="<?=e(base_url('assets/js/neptune-assignment-notifications.js'))?>?v=4"></script>
<?php endif; ?>
<?php if(!empty($u) && !($isPublicHome??false)): ?>
<script>if(window.NeptuneAssignmentNotificationsConfig){window.NeptuneAssignmentNotificationsConfig.pushEndpoint=<?=json_encode(base_url('api/assignment-push.php'))?>;window.NeptuneAssignmentNotificationsConfig.pushBase=<?=json_encode(base_url(''))?>;}</script>
<script defer src="<?=e(base_url('assets/js/neptune-assignment-push.js'))?>?v=4"></script>
<?php endif; ?>
</body></html>
