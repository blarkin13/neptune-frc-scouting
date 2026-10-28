"""Native RC system operations, reachable only through the root-owned peer-checked socket."""
from pathlib import Path
import base64,datetime,hashlib,json,os,pwd,re,shutil,stat,subprocess,tempfile,time,uuid
PROD=Path('/var/www/neptune');STATE=Path('/var/lib/neptune-release-center');DOWNLOADS=STATE/'downloads';RECOVERY=STATE/'file-recovery';JOBS=STATE/'system-jobs';STAGING=STATE/'staging'
MAX_EDIT=2*1024*1024;MAX_UPLOAD=50*1024*1024;ID=re.compile(r'^[a-f0-9]{32}$')
def now():return datetime.datetime.now(datetime.timezone.utc).isoformat()
def sha(p):
 h=hashlib.sha256()
 with p.open('rb') as f:
  for b in iter(lambda:f.read(1024*1024),b''):h.update(b)
 return h.hexdigest()
def private_dir(p,shared=False):
 if p.is_symlink():raise ValueError('Linked state directory refused')
 p.mkdir(parents=True,exist_ok=True,mode=0o750 if shared else 0o700)
 os.chmod(p,0o750 if shared else 0o700);os.chown(p,0,pwd.getpwnam('neptunerelease').pw_gid if shared else 0)
def target(raw='',existing=True):
 if not isinstance(raw,str) or '\x00' in raw:raise ValueError('Invalid file path')
 rel=Path(raw)
 if rel.is_absolute() or '..' in rel.parts:raise ValueError('Use a path within Neptune')
 p=PROD/rel
 if any(q.is_symlink() for q in (p,*p.parents) if q.is_relative_to(PROD)):raise ValueError('Symbolic links are listed but not followed')
 if not p.resolve(strict=existing).is_relative_to(PROD.resolve()):raise ValueError('Path is outside Neptune')
 return p

def recovery(p,kind='edit'):
 private_dir(RECOVERY);ident=uuid.uuid4().hex;folder=RECOVERY/ident;folder.mkdir(mode=0o700)
 meta={'id':ident,'path':str(p.relative_to(PROD)),'kind':kind,'at':now(),'is_dir':p.is_dir()}
 if kind=='trash':os.replace(p,folder/'item')
 elif p.is_dir():shutil.copytree(p,folder/'item')
 else:shutil.copy2(p,folder/'item')
 (folder/'meta.json').write_text(json.dumps(meta));return meta

def ticket(path,name):
 private_dir(DOWNLOADS,True)
 for p in DOWNLOADS.glob('*.json'):
  if time.time()-p.stat().st_mtime>1800:
   (DOWNLOADS/(p.stem+'.bin')).unlink(missing_ok=True);p.unlink(missing_ok=True)
 ident=uuid.uuid4().hex;dest=DOWNLOADS/(ident+'.bin')
 if path!=dest:shutil.copy2(path,dest)
 os.chown(dest,0,pwd.getpwnam('neptunerelease').pw_gid);dest.chmod(0o640)
 meta={'name':name,'bytes':dest.stat().st_size,'created_at':now()};p=DOWNLOADS/(ident+'.json');p.write_text(json.dumps(meta));os.chown(p,0,pwd.getpwnam('neptunerelease').pw_gid);p.chmod(0o640)
 return {'download_id':ident,**meta}

def db(op,args):
 p=subprocess.run(['/usr/bin/php','-d','memory_limit=256M',str(Path(__file__).with_name('system_db.php'))],input=json.dumps({'op':op,'args':args}),text=True,capture_output=True,timeout=55)
 try:d=json.loads(p.stdout)
 except ValueError:raise ValueError('System database operation failed; inspect the root service log')
 if p.returncode or d.get('error'):raise ValueError(d.get('error','System operation failed'))
 return d

def files(op,a):
 p=target(a.get('path',''),existing=op not in ('mkdir','create'))
 if op=='list':
  if not p.is_dir():raise ValueError('Select a folder')
  rows=[]
  for x in sorted(p.iterdir(),key=lambda q:(not q.is_dir(),q.name.lower()))[:5000]:
   try:
    st=x.lstat();rows.append({'name':x.name,'path':str(x.relative_to(PROD)),'is_dir':x.is_dir() and not x.is_symlink(),'is_link':x.is_symlink(),'bytes':st.st_size,'modified':st.st_mtime,'mode':oct(stat.S_IMODE(st.st_mode))})
   except OSError:continue
  return {'path':str(p.relative_to(PROD)) if p!=PROD else '','entries':rows}
 if op=='upload':
  name=a.get('name','');ident=a.get('upload_id','')
  if not isinstance(name,str) or not name or '/' in name or '\\' in name or name in ('.','..') or '\x00' in name or not ID.fullmatch(ident):raise ValueError('Invalid upload name')
  if not p.is_dir():raise ValueError('Choose a folder')
  dest=target(str(p.relative_to(PROD)/name),existing=False)
  if dest.exists():raise ValueError('That file already exists')
  staged=STAGING/('system-upload-'+ident);fd=os.open(staged,os.O_RDONLY|os.O_NOFOLLOW);st=os.fstat(fd)
  if not stat.S_ISREG(st.st_mode) or st.st_uid!=pwd.getpwnam('neptunerelease').pw_uid or st.st_nlink!=1 or not 0<st.st_size<=MAX_UPLOAD:os.close(fd);raise ValueError('Invalid staged upload')
  outfd,temp=tempfile.mkstemp(prefix='.rc-upload-',dir=p);h=hashlib.sha256()
  try:
   with os.fdopen(fd,'rb') as source,os.fdopen(outfd,'wb') as out:
    for block in iter(lambda:source.read(1024*1024),b''):out.write(block);h.update(block)
   if h.hexdigest()!=a.get('sha256') or st.st_size!=a.get('bytes'):raise ValueError('Upload changed')
   os.chown(temp,0,33);os.chmod(temp,0o664);os.link(temp,dest)
  finally:Path(temp).unlink(missing_ok=True);staged.unlink(missing_ok=True)
  return {'message':'File uploaded'}
 if op=='read':
  if not p.is_file() or p.stat().st_size>MAX_EDIT:raise ValueError('Select a text file no larger than 2 MiB')
  try:content=p.read_text(encoding='utf-8')
  except UnicodeDecodeError:raise ValueError('Binary file; use Download')
  return {'path':str(p.relative_to(PROD)),'content':content,'sha256':sha(p)}
 if op=='write':
  content=a.get('content');expected=a.get('sha256')
  if not isinstance(content,str) or len(content.encode())>MAX_EDIT:raise ValueError('Text exceeds 2 MiB')
  if not p.is_file() or sha(p)!=expected:raise ValueError('The file changed; reload it before saving')
  if p.suffix=='.php':
   check=subprocess.run(['/usr/bin/php','-l'],input=content,text=True,capture_output=True,timeout=10)
   if check.returncode:raise ValueError(check.stdout[:500])
  backup=recovery(p);st=p.stat();fd,name=tempfile.mkstemp(prefix='.rc-edit-',dir=p.parent)
  try:
   with os.fdopen(fd,'w') as f:f.write(content);f.flush();os.fsync(f.fileno())
   os.chown(name,st.st_uid,st.st_gid);os.chmod(name,stat.S_IMODE(st.st_mode));os.replace(name,p)
  finally:Path(name).unlink(missing_ok=True)
  return {'message':'File saved','sha256':sha(p),'recovery_id':backup['id']}
 if op in ('mkdir','create'):
  if p==PROD or p.exists():raise ValueError('That path already exists')
  if not p.parent.is_dir():raise ValueError('Parent folder does not exist')
  if op=='mkdir':p.mkdir(mode=0o775)
  else:
   with p.open('x'):pass
   p.chmod(0o664)
  os.chown(p,0,33);return {'message':'Folder created' if op=='mkdir' else 'File created'}
 if p==PROD:raise ValueError('Choose an item within Neptune')
 if op=='move':
  dest=target(a.get('destination',''),existing=False)
  if dest.exists() or not dest.parent.is_dir() or dest.is_relative_to(p):raise ValueError('Choose a new destination with an existing parent folder')
  os.replace(p,dest);return {'message':'Item moved','path':str(dest.relative_to(PROD))}
 if op=='trash':return {'message':'Moved to Trash; restore or permanently delete it from Trash','recovery':recovery(p,'trash')}
 if op=='download':
  if p.is_dir():
   private_dir(DOWNLOADS,True);temp=DOWNLOADS/(uuid.uuid4().hex+'.tar.gz')
   try:
    subprocess.run(['/usr/bin/tar','-czf',str(temp),'-C',str(p.parent),p.name],check=True,timeout=55);return ticket(temp,p.name+'.tar.gz')
   finally:temp.unlink(missing_ok=True)
  return ticket(p,p.name)
 raise ValueError('Unknown file operation')

def jobs_status():
 private_dir(JOBS);items=[]
 for p in sorted(JOBS.glob('*.json'),key=lambda p:p.stat().st_mtime,reverse=True)[:15]:
  try:
   row=json.loads(p.read_text());pid=int(row['pid']);running=False
   try:running=Path('/proc/'+str(pid)+'/cmdline').read_bytes().find(row['script'].encode())>=0
   except OSError:pass
   log=JOBS/(p.stem+'.log');text=''
   if log.exists():
    with log.open('rb') as f:f.seek(max(0,log.stat().st_size-20000));text=f.read().decode('utf-8','replace')
   items.append({**row,'running':running,'log':text})
  except (ValueError,OSError,KeyError):continue
 return items

def launch(a):
 year=datetime.date.today().year;start=int(a.get('start_year',year));end=int(a.get('end_year',start));mode=a.get('mode','recalc')
 if not 1992<=start<=end<=year+1:raise ValueError('Choose a valid season range')
 if any(j['running'] for j in jobs_status()):raise ValueError('An AUGUR job is already running')
 modes={'directory':('augur-team-directory-cli.php',['--force']),'opr':('augur-opr-rebuild-cli.php',[]),'depa':('augur-depa-backfill-cli.php',[]),'full':('augur-epa-backfill-cli.php',['--with-depa']),'recalc':('augur-epa-backfill-cli.php',['--with-depa','--recalc-only'])}
 if mode not in modes:raise ValueError('Unknown AUGUR job')
 script,flags=modes[mode];path=PROD/'public_html/Neptune/admin'/script
 if not path.is_file():raise ValueError('The configured AUGUR script is missing')
 private_dir(JOBS);ident=uuid.uuid4().hex;log=JOBS/(ident+'.log')
 # Match the website's execution account; detached jobs are separate from the root console timeout.
 with log.open('ab') as output:
  proc=subprocess.Popen(['/usr/sbin/runuser','-u','www-data','--','/usr/bin/php',str(path),str(start),str(end),*flags],stdin=subprocess.DEVNULL,stdout=output,stderr=subprocess.STDOUT,start_new_session=True)
 row={'id':ident,'pid':proc.pid,'mode':mode,'script':str(path),'start_year':start,'end_year':end,'started_at':now()};(JOBS/(ident+'.json')).write_text(json.dumps(row));return {'message':'AUGUR job started',**row}

def handle(op,a):
 if not isinstance(op,str) or not isinstance(a,dict):raise ValueError('Invalid system operation')
 if op.startswith('files.'):return files(op.split('.',1)[1],a)
 if op=='files_recovery.list':
  private_dir(RECOVERY);rows=[]
  for p in sorted(RECOVERY.glob('*/meta.json'),key=lambda p:p.stat().st_mtime,reverse=True)[:100]:
   try:rows.append(json.loads(p.read_text()))
   except (ValueError,OSError):continue
  return {'items':rows}
 if op in ('files_recovery.restore','files_recovery.purge'):
  ident=a.get('id','')
  if not ID.fullmatch(ident):raise ValueError('Invalid recovery item')
  folder=RECOVERY/ident;meta=json.loads((folder/'meta.json').read_text());p=target(meta['path'],existing=False)
  if op.endswith('purge'):shutil.rmtree(folder);return {'message':'Recovery copy permanently deleted'}
  if p.exists():
   recovery(p)
   if p.is_dir():shutil.rmtree(p)
  if not p.parent.is_dir():raise ValueError('Restore the parent folder first')
  if (folder/'item').is_dir():shutil.copytree(folder/'item',p,dirs_exist_ok=True)
  else:shutil.copy2(folder/'item',p)
  return {'message':'Item restored'}
 if op=='platform.export':
  private_dir(DOWNLOADS,True);path=DOWNLOADS/(uuid.uuid4().hex+'.bin')
  try:result=db(op,{**a,'output':str(path)});return ticket(path,result['name'])
  finally:path.unlink(missing_ok=True)
 if op.startswith('platform.') or op in ('augur.overview','augur.schedule','augur.phase_save','augur.phase_delete'):return db(op,a)
 if op=='augur.status':return {'jobs':jobs_status()}
 if op=='augur.run':return launch(a)
 if op=='downloads.delete':
  ident=a.get('id','')
  if not ID.fullmatch(ident):raise ValueError('Invalid download')
  for suffix in ('.bin','.json'):(DOWNLOADS/(ident+suffix)).unlink(missing_ok=True)
  return {'removed':True}
 raise ValueError('Unknown system operation')

# Background archive jobs never hold an HTTP request or place exports under the website.
def export_status():
 private_dir(JOBS);rows=[]
 for p in sorted(JOBS.glob('export-*.json'),key=lambda p:p.stat().st_mtime,reverse=True)[:15]:
  try:rows.append(json.loads(p.read_text()))
  except (ValueError,OSError):continue
 return rows

def archive_start(kind):
 if kind not in ('database','www','app','rc','patches'):raise ValueError('Unknown archive')
 if any(r.get('state')=='running' and Path('/proc/'+str(r.get('pid',0))).exists() for r in export_status()):raise ValueError('An archive is already being prepared')
 if shutil.disk_usage(STATE).free<2*1024**3:raise ValueError('At least 2 GiB free space is needed to prepare an archive')
 private_dir(JOBS);ident=uuid.uuid4().hex;row={'id':ident,'kind':kind,'state':'running','started_at':now()};path=JOBS/('export-'+ident+'.json');path.write_text(json.dumps(row))
 with (JOBS/('export-'+ident+'.log')).open('ab') as log:
  p=subprocess.Popen(['/usr/bin/python3','-B',str(Path(__file__)), '--export-worker',ident],stdin=subprocess.DEVNULL,stdout=log,stderr=subprocess.STDOUT,start_new_session=True)
 row['pid']=p.pid;path.write_text(json.dumps(row));return {'message':'Preparing '+kind+' archive',**row}

def export_worker(ident):
 import gzip,tarfile
 if os.geteuid()!=0 or not ID.fullmatch(ident):raise ValueError('Invalid archive worker')
 path=JOBS/('export-'+ident+'.json');row=json.loads(path.read_text());kind=row['kind'];private_dir(DOWNLOADS,True);work=DOWNLOADS/('build-'+ident);work.mkdir(mode=0o700)
 try:
  stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%SZ');archive=work/('Neptune_'+kind+'_'+stamp+'.tar.gz')
  if kind=='database':
   sql=work/'database.sql.gz'
   with (work/'dump.stderr').open('wb') as err:
    p=subprocess.Popen(['/usr/bin/mysqldump','--protocol=socket','--user=root','--single-transaction','--quick','--triggers','--routines','--events','--no-tablespaces','--hex-blob','--default-character-set=utf8mb4','scouting'],stdout=subprocess.PIPE,stderr=err)
    try:
     with gzip.open(sql,'wb',compresslevel=5) as out:shutil.copyfileobj(p.stdout,out,1024*1024)
     p.stdout.close();code=p.wait()
     if code:raise ValueError('Database dump failed; see private export log')
    finally:
     if p.poll() is None:p.kill();p.wait()
   with tarfile.open(archive,'w:gz') as tar:tar.add(sql,arcname='database.sql.gz')
  else:
   sources={'www':Path('/var/www'),'app':PROD/'public_html/Neptune','rc':Path('/opt/neptune-release-center'),'patches':PROD/'maintenance-uploads'};source=sources[kind]
   if not source.is_dir():raise ValueError('The selected folder does not exist')
   def include(info):
    if kind in ('www','rc'):
     parts=Path(info.name).parts;leaf=parts[-1].lower()
     if any(p in ('neptune_secure','.git','maintenance-backups','file-manager-backups','sessions','__pycache__') for p in parts) or leaf in ('config.php','.env','.my.cnf','credentials.json','config.env') or leaf.endswith(('.sql','.sql.gz','.pem','.key')):return None
    return info
   with tarfile.open(archive,'w:gz',compresslevel=5) as tar:tar.add(source,arcname=source.name,filter=include)
  result=ticket(archive,archive.name);row.update(state='ready',finished_at=now(),download=result)
 except Exception as e:row.update(state='failed',finished_at=now(),error=str(e)[:300])
 finally:
  path.write_text(json.dumps(row));shutil.rmtree(work,ignore_errors=True)

def legacy_history():
 root=PROD/'maintenance-backups';rows=[]
 for p in root.glob('*/manifest.json'):
  if p.is_symlink():continue
  try:
   m=json.loads(p.read_text());rows.append({'name':p.parent.name,'package':m.get('package',p.parent.name),'created_at':m.get('created_at'),'status':m.get('status','installed'),'file_count':len(m.get('files',[]))})
  except (ValueError,OSError):continue
 return sorted(rows,key=lambda r:r.get('created_at') or r['name'],reverse=True)[:100]

def legacy_restore(a):
 import fcntl
 name=a.get('name','')
 if not isinstance(name,str) or not name or name in ('.','..') or Path(name).name!=name:raise ValueError('Invalid rollback point')
 root=PROD/'maintenance-backups';folder=root/name
 if folder.is_symlink() or not folder.is_dir():raise ValueError('Rollback point missing')
 manifest=folder/'manifest.json';m=json.loads(manifest.read_text());steps=[]
 for f in reversed(m.get('files',[])):
  p=target(f.get('target',''),existing=False)
  if p==PROD:raise ValueError('Invalid rollback target')
  source=folder/str(f.get('backup',''))
  if f.get('existed') and (source.is_symlink() or not source.resolve().is_relative_to(folder.resolve()) or not source.is_file()):raise ValueError('Backup file missing')
  expected=f.get('installed_sha256')
  if expected and p.exists() and sha(p)!=expected and not a.get('force'):raise ValueError('Later edits exist. Enable Overwrite later edits to restore them.')
  steps.append((f,p,source))
 if not steps:raise ValueError('No files in this rollback point')
 with (root/'.install.lock').open('a+') as lock:
  fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  for f,p,source in steps:
   if p.exists():recovery(p)
  for f,p,source in steps:
   if f.get('existed'):
    p.parent.mkdir(parents=True,exist_ok=True);fd,temp=tempfile.mkstemp(prefix='.rc-restore-',dir=p.parent)
    try:
     with os.fdopen(fd,'wb') as out,source.open('rb') as inp:shutil.copyfileobj(inp,out)
     st=p.stat() if p.exists() else source.stat();os.chown(temp,st.st_uid,st.st_gid);os.chmod(temp,stat.S_IMODE(st.st_mode));os.replace(temp,p)
    finally:Path(temp).unlink(missing_ok=True)
   else:p.unlink(missing_ok=True)
  m.update(status='rolled_back',rolled_back_at=now());manifest.write_text(json.dumps(m,indent=2))
 return {'message':'Restored '+str(len(steps))+' files; current copies are in File Manager recovery'}

_original_handle=handle
def handle(op,a):
 if not isinstance(op,str) or not isinstance(a,dict):raise ValueError('Invalid system request')
 if op=='maintenance.overview':return {'exports':export_status(),'history':legacy_history()}
 if op=='maintenance.export':return archive_start(a.get('kind',''))
 if op=='maintenance.restore':return legacy_restore(a)
 return _original_handle(op,a)
if __name__=='__main__':
 import sys
 if len(sys.argv)==3 and sys.argv[1]=='--export-worker':export_worker(sys.argv[2])
