#!/usr/bin/env python3
"""Root-only fixed-action worker. Never run installer scripts from user ZIPs."""
from __future__ import annotations
import base64
import datetime as dt
import fcntl
import hashlib
import hmac
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
import zipfile
from mission_control_policy import inspect as inspect_update
from qa_package_policy import inspect as inspect_qa
import production_ops
import cleanup_ops
import package_flow_ops
import github_mirror_ops

BASE=Path('/var/lib/neptune-release-center')
STAGE=BASE/'staging';REPORTS=BASE/'reports';REQUEST=STAGE/'mission-control.request'
ROOT=Path('/opt/neptune-release-center');RECOVERY=BASE/'mission-control-recovery'
MC_STATUS=REPORTS/'mission-control-status.json';GITHUB_STATUS=REPORTS/'github-publish-status.json'
PROD_STATUS=REPORTS/'production-status.json';PROD_LAST=REPORTS/'production-last-success.json'
CLEAN_STATUS=REPORTS/'cleanup-status.json';PACKAGE_STATUS=REPORTS/'package-flow-status.json'
MIRROR_STATUS=REPORTS/'github-mirror-status.json'
FLASH_REQ=STAGE/'flash-qa.request';FLASH_STATUS=REPORTS/'flash-qa-status.json';DEPLOY_STATUS=REPORTS/'deploy-qa-status.json'
APPROVAL=STAGE/'qa-test-approval.state';TOKEN=Path('/etc/neptune-release-center/github-token')
LOCK=Path('/run/lock/neptune-release-mission-control.lock')
QA_LOCK=Path('/run/lock/neptune-release-flash-web.lock')
ID=re.compile(r'^[a-f0-9]{32}$');SHA=re.compile(r'^[a-f0-9]{64}$')
REPO='blarkin13/neptune-frc-scouting';BASE_BRANCH='main'


def now():return dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds').replace('+00:00','Z')
def fail(message):raise ValueError(message)
def load(path):return json.loads(path.read_bytes())
def sha(path):
    h=hashlib.sha256()
    with path.open('rb') as src:
        for block in iter(lambda:src.read(1024*1024),b''):h.update(block)
    return h.hexdigest()
def safe_file(path,*,owner=None,max_size=10_000_000):
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_CLOEXEC)
    try:
        info=os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or info.st_size>max_size or (owner is not None and info.st_uid!=owner) or (info.st_mode&0o022):
            fail('Unsafe staged file owner/mode/type')
    finally:os.close(fd)
def write_status(path,**fields):
    fd,temp=tempfile.mkstemp(prefix='.mission-status-',dir=REPORTS)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(fields,f,separators=(',',':'));f.flush();os.fsync(f.fileno())
        os.chmod(temp,0o644);os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def no_qa_running():
    if FLASH_REQ.exists():fail('A QA flash/deploy is queued')
    for path in (FLASH_STATUS,DEPLOY_STATUS):
        if path.exists() and load(path).get('state') in ('running','queued'):fail('QA operation is running')
    r=subprocess.run(['systemctl','is-active','neptune-release-flash.service'],capture_output=True,text=True,timeout=10)
    if r.stdout.strip()=='active':fail('QA worker is running')

def current_qa_approval(ident):
    if not ID.fullmatch(ident):fail('Invalid package ID')
    no_qa_running()
    deploy=load(DEPLOY_STATUS)
    if deploy.get('state')!='success' or deploy.get('package_id')!=ident or not isinstance(deploy.get('finished_at'),str):
        fail('Not the latest successfully deployed QA ZIP')
    if FLASH_STATUS.exists():
        flash=load(FLASH_STATUS)
        if flash.get('state') in ('running','queued') or str(flash.get('started_at') or '')>=deploy['finished_at']:
            fail('QA refresh invalidated tested package')
    meta=load(STAGE/(ident+'.json'))
    if meta.get('id')!=ident or meta.get('policy_version')!=2 or not SHA.fullmatch(str(meta.get('sha256',''))):fail('Invalid staged package metadata')
    approval=load(APPROVAL)
    if any(approval.get(k)!=v for k,v in {'package_id':ident,'sha256':meta['sha256'],'deploy_finished_at':deploy['finished_at']}.items()):
        fail('Manual QA sign-off required for exact deployment')
    zp=STAGE/(ident+'.zip')
    safe_file(zp,owner=pwd.getpwnam('neptunerelease').pw_uid,max_size=50*1024*1024)
    if not hmac.compare_digest(sha(zp),meta['sha256']):fail('Package ZIP changed after QA sign-off')
    files=inspect_qa(zp)
    if len(files)!=meta.get('eligible_count'):fail('ZIP file plan changed after QA install')
    if len(files)>70 or sum(f['size'] for f in files)>12*1024*1024:fail('GitHub PR limited to 70 files / 12 MiB')
    return zp,meta,files,deploy

def github_token():
    safe_file(TOKEN,owner=0,max_size=300)
    st=TOKEN.stat()
    if st.st_mode & 0o077:fail('GitHub token permissions must be root-only (0600)')
    token=TOKEN.read_text().strip()
    if len(token)<20 or any(c.isspace() for c in token):fail('Invalid GitHub token file')
    return token

def gh_request(token,method,endpoint,payload=None):
    url='https://api.github.com/repos/'+REPO+endpoint
    body=json.dumps(payload).encode() if payload is not None else None
    req=urllib.request.Request(url, data=body, method=method, headers={'Accept':'application/vnd.github+json', 'Authorization':'Bearer '+token, 'X-GitHub-Api-Version':'2022-11-28','User-Agent':'Neptune-Release-Mission-Control','Content-Type':'application/json'})
    try:
        with urllib.request.urlopen(req,timeout=25) as r:
            return json.loads(r.read(5*1024*1024))
    except urllib.error.HTTPError as err:
        # NO response body, and never leak Authorization header into logs.
        fail('GitHub API returned HTTP '+str(err.code)+' for '+method+' '+endpoint.split('?')[0])

def publish_pr(ident):
    zip_path,meta,plan,deploy=current_qa_approval(ident)
    token=github_token()
    last=load(GITHUB_STATUS) if GITHUB_STATUS.exists() else {}
    if last.get('state')=='success' and last.get('package_id')==ident and last.get('sha256')==meta['sha256']:
        fail('This tested package has already been published')
    branch='neptune/qa-'+ident[:14]
    expected_title='QA-tested Neptune patch: '+meta['name'][:90]
    expected_body=('Manually tested in QA. Review every changed file before merging.\n\n'
                   'ZIP: `'+meta['name']+'`\n\nSHA-256: `'+meta['sha256']+'`\n\n'
                   'QA installed: `'+deploy['finished_at']+'`\n\n'
                   'This draft pull request does not deploy to production.')
    # Reconcile a previous partially completed request *before* creating Git objects.
    # A response from GitHub may be lost after a successful write; retries must not
    # create an unrelated branch or replace a branch an operator has edited.
    prs=gh_request(token,'GET','/pulls?state=all&head=blarkin13:'+branch+'&base='+BASE_BRANCH+'&per_page=100')
    matched=[p for p in prs if p.get('head',{}).get('ref')==branch and p.get('base',{}).get('ref')==BASE_BRANCH]
    if matched:
        if len(matched)!=1:fail('Ambiguous GitHub PRs for tested package branch')
        p=matched[0]
        if p.get('title')!=expected_title or p.get('body')!=expected_body:
            fail('Existing PR metadata does not match QA ZIP; inspect GitHub')
        url=str(p.get('html_url',''))
        if not re.fullmatch(r'https://github\.com/blarkin13/neptune-frc-scouting/pull/[0-9]+',url):fail('Unexpected existing PR URL')
        if p.get('state')=='open' and p.get('draft') is True:
            # A matching draft is an idempotent success, not a failed duplicate.
            return {'url':url,'branch':branch,'sha256':meta['sha256'],'package_id':ident,'pr_number':p['number'],'merged':False}
        if p.get('state')=='closed' and p.get('merged_at'):
            # Reconcile a previously merged PR ONLY after verifying exact merged main bytes.
            # Never create a second branch or accept a stale/overridden PR as proof.
            details=gh_request(token,'GET','/pulls/'+str(p['number']))
            if details.get('base',{}).get('ref')!=BASE_BRANCH or not details.get('merged_at'):
                fail('PR has not been verified as merged into main')
            changed=gh_request(token,'GET','/pulls/'+str(p['number'])+'/files?per_page=100')
            if not isinstance(changed,list) or {x.get('filename') for x in changed}!={x['target_path'] for x in plan} or len(changed)!=len(plan):
                fail('Merged PR includes files outside tested ZIP')
            if any(x.get('status') not in ('modified','added') for x in changed):
                fail('Merged PR contains unsupported changes')
            main=gh_request(token,'GET','/git/ref/heads/'+BASE_BRANCH)['object']['sha']
            commit=gh_request(token,'GET','/git/commits/'+main)
            tree=gh_request(token,'GET','/git/trees/'+commit['tree']['sha']+'?recursive=1')
            if tree.get('truncated'):fail('GitHub main tree was truncated')
            file_hashes={x['path']:x['sha'] for x in tree.get('tree',[]) if x.get('type')=='blob' and x.get('mode')=='100644'}
            with zipfile.ZipFile(zip_path) as archive:
                for f in plan:
                    data=archive.read(f['archive_path'])
                    if hashlib.sha256(data).hexdigest()!=f['sha256']:
                        fail('Staged package changed during GitHub reconciliation')
                    expected=hashlib.sha1(('blob '+str(len(data))+'\0').encode()+data).hexdigest()
                    if file_hashes.get(f['target_path'])!=expected:
                        fail('GitHub main no longer contains exact QA-tested ZIP: '+f['target_path'])
            return {'url':url,'branch':branch,'sha256':meta['sha256'],'package_id':ident,'pr_number':p['number'],'merged':True}
        fail('Existing PR is neither a draft nor a merged release; inspect GitHub')
    # If a branch exists without a PR, stop; don't overwrite or duplicate it.
    refs=gh_request(token,'GET','/git/matching-refs/heads/'+branch)
    if any(r.get('ref')=='refs/heads/'+branch for r in refs):
        fail('Existing release branch without matching draft PR; inspect GitHub')
    base_ref=gh_request(token,'GET','/git/ref/heads/'+BASE_BRANCH)
    commit_sha=base_ref['object']['sha']
    base_commit=gh_request(token,'GET','/git/commits/'+commit_sha)
    tree_sha=base_commit['tree']['sha']
    entries=[]
    with zipfile.ZipFile(zip_path) as z:
        for item in plan:
            with z.open(item['archive_path']) as stream:data=stream.read(30*1024*1024+1)
            if hashlib.sha256(data).hexdigest()!=item['sha256']:fail('ZIP entry changed during publication')
            blob=gh_request(token,'POST','/git/blobs',{'content':base64.b64encode(data).decode(),'encoding':'base64'})
            entries.append({'path':item['target_path'],'mode':'100644','type':'blob','sha':blob['sha']})
    tree=gh_request(token,'POST','/git/trees',{'base_tree':tree_sha,'tree':entries})
    commit=gh_request(token,'POST','/git/commits',{'message':'Neptune QA-tested patch '+ident[:14], 'tree':tree['sha'],'parents':[commit_sha]})
    try:
        gh_request(token,'POST','/git/refs',{'ref':'refs/heads/'+branch,'sha':commit['sha']})
    except ValueError:
        fail('GitHub branch creation failed or raced; inspect existing branch/PR before retry')
    pr=gh_request(token,'POST','/pulls',{'title':expected_title,'head':branch,'base':BASE_BRANCH,'body':expected_body,'draft':True})
    url=str(pr['html_url'])
    if not re.fullmatch(r'https://github\.com/blarkin13/neptune-frc-scouting/pull/[0-9]+',url):fail('Unexpected GitHub PR URL')
    return {'url':url,'branch':branch,'sha256':meta['sha256'],'package_id':ident,'pr_number':pr['number']}


def update_control(ident):
    if not ID.fullmatch(ident):fail('Invalid Neptune Release Control update ID')
    uid=pwd.getpwnam('neptunerelease').pw_uid
    meta_path=STAGE/('mc-'+ident+'.json');path=STAGE/('mc-'+ident+'.zip')
    safe_file(meta_path,owner=uid,max_size=20_000)
    safe_file(path,owner=uid,max_size=8*1024*1024)
    meta=load(meta_path)
    if meta.get('id')!=ident or not SHA.fullmatch(str(meta.get('sha256',''))) or not hmac.compare_digest(sha(path),meta['sha256']):
        fail('Neptune Release Control ZIP/metadata fingerprint mismatch')
    plan=inspect_update(path)
    if plan!=meta.get('plan'):fail('Neptune Release Control update manifest changed since validation')
    for f in plan['files']:
        target=ROOT/f['path']
        if not target.is_file() or target.is_symlink() or target.stat().st_uid!=0:
            fail('Unexpected installed Neptune Release Control file: '+f['path'])
        if (f['path'].startswith('bin/') or f['path']=='web/server.py') and not plan.get('verified_signature'):
            fail('Root-owned code may only be updated by signed bundles')
    RECOVERY.mkdir(mode=0o700,parents=True,exist_ok=True)
    if RECOVERY.is_symlink() or RECOVERY.stat().st_uid!=0:fail('Unsafe recovery location')
    folder=RECOVERY/(now().replace(':','').replace('-','')+'-'+ident)
    folder.mkdir(mode=0o700)
    installed=[]
    try:
        with zipfile.ZipFile(path) as z:
            for f in plan['files']:
                src=ROOT/f['path'];dst=folder/f['path'];dst.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
                shutil.copy2(src,dst)
            for f in plan['files']:
                target=ROOT/f['path'];temp=target.with_name('.mc-update-'+ident+'-'+target.name)
                if temp.exists() or temp.is_symlink():fail('Stale temporary Neptune Release Control file')
                try:
                    with temp.open('xb') as out:
                        with z.open(f['path']) as inp:shutil.copyfileobj(inp,out)
                    if sha(temp)!=f['sha256']:fail('Deployed Neptune Release Control file digest mismatch')
                    os.chown(temp,0,0);os.chmod(temp,0o644)
                    os.replace(temp,target);installed.append(f['path'])
                finally:temp.unlink(missing_ok=True)
        result=subprocess.run(['systemctl','restart','neptune-release-center.service'],capture_output=True,text=True,timeout=25)
        if result.returncode or subprocess.run(['systemctl','is-active','--quiet','neptune-release-center.service'],timeout=10).returncode:
            fail('Neptune Release Control restart failed')
        # Check website behind private Basic Auth, never require public exposure.
        healthy=False
        for _ in range(10):
            try:urllib.request.urlopen('http://127.0.0.1:18081/api/telemetry',timeout=2)
            except urllib.error.HTTPError as err:
                if err.code==401:healthy=True;break
            except (OSError,TimeoutError):pass
            time.sleep(.5)
        if not healthy:fail('Private Mission Control health probe failed')
        return {'version':plan['version'],'files':len(plan['files']),'recovery':str(folder),
                'phase':'Signed backend and web update installed' if plan['schema']==2 else 'Web-only update installed'}
    except BaseException:
        for f in installed:shutil.copy2(folder/f,ROOT/f)
        subprocess.run(['systemctl','restart','neptune-release-center.service'],capture_output=True,timeout=25)
        raise


def consume():
    if not os.path.lexists(REQUEST):return None
    fd=os.open(REQUEST,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        st=os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_nlink!=1 or st.st_uid!=pwd.getpwnam('neptunerelease').pw_uid or st.st_mode&0o077 or st.st_size>90:
            fail('Unsafe action marker')
        raw=os.read(fd,100)
    finally:os.close(fd);REQUEST.unlink(missing_ok=True)
    match=re.fullmatch(rb'(UPDATE-RC|MAINTENANCE|GITHUB-PR|GIT-SCAN|GIT-MIRROR|GIT-SYNC|CHECK-PROD|PROMOTE-PROD|ROLLBACK-PROD|CLEANUP-SCAN|CLEANUP-HOLD|CLEANUP-RESTORE|PKG-SCAN|PKG-PROMOTE|PKG-REMOVE-QA|PKG-REMOVE-PROD)-([a-f0-9]{32})\n',raw)
    if not match:fail('Unsupported action marker')
    return match.group(1).decode(),match.group(2).decode()


def run_action(kind,ident):
    if kind=='MAINTENANCE':
        import maintenance_ops
        return maintenance_ops.run(ident)
    if kind=='PKG-SCAN':return package_flow_ops.inventory()
    if kind=='PKG-PROMOTE':return package_flow_ops.promote(ident)
    if kind=='PKG-REMOVE-QA':return package_flow_ops.remove_qa(ident)
    if kind=='PKG-REMOVE-PROD':return package_flow_ops.remove_production(ident)
    if kind=='UPDATE-RC':return update_control(ident)
    if kind=='GITHUB-PR':return publish_pr(ident)
    if kind=='GIT-SCAN':return github_mirror_ops.scan(ident,gh_request,github_token(),REPO,BASE_BRANCH)
    if kind=='GIT-SYNC':
        import github_auto_sync
        return github_auto_sync.synchronize(ident,action='GIT-SYNC')
    if kind=='GIT-MIRROR':return github_mirror_ops.publish(ident,gh_request,github_token(),REPO,BASE_BRANCH)
    if kind in ('CHECK-PROD','PROMOTE-PROD'):
        token=github_token();github=load(GITHUB_STATUS)
        args=(ident,current_qa_approval,gh_request,token,github)
        if kind=='CHECK-PROD':return production_ops.preflight(*args)
        result=production_ops.deploy(*args)
        write_status(PROD_LAST,state='success',**result)
        return result
    if kind=='ROLLBACK-PROD':
        if not PROD_LAST.exists():fail('No successful production release receipt')
        result=production_ops.rollback(ident,load(PROD_LAST))
        write_status(PROD_LAST,state='rolled_back',**result)
        return result
    if kind=='CLEANUP-SCAN':return cleanup_ops.inventory()
    if kind=='CLEANUP-HOLD':return cleanup_ops.quarantine(ident)
    if kind=='CLEANUP-RESTORE':return cleanup_ops.restore(ident)
    fail('Unsupported fixed action')


def main():
    if os.geteuid()!=0:raise SystemExit('This worker is root-only')
    os.umask(0o077)
    with LOCK.open('a+') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        job=consume()
        if not job:return
        kind,ident=job
        status=(REPORTS/'maintenance-status.json' if kind=='MAINTENANCE' else MC_STATUS if kind=='UPDATE-RC' else GITHUB_STATUS if kind=='GITHUB-PR' else MIRROR_STATUS if kind in ('GIT-SCAN','GIT-MIRROR','GIT-SYNC') else
                PROD_STATUS if kind in ('CHECK-PROD','PROMOTE-PROD','ROLLBACK-PROD') else PACKAGE_STATUS if kind.startswith('PKG-') else CLEAN_STATUS)
        # A fixed operation has unique status args; never pass duplicate **keys.
        previous=load(PROD_STATUS) if status==PROD_STATUS and PROD_STATUS.exists() else {}
        write_status(status,state='running',phase='Validating '+kind,package_id=ident,action=kind,started_at=now())
        try:
            no_qa_running()
            with QA_LOCK.open('a+') as qlock:
                fcntl.flock(qlock,fcntl.LOCK_EX|fcntl.LOCK_NB)
                result=run_action(kind,ident)
            fields={'state':'success','phase':'Operation succeeded','package_id':ident,'action':kind,'finished_at':now()}
            fields.update(result)
            write_status(status,**fields)
        except Exception as ex:
            detail=str(ex)[:180]
            write_status(status,state='failed',phase=detail,package_id=ident,action=kind,finished_at=now(),
                         previous_success=previous.get('recovery') if previous.get('state')=='success' else None)
            print('Operation failed:',detail,file=sys.stderr)
            raise SystemExit(1)

if __name__=='__main__':main()
