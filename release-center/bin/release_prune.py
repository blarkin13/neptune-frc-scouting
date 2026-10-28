#!/usr/bin/env python3
"""Reviewed pruning of obsolete Neptune helper releases and file rollback copies."""
from pathlib import Path
import datetime,fcntl,hashlib,json,os,re,shutil,stat,sys,tempfile
DOWNLOADS=Path('/var/www/neptune/public_html/Neptune/community/downloads')
BACKUPS=Path('/root')
STATE=Path('/var/lib/neptune-release-center/reports')
PLAN=STATE/'release-prune-plan.json'
LOCK=Path('/run/lock/neptune-release-prune.lock')
KEEP=2
SQL_SUFFIXES=('.sql','.sql.gz','.sql.bz2','.sql.xz','.dump','.dump.gz')
def stamp():return datetime.datetime.now(datetime.timezone.utc).isoformat()
def digest(data):return hashlib.sha256(json.dumps(data,sort_keys=True,separators=(',',':')).encode()).hexdigest()
def natural(name):return [int(x) if x.isdigit() else x.lower() for x in re.split(r'(\d+)',name)]
def details(p):
 if p.is_symlink():return None
 st=p.lstat()
 if not (stat.S_ISREG(st.st_mode) or stat.S_ISDIR(st.st_mode)):return None
 # Never include database dumps, even if nested inside a rollback folder.
 if any(w in p.name.lower() for w in ('database','mysql','db-backup','sql-backup')) or p.name.lower().endswith(SQL_SUFFIXES):return None
 total=0;files=[]
 entries=[p]
 if p.is_dir():
  for parent,dirs,names in os.walk(p,followlinks=False):
   dirs[:]=sorted(d for d in dirs if not (Path(parent)/d).is_symlink())
   entries.extend(Path(parent)/name for name in sorted(names))
 for f in entries:
  if f.is_symlink():return None
  stf=f.lstat()
  if f.name.lower().endswith(SQL_SUFFIXES):return None
  if stat.S_ISREG(stf.st_mode):total+=stf.st_size
  elif not stat.S_ISDIR(stf.st_mode):return None
  files.append([str(f.relative_to(p)) if f!=p else '.',stf.st_ino,stf.st_size,stf.st_mtime_ns,stf.st_mode])
 return {'path':str(p),'name':p.name,'bytes':total,'modified':st.st_mtime,'is_dir':p.is_dir(),'fingerprint':digest(files)}
def inventory():
 rows=[];kept=[]
 for sub,pattern,feed in [('mac','Neptune-Training-Helper-[0-9]*.zip','appcast.xml'),('windows','Neptune-Training-Helper-[0-9]*-update.zip','latest.json')]:
  folder=DOWNLOADS/sub
  if not folder.is_dir() or folder.is_symlink():continue
  versions=sorted([p for p in folder.glob(pattern) if p.is_file() and not p.is_symlink()],key=lambda p:natural(p.name),reverse=True)
  reference=(folder/feed).read_text() if (folder/feed).is_file() and not (folder/feed).is_symlink() else ''
  # Without a readable feed there is no trustworthy active-release reference.
  if not reference:
   kept.extend({'path':str(p),'reason':'No active feed; preserved'} for p in versions);continue
  for i,p in enumerate(versions):
   if i<KEEP or p.name in reference:
    kept.append({'path':str(p),'reason':'Newest two versions' if i<KEEP else 'Referenced by live feed'});continue
   d=details(p)
   if d:rows.append(d)
 if BACKUPS.is_dir() and not BACKUPS.is_symlink():
  backups=sorted([p for p in BACKUPS.glob('*-backup-20[0-9][0-9]*') if not p.is_symlink()],key=lambda p:p.stat().st_mtime_ns,reverse=True)
  eligible=[]
  for p in backups:
   d=details(p)
   if d:eligible.append((p,d))
   else:kept.append({'path':str(p),'reason':'Database dump or unsupported item; preserved'})
  for i,(p,d) in enumerate(eligible):
   if i<KEEP:kept.append({'path':str(p),'reason':'Newest two rollback copies'})
   else:rows.append(d)
 return {'items':sorted(rows,key=lambda r:r['path']),'kept':kept,'keep_versions':KEEP,
         'scope':'Keeps the newest two Mac/Windows helper releases, live feed targets and two newest root rollback copies. Database dumps and journal logs are preserved.'}
def write_plan(plan):
 STATE.mkdir(parents=True,exist_ok=True)
 fd,tmp=tempfile.mkstemp(prefix='.release-prune-',dir=STATE)
 try:
  with os.fdopen(fd,'w') as f:json.dump(plan,f);f.flush();os.fsync(f.fileno())
  os.chmod(tmp,0o644);os.replace(tmp,PLAN)
 finally:Path(tmp).unlink(missing_ok=True)
def handle(op,args):
 if op=='scan':
  data=inventory();data['digest']=digest(data);data['created_at']=stamp();write_plan(data);return data
 if op!='prune' or set(args)!={'digest','confirmation'} or args['confirmation']!='REMOVE OLD RELEASES':raise ValueError('Review a release cleanup preview first')
 if not PLAN.is_file():raise ValueError('Run a new release cleanup scan')
 reviewed=json.loads(PLAN.read_text())
 age=(datetime.datetime.now(datetime.timezone.utc)-datetime.datetime.fromisoformat(reviewed['created_at'])).total_seconds()
 if not 0<=age<=1800 or reviewed['digest']!=args['digest']:raise ValueError('Preview expired or changed; scan again')
 with LOCK.open('a') as lock:
  fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  current=inventory()
  if digest(current)!=args['digest']:raise ValueError('Releases changed since preview; scan again')
  freed=0
  disk_before=shutil.disk_usage(BACKUPS).free
  for row in current['items']:
   p=Path(row['path'])
   if details(p)!=row:raise ValueError('Cleanup item changed; scan again')
   if p.is_dir():shutil.rmtree(p)
   else:p.unlink()
   freed+=row['bytes']
  os.sync()
  reclaimed=max(0,shutil.disk_usage(BACKUPS).free-disk_before)
  receipt={'message':f'Removed {len(current["items"])} old releases / rollback copies','removed':len(current['items']),'bytes':reclaimed,'removed_file_bytes':freed,'finished_at':stamp()}
  write_plan({**inventory(),'created_at':stamp(),'last_run':receipt});return receipt
if __name__=='__main__':
 if '--dry-run' in sys.argv or '--json' in sys.argv:
  data=inventory()
  if '--json' in sys.argv:print(json.dumps(data,indent=2))
  else:
   print(data['scope'])
   for r in data['items']:print('remove',r['path'],r['bytes'],'bytes')
   print(len(data['items']),'eligible old releases / rollback copies')
 else:
  p=handle('scan',{});print(json.dumps(handle('prune',{'digest':p['digest'],'confirmation':'REMOVE OLD RELEASES'})))
