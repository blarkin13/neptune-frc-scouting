#!/usr/bin/env python3
"""Root archive inventory and reversible quarantine; never touch arbitrary paths.

Conservative design: direct children of /root matching Neptune archive names only.
Directories, hidden files, symlinks, current/young archives and recovery folders
cannot be moved. Permanent purge requires a separate future approval feature.
"""
from __future__ import annotations
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import stat

ROOT=Path('/root')
STATE=Path('/var/lib/neptune-release-center')
REPORT=STATE/'reports/cleanup-inventory.json'
QUARANTINE=STATE/'cleanup-quarantine'
RECEIPTS=STATE/'cleanup-receipts'
ALLOWED=re.compile(r'^(?:Neptune_|neptune-)[A-Za-z0-9_.-]{1,160}\.(?:zip|tar\.gz|tgz)$')
EXCLUDE=('neptune-release-center-before-','neptune-footer-before-','neptune-prod-source-',
         'neptune-production-backup-','Neptune_Release_Center_Phase4B')
AGE_MIN=7*24*3600


def stop(m):raise ValueError(m)
def utc():return dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds').replace('+00:00','Z')
def sha(p):
 h=hashlib.sha256()
 with p.open('rb') as f:
  for x in iter(lambda:f.read(1048576),b''):h.update(x)
 return h.hexdigest()
def name_ok(name):
 return isinstance(name,str) and ALLOWED.fullmatch(name) and not any(name.startswith(x) for x in EXCLUDE)
def dir_safe(p):
 p.mkdir(parents=True,mode=0o700,exist_ok=True)
 if p.is_symlink() or p.stat().st_uid!=0 or p.stat().st_mode&0o077:stop('Unsafe cleanup private directory')
def atomic_json(p,v):
 import tempfile
 fd,temp=tempfile.mkstemp(dir=p.parent,prefix='.cleanup-state-')
 try:
  with os.fdopen(fd,'w') as o:json.dump(v,o);o.flush();os.fsync(o.fileno())
  os.chmod(temp,0o600 if 'reports' not in p.parts else 0o644);os.replace(temp,p)
 finally:
  if os.path.lexists(temp):os.unlink(temp)

def scan():
 """Only direct root archive files. Review remains user-triggered; no delete."""
 candidates=[];now=dt.datetime.now(dt.timezone.utc).timestamp();names={}
 for p in ROOT.iterdir():
  if not name_ok(p.name) or p.is_symlink():continue
  info=p.lstat()
  if not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or info.st_uid!=0:continue
  age=int(max(0,now-info.st_mtime))
  # Hash only reasonably sized root ZIPs, not the 2.2GiB database backups.
  fingerprint=sha(p) if info.st_size<=250*1024*1024 else None
  eligible=age>=AGE_MIN and info.st_size<=250*1024*1024
  names[p.name]=(p,info)
  candidates.append({'name':p.name,'size_bytes':info.st_size,'modified_at':dt.datetime.fromtimestamp(info.st_mtime,dt.timezone.utc).isoformat(timespec='seconds').replace('+00:00','Z'),
   'age_days':age//86400,'sha256':fingerprint,'eligible':eligible,'reason':'Ready for quarantine' if eligible else 'Too recent or too large'})
 fingerprints={}
 for x in candidates:
  if x['sha256']:
   fingerprints.setdefault(x['sha256'],[]).append(x['name'])
 for x in candidates:
  x['duplicate_count']=len(fingerprints.get(x['sha256'],[]))-1 if x['sha256'] else 0
 candidates.sort(key=lambda x:(-int(x['eligible']),-x['age_days'],x['name']))
 # Non-destructive direct-child inventory for administrator review only.
 # Hidden dotfiles, secrets, symlinks and arbitrary paths are never shown here.
 # No scanner result can authorize arbitrary file/folder deletion; quarantine()
 # continues to permit only eligible fingerprinted Neptune archive files.
 directories=[];other_files=[]
 hidden_re=re.compile(r'(?:password|secret|token|credential|private|key|\.env|config|pem|ssh)',re.I)
 for p in ROOT.iterdir():
  name=p.name
  if name.startswith('.') or hidden_re.search(name) or p.is_symlink() or len(name)>180:
   continue
  try:meta=p.lstat()
  except OSError:continue
  if meta.st_uid!=0:continue
  age=int(max(0,now-meta.st_mtime))//86400
  if stat.S_ISDIR(meta.st_mode):
   try:empty=next(p.iterdir(),None) is None
   except (OSError,PermissionError):empty=False
   directories.append({'name':name,'age_days':age,'empty':empty,'review':
      'Empty · manual review' if empty and age>=7 else 'Protected · no automatic deletion'})
  elif stat.S_ISREG(meta.st_mode) and not name_ok(name):
   other_files.append({'name':name,'age_days':age,'size_bytes':meta.st_size,
     'review':'Manual review · no automatic deletion'})
 directories.sort(key=lambda x:x['name'].lower())
 other_files.sort(key=lambda x:x['name'].lower())
 return {'scanned_at':utc(),'scope':'direct /root Neptune archives, non-hidden folders and files (review only)','items':candidates[:200],
   'directories':directories[:100],'directories_total':len(directories),
   'other_files':other_files[:100],'other_files_total':len(other_files),
   'total_archives':len(candidates),'eligible_count':sum(x['eligible'] for x in candidates),
   'eligible_bytes':sum(x['size_bytes'] for x in candidates if x['eligible'])}

def inventory():
 report=scan();atomic_json(REPORT,report)
 if QUARANTINE.is_dir():
  atomic_json(STATE/'reports/cleanup-quarantine-list.json',{'items':list_quarantine(),'updated_at':utc()})
 return {'phase':'Root archive inventory completed; nothing deleted',
   'candidate_count':report['total_archives'],'eligible_count':report['eligible_count'],'eligible_bytes':report['eligible_bytes']}

def quarantine(request_id):
 """Move selected ZIPs into a root-only holding area; does not free disk space."""
 if not re.fullmatch(r'[a-f0-9]{32}',request_id):stop('Invalid cleanup request ID')
 request=STATE/'staging'/('cleanup-'+request_id+'.json')
 if request.is_symlink() or not request.is_file() or request.stat().st_size>4096:stop('Missing or unsafe cleanup request')
 record=json.loads(request.read_text());selection=record.get('names')
 if (record.get('id')!=request_id or not isinstance(selection,list) or not 1<=len(selection)<=10 or
     len(set(selection))!=len(selection) or any(not name_ok(n) for n in selection)):
  stop('Invalid cleanup selection')
 current={x['name']:x for x in scan()['items']}
 for name in selection:
  item=current.get(name)
  if not item or not item['eligible'] or item['sha256']!=record.get('sha256',{}).get(name):
   stop('Candidate no longer eligible or fingerprint changed: '+name)
 dir_safe(QUARANTINE);dir_safe(RECEIPTS)
 stamp=dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
 target=QUARANTINE/(stamp+'-'+request_id);target.mkdir(mode=0o700)
 moved=[]
 try:
  for name in selection:
   p=ROOT/name
   if p.is_symlink() or not p.is_file() or p.stat().st_nlink!=1:stop('Candidate changed: '+name)
   dst=target/name
   if dst.exists():stop('Duplicate quarantine name')
   if sha(p)!=record['sha256'][name]:stop('Candidate changed since selection')
   os.rename(p,dst);moved.append(name)
  report={'request_id':request_id,'quarantined_at':utc(),'files':[
   {'name':n,'sha256':record['sha256'][n],'bytes':(target/n).stat().st_size} for n in moved],
   'directory':str(target)}
  atomic_json(RECEIPTS/(request_id+'.json'),report)
  inventory()
  return {'phase':'Selected archived packages moved into private quarantine; not deleted',
   'request_id':request_id,'count':len(moved),'bytes':sum(f['bytes'] for f in report['files'])}
 except BaseException:
  for name in moved:
   os.rename(target/name,ROOT/name)
  raise

def restore(request_id):
 if not re.fullmatch(r'[a-f0-9]{32}',request_id):stop('Invalid cleanup restore ID')
 receipt=RECEIPTS/(request_id+'.json')
 if receipt.is_symlink() or not receipt.is_file():stop('Quarantine receipt not found')
 data=json.loads(receipt.read_text());target=Path(data['directory'])
 if target.parent!=QUARANTINE or target.is_symlink():stop('Invalid quarantine recovery path')
 items=data['files']
 for x in items:
  name=x['name'];origin=ROOT/name;stored=target/name
  if not name_ok(name) or origin.exists() or origin.is_symlink() or stored.is_symlink() or not stored.is_file() or sha(stored)!=x['sha256']:
   stop('Original location occupied or package fingerprint mismatch: '+name)
 for x in items:os.rename(target/x['name'],ROOT/x['name'])
 receipt.unlink()
 try:target.rmdir()
 except OSError:pass
 inventory()
 return {'phase':'Quarantined packages restored to /root','request_id':request_id,'count':len(items)}

def list_quarantine():
 result=[]
 if RECEIPTS.is_dir():
  for p in RECEIPTS.glob('*.json'):
   if p.is_symlink() or not re.fullmatch(r'[a-f0-9]{32}\.json',p.name):continue
   try:
    d=json.loads(p.read_text());result.append({'request_id':d['request_id'],'at':d['quarantined_at'],'files':[{'name':f['name'],'bytes':f['bytes']} for f in d['files']]})
   except (OSError,KeyError,ValueError):continue
 return sorted(result,key=lambda x:x['at'],reverse=True)[:30]
