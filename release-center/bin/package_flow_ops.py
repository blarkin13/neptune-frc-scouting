#!/usr/bin/env python3
"""Neptune Release Control: direct QA -> production package lifecycle.

Root-only actions via the existing fixed-action queue. No GitHub dependency.
No arbitrary filesystem paths are accepted from the browser, no ZIP scripts run.
"""
from __future__ import annotations
import datetime as dt
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile
import zipfile
from qa_package_policy import inspect
from production_ops import strict_target

BASE=Path('/var/lib/neptune-release-center')
STAGING=BASE/'staging'
QA_RECOVERY=BASE/'deploy-qa-recovery'
STATE=BASE/'package-flow'
PROD_RECOVERY=STATE/'production'
QA_DELETED=STATE/'qa-removed'
REPORT=BASE/'reports/package-flow-inventory.json'
PROD=Path('/var/www/neptune')
QA=Path('/var/www/neptune-qa')
IDS=re.compile(r'^[a-f0-9]{32}$')


def fail(s):raise ValueError(s)
def utc():return dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds').replace('+00:00','Z')
def digest(p):
 h=hashlib.sha256()
 with p.open('rb') as f:
  for chunk in iter(lambda:f.read(1048576),b''):h.update(chunk)
 return h.hexdigest()
def dirmode(p):
 p.mkdir(parents=True,exist_ok=True,mode=0o700)
 if p.is_symlink() or not p.is_dir() or p.stat().st_uid!=0 or stat.S_IMODE(p.stat().st_mode)!=0o700:fail('Unsafe operation directory')
def atom_json(p,data,mode=0o600):
 p.parent.mkdir(parents=True,exist_ok=True)
 fd,name=tempfile.mkstemp(prefix='.rc-flow-',dir=p.parent)
 try:
  with os.fdopen(fd,'w') as f:json.dump(data,f,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
  os.chmod(name,mode);os.replace(name,p)
 finally:
  if os.path.exists(name):os.unlink(name)
def getjson(p):
 if not p.is_file() or p.is_symlink():fail('Missing or unsafe release record')
 return json.loads(p.read_text())
def validate(ident):
 if not isinstance(ident,str) or not IDS.fullmatch(ident):fail('Invalid package identifier')
def qa_deployer():
 # Fixed trusted worker module, never loaded from uploaded ZIP.
 location='/opt/neptune-release-center/bin/qa-package-deploy.py'
 sp=importlib.util.spec_from_file_location('neptune_fixed_qa_deployer',location)
 mod=importlib.util.module_from_spec(sp);sp.loader.exec_module(mod)
 return mod

def package_description(path,entries):
 """Use a small optional manifest description, otherwise the first filenames."""
 try:
  with zipfile.ZipFile(path) as z:
   for i in z.infolist():
    if i.filename in ('manifest.json','package.json') and i.file_size<12000:
     obj=json.loads(z.read(i))
     if isinstance(obj,dict):
      val=obj.get('description') or obj.get('summary')
      if isinstance(val,str) and 4<=len(val.strip())<=500:return val.strip()[:320]
 except (OSError,ValueError,KeyError,zipfile.BadZipFile):pass
 names=[f['target_path'].split('/')[-1] for f in entries[:3]]
 return 'Updates '+', '.join(names)+(f' and {len(entries)-3} more files' if len(entries)>3 else '')

def installed_records():
 """Return latest independently backed-up successful QA installations."""
 found={}
 if not QA_RECOVERY.is_dir():return found
 for folder in sorted(QA_RECOVERY.iterdir(),key=lambda x:x.name,reverse=True):
  if not folder.is_dir() or folder.is_symlink():continue
  ident=folder.name[-32:]
  if not IDS.fullmatch(ident) or ident in found or (QA_DELETED/(ident+'.json')).exists():continue
  plan=folder/'recovery-plan.json'
  zip_file=folder/'approved.zip'
  meta=STAGING/(ident+'.json')
  if not plan.is_file() or not zip_file.is_file() or not meta.is_file():continue
  try:
   data=getjson(plan);m=getjson(meta)
   if data.get('id')!=ident or m.get('id')!=ident or m.get('policy_version')!=2:continue
   if digest(zip_file)!=m.get('sha256'):continue
   records=data.get('files',[])
   if not isinstance(records,list):continue
   entries=inspect(zip_file)
   if len(entries)!=m.get('eligible_count'):continue
   found[ident]={'id':ident,'name':m.get('name',ident),'uploaded_at':m.get('uploaded_at'),
     'installed_at':dt.datetime.fromtimestamp(folder.stat().st_mtime,dt.timezone.utc).isoformat(),
     'files':entries,'changed':records,'folder':folder,'zip':zip_file,'sha256':m['sha256'],
     'description':package_description(zip_file,entries)}
  except (OSError,ValueError,KeyError,zipfile.BadZipFile):continue
 return found

def target_matches(root,path,wanted):
 try:
  p=strict_target(root,path)
  return p.is_file() and digest(p)==wanted
 except (OSError,ValueError):return False

def verified_qa(p):
 return all(target_matches(QA,x['target_path'],x['sha256']) for x in p['files'])

def production_record(ident):
 p=PROD_RECOVERY/(ident+'/receipt.json')
 if not p.exists():return None
 try:
  r=getjson(p)
  if r.get('package_id')!=ident:return None
  return r
 except (OSError,ValueError):return None

def active_prod(r):
 return r and r.get('status')=='installed' and all(target_matches(PROD,f['path'],f['new']) for f in r.get('changes',[]))

def inventory():
 """Export browser-safe receipt-backed inventory. No root filenames or credentials."""
 dirmode(STATE);dirmode(PROD_RECOVERY);dirmode(QA_DELETED)
 qa=installed_records(); qa_only=[]; recent=[]
 prod_records={}
 for folder in PROD_RECOVERY.iterdir():
  if folder.is_symlink() or not folder.is_dir() or not IDS.fullmatch(folder.name):continue
  rec=production_record(folder.name)
  if rec:prod_records[folder.name]=rec
 for ident,p in qa.items():
  if not verified_qa(p):continue
  in_prod=all(target_matches(PROD,f['target_path'],f['sha256']) for f in p['files'])
  if not in_prod:
   qa_only.append({'id':ident,'name':p['name'],'uploaded_at':p['uploaded_at'],
      'installed_at':p['installed_at'],'file_count':len(p['files']),
      'files':[f['target_path'] for f in p['files']],
      'sha256':p['sha256'],'description':p['description'],
      'in_production':False})
 for ident,r in prod_records.items():
  meta=STAGING/(ident+'.json')
  try:m=getjson(meta)
  except (OSError,ValueError):m={}
  verified=bool(active_prod(r))
  recent.append({'id':ident,'name':m.get('name',ident),'installed_at':r.get('installed_at'),
    'removed_at':r.get('removed_at'),'file_count':len(r.get('files',[])),
    'files':r.get('files',[]),'status':'installed' if verified else 'removed' if r.get('status')=='removed' else 'changed',
    'description':'Updates '+', '.join(x.split('/')[-1] for x in r.get('files',[])[:3])})
 recent.sort(key=lambda x:x.get('installed_at') or '',reverse=True)
 qa_only.sort(key=lambda x:x.get('installed_at') or '',reverse=True)
 payload={'updated_at':utc(),'qa_only':qa_only[:100],'production_recent':recent[:100],
 'total_qa_only':len(qa_only),'total_production':len(recent)}
 atom_json(REPORT,payload,0o644)
 return {'phase':f'Inventory updated: {len(qa_only)} QA-only and {len(recent)} production release(s)', 'qa_count':len(qa_only),'prod_count':len(recent)}

def assert_no_conflicting_newer(scope,ident,paths):
 if scope=='production':
  base=PROD_RECOVERY;current=production_record(ident)
  start=current.get('installed_at','') if current else ''
  for f in base.iterdir():
   if not f.is_dir() or f.name==ident or not IDS.fullmatch(f.name):continue
   r=production_record(f.name)
   if r and r.get('status')=='installed' and r.get('installed_at','')>start and any(x['path'] in paths for x in r.get('changes',[])):
    fail('A newer production release also modifies these files. Remove it first.')
 elif scope=='qa':
  packages=installed_records()
  if ident not in packages:fail('QA package is not an active installation')
  start=packages[ident]['folder'].name
  for key,p in packages.items():
   if key!=ident and p['folder'].name>start and any(f['target_path'] in paths for f in p['files']) and verified_qa(p):
    fail('A newer QA package also modifies these files. Remove it first.')

def write_file(dest,raw,reference=None):
 fd,temp=tempfile.mkstemp(prefix='.neptune-package-',dir=dest.parent)
 try:
  with os.fdopen(fd,'wb') as out:out.write(raw);out.flush();os.fsync(out.fileno())
  if reference and reference.exists():
   info=reference.stat();os.chown(temp,info.st_uid,info.st_gid);os.chmod(temp,stat.S_IMODE(info.st_mode))
  else:os.chown(temp,0,33);os.chmod(temp,0o644)
  if dest.suffix=='.php':
   chk=subprocess.run(['php','-l',temp],capture_output=True,timeout=20)
   if chk.returncode:fail('PHP syntax invalid: '+dest.name)
  os.replace(temp,dest)
 finally:
  if os.path.exists(temp):os.unlink(temp)

def restore_files(root,receipt,*,force=False):
 for row in reversed(receipt['changes']):
  dest=strict_target(root,row['path'])
  if not force and not target_matches(root,row['path'],row['new']):fail('File changed since installation: '+row['path'])
  if row['old'] is None:
   dest.unlink(missing_ok=True)
  else:
   back=Path(receipt['backup_dir'])/row['path']
   if not back.is_file() or back.is_symlink() or digest(back)!=row['old']:fail('Backup unavailable: '+row['path'])
   write_file(dest,back.read_bytes(),reference=back)

def promote(ident):
 validate(ident);dirmode(STATE);dirmode(PROD_RECOVERY)
 p=installed_records().get(ident)
 if not p:fail('This package has not been installed in QA')
 if not verified_qa(p):fail('QA file contents differ from the installed package. Recheck QA first.')
 old=production_record(ident)
 if old and old.get('status')=='installed':fail('This package already has an active Production installation')
 if old and old.get('status') not in ('removed',):fail('A prior incomplete release must be reviewed before this package is reused')
 if shutil.disk_usage(PROD).free<1024*1024*1024+3*sum(x['size'] for x in p['files']):fail('Not enough disk space for recovery')
 changes=[];staged={}
 with zipfile.ZipFile(p['zip']) as z:
  for row in p['files']:
   path=row['target_path'];prod=strict_target(PROD,path);new=z.read(row['archive_path'])
   if hashlib.sha256(new).hexdigest()!=row['sha256']:fail('Approved ZIP changed during promotion')
   if not target_matches(QA,path,row['sha256']):fail('QA changed since package installation: '+path)
   current=digest(prod) if prod.exists() else None
   if current==row['sha256']:continue
   changes.append({'path':path,'old':current,'new':row['sha256']});staged[path]=new
 if not changes:fail('All package files are already in production')
 folder=PROD_RECOVERY/ident
 folder.mkdir(mode=0o700,exist_ok=True)
 if folder.is_symlink():fail('Unsafe production receipt folder')
 generation=(int(old.get('generation',1))+1) if old else 1
 backup=folder/('previous-'+str(generation));backup.mkdir(mode=0o700)
 if old:atom_json(folder/('history-'+str(generation-1)+'.json'),old)
 receipt={'package_id':ident,'generation':generation,'status':'pending','installed_at':utc(),'backup_dir':str(backup),
   'files':[f['target_path'] for f in p['files']],'changes':changes}
 applied=[]
 try:
  for item in changes:
   dst=strict_target(PROD,item['path'])
   if item['old'] is not None:
    back=backup/item['path'];back.parent.mkdir(parents=True,exist_ok=True)
    shutil.copy2(dst,back)
    if digest(back)!=item['old']:fail('Backup verification failed: '+item['path'])
  atom_json(folder/'receipt.json',receipt)
  for item in changes:
   dst=strict_target(PROD,item['path'])
   if (digest(dst) if dst.exists() else None)!=item['old']:fail('Production changed during installation')
   write_file(dst,staged[item['path']],reference=dst)
   applied.append(item)
   if not target_matches(PROD,item['path'],item['new']):fail('Production file validation failed')
  if subprocess.run(['systemctl','is-active','--quiet','apache2'],timeout=10).returncode:
   fail('Apache service is not active')
  receipt['status']='installed';receipt['verified_at']=utc();atom_json(folder/'receipt.json',receipt)
  inventory();return {'phase':'Installed directly from QA into Production','files':len(changes)}
 except BaseException:
  if applied:
   restore_files(PROD,{**receipt,'changes':applied},force=True)
  receipt['status']='failed';atom_json(folder/'receipt.json',receipt)
  raise

def remove_production(ident):
 validate(ident)
 r=production_record(ident)
 if not r or r.get('status')!='installed':fail('This package is not recorded as installed in production')
 assert_no_conflicting_newer('production',ident,{f['path'] for f in r['changes']})
 # Check all preconditions before touching any production file.
 current={}
 for x in r['changes']:
  if not target_matches(PROD,x['path'],x['new']):fail('Production content changed: '+x['path'])
  dst=strict_target(PROD,x['path']);current[x['path']]=dst.read_bytes()
  if x['old'] is not None:
   b=Path(r['backup_dir'])/x['path']
   if not b.is_file() or digest(b)!=x['old']:fail('Recovery backup does not match')
 try:
  restore_files(PROD,r)
 except BaseException:
  # Undo any partial rollback using the exact bytes captured at operation start.
  for x in r['changes']:
   dst=strict_target(PROD,x['path'])
   if not target_matches(PROD,x['path'],x['new']):write_file(dst,current[x['path']],reference=dst)
  raise
 r['status']='removed';r['removed_at']=utc();atom_json(PROD_RECOVERY/ident/'receipt.json',r)
 inventory();return {'phase':'Production package removed and original files restored','files':len(r['changes'])}

def remove_qa(ident):
 validate(ident);dirmode(STATE);dirmode(QA_DELETED)
 p=installed_records().get(ident)
 if not p:fail('Package is not currently installed in QA')
 if not verified_qa(p):fail('QA contents no longer match this package')
 assert_no_conflicting_newer('qa',ident,{f['target_path'] for f in p['files']})
 module=qa_deployer()
 module.assert_qa_safety()
 for f in p['changed']:
  loc=module.read_target(f['scope'],f['relative_path'])
  if not loc or loc['sha256']!=f['new']:fail('QA file has been modified after this package')
  if f['old']:
   src=p['folder']/'before'/f['scope']/f['relative_path']
   if not src.is_file() or src.is_symlink() or digest(src)!=f['old']['sha256']:fail('QA recovery backup missing')
 restored=[]
 try:
  for f in reversed(p['changed']):
   s,rel=f['scope'],f['relative_path']
   if f['old']:
    module.put_file(s,rel,p['folder']/'before'/s/rel,mode=f['old']['mode'],uid=f['old']['uid'],gid=f['old']['gid'])
   else:module.remove_new(s,rel)
   restored.append(f)
 except BaseException:
  # Recover the new installed bytes if restoration failed partway through.
  with zipfile.ZipFile(p['zip']) as z:
   for item in reversed(restored):
    entry=next(x for x in p['files'] if x['target_path']==item['target_path'])
    fpath=STATE/('.qa-reinstall-'+ident)
    fpath.write_bytes(z.read(entry['archive_path']))
    try:module.put_file(item['scope'],item['relative_path'],fpath)
    finally:fpath.unlink(missing_ok=True)
  raise
 atom_json(QA_DELETED/(ident+'.json'),{'id':ident,'removed_at':utc()})
 inventory();return {'phase':'QA package removed and previous QA files restored','files':len(p['changed'])}
