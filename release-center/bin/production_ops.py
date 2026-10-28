#!/usr/bin/env python3
"""Neptune production deployment: fail-closed GitHub + QA provenance, narrow file writes.

Only called from a root-owned, pre-installed fixed-action worker. Never executes ZIP code.
Database, uploaded media and symlinks are never modified.
"""
from __future__ import annotations
import base64
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import zipfile

PROD=Path('/var/www/neptune')
QA=Path('/var/www/neptune-qa')
STATE=Path('/var/lib/neptune-release-center')
STAGE=STATE/'staging'
PREFLIGHT=STATE/'production-plans'
RECOVERY=STATE/'production-recovery'
HEALTH='https://neptune.mckinneysteamacademy.org/'
PACKAGE_ID=re.compile(r'^[a-f0-9]{32}$')
SHA=re.compile(r'^[a-f0-9]{64}$')
GIT_SHA=re.compile(r'^[a-f0-9]{40}$')


def stop(message):raise ValueError(message)
def utc():return dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds').replace('+00:00','Z')
def digest(p):
 h=hashlib.sha256()
 with p.open('rb') as f:
  for b in iter(lambda:f.read(1048576),b''):h.update(b)
 return h.hexdigest()
def git_blob(data):return hashlib.sha1(('blob %d\0'%len(data)).encode()+data).hexdigest()
def ensure_dir(p):
 p.mkdir(mode=0o700,parents=True,exist_ok=True)
 if p.is_symlink() or p.stat().st_uid!=0 or p.stat().st_mode&0o077:stop('Unsafe production state directory')
def strict_target(root,rel,*,need_exists=False):
 """Reject parent symlinks and any target crossing out of the approved code root."""
 if not isinstance(rel,str) or len(rel)>260 or rel.startswith('/') or '\\' in rel or any(x in ('','.','..') for x in rel.split('/')):stop('Unsafe production relative path')
 if not (rel.startswith('public_html/Neptune/') or rel.startswith('neptune_secure/')):stop('Path outside approved application scope')
 cur=root
 if cur.is_symlink() or not cur.is_dir():stop('Unsafe application root')
 parts=rel.split('/')
 for i,part in enumerate(parts):
  cur=cur/part
  if cur.is_symlink():stop('Symlink in production path')
  if cur.exists():
   if i<len(parts)-1 and not cur.is_dir():stop('Non-directory parent in application path')
   if i==len(parts)-1 and not cur.is_file():stop('Deployment target is not a regular file')
  elif i<len(parts)-1:stop('Parent directory missing; no recursive creation in production')
 if need_exists and not cur.exists():stop('Required application file missing')
 return cur

def file_tree(gh,token,commit_sha):
 if not GIT_SHA.fullmatch(str(commit_sha)):stop('Invalid Git commit SHA')
 commit=gh(token,'GET','/git/commits/'+commit_sha)
 tree_sha=commit['tree']['sha']
 tree=gh(token,'GET','/git/trees/'+tree_sha+'?recursive=1')
 if tree.get('truncated') or len(tree.get('tree',[]))>20000:stop('Cannot verify complete GitHub tree')
 return {x['path']:x['sha'] for x in tree['tree'] if x.get('type')=='blob' and x.get('mode')=='100644'}

def github_provenance(gh,token,ident,meta,plan,github):
 """Package must have been merged in a precise PR; merged main must match ZIP entries."""
 if (github.get('state')!='success' or github.get('package_id')!=ident or
     github.get('sha256')!=meta['sha256'] or
     type(github.get('pr_number')) is not int):
  stop('No successful GitHub publication receipt for this exact QA-tested ZIP')
 number=github['pr_number'];pr=gh(token,'GET','/pulls/'+str(number))
 if (pr.get('state')!='closed' or not pr.get('merged_at') or
     pr.get('base',{}).get('ref')!='main' or
     pr.get('head',{}).get('ref')!='neptune/qa-'+ident[:14] or
     ('SHA-256: `'+meta['sha256']+'`') not in (pr.get('body') or '')):
  stop('Associated GitHub PR is not merged into main with matching QA ZIP receipt')
 if pr.get('changed_files',999)>70:stop('Merged PR too large for controlled promotion')
 rows=gh(token,'GET','/pulls/'+str(number)+'/files?per_page=100')
 if not isinstance(rows,list) or len(rows)!=len(plan):stop('GitHub PR file count differs from QA-tested ZIP')
 if {x.get('filename') for x in rows}!={x['target_path'] for x in plan}:stop('GitHub PR contains unrelated file changes')
 if any(x.get('status') not in ('modified','added') for x in rows):stop('GitHub PR contains deletion/rename or unsupported file changes')
 main=gh(token,'GET','/git/ref/heads/main')['object']['sha']
 base=pr.get('base',{}).get('sha')
 if not GIT_SHA.fullmatch(str(main)) or not GIT_SHA.fullmatch(str(base)):stop('Invalid GitHub branch/base SHA')
 return {'number':number,'main_sha':main,'base_sha':base,'main_tree':file_tree(gh,token,main),'base_tree':file_tree(gh,token,base)}

def make_plan(ident,qa_approval,gh,token,github):
 """Read-only, rechecks staging, QA, GitHub merged main and live production."""
 if not PACKAGE_ID.fullmatch(str(ident)):stop('Invalid release package ID')
 zip_path,meta,files,deploy=qa_approval(ident)
 ghp=github_provenance(gh,token,ident,meta,files,github)
 changes=[]
 with zipfile.ZipFile(zip_path) as z:
  for f in files:
   rel=f['target_path'];target=strict_target(PROD,rel);qfile=strict_target(QA,rel,need_exists=True)
   new=z.read(f['archive_path']);wanted=f['sha256']
   if hashlib.sha256(new).hexdigest()!=wanted:stop('Archive content digest mismatch')
   if digest(qfile)!=wanted:stop('QA differs from manually tested ZIP: '+rel)
   if ghp['main_tree'].get(rel)!=git_blob(new):stop('Merged GitHub main differs from QA ZIP: '+rel)
   base_git=ghp['base_tree'].get(rel)
   if target.exists():
    current=target.read_bytes()
    if base_git is None or git_blob(current)!=base_git:stop('Production drift from GitHub PR base: '+rel)
    previous=hashlib.sha256(current).hexdigest()
   else:
    if base_git is not None:stop('Expected production file is missing: '+rel)
    previous=None
   if previous==wanted:stop('Update already present in production: '+rel)
   changes.append({'path':rel,'archive_path':f['archive_path'],'from_sha256':previous,'to_sha256':wanted,'bytes':len(new)})
 return {'kind':'neptune-production-preflight','schema':1,'package_id':ident,
         'zip_sha256':meta['sha256'],'pr_number':ghp['number'],'github_base_sha':ghp['base_sha'],
         'github_main_sha':ghp['main_sha'],'qa_finished_at':deploy['finished_at'],
         'checked_at':utc(),'files':changes}

def plan_path(ident):return PREFLIGHT/('plan-'+ident+'.json')
def write_json_atomic(path,obj):
 data=json.dumps(obj,sort_keys=True,separators=(',',':')).encode();fd,temp=tempfile.mkstemp(prefix='.prod-state-',dir=path.parent)
 try:
  with os.fdopen(fd,'wb') as o:o.write(data);o.flush();os.fsync(o.fileno())
  os.chmod(temp,0o600);os.replace(temp,path)
 finally:
  if os.path.exists(temp):os.unlink(temp)

def preflight(ident,qa_approval,gh,token,github):
 plan=make_plan(ident,qa_approval,gh,token,github)
 ensure_dir(PREFLIGHT);write_json_atomic(plan_path(ident),plan)
 return {'package_id':ident,'pr_number':plan['pr_number'],'files':len(plan['files']),
         'main_sha':plan['github_main_sha'],'phase':'Preflight passed; production remains unchanged',
         'can_deploy':True,'checked_at':plan['checked_at']}

def check_services():
 # Code validation and public HTTP response are liveness checks, not functional testing.
 r=subprocess.run(['systemctl','is-active','--quiet','apache2.service'],timeout=10)
 if r.returncode:stop('Apache service is not active after production installation')
 req=urllib.request.Request(HEALTH,headers={'User-Agent':'Neptune-Mission-Control-Release-Health/1'})
 try:
  with urllib.request.urlopen(req,timeout=12) as h:
   if h.status not in (200,204):stop('Production health probe returned HTTP '+str(h.status))
   h.read(1024)
 except urllib.error.HTTPError as e:
  if e.code not in (302,303,401,403):stop('Production health probe returned HTTP '+str(e.code))
 except (OSError,TimeoutError) as e:stop('Production health probe failed: '+type(e).__name__)

def restore_receipt(receipt,*,verify_deployed):
 files=receipt['files'];folder=Path(receipt['recovery'])
 if not folder.is_dir() or folder.is_symlink() or folder.parent!=RECOVERY:stop('Recovery folder missing or unsafe')
 for f in files:
  target=strict_target(PROD,f['path'])
  if verify_deployed and (not target.exists() or digest(target)!=f['to_sha256']):stop('Manual rollback refused: current production no longer matches deployed release')
 for f in reversed(files):
  dst=strict_target(PROD,f['path'])
  if f['from_sha256'] is None:
   dst.unlink(missing_ok=True)
   continue
  source=folder/'files'/f['path']
  if not source.is_file() or source.is_symlink() or digest(source)!=f['from_sha256']:stop('Recovery backup invalid: '+f['path'])
  fd,temp=tempfile.mkstemp(prefix='.prod-rollback-',dir=dst.parent)
  try:
   with os.fdopen(fd,'wb') as o:
    with source.open('rb') as inp:shutil.copyfileobj(inp,o)
    o.flush();os.fsync(o.fileno())
   st=source.stat();os.chown(temp,st.st_uid,st.st_gid);os.chmod(temp,stat.S_IMODE(st.st_mode));os.replace(temp,dst)
  finally:
   if os.path.exists(temp):os.unlink(temp)

def deploy(ident,qa_approval,gh,token,github):
 ensure_dir(PREFLIGHT);ensure_dir(RECOVERY)
 plan_file=plan_path(ident)
 if not plan_file.is_file() or plan_file.is_symlink():stop('Run production preflight before deploying')
 approved=json.loads(plan_file.read_text());ts=dt.datetime.fromisoformat(approved['checked_at'].replace('Z','+00:00'))
 if (dt.datetime.now(dt.timezone.utc)-ts).total_seconds()>1800:stop('Production preflight expired; run it again')
 fresh=make_plan(ident,qa_approval,gh,token,github)
 a={k:v for k,v in approved.items() if k!='checked_at'}
 b={k:v for k,v in fresh.items() if k!='checked_at'}
 if a!=b:stop('Live production, QA or GitHub changed since preflight')
 stamp=dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
 folder=RECOVERY/(stamp+'-'+ident)
 folder.mkdir(mode=0o700)
 receipt=dict(approved,recovery=str(folder),installed_at=utc())
 applied=[]
 zp=STAGE/(ident+'.zip')
 try:
  with zipfile.ZipFile(zp) as z:
   for f in approved['files']:
    source=strict_target(PROD,f['path'])
    if f['from_sha256'] is not None:
     dest=folder/'files'/f['path'];dest.parent.mkdir(mode=0o700,parents=True,exist_ok=True);shutil.copy2(source,dest)
     if digest(dest)!=f['from_sha256']:stop('Backup verification failed')
   write_json_atomic(folder/'receipt.json',receipt)
   # Write to temporary sibling files, lint PHP before installing, preserve ownership.
   for f in approved['files']:
    dst=strict_target(PROD,f['path']);raw=z.read(f['archive_path'])
    if hashlib.sha256(raw).hexdigest()!=f['to_sha256']:stop('Uploaded ZIP changed after approval')
    fd,temp=tempfile.mkstemp(prefix='.prod-release-',suffix=Path(f['path']).suffix,dir=dst.parent)
    try:
     with os.fdopen(fd,'wb') as o:o.write(raw);o.flush();os.fsync(o.fileno())
     if dst.exists():
      st=dst.stat();os.chown(temp,st.st_uid,st.st_gid);os.chmod(temp,stat.S_IMODE(st.st_mode))
     else:os.chown(temp,0,33);os.chmod(temp,0o644)
     if f['path'].endswith('.php'):
      if subprocess.run(['php','-l',temp],capture_output=True,timeout=15).returncode:stop('PHP syntax check failed for '+f['path'])
     os.replace(temp,dst);applied.append(f)
    finally:
     if os.path.exists(temp):os.unlink(temp)
  check_services()
  receipt['verified_at']=utc();write_json_atomic(folder/'receipt.json',receipt)
  return {'package_id':ident,'recovery':str(folder),'pr_number':approved['pr_number'],
          'files':len(applied),'verified_at':receipt['verified_at'],
          'phase':'Production files installed; PHP syntax and HTTP liveness passed'}
 except BaseException:
  if applied:
   restore_receipt(dict(receipt,files=applied),verify_deployed=False)
  raise

def rollback(ident,last_receipt):
 if last_receipt.get('state')!='success' or last_receipt.get('package_id')!=ident or not last_receipt.get('recovery'):
  stop('Only the most recent successful production deployment is eligible for rollback')
 folder=Path(last_receipt['recovery'])
 if folder.parent!=RECOVERY:stop('Invalid production rollback directory')
 receipt=json.loads((folder/'receipt.json').read_text())
 if receipt.get('package_id')!=ident:stop('Rollback receipt does not match selected release')
 restore_receipt(receipt,verify_deployed=True)
 check_services()
 return {'package_id':ident,'recovery':str(folder),'phase':'Production recovery restored and liveness checked',
         'rolled_back_at':utc(),'files':len(receipt['files'])}
