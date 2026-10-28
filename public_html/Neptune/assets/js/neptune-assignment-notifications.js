(()=>{
  'use strict';
  const cfg=window.NeptuneAssignmentNotificationsConfig;
  if(!cfg?.endpoint||!cfg?.userId||!cfg?.organizationId)return;
  const root=document.createElement('div');
  root.className='neptune-alert-root';
  root.innerHTML=`<section class="neptune-alert-panel" id="neptuneAlertPanel" role="dialog" aria-modal="false" aria-label="Assignment notifications" hidden>
    <div class="neptune-alert-heading"><span><i class="fa-solid fa-bell" aria-hidden="true"></i> Assignment notifications</span><button type="button" class="neptune-alert-close" aria-label="Close notifications"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="neptune-alert-toolbar"><button type="button" class="neptune-alert-mark-all">Mark all read</button><button type="button" class="neptune-alert-browser" hidden>Enable browser alerts</button></div>
    <div class="neptune-alert-items" role="list"><p class="neptune-alert-empty">Loading notifications…</p></div>
    <div class="neptune-alert-tasks" hidden><div class="neptune-alert-tasks-header"><button type="button" class="neptune-alert-tasks-back">← Notifications</button><strong>My Active Assignments</strong></div><div class="neptune-alert-tasks-list" aria-live="polite"></div></div><button type="button" class="neptune-alert-account">Open My Assignments <i class="fa-solid fa-list-check" aria-hidden="true"></i></button>
  </section>`;
  document.body.append(root);
  const panel=root.querySelector('.neptune-alert-panel');
  const itemsEl=root.querySelector('.neptune-alert-items');
  const allButton=root.querySelector('.neptune-alert-mark-all');
  const browserButton=root.querySelector('.neptune-alert-browser');
  // No dashboard navigation: this panel loads assignments from the same
  // authenticated endpoint as the working notification bell.
  const accountButton=root.querySelector('.neptune-alert-account');
  const taskView=root.querySelector('.neptune-alert-tasks');
  const tasksList=root.querySelector('.neptune-alert-tasks-list');
  const taskBack=root.querySelector('.neptune-alert-tasks-back');
  let showingTasks=false, tasksBusy=false;
  function taskMode(enabled){
    showingTasks=!!enabled;
    taskView.hidden=!showingTasks;
    root.querySelector('.neptune-alert-items').hidden=showingTasks;
    root.querySelector('.neptune-alert-toolbar').hidden=showingTasks;
    accountButton.hidden=showingTasks;
    if(showingTasks)loadTasks();
  }
  accountButton.addEventListener('click',()=>taskMode(true));
  taskBack.addEventListener('click',()=>taskMode(false));
  const taskEl=(tag,className,value)=>{const n=document.createElement(tag);if(className)n.className=className;if(value!==undefined)n.textContent=value;return n;};
  function taskError(e){const msg=(e&&e.message)||'The assignment could not be updated.';if(window.NeptuneUI?.toast)window.NeptuneUI.toast(msg,'bad',{title:'Assignment not updated'});else console.error(msg);}
  async function taskAction(id,transition,form){
    const data=new FormData(form||document.createElement('form'));
    data.set('csrf',cfg.csrf);data.set('action','assignment_transition');
    data.set('assignment_id',String(id));data.set('transition',transition);
    const r=await fetch(cfg.endpoint,{method:'POST',body:data,credentials:'same-origin',cache:'no-store'});
    const d=await r.json();if(!r.ok||d.status!=='success')throw new Error(d.message||'Assignment update failed');
  }
  function renderTasks(tasks){
    tasksList.replaceChildren();
    if(!tasks.length){tasksList.append(taskEl('p','neptune-alert-empty','No active assignments. Completed tasks are cleared automatically.'));return;}
    for(const a of tasks){
      const item=taskEl('section','neptune-alert-task');
      let name='Request · '+(a.request_kind||'Task');
      if(a.kind==='match')name=(a.match||'Match')+' · '+(a.alliance||'')+' '+a.station+' · Team #'+(a.team||'?');
      if(a.kind==='pit')name='Pit scouting · Team #'+a.team;
      item.append(taskEl('strong','',name));
      item.append(taskEl('small','',a.event+' · '+a.status+' · '+(a.mode||'').replace('either','either scouting mode')));
      if(a.instructions)item.append(taskEl('p','',a.instructions));
      const form=taskEl('form','neptune-alert-task-form');
      if(a.kind==='request'){
        const label=taskEl('label','', 'Answer or notes'+(a.request_kind==='question'?' (required)':''));
        const answer=taskEl('textarea');answer.name='response_text';answer.rows=2;answer.maxLength=4000;label.append(answer);form.append(label);
        const photoLabel=taskEl('label','', 'Attach photo'+(a.request_kind==='photo'?' (required)':''));
        const photo=taskEl('input');photo.type='file';photo.name='evidence_photo';photo.accept='image/jpeg,image/png,image/webp';photoLabel.append(photo);form.append(photoLabel);
      }
      const actions=taskEl('div','neptune-alert-task-actions');
      if(a.status==='assigned'){
        const ack=taskEl('button','neptune-alert-task-ack','Acknowledge');ack.type='button';
        ack.addEventListener('click',async()=>{ack.disabled=true;try{await taskAction(a.id,'acknowledge');await loadTasks();}catch(e){taskError(e);}finally{ack.disabled=false;}});
        actions.append(ack);
      }
      const done=taskEl('button','neptune-alert-task-done','Mark completed');done.type='submit';
      actions.append(done);form.append(actions);
      form.addEventListener('submit',async event=>{event.preventDefault();done.disabled=true;try{await taskAction(a.id,'complete',form);await loadTasks();}catch(e){taskError(e);}finally{done.disabled=false;}});
      item.append(form);tasksList.append(item);
    }
  }
  async function loadTasks(){
    if(tasksBusy)return;tasksBusy=true;
    tasksList.replaceChildren(taskEl('p','neptune-alert-empty','Loading assignments…'));
    try{
      // Reconcile previously completed work; errors do not prevent viewing tasks.
      try{await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf:cfg.csrf,action:'reconcile_assignments'})});}catch(_){}
      const url=new URL(cfg.endpoint,location.href);url.searchParams.set('action','assignments');
      const r=await fetch(url.href,{credentials:'same-origin',cache:'no-store'});
      const d=await r.json();if(!r.ok||d.status!=='success')throw new Error(d.message||'Assignments unavailable');
      renderTasks(Array.isArray(d.assignments)?d.assignments:[]);
    }catch(e){tasksList.replaceChildren(taskEl('p','neptune-alert-empty',e.message||'Assignments unavailable.'));}
    finally{tasksBusy=false;}
  }
  const bell=document.createElement('button');
  bell.type='button';bell.className='neptune-alert-bell';bell.setAttribute('aria-label','Assignment notifications');
  bell.setAttribute('aria-haspopup','dialog');bell.setAttribute('aria-controls','neptuneAlertPanel');bell.setAttribute('aria-expanded','false');
  bell.innerHTML='<i class="fa-solid fa-bell" aria-hidden="true"></i><span class="neptune-alert-count" hidden></span>';
  const badge=bell.querySelector('.neptune-alert-count');
  const hamburger=document.getElementById('mobileMenuToggle');
  if(hamburger?.parentNode){hamburger.parentNode.insertBefore(bell,hamburger);}else{
    bell.classList.add('neptune-alert-bell-fixed');document.body.append(bell);
  }
  const toasts=document.createElement('div');toasts.className='neptune-alert-toasts';toasts.setAttribute('aria-live','polite');document.body.append(toasts);
  const storageKey=`neptune_assignment_notification_v1_${cfg.organizationId}_${cfg.userId}`;
  let lastKnown=0, initialized=false, busy=false, lastFetch=0, unread=0;
  try{lastKnown=Number(localStorage.getItem(storageKey)||0);}catch(_){}
  window.addEventListener('storage',event=>{if(event.key===storageKey)lastKnown=Math.max(lastKnown,Number(event.newValue)||0);});
  let isOpen=false;
  const itemIcon={match:'fa-flag-checkered',pit:'fa-robot',request:'fa-list-check'};
  const timeLabel=value=>{
    if(!value)return '';
    const date=new Date(String(value).replace(' ','T')+'Z');
    return Number.isNaN(date.valueOf())?'':date.toLocaleString(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
  };
  const el=(tag,cls,text)=>{const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;return node;};
  function showToast(message){
    const toast=el('button','neptune-alert-toast',message);toast.type='button';
    const close=()=>toast.remove();
    toast.addEventListener('click',()=>{setOpen(true);close();});
    toasts.append(toast);setTimeout(close,7000);
    // Browser notifications are optional and work only while Neptune remains open.
    if(document.visibilityState!=='visible'&&'Notification' in window&&Notification.permission==='granted'){
      try{const n=new Notification('Neptune · New assignment',{body:message,tag:`neptune-assign-${cfg.userId}`});n.onclick=()=>{window.focus();setOpen(true);n.close();};}catch(_){}
    }
  }
  function setOpen(open){
    isOpen=!!open;panel.hidden=!isOpen;bell.setAttribute('aria-expanded',String(isOpen));
    if(isOpen){refresh(true);if(showingTasks)loadTasks();panel.querySelector('.neptune-alert-close')?.focus({preventScroll:true});}
  }
  bell.addEventListener('click',()=>setOpen(!isOpen));
  root.querySelector('.neptune-alert-close').addEventListener('click',()=>{setOpen(false);bell.focus();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&isOpen){setOpen(false);bell.focus();}});
  document.addEventListener('pointerdown',e=>{if(isOpen&&!root.contains(e.target)&&!bell.contains(e.target))setOpen(false);});
  if('Notification' in window&&window.isSecureContext&&Notification.permission==='default'){
    browserButton.hidden=false;
    browserButton.addEventListener('click',async()=>{
      try{await Notification.requestPermission();}catch(_){}
      browserButton.hidden=true;
    });
  }
  function render(items){
    itemsEl.replaceChildren();
    if(!items.length){itemsEl.append(el('p','neptune-alert-empty','No assignment notifications yet.'));return;}
    for(const item of items){
      const card=el('article','neptune-alert-item'+(item.read_at?'':' is-unread'));
      card.setAttribute('role','listitem');
      const icon=el('span','neptune-alert-item-icon');
      const i=el('i',`fa-solid ${itemIcon[item.kind]||'fa-bell'}`);i.setAttribute('aria-hidden','true');icon.append(i);
      const content=el('div','neptune-alert-item-content');
      content.append(el('strong','',item.title),el('p','',item.body),el('small','',timeLabel(item.created_at)));
      card.append(icon,content);
      if(!item.read_at){
        const read=el('button','neptune-alert-read','Mark read');read.type='button';
        read.setAttribute('aria-label',`Mark ${item.title} as read`);
        read.addEventListener('click',()=>void markRead(item.id));card.append(read);
      }
      itemsEl.append(card);
    }
  }
  function updateBadge(n){
    unread=n;badge.hidden=n<1;
    badge.textContent=n>99?'99+':String(n);
    bell.setAttribute('aria-label',n?`${n} unread assignment notification${n===1?'':'s'}`:'Assignment notifications');
    allButton.disabled=n===0;
  }
  async function refresh(force=false){
    if(busy){if(force)setTimeout(()=>refresh(true),250);return;}
    if(!force&&Date.now()-lastFetch<7000)return;
    if(!force&&document.visibilityState!=='visible')return;
    busy=true;lastFetch=Date.now();
    try{
      const response=await fetch(cfg.endpoint,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      if(!response.ok)throw new Error('Unavailable');
      const data=await response.json();
      if(data.status!=='success')throw new Error('Unavailable');
      const items=Array.isArray(data.notifications)?data.notifications:[];
      updateBadge(Number(data.unread)||0);
      render(items);
      const highest=items.reduce((max,item)=>Math.max(max,Number(item.id)||0),0);
      try{lastKnown=Math.max(lastKnown,Number(localStorage.getItem(storageKey))||0);}catch(_){}
      if(!initialized){
        // Notify on sign-in about work assigned while this device was away.
        const fresh=items.filter(item=>Number(item.id)>lastKnown&&!item.read_at);
        if(lastKnown===0&&unread>0)showToast(unread===1?'You have a new scouting assignment':`You have ${unread} unread scouting assignments`);
        else if(fresh.length)showToast(fresh.length===1?fresh[0].title:`${fresh.length} new assignment notifications`);
        initialized=true;
      }else{
        const fresh=items.filter(item=>Number(item.id)>lastKnown&&!item.read_at);
        if(fresh.length){
          const label=fresh.length===1?fresh[0].title:`${fresh.length} new assignment notifications`;
          showToast(label);
        }
      }
      if(highest>lastKnown){lastKnown=highest;try{localStorage.setItem(storageKey,String(lastKnown));}catch(_){}}
    }catch(_){
      if(isOpen)itemsEl.replaceChildren(el('p','neptune-alert-empty','Notifications unavailable. Retrying automatically.'));
    }finally{busy=false;}
  }
  async function action(values){
    const form=new URLSearchParams({csrf:cfg.csrf,...values});
    const response=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:form.toString()});
    if(!response.ok)throw new Error('Failed to update notification');
    const data=await response.json();if(data.status!=='success')throw new Error('Failed to update notification');
    await refresh(true);
  }
  async function markRead(id){
    try{await action({action:'mark_read',notification_id:String(id)});}catch(_){showToast('Unable to mark notification read. Try again.');}
  }
  allButton.addEventListener('click',async()=>{
    allButton.disabled=true;
    try{await action({action:'mark_all_read'});}catch(_){showToast('Unable to mark notifications read. Try again.');allButton.disabled=false;}
  });
  // If the upgraded presence relay is installed, a generic organization-wide
  // signal prompts each browser to fetch its own authenticated inbox. Polling
  // remains authoritative when WebSockets are unavailable.
  window.NeptunePresence?.subscribe?.(msg=>{if(msg?.type==='notification-poke')refresh(true);});
  refresh(true);
  setInterval(()=>refresh(),15000);
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')refresh(true);});
  window.addEventListener('focus',()=>refresh(true));
  window.addEventListener('pageshow',()=>refresh(true));
  // If the server reports that My Account is missing, a tapped push will
  // focus Neptune and open the assignment inbox rather than navigating to 404.
  navigator.serviceWorker?.addEventListener('message', event=>{
    if(event.data?.type==='neptune-open-assignment-inbox')setOpen(true);
  });
  function openAssignments(){setOpen(true);taskMode(true);}
  navigator.serviceWorker?.addEventListener('message',event=>{
    if(event.data?.type==='neptune-open-assignment-inbox')openAssignments();
  });
  const params=new URLSearchParams(location.search);
  if(params.has('neptune_assignments')){
    // No page redirect; pop the in-page task view upon push-notification launch.
    openAssignments();
    params.delete('neptune_assignments');
    const next=location.pathname+(params.size?'?'+params.toString():'')+location.hash;
    history.replaceState(history.state,'',next);
  }
  window.NeptuneAssignmentNotifications={refresh:()=>refresh(true),open:()=>setOpen(true),openAssignments};
})();
