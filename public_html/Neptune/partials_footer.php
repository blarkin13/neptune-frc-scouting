</main>
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
</script>
</body></html>
