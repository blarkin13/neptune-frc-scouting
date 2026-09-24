(()=>{
  'use strict';
  const dialog=document.getElementById('spotIconPicker');
  const input=document.getElementById('spotIconClass');
  const openBtn=document.getElementById('spotChooseIconBtn');
  const closeBtn=document.getElementById('spotIconPickerClose');
  const cancelBtn=document.getElementById('spotIconCancelBtn');
  const applyBtn=document.getElementById('spotIconApplyBtn');
  const search=document.getElementById('spotIconSearch');
  const category=document.getElementById('spotIconCategory');
  const grid=document.getElementById('spotIconGrid');
  const resultCount=document.getElementById('spotIconResultCount');
  const versionEl=document.getElementById('spotIconVersion');
  const preview=document.getElementById('spotIconDetailPreview');
  const title=document.getElementById('spotIconDetailTitle');
  const nameCode=document.getElementById('spotIconDetailName');
  const variants=document.getElementById('spotIconVariants');
  const categoriesEl=document.getElementById('spotIconCategories');
  const classCode=document.getElementById('spotIconClassCode');
  const currentPreview=document.getElementById('spotIconCurrentPreview');
  const sentinel=document.getElementById('spotIconLoadSentinel');
  if(!dialog||!input||!openBtn||!grid)return;

  const catalogUrl=dialog.dataset.catalogUrl||'';
  const BATCH=240;
  let catalog=null, filtered=[], shown=0, selected=null, selectedStyle='solid', styleFilter='all', observer=null;
  const esc=(s)=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const styleClass=s=>s==='brands'?'fa-brands':s==='regular'?'fa-regular':'fa-solid';
  const prettyStyle=s=>s==='brands'?'Brands':s==='regular'?'Regular':'Solid';
  const currentParts=()=>String(input.value||'').trim().split(/\s+/).filter(Boolean);
  const currentIconName=()=>{const p=currentParts().find(x=>/^fa-(?!solid$|regular$|brands$|thin$|light$|duotone$)[a-z0-9-]+$/i.test(x));return p?p.replace(/^fa-/,''):''};
  const currentStyle=()=>{const p=currentParts();return p.includes('fa-brands')?'brands':p.includes('fa-regular')?'regular':'solid'};
  const updateCurrentPreview=()=>{currentPreview.className='';const parts=currentParts();currentPreview.classList.add(...(parts.length?parts:['fa-solid','fa-tag']))};

  async function loadCatalog(){
    if(catalog)return catalog;
    grid.innerHTML='<div class="spot-icon-picker-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading local icon library…</div>';
    const r=await fetch(catalogUrl,{credentials:'same-origin'});
    if(!r.ok)throw new Error(`Icon library returned HTTP ${r.status}`);
    catalog=await r.json();
    versionEl.textContent=`Font Awesome Free ${catalog.version} · ${Number(catalog.count||0).toLocaleString()} icons · locally hosted`;
    category.innerHTML='<option value="">All categories</option>'+catalog.categories.map(c=>`<option value="${esc(c)}">${esc(c)}</option>`).join('');
    return catalog;
  }

  function selectIcon(icon,requestedStyle=null,scrollDetail=false){
    selected=icon;
    const styles=icon.styles||[];
    selectedStyle=(requestedStyle&&styles.includes(requestedStyle))?requestedStyle:(styles.includes('solid')?'solid':styles[0]||'solid');
    title.textContent=icon.label;
    nameCode.textContent=`fa-${icon.name}`;
    renderDetail();
    grid.querySelectorAll('.spot-icon-choice.selected').forEach(el=>el.classList.remove('selected'));
    const btn=grid.querySelector(`[data-icon-name="${CSS.escape(icon.name)}"]`);if(btn)btn.classList.add('selected');
    if(scrollDetail&&window.matchMedia('(max-width:780px)').matches)document.getElementById('spotIconDetail').scrollIntoView({behavior:'smooth',block:'start'});
  }

  function renderDetail(){
    if(!selected){
      preview.innerHTML='<i class="fa-solid fa-icons"></i>';title.textContent='Choose an icon';nameCode.textContent='Select any icon from the library.';variants.innerHTML='';categoriesEl.innerHTML='';classCode.textContent='';applyBtn.disabled=true;return;
    }
    const cls=`${styleClass(selectedStyle)} fa-${selected.name}`;
    preview.innerHTML=`<i class="${esc(cls)}"></i>`;
    variants.innerHTML=(selected.styles||[]).map(s=>`<button type="button" class="secondary spot-icon-variant ${s===selectedStyle?'active':''}" data-icon-style="${esc(s)}"><i class="${styleClass(s)} fa-${esc(selected.name)}"></i><span><b>${prettyStyle(s)}</b><br><small>${styleClass(s)}</small></span></button>`).join('');
    categoriesEl.innerHTML=(selected.categories||[]).length?(selected.categories||[]).map(c=>`<span>${esc(c)}</span>`).join(''):'<span>Uncategorized</span>';
    classCode.textContent=cls;applyBtn.disabled=false;
  }

  function matches(icon,q){
    if(!q)return true;
    const hay=[icon.name,icon.label,...(icon.terms||[]),...(icon.aliases||[]),...(icon.categories||[])].join(' ').toLowerCase();
    return q.split(/\s+/).filter(Boolean).every(part=>hay.includes(part));
  }
  function refilter(){
    if(!catalog)return;
    const q=search.value.trim().toLowerCase(),cat=category.value;
    filtered=catalog.icons.filter(icon=>matches(icon,q)&&(!cat||(icon.categories||[]).includes(cat))&&(styleFilter==='all'||(icon.styles||[]).includes(styleFilter)));
    shown=0;grid.innerHTML='';resultCount.textContent=`${filtered.length.toLocaleString()} icon${filtered.length===1?'':'s'}`;
    appendBatch();
    if(!filtered.length)grid.innerHTML='<div class="spot-icon-empty">No icons match those filters.</div>';
  }
  function appendBatch(){
    if(shown>=filtered.length)return;
    const chunk=filtered.slice(shown,shown+BATCH);
    const frag=document.createDocumentFragment();
    chunk.forEach(icon=>{
      const b=document.createElement('button');b.type='button';b.className='spot-icon-choice'+(selected&&selected.name===icon.name?' selected':'');b.dataset.iconName=icon.name;b.title=icon.label;
      const style=icon.styles.includes('solid')?'solid':icon.styles[0];
      b.innerHTML=`<i class="${styleClass(style)} fa-${esc(icon.name)}"></i><span>${esc(icon.label)}</span>`;
      b.addEventListener('click',()=>selectIcon(icon,null,true));frag.appendChild(b);
    });
    grid.appendChild(frag);shown+=chunk.length;
  }

  async function openPicker(){
    try{
      await loadCatalog();
      const wanted=currentIconName(),style=currentStyle();
      const found=catalog.icons.find(i=>i.name===wanted||(i.aliases||[]).includes(wanted));
      if(found)selectIcon(found,style,false); else renderDetail();
      refilter();
      if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');
      setTimeout(()=>search.focus(),20);
    }catch(err){
      console.error(err);alert('Neptune could not load the local icon library.');
    }
  }
  function closePicker(){if(typeof dialog.close==='function')dialog.close();else dialog.removeAttribute('open')}

  openBtn.addEventListener('click',openPicker);closeBtn.addEventListener('click',closePicker);cancelBtn.addEventListener('click',closePicker);
  applyBtn.addEventListener('click',()=>{if(!selected)return;input.value=`${styleClass(selectedStyle)} fa-${selected.name}`;input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));closePicker();input.focus()});
  input.addEventListener('input',updateCurrentPreview);updateCurrentPreview();
  search.addEventListener('input',refilter);category.addEventListener('change',refilter);
  document.querySelectorAll('[data-icon-style-filter]').forEach(btn=>btn.addEventListener('click',()=>{document.querySelectorAll('[data-icon-style-filter]').forEach(b=>b.classList.remove('active'));btn.classList.add('active');styleFilter=btn.dataset.iconStyleFilter||'all';refilter()}));
  variants.addEventListener('click',e=>{const b=e.target.closest('[data-icon-style]');if(!b)return;selectedStyle=b.dataset.iconStyle;renderDetail()});
  dialog.addEventListener('click',e=>{if(e.target===dialog)closePicker()});
  dialog.addEventListener('cancel',e=>{e.preventDefault();closePicker()});
  if('IntersectionObserver'in window){observer=new IntersectionObserver(entries=>{if(entries.some(x=>x.isIntersecting))appendBatch()},{root:document.getElementById('spotIconScroll'),rootMargin:'300px'});observer.observe(sentinel)}
})();
