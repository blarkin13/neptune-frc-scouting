#!/usr/bin/env python3
"""Generate read-only Neptune production/QA file+schema comparisons. Never query row data."""
from __future__ import annotations
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

PROD = Path('/var/www/neptune')
QA = Path('/var/www/neptune-qa')
OUT_DIR = Path('/var/lib/neptune-release-center/reports')
PROD_DB = 'scouting'
QA_DB = 'neptune_qa'
SCOPES = ('public_html/Neptune', 'neptune_secure')
IGNORED_DIRS = {'uploads','games','spot_media','maintenance-backups','maintenance-uploads',
                'file-manager-backups','backups','sessions','.git','.svn','__pycache__','vendor'}
IGNORED_FILENAMES = {'config.php','.env','.DS_Store','Thumbs.db'}
IGNORED_SUFFIXES = ('.env','.zip','.sql','.sql.gz','.log','.key','.pem','.bak','.orig','.swp','.pid')
EXPECTED_QA_VARIANCE = {
    'public_html/Neptune/admin/file-manager.php',
    'public_html/Neptune/admin/maintenance.php',
    'neptune_secure/google-auth.php'
}


def inventory(root: Path) -> dict[str, str]:
    if root.is_symlink() or not root.is_dir():
        raise RuntimeError(f'Invalid application root {root}')
    results = {}
    for scope in SCOPES:
        base = root / scope
        if base.is_symlink() or not base.is_dir():
            raise RuntimeError(f'Missing application tree: {base}')
        for dirpath, dirs, files in os.walk(base, followlinks=False):
            dirs[:] = [d for d in dirs if d not in IGNORED_DIRS
                        and not (Path(dirpath) / d).is_symlink()]
            for filename in files:
                if (filename in IGNORED_FILENAMES or filename.startswith('.') and filename != '.htaccess'
                    or any(filename.endswith(s) for s in IGNORED_SUFFIXES)
                    or '.backup' in filename or '.before' in filename):
                    continue
                path = Path(dirpath) / filename
                if path.is_symlink() or not path.is_file():
                    continue
                rel = path.relative_to(root).as_posix()
                digest = hashlib.sha256()
                try:
                    with path.open('rb') as f:
                        for chunk in iter(lambda: f.read(1024 * 1024), b''):
                            digest.update(chunk)
                except PermissionError:
                    # Secret/private files should never become visible as content or hashes.
                    continue
                results[rel] = digest.hexdigest()
    return results


def schema(db: str) -> dict[str, str]:
    if db not in (PROD_DB, QA_DB):
        raise RuntimeError('Database is not on the explicit allowlist')
    # Never access application credentials; mysql root uses the local Unix socket.
    # No row data or column defaults are retrieved.
    query = r'''
SELECT CONCAT('T\t',TABLE_NAME,'\t',TABLE_TYPE,'\t',COALESCE(ENGINE,''))
FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
UNION ALL
SELECT CONCAT('C\t',TABLE_NAME,'\t',LPAD(ORDINAL_POSITION,5,'0'),'\t',COLUMN_NAME,'\t',COLUMN_TYPE,'\t',IS_NULLABLE,'\t',EXTRA)
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
UNION ALL
SELECT CONCAT('I\t',TABLE_NAME,'\t',INDEX_NAME,'\t',LPAD(SEQ_IN_INDEX,5,'0'),'\t',COALESCE(COLUMN_NAME,''),'\t',NON_UNIQUE)
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
ORDER BY 1'''
    run = subprocess.run(['mysql', '--batch', '--raw', '--skip-column-names',
                          '--database='+db, '--execute='+query],
                         capture_output=True, text=True, check=True, timeout=45)
    grouped: dict[str, list[str]] = {}
    for line in run.stdout.splitlines():
        cells = line.split('\\t', 2) if '\\t' in line else line.split('\t', 2)
        if len(cells) < 3:
            raise RuntimeError('Unexpected MySQL metadata format')
        kind, name, rest = cells
        if kind not in ('T','C','I'):
            raise RuntimeError('Unexpected schema metadata type')
        grouped.setdefault(name, []).append(f'{kind}\t{rest}')
    return {name: hashlib.sha256('\n'.join(sorted(rows)).encode()).hexdigest()
            for name,rows in grouped.items()}


def build_report(prod: dict[str,str], qa: dict[str,str], ps: dict[str,str], qs: dict[str,str]) -> dict:
    files=[]
    totals={'identical':0,'different':0,'only_production':0,'only_qa':0,'expected_qa_variance':0}
    for name in sorted(prod.keys() | qa.keys()):
        if name not in qa: status='only_production'
        elif name not in prod: status='only_qa'
        elif prod[name] == qa[name]: status='identical'
        elif name in EXPECTED_QA_VARIANCE: status='expected_qa_variance'
        else: status='different'
        totals[status] += 1
        files.append({'path':name,'status':status,'prod_sha256':prod.get(name), 'qa_sha256':qa.get(name)})
    table_counts={'identical':0,'different':0,'only_production':0,'only_qa':0}
    tables=[]
    for name in sorted(ps.keys() | qs.keys()):
        status=('only_production' if name not in qs else 'only_qa' if name not in ps else
                'identical' if ps[name] == qs[name] else 'different')
        table_counts[status]+=1
        tables.append({'table':name,'status':status})
    return {
        'report_format':1,
        'generated_at':dt.datetime.now(dt.timezone.utc).isoformat(),
        'production_root':str(PROD),'qa_root':str(QA),
        'production_db':PROD_DB,'qa_db':QA_DB,
        'file_summary':totals,'file_items':files,
        'schema_summary':table_counts,'schema_items':tables,
        'prod_manifest':prod,'qa_manifest':qa,
        'notes':['Secrets, uploads, game content and backup archives are excluded from file hashing.',
                 'The schema comparison includes table, column and index definitions, not row contents.',
                 'QA-specific file differences are explicitly classified as expected variation.',
                 'A successful comparison does not prove application or data isolation.']
    }


def main() -> None:
    if os.geteuid() != 0:
        raise RuntimeError('Run this read-only report generator as root via systemd/SSM')
    out_dir = Path(os.environ.get('NEPTUNE_RC_REPORT_DIR', str(OUT_DIR)))
    if PROD.resolve() == QA.resolve():
        raise RuntimeError('QA and production resolve to same path')
    prod,qa=inventory(PROD),inventory(QA)
    ps,qs=schema(PROD_DB),schema(QA_DB)
    data=build_report(prod,qa,ps,qs)
    out_dir.mkdir(mode=0o755, parents=True, exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.neptune-compare-',dir=out_dir)
    try:
        with os.fdopen(fd,'w',encoding='utf-8') as f:
            json.dump(data,f,separators=(',',':'))
            f.write('\n')
        os.chmod(tmp,0o644)
        os.replace(tmp,out_dir/'latest.json')
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
    print('REPORT READY:',out_dir/'latest.json')
    print('FILES:', data['file_summary'])
    print('SCHEMA:', data['schema_summary'])

if __name__=='__main__':
    try:main()
    except Exception as e:
        print(f'ERROR: {e}',file=sys.stderr)
        sys.exit(1)
