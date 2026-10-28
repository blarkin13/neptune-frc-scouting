"""Guarded production-source to GitHub draft-PR workflow.

Never deploys software. Only eligible source files may be submitted; runtime data and secrets stay private. Every PR requires a recently reviewed preview and unchanged
GitHub main and local bytes. No user-controlled source paths or git commands.
"""
from __future__ import annotations
import base64
import datetime as dt
import hashlib
import json
from pathlib import Path
import re

PROD = Path('/var/www/neptune')
SOURCE_MODE = 'production-v2'
PREVIEW = Path('/var/lib/neptune-release-center/reports/github-mirror-preview.json')
MAX_FILES = 70
MAX_TOTAL = 12 * 1024 * 1024
MAX_ONE = 2 * 1024 * 1024
# Source-only discovery. JSON/XML already tracked by GitHub may be updated, but
# newly created runtime data must never become source merely because of suffix.
CODE_EXTENSIONS = ('.php', '.js', '.css', '.html', '.md', '.py', '.sh', '.command')
DATA_EXTENSIONS = ('.json', '.xml', '.txt', '.webmanifest')
ROOT_FILES = {'README.md', 'INSTALL.md', 'install.sh'}
EXCLUDE = {
 'public_html/Neptune/admin/file-manager.php',
 'public_html/Neptune/admin/maintenance.php',
 'neptune_secure/config.php',
 'neptune_secure/google-auth.php',
 'neptune_secure/offline_sync.php',
}
RC_ROOT = Path('/opt/neptune-release-center')
RC_FILES = {
 'release-center/' + str(p) for p in (
  'bin/github_mirror_ops.py', 'bin/github_auto_sync.py', 'bin/release_prune.py', 'bin/neptune-prune-releases.sh', 'bin/mission_control_ops.py',
  'bin/mission_control_policy.py', 'bin/production_ops.py',
  'bin/cleanup_ops.py', 'bin/package_flow_ops.py', 'bin/maintenance_ops.py',
  'bin/qa_package_policy.py', 'bin/system_ops.py', 'bin/system_db.php',
  'bin/root_console.py', 'bin/compare.py', 'bin/qa-isolation-check.py',
  'web/server.py', 'web/app.js', 'web/app.css', 'web/index.html')
}
SOURCE_ROOTS = ('public_html/Neptune', 'neptune_secure', 'scripts', 'sql')

def eligible(path: str) -> bool:
    if (not isinstance(path, str) or not path or len(path) > 240
        or path.startswith('/') or '\\' in path
        or any(part in ('', '.', '..') for part in path.split('/'))):
        return False
    parts = path.split('/')
    if any(part.startswith('.') for part in parts):return False
    if any(re.search(r'(?:\.bak|\.before|\.backup|~$|\.pre-|\.old(?:$|[.-]))', part, re.I) for part in parts):
        return False
    if path in EXCLUDE or path.startswith((
        'public_html/Neptune/uploads/', 'public_html/Neptune/games/',
        'public_html/Neptune/assets/vendor/', 'neptune_secure/spot_media/')):
        return False
    if any(part.lower() in {'cache','logs','tmp','temp','sessions','backups','vendor','node_modules','__pycache__'} for part in parts):
        return False
    if path in RC_FILES or path in ROOT_FILES:return True
    if path.startswith('sql/'):
        return path.endswith('.sql') or path == 'sql/EXPECTED_TABLES.txt'
    if not path.startswith(('public_html/Neptune/', 'neptune_secure/', 'scripts/')):
        return False
    leaf = parts[-1].lower()
    if leaf.endswith(('.pem','.key','.env','.secret')) or leaf in {'credentials.json','service-account.json'}:
        return False
    return leaf.endswith(CODE_EXTENSIONS + DATA_EXTENSIONS)

def discoverable(path: str) -> bool:
    if not eligible(path):return False
    leaf=path.rsplit('/',1)[-1].lower()
    return (path in RC_FILES or path in ROOT_FILES or path.startswith('sql/')
            or leaf.endswith(CODE_EXTENSIONS)
            or leaf.startswith('requirements') and leaf.endswith('.txt'))

def location(path: str):
    if path in RC_FILES:
        return RC_ROOT, path[len('release-center/'):]
    return PROD, path

def source_path(path: str):
    if not eligible(path):return None
    root, relative = location(path)
    if root.is_symlink():raise ValueError('Source root is a symlink')
    candidate=root
    for part in relative.split('/'):
        candidate=candidate/part
        if candidate.is_symlink():raise ValueError('Source symlink refused: '+path)
    if not candidate.is_file():return None
    try:candidate.resolve(strict=True).relative_to(root.resolve(strict=True))
    except (ValueError,OSError):raise ValueError('Source escapes root: '+path)
    return candidate

def local_paths():
    result=set()
    for relative in SOURCE_ROOTS:
        root=PROD/relative
        if not root.is_dir() or root.is_symlink():
            raise ValueError('Required source directory unavailable: '+relative)
        # Never descend through symlink directories.
        import os
        for parent, dirs, files in os.walk(root, followlinks=False):
            dirs[:] = sorted(d for d in dirs if not (Path(parent)/d).is_symlink()
                             and not d.startswith('.') and d.lower() not in
                             {'cache','logs','tmp','temp','sessions','backups','vendor','node_modules','__pycache__'})
            for name in files:
                path=str((Path(parent)/name).relative_to(PROD))
                if discoverable(path) and source_path(path):result.add(path)
    for path in ROOT_FILES | RC_FILES:
        if source_path(path):result.add(path)
    return result

def deletion_allowed(path: str) -> bool:
    # GitHub-only installer files and schemas are intentionally preserved. A
    # missing deployment artifact is not proof that source should be deleted.
    return (eligible(path) and path.startswith(('public_html/Neptune/', 'neptune_secure/'))
            and path.lower().endswith(CODE_EXTENSIONS))

def checked_bytes(path: str, source: Path) -> bytes:
    if source.stat().st_size > MAX_ONE:raise ValueError('Source exceeds 2 MiB: '+path)
    data=source.read_bytes()
    if len(data)>MAX_ONE:raise ValueError('Source exceeds 2 MiB: '+path)
    # Fail closed on high-confidence credential formats; never print the value.
    patterns = (
        rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',
        rb'(?<![A-Za-z0-9])(?:ghp_|github_pat_)[A-Za-z0-9_]{20,}',
        rb'(?<![A-Z0-9])(?:AKIA|ASIA)[A-Z0-9]{16}(?![A-Z0-9])',
        rb'AIza[0-9A-Za-z_-]{35}',
    )
    if any(re.search(pattern,data) for pattern in patterns):
        raise ValueError('Possible embedded credential; review locally: '+path)
    return data


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def blob_sha(data: bytes) -> str:
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()

def gather(gh_request, token: str, repo: str, base: str):
    """Compare the union of approved local source and tracked GitHub paths."""
    ref=gh_request(token,'GET','/git/ref/heads/'+base)['object']['sha']
    commit=gh_request(token,'GET','/git/commits/'+ref)
    tree=gh_request(token,'GET','/git/trees/'+commit['tree']['sha']+'?recursive=1')
    if tree.get('truncated') or not isinstance(tree.get('tree'),list):
        raise ValueError('GitHub tree unavailable or truncated')
    tracked={r['path']:r for r in tree['tree'] if r.get('type')=='blob' and eligible(r.get('path',''))}
    discovered=local_paths()
    updates=[];total=0;pending_count=0;pending_bytes=0
    for path in sorted(set(tracked)|discovered):
        row=tracked.get(path)
        if row and row.get('mode') not in ('100644','100755'):
            raise ValueError('Unsupported GitHub source mode: '+path)
        source=source_path(path)
        if source is None:
            if not row or not deletion_allowed(path):continue
            change={'path':path,'action':'delete','sha256':None,'bytes':0,'mode':row['mode']}
        else:
            prod=checked_bytes(path,source)
            if row and blob_sha(prod)==row['sha']:continue
            mode=row['mode'] if row else ('100755' if path.endswith(('.sh','.command')) else '100644')
            change={'path':path,'action':'modify' if row else 'add',
                    'sha256':digest(prod),'bytes':len(prod),'mode':mode}
        if len(updates)>=MAX_FILES or total+change['bytes']>MAX_TOTAL:
            pending_count+=1;pending_bytes+=change['bytes'];continue
        updates.append(change);total+=change['bytes']
    return {'base_sha':ref,'files':updates,'total_bytes':total,
            'pending_count':pending_count,'pending_bytes':pending_bytes}


def scan(ident, gh_request, token, repo, base):
    plan=gather(gh_request,token,repo,base)
    record={'request_id':ident,'source_mode':SOURCE_MODE,
            'created_at':dt.datetime.now(dt.timezone.utc).isoformat(),
            'base_sha':plan['base_sha'], 'files':plan['files'],
            'total_bytes':plan['total_bytes'],
            'pending_count':plan['pending_count'],'pending_bytes':plan['pending_bytes']}
    # Root worker owns this directory and overwrites only a fixed path.
    import os, tempfile
    fd,tmp=tempfile.mkstemp(prefix='.github-mirror-',dir=PREVIEW.parent)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(record,f,separators=(',',':'));f.flush();os.fsync(f.fileno())
        os.chmod(tmp,0o644)
        os.replace(tmp,PREVIEW)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
    return {'files':len(record['files']), 'phase':
            ('Ready for explicit review: '+str(len(record['files']))+' verified file(s) in this batch; '+str(record['pending_count'])+' remaining for later batches' if record['files'] else
             'GitHub main matches eligible production source (runtime data and secrets excluded)'),
            'base_sha':record['base_sha']}

def publish(ident, gh_request, token, repo, base):
    preview=json.loads(PREVIEW.read_text())
    if preview.get('source_mode')!=SOURCE_MODE:
        raise ValueError('Run a new production-source scan before publishing')
    if ident!=preview.get('request_id') or not re.fullmatch(r'[a-f0-9]{32}',ident):
        raise ValueError('Preview ID changed; run scan again')
    stamp=dt.datetime.fromisoformat(preview['created_at'])
    if (dt.datetime.now(dt.timezone.utc)-stamp).total_seconds()>1800:
        raise ValueError('Preview expired after 30 minutes; scan again')
    current=gather(gh_request,token,repo,base)
    if (current['base_sha']!=preview['base_sha'] or current['files']!=preview['files']
        or current['pending_count']!=preview.get('pending_count',0)
        or current['pending_bytes']!=preview.get('pending_bytes',0)):
        raise ValueError('GitHub main or production bytes changed; rescan')
    changes=current['files']
    if not changes:raise ValueError('No production-to-GitHub changes exist')
    branch='neptune/verified-mirror-'+ident[:14]
    refs=gh_request(token,'GET','/git/matching-refs/heads/'+branch)
    if any(r.get('ref')=='refs/heads/'+branch for r in refs):
        raise ValueError('Existing mirror branch must be reviewed; no automatic overwrite')
    commit=gh_request(token,'GET','/git/commits/'+current['base_sha'])
    entries=[]
    for f in changes:
        if f['action']=='delete':
            if source_path(f['path']) is not None:raise ValueError('Deleted source reappeared: '+f['path'])
            entries.append({'path':f['path'],'mode':f['mode'],'type':'blob','sha':None})
            continue
        source=source_path(f['path'])
        if source is None:raise ValueError('Production source unavailable: '+f['path'])
        data=checked_bytes(f['path'],source)
        if digest(data)!=f['sha256']:
            raise ValueError('File changed since preview: '+f['path'])
        b=gh_request(token,'POST','/git/blobs',{'content':base64.b64encode(data).decode(),'encoding':'base64'})
        entries.append({'path':f['path'],'mode':f['mode'],'type':'blob','sha':b['sha']})
    new_tree=gh_request(token,'POST','/git/trees',{'base_tree':commit['tree']['sha'],'tree':entries})
    new_commit=gh_request(token,'POST','/git/commits',{'message':'Sync Neptune production code','tree':new_tree['sha'],'parents':[current['base_sha']]})
    gh_request(token,'POST','/git/refs',{'ref':'refs/heads/'+branch,'sha':new_commit['sha']})
    try:
        pr=gh_request(token,'POST','/pulls',{
          'title':'Sync Neptune production changes',
          'head':branch,'base':base,'draft':True,
          'body':('Changes from live production, verified against the reviewed production scan. QA was not used or claimed as validation.\n\n'
                  'This draft PR does not count as a signed, QA-tested ZIP release and '
                  'cannot be used to bypass production deployment gates.\n\n'
                  'Review every changed file before merging.\n\n'
                  'Batch size: '+str(len(changes))+' files. Remaining after this batch: '
                  +str(current['pending_count'])+' files. Merge, then rescan for the next batch.\n')})
    except Exception:
        raise ValueError('Branch created, but PR creation failed. Inspect '+branch+' manually; do not retry blindly')
    url=str(pr.get('html_url',''))
    if not re.fullmatch(r'https://github\.com/blarkin13/neptune-frc-scouting/pull/[0-9]+',url):
        raise ValueError('GitHub returned unexpected PR URL; inspect '+branch)
    return {'url':url,'branch':branch,'pr_number':pr['number'],'files':len(changes),
            'base_sha':current['base_sha'],'pending_count':current['pending_count'],
            'phase':'Draft PR created from reviewed production code; '+str(current['pending_count'])+' file(s) remain for later batches. Merge then rescan.'}

