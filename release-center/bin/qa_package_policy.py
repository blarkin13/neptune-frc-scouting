#!/usr/bin/env python3
"""Shared, fail-closed package policy. Never execute content from a ZIP.

Only a narrow collection of web-app files can be written into disposable QA.
Paths are normalized independent of untrusted upload-manifest metadata.
"""
from __future__ import annotations
import hashlib
from pathlib import PurePosixPath
import re
import stat
import zipfile

MAX_FILES = 1500
MAX_EXPANDED = 200 * 1024 * 1024
MAX_FILE = 30 * 1024 * 1024
MAX_PATH = 240
APP_DIRS = frozenset('admin analytics api assets auth dashboard docs errors field-practice help images pit play prescout scout spot sql wifi strategy games'.split()) | frozenset({'Neptune_MultiOrg_DatabaseLab'})
# sql/ is never deployable even if nested. No SQL migration execution in this phase.
APP_DIRS = APP_DIRS - {'sql'}
APP_TYPES = frozenset('.php .js .css .json .html .htm .svg .png .jpeg .jpg .gif .webp .avif .ico .woff .woff2 .ttf .otf .eot .txt .md .xml .map .pdf'.split())
IGNORED_ROOT_FILES = frozenset({'readme.md','readme.txt','changelog.md','changelog.txt','sha256sums.txt','manifest.json','neptune-package.json','host_install_first.txt'})
BANNED_PARTS = frozenset({'uploads','spot_media','backup','backups','maintenance-uploads','maintenance-backups','.git','.svn','.github','__pycache__','node_modules','vendor_secrets','.well-known'})
BANNED_LEAVES = frozenset({'.htaccess','.user.ini','.env','.my.cnf','config.php','google-auth.php','credentials.json','composer.phar','file-manager.php','maintenance.php'})
BANNED_EXTENSIONS = ('.env','.key','.pem','.p12','.pfx','.bak','.log','.sql','.sh','.py','.zip','.tgz','.tar','.gz','.db','.sqlite','.ini','.conf','.crt','.cer')
PROTECTED_APP = frozenset({'admin/file-manager.php','admin/maintenance.php','api/offline-sync.php','api/portable-sync.php','api/portable-sync-media.php'})
SAFE_PART = re.compile(r'^[A-Za-z0-9_][A-Za-z0-9_. @()+-]{0,119}$')

def clean_name(name: str):
    if not name or len(name)>MAX_PATH or '\\' in name or name.startswith('/') or '\x00' in name or re.match(r'^[A-Za-z]:',name):
        raise ValueError('Unsafe archive path')
    n=name.rstrip('/')
    parts=n.split('/')
    if any(not SAFE_PART.fullmatch(p) or p in ('.','..') or p.startswith('.') for p in parts):
        raise ValueError('Unsafe archive path component: '+name[:100])
    return parts

def ignored_mac(name):
    return name.startswith('__MACOSX/') or name=='__MACOSX/' or name.endswith('/.DS_Store') or name=='.DS_Store'

def _normalize(parts):
    if len(parts)>=3 and parts[:2]==['public_html','Neptune']:
        scope='app'; rel=parts[2:]
    elif len(parts)>=2 and parts[0]=='Neptune':
        scope='app'; rel=parts[1:]
    elif len(parts)>=2 and parts[0]=='neptune_secure':
        scope='secure'; rel=parts[1:]
    elif len(parts)>=2 and parts[0] in APP_DIRS:
        scope='app'; rel=parts
    elif len(parts)==1 and PurePosixPath(parts[0]).suffix.lower() in APP_TYPES:
        scope='app'; rel=parts
    else:
        raise ValueError('No safe Neptune QA mapping for: '+'/'.join(parts))
    if not rel or any(p.lower() in BANNED_PARTS for p in rel):
        raise ValueError('Protected QA data/media path: '+'/'.join(parts))
    lower='/'.join(rel).lower()
    if (rel[-1].lower() in BANNED_LEAVES or rel[-1].lower().endswith(BANNED_EXTENSIONS)
        or rel[-1].startswith('.') or (scope=='app' and lower in PROTECTED_APP)):
        raise ValueError('Protected or restricted file: '+'/'.join(parts))
    ext=PurePosixPath(rel[-1]).suffix.lower()
    if ext not in APP_TYPES or (scope=='secure' and ext!='.php'):
        raise ValueError('Unsupported file type or secure scope: '+'/'.join(parts))
    if any(p.lower() in BANNED_PARTS for p in rel[:-1]):
        raise ValueError('Protected folder: '+'/'.join(parts))
    return scope,'/'.join(rel)

def inspect(path, *, digest_files=True):
    """Returns deterministic normalized file plan, or refuses entire ZIP.

    No archive extraction, no shell execution, no following ZIP-supplied symlinks.
    Files from __MACOSX are ignored but the remaining contents are validated.
    """
    with zipfile.ZipFile(path,'r') as arc:
        all_info=arc.infolist()
        if len(all_info)>MAX_FILES:raise ValueError('ZIP exceeds maximum entry count')
        entries=[]
        for z in all_info:
            if ignored_mac(z.filename):continue
            parts=clean_name(z.filename)
            file_type=(z.external_attr>>16)&0o170000
            if file_type not in (0,stat.S_IFREG,stat.S_IFDIR):
                raise ValueError('ZIP contains a link or special file')
            if z.flag_bits & 0x01:raise ValueError('Encrypted ZIP entries are not supported')
            if z.compress_type not in (zipfile.ZIP_STORED,zipfile.ZIP_DEFLATED):
                raise ValueError('Unsupported ZIP compression format')
            if not z.is_dir():entries.append((z,parts))
        if not entries:raise ValueError('ZIP has no deployable file entries')
        # One wrapper directory is allowed, never inferred from a recognized mapping root.
        prefixes={parts[0] for _,parts in entries}
        has_wrapper=(len(prefixes)==1 and all(len(parts)>1 for _,parts in entries)
                     and next(iter(prefixes)) not in (APP_DIRS|BANNED_PARTS|{'Neptune','public_html','neptune_secure'})
                     and any(parts[1] in (APP_DIRS|{'Neptune','public_html','neptune_secure'}) for _,parts in entries))
        result=[];keys=set();total=0
        for z,parts in entries:
            if has_wrapper:parts=parts[1:]
            if len(parts)==1 and parts[0].lower() in IGNORED_ROOT_FILES:
                continue
            if parts[0] in ('sql','scripts'):
                raise ValueError('SQL/scripts in package require separate review; no automatic migration')
            scope,rel=_normalize(parts)
            key=(scope+'/'+rel).lower()
            if key in keys:raise ValueError('Duplicate or case-colliding deployment targets')
            keys.add(key)
            if z.file_size>MAX_FILE:raise ValueError('File exceeds 30 MB limit')
            total+=z.file_size
            if total>MAX_EXPANDED:raise ValueError('Expanded ZIP exceeds 200 MB limit')
            record={'archive_path':z.filename,'scope':scope,'relative_path':rel,
                    'target_path':('public_html/Neptune/' if scope=='app' else 'neptune_secure/')+rel,
                    'size':z.file_size}
            if digest_files:
                h=hashlib.sha256()
                with arc.open(z,'r') as source:
                    for chunk in iter(lambda:source.read(1024*1024),b''):h.update(chunk)
                record['sha256']=h.hexdigest()
            result.append(record)
    if not result:raise ValueError('ZIP contains only documentation/metadata')
    result.sort(key=lambda r:r['target_path'])
    return result
