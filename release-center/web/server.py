#!/usr/bin/env python3
"""Private, unprivileged Release Center: read-only reports and inert ZIP staging."""
from __future__ import annotations
import base64
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import io
import datetime as dt
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import stat
import threading
import sys
import time
import urllib.parse
import uuid
import zipfile
sys.path.insert(0,'/opt/neptune-release-center/bin')
from qa_package_policy import inspect as deployment_inspect
from mission_control_policy import inspect as mission_control_inspect

BASE = Path('/var/lib/neptune-release-center')
REPORT = BASE / 'reports/latest.json'
STAGING = BASE / 'staging'
FLASH_REQUEST = STAGING / 'flash-qa.request'
FLASH_STATUS = BASE / 'reports/flash-qa-status.json'
WEB = Path('/opt/neptune-release-center/web')
AUTH = Path('/etc/neptune-release-center/basic-auth.json')
HOST = '127.0.0.1'
PORT = 18081
MAX_UPLOAD = 50 * 1024 * 1024
MAX_EXPANDED = 200 * 1024 * 1024
MAX_FILES = 1500
MAX_FILE = 30 * 1024 * 1024
CSRF_TOKEN = secrets.token_urlsafe(32)
USER = 'releaseadmin'
ALLOWED_HOSTS = {'127.0.0.1:18081', 'localhost:18081', 'rc.neptunefrc.com'}
UPLOAD_PATH_PATTERN = re.compile(r'^[a-zA-Z0-9 _+.()-]{1,120}\.zip$', re.I)
ID_PATTERN = re.compile(r'^[a-f0-9]{32}$')
DEPLOY_STATUS = BASE / 'reports/deploy-qa-status.json'
DEPLOY_MARKER_PREFIX = 'DEPLOY-QA-'
TELEMETRY_FILE = STAGING / 'server-telemetry.ndjson'
QA_TEST_APPROVAL = STAGING / 'qa-test-approval.state'
MC_REQUEST = STAGING / 'mission-control.request'
MC_STATUS = BASE / 'reports/mission-control-status.json'
GITHUB_STATUS = BASE / 'reports/github-publish-status.json'
GITHUB_TOKEN = Path('/etc/neptune-release-center/github-token')
PRODUCTION_STATUS = BASE / 'reports/production-status.json'
PRODUCTION_LAST = BASE / 'reports/production-last-success.json'
CLEANUP_STATUS = BASE / 'reports/cleanup-status.json'
GITHUB_MIRROR_STATUS = BASE / 'reports/github-mirror-status.json'
GITHUB_MIRROR_PREVIEW = BASE / 'reports/github-mirror-preview.json'
CLEANUP_INVENTORY = BASE / 'reports/cleanup-inventory.json'
CLEANUP_QUARANTINE = BASE / 'reports/cleanup-quarantine-list.json'
PACKAGE_FLOW_STATUS = BASE / 'reports/package-flow-status.json'
PACKAGE_FLOW_INVENTORY = BASE / 'reports/package-flow-inventory.json'
ROOT_ARCHIVE_PATTERN = re.compile(r'^(?:Neptune_|neptune-)[A-Za-z0-9_.-]{1,160}\.(?:zip|tar\.gz|tgz)$')

METRICS_LOCK = threading.Lock()
METRIC_PERIOD_SECONDS = 300




def read_json(path: Path):
    with path.open('r', encoding='utf-8') as f: return json.load(f)


def reviewed_package_plan(ident: str, meta: dict):
    """Verify stored upload fingerprint and recalculate strict deployment mappings."""
    if meta.get('policy_version')!=2 or meta.get('id')!=ident:
        raise ValueError('Legacy ZIP must be re-uploaded for deployment review')
    archive=STAGING/(ident+'.zip')
    size=archive.stat().st_size
    if size<1 or size>MAX_UPLOAD:
        raise ValueError('Staged ZIP size is outside upload limit')
    digest=hashlib.sha256()
    with archive.open('rb') as inp:
        for chunk in iter(lambda: inp.read(1024*1024), b''):digest.update(chunk)
    if not hmac.compare_digest(digest.hexdigest(),meta.get('sha256','')):
        raise ValueError('Staged ZIP fingerprint changed; re-upload')
    plan=deployment_inspect(archive)
    if meta.get('eligible_count')!=len(plan):
        raise ValueError('Deployment mapping no longer matches staged manifest')
    return plan


def inspect_zip(path: Path, report: dict):
    prod=report.get('prod_manifest',{})
    qa=report.get('qa_manifest',{})
    items=[]
    seen=set()
    expanded=0
    with zipfile.ZipFile(path) as archive:
        infos=archive.infolist()
        if len(infos)>MAX_FILES:
            raise ValueError('ZIP has too many entries')
        for z in infos:
            raw=z.filename.replace('\\','/')
            if ('\x00' in raw or raw.startswith('/') or re.match(r'^[a-zA-Z]:',raw)
                or '//' in raw or any(p in ('..','') for p in raw.strip('/').split('/'))):
                raise ValueError('ZIP contains an unsafe file path')
            ftype=(z.external_attr >> 16) & 0o170000
            if ftype not in (0,stat.S_IFREG,stat.S_IFDIR):
                raise ValueError('ZIP contains a symlink or special file')
            if z.is_dir():continue
            leaf=PurePosixPath(raw).name.lower()
            if (leaf in ('config.php', '.env', '.my.cnf', 'credentials.json')
                or leaf.endswith(('.env', '.key', '.pem', '.p12', '.pfx'))):
                raise ValueError('ZIP contains a secret or credential file; remove it before staging')
            if z.flag_bits & 0x1:
                raise ValueError('Password-protected ZIPs are unsupported')
            if z.file_size > MAX_FILE:
                raise ValueError('ZIP contains a file larger than 30 MB')
            expanded += z.file_size
            if expanded > MAX_EXPANDED:
                raise ValueError('Uncompressed ZIP size exceeds 200 MB')
            # Archive may contain one wrapper folder (not itself a deployment path).
            mapped=raw.strip('/')
            parts=mapped.split('/')
            if parts[0] not in ('public_html','neptune_secure','admin','analytics','scout','dashboard',
                                'api','assets','auth','pit','prescout','games','images','sql','scripts',
                                'index.php','register.php','scouting.php','health.php') and len(parts)>1:
                mapped='/'.join(parts[1:])
            if mapped.startswith('public_html/Neptune/') or mapped.startswith('neptune_secure/'):
                key=mapped
            elif mapped.startswith(('admin/','analytics/','scout/','dashboard/','api/','assets/','auth/',
                                    'pit/','prescout/','games/','images/')) or mapped in (
                                        'index.php','register.php','scouting.php','health.php'):
                key='public_html/Neptune/'+mapped
            elif mapped.startswith(('sql/','scripts/')):
                key=mapped
            else:
                key='unmapped:'+mapped
            if key in seen:raise ValueError('ZIP contains duplicate normalized paths')
            seen.add(key)
            with archive.open(z) as f:
                sha=hashlib.sha256()
                for chunk in iter(lambda: f.read(1024*1024), b''):
                    sha.update(chunk)
            digest=sha.hexdigest()
            if key.startswith('unmapped:') or key.startswith(('sql/','scripts/')):
                verdict='needs_mapping'
            elif key not in prod and key not in qa:
                verdict='new_file'
            elif prod.get(key)==digest and qa.get(key)==digest:
                verdict='same_both'
            elif prod.get(key)==digest:
                verdict='same_production'
            elif qa.get(key)==digest:
                verdict='same_qa'
            else:
                verdict='different_both'
            items.append({'archive_path':raw,'target_path':key,'size':z.file_size,
                          'sha256':digest,'comparison':verdict})
    if not items:raise ValueError('ZIP contains no files')
    return {'files':items, 'file_count':len(items),'expanded_bytes':expanded}


def safe_service_state_file(path: Path):
    """Return bytes from a service-owned, single-link, non-symlink state file."""
    try:
        fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_CLOEXEC)
    except FileNotFoundError:
        return None
    try:
        info=os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or
            info.st_uid!=os.geteuid() or info.st_mode & 0o022 or
            info.st_size>250_000):
            raise ValueError('Unsafe telemetry or approval state file')
        with os.fdopen(fd,'rb') as f:
            fd=-1
            return f.read(250_001)
    finally:
        if fd>=0:os.close(fd)


def atomic_service_state_write(path: Path, payload: bytes):
    if len(payload)>250_000:
        raise ValueError('State exceeds safe limit')
    existing=safe_service_state_file(path)
    # A safe existing file may be replaced, but a linked/foreign one never may.
    del existing
    temp=path.with_name('.'+path.name+'.'+uuid.uuid4().hex+'.tmp')
    fd=os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|os.O_CLOEXEC,0o600)
    try:
        with os.fdopen(fd,'wb') as f:
            fd=-1
            f.write(payload)
            f.flush()
            os.fsync(f.fileno())
        os.replace(temp,path)
        dirfd=os.open(STAGING,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(dirfd)
        finally:os.close(dirfd)
    finally:
        if fd>=0:os.close(fd)
        temp.unlink(missing_ok=True)


def read_current_telemetry():
    fs=os.statvfs('/')
    total=fs.f_blocks*fs.f_frsize
    used=(fs.f_blocks-fs.f_bfree)*fs.f_frsize
    available=fs.f_bavail*fs.f_frsize
    meminfo={}
    for line in Path('/proc/meminfo').read_text().splitlines():
        key,_,value=line.partition(':')
        if key in ('MemTotal','MemAvailable'):
            meminfo[key]=int(value.strip().split()[0])*1024
    memtotal=meminfo['MemTotal'];memused=memtotal-meminfo['MemAvailable']
    uptime=float(Path('/proc/uptime').read_text().split()[0])
    load=os.getloadavg()
    return {'sampled_at':dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds').replace('+00:00','Z'),
            'disk':{'total_bytes':total,'used_bytes':used,'available_bytes':available,
                    'used_percent':round(100*used/total,2)},
            'memory':{'total_bytes':memtotal,'used_bytes':memused,
                      'available_bytes':meminfo['MemAvailable'],'used_percent':round(100*memused/memtotal,2)},
            'load_1m':round(load[0],2),'load_5m':round(load[1],2),'load_15m':round(load[2],2),
            'uptime_seconds':int(uptime)}


def telemetry_payload():
    current=read_current_telemetry()
    with METRICS_LOCK:
        raw=safe_service_state_file(TELEMETRY_FILE)
        if raw:
            try:
                history=[json.loads(line) for line in raw.splitlines() if line]
                if not isinstance(history,list) or len(history)>144:
                    history=[]
            except (ValueError,TypeError):
                history=[]
        else:history=[]
        now=time.time()
        # First observation is persisted immediately; later points at 5-minute
        # intervals. No fictitious history is generated.
        last_at=history[-1].get('unix',0) if history else 0
        if not isinstance(last_at,(int,float)):last_at=0
        if now-last_at>=METRIC_PERIOD_SECONDS:
            history.append({'unix':int(now),'at':current['sampled_at'],
                            'disk_used_percent':current['disk']['used_percent'],
                            'memory_used_percent':current['memory']['used_percent']})
            history=[h for h in history[-144:] if isinstance(h,dict)]
            atomic_service_state_write(TELEMETRY_FILE,b'\n'.join(json.dumps(h,separators=(',',':')).encode() for h in history)+b'\n')
    current['samples']=[{'at':x['at'],'disk_used_percent':x['disk_used_percent'],
                         'memory_used_percent':x['memory_used_percent']} for x in history
                        if all(k in x for k in ('at','disk_used_percent','memory_used_percent'))]
    current['history_cadence_seconds']=METRIC_PERIOD_SECONDS
    return current


def valid_qa_install():
    """Only latest successful ZIP deployment without a subsequent Flash QA counts."""
    if FLASH_REQUEST.exists():return None
    try:
        deploy=read_json(DEPLOY_STATUS)
        flash=read_json(FLASH_STATUS) if FLASH_STATUS.is_file() else {}
    except (OSError,TypeError,ValueError):return None
    ident=deploy.get('package_id')
    finished=deploy.get('finished_at')
    if (deploy.get('state')!='success' or not isinstance(ident,str)
        or not ID_PATTERN.fullmatch(ident) or not isinstance(finished,str)
        or flash.get('state') in ('running','queued')):
        return None
    # ISO UTC values are lexicographically sortable in this fixed timestamp format.
    flash_start=flash.get('started_at')
    if isinstance(flash_start,str) and flash_start>=finished:return None
    try:
        meta=read_json(STAGING/(ident+'.json'))
        if meta.get('id')!=ident or meta.get('policy_version')!=2 or not re.fullmatch(r'[a-f0-9]{64}',meta.get('sha256','')):
            return None
    except (OSError,ValueError,TypeError):return None
    return {'id':ident,'sha256':meta['sha256'],'finished_at':finished}


def release_state():
    deploy=valid_qa_install()
    out={'qa_installed':bool(deploy),'qa_package_id':deploy['id'] if deploy else None,
         'manual_qa_tested':False,'github_published':False,'production_promoted':False}
    if not deploy:return out
    try:
        raw=safe_service_state_file(QA_TEST_APPROVAL)
        record=json.loads(raw) if raw else {}
        out['manual_qa_tested']=all(record.get(k)==v for k,v in (
            ('package_id',deploy['id']),('sha256',deploy['sha256']),('deploy_finished_at',deploy['finished_at'])))
        if out['manual_qa_tested']:out['manually_tested_at']=record.get('approved_at')
    except (OSError,ValueError,TypeError):pass
    try:
        github=read_json(GITHUB_STATUS)
        if (out['manual_qa_tested'] and github.get('state')=='success' and
            github.get('package_id')==deploy['id'] and github.get('sha256')==deploy['sha256']):
            out['github_published']=True
            out['github_pr_merged']=github.get('merged') is True
            out['github_pr_url']=github.get('url')
    except (OSError,ValueError,TypeError):pass
    try:
        receipt=read_json(PRODUCTION_LAST)
        if receipt.get('state')=='success' and receipt.get('package_id')==deploy['id']:
            out['production_promoted']=True
            out['production_verified_at']=receipt.get('verified_at')
    except (OSError,ValueError,TypeError):pass
    return out


def approve_qa_test(ident):
    deploy=valid_qa_install()
    if not deploy or deploy['id']!=ident:raise ValueError('Only the latest successfully installed QA package can be marked tested')
    # Preserve staged ZIP integrity and policy at the point of manual acknowledgment.
    meta=read_json(STAGING/(ident+'.json'))
    reviewed_package_plan(ident,meta)
    record={'package_id':ident,'sha256':deploy['sha256'],
            'deploy_finished_at':deploy['finished_at'],
            'approved_at':dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds').replace('+00:00','Z'),
            'kind':'manual_user_test_acknowledgment_not_automated_verification'}
    atomic_service_state_write(QA_TEST_APPROVAL,json.dumps(record,separators=(',',':')).encode())
    return release_state()


class Handler(BaseHTTPRequestHandler):
    server_version='NeptuneReleaseCenter/0.1'
    sys_version=''

    def log_message(self, fmt,*args):
        # Do not log basic-auth credentials or file contents.
        print('%s [%s] %s' % (self.client_address[0],time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),fmt%args),flush=True)

    def reply(self, code:int, obj, *,headers=None):
        payload=json.dumps(obj,ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header('Content-Type','application/json; charset=utf-8')
        self.send_header('Content-Length',str(len(payload)))
        self.secure_headers()
        for k,v in (headers or {}).items():self.send_header(k,v)
        try:
            self.end_headers()
            self.wfile.write(payload)
        except (BrokenPipeError,ConnectionResetError):
            self.close_connection=True

    def secure_headers(self):
        self.send_header('Cache-Control','no-store')
        self.send_header('X-Content-Type-Options','nosniff')
        self.send_header('X-Frame-Options','DENY')
        self.send_header('Referrer-Policy','no-referrer')
        self.send_header('Content-Security-Policy',"default-src 'none'; script-src 'self'; style-src 'self'; font-src 'self'; connect-src 'self'; img-src 'self' data: blob:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'")

    def allowed(self):
        if self.headers.get('Host','').lower() not in ALLOWED_HOSTS:
            self.reply(421,{'error':'Only localhost is allowed'})
            return False
        v=self.headers.get('Authorization','')
        if not v.startswith('Basic '):
            self.auth_required();return False
        try:
            raw=base64.b64decode(v.split(' ',1)[1],validate=True).decode('utf-8')
            user,password=raw.split(':',1)
            settings=read_json(AUTH)
            salt=bytes.fromhex(settings['salt'])
            expected=bytes.fromhex(settings['hash'])
            actual=hashlib.pbkdf2_hmac('sha256',password.encode(),salt,settings['iterations'])
            if user!=USER or not hmac.compare_digest(actual,expected):
                self.auth_required();return False
        except (ValueError,KeyError,OSError,UnicodeDecodeError,TypeError):
            self.auth_required();return False
        return True

    def auth_required(self):
        self.reply(401,{'error':'Authentication required'},headers={
            'WWW-Authenticate':'Basic realm="Neptune Release Center"'})

    def do_GET(self):
        if not self.allowed():return
        path=urllib.parse.urlsplit(self.path).path
        try:
            if path=='/':
                doc=(WEB/'index.html').read_text(encoding='utf-8').replace('@@CSRF@@',CSRF_TOKEN)
                body=doc.encode()
                self.send_response(200)
                self.send_header('Content-Type','text/html; charset=utf-8')
                self.send_header('Content-Length',str(len(body)))
                self.secure_headers();self.end_headers();self.wfile.write(body)
            elif path in ('/app.js','/app.css'):
                file=WEB/path.lstrip('/')
                body=file.read_bytes()
                self.send_response(200)
                self.send_header('Content-Type','text/javascript; charset=utf-8' if path.endswith('.js') else 'text/css; charset=utf-8')
                self.send_header('Content-Length',str(len(body)))
                self.secure_headers();self.end_headers();self.wfile.write(body)
            elif path=='/api/package-flow/inventory':
                data=read_json(PACKAGE_FLOW_INVENTORY) if PACKAGE_FLOW_INVENTORY.is_file() else {'updated_at':None,'qa_only':[],'production_recent':[],'needs_refresh':True}
                status=read_json(PACKAGE_FLOW_STATUS) if PACKAGE_FLOW_STATUS.is_file() else {'state':'idle'}
                self.reply(200,{**data,'operation':{k:status.get(k) for k in ('state','phase','action','package_id','finished_at')}})
            elif path=='/api/telemetry':
                self.reply(200,telemetry_payload())
            elif path=='/api/mission-control/status':
                if MC_REQUEST.exists():self.reply(200,{'state':'queued','phase':'Neptune Release Control operation pending'})
                elif MC_STATUS.is_file():
                    d=read_json(MC_STATUS)
                    self.reply(200,{k:d.get(k) for k in ('state','phase','package_id','finished_at','version','files')})
                else:self.reply(200,{'state':'idle','phase':'Upload a Neptune Release Control update ZIP'})
            elif path=='/api/mission-control/updates':
                items=[]
                for x in sorted(STAGING.glob('mc-*.json'),key=lambda q:q.stat().st_mtime,reverse=True):
                    try:
                        d=read_json(x)
                        if ID_PATTERN.fullmatch(d.get('id','')):
                            items.append({k:d.get(k) for k in ('id','name','sha256','uploaded_at','plan')})
                    except (ValueError,OSError,TypeError):continue
                self.reply(200,{'updates':items[:25]})
            elif path=='/api/github-mirror/status':
                report=read_json(GITHUB_MIRROR_STATUS) if GITHUB_MIRROR_STATUS.is_file() else {'state':'idle','phase':'Scan current production changes to prepare a draft PR'}
                preview=read_json(GITHUB_MIRROR_PREVIEW) if GITHUB_MIRROR_PREVIEW.is_file() else {}
                self.reply(200,{'status':{k:report.get(k) for k in ('state','phase','action','request_id','package_id','url','branch','pr_number','files','finished_at','commits','remaining','base_sha')},
                                'preview':{k:preview.get(k) for k in ('request_id','created_at','base_sha','files','total_bytes','source_mode','pending_count','pending_bytes')}})
            elif path=='/api/github/status':
                current=read_json(GITHUB_STATUS) if GITHUB_STATUS.is_file() else {'state':'idle'}
                self.reply(200,{'configured':GITHUB_TOKEN.is_file(),
                                **{k:current.get(k) for k in ('state','phase','package_id','url','branch','finished_at')}})
            elif path=='/api/production/status':
                current=read_json(PRODUCTION_STATUS) if PRODUCTION_STATUS.is_file() else {'state':'idle','phase':'Run a production preflight after merging an approved GitHub PR'}
                last=read_json(PRODUCTION_LAST) if PRODUCTION_LAST.is_file() else {}
                # Minimal status only. No local root backup paths exposed to browser.
                fields=('state','phase','action','package_id','files','pr_number','finished_at','checked_at','verified_at','can_deploy','rolled_back_at')
                out={k:current.get(k) for k in fields}
                out['last_success']={k:last.get(k) for k in ('state','package_id','pr_number','files','verified_at','rolled_back_at')}
                self.reply(200,out)
            elif path=='/api/cleanup/status':
                current=read_json(CLEANUP_STATUS) if CLEANUP_STATUS.is_file() else {'state':'idle','phase':'Root inventory not run yet'}
                self.reply(200,{k:current.get(k) for k in ('state','phase','action','finished_at','eligible_count','eligible_bytes','candidate_count','request_id','count','bytes')})
            elif path=='/api/cleanup/inventory':
                inventory=read_json(CLEANUP_INVENTORY) if CLEANUP_INVENTORY.is_file() else {'items':[],'total_archives':0,'eligible_count':0,'scanned_at':None}
                quarantine=read_json(CLEANUP_QUARANTINE) if CLEANUP_QUARANTINE.is_file() else {'items':[]}
                self.reply(200,{'inventory':inventory,'quarantine':quarantine.get('items',[])})
            elif path=='/api/release-state':
                self.reply(200,release_state())
            elif path=='/api/summary':
                if REPORT.is_file():
                    x=read_json(REPORT)
                    x.pop('prod_manifest',None);x.pop('qa_manifest',None)
                    self.reply(200,x)
                else:self.reply(503,{'error':'No comparison report yet. Run the comparison command.'})
            elif path=='/api/flash-qa/status':
                if FLASH_REQUEST.exists():
                    self.reply(200,{'state':'queued','phase':'Waiting for the privileged QA-only worker'})
                elif FLASH_STATUS.is_file():
                    x=read_json(FLASH_STATUS)
                    self.reply(200,{k:x.get(k) for k in ('state','phase','started_at','finished_at','error_code')})
                else:
                    self.reply(200,{'state':'idle','phase':'Ready to refresh QA (app-only recommended)'})
            elif path=='/api/deploy-qa/status':
                if FLASH_REQUEST.exists():
                    self.reply(200,{'state':'queued','phase':'A QA operation is queued'})
                elif DEPLOY_STATUS.is_file():
                    x=read_json(DEPLOY_STATUS)
                    self.reply(200,{k:x.get(k) for k in ('state','phase','started_at','finished_at','error_code','package_id')})
                else:self.reply(200,{'state':'idle','phase':'Choose a staged, eligible ZIP to deploy to QA'})
            elif path=='/api/packages':
                rows=[]
                for p in sorted(STAGING.glob('*.json'), key=lambda f:f.stat().st_mtime,reverse=True):
                    if not ID_PATTERN.fullmatch(p.stem):continue
                    try:
                        d=read_json(p)
                        rows.append({k:d.get(k) for k in ('id','name','uploaded_at','file_count','expanded_bytes','sha256')})
                    except (OSError,ValueError):continue
                self.reply(200,{'packages':rows[:100]})
            elif path.startswith('/api/packages/'):
                item=path.rsplit('/',1)[-1]
                if not ID_PATTERN.fullmatch(item):return self.reply(404,{'error':'Not found'})
                p=STAGING/(item+'.json')
                if not p.is_file():return self.reply(404,{'error':'Not found'})
                data=read_json(p)
                # Annotate eligibility from actual ZIP bytes; never rely on legacy mapping.
                if data.get('policy_version')==2:
                    try:
                        plan=reviewed_package_plan(item,data)
                        data['deployable']=True
                        data['deploy_files']=plan
                    except (ValueError,OSError,zipfile.BadZipFile) as e:
                        data['deployable']=False
                        data['deploy_error']=str(e)[:180]
                else:
                    data['deployable']=False
                    data['deploy_error']='Legacy upload: re-upload ZIP to review under QA deployment policy'
                self.reply(200,data)
            else:self.reply(404,{'error':'Not found'})
        except (OSError,ValueError,TypeError) as err:
            self.reply(500,{'error':'Unable to load resource'})
            print('Error:',str(err),flush=True)

    def do_POST(self):
        if not self.allowed():return
        path=urllib.parse.urlsplit(self.path).path
        if path not in ('/api/upload','/api/flash-qa/request','/api/deploy-qa/request','/api/qa-test/approve',
                        '/api/mission-control/upload','/api/mission-control/request','/api/github/publish',
                        '/api/github-mirror/scan','/api/github-mirror/publish','/api/github-mirror/sync',
                        '/api/production/preflight','/api/production/install','/api/production/rollback',
                        '/api/cleanup/scan','/api/cleanup/hold','/api/cleanup/restore',
                        '/api/package-flow/request'):
            return self.reply(404,{'error':'Not found'})
        origin=self.headers.get('Origin','')
        host=self.headers.get('Host','').lower()
        if ((host=='rc.neptunefrc.com' and origin!='https://rc.neptunefrc.com') or
            (origin and origin not in ('http://localhost:18081','http://127.0.0.1:18081','https://rc.neptunefrc.com'))):
            return self.reply(403,{'error':'Origin rejected'})
        if not hmac.compare_digest(self.headers.get('X-CSRF',''),CSRF_TOKEN):
            return self.reply(403,{'error':'CSRF validation failed'})
        if path=='/api/package-flow/request':
            if self.headers.get('Content-Type')!='application/json':return self.reply(415,{'error':'JSON required'})
            try:
                n=int(self.headers.get('Content-Length','0'))
                if not 1<=n<=200:return self.reply(400,{'error':'Invalid request length'})
                request=json.loads(self.rfile.read(n))
                if not isinstance(request,dict):return self.reply(400,{'error':'Invalid request'})
            except (ValueError,UnicodeDecodeError):return self.reply(400,{'error':'Invalid JSON'})
            actions={'refresh':'PKG-SCAN','promote':'PKG-PROMOTE','remove_qa':'PKG-REMOVE-QA','remove_prod':'PKG-REMOVE-PROD'}
            action=request.get('action');code=actions.get(action)
            if code is None:return self.reply(400,{'error':'Unknown package action'})
            if action=='refresh':
                if set(request)!={'action'}:return self.reply(400,{'error':'Invalid refresh request'})
                ident=uuid.uuid4().hex
            else:
                ident=request.get('package_id')
                if set(request)!={'action','package_id'} or not isinstance(ident,str) or not ID_PATTERN.fullmatch(ident):
                    return self.reply(400,{'error':'Choose one valid package'})
            if MC_REQUEST.exists() or FLASH_REQUEST.exists():return self.reply(409,{'error':'Another release operation is queued'})
            for status_file in (FLASH_STATUS,DEPLOY_STATUS,MC_STATUS,GITHUB_STATUS,GITHUB_MIRROR_STATUS,PRODUCTION_STATUS,CLEANUP_STATUS,PACKAGE_FLOW_STATUS):
                if status_file.exists() and read_json(status_file).get('state') in ('queued','running'):
                    return self.reply(409,{'error':'Another release operation is running'})
            try:
                marker=(code+'-'+ident+'\n').encode('ascii')
                fd=os.open(MC_REQUEST,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
                with os.fdopen(fd,'wb') as f:f.write(marker);f.flush();os.fsync(f.fileno())
                return self.reply(202,{'state':'queued','message':action.replace('_',' ').title()+' queued'})
            except FileExistsError:return self.reply(409,{'error':'Operation already queued'})
            except OSError:return self.reply(503,{'error':'Unable to queue operation'})
        if path in ('/api/github-mirror/scan','/api/github-mirror/publish','/api/github-mirror/sync'):
            if self.headers.get('Content-Type')!='application/json':return self.reply(415,{'error':'JSON required'})
            try:
                length=int(self.headers.get('Content-Length','0'))
                if not 1<=length<=128:return self.reply(400,{'error':'Invalid request size'})
                body=json.loads(self.rfile.read(length))
                if not isinstance(body,dict):return self.reply(400,{'error':'Invalid request'})
            except (ValueError,UnicodeDecodeError):return self.reply(400,{'error':'Malformed JSON'})
            if MC_REQUEST.exists() or FLASH_REQUEST.exists():return self.reply(409,{'error':'Another operation is queued'})
            for report in (FLASH_STATUS,DEPLOY_STATUS,MC_STATUS,GITHUB_STATUS,GITHUB_MIRROR_STATUS,PRODUCTION_STATUS,CLEANUP_STATUS):
                if report.is_file() and read_json(report).get('state') in ('running','queued'):
                    return self.reply(409,{'error':'Another operation is running'})
            if path.endswith(('/scan','/sync')):
                if body!={}:return self.reply(400,{'error':'This action does not take arbitrary parameters'})
                ident=uuid.uuid4().hex
                op='GIT-SYNC' if path.endswith('/sync') else 'GIT-SCAN'
            else:
                ident=body.get('preview_id')
                preview=read_json(GITHUB_MIRROR_PREVIEW) if GITHUB_MIRROR_PREVIEW.is_file() else {}
                if (not isinstance(ident,str) or not ID_PATTERN.fullmatch(ident) or body!={'preview_id':ident}
                    or ident!=preview.get('request_id') or not preview.get('files')):
                    return self.reply(409,{'error':'Run and review a nonempty GitHub mirror scan first'})
                op='GIT-MIRROR'
            try:
                fd=os.open(MC_REQUEST,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
                with os.fdopen(fd,'wb') as out:
                    out.write((op+'-'+ident+'\n').encode('ascii'));out.flush();os.fsync(out.fileno())
                return self.reply(202,{'state':'queued','message':'Read-only scan queued' if op=='GIT-SCAN' else 'Sync to GitHub main queued' if op=='GIT-SYNC' else 'Reviewed draft PR creation queued'})
            except FileExistsError:return self.reply(409,{'error':'Release worker already queued'})
            except OSError:return self.reply(503,{'error':'Unable to queue GitHub operation'})
        if path in ('/api/production/preflight','/api/production/install','/api/production/rollback',
                    '/api/cleanup/scan','/api/cleanup/hold','/api/cleanup/restore'):
            if self.headers.get('Content-Type')!='application/json':return self.reply(415,{'error':'JSON required'})
            try:limit=int(self.headers.get('Content-Length','0'))
            except ValueError:limit=0
            if not 1<=limit<=4096:return self.reply(400,{'error':'Invalid request size'})
            try:body=json.loads(self.rfile.read(limit))
            except (ValueError,UnicodeDecodeError):return self.reply(400,{'error':'Malformed JSON'})
            # Prevent overlapping operations across ALL fixed-action workers.
            if MC_REQUEST.exists() or FLASH_REQUEST.exists():return self.reply(409,{'error':'Another release operation is queued'})
            for status_file in (FLASH_STATUS,DEPLOY_STATUS,MC_STATUS,GITHUB_STATUS,GITHUB_MIRROR_STATUS,PRODUCTION_STATUS,CLEANUP_STATUS):
                if status_file.exists() and read_json(status_file).get('state') in ('queued','running'):
                    return self.reply(409,{'error':'A release operation is still running'})
            try:
                if path.startswith('/api/production/'):
                    ident=body.get('package_id') if isinstance(body,dict) else None
                    if not isinstance(ident,str) or not ID_PATTERN.fullmatch(ident) or body!={'package_id':ident}:
                        return self.reply(400,{'error':'Choose one valid package'})
                    if path=='/api/production/preflight':
                        if valid_qa_install() is None or release_state().get('qa_package_id')!=ident or not release_state().get('manual_qa_tested'):
                            return self.reply(409,{'error':'Latest QA ZIP must be installed and manually tested'})
                        code='CHECK-PROD'
                    elif path=='/api/production/install':
                        ps=read_json(PRODUCTION_STATUS) if PRODUCTION_STATUS.is_file() else {}
                        if ps.get('action')!='CHECK-PROD' or ps.get('state')!='success' or ps.get('package_id')!=ident or not ps.get('can_deploy'):
                            return self.reply(409,{'error':'Successful production preflight required before installation'})
                        code='PROMOTE-PROD'
                    else:
                        last=read_json(PRODUCTION_LAST) if PRODUCTION_LAST.is_file() else {}
                        if last.get('state')!='success' or last.get('package_id')!=ident:
                            return self.reply(409,{'error':'Only last successful production release may be rolled back'})
                        code='ROLLBACK-PROD'
                elif path=='/api/cleanup/scan':
                    if body!={}:return self.reply(400,{'error':'Scan accepts no user-defined filesystem path'})
                    ident=uuid.uuid4().hex;code='CLEANUP-SCAN'
                elif path=='/api/cleanup/hold':
                    items=body.get('items') if isinstance(body,dict) else None
                    if not isinstance(items,list) or not 1<=len(items)<=10:return self.reply(400,{'error':'Select 1 to 10 archive candidates'})
                    names=[];digests={}
                    for row in items:
                        if not isinstance(row,dict) or set(row)!={'name','sha256'}:return self.reply(400,{'error':'Invalid cleanup selection'})
                        n=row['name'];h=row['sha256']
                        if not isinstance(n,str) or not ROOT_ARCHIVE_PATTERN.fullmatch(n) or not isinstance(h,str) or not re.fullmatch(r'[a-f0-9]{64}',h):
                            return self.reply(400,{'error':'Cleanup candidate name or digest invalid'})
                        names.append(n);digests[n]=h
                    if len(set(names))!=len(names):return self.reply(400,{'error':'Duplicate cleanup candidate'})
                    recent=read_json(CLEANUP_INVENTORY) if CLEANUP_INVENTORY.is_file() else {}
                    allowed={item.get('name'):item.get('sha256') for item in recent.get('items',[]) if item.get('eligible')}
                    if any(allowed.get(n)!=digests[n] for n in names):return self.reply(409,{'error':'Rescan /root before quarantining changed packages'})
                    ident=uuid.uuid4().hex;code='CLEANUP-HOLD'
                    record={'id':ident,'names':names,'sha256':digests}
                    atomic_service_state_write(STAGING/('cleanup-'+ident+'.json'),json.dumps(record).encode())
                else:
                    ident=body.get('request_id') if isinstance(body,dict) else None
                    if not isinstance(ident,str) or not ID_PATTERN.fullmatch(ident) or body!={'request_id':ident}:
                        return self.reply(400,{'error':'Select a quarantine receipt ID'})
                    recent=read_json(CLEANUP_QUARANTINE) if CLEANUP_QUARANTINE.is_file() else {}
                    if ident not in [r.get('request_id') for r in recent.get('items',[])]:
                        return self.reply(409,{'error':'Quarantine receipt not in latest inventory'})
                    code='CLEANUP-RESTORE'
                fd=os.open(MC_REQUEST,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
                with os.fdopen(fd,'wb') as f:
                    f.write((code+'-'+ident+'\n').encode('ascii'));f.flush();os.fsync(f.fileno())
                return self.reply(202,{'state':'queued','message':code.replace('-',' ').title()+' queued for root-owned worker'})
            except (OSError,ValueError,TypeError) as e:return self.reply(409,{'error':str(e)[:180]})
        if path=='/api/mission-control/upload':
            if self.headers.get('Content-Type')!='application/zip':
                return self.reply(415,{'error':'Neptune Release Control ZIP required'})
            filename=self.headers.get('X-Filename','')
            if not UPLOAD_PATH_PATTERN.fullmatch(filename):return self.reply(400,{'error':'Invalid ZIP filename'})
            try:length=int(self.headers.get('Content-Length','0'))
            except ValueError:length=0
            if not 0<length<=8*1024*1024:return self.reply(413,{'error':'Neptune Release Control ZIP limit is 8 MiB'})
            ident=uuid.uuid4().hex
            temp=STAGING/('mc-'+ident+'.part');final=STAGING/('mc-'+ident+'.zip');meta=STAGING/('mc-'+ident+'.json')
            try:
                sha=hashlib.sha256()
                with temp.open('xb') as out:
                    remaining=length
                    while remaining:
                        data=self.rfile.read(min(1048576,remaining))
                        if not data:raise ValueError('Incomplete upload')
                        out.write(data);sha.update(data);remaining-=len(data)
                plan=mission_control_inspect(temp)
                os.replace(temp,final)
                record={'id':ident,'name':filename,'uploaded_at':dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds').replace('+00:00','Z'),
                        'sha256':sha.hexdigest(),'plan':plan}
                with meta.open('x') as out:json.dump(record,out)
                self.reply(201,{'id':ident,'plan':plan,'sha256':record['sha256']})
            except (OSError,ValueError,zipfile.BadZipFile) as e:
                for fp in (temp,final,meta):fp.unlink(missing_ok=True)
                self.reply(400,{'error':str(e)[:180]})
            return
        if path in ('/api/mission-control/request','/api/github/publish'):
            if self.headers.get('Content-Type')!='application/json':return self.reply(415,{'error':'JSON required'})
            try:length=int(self.headers.get('Content-Length','0'))
            except ValueError:length=0
            if not 1<=length<=128:return self.reply(400,{'error':'Invalid request size'})
            try:body=json.loads(self.rfile.read(length))
            except (ValueError,UnicodeDecodeError):return self.reply(400,{'error':'Malformed JSON'})
            ident=body.get('package_id') if isinstance(body,dict) else None
            if not isinstance(ident,str) or not ID_PATTERN.fullmatch(ident) or body!={'package_id':ident}:
                return self.reply(400,{'error':'Select exactly one valid package'})
            if MC_REQUEST.exists() or FLASH_REQUEST.exists():return self.reply(409,{'error':'A release operation is already queued'})
            try:
                for statpath in (FLASH_STATUS,DEPLOY_STATUS,MC_STATUS,GITHUB_STATUS,GITHUB_MIRROR_STATUS,PRODUCTION_STATUS,CLEANUP_STATUS):
                    if statpath.is_file() and read_json(statpath).get('state') in ('queued','running'):
                        return self.reply(409,{'error':'A release operation is still running'})
                if path=='/api/mission-control/request':
                    meta=read_json(STAGING/('mc-'+ident+'.json'))
                    archive=STAGING/('mc-'+ident+'.zip')
                    if (meta.get('id')!=ident or not isinstance(meta.get('sha256'),str) or not hmac.compare_digest(hashlib.sha256(archive.read_bytes()).hexdigest(),meta['sha256'])
                        or mission_control_inspect(archive)!=meta.get('plan')):
                        return self.reply(409,{'error':'Neptune Release Control ZIP changed since validation'})
                    marker=('UPDATE-RC-'+ident+'\n').encode('ascii')
                else:
                    if not GITHUB_TOKEN.is_file():return self.reply(409,{'error':'GitHub token not configured on AWS'})
                    current=valid_qa_install()
                    approved=release_state()
                    if not current or current['id']!=ident or not approved.get('manual_qa_tested'):
                        return self.reply(409,{'error':'Install and manually mark this exact ZIP tested in QA first'})
                    metadata=read_json(STAGING/(ident+'.json'))
                    reviewed_package_plan(ident,metadata)
                    if approved.get('github_published'):return self.reply(409,{'error':'This QA-tested ZIP already has a pull request'})
                    marker=('GITHUB-PR-'+ident+'\n').encode('ascii')
                fd=os.open(MC_REQUEST,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
                with os.fdopen(fd,'wb') as out:out.write(marker);out.flush();os.fsync(out.fileno())
                self.reply(202,{'state':'queued','message':'Request queued for private privileged worker'})
            except FileExistsError:self.reply(409,{'error':'Release worker already queued'})
            except (ValueError,OSError,zipfile.BadZipFile) as e:self.reply(409,{'error':str(e)[:175]})
            return
        if path in ('/api/flash-qa/request','/api/deploy-qa/request','/api/qa-test/approve'):
            if self.headers.get('Content-Type')!='application/json':
                return self.reply(415,{'error':'JSON required'})
            try:
                length=int(self.headers.get('Content-Length','0'))
            except ValueError:
                length=0
            if length<1 or length>256:
                return self.reply(400,{'error':'Invalid request length'})
            try:
                body=json.loads(self.rfile.read(length))
                if not isinstance(body,dict):
                    return self.reply(400,{'error':'Invalid confirmation'})
                if path=='/api/qa-test/approve':
                    ident=body.get('package_id')
                    if (not isinstance(ident,str) or not ID_PATTERN.fullmatch(ident) or
                        body!={'package_id':ident}):
                        return self.reply(400,{'error':'Choose exactly one successful QA installation'})
                    try:return self.reply(200,approve_qa_test(ident))
                    except (OSError,ValueError,zipfile.BadZipFile) as e:
                        return self.reply(409,{'error':str(e)[:175]})
                if path=='/api/deploy-qa/request':
                    ident=body.get('package_id')
                    if (not isinstance(ident,str) or not ID_PATTERN.fullmatch(ident) or
                        body!={'package_id':ident}):
                        return self.reply(400,{'error':'Select exactly one staged package for QA installation'})
                    package_meta=STAGING/(ident+'.json')
                    if not package_meta.is_file():return self.reply(404,{'error':'Staged package not found'})
                    meta=read_json(package_meta)
                    if meta.get('policy_version')!=2 or meta.get('id')!=ident:
                        return self.reply(400,{'error':'Legacy ZIP must be re-uploaded for deployment review'})
                    try:
                        reviewed_package_plan(ident,meta)
                    except (OSError,ValueError,zipfile.BadZipFile) as e:
                        return self.reply(400,{'error':'ZIP is not deployable: '+str(e)[:150]})
                    marker=('DEPLOY-QA-'+ident+'\n').encode('ascii')
                    kind='deploy'
                elif body=={'mode':'database'}:
                    marker=b'FLASH-QA-DATABASE\n'
                    kind='database'
                elif body=={'mode':'full'}:
                    marker=b'FLASH-QA-FULL\n'
                    kind='full'
                elif body=={'mode':'app'}:
                    marker=b'FLASH-QA-APP\n'
                    kind='app'
                else:
                    return self.reply(400,{'error':'Choose a supported QA refresh mode'})
            except (ValueError,UnicodeDecodeError):
                return self.reply(400,{'error':'Invalid JSON'})
            if FLASH_REQUEST.exists() or MC_REQUEST.exists():
                return self.reply(409,{'error':'A release operation is already queued'})
            for path_check in (MC_STATUS,GITHUB_STATUS,GITHUB_MIRROR_STATUS,PRODUCTION_STATUS,CLEANUP_STATUS):
                if path_check.is_file() and read_json(path_check).get('state') in ('running','queued'):
                    return self.reply(409,{'error':'A Neptune Release Control or GitHub operation is active'})
            try:
                state=read_json(FLASH_STATUS).get('state') if FLASH_STATUS.exists() else 'idle'
                dstate=read_json(DEPLOY_STATUS).get('state') if DEPLOY_STATUS.exists() else 'idle'
                if state=='running' or dstate=='running':
                    return self.reply(409,{'error':'A QA operation is already running'})
                # Fixed literal only; no user-supplied arguments ever reach the root worker.
                fd=os.open(FLASH_REQUEST,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
                with os.fdopen(fd,'wb') as f:
                    f.write(marker)
                    f.flush();os.fsync(f.fileno())
                messages={'app':'QA application-only refresh queued; database and media stay unchanged.',
                          'full':'Full QA mirror queued: code, media and database refresh.',
                          'database':'QA database refresh queued; code and media stay unchanged.',
                          'deploy':'ZIP installation queued for QA only; production remains locked.'}
                return self.reply(202,{'state':'queued','message':messages[kind]})
            except FileExistsError:
                return self.reply(409,{'error':'QA operation already queued'})
            except OSError:
                return self.reply(503,{'error':'Unable to queue QA operation'})
        if self.headers.get('Content-Type')!='application/zip':
            return self.reply(415,{'error':'Upload a .zip archive'})
        name=self.headers.get('X-Filename','')
        if not UPLOAD_PATH_PATTERN.fullmatch(name):
            return self.reply(400,{'error':'Invalid ZIP filename'})
        try:length=int(self.headers.get('Content-Length','0'))
        except ValueError:length=0
        if length<1 or length>MAX_UPLOAD:
            return self.reply(413,{'error':'ZIP must be 1 byte to 50 MB'})
        if not REPORT.is_file():
            return self.reply(503,{'error':'Generate a comparison report before uploading packages'})
        ident=uuid.uuid4().hex
        temp=STAGING/(ident+'.part')
        final=STAGING/(ident+'.zip')
        manifest=STAGING/(ident+'.json')
        try:
            with temp.open('xb') as f:
                remaining=length
                sha=hashlib.sha256()
                while remaining:
                    chunk=self.rfile.read(min(1024*1024,remaining))
                    if not chunk:raise ValueError('Incomplete upload')
                    remaining-=len(chunk)
                    f.write(chunk);sha.update(chunk)
            inspected=inspect_zip(temp,read_json(REPORT))
            try:
                eligible=deployment_inspect(temp)
                policy_error=None
            except (ValueError,OSError,zipfile.BadZipFile) as error:
                eligible=[];policy_error=str(error)[:180]
            os.replace(temp,final)
            metadata={'id':ident,'name':name,'uploaded_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),
                      'sha256':sha.hexdigest(),**inspected,'status':'STAGED_ONLY',
                      'policy_version':2,'eligible_count':len(eligible),'deploy_error':policy_error,
                      'warning':'No files have been deployed to QA, GitHub, or production.'}
            # Never store uploaded package content in public directories.
            with manifest.open('x',encoding='utf-8') as f:json.dump(metadata,f)
            self.reply(201,{'id':ident,'file_count':inspected['file_count'],
                            'deployable':bool(eligible),'deploy_error':policy_error, 'status':'STAGED_ONLY'})
        except (OSError,ValueError,zipfile.BadZipFile,RuntimeError) as e:
            for fp in (temp,final,manifest):
                try:fp.unlink(missing_ok=True)
                except OSError:pass
            self.reply(400,{'error':str(e)[:180]})


def main():
    if os.geteuid()==0:raise SystemExit('Refusing to run release website as root')
    if not STAGING.is_dir():raise SystemExit('Staging directory missing')
    with ThreadingHTTPServer((HOST,PORT),Handler) as server:
        server.daemon_threads=True
        print(f'Neptune Release Center available privately at {HOST}:{PORT}',flush=True)
        server.serve_forever()

# Production-first Maintenance Center API. Uses the installed authentication,
# Origin/CSRF checks and root fixed-action worker; no credentials enter browser JSON.
def maintenance_report(name,default):
    path=BASE/'reports'/name
    if not path.is_file() or path.is_symlink() or path.stat().st_size>2_000_000:return default
    try:return read_json(path)
    except (ValueError,OSError):return default

def maintenance_packages():
    rows=[]
    for path in sorted(STAGING.glob('maintenance-package-*.json'),key=lambda p:p.stat().st_mtime,reverse=True)[:30]:
        if path.is_symlink() or path.stat().st_size>250_000:continue
        try:
            d=read_json(path)
            if not ID_PATTERN.fullmatch(str(d.get('id',''))):continue
            item={k:d.get(k) for k in ('id','name','sha256','bytes','uploaded_at')}
            result=maintenance_report('maintenance-package-'+d['id']+'.json',{})
            item['review']={k:result.get(k) for k in ('state','phase','plan_digest','files','created_at','finished_at','backup_id')}
            rows.append(item)
        except (ValueError,OSError):continue
    return rows

def maintenance_busy():
    if MC_REQUEST.exists() or FLASH_REQUEST.exists():return True
    for path in (MC_STATUS,GITHUB_STATUS,GITHUB_MIRROR_STATUS,PRODUCTION_STATUS,CLEANUP_STATUS,BASE/'reports/maintenance-status.json'):
        if path.is_file() and read_json(path).get('state') in ('running','queued'):return True
    return False

def maintenance_request_headers(self):
    origin=self.headers.get('Origin','');host=self.headers.get('Host','').lower()
    if ((host=='rc.neptunefrc.com' and origin!='https://rc.neptunefrc.com') or
        (origin and origin not in ('http://localhost:18081','http://127.0.0.1:18081','https://rc.neptunefrc.com'))):
        self.reply(403,{'error':'Origin rejected'});return False
    if not hmac.compare_digest(self.headers.get('X-CSRF',''),CSRF_TOKEN):
        self.reply(403,{'error':'CSRF validation failed'});return False
    return True



# ===== Database explorer: guarded SQL over limited MySQL accounts (inlined, unit-tested) =====
import re

MAX_SQL = 20000

# One pass over quoted strings, backtick identifiers, and comments. Quoted spans become a neutral
# placeholder so separators/keywords inside them are not mistaken for SQL; comments become a space.
_SCAN = re.compile(r"""
    '(?:\\.|''|[^'\\])*'          # '...'  (\\ escape or doubled '')
  | "(?:\\.|""|[^"\\])*"          # "..."
  | `(?:[^`]|``)*`                # `ident`
  | /\*.*?\*/                      # /* block comment */  (incl. /*! ... */, handled below)
  | --(?:[ \t][^\n]*|$)            # -- line comment (needs a space/EOL after --)
  | \#[^\n]*                       # # line comment
""", re.S | re.X)

_READ_VERBS = ('select', 'with', 'show', 'explain', 'describe', 'desc')
_WRITE_VERBS = ('insert', 'update', 'delete')

_FORBIDDEN = [
    (re.compile(r'into\s+outfile', re.I), 'writing files (INTO OUTFILE) is not allowed'),
    (re.compile(r'into\s+dumpfile', re.I), 'writing files (INTO DUMPFILE) is not allowed'),
    (re.compile(r'load_file\s*\(', re.I), 'reading files (LOAD_FILE) is not allowed'),
    (re.compile(r'load\s+data', re.I), 'LOAD DATA is not allowed'),
    (re.compile(r'\\G'), r'the \G terminator is not allowed'),
]


class SqlError(ValueError):
    pass


def _scan_copy(sql):
    """Comment-free copy with quoted literals/identifiers replaced by a neutral token."""
    def repl(m):
        t = m.group(0)
        return ' ' if t[:2] in ('/*', '--') or t[:1] == '#' else ' _ '
    return _SCAN.sub(repl, sql)


def _common(sql):
    if not isinstance(sql, str):
        raise SqlError('SQL must be text')
    sql = sql.strip()
    if not sql:
        raise SqlError('Enter a statement')
    if len(sql) > MAX_SQL:
        raise SqlError('Statement is too long')
    if '/*!' in sql:
        raise SqlError('MySQL executable comments (/*! ... */) are not allowed')
    scan = _scan_copy(sql).strip()
    # Allow exactly one optional trailing semicolon; no other statement separators.
    if scan.endswith(';'):
        scan = scan[:-1].rstrip()
    if ';' in scan:
        raise SqlError('Only one statement is allowed')
    for pattern, message in _FORBIDDEN:
        if pattern.search(scan):
            raise SqlError(message)
    return sql.rstrip().rstrip(';').rstrip(), scan


def _verb(scan):
    m = re.match(r'\(*\s*([a-zA-Z]+)', scan)
    return m.group(1).lower() if m else ''


def check_read(sql):
    """Return the statement to run, or raise SqlError. Read path: SELECT/SHOW/EXPLAIN/DESC/WITH."""
    clean, scan = _common(sql)
    if _verb(scan) not in _READ_VERBS:
        raise SqlError('Only SELECT, SHOW, EXPLAIN, DESCRIBE or WITH queries are allowed here')
    return clean


def check_write(sql):
    """Return (statement, verb), or raise SqlError. Write path: INSERT/UPDATE/DELETE with WHERE."""
    clean, scan = _common(sql)
    verb = _verb(scan)
    if verb not in _WRITE_VERBS:
        raise SqlError('Only INSERT, UPDATE or DELETE statements are allowed on the write path')
    if verb in ('update', 'delete') and not re.search(r'\bwhere\b', scan, re.I):
        raise SqlError(verb.upper() + ' must include a WHERE clause')
    return clean, verb


# ---- identifier + value escaping for structured (grid) edits -------------------------------------

_IDENT = re.compile(r'^[A-Za-z0-9_$]{1,64}$')


def ident(name):
    """Backtick-quote a validated identifier (table or column)."""
    if not isinstance(name, str) or not _IDENT.fullmatch(name):
        raise SqlError('Invalid identifier: ' + str(name)[:40])
    return '`' + name + '`'


def literal(value):
    """MySQL string/number/NULL literal with full escaping (for structured edits only)."""
    if value is None:
        return 'NULL'
    if isinstance(value, bool):
        return '1' if value else '0'
    if isinstance(value, int):
        return str(value)
    if isinstance(value, float):
        if value != value or value in (float('inf'), float('-inf')):
            raise SqlError('Invalid numeric value')
        return repr(value)
    if not isinstance(value, str):
        raise SqlError('Unsupported value type')
    return "CONVERT(X'" + value.encode('utf-8').hex() + "' USING utf8mb4)"


def build_update(table, changes, keys):
    """Structured UPDATE from the Browse grid. `changes` and `keys` are {column: value}."""
    if not changes:
        raise SqlError('No changes to apply')
    if not keys:
        raise SqlError('This row has no key columns to target safely')
    sets = ', '.join('%s = %s' % (ident(c), literal(v)) for c, v in changes.items())
    where = ' AND '.join('%s = %s' % (ident(c), literal(v)) for c, v in keys.items())
    return 'UPDATE %s SET %s WHERE %s LIMIT 1' % (ident(table), sets, where)


def build_delete(table, keys):
    if not keys:
        raise SqlError('This row has no key columns to target safely')
    where = ' AND '.join('%s = %s' % (ident(c), literal(v)) for c, v in keys.items())
    return 'DELETE FROM %s WHERE %s LIMIT 1' % (ident(table), where)


MAX_ROWS = 1000
MAX_CELL = 4000
TIMEOUT_MS = 5000


def _trunc(v):
    if isinstance(v, str) and len(v) > MAX_CELL:
        return v[:MAX_CELL] + '…'
    return v


def parse_json_lines(stdout, cap=MAX_ROWS):
    """Each non-empty line is a JSON array (one row). Returns (rows, truncated)."""
    rows = []
    for line in stdout.splitlines():
        if not line.strip():
            continue
        row = json.loads(line)
        if not isinstance(row, list):
            raise ValueError('Unexpected row shape from database')
        rows.append([_trunc(v) for v in row])
    truncated = len(rows) > cap
    return rows[:cap], truncated


def parse_header(stdout):
    """First line of a `--batch` (tab-separated, with column names) result: the column names."""
    line = stdout.splitlines()[0] if stdout.splitlines() else ''
    return line.split('\t') if line else []


def parse_tsv(stdout):
    """Tab-separated `--batch` output (headers + rows) for SHOW/EXPLAIN/DESCRIBE. NULL -> None."""
    lines = stdout.splitlines()
    if not lines:
        return [], []
    columns = lines[0].split('\t')
    rows = []
    for line in lines[1:]:
        cells = line.split('\t')
        rows.append([None if c == 'NULL' else _trunc(c) for c in cells])
    return columns, rows


def count_sql(table):
    return 'SELECT COUNT(*) FROM %s' % ident(table)


def browse_json_sql(table, columns, pk, page, limit):
    """JSON-array rows for one page, ordered by primary key when there is one."""
    limit = max(1, min(int(limit), MAX_ROWS))
    offset = max(0, int(page)) * limit
    cols = ', '.join(ident(c) for c in columns)
    order = (' ORDER BY ' + ', '.join(ident(c) for c in pk)) if pk else ''
    return 'SELECT JSON_ARRAY(%s) FROM %s%s LIMIT %d OFFSET %d' % (cols, ident(table), order, limit, offset)


def columns_probe_sql(user_sql):
    """Column names of a validated SELECT/WITH, via a zero-row derived table."""
    return 'SELECT * FROM (%s) AS _q LIMIT 0' % user_sql


def query_json_sql(user_sql, columns, cap=MAX_ROWS):
    """Wrap a validated SELECT/WITH so each row comes back as a JSON array; +1 to detect truncation."""
    cols = ', '.join(ident(c) for c in columns)
    return 'SELECT JSON_ARRAY(%s) FROM (%s) AS _q LIMIT %d' % (cols, user_sql, cap + 1)


def rowcount_suffix(stmt):
    """Append ROW_COUNT() so the write's affected-row count comes back on its own line."""
    return stmt + '; SELECT ROW_COUNT()'


def information_schema_columns_sql(schema, table):
    return ("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA "
            "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION"
            ) % (literal(schema), literal(table))


def information_schema_tables_sql(schema):
    return ("SELECT TABLE_NAME, TABLE_ROWS, ROUND((DATA_LENGTH+INDEX_LENGTH)/1048576,1), ENGINE "
            "FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
            ) % literal(schema)



# ---- Database explorer backend (limited-account, read + DML; see release-center/db-explorer) ----
import subprocess as _subprocess
EXPLORER_CNF = Path('/etc/neptune-release-center/explorer.cnf')
EDITOR_CNF = Path('/etc/neptune-release-center/editor.cnf')
DB_SCHEMA = 'scouting'
DB_TIMEOUT_S = 8

def db_run(cnf, sql, raw=False):
    cnf = Path(cnf)
    if not cnf.is_file():
        raise ValueError('Database access is not configured on this server')
    import selectors,signal
    args = ['mysql', '--defaults-extra-file=' + str(cnf), '--binary-mode', '--batch',
            '--default-character-set=utf8mb4', '--database=' + DB_SCHEMA,
            '--init-command=SET SESSION MAX_EXECUTION_TIME=%d' % (DB_TIMEOUT_S * 1000)]
    if raw: args += ['--raw', '--skip-column-names']
    # stdin + --binary-mode prevents mysql client shell/source commands; no SQL/password in argv.
    p = _subprocess.Popen(args,stdin=_subprocess.PIPE,stdout=_subprocess.PIPE,stderr=_subprocess.PIPE,start_new_session=True)
    out=bytearray();err=bytearray();sel=selectors.DefaultSelector();deadline=time.monotonic()+DB_TIMEOUT_S+4
    try:
        p.stdin.write(sql.encode('utf-8')+b'\n');p.stdin.close()
        sel.register(p.stdout,selectors.EVENT_READ,out);sel.register(p.stderr,selectors.EVENT_READ,err)
        while sel.get_map():
            if time.monotonic()>deadline:raise ValueError('The query timed out')
            for key,_ in sel.select(.1):
                data=os.read(key.fileobj.fileno(),16384)
                if not data:sel.unregister(key.fileobj);continue
                key.data.extend(data)
                if len(out)>4*1024*1024 or len(err)>16000:raise ValueError('Result is too large; select fewer columns or rows')
        p.wait(timeout=1)
        if p.returncode:
            lines=err.decode('utf-8','replace').strip().splitlines()
            raise ValueError((lines[-1][:300] if lines else 'The query failed').replace('ERROR','Database error',1))
        return out.decode('utf-8','replace')
    except OSError:raise ValueError('Database client is unavailable')
    finally:
        sel.close()
        if p.poll() is None:
            try:os.killpg(p.pid,signal.SIGKILL)
            except ProcessLookupError:pass
            p.wait()
        p.stdout.close();p.stderr.close()


def db_index_sql(schema, table):
    return ("SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS "
            "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s ORDER BY INDEX_NAME, SEQ_IN_INDEX") % (literal(schema), literal(table))

def db_table_meta(name):
    ident(name)
    _, rows = parse_tsv(db_run(EXPLORER_CNF, information_schema_columns_sql(DB_SCHEMA, name)))
    if not rows:
        raise ValueError('No such table')
    columns = [{'name': r[0], 'type': r[1], 'nullable': r[2] == 'YES', 'key': r[3] or '',
                'default': r[4], 'extra': r[5]} for r in rows]
    pk = [c['name'] for c in columns if c['key'] == 'PRI']
    return columns, pk

def db_schema():
    _, rows = parse_tsv(db_run(EXPLORER_CNF, information_schema_tables_sql(DB_SCHEMA)))
    tables = [{'name': r[0], 'rows': int(r[1]) if r[1] not in (None, '') else None,
               'size_mb': float(r[2]) if r[2] not in (None, '') else None, 'engine': r[3]} for r in rows]
    return {'database': DB_SCHEMA, 'tables': tables}

def db_table(name):
    columns, pk = db_table_meta(name)
    _, irows = parse_tsv(db_run(EXPLORER_CNF, db_index_sql(DB_SCHEMA, name)))
    seen = {}
    for r in irows:
        idx = seen.setdefault(r[0], {'name': r[0], 'columns': [], 'unique': r[2] == '0'})
        idx['columns'].append(r[1])
    return {'name': name, 'columns': columns, 'primary_key': pk, 'indexes': list(seen.values())}

def db_browse(name, page, limit):
    columns, pk = db_table_meta(name)
    limit = max(1, min(int(limit), MAX_ROWS)); page = max(0, int(page))
    _, crows = parse_tsv(db_run(EXPLORER_CNF, count_sql(name)))
    total = int(crows[0][0]) if crows and crows[0] and str(crows[0][0]).lstrip('-').isdigit() else 0
    names = [c['name'] for c in columns]
    rows, _t = parse_json_lines(db_run(EXPLORER_CNF, browse_json_sql(name, names, pk, page, limit), raw=True), cap=limit)
    return {'name': name, 'columns': names, 'primary_key': pk, 'rows': rows, 'total_rows': total, 'page': page, 'limit': limit}

# PDO supplies real result metadata, including empty result sets and duplicate column names.
PDO_QUERY = r"""
$body=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try {
 $cfg=parse_ini_file($body['cnf'],true,INI_SCANNER_RAW)['client'];
 $pdo=new PDO('mysql:host=localhost;dbname=scouting;charset=utf8mb4',$cfg['user'],$cfg['password'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
 PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
 $pdo->exec('SET SESSION MAX_EXECUTION_TIME=8000');
 $stmt=$pdo->prepare($body['sql']);$stmt->execute();$columns=[];$rows=[];$bytes=0;$truncated=false;
 for($i=0;$i<$stmt->columnCount();$i++){$meta=$stmt->getColumnMeta($i);$columns[]=$meta['name'];}
 while(($row=$stmt->fetch(PDO::FETCH_NUM))!==false){
  if(count($rows)>=1000){$truncated=true;break;}
  foreach($row as &$v){if(is_string($v)&&strlen($v)>4000)$v=substr($v,0,4000).'…';}unset($v);
  $encoded=json_encode($row,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
  if($bytes+strlen($encoded)>2000000){$truncated=true;break;}$bytes+=strlen($encoded);$rows[]=$row;
 }
 echo json_encode(['columns'=>$columns,'rows'=>$rows,'truncated'=>$truncated],JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
} catch(Throwable $e){echo json_encode(['error'=>substr($e->getMessage(),0,300)],JSON_INVALID_UTF8_SUBSTITUTE);exit(1);}
"""
def db_query(sql):
    clean=check_read(sql);started=time.monotonic()
    if not EXPLORER_CNF.is_file():raise ValueError('Database access is not configured on this server')
    try:
        p=_subprocess.run(['/usr/bin/php','-d','memory_limit=64M','-r',PDO_QUERY],input=json.dumps({'sql':clean,'cnf':str(EXPLORER_CNF)}),capture_output=True,text=True,timeout=DB_TIMEOUT_S+4)
    except _subprocess.TimeoutExpired:raise ValueError('The query timed out')
    except OSError:raise ValueError('Database query runtime is unavailable')
    if len(p.stdout)>3*1024*1024:raise ValueError('Result is too large; select fewer columns or rows')
    try:result=json.loads(p.stdout)
    except ValueError:raise ValueError('Query result exceeded the runtime limit or could not be read')
    if p.returncode or result.get('error'):raise ValueError(result.get('error','The query failed'))
    result.update(row_count=len(result['rows']),elapsed_ms=int((time.monotonic()-started)*1000));return result


def _db_require_keys(keys, pk):
    if not pk or not keys or set(keys) != set(pk):
        raise ValueError('Target the row by its full primary key')

def db_write(body):
    if body.get('confirm') != 'APPLY':
        raise ValueError('Type APPLY to confirm a write')
    if body.get('sql'):
        stmt, _verb = check_write(body['sql'])
    else:
        table = body.get('table')
        columns, pk = db_table_meta(table)
        names = {c['name'] for c in columns}
        if body.get('delete'):
            keys = body.get('keys') or {}; _db_require_keys(keys, pk); stmt = build_delete(table, keys)
        elif body.get('insert') is not None:
            ins = body['insert'] or {}
            for c in ins:
                if c not in names:
                    raise ValueError('Unknown column: ' + str(c)[:40])
            if not ins:
                raise ValueError('Nothing to insert')
            stmt = 'INSERT INTO %s (%s) VALUES (%s)' % (ident(table),
                   ', '.join(ident(c) for c in ins), ', '.join(literal(v) for v in ins.values()))
        else:
            changes = body.get('changes') or {}; keys = body.get('keys') or {}
            for c in list(changes) + list(keys):
                if c not in names:
                    raise ValueError('Unknown column: ' + str(c)[:40])
            _db_require_keys(keys, pk); stmt = build_update(table, changes, keys)
    out = db_run(EDITOR_CNF, rowcount_suffix(stmt), raw=True)
    affected = 0
    for line in out.splitlines():
        token = line.strip()
        if token.lstrip('-').isdigit():
            affected = int(token)
    return {'affected_rows': affected, 'phase': '%d row(s) affected.' % affected}

def db_get(self, path):
    if not self.allowed():
        return True
    import urllib.parse as _u
    q = _u.parse_qs(_u.urlsplit(self.path).query)
    try:
        if path == '/api/db/schema':
            self.reply(200, db_schema())
        elif path == '/api/db/table':
            self.reply(200, db_table(q.get('name', [''])[0]))
        elif path == '/api/db/browse':
            self.reply(200, db_browse(q.get('name', [''])[0], q.get('page', ['0'])[0], q.get('limit', ['50'])[0]))
        else:
            self.reply(404, {'error': 'Not found'})
    except ValueError as e:
        self.reply(400, {'error': str(e)[:300]})
    except OSError:
        self.reply(503, {'error': 'Database is temporarily unavailable'})
    return True

def db_post(self, path):
    if not self.allowed() or not maintenance_request_headers(self):
        return True
    if self.headers.get('Content-Type') != 'application/json':
        self.reply(415, {'error': 'JSON required'}); return True
    try:
        length = int(self.headers.get('Content-Length', '0'))
        if not 1 <= length <= 20000:
            self.reply(400, {'error': 'Invalid request size'}); return True
        body = json.loads(self.rfile.read(length))
        if not isinstance(body, dict):
            raise ValueError('Invalid request')
        if path == '/api/db/query':
            self.reply(200, db_query(body.get('sql', '')))
        elif path == '/api/db/write':
            self.reply(200, db_write(body))
        else:
            self.reply(404, {'error': 'Not found'})
    except (ValueError, TypeError, UnicodeDecodeError) as e:
        self.reply(400, {'error': str(e)[:300]})
    except OSError:
        self.reply(503, {'error': 'Database is temporarily unavailable'})
    return True

_legacy_get=Handler.do_GET
_legacy_post=Handler.do_POST

def maintenance_get(self):
    path=urllib.parse.urlsplit(self.path).path
    if path.startswith('/api/db/'):return db_get(self,path)
    if not path.startswith('/api/maintenance/'):return _legacy_get(self)
    if not self.allowed():return
    try:
        if path=='/api/maintenance/summary':
            return self.reply(200,{'version':'maintenance-preview-1','environment':'production','qa_required':False,
              'operation':({'state':'queued','phase':'A maintenance operation is queued.'} if MC_REQUEST.exists() else maintenance_report('maintenance-status.json',{'state':'idle','phase':'No maintenance operation is running.'})),
              'database':maintenance_report('maintenance-database.json',None),
              'backup':maintenance_report('maintenance-backup.json',None),
              'recovery':maintenance_report('maintenance-recovery.json',{'items':[]}),
              'storage':maintenance_report('maintenance-storage.json',None),
              'packages':maintenance_packages()})
        return self.reply(404,{'error':'Not found'})
    except (ValueError,OSError):return self.reply(503,{'error':'Maintenance status is temporarily unavailable'})

def maintenance_post(self):
    path=urllib.parse.urlsplit(self.path).path
    retired={'/api/upload','/api/flash-qa/request','/api/deploy-qa/request','/api/qa-test/approve','/api/github/publish','/api/package-flow/request','/api/production/preflight','/api/production/install','/api/production/rollback'}
    if path in retired:
        if not self.allowed():return
        return self.reply(410,{'error':'The QA release workflow is retired. Use production package inspection and review.'})
    if path.startswith('/api/db/'):return db_post(self,path)
    if not path.startswith('/api/maintenance/'):return _legacy_post(self)
    if not self.allowed() or not maintenance_request_headers(self):return
    try:
        if path not in ('/api/maintenance/upload','/api/maintenance/request'):return self.reply(404,{'error':'Not found'})
        if maintenance_busy():return self.reply(409,{'error':'Another maintenance operation is queued or running'})
        length=int(self.headers.get('Content-Length','0'))
        if path.endswith('/upload'):
            if self.headers.get('Content-Type')!='application/zip':return self.reply(415,{'error':'Choose a ZIP package'})
            if not 1<=length<=MAX_UPLOAD:return self.reply(413,{'error':'ZIP package must be 1 byte to 50 MB'})
            name=urllib.parse.unquote(self.headers.get('X-Filename',''))
            if not UPLOAD_PATH_PATTERN.fullmatch(name):return self.reply(400,{'error':'Invalid ZIP filename'})
            ident=uuid.uuid4().hex;destination=STAGING/('maintenance-package-'+ident+'.zip');temporary=destination.with_suffix('.uploading')
            h=hashlib.sha256();remaining=length
            try:
                fd=os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
                with os.fdopen(fd,'wb') as out:
                    while remaining:
                        chunk=self.rfile.read(min(1024*1024,remaining))
                        if not chunk:raise ValueError('Incomplete package upload')
                        out.write(chunk);h.update(chunk);remaining-=len(chunk)
                    out.flush();os.fsync(out.fileno())
                deployment_inspect(temporary)
                os.replace(temporary,destination)
                meta={'id':ident,'name':name,'sha256':h.hexdigest(),'bytes':length,'uploaded_at':dt.datetime.now(dt.timezone.utc).isoformat()}
                atomic_service_state_write(destination.with_suffix('.json'),json.dumps(meta).encode())
            except Exception:
                temporary.unlink(missing_ok=True);destination.unlink(missing_ok=True);raise
            return self.reply(201,{'id':ident,'name':name,'phase':'Uploaded. Inspect the production changes before installation.'})
        if self.headers.get('Content-Type')!='application/json':return self.reply(415,{'error':'JSON required'})
        if not 1<=length<=20_000:return self.reply(400,{'error':'Invalid request size'})
        import maintenance_ops
        body=maintenance_ops.validate_job(json.loads(self.rfile.read(length)))
        ident=uuid.uuid4().hex
        atomic_service_state_write(STAGING/('maintenance-request-'+ident+'.json'),json.dumps(body).encode())
        try:
            fd=os.open(MC_REQUEST,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            with os.fdopen(fd,'wb') as f:f.write(('MAINTENANCE-'+ident+'\n').encode());f.flush();os.fsync(f.fileno())
        except Exception:
            (STAGING/('maintenance-request-'+ident+'.json')).unlink(missing_ok=True);raise
        return self.reply(202,{'state':'queued','request_id':ident,'action':body['action'],'phase':'Maintenance request queued.'})
    except FileExistsError:return self.reply(409,{'error':'An operation was queued while you were reviewing; retry after it finishes'})
    except (ValueError,TypeError,UnicodeDecodeError,zipfile.BadZipFile) as e:return self.reply(400,{'error':str(e)[:240]})
    except OSError:return self.reply(503,{'error':'Unable to stage the maintenance operation'})

Handler.do_GET=maintenance_get
Handler.do_POST=maintenance_post


# Root console and self-hosted Font Awesome assets. Same RC authentication and CSRF policy.
def console_request(body):
    import socket
    data=json.dumps(body,ensure_ascii=False).encode()+b'\n'
    if len(data)>4*1024*1024:raise ValueError('Console request too large')
    with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as conn:
        conn.settimeout(65);conn.connect('/run/neptune-root-console/console.sock');conn.sendall(data)
        response=bytearray()
        while not response.endswith(b'\n'):
            block=conn.recv(16384)
            if not block:break
            response.extend(block)
            if len(response)>12*1024*1024:raise ValueError('Console response too large')
    result=json.loads(response)
    if result.get('error'):raise ValueError(result['error'])
    return result

_before_console_get=Handler.do_GET
_before_console_post=Handler.do_POST

def console_get(self):
    path=urllib.parse.urlsplit(self.path).path
    if path=='/api/console/status':
        if not self.allowed():return
        try:return self.reply(200,console_request({'action':'status'}))
        except (OSError,ValueError):return self.reply(503,{'error':'Root console is temporarily unavailable'})
    if path.startswith('/vendor/fontawesome/'):
        if not self.allowed():return
        rel=path.removeprefix('/vendor/fontawesome/')
        allowed={'css/all.min.css','webfonts/fa-solid-900.woff2','webfonts/fa-regular-400.woff2','webfonts/fa-brands-400.woff2','webfonts/fa-v4compatibility.woff2'}
        if rel not in allowed:return self.reply(404,{'error':'Not found'})
        file=WEB/'vendor/fontawesome'/rel
        if not file.is_file():return self.reply(404,{'error':'Font asset unavailable'})
        data=file.read_bytes();self.send_response(200);self.send_header('Content-Type','text/css' if rel.endswith('.css') else 'font/woff2');self.send_header('Content-Length',str(len(data)));self.secure_headers();self.end_headers();self.wfile.write(data);return
    return _before_console_get(self)

def console_post(self):
    path=urllib.parse.urlsplit(self.path).path
    if path!='/api/console/run':return _before_console_post(self)
    if not self.allowed() or not maintenance_request_headers(self):return
    try:
        if self.headers.get('Content-Type')!='application/json':return self.reply(415,{'error':'JSON required'})
        length=int(self.headers.get('Content-Length','0'))
        if not 1<=length<=24000:raise ValueError('Console request too large')
        body=json.loads(self.rfile.read(length))
        if not isinstance(body,dict) or set(body)-{'command','cwd'}:raise ValueError('Invalid console request')
        return self.reply(200,console_request({'action':'run',**body}))
    except (ValueError,TypeError,UnicodeDecodeError) as e:return self.reply(400,{'error':str(e)[:300]})
    except OSError:return self.reply(503,{'error':'Root console is temporarily unavailable'})

Handler.do_GET=console_get
Handler.do_POST=console_post


_before_system_get=Handler.do_GET
_before_system_post=Handler.do_POST

def system_get(self):
    path=urllib.parse.urlsplit(self.path).path
    if path!='/api/system/download':return _before_system_get(self)
    if not self.allowed():return
    ident=urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query).get('id',[''])[0]
    if not ID_PATTERN.fullmatch(ident):return self.reply(400,{'error':'Invalid download'})
    root=BASE/'downloads';file=root/(ident+'.bin');meta_path=root/(ident+'.json')
    if file.is_symlink() or meta_path.is_symlink() or not file.is_file() or not meta_path.is_file():return self.reply(404,{'error':'Download expired'})
    meta=json.loads(meta_path.read_text());name=Path(meta['name']).name
    try:
        self.send_response(200);self.send_header('Content-Type','application/octet-stream');self.send_header('Content-Disposition',"attachment; filename*=UTF-8''"+urllib.parse.quote(name,safe=''));self.send_header('Content-Length',str(file.stat().st_size));self.secure_headers();self.end_headers()
        with file.open('rb') as f:
            for block in iter(lambda:f.read(1024*1024),b''):self.wfile.write(block)
    finally:
        try:console_request({'action':'system','op':'downloads.delete','args':{'id':ident}})
        except Exception:pass

def system_post(self):
    path=urllib.parse.urlsplit(self.path).path
    if path not in ('/api/system/dispatch','/api/system/upload'):return _before_system_post(self)
    if not self.allowed() or not maintenance_request_headers(self):return
    try:
        length=int(self.headers.get('Content-Length','0'))
        if path.endswith('/upload'):
            if not 0<length<=50*1024*1024:raise ValueError('Upload must be 1 byte to 50 MiB')
            rel=urllib.parse.unquote(self.headers.get('X-Directory',''));name=urllib.parse.unquote(self.headers.get('X-Filename',''));ident=uuid.uuid4().hex;p=STAGING/('system-upload-'+ident);h=hashlib.sha256()
            try:
                fd=os.open(p,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
                with os.fdopen(fd,'wb') as f:
                    remaining=length
                    while remaining:
                        chunk=self.rfile.read(min(remaining,1024*1024))
                        if not chunk:raise ValueError('Incomplete upload')
                        f.write(chunk);h.update(chunk);remaining-=len(chunk)
                result=console_request({'action':'system','op':'files.upload','args':{'path':rel,'name':name,'upload_id':ident,'sha256':h.hexdigest(),'bytes':length}})
            finally:p.unlink(missing_ok=True)
        else:
            if self.headers.get('Content-Type')!='application/json':return self.reply(415,{'error':'JSON required'})
            if not 0<length<=4*1024*1024:raise ValueError('Request exceeds 4 MiB')
            body=json.loads(self.rfile.read(length))
            if not isinstance(body,dict) or set(body)!={'op','args'}:raise ValueError('Invalid system request')
            result=console_request({'action':'system',**body})
        return self.reply(200,result)
    except (ValueError,TypeError,UnicodeDecodeError) as e:return self.reply(400,{'error':str(e)[:500]})
    except OSError:return self.reply(503,{'error':'System service is temporarily unavailable'})
Handler.do_GET=system_get
Handler.do_POST=system_post

if __name__=='__main__':main()
