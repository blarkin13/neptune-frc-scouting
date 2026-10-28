"""Fixed, owner-reviewed production maintenance operations. No arbitrary SQL or shell input."""
from __future__ import annotations
import datetime as dt,fcntl,hashlib,json,os,pwd,re,shutil,stat,subprocess,tempfile,time,urllib.request,zipfile
from pathlib import Path
import qa_package_policy as policy
import production_ops as prod
STATE=Path('/var/lib/neptune-release-center');STAGE=STATE/'staging';REPORTS=STATE/'reports'
PLANS=STATE/'maintenance-plans';RECOVERY=STATE/'maintenance-recovery';PROD=Path('/var/www/neptune')
ID=re.compile(r'^[a-f0-9]{32}$');SHA=re.compile(r'^[a-f0-9]{64}$');SERVICE_USER='neptunerelease'
ACTIONS={'inspect','install','restore','database_scan','storage_scan','storage_delete','backup_now','backup_status'}
def utc():return dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds')
def sha(data):return hashlib.sha256(data).hexdigest()
def file_sha(path):
 h=hashlib.sha256()
 with path.open('rb') as f:
  for block in iter(lambda:f.read(1024*1024),b''):h.update(block)
 return h.hexdigest()
def encoded(value):return json.dumps(value,sort_keys=True,separators=(',',':')).encode()
def identifier(value):
 if not isinstance(value,str) or not ID.fullmatch(value):raise ValueError('Invalid maintenance identifier')
 return value

def read(path,limit,owner):
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_CLOEXEC)
 try:
  s=os.fstat(fd)
  if not stat.S_ISREG(s.st_mode) or s.st_nlink!=1 or s.st_uid!=owner or s.st_mode&0o022 or s.st_size>limit:raise ValueError('Unsafe maintenance state or upload')
  with os.fdopen(fd,'rb') as f:fd=-1;data=f.read(limit+1)
  if len(data)>limit:raise ValueError('State exceeds size limit')
  return data
 finally:
  if fd>=0:os.close(fd)

def rootdir(path):
 path.mkdir(mode=0o700,parents=True,exist_ok=True)
 if path.is_symlink() or path.stat().st_uid!=0 or path.stat().st_mode&0o077:raise ValueError('Unsafe private maintenance directory')

def write_private(path,data):
 rootdir(path.parent);atomic(path,encoded(data),uid=0,gid=0,mode=0o600)
def atomic(path,data,*,uid=None,gid=None,mode=None):
 if path.is_symlink():raise ValueError('Linked destination refused')
 fd,name=tempfile.mkstemp(prefix='.maintenance-',dir=path.parent)
 try:
  with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
  if uid is not None:os.chown(name,uid,gid)
  if mode is not None:os.chmod(name,mode)
  os.replace(name,path)
 finally:Path(name).unlink(missing_ok=True)
def report(name,data):
 atomic(REPORTS/name,encoded(data),uid=0,gid=0,mode=0o644)
def target(rel):return prod.strict_target(PROD,rel)
def json_private(path):return json.loads(read(path,2_000_000,0))

def validate_job(body):
 if not isinstance(body,dict) or body.get('action') not in ACTIONS:raise ValueError('Unsupported maintenance action')
 action=body['action'];allowed={'action'}
 if action in ('inspect','install'):allowed.add('package_id');identifier(body.get('package_id'))
 if action in ('install','storage_delete'):allowed|={'plan_digest','confirmation'}
 if action=='install' and body.get('confirmation')!='INSTALL TO PRODUCTION':raise ValueError('Type INSTALL TO PRODUCTION to install')
 if action=='restore':
  allowed|={'backup_id','confirmation'};identifier(body.get('backup_id'))
  if body.get('confirmation')!='RESTORE FILES':raise ValueError('Type RESTORE FILES to restore')
 if action=='storage_delete':
  allowed|={'plan_id','selected'};identifier(body.get('plan_id'))
  if body.get('confirmation')!='DELETE FILES':raise ValueError('Type DELETE FILES to delete')
  if not isinstance(body.get('selected'),list) or not 1<=len(body['selected'])<=100 or not all(isinstance(x,str) for x in body['selected']) or len(set(body['selected']))!=len(body['selected']):raise ValueError('Select 1–100 distinct cleanup candidates')
  if not all(isinstance(x,str) and SHA.fullmatch(x) for x in body['selected']):raise ValueError('Invalid cleanup candidate')
 if action in ('install','storage_delete') and not SHA.fullmatch(str(body.get('plan_digest',''))):raise ValueError('Invalid reviewed plan digest')
 if set(body)!=allowed:raise ValueError('Unexpected maintenance request fields')
 return body

def package_bytes(ident):
 identifier(ident);uid=pwd.getpwnam(SERVICE_USER).pw_uid
 meta=json.loads(read(STAGE/('maintenance-package-'+ident+'.json'),250_000,uid))
 data=read(STAGE/('maintenance-package-'+ident+'.zip'),50*1024*1024,uid)
 if meta.get('id')!=ident or meta.get('sha256')!=sha(data):raise ValueError('Uploaded package changed')
 return meta,data

def inspect_package(ident):
 meta,data=package_bytes(ident);changes=[]
 with tempfile.TemporaryDirectory(prefix='neptune-package-check-') as tmp:
  archive=Path(tmp)/'update.zip';archive.write_bytes(data);files=policy.inspect(archive)
  with zipfile.ZipFile(archive) as z:
   preview_budget=120_000
   # Optional Maintenance Console base hashes strengthen the current-file review.
   base={}
   manifests=[i for i in z.infolist() if i.filename.rstrip('/').split('/')[-1]=='neptune-package.json']
   if len(manifests)>1:raise ValueError('Duplicate package manifests')
   if manifests:
    if manifests[0].file_size>100_000:raise ValueError('Package manifest exceeds limit')
    manifest=json.loads(z.read(manifests[0]));base=manifest.get('base',{})
    if not isinstance(base,dict):raise ValueError('Invalid base-hash manifest')
   for f in files:
    path=target(f['target_path']);raw=z.read(f['archive_path']);previous=sha(path.read_bytes()) if path.exists() else None
    if f['target_path'] in base and base[f['target_path']]!=previous:raise ValueError('Package base does not match production: '+f['target_path'])
    if f['target_path'].endswith('.php'):
     check=Path(tmp)/'lint.php';check.write_bytes(raw)
     result=subprocess.run(['php','-l',str(check)],capture_output=True,text=True,timeout=15)
     if result.returncode:raise ValueError('PHP syntax check failed: '+f['target_path'])
    if previous!=f['sha256']:
     entry={'path':f['target_path'],'archive_path':f['archive_path'],'from_sha256':previous,'to_sha256':f['sha256'],'bytes':f['size']}
     if preview_budget and Path(f['target_path']).suffix in ('.php','.js','.css','.json','.html','.txt','.md','.xml'):
      import difflib
      try:
       old=path.read_text() if path.exists() else ''
       diff=''.join(difflib.unified_diff(old.splitlines(True),raw.decode('utf-8').splitlines(True),fromfile='current/'+f['target_path'],tofile='package/'+f['target_path']))
       entry['diff_preview']=diff[:min(12_000,preview_budget)];entry['diff_truncated']=len(diff)>len(entry['diff_preview']);preview_budget-=len(entry['diff_preview'])
      except UnicodeDecodeError:pass
     changes.append(entry)
 if not changes:raise ValueError('This package has no changes to install')
 plan={'kind':'production-maintenance-v1','package_id':ident,'name':meta['name'],'zip_sha256':sha(data),'created_at':utc(),'created_unix':int(time.time()),'files':changes}
 write_private(PLANS/('package-'+ident+'.json'),plan)
 summary={**plan,'plan_digest':sha(encoded(plan)),'state':'review','phase':'Review the production file changes before installing. SQL is never executed from the ZIP.'}
 report('maintenance-package-'+ident+'.json',summary);return summary

def reviewed(path,digest):
 plan=json_private(path)
 if sha(encoded(plan))!=digest or not 0<=time.time()-plan['created_unix']<=1800:raise ValueError('Review expired or changed; inspect again')
 return plan

def live_matches(files,side):
 for f in files:
  p=target(f['path']);actual=sha(p.read_bytes()) if p.exists() else None
  if actual!=f[side]:raise ValueError('Production changed; review again: '+f['path'])

def health():
 if subprocess.run(['systemctl','is-active','--quiet','apache2.service'],timeout=10).returncode:raise ValueError('Website service is not active')
 with urllib.request.urlopen('https://neptunefrc.com/community/',timeout=15) as response:
  if response.status!=200:raise ValueError('Website liveness check failed')

def install_package(ident,digest):
 plan=reviewed(PLANS/('package-'+identifier(ident)+'.json'),digest);meta,data=package_bytes(ident)
 if sha(data)!=plan['zip_sha256']:raise ValueError('ZIP changed after inspection')
 rootdir(RECOVERY);backup_id=os.urandom(16).hex();folder=RECOVERY/backup_id
 lockroot=PROD/'maintenance-backups'
 if lockroot.is_symlink() or not lockroot.is_dir():raise ValueError('Maintenance backup/lock directory is missing')
 with (lockroot/'.install.lock').open('a+') as lock:
  fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB);live_matches(plan['files'],'from_sha256');rootdir(folder);receipt={**plan,'backup_id':backup_id,'installed_at':utc(),'state':'installing'};applied=[]
  for f in plan['files']:
   source=target(f['path'])
   if source.exists():
    s=source.stat();f['previous_mode']=stat.S_IMODE(s.st_mode);f['previous_uid']=s.st_uid;f['previous_gid']=s.st_gid
    destination=folder/'files'/f['path'];destination.parent.mkdir(parents=True,mode=0o700,exist_ok=True);shutil.copy2(source,destination)
    if sha(destination.read_bytes())!=f['from_sha256']:raise ValueError('Rollback copy verification failed')
  write_private(folder/'receipt.json',receipt)
  try:
   import io
   with zipfile.ZipFile(io.BytesIO(data)) as z:
    # Recheck every current target immediately before replacement.
    for f in plan['files']:
     live_matches([f],'from_sha256');path=target(f['path']);raw=z.read(f['archive_path'])
     if sha(raw)!=f['to_sha256']:raise ValueError('Package content changed')
     s=path.stat() if path.exists() else None
     atomic(path,raw,uid=s.st_uid if s else 0,gid=s.st_gid if s else 33,mode=stat.S_IMODE(s.st_mode) if s else 0o644);applied.append(f)
   health();receipt['state']='installed';write_private(folder/'receipt.json',receipt)
  except Exception:
   restore_files(folder,applied);receipt['state']='rolled_back';write_private(folder/'receipt.json',receipt);raise
 result={'state':'success','phase':'Production files installed. Liveness passed; review the changed pages before calling the release verified.','package_id':ident,'backup_id':backup_id,'name':plan['name'],'files':len(plan['files']),'finished_at':utc()}
 report('maintenance-package-'+ident+'.json',result);refresh_recovery();return result

def restore_files(folder,files):
 live_matches(files,'to_sha256')
 for f in files:
  if f['from_sha256'] is not None:
   b=folder/'files'/f['path']
   if b.is_symlink() or not b.is_file() or sha(b.read_bytes())!=f['from_sha256']:raise ValueError('Rollback copy is missing or changed')
 for f in reversed(files):
  path=target(f['path'])
  if f['from_sha256'] is None:path.unlink()
  else:atomic(path,(folder/'files'/f['path']).read_bytes(),uid=f['previous_uid'],gid=f['previous_gid'],mode=f['previous_mode'])

def restore_package(backup_id):
 folder=RECOVERY/identifier(backup_id);receipt=json_private(folder/'receipt.json')
 if receipt.get('state')!='installed':raise ValueError('This recovery point is not an installed update')
 with (PROD/'maintenance-backups/.install.lock').open('a+') as lock:
  fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB);restore_files(folder,receipt['files']);health();receipt['state']='restored';write_private(folder/'receipt.json',receipt)
 refresh_recovery();return {'state':'success','phase':'Previous website files restored. Database migrations were not reversed.','backup_id':backup_id}

def refresh_recovery():
 rows=[]
 if RECOVERY.exists():
  for p in RECOVERY.iterdir():
   if not p.is_symlink() and p.is_dir() and ID.fullmatch(p.name) and (p/'receipt.json').is_file():
    d=json_private(p/'receipt.json');rows.append({k:d.get(k) for k in ('backup_id','name','installed_at','state')})
 report('maintenance-recovery.json',{'items':sorted(rows,key=lambda r:r.get('installed_at',''),reverse=True)[:30]})

def mysql(sql):
 p=subprocess.run(['mysql','--batch','-e',sql],capture_output=True,text=True,timeout=30)
 if p.returncode:raise ValueError('Database diagnostics failed; check the server log')
 lines=p.stdout.splitlines()
 if not lines:return []
 keys=lines[0].split('\t');return [dict(zip(keys,l.split('\t'))) for l in lines[1:]]
def database_scan():
 tables=mysql("SELECT TABLE_NAME,ROUND((DATA_LENGTH+INDEX_LENGTH)/1048576,1) size_mb,TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA='scouting' ORDER BY DATA_LENGTH+INDEX_LENGTH DESC LIMIT 12")
 variables=mysql("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','binlog_expire_logs_seconds','binlog_expire_logs_auto_purge','log_bin')")
 status=mysql("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests','Slow_queries','Threads_connected','Uptime')")
 binary_logging=next((str(r['Value']).upper() in ('ON','1','TRUE') for r in variables if r['Variable_name']=='log_bin'),False)
 logs=mysql('SHOW BINARY LOGS') if binary_logging else []
 data={'sampled_at':utc(),'tables':tables,'variables':variables,'status':status,'binary_logs':{'enabled':binary_logging,'files':len(logs),'bytes':sum(int(r['File_size']) for r in logs),'current':logs[-1]['Log_name'] if logs else None},'read_only':True}
 report('maintenance-database.json',data);return {'phase':'Database diagnostics refreshed; no records or settings changed.'}

# Only old uploaded ZIPs and /root release archives are eligible. Databases,
# SQL dumps, model files, current packages, runtime settings and backups are excluded.
CLEAN_ROOTS=(Path('/var/www/neptune/maintenance-uploads'),Path('/root'))
def cleanup_candidate(p,root,now):
 if p.parent!=root or root.is_symlink() or p.is_symlink() or not p.is_file():return None
 s=p.stat()
 if not p.name.lower().endswith('.zip') or (root==Path('/root') and not p.name.startswith(('Neptune_','neptune-'))):return None
 if now-s.st_mtime<7*86400 or s.st_size>250*1024*1024 or s.st_nlink!=1:return None
 return {'path':str(p),'name':p.name,'bytes':s.st_size,'allocated_bytes':s.st_blocks*512,'sha256':file_sha(p),'modified_at':dt.datetime.fromtimestamp(s.st_mtime,dt.timezone.utc).isoformat()}
def storage_scan(ident):
 rows=[];now=time.time()
 for root in CLEAN_ROOTS:
  if root.is_dir() and not root.is_symlink():
   for p in sorted(root.iterdir()):
    row=cleanup_candidate(p,root,now)
    if row:row['id']=sha(encoded(row));rows.append(row)
 plan={'plan_id':ident,'created_unix':int(now),'created_at':utc(),'items':rows[:200]};write_private(PLANS/('cleanup-'+ident+'.json'),plan)
 report('maintenance-storage.json',{**plan,'plan_digest':sha(encoded(plan)),'scope':'ZIP update archives older than seven days. No databases, SQL backups, models or current uploads.'})
 return {'phase':'Cleanup candidates ready for review. Nothing deleted.','plan_id':ident,'files':len(plan['items'])}
def storage_delete(body):
 plan=reviewed(PLANS/('cleanup-'+identifier(body['plan_id'])+'.json'),body['plan_digest']);lookup={r['id']:r for r in plan['items']};chosen=[]
 for key in body['selected']:
  if key not in lookup:raise ValueError('Selected file is not in the reviewed plan')
  row=lookup[key];p=Path(row['path']);root=p.parent
  if root not in CLEAN_ROOTS or cleanup_candidate(p,root,time.time())!= {k:v for k,v in row.items() if k!='id'}:raise ValueError('Cleanup file changed; scan again')
  chosen.append(row)
 freed=0;before=shutil.disk_usage(PROD).free
 with (PROD/'maintenance-backups/.install.lock').open('a+') as lock:
  fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  for row in chosen:
   p=Path(row['path'])
   if file_sha(p)!=row['sha256']:raise ValueError('Cleanup file changed')
   p.unlink();freed+=row['allocated_bytes']
 os.sync();after=shutil.disk_usage(PROD).free
 return {'phase':'Selected old archives permanently deleted.','files':len(chosen),'selected_allocated_bytes':freed,'freed_bytes':max(0,after-before)}

def backup_status():
 path=Path('/var/lib/neptune-backup/status.json');data={}
 if path.is_file() and not path.is_symlink() and path.stat().st_size<100_000:
  original=json.loads(path.read_text());data={k:original.get(k) for k in ('status','last_attempt_at','last_success_at','last_success_size_bytes','last_success_tag','next_scheduled_at','schedule_utc')}
 service=subprocess.run(['systemctl','show','neptune-production-backup.service','--property=LoadState','--value'],capture_output=True,text=True,timeout=10)
 data.update(sampled_at=utc(),configured=service.stdout.strip()=='loaded',destination='Existing private encrypted backup repository')
 report('maintenance-backup.json',data);refresh_recovery();return {'phase':'Backup status refreshed.'}

def backup_now():
 p=subprocess.run(['systemctl','show','neptune-production-backup.service','--property=LoadState','--value'],capture_output=True,text=True,timeout=10)
 if p.stdout.strip()!='loaded':raise ValueError('The existing backup service is not configured')
 if subprocess.run(['systemctl','is-active','--quiet','neptune-production-backup.service'],timeout=10).returncode==0:raise ValueError('A backup is already running')
 subprocess.run(['systemctl','start','--no-block','neptune-production-backup.service'],check=True,timeout=15)
 return {'phase':'Existing production backup service started. Check its result before assuming the backup succeeded.'}

def run(ident):
 identifier(ident);uid=pwd.getpwnam(SERVICE_USER).pw_uid;body=validate_job(json.loads(read(STAGE/('maintenance-request-'+ident+'.json'),20_000,uid)))
 action=body['action']
 if action=='inspect':return inspect_package(body['package_id'])
 if action=='install':return install_package(body['package_id'],body['plan_digest'])
 if action=='restore':return restore_package(body['backup_id'])
 if action=='database_scan':return database_scan()
 if action=='storage_scan':return storage_scan(ident)
 if action=='storage_delete':return storage_delete(body)
 if action=='backup_now':return backup_now()
 if action=='backup_status':return backup_status()
 raise ValueError('Unsupported maintenance operation')
