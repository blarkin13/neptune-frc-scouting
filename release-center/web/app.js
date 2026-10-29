'use strict';
// Neptune ocean background with rising bubbles (same recipe as neptunefrc.com). Decorative and
// hidden from assistive technology; styles are set through the CSSOM, which the page's CSP allows.
(()=>{if(document.querySelector('.neptune-ocean'))return;const ocean=document.createElement('div');ocean.className='neptune-ocean';ocean.setAttribute('aria-hidden','true');
for(const part of ['depth','rays','caustics','floor']){const layer=document.createElement('div');layer.className='neptune-ocean__'+part;ocean.append(layer);}
const count=window.innerWidth<700?28:64;for(let n=0;n<count;n++){const b=document.createElement('i');b.className='neptune-ocean__bubble';const set=(k,v)=>b.style.setProperty(k,v);
set('--x',(Math.random()*100).toFixed(2)+'%');set('--duration',(10+Math.random()*21).toFixed(2)+'s');set('--delay',(-Math.random()*32).toFixed(2)+'s');set('--size',(4+Math.pow(Math.random(),1.6)*20).toFixed(1)+'px');set('--alpha',(.3+Math.random()*.5).toFixed(2));set('--drift',(Math.random()*180-90).toFixed(1)+'px');ocean.append(b);}
ocean.style.setProperty('--ocean-ray-tilt',(Math.random()*8-4).toFixed(1)+'deg');document.body.prepend(ocean);})();
const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
let summarySignature='',state={},telemetry=null,mirror=null,selectedFile=null,reviewId=null,controlPackage=null,sending=false;
const formatBytes=n=>{n=Number(n);if(!Number.isFinite(n))return '—';if(n===0)return '0 B';const units=['B','KiB','MiB','GiB','TiB'];let i=0;while(n>=1024&&i<4){n/=1024;i++;}return n.toFixed(i?1:0)+' '+units[i];};
const when=s=>{if(!s)return 'Not recorded';const d=new Date(s);return Number.isNaN(d.valueOf())?'Unknown time':d.toLocaleString(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});};
const text=(selector,value)=>{const node=$(selector);if(node)node.textContent=value??'—';};
function node(tag,content,className){const e=document.createElement(tag);if(content!=null)e.textContent=content;if(className)e.className=className;return e;}
let connectionWarning=false;
function notice(message,error=false){if(!connectionWarning)$('#globalNotice').hidden=true;if(message)toast(String(message),error?'error':'success');}
function toast(message,kind){const host=$('#toastHost');if(!host)return;const t=node('div',message,'toast'+(kind?' toast-'+kind:''));host.append(t);requestAnimationFrame(()=>t.classList.add('show'));const kill=()=>{t.classList.remove('show');setTimeout(()=>t.remove(),300);};t.addEventListener('click',kill);setTimeout(kill,5200);}
function busy(){return sending||['queued','running'].includes(state.operation?.state);}
function connectionError(message,code){const e=new Error(message);e.code=code;return e;}
async function request(path,options={}){
 const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),options.method&&options.method!=='GET'?65000:15000);
 try{
  const r=await fetch(path,{credentials:'same-origin',cache:'no-store',redirect:'manual',...options,signal:options.signal||controller.signal});
  if(r.type==='opaqueredirect'||r.status===401)throw connectionError('Your access session has expired. Reconnect to sign in again.','AUTH_REQUIRED');
  let d;try{d=await r.json();}catch{
   if(r.ok&&(r.headers.get('Content-Type')||'').includes('text/html'))throw connectionError('Your access session needs to be renewed. Reconnect to sign in again.','AUTH_REQUIRED');
   if(r.ok)throw connectionError('The server returned an unreadable response. Retrying automatically.','UNAVAILABLE');
   throw connectionError('The server is temporarily unavailable ('+r.status+').','UNAVAILABLE');
  }
  if(!r.ok)throw connectionError(d.error||'Request failed ('+r.status+')',r.status>=500?'UNAVAILABLE':'REQUEST_FAILED');
  return d;
 }catch(e){if(e.name==='AbortError')throw connectionError('The server took too long to respond. Retrying automatically.','TIMEOUT');throw e;}
 finally{clearTimeout(timer);}
}

function post(path,body){return request(path,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF':$('meta[name="csrf-token"]').content},body:JSON.stringify(body)});}
async function action(body){if(busy())throw new Error('Wait for the current operation to finish.');sending=true;renderButtons();try{const result=await post('/api/maintenance/request',body);state.operation={state:'queued',phase:result.phase||result.message};notice(result.phase||result.message||'Request queued.');await refresh();return result;}finally{sending=false;renderButtons();}}
const titles={health:['Health & database','See what needs attention before making a change.'],updates:['Production updates','Inspect a package, review its changes, then install it.'],github:['GitHub sync','Keep the repository aligned with the code running in production.'],backups:['Backups & recovery','Check the last backup and review your website recovery points.'],storage:['Storage cleanup','Reclaim space from old update archives with a reviewed deletion.'],database:['SQL','Browse tables, inspect structure, edit rows, and run queries.'],platform:['Platform Administration','Organizations, owners, access and tenant operations.'],files:['File Manager','Browse and maintain server files in /var and /opt.'],augur:['AUGUR Operations','Scheduled jobs, EPA archives, rebuilds and phase maps.'],terminal:['Root Terminal','Server command access through the authenticated RC connection.']};
function tab(name){if(!(name in titles))name='health';$$('.panel-view').forEach(e=>e.hidden=e.id!=='panel-'+name);$$('.nav-link').forEach(e=>{const active=e.dataset.tab===name;e.classList.toggle('active',active);if(active)e.setAttribute('aria-current','page');else e.removeAttribute('aria-current');});text('#pageTitle',titles[name][0]);text('#pageSubtitle',titles[name][1]);}
$$('[data-tab]').forEach(e=>e.addEventListener('click',()=>{location.hash=e.dataset.tab;tab(e.dataset.tab);}));window.addEventListener('hashchange',()=>tab(location.hash.slice(1)));tab(location.hash.slice(1));
// The clicked operation button is the user's instruction; no typed phrases or extra modal gate.
async function confirmChange(){return true;}

function renderTelemetry(d){telemetry=d;const disk=d.disk||{},memory=d.memory||{};text('#diskPercent',Number.isFinite(disk.used_percent)?disk.used_percent.toFixed(1)+'%':'—');text('#diskDetail',formatBytes(disk.used_bytes)+' used of '+formatBytes(disk.total_bytes));text('#diskFree',formatBytes(disk.available_bytes)+' free');$('#diskBar').value=Number(disk.used_percent)||0;text('#memoryPercent',Number.isFinite(memory.used_percent)?memory.used_percent.toFixed(1)+'%':'—');text('#memoryDetail',formatBytes(memory.used_bytes)+' used of '+formatBytes(memory.total_bytes));$('#memoryBar').value=Number(memory.used_percent)||0;text('#healthSample','Reading: '+when(d.sampled_at));const samples=(d.samples||[]).filter(s=>Number.isFinite(s.disk_used_percent)&&Number.isFinite(s.memory_used_percent));const points=key=>samples.map((s,i)=>(samples.length<2?0:i*600/(samples.length-1)).toFixed(1)+','+(140-Math.max(0,Math.min(100,s[key]))*1.4).toFixed(1)).join(' ');$('#diskHistory').setAttribute('points',points('disk_used_percent'));$('#memoryHistory').setAttribute('points',points('memory_used_percent'));$('#historyEmpty').hidden=samples.length>=2;}
function renderDatabase(){const d=state.database;if(!d)return;text('#databaseWhen',(Date.now()-new Date(d.sampled_at).getTime()>900000?'Saved reading — refresh for current values: ':'Last checked: ')+when(d.sampled_at));text('#binlogSize',d.binary_logs?.enabled===false?'Disabled':formatBytes(d.binary_logs?.bytes));const vars=Object.fromEntries((d.variables||[]).map(r=>[r.Variable_name,r.Value]));const status=Object.fromEntries((d.status||[]).map(r=>[r.Variable_name,Number(r.Value)]));text('#binlogRetention',vars.binlog_expire_logs_seconds?Number(vars.binlog_expire_logs_seconds)/3600+' hours'+(d.binary_logs?.enabled===false?' (inactive)':''):'Unknown');text('#dbCacheHit',status.Innodb_buffer_pool_read_requests?((1-status.Innodb_buffer_pool_reads/status.Innodb_buffer_pool_read_requests)*100).toFixed(3)+'%':'—');text('#dbSlow',Number.isFinite(status.Slow_queries)?status.Slow_queries.toLocaleString():'—');const body=$('#databaseRows');body.replaceChildren();for(const r of d.tables||[]){const tr=node('tr');tr.append(node('td',r.TABLE_NAME),node('td',r.size_mb+' MiB'),node('td',Number(r.TABLE_ROWS).toLocaleString()));const td=node('td'),inspect=node('button','Open table','button-secondary');inspect.addEventListener('click',()=>window.dispatchEvent(new CustomEvent('rc-open-table',{detail:r.TABLE_NAME})));td.append(inspect);tr.append(td);body.append(tr);}}
function renderBackup(){const d=state.backup;if(d){const status=d.status==='success'?'Successful':d.status==='running'?'Running':d.status==='failure'?'Needs attention':'Unknown';text('#backupHealth',status);text('#backupHealthWhen','Last success: '+when(d.last_success_at));text('#backupState',status==='Unknown'?'Backup status unknown':status+' backup');text('#backupLast',when(d.last_success_at));text('#backupNext',when(d.next_scheduled_at));text('#backupSize',formatBytes(d.last_success_size_bytes));}const list=$('#recoveryList');list.replaceChildren();const rows=state.recovery?.items||[];$('#savedRecoveryCard').hidden=rows.length===0;if(!rows.length)list.append(node('p','No recovery points from this center yet.','empty'));for(const r of rows){const item=node('div',null,'item'),copy=node('div');copy.append(node('strong',r.name||'Website update'),node('p',when(r.installed_at)+' · '+r.state));item.append(copy);if(r.state==='installed'){const button=node('button','Restore files','button-secondary');button.disabled=busy();button.addEventListener('click',async()=>{try{if(await confirmChange('Restore previous website files?','This restores files from the selected update. It does not roll back database migrations. Later production edits will stop the restore.','RESTORE FILES',[r.name||r.backup_id,when(r.installed_at)]))await action({action:'restore',backup_id:r.backup_id,confirmation:'RESTORE FILES'});}catch(e){notice(e.message,true);}});item.append(button);}list.append(item);}}
function renderPackages(){const list=$('#packageList');list.replaceChildren();if(!(state.packages||[]).length)list.append(node('p','No packages uploaded yet.','empty'));for(const p of state.packages||[]){const item=node('div',null,'item'),copy=node('div'),buttons=node('div',null,'actions');copy.append(node('strong',p.name),node('p',formatBytes(p.bytes)+' · '+when(p.uploaded_at)+' · '+(p.review?.state||'uploaded')));const inspect=node('button','Inspect changes','button-secondary');inspect.disabled=busy()||p.review?.state==='success';inspect.addEventListener('click',async()=>{reviewId=p.id;try{await action({action:'inspect',package_id:p.id});}catch(e){notice(e.message,true);}});buttons.append(inspect);item.append(copy,buttons);list.append(item);}renderReview();}
function renderReview(){const p=(state.packages||[]).find(p=>p.id===reviewId);const d=p?.review;const visible=d?.state==='review'&&Array.isArray(d.files);$('#packageReview').hidden=!visible;if(!visible)return;text('#reviewName',p.name);text('#reviewInfo',d.files.length+' changed files · inspected '+when(d.created_at));const body=$('#reviewRows');body.replaceChildren();for(const r of d.files){const tr=node('tr');const path=node('td',r.path);if(r.diff_preview){const details=node('details');details.append(node('summary','View diff'),node('pre',r.diff_preview,'diff'));path.append(details);}tr.append(path,node('td',r.from_sha256?'Update existing file':'Add file'),node('td',formatBytes(r.bytes)));body.append(tr);}}
function storageSelection(){const selected=$$('#storageRows input:checked').map(i=>i.value);const rows=(state.storage?.items||[]).filter(r=>selected.includes(r.id));text('#selectedStorage',rows.length+' file'+(rows.length===1?'':'s'));text('#selectedBytes',formatBytes(rows.reduce((sum,r)=>sum+Number(r.allocated_bytes||r.bytes),0)));$('#deleteStorage').disabled=busy()||!rows.length;return rows;}
function renderStorage(){const d=state.storage;if(!d)return;const previously=new Set($$('#storageRows input:checked').map(i=>i.value));text('#storageWhen',when(d.created_at));text('#storageScope',d.scope);const body=$('#storageRows');body.replaceChildren();if(!d.items?.length){const tr=node('tr'),td=node('td','No eligible old ZIP archives found.','empty');td.colSpan=4;tr.append(td);body.append(tr);}for(const r of d.items||[]){const tr=node('tr'),td=node('td'),input=node('input');input.type='checkbox';input.value=r.id;input.checked=previously.has(r.id);input.setAttribute('aria-label','Select '+r.name);input.addEventListener('change',storageSelection);td.append(input);tr.append(td,node('td',r.name),node('td',formatBytes(r.bytes)),node('td',when(r.modified_at)));body.append(tr);}storageSelection();}
function renderButtons(){$$('[data-action]').forEach(b=>b.disabled=busy());$('#uploadPackage').disabled=busy()||!selectedFile;$('#installPackage').disabled=busy()||!reviewId;$('#createBackup').disabled=busy()||!state.backup?.configured;$('#scanGithub').disabled=busy()||['running','queued'].includes(mirror?.status?.state);$('#publishGithub').disabled=busy()||['running','queued'].includes(mirror?.status?.state);$('#installControl').disabled=busy()||!controlPackage;storageSelection();}
function renderSummary(d){const signature=JSON.stringify(d);if(signature===summarySignature){renderButtons();return;}summarySignature=signature;state=d;$('#previewBanner').hidden=!d.demo;text('#connectionBadge',d.demo?'PREVIEW':'CONNECTED');$('#connectionBadge').classList.toggle('connected',!d.demo);text('#lastUpdated',(d.demo?'Preview updated ':'Updated ')+new Date().toLocaleTimeString());const op=d.operation||{};text('#operationTitle',op.state==='running'?'Working':op.state==='queued'?'Queued':op.state==='failed'?'Needs attention':'Ready');text('#operationDetail',(op.phase||'No maintenance operation is running.')+(Number.isFinite(op.freed_bytes)?' Disk space reclaimed: '+formatBytes(op.freed_bytes)+'.':''));renderDatabase();renderBackup();renderPackages();renderStorage();renderButtons();}
function renderMirror(d){mirror=d;text('#githubStatus',d.status?.phase||'Run a fresh production scan.');const preview=d.preview||{},valid=preview.source_mode==='production-v2'&&Array.isArray(preview.files)&&preview.files.length>0;$('#githubReview').hidden=!valid;if(valid){text('#githubSummary',preview.files.length+' files in this batch'+(preview.pending_count?' · '+preview.pending_count+' remaining':''));text('#githubScanWhen','Scanned '+when(preview.created_at)+' · '+formatBytes(preview.total_bytes));$('#githubFiles').replaceChildren(...preview.files.map(f=>{const row=node('div',f.path,'file-row');row.append(node('small',(f.action||'modify').toUpperCase()+' · '+(f.action==='delete'?'Removed from production':'SHA-256 '+String(f.sha256).slice(0,16)+'… · '+formatBytes(f.bytes))));return row;}));}const link=$('#githubLink');link.replaceChildren();if(/^[a-f0-9]{40}$/.test(d.status?.base_sha||'')){const a=node('a','View latest saved commit ↗','text-link spaced');a.href='https://github.com/blarkin13/neptune-frc-scouting/commit/'+d.status.base_sha;a.target='_blank';a.rel='noopener noreferrer';link.append(a);}if(/^https:\/\/github\.com\/blarkin13\/neptune-frc-scouting\/pull\/\d+$/.test(d.status?.url||'')){const a=node('a','Open draft pull request ↗','text-link spaced');a.href=d.status.url;a.target='_blank';a.rel='noopener noreferrer';link.append(a);}renderButtons();}
let refreshInFlight=null,connectionFailures=0;
function connectionStatus(mode){
 const banner=$('#globalNotice');
 if(mode==='connected'){
  if(connectionWarning){banner.hidden=true;banner.replaceChildren();connectionWarning=false;}
  return;
 }
 connectionWarning=true;banner.replaceChildren();banner.hidden=false;banner.classList.add('error');
 const message=node('span',mode==='auth'?'Your access session has expired. Reconnect to sign in again.':'Connection interrupted. Your last readings are shown while we retry automatically.');
 const actions=node('div',null,'action-row'),retry=node('button','Retry now','button-secondary'),reconnect=node('a','Reconnect','button-secondary');
 retry.addEventListener('click',()=>refresh());reconnect.href='/';actions.append(retry,reconnect);banner.append(message,actions);
}
async function refresh(){
 if(refreshInFlight)return refreshInFlight;
 refreshInFlight=(async()=>{
  const results=await Promise.allSettled([request('/api/telemetry'),request('/api/maintenance/summary'),request('/api/github-mirror/status')]);
  if(results[0].status==='fulfilled')renderTelemetry(results[0].value);
  if(results[1].status==='fulfilled')renderSummary(results[1].value);
  if(results[2].status==='fulfilled')renderMirror(results[2].value);
  const auth=results.slice(0,2).some(r=>r.status==='rejected'&&r.reason?.code==='AUTH_REQUIRED');
  const failed=results[0].status==='rejected'&&results[1].status==='rejected';
  if(auth){connectionFailures++;connectionStatus('auth');text('#connectionBadge','SIGN IN');$('#connectionBadge').classList.remove('connected');}
  else if(failed){connectionFailures++;if(connectionFailures>=2){text('#connectionBadge','RECONNECTING');$('#connectionBadge').classList.remove('connected');connectionStatus('offline');}}
  else{connectionFailures=0;connectionStatus('connected');text('#connectionBadge',state.demo?'PREVIEW':'CONNECTED');$('#connectionBadge').classList.toggle('connected',!state.demo);}
 })();
 try{return await refreshInFlight;}finally{refreshInFlight=null;}
}
window.addEventListener('online',()=>refresh().catch(()=>{}));
window.addEventListener('focus',()=>refresh().catch(()=>{}));
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh().catch(()=>{});});

$('#refreshAll').addEventListener('click',()=>refresh().catch(e=>notice(e.message,true)));$$('[data-action]').forEach(b=>b.addEventListener('click',()=>action({action:b.dataset.action}).catch(e=>notice(e.message,true))));
function chooseFile(file){if(!file)return;if(!/\.zip$/i.test(file.name)||file.size>50*1024*1024){notice('Choose a ZIP package no larger than 50 MB.',true);return;}selectedFile=file;text('#chosenName',file.name);text('#chosenSize',formatBytes(file.size));$('#chosenPackage').hidden=false;renderButtons();}
$('#dropzone').addEventListener('click',()=>$('#packageFile').click());$('#dropzone').addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();$('#packageFile').click();}});$('#packageFile').addEventListener('change',()=>chooseFile($('#packageFile').files?.[0]));$('#dropzone').addEventListener('dragover',e=>{e.preventDefault();$('#dropzone').classList.add('dragover');});$('#dropzone').addEventListener('dragleave',()=>$('#dropzone').classList.remove('dragover'));$('#dropzone').addEventListener('drop',e=>{e.preventDefault();$('#dropzone').classList.remove('dragover');if(e.dataTransfer.files.length!==1){notice('Choose one update package at a time.',true);return;}chooseFile(e.dataTransfer.files[0]);});$('#clearFile').addEventListener('click',()=>{selectedFile=null;$('#packageFile').value='';$('#chosenPackage').hidden=true;renderButtons();});
$('#uploadPackage').addEventListener('click',async()=>{if(!selectedFile||busy())return;sending=true;renderButtons();try{const d=await request('/api/maintenance/upload',{method:'POST',headers:{'Content-Type':'application/zip','X-CSRF':$('meta[name="csrf-token"]').content,'X-Filename':encodeURIComponent(selectedFile.name)},body:selectedFile});notice(d.phase);selectedFile=null;$('#chosenPackage').hidden=true;reviewId=d.id;await refresh();}catch(e){notice(e.message,true);}finally{sending=false;renderButtons();}});
$('#closeReview').addEventListener('click',()=>{reviewId=null;renderReview();});$('#installPackage').addEventListener('click',async()=>{const p=(state.packages||[]).find(p=>p.id===reviewId);if(!p?.review?.plan_digest)return;try{if(await confirmChange('Install this package to production?','This replaces the listed Neptune website files and creates verified file rollback copies. Check the changed pages after installation.','INSTALL TO PRODUCTION',p.review.files.map(f=>f.path)))await action({action:'install',package_id:p.id,plan_digest:p.review.plan_digest,confirmation:'INSTALL TO PRODUCTION'});}catch(e){notice(e.message,true);}});
$('#scanGithub').addEventListener('click',async()=>{if(busy())return;sending=true;renderButtons();try{await post('/api/github-mirror/scan',{});notice('Production-to-GitHub scan queued.');await refresh();}catch(e){notice(e.message,true);}finally{sending=false;renderButtons();}});
$('#publishGithub').addEventListener('click',async()=>{if(busy())return;try{if(!await confirmChange('Sync production to GitHub main now?','This saves eligible production source changes directly to blarkin13/neptune-frc-scouting main. The next automatic sync runs at 11:00 p.m. Central.','SYNC TO MAIN',mirror?.preview?.files?.map(f=>f.path)||[]))return;sending=true;renderButtons();await post('/api/github-mirror/sync',{});notice('Sync to GitHub main queued.');await refresh();}catch(e){notice(e.message,true);}finally{sending=false;renderButtons();}});
$('#createBackup').addEventListener('click',async()=>{try{if(await confirmChange('Create an encrypted production backup?','This backs up Neptune’s database, uploads and game files to the existing private GitHub backup repository. It uses the existing encryption key and retention policy.','',[]))await action({action:'backup_now'});}catch(e){notice(e.message,true);}});
$('#deleteStorage').addEventListener('click',async()=>{const rows=storageSelection();if(!rows.length)return;try{if(await confirmChange('Permanently delete these archives?','The selected old update ZIPs will be removed, reclaiming approximately '+formatBytes(rows.reduce((n,r)=>n+Number(r.allocated_bytes||r.bytes),0))+'. They cannot be restored by this center after deletion.','DELETE FILES',rows.map(r=>r.name)))await action({action:'storage_delete',plan_id:state.storage.plan_id,plan_digest:state.storage.plan_digest,selected:rows.map(r=>r.id),confirmation:'DELETE FILES'});}catch(e){notice(e.message,true);}});

// Frictionless control-panel updater: drop a ZIP, it inspects immediately, one Install button, toast, clear.
(()=>{const drop=$('#controlDrop'),review=$('#controlReview');if(!drop)return;
function resetControl(){controlPackage=null;review.hidden=true;drop.hidden=false;$('#controlReviewFiles').replaceChildren();text('#controlStatus','');renderButtons();}
async function inspectControl(file){
 if(!/\.zip$/i.test(file.name)||file.size>8*1024*1024){toast('Choose a ZIP no larger than 8 MB.','error');return;}
 text('#controlStatus','Inspecting '+file.name+'…');
 try{const d=await request('/api/mission-control/upload',{method:'POST',headers:{'Content-Type':'application/zip','X-CSRF':$('meta[name="csrf-token"]').content,'X-Filename':file.name},body:file});
  controlPackage=d;const plan=d.plan||{};text('#controlReviewName',file.name);
  text('#controlReviewMeta','v'+(plan.version||'?')+' · '+(plan.files||[]).length+' file'+((plan.files||[]).length===1?'':'s'));
  const sig=$('#controlSig');const safe=plan.verified_signature||plan.schema===1;
  sig.textContent=plan.verified_signature?'Signature verified':(plan.schema===1?'Web-only':'Unsigned');sig.className='pill'+(safe?' connected':'');
  $('#controlReviewFiles').replaceChildren(...(plan.files||[]).map(f=>{const row=node('div',f.path,'file-row');row.append(node('small','SHA-256 '+String(f.sha256||'').slice(0,16)+'… · '+formatBytes(f.bytes||0)));return row;}));
  drop.hidden=true;review.hidden=false;text('#controlStatus','');renderButtons();
 }catch(e){controlPackage=null;toast(e.message,'error');text('#controlStatus','');}}
drop.addEventListener('click',()=>$('#controlFile').click());
drop.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();$('#controlFile').click();}});
$('#controlFile').addEventListener('change',()=>{const f=$('#controlFile').files?.[0];if(f)inspectControl(f);$('#controlFile').value='';});
drop.addEventListener('dragover',e=>{e.preventDefault();drop.classList.add('dragover');});
drop.addEventListener('dragleave',()=>drop.classList.remove('dragover'));
drop.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('dragover');const f=e.dataTransfer?.files?.[0];if(f)inspectControl(f);});
$('#controlClear').addEventListener('click',resetControl);
$('#installControl').addEventListener('click',async()=>{if(!controlPackage||busy())return;const label=controlPackage.plan?.version||'the update';$('#installControl').disabled=true;
 try{await post('/api/mission-control/request',{package_id:controlPackage.id});toast('Installing '+label+' — the panel will restart and reconnect.','success');resetControl();}
 catch(e){toast(e.message,'error');$('#installControl').disabled=false;}});})();
refresh().catch(e=>notice(e.message,true));setInterval(()=>{if(!document.hidden)refresh().catch(()=>{});},15000);
// Hamburger menu: the section list lives in a slide-out drawer. While it is open the page behind is
// inert, Escape/backdrop/a choice closes it, and focus returns to the menu button.
(()=>{const drawer=$('#navDrawer'),toggle=$('#menuToggle'),backdrop=$('#drawerBackdrop'),page=$('main'),crumb=$('#crumbSection');if(!drawer||!toggle)return;
const isOpen=()=>drawer.classList.contains('open');
function set(open,returnFocus=true){drawer.classList.toggle('open',open);document.body.classList.toggle('drawer-open',open);backdrop.hidden=!open;drawer.inert=!open;if(page)page.inert=open;toggle.setAttribute('aria-expanded',String(open));
 if(open)(drawer.querySelector('.nav-link.active')||drawer.querySelector('.nav-link'))?.focus();else if(returnFocus)toggle.focus();}
function syncCrumb(){const active=drawer.querySelector('.nav-link.active');if(crumb&&active)crumb.textContent=[...active.childNodes].filter(n=>n.nodeType===3).map(n=>n.textContent).join('').trim().toUpperCase();}
drawer.inert=true;syncCrumb();
toggle.addEventListener('click',()=>set(!isOpen()));$('#menuClose')?.addEventListener('click',()=>set(false));backdrop.addEventListener('click',()=>set(false));
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&isOpen()){e.preventDefault();set(false);}});
drawer.querySelectorAll('[data-tab],.brand').forEach(e=>e.addEventListener('click',()=>{if(isOpen())set(false);setTimeout(syncCrumb);}));
window.addEventListener('hashchange',()=>setTimeout(syncCrumb));})();
// Database explorer: schema tree, browse/structure, and a guarded SQL window. All queries run on the
// server through limited MySQL accounts (read: SELECT-only; write: DML-only, DDL/FILE-less). Writes
// execute from the action buttons. Authentication and server validation remain in force.
(()=>{const panel=$('#panel-database');if(!panel)return;
let tables=[],current=null,cols=[],pk=[],page=0,total=0,lastGrid=null,sqlGrid=null,loaded=false;const LIMIT=50;
const cell=v=>v===null?node('span','NULL','cell-null'):node('span',String(v));
function grid(head,body,columns,rows,actions){head.replaceChildren();body.replaceChildren();
 const tr=node('tr');if(actions)tr.append(node('th',''));columns.forEach(c=>tr.append(node('th',c)));head.append(tr);
 if(!rows.length){const r=node('tr'),td=node('td','No rows.','empty');td.colSpan=columns.length+(actions?1:0);r.append(td);body.append(r);return;}
 rows.forEach((row,i)=>{const r=node('tr');if(actions){const td=node('td',null,'db-rowact');
   const e=node('button','Edit','button-tertiary');e.addEventListener('click',()=>editRow(row));
   const d=node('button','Delete','button-tertiary');d.addEventListener('click',()=>deleteRow(row));
   if(pk.length){td.append(e,d);}else td.append(node('span','no key','muted small'));r.append(td);}
   row.forEach(v=>{const td=node('td');td.append(cell(v));r.append(td);});body.append(r);});}
function toCSV(columns,rows){const esc=v=>v===null?'':/[",\n]/.test(String(v))?'"'+String(v).replace(/"/g,'""')+'"':String(v);
 return [columns.map(esc).join(','),...rows.map(r=>r.map(esc).join(','))].join('\n');}
function download(name,text){const b=new Blob([text],{type:'text/csv'});const u=URL.createObjectURL(b);const a=node('a');a.href=u;a.download=name;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(u),1000);}
async function loadSchema(){try{const d=await request('/api/db/schema');tables=d.tables||[];text('#dbSchemaName',d.database||'scouting');renderTree();loaded=true;}catch(e){$('#dbTables').replaceChildren(node('p',e.message,'empty'));}}
function renderTree(){const q=($('#dbFilter').value||'').toLowerCase();const box=$('#dbTables');box.replaceChildren();
 const show=tables.filter(t=>t.name.toLowerCase().includes(q));if(!show.length){box.append(node('p','No tables.','empty'));return;}
 show.forEach(t=>{const b=node('button',null,'db-table'+(t.name===current?' active':''));b.append(node('span',t.name,'db-table-name'),node('span',(t.rows??'?')+' rows','db-table-meta'));
  b.addEventListener('click',()=>selectTable(t.name));box.append(b);});}
async function selectTable(name){current=name;page=0;renderTree();setView('browse');await Promise.all([loadStructure(),loadBrowse()]);}
async function loadBrowse(){if(!current)return;try{const d=await request('/api/db/browse?name='+encodeURIComponent(current)+'&page='+page+'&limit='+LIMIT);
 cols=d.columns||[];pk=d.primary_key||[];total=d.total_rows||0;lastGrid={columns:cols,rows:d.rows||[]};
 text('#dbBrowseTitle',current);text('#dbBrowseInfo',total.toLocaleString()+' rows'+(pk.length?' · key: '+pk.join(', '):' · no primary key (read-only rows)'));
 grid($('#dbBrowseHead'),$('#dbBrowseBody'),cols,d.rows||[],true);
 const pages=Math.max(1,Math.ceil(total/LIMIT));text('#dbPageInfo','Page '+(page+1)+' of '+pages);
 $('#dbPrev').disabled=page<=0;$('#dbNext').disabled=page>=pages-1;$('#dbExport').disabled=!(d.rows||[]).length;$('#dbInsert').disabled=!pk.length;
 }catch(e){notice(e.message,true);}}
async function loadStructure(){if(!current)return;try{const d=await request('/api/db/table?name='+encodeURIComponent(current));
 text('#dbStructureTitle',current);const b=$('#dbStructureBody');b.replaceChildren();
 (d.columns||[]).forEach(c=>{const tr=node('tr');tr.append(node('td',c.name),node('td',c.type),node('td',c.nullable?'YES':'NO'),node('td',c.key||''),node('td',c.default===null?'NULL':String(c.default??'')),node('td',c.extra||''));b.append(tr);});
 const idx=$('#dbIndexes');idx.replaceChildren();if((d.indexes||[]).length){idx.append(node('h3','Indexes'));const wrap=node('div',null,'table-wrap'),t=node('table'),thead=node('thead'),tr=node('tr');['Index','Columns','Unique'].forEach(h=>tr.append(node('th',h)));thead.append(tr);const tb=node('tbody');d.indexes.forEach(i=>{const r=node('tr');r.append(node('td',i.name),node('td',(i.columns||[]).join(', ')),node('td',i.unique?'yes':'no'));tb.append(r);});t.append(thead,tb);wrap.append(t);idx.append(wrap);}
 }catch(e){notice(e.message,true);}}
function setView(v){$$('.db-tab').forEach(b=>b.classList.toggle('active',b.dataset.dbView===v));
 $('#dbBrowse').hidden=v!=='browse';$('#dbStructure').hidden=v!=='structure';$('#dbSql').hidden=v!=='sql';}
function rowObject(row){const o={};cols.forEach((c,i)=>o[c]=row[i]);return o;}
function keyOf(row){const o=rowObject(row),k={};(pk.length?pk:[]).forEach(c=>k[c]=o[c]);return k;}
async function editRow(row){const o=rowObject(row);await openRowDialog('Edit row',o,async changes=>{
 if(!Object.keys(changes).length){notice('No changes.');return;}
 const summary=Object.entries(changes).map(([c,v])=>c+' → '+(v===null?'NULL':v));
 if(await confirmChange('Apply this change?','UPDATE on '+current+' (one row, matched by its key).','APPLY',[...summary,'WHERE '+Object.entries(keyOf(row)).map(([c,v])=>c+'='+v).join(' AND ')]))
  await write({table:current,changes,keys:keyOf(row)});});}
async function deleteRow(row){if(await confirmChange('Delete this row?','DELETE on '+current+' (one row, matched by its key). This cannot be undone from here.','APPLY',Object.entries(keyOf(row)).map(([c,v])=>c+'='+v)))
 await write({table:current,keys:keyOf(row),delete:true});}
async function insertRow(){const blank={};cols.forEach(c=>blank[c]=null);await openRowDialog('Insert row',blank,async changes=>{
 if(await confirmChange('Insert this row?','INSERT into '+current+'.','APPLY',Object.entries(changes).map(([c,v])=>c+' = '+(v===null?'NULL':v))))
  await write({table:current,insert:changes});},true);}
async function write(body){try{const d=await post('/api/db/write',{...body,confirm:'APPLY'});notice((d.affected_rows??0)+' row(s) affected.');await loadBrowse();}catch(e){notice(e.message,true);}}
function openRowDialog(title,obj,onsave,isInsert){return new Promise(resolve=>{const dlg=$('#rowDialog');text('#rowTitle',title);text('#rowSub',current+(pk.length?' · key: '+pk.join(', '):''));
 const box=$('#rowFields');box.replaceChildren();const inputs={};
 cols.forEach(c=>{const wrap=node('label',null,'row-field');wrap.append(node('span',c,'row-field-label'));const inp=node('input');inp.value=obj[c]===null?'':String(obj[c]);inp.dataset.col=c;if(pk.includes(c)&&!isInsert)inp.readOnly=true;inputs[c]=inp;wrap.append(inp);
   const nullable=node('label',null,'row-null');const cb=node('input');cb.type='checkbox';cb.checked=obj[c]===null;cb.addEventListener('change',()=>{inp.disabled=cb.checked;});inp.disabled=cb.checked;nullable.append(cb,node('span','NULL'));wrap.append(nullable);box.append(wrap);});
 dlg.returnValue='cancel';dlg.showModal();
 const onClose=async()=>{dlg.removeEventListener('close',onClose);if(dlg.returnValue!=='save'){resolve();return;}
   const changes={};cols.forEach(c=>{const inp=inputs[c];const val=inp.disabled?null:inp.value;if(isInsert){if(!(pk.includes(c)&&val==='' ))changes[c]=val;}else{const before=obj[c];const norm=inp.disabled?null:inp.value;if(String(before===null?'∅':before)!==String(norm===null?'∅':norm))changes[c]=norm;}});
   resolve();await onsave(changes);};
 dlg.addEventListener('close',onClose);});}
async function runSql(){const sql=$('#dbSqlText').value.trim();if(!sql)return;const verb=(sql.match(/^[a-zA-Z]+/)||[''])[0].toLowerCase();
 const isWrite=['insert','update','delete'].includes(verb);
 try{if(isWrite){if(!await confirmChange('Run this write?','It runs once through the DML-only account. UPDATE/DELETE must have a WHERE clause.','APPLY',[sql.slice(0,400)]))return;
   const d=await post('/api/db/write',{sql,confirm:'APPLY'});text('#dbSqlInfo',(d.affected_rows??0)+' row(s) affected.');grid($('#dbSqlHead'),$('#dbSqlBody'),[],[]);if(current)loadBrowse();}
  else{const d=await post('/api/db/query',{sql});sqlGrid={columns:d.columns||[],rows:d.rows||[]};grid($('#dbSqlHead'),$('#dbSqlBody'),d.columns||[],d.rows||[]);
   text('#dbSqlInfo',(d.row_count??(d.rows||[]).length)+' rows'+(d.truncated?' (truncated)':'')+(d.elapsed_ms!=null?' · '+d.elapsed_ms+' ms':''));$('#dbSqlExport').disabled=!(d.rows||[]).length;}
 }catch(e){text('#dbSqlInfo','');notice(e.message,true);}}
panel.querySelectorAll('.db-tab').forEach(b=>b.addEventListener('click',()=>setView(b.dataset.dbView)));
$('#dbFilter').addEventListener('input',renderTree);$('[data-db-refresh]').addEventListener('click',loadSchema);
$('#dbPrev').addEventListener('click',()=>{if(page>0){page--;loadBrowse();}});$('#dbNext').addEventListener('click',()=>{page++;loadBrowse();});
$('#dbInsert').addEventListener('click',insertRow);$('#dbRun').addEventListener('click',runSql);
$('#dbExport').addEventListener('click',()=>{if(lastGrid)download(current+'-page'+(page+1)+'.csv',toCSV(lastGrid.columns,lastGrid.rows));});
$('#dbSqlExport').addEventListener('click',()=>{if(sqlGrid)download('query.csv',toCSV(sqlGrid.columns,sqlGrid.rows));});
// Load the schema the first time the Database section is opened.
const watch=()=>{if(location.hash.slice(1)==='database'&&!loaded)loadSchema();};
window.addEventListener('rc-open-table',async e=>{location.hash='database';if(!loaded)await loadSchema();await selectTable(e.detail);});window.addEventListener('hashchange',watch);watch();})();


(()=>{
  (function initServerTerminal(){
    const root=document.getElementById('nmTty');
    const form=document.getElementById('nmTtyForm');
    const input=document.getElementById('nmTtyInput');
    const history=document.getElementById('nmTtyHistory');
    const scroller=document.getElementById('nmTtyScroll');
    const promptEl=document.getElementById('nmTtyPrompt');
    const status=document.getElementById('nmTtyStatus');
    const latest=document.getElementById('nmTtyLatest');
    if(!root||!form||!input||!history||!scroller||!promptEl||!status||!latest||typeof fetch!=='function') return;

    const user=root.dataset.user||'www-data';
    let host=root.dataset.host||'neptune';
    const home=(root.dataset.home||'').replace(/\/+$/,'');
    const sigil=user==='root'?'#':'$';
    const MAX_ENTRIES=150, MAX_RECALL=100;
    let cwd=root.dataset.cwd||'/';
    let busy=false;
    const past=[];
    let pastIndex=0, draft='';

    const el=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
    const shortPath=dir=>{
      if(home&&dir===home) return '~';
      if(home&&dir.startsWith(home+'/')) return '~'+dir.slice(home.length);
      return dir;
    };
    function fillPrompt(target,dir){
      target.textContent='';
      target.append(el('span','nm-tty-ph',user+'@'+host),':',el('span','nm-tty-pp',shortPath(dir)),sigil);
    }
    function atBottom(){return scroller.scrollHeight-scroller.scrollTop-scroller.clientHeight<90;}
    function toEnd(){scroller.scrollTop=scroller.scrollHeight;}
    function autosize(){
      const max=Math.max(120,Math.round(window.innerHeight*0.4));
      input.style.height='auto';
      input.style.height=Math.min(input.scrollHeight,max)+'px';
      input.style.overflowY=input.scrollHeight>max?'auto':'hidden';
    }
    function setInput(value){
      input.value=value;autosize();
      input.setSelectionRange(value.length,value.length);
    }
    function setStatus(text,isError){
      status.textContent=text||'';
      status.classList.toggle('is-error',!!isError);
    }
    function addEntry(dir,command){
      const entry=el('div','nm-tty-entry');
      const line=el('div','nm-tty-cmd');
      const p=el('span','nm-tty-prompt');
      fillPrompt(p,dir);
      line.append(p,' ',el('span','nm-tty-cmdtext',command));
      entry.append(line);
      history.append(entry);
      while(history.querySelectorAll('.nm-tty-entry').length>MAX_ENTRIES){
        history.querySelector('.nm-tty-entry')?.remove();
      }
      return entry;
    }
    // The copy button copies only the most recent command's result.
    function setLatest(text){latest.textContent=text||'';}
    function clearScreen(){
      history.textContent='';
      setLatest('');
      setStatus('');
      toEnd();
    }
    function setBusy(state){
      busy=state;
      form.classList.toggle('is-busy',state);
      if(!state){
        fillPrompt(promptEl,cwd);
        input.focus({preventScroll:true});
      }
    }
    function failureHint(response,endpoint){
      if(response.redirected) return 'Neptune redirected the request. Sign in again and reload Maintenance Console.';
      if(response.status===419) return 'Your security token expired. Reload Maintenance Console.';
      if(response.status===403) return 'Platform-owner authorization was rejected. Sign in with strong authentication.';
      if(response.status>=500) return 'Neptune returned a server error. Review Apache/PHP logs in AWS.';
      return 'Neptune returned a non-JSON response (HTTP '+response.status+') from '+endpoint+'. Verify the public Maintenance Console route.';
    }

    async function run(){
      if(busy) return;
      const typed=input.value.replace(/\r\n?/g,'\n').trim();
      const dir=cwd;
      input.value='';autosize();setStatus('');
      if(!typed){            // empty Enter just prints a fresh prompt, like a real shell
        addEntry(dir,'');toEnd();return;
      }
      if(past[past.length-1]!==typed){past.push(typed);if(past.length>MAX_RECALL)past.shift();}
      pastIndex=past.length;draft='';
      if(typed==='clear'){clearScreen();return;}

      const entry=addEntry(dir,typed);
      entry.classList.add('is-running');
      setBusy(true);
      toEnd();

      const body=JSON.stringify({command:typed,cwd:dir});
      const controller=new AbortController();
      const abortTimer=setTimeout(()=>controller.abort(),75000);
      try{
        const endpoint='/api/console/run';
        const response=await fetch(endpoint,{
          method:'POST',body,credentials:'same-origin',redirect:'follow',cache:'no-store',
          signal:controller.signal,
          headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF':$('meta[name="csrf-token"]').content}
        });
        if(!(response.headers.get('content-type')||'').toLowerCase().includes('application/json')){
          throw new Error(failureHint(response,endpoint));
        }
        let result;
        try{result=await response.json();}catch(_){throw new Error('The server returned malformed JSON (HTTP '+response.status+').');}
        if(!response.ok||result?.error){
          throw new Error(String(result?.error||('Terminal request failed (HTTP '+response.status+').')));
        }
        const raw=String(result.raw??result.output??'');
        if(raw) entry.append(el('div','nm-tty-out',raw));
        const code=Number.isInteger(result.exit_code)?result.exit_code:null;
        let exitNote='';
        if(code!==null&&code!==0){
          exitNote=result.timed_out?'[exit 124 · stopped after the 60 second limit]':'[exit '+code+']';
          entry.append(el('div','nm-tty-exit',exitNote));
        }
        setLatest(raw+(raw&&exitNote?'\n':'')+exitNote);
        if(typeof result.cwd==='string'&&result.cwd.startsWith('/')) cwd=result.cwd;
      }catch(error){
        const message=error?.name==='AbortError'
          ? 'Request timed out; command outcome is unknown. Check before retrying.'
          : String(error?.message||'Could not reach Neptune. Command outcome is unknown.');
        entry.append(el('div','nm-tty-out is-error','[error] '+message));
        setLatest('[error] '+message);
        setStatus(message,true);
      }finally{
        clearTimeout(abortTimer);
        entry.classList.remove('is-running');
        setBusy(false);
        autosize();
        toEnd();
      }
    }

    form.addEventListener('submit',event=>{event.preventDefault();run();});
    input.addEventListener('input',()=>{autosize();toEnd();});
    input.addEventListener('keydown',event=>{
      if(event.isComposing) return;
      const plain=!event.shiftKey&&!event.altKey&&!event.ctrlKey&&!event.metaKey;
      // Enter (or Ctrl/Cmd+Enter) runs; Shift+Enter inserts a newline.
      if(event.key==='Enter'&&(!event.shiftKey||event.ctrlKey||event.metaKey)){
        event.preventDefault();run();return;
      }
      // Ctrl+L clears the screen.
      if(event.ctrlKey&&!event.altKey&&!event.metaKey&&event.key.toLowerCase()==='l'){
        event.preventDefault();clearScreen();return;
      }
      // Ctrl+C with nothing selected abandons the current line, like a real shell.
      if(event.ctrlKey&&!event.altKey&&!event.metaKey&&!event.shiftKey&&event.key.toLowerCase()==='c'
         &&input.selectionStart===input.selectionEnd){
        event.preventDefault();
        addEntry(cwd,input.value.replace(/\s+$/,'')+'^C');
        input.value='';autosize();pastIndex=past.length;draft='';toEnd();return;
      }
      if(plain&&event.key==='ArrowUp'&&input.selectionStart===input.selectionEnd
         &&!input.value.slice(0,input.selectionStart).includes('\n')&&past.length&&pastIndex>0){
        event.preventDefault();
        if(pastIndex===past.length) draft=input.value;
        pastIndex-=1;setInput(past[pastIndex]);return;
      }
      if(plain&&event.key==='ArrowDown'&&input.selectionStart===input.selectionEnd
         &&!input.value.slice(input.selectionEnd).includes('\n')&&pastIndex<past.length){
        event.preventDefault();
        pastIndex+=1;setInput(pastIndex===past.length?draft:past[pastIndex]);
      }
    });
    // Clicking anywhere on the screen focuses the input, unless text is being selected.
    scroller.addEventListener('click',event=>{
      if(busy||input.disabled||event.target.closest('a')) return;
      if(String(window.getSelection?.()||'')!=='') return;
      input.focus({preventScroll:true});
    });
    window.addEventListener('resize',autosize);
    request('/api/console/status').then(d=>{if(d.uid!==0)throw new Error('Root broker identity mismatch');host=d.hostname;cwd=d.cwd;fillPrompt(promptEl,cwd);text('#terminalConnection','ROOT · CONNECTED');}).catch(e=>{text('#terminalConnection','UNAVAILABLE');setStatus(e.message,true);});
    fillPrompt(promptEl,cwd);
    autosize();
  })();
  const copyIcon='<i class="fa-regular fa-copy" aria-hidden="true"></i>';
  const checkIcon='<i class="fa-solid fa-check" aria-hidden="true"></i>';
  const errorIcon='<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>';
  document.querySelectorAll('[data-nm-copy-output]').forEach(copyButton=>{
    let resetTimer=null;
    const defaultLabel=copyButton.getAttribute('aria-label')||'Copy terminal transcript';
    const defaultTitle=copyButton.title||'Copy transcript';
    copyButton.addEventListener('click',async()=>{
      const output=document.getElementById(copyButton.getAttribute('data-nm-copy-output'));
      const status=document.getElementById(copyButton.getAttribute('data-nm-copy-status'));
      if(!output) return;
      clearTimeout(resetTimer);
      copyButton.classList.remove('is-copied','is-copy-error');
      copyButton.innerHTML=copyIcon;
      copyButton.disabled=true;
      try{
        const text=(output.innerText||output.textContent||'').replace(/\n{3,}/g,'\n\n');
        if(!text.trim()) throw new Error(output.dataset.nmEmptyMessage||'Nothing to copy yet.');
        if(navigator.clipboard && typeof navigator.clipboard.writeText==='function'){
          await navigator.clipboard.writeText(text);
        }else{
          const holder=document.createElement('textarea');
          holder.value=text;holder.setAttribute('readonly','');
          holder.style.position='fixed';holder.style.top='-1000px';
          document.body.appendChild(holder);
          holder.select();holder.setSelectionRange(0,holder.value.length);
          let copied=false;
          try{copied=document.execCommand('copy');}finally{holder.remove();}
          if(!copied)throw new Error('Clipboard unavailable. Select the transcript and copy manually.');
        }
        copyButton.innerHTML=checkIcon;
        copyButton.classList.add('is-copied');
        copyButton.setAttribute('aria-label','Copied to clipboard');
        copyButton.title='Copied';
        if(status){status.classList.remove('is-error');status.textContent='Copied to clipboard.';}
      }catch(error){
        copyButton.innerHTML=errorIcon;
        copyButton.classList.add('is-copy-error');
        copyButton.setAttribute('aria-label','Copy failed');
        copyButton.title='Copy failed';
        if(status){status.classList.add('is-error');status.textContent=String(error?.message||'Clipboard unavailable.');}
      }finally{
        copyButton.disabled=false;
        resetTimer=setTimeout(()=>{
          copyButton.innerHTML=copyIcon;
          copyButton.classList.remove('is-copied','is-copy-error');
          copyButton.setAttribute('aria-label',defaultLabel);
          copyButton.title=defaultTitle;
          // Keep command errors visible; remove transient clipboard notices.
          if(status?.textContent==='Copied to clipboard.')status.textContent='';
        },2200);
      }
    });
  });
})();

// Native RC System modules. Every view uses RC's own DOM and authenticated API.
(()=>{
 const call=(op,args={})=>post('/api/system/dispatch',{op,args});
 const fail=e=>notice(e.message||String(e),true);
 const button=(label,icon,fn,cls='button-secondary')=>{const b=node('button',null,cls);const i=node('i');i.className='fa fa-solid '+icon;i.setAttribute('aria-hidden','true');b.append(i,document.createTextNode(' '+label));b.addEventListener('click',async()=>{b.disabled=true;try{await fn();}catch(e){fail(e);}finally{b.disabled=false;}});return b;};
 function table(target,columns,rows,actions){const wrap=$(target),t=node('table'),head=node('thead'),hr=node('tr');for(const [label] of columns)hr.append(node('th',label));if(actions)hr.append(node('th','Actions'));head.append(hr);const body=node('tbody');for(const row of rows){const tr=node('tr');for(const [,key,format] of columns)tr.append(node('td',format?format(row[key],row):String(row[key]??'—')));if(actions){const td=node('td');td.append(actions(row));tr.append(td);}body.append(tr);}t.append(head,body);wrap.replaceChildren(t);if(!rows.length)wrap.append(node('p','No records.','empty'));}
 function download(d){if(!/^[a-f0-9]{32}$/.test(d.download_id||''))throw new Error('Download was not prepared');const a=node('a');a.href='/api/system/download?id='+d.download_id;a.download=d.name||'Neptune-download';document.body.append(a);a.click();a.remove();}
 function fields(form){const out=Object.fromEntries(new FormData(form));form.querySelectorAll('input[type=checkbox]').forEach(i=>out[i.name]=i.checked);return out;}
 let orgs=[],selectedOrg=0,platformId=1;
 async function platform(){const d=await call('platform.list');orgs=d.organizations||[];platformId=d.platform_id;renderOrgs();}
 function renderOrgs(){const q=$('#platformFilter').value.toLowerCase();const list=$('#platformOrganizations');list.replaceChildren();for(const o of orgs.filter(o=>[o.name,o.slug,o.id].join(' ').toLowerCase().includes(q))){const card=node('div',null,'native-tile');card.append(node('h3',o.name),node('p',(o.platform_status||'active')+' · '+o.active_user_count+'/'+o.user_count+' active users · '+o.team_count+' teams','small muted'));const row=node('div',null,'action-row');row.append(button('Details','fa-building-shield',()=>orgDetail(o.id)),button('Export','fa-download',async()=>download(await call('platform.export',{id:o.id}))));card.append(row);list.append(card);}}
 async function orgDetail(id){selectedOrg=id;const d=await call('platform.detail',{id}),o=d.organization;$('#platformDetail').hidden=false;text('#platformName',o.name);const facts=$('#platformFacts');facts.replaceChildren(...[['Status',o.platform_status],['Workspace',o.google_workspace_domain||'None'],['Created',when(o.created_at)]].map(([k,v])=>{const n=node('div');n.append(node('small',k),node('strong',v));return n;}));const actions=$('#platformActions');actions.replaceChildren();const act=async action=>{const result=await call('platform.action',{id,action});notice(result.message);await platform();await orgDetail(id);};if(id!==platformId)actions.append(button(o.platform_status==='suspended'?'Reactivate':'Suspend','fa-power-off',()=>act(o.platform_status==='suspended'?'reactivate':'suspend')));actions.append(button('Clear Workspace restriction','fa-unlink',()=>act('clear_google_domain')));const owners=$('#platformOwners');owners.replaceChildren();for(const u of d.owners){const row=node('div',null,'native-owner'),copy=node('div');copy.append(node('strong',u.display_name||u.username),node('p',(u.email||'')+' · '+(u.active?'Active':'Inactive'),'small muted'));row.append(copy);if(u.active)row.append(button('Reset password','fa-key',async()=>{const result=await call('platform.action',{id,action:'reset_owner',owner_id:u.id});text('#platformOneTime','Temporary password for '+(u.display_name||u.username)+': '+result.temporary_password+'\nThe owner must change it at next sign-in. Copy it now; it is not retained by RC.');$('#platformOneTime').hidden=false;}));owners.append(row);}table('#platformTeams',[['Team','frc_team_number'],['Name','display_name'],['Active','active']],d.teams);table('#platformAudit',[['Action','action'],['Entity','entity_id'],['When','created_at',when]],d.audit);}
 $('#platformRefresh').addEventListener('click',()=>platform().catch(fail));$('#platformFilter').addEventListener('input',renderOrgs);
 let directory='/var/www/neptune/public_html/Neptune',parentDirectory='/var/www/neptune/public_html',editing=null,entries=[],selection=new Set(),sortKey='name',sortDirection=1,view='list';
 const kind=r=>r.is_link?'Link':r.is_dir?'Folder':'File';
 const previewable=r=>!r.is_dir&&/\.(png|jpe?g|gif|webp|avif|svg)$/i.test(r.name);
 function dialog(title){const d=node('dialog',null,'file-dialog'),h=node('div',null,'section-heading'),heading=node('h2',title),close=button('Close','fa-xmark',()=>d.close());h.append(heading,close);d.append(h);document.body.append(d);d.addEventListener('click',e=>{if(e.target===d)d.close();});d.addEventListener('close',()=>d.remove(),{once:true});return d;}
 async function files(){const d=await call('files.list',{path:directory});directory=d.path;parentDirectory=d.parent||'/';entries=d.entries;selection.clear();$('#filePath').value=directory;renderFiles();}
 function sortedFiles(){return [...entries].sort((a,b)=>{if(a.is_dir!==b.is_dir)return a.is_dir?-1:1;let av=sortKey==='type'?kind(a):a[sortKey],bv=sortKey==='type'?kind(b):b[sortKey];return sortDirection*(typeof av==='number'?av-bv:String(av).localeCompare(String(bv),undefined,{numeric:true,sensitivity:'base'}));});}
 function setSort(key){sortDirection=sortKey===key?-sortDirection:1;sortKey=key;$('#fileSort').value=key;renderFiles();}
 function selectItem(r,checked){checked?selection.add(r.path):selection.delete(r.path);selectionState();}
 function selectionState(){const n=selection.size;$('#fileSelection').hidden=!n;text('#fileSelectionCount',n+' selected');}
 function check(r){const input=node('input');input.type='checkbox';input.checked=selection.has(r.path);input.setAttribute('aria-label','Select '+r.name);input.addEventListener('click',e=>e.stopPropagation());input.addEventListener('change',()=>selectItem(r,input.checked));return input;}
 function bindFileClicks(control,r){let singleClick=null;control.addEventListener('click',()=>{clearTimeout(singleClick);singleClick=setTimeout(()=>{singleClick=null;itemModal(r);},400);});control.addEventListener('dblclick',e=>{e.preventDefault();clearTimeout(singleClick);singleClick=null;if(r.is_link)itemModal(r);else openItem(r).catch(fail);});}
 function renderFiles(){const wrap=$('#fileListing');wrap.replaceChildren();const rows=sortedFiles();text('#fileDirection',sortDirection===1?'↑ Ascending':'↓ Descending');if(view==='grid'){const grid=node('div',null,'fm-grid');for(const r of rows){const card=node('div',null,'fm-card');card.classList.toggle('is-selected',selection.has(r.path));const open=node('button',null,'fm-card-open');open.append(node('span',r.is_dir?'📁':previewable(r)?'▧':'▤','fm-file-icon'),node('strong',r.name),node('small',kind(r)+' · '+(r.is_dir?'Folder':formatBytes(r.bytes))));bindFileClicks(open,r);card.append(check(r),open);grid.append(card);}wrap.append(grid);}else{const t=node('table'),head=node('thead'),hr=node('tr'),all=node('input');all.type='checkbox';all.setAttribute('aria-label','Select all items');all.checked=rows.length>0&&selection.size===rows.length;all.addEventListener('change',()=>{selection=new Set(all.checked?rows.map(r=>r.path):[]);renderFiles();});const cell=node('th');cell.append(all);hr.append(cell);for(const [label,key] of [['Name','name'],['Type','type'],['Size','bytes'],['Modified','modified']]){const th=node('th');th.setAttribute('aria-sort',sortKey===key?(sortDirection===1?'ascending':'descending'):'none');const b=node('button',label+' '+(sortKey===key?(sortDirection===1?'▴':'▾'):'▴▾'),'file-sort');b.addEventListener('click',()=>setSort(key));th.append(b);hr.append(th);}hr.append(node('th','Actions'));head.append(hr);const body=node('tbody');for(const r of rows){const tr=node('tr'),selected=node('td'),name=node('td'),link=node('button',(r.is_dir?'📁 ':'')+r.name,'file-name');selected.append(check(r));bindFileClicks(link,r);name.append(link);const actions=node('td');actions.append(button(r.is_dir?'Open':'Actions',r.is_dir?'fa-folder-open':'fa-ellipsis',()=>r.is_dir?openItem(r):itemModal(r)));tr.append(selected,name,node('td',kind(r)),node('td',r.is_dir?'—':formatBytes(r.bytes)),node('td',new Date(r.modified*1000).toLocaleString()),actions);body.append(tr);}t.append(head,body);wrap.append(t);}if(!rows.length)wrap.append(node('p','This folder is empty.','empty'));selectionState();}
 async function openItem(r){if(r.is_link)throw new Error('Symbolic links are not followed.');if(r.is_dir){directory=r.path;await files();}else await edit(r.path);}
 async function renameItem(r){const name=await textEntry('Rename '+r.name,r.name,'New name');if(name===null)return;if(!name||/[\/\\]/.test(name)||name==='.'||name==='..')throw new Error('Enter a name without directory separators');notice((await call('files.move',{path:r.path,destination:r.path.slice(0,r.path.lastIndexOf('/')+1)+name})).message);}
 async function pickFolder(start=directory){return new Promise(resolve=>{const d=dialog('Choose destination folder'),path=node('p',null,'fm-path'),list=node('div',null,'fm-destinations'),actions=node('div',null,'action-row end');let chosen=start,done=false;const finish=value=>{if(done)return;done=true;resolve(value);d.close();};const cancel=button('Cancel','fa-xmark',()=>finish(null)),use=button('Move here','fa-check',()=>finish(chosen),'action-primary');actions.append(cancel,use);d.append(path,list,actions);d.addEventListener('close',()=>{if(!done){done=true;resolve(null);}});async function load(p){const data=await call('files.list',{path:p});chosen=data.path;path.textContent=chosen;use.disabled=chosen==='/';list.replaceChildren();if(chosen!=='/')list.append(button('↑ Parent folder','fa-arrow-up',()=>load(data.parent||'/')));for(const r of data.entries.filter(r=>r.is_dir&&!r.is_link))list.append(button('📁 '+r.name,'fa-folder',()=>load(r.path)));}d.showModal();load(start).catch(e=>{fail(e);finish(null);});});}
 async function moveItems(rows){const dest=await pickFolder();if(dest===null)return;for(const r of rows)await call('files.move',{path:r.path,destination:dest.replace(/\/$/,'')+'/'+r.name});notice('Moved '+rows.length+' item(s).');await files();}
 async function trashItems(rows){for(const r of rows)await call('files.trash',{path:r.path});notice('Moved '+rows.length+' item(s) to Trash.');await files();}
 async function imagePreview(r){const data=await call('files.download',{path:r.path}),response=await fetch('/api/system/download?id='+data.download_id,{credentials:'same-origin'});if(!response.ok)throw new Error('Preview unavailable');const blob=await response.blob(),url=URL.createObjectURL(new Blob([blob],{type:'image/'+(/\.svg$/i.test(r.name)?'svg+xml':/\.jpe?g$/i.test(r.name)?'jpeg':r.name.split('.').pop().toLowerCase())}));const d=dialog(r.name),img=node('img');img.src=url;img.alt=r.name;img.className='fm-preview-image';d.append(img);d.addEventListener('close',()=>URL.revokeObjectURL(url),{once:true});d.showModal();}
 function itemModal(r){const d=dialog(r.name),meta=node('dl',null,'fm-detail-meta');for(const [k,v] of [['Type',kind(r)],['Size',r.is_dir?'Folder':formatBytes(r.bytes)],['Modified',new Date(r.modified*1000).toLocaleString()],['Permissions',r.mode],['Path',r.path]])meta.append(node('dt',k),node('dd',v));const actions=node('div',null,'fm-action-list');const run=fn=>async()=>{d.close();await fn();};if(!r.is_link){actions.append(button(r.is_dir?'Open':'Edit',r.is_dir?'fa-folder-open':'fa-pen',run(()=>openItem(r))));if(previewable(r))actions.append(button('Preview','fa-eye',run(()=>imagePreview(r))));actions.append(button('Rename','fa-i-cursor',run(async()=>{await renameItem(r);await files();})),button('Move','fa-folder-tree',run(()=>moveItems([r]))),button('Download','fa-download',run(async()=>download(await call('files.download',{path:r.path})))),button('Trash','fa-trash',run(()=>trashItems([r])),'danger'));}else actions.append(node('p','Symbolic links are shown for reference and are not followed.'));d.append(meta,actions);d.showModal();}
 $('#fileView').addEventListener('change',e=>{view=e.target.value;renderFiles();});$('#fileSort').addEventListener('change',e=>{sortKey=e.target.value;renderFiles();});$('#fileDirection').addEventListener('click',()=>{sortDirection*=-1;renderFiles();});$$('[data-file-root]').forEach(b=>b.addEventListener('click',()=>{directory=b.dataset.fileRoot;files().catch(fail);}));
 const selectedRows=()=>entries.filter(r=>selection.has(r.path));$('#fileClearSelection').addEventListener('click',()=>{selection.clear();renderFiles();});$('#fileBatchRename').addEventListener('click',async()=>{try{for(const r of selectedRows())await renameItem(r);await files();}catch(e){fail(e);}});$('#fileBatchMove').addEventListener('click',()=>moveItems(selectedRows()).catch(fail));$('#fileBatchTrash').addEventListener('click',()=>trashItems(selectedRows()).catch(fail));$('#fileBatchDownload').addEventListener('click',async()=>{try{download(await call('files.pack',{paths:selectedRows().map(r=>r.path)}));}catch(e){fail(e);}});
 let prunePlan=null;$('#pruneScan').addEventListener('click',async()=>{try{prunePlan=await call('releases.scan');text('#pruneSummary',prunePlan.items.length+' old update(s) · '+formatBytes(prunePlan.items.reduce((n,r)=>n+r.bytes,0))+' can be reclaimed.');const list=$('#pruneItems');list.replaceChildren(...prunePlan.items.map(r=>node('p',r.path+' · '+formatBytes(r.bytes),'fm-path')));$('#pruneKept').replaceChildren(...prunePlan.kept.map(r=>node('p',r.path+' — '+r.reason,'small fm-path')));$('#pruneRun').disabled=!prunePlan.items.length;}catch(e){fail(e);}});$('#pruneRun').addEventListener('click',async()=>{if(!prunePlan)return;$('#pruneRun').disabled=true;try{const d=dialog('Remove the previewed old updates?');d.append(node('p','This permanently removes '+prunePlan.items.length+' old releases / rollback copies ('+formatBytes(prunePlan.items.reduce((n,r)=>n+r.bytes,0))+'). Live feed targets and database dumps are kept.'));d.append(button('Remove old updates','fa-trash',async()=>{d.close();try{const result=await call('releases.prune',{digest:prunePlan.digest,confirmation:'REMOVE OLD RELEASES'});notice(result.message);text('#pruneSummary',result.message+' · '+formatBytes(result.bytes)+' reclaimed.');$('#pruneItems').replaceChildren();prunePlan=null;}catch(e){fail(e);}},'danger'));d.addEventListener('close',()=>{$('#pruneRun').disabled=!prunePlan?.items?.length;},{once:true});d.showModal();}catch(e){fail(e);}});
 async function textEntry(title,value,label){let dlg=$('#nativeInputDialog');if(!dlg){dlg=node('dialog');dlg.id='nativeInputDialog';const form=node('form');form.method='dialog';const h=node('h2');h.id='nativeInputTitle';const input=node('input');input.id='nativeInputValue';input.autocomplete='off';const row=node('div',null,'action-row end');for(const [text,val] of [['Cancel','cancel'],['Save','save']]){const b=node('button',text,'button-secondary');b.value=val;row.append(b);}form.append(h,input,row);dlg.append(form);document.body.append(dlg);}text('#nativeInputTitle',title);$('#nativeInputValue').value=value;$('#nativeInputValue').setAttribute('aria-label',label);dlg.returnValue='cancel';dlg.showModal();$('#nativeInputValue').focus();return new Promise(resolve=>dlg.addEventListener('close',()=>resolve(dlg.returnValue==='save'?$('#nativeInputValue').value:null),{once:true}));}
 async function edit(path){editing=await call('files.read',{path});text('#fileEditName',path);$('#fileContent').value=editing.content;$('#fileEditor').showModal();$('#fileContent').focus();}
 async function fileRecovery(){const d=await call('files_recovery.list');$('#fileRecoveryPanel').hidden=false;const list=$('#fileRecoveryList');list.replaceChildren();for(const r of d.items){const row=node('div',null,'item'),copy=node('div');copy.append(node('strong',r.path),node('p',r.kind+' · '+when(r.at),'small muted'));row.append(copy,button('Restore','fa-clock-rotate-left',async()=>{notice((await call('files_recovery.restore',{id:r.id})).message);await files();}),button('Permanently delete','fa-trash-can',async()=>{notice((await call('files_recovery.purge',{id:r.id})).message);await fileRecovery();}));list.append(row);}if(!d.items.length)list.append(node('p','No recovery copies or trashed items.','empty'));}
 $('#filePathForm').addEventListener('submit',e=>{e.preventDefault();directory=$('#filePath').value.trim()||'/';files().catch(fail);});$('#fileReload').addEventListener('click',()=>files().catch(fail));$('#fileUp').addEventListener('click',()=>{directory=parentDirectory;files().catch(fail);});$('#fileRecovery').addEventListener('click',()=>fileRecovery().catch(fail));$('#fileEditClose').addEventListener('click',()=>{$('#fileEditor').close();editing=null;});$('#fileSave').addEventListener('click',async()=>{if(!editing)return;try{const d=await call('files.write',{path:editing.path,sha256:editing.sha256,content:$('#fileContent').value});editing.sha256=d.sha256;notice(d.message);await files();}catch(e){fail(e);}});
 for(const [id,op] of [['fileNewFolder','mkdir'],['fileNewFile','create']])$('#'+id).addEventListener('click',async()=>{try{const name=await textEntry(op==='mkdir'?'New folder':'New file','','Item name');if(name===null)return;if(!name||/[\/\\]/.test(name)||name==='.'||name==='..')throw new Error('Enter a name without directory separators');const path=directory.replace(/\/$/,'')+'/'+name;notice((await call('files.'+op,{path})).message);await files();if(op==='create')await edit(path);}catch(e){fail(e);}});
 async function uploadFiles(fileList){for(const file of fileList){if(file.size>50*1024*1024)throw new Error(file.name+' exceeds 50 MiB');await request('/api/system/upload',{method:'POST',headers:{'Content-Type':'application/octet-stream','X-CSRF':$('meta[name="csrf-token"]').content,'X-Filename':encodeURIComponent(file.name),'X-Directory':encodeURIComponent(directory)},body:file});}notice('Upload complete');await files();}
 $('#fileUpload').addEventListener('click',()=>{const d=dialog('Upload files to '+directory),zone=node('div','Drop files here or choose files','fm-upload-zone'),input=node('input'),list=node('p'),submit=button('Upload here','fa-upload',async()=>{try{await uploadFiles(chosen);d.close();}catch(e){fail(e);}},'action-primary');input.type='file';input.multiple=true;let chosen=[];submit.disabled=true;function choose(items){chosen=[...items];list.textContent=chosen.map(f=>f.name+' · '+formatBytes(f.size)).join(', ');submit.disabled=!chosen.length;}input.addEventListener('change',()=>choose(input.files));zone.append(input);zone.addEventListener('dragover',e=>{e.preventDefault();zone.classList.add('dragover');});zone.addEventListener('dragleave',()=>zone.classList.remove('dragover'));zone.addEventListener('drop',e=>{e.preventDefault();zone.classList.remove('dragover');choose(e.dataTransfer.files);});d.append(zone,list,submit);d.showModal();});
 function setForm(form,data){for(const [k,v] of Object.entries(data)){const e=form.elements.namedItem(k);if(!e)continue;if(e.type==='checkbox')e.checked=!!Number(v);else e.value=v??'';}}
 function terms(rows){return (rows||[]).map(x=>typeof x==='string'?x:(x.weight===1?'':x.weight+' * ')+x.path).join('\n');}
 async function augur(){const year=Number($('#augurYear').value);const d=await call('augur.overview',{year});text('#augurHeartbeat',Object.keys(d.heartbeat||{}).length?'Scheduler: '+JSON.stringify(d.heartbeat):'No scheduler heartbeat recorded');setForm($('#augurSchedule'),d.schedule||{});table('#augurLive',[['Organization','organization_name'],['Enabled','enabled'],['Last success','last_live_success_at',when],['Message','last_live_message']],d.live||[]);table('#augurHistory',[['Organization','organization_name'],['Action','action'],['When','created_at',when]],d.history||[]);table('#augurArchive',[['Season','season_year'],['Archived events','events_archived'],['Source ready','source_ready'],['Rated','rated_events']],d.archive||[]);const saved=d.phase?.[0];let map={};try{map=saved?JSON.parse(saved.mapping_json):{};}catch{}const phaseData={notes:saved?.notes||'',reconcile:map.reconcile??'teleop',modeled_subtract:terms(map.modeled_subtract)};for(const key of ['auto','teleop','endgame']){phaseData[key+'_mode']=map[key]?.mode||'sum';phaseData[key+'_terms']=terms(map[key]?.terms);}setForm($('#augurPhases'),phaseData);text('#augurSample',JSON.stringify(d.sample?.[0]||{},null,2));await augurJobs();}
 async function augurJobs(){const d=await call('augur.status');const box=$('#augurJobs');box.replaceChildren();for(const j of d.jobs){const item=node('details');item.open=!!j.running;item.append(node('summary',j.mode+' · '+j.start_year+'–'+j.end_year+' · '+(j.running?'Running':'Finished')),node('pre',j.log||'Waiting for output…','native-log'));box.append(item);}}
 $('#augurRefresh').addEventListener('click',()=>augur().catch(fail));$('#augurYear').addEventListener('change',()=>augur().catch(fail));$('#augurRun').addEventListener('click',async()=>{try{notice((await call('augur.run',{mode:$('#augurMode').value,start_year:Number($('#augurStart').value),end_year:Number($('#augurEnd').value)})).message);await augurJobs();}catch(e){fail(e);}});
 $('#augurSchedule').addEventListener('submit',async e=>{e.preventDefault();try{notice((await call('augur.schedule',fields(e.target))).message);}catch(e){fail(e);}});$('#augurPhases').addEventListener('submit',async e=>{e.preventDefault();try{notice((await call('augur.phase_save',{...fields(e.target),year:Number($('#augurYear').value)})).message);}catch(e){fail(e);}});$('#augurPhaseDelete').addEventListener('click',async()=>{try{notice((await call('augur.phase_delete',{year:Number($('#augurYear').value)})).message);await augur();}catch(e){fail(e);}});
 $$('[data-augur-tab]').forEach(b=>b.addEventListener('click',()=>{$$('[data-augur-tab]').forEach(x=>x.classList.toggle('active',x===b));$$('[data-augur-panel]').forEach(x=>x.hidden=x.dataset.augurPanel!==b.dataset.augurTab);}));
 const loaders={platform,files,augur};const show=()=>{const load=loaders[location.hash.slice(1)];if(load)load().catch(fail);};window.addEventListener('hashchange',show);show();setInterval(()=>{if(document.hidden)return;if(location.hash==='#augur')augurJobs().catch(()=>{});},10000);
})();
