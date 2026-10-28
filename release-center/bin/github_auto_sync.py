#!/usr/bin/env python3
"""Operator-authorized production source -> GitHub main synchronization.

Root-only systemd job. Shares the release worker lock, never deploys production,
never force-pushes, and records each GitHub commit. Runtime data stays excluded.
"""
from __future__ import annotations
import datetime as dt
import fcntl
import json
import os
from pathlib import Path
import tempfile
import uuid
import github_mirror_ops as mirror
import mission_control_ops as mc

MAX_BATCHES = 10
HISTORY = mc.REPORTS / 'github-auto-sync-history.json'

def now():return dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds')

def record_commit(commit,files):
    history=json.loads(HISTORY.read_text()) if HISTORY.exists() else []
    history.append({'finished_at':now(),'sha':commit,'files':files})
    fd,name=tempfile.mkstemp(prefix='.github-auto-history-',dir=mc.REPORTS)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(history[-200:],f);f.flush();os.fsync(f.fileno())
        os.chmod(name,0o644);os.replace(name,HISTORY)
    finally:
        if os.path.exists(name):os.unlink(name)

def sync_batch(plan,gh,token):
    entries=[]
    for change in plan['files']:
        path=change['path'];source=mirror.source_path(path)
        if change['action']=='delete':
            if source is not None:raise ValueError('Deleted source reappeared: '+path)
            entries.append({'path':path,'mode':change['mode'],'type':'blob','sha':None})
            continue
        if source is None:raise ValueError('Source disappeared: '+path)
        data=mirror.checked_bytes(path,source)
        if mirror.digest(data)!=change['sha256']:raise ValueError('Source changed: '+path)
        # Git tree inline content avoids hundreds of blob-write API calls.
        entries.append({'path':path,'mode':change['mode'],'type':'blob','content':data.decode('utf-8')})
    parent=gh(token,'GET','/git/commits/'+plan['base_sha'])
    tree=gh(token,'POST','/git/trees',{'base_tree':parent['tree']['sha'],'tree':entries})
    commit=gh(token,'POST','/git/commits',{
        'message':'Sync Neptune production source ('+str(len(entries))+' files)',
        'tree':tree['sha'],'parents':[plan['base_sha']]})
    current=mirror.gather(gh,token,mc.REPO,mc.BASE_BRANCH)
    if current!=plan:raise ValueError('Production or GitHub changed during sync; retry next run')
    # GitHub refuses a non-fast-forward update if another writer races this job.
    gh(token,'PATCH','/git/refs/heads/'+mc.BASE_BRANCH,{'sha':commit['sha'],'force':False})
    actual=gh(token,'GET','/git/ref/heads/'+mc.BASE_BRANCH)['object']['sha']
    if actual!=commit['sha']:raise ValueError('GitHub main changed after sync; check next scan')
    record_commit(commit['sha'],[{k:c[k] for k in ('path','action','sha256','bytes')} for c in plan['files']])
    return commit['sha']

def synchronize(ident=None, action='AUTO-SYNC'):
    """Caller holds the release and QA locks. Used by timer and manual button."""
    ident=ident or uuid.uuid4().hex;total=0;commits=[]
    try:
        mc.write_status(mc.MIRROR_STATUS,state='running',action=action,
                        phase='Synchronizing production source to GitHub main',started_at=now(),request_id=ident)
        token=mc.github_token()
        for _ in range(MAX_BATCHES):
            plan=mirror.gather(mc.gh_request,token,mc.REPO,mc.BASE_BRANCH)
            if not plan['files']:break
            commit=sync_batch(plan,mc.gh_request,token)
            total+=len(plan['files']);commits.append(commit)
            print(json.dumps({'sha':commit,'files':len(plan['files']),'remaining':plan['pending_count']}),flush=True)
        # Persist a fresh preview and report the actual remaining state.
        mirror.scan(ident,mc.gh_request,token,mc.REPO,mc.BASE_BRANCH)
        preview=json.loads(mirror.PREVIEW.read_text())
        remaining=len(preview['files'])+preview['pending_count']
        phase=(str(remaining)+' source changes remain; next timer run will continue' if remaining else
               'GitHub main matches eligible production source; automatic sync enabled')
        mc.write_status(mc.MIRROR_STATUS,state='success',action=action,phase=phase,
                        files=total,commits=commits,remaining=remaining,finished_at=now(),
                        request_id=ident,base_sha=preview['base_sha'])
        print(json.dumps({'state':'success','files':total,'remaining':remaining,'base_sha':preview['base_sha']}),flush=True)
        return {'phase':phase,'files':total,'commits':commits,'remaining':remaining,'base_sha':preview['base_sha']}
    except Exception as e:
        mc.write_status(mc.MIRROR_STATUS,state='failed',action=action,
                        phase=str(e),files=total,commits=commits,finished_at=now(),request_id=ident)
        raise

def run():
    with mc.LOCK.open('a') as lock, mc.QA_LOCK.open('a') as qa_lock:
        try:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            fcntl.flock(qa_lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            raise RuntimeError('Release worker busy; use Sync to main now when it finishes')
        if mc.REQUEST.exists() or mc.FLASH_REQ.exists():
            raise RuntimeError('Release operation queued; use Sync to main now when it finishes')
        mc.no_qa_running()
        return synchronize()

if __name__=='__main__':run()
