"""Constrained update ZIP policy.

Schema 1: original web-only updates (no privileged files).
Schema 2: narrow web/bin allowlist, cryptographically verified against pinned
operator-owned Ed25519 public key. No systemd units or arbitrary scripts.
"""
from __future__ import annotations
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile
import zipfile

WEB={'web/index.html','web/app.css','web/app.js','web/server.py'}
WEB_ONLY={'web/index.html','web/app.css','web/app.js'}
TRUSTED_BIN={'bin/system_ops.py','bin/system_db.php','bin/root_console.py','bin/mission_control_ops.py','bin/mission_control_policy.py','bin/production_ops.py','bin/cleanup_ops.py','bin/github_mirror_ops.py','bin/maintenance_ops.py','bin/qa_package_policy.py'}
MANIFEST='mission-control-update.json';SIGNATURE='mission-control-update.sig'
PUBLIC_KEY=Path('/etc/neptune-release-center/mc-update-signing.pub.pem')
MAX_BYTES=8*1024*1024;MAX_TOTAL=14*1024*1024;MAX_FILE=7*1024*1024
VERSION=re.compile(r'^[A-Za-z0-9][A-Za-z0-9._+-]{0,50}$')
SHA=re.compile(r'^[a-f0-9]{64}$')

def verify_signature(manifest_bytes,sig_bytes):
 if len(sig_bytes)!=64:raise ValueError('Ed25519 signature must be exactly 64 bytes')
 if (not PUBLIC_KEY.is_file() or PUBLIC_KEY.is_symlink() or PUBLIC_KEY.stat().st_uid!=0 or
     PUBLIC_KEY.stat().st_mode&0o022):
  raise ValueError('Trusted Neptune Release Control signing public key not configured')
 with tempfile.TemporaryDirectory(prefix='neptune-sig-') as d:
  input_path=Path(d)/'manifest.json';sig=Path(d)/'manifest.sig'
  input_path.write_bytes(manifest_bytes);sig.write_bytes(sig_bytes)
  proc=subprocess.run(['openssl','pkeyutl','-verify','-pubin','-inkey',str(PUBLIC_KEY),
                       '-rawin','-in',str(input_path),'-sigfile',str(sig)],
                      capture_output=True,timeout=12)
  if proc.returncode:raise ValueError('Neptune Release Control update signature is invalid')

def inspect(path):
 path=Path(path)
 if not path.is_file() or not 0<path.stat().st_size<=MAX_BYTES:raise ValueError('Neptune Release Control ZIP must be 1 byte to 8 MiB')
 with zipfile.ZipFile(path) as z:
  infos=z.infolist()
  if len(infos)>14:raise ValueError('Too many files in Neptune Release Control ZIP')
  found={};total=0
  for info in infos:
   name=info.filename
   if name in ('web/','bin/'):continue
   if name not in (WEB|TRUSTED_BIN|{MANIFEST,SIGNATURE}):raise ValueError('Unapproved Neptune Release Control ZIP path: '+name[:120])
   if name in found or info.is_dir():raise ValueError('Duplicate or directory ZIP entry')
   ftype=(info.external_attr>>16)&0o170000
   if ftype not in (0,stat.S_IFREG):raise ValueError('Link/special entry refused')
   if info.flag_bits&1 or info.compress_type not in (zipfile.ZIP_STORED,zipfile.ZIP_DEFLATED):raise ValueError('Unsupported ZIP format')
   if info.file_size>MAX_FILE:raise ValueError('Update contains an oversized file')
   total+=info.file_size
   if total>MAX_TOTAL:raise ValueError('ZIP expands beyond 14 MiB')
   with z.open(info) as src:data=src.read(MAX_FILE+1)
   if len(data)!=info.file_size:raise ValueError('ZIP entry size mismatch')
   found[name]=(hashlib.sha256(data).hexdigest(),data)
  if MANIFEST not in found:raise ValueError('Missing Neptune Release Control update manifest')
  try:manifest=json.loads(found[MANIFEST][1])
  except (UnicodeDecodeError,ValueError):raise ValueError('Invalid update manifest')
  if (not isinstance(manifest,dict) or set(manifest)!={'kind','schema','version','files'} or
      manifest['kind']!='neptune-mission-control' or type(manifest['schema']) is not int or
      manifest['schema'] not in (1,2) or not isinstance(manifest['version'],str) or
      not VERSION.fullmatch(manifest['version'])):raise ValueError('Unsupported update manifest')
  files=manifest['files'];schema=manifest['schema'];allowed=WEB_ONLY if schema==1 else WEB|TRUSTED_BIN
  expected=set(found)-{MANIFEST}-( {SIGNATURE} if schema==2 else set() )
  if not isinstance(files,dict) or not files or set(files)!=expected or not set(files)<=allowed:
   raise ValueError('Manifest must list exactly the accepted files')
  if schema==1 and SIGNATURE in found:raise ValueError('Unexpected signature in legacy web-only ZIP')
  if schema==2:
   if SIGNATURE not in found:raise ValueError('Signed privileged update requires signature file')
   verify_signature(found[MANIFEST][1],found[SIGNATURE][1])
  for name,claimed_sha in files.items():
   if not isinstance(claimed_sha,str) or not SHA.fullmatch(claimed_sha) or claimed_sha!=found[name][0]:
    raise ValueError('Manifest checksum mismatch: '+name)
  for name in sorted(files):
   if name.endswith('.py'):
    try:compile(found[name][1],name,'exec')
    except (SyntaxError,ValueError):raise ValueError('Python syntax failed: '+name)
  return {'version':manifest['version'],'schema':schema,'verified_signature':schema==2,
          'files':[{'path':key,'sha256':found[key][0],'bytes':len(found[key][1])} for key in sorted(files)]}
