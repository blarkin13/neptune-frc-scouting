#!/usr/bin/env python3
"""Conservative DAC access policy for neptuneqa when systemd forbids runuser.
No impersonation; abort on symlinks, POSIX ACLs or unknown path types.
Does not replace auditing PHP-FPM pool/sandbox, DB privileges, or credentials.
"""
import errno, os, pwd, stat, sys
from pathlib import Path
USER='neptuneqa'
PROD=Path('/var/www/neptune')

def stop(s): raise SystemExit('STOP: QA isolation check: '+s)

def ancestors(path):
    path=Path(path)
    if not path.is_absolute(): stop('nonabsolute path')
    return [Path('/'), *[Path('/').joinpath(*path.parts[1:i+1]) for i in range(1,len(path.parts))]]

def get_st(path):
    st=os.lstat(path)
    if stat.S_ISLNK(st.st_mode):stop('symbolic link in protected path: '+str(path))
    if not (stat.S_ISDIR(st.st_mode) or stat.S_ISREG(st.st_mode)):stop('nonregular protected path: '+str(path))
    for name in ('system.posix_acl_access','system.posix_acl_default'):
        try:os.getxattr(path,name,follow_symlinks=False)
        except OSError as ex:
            if ex.errno not in (errno.ENODATA,errno.ENOTSUP,errno.EOPNOTSUPP):stop('cannot validate ACL: '+str(path))
        else: stop('ACL needs direct neptuneqa user check: '+str(path))
    return st

def permit(st,uid,gids,mask):
    # Kernel DAC mode-bits for a nonroot user without DAC-bypass capabilities.
    shift=6 if st.st_uid==uid else 3 if st.st_gid in gids else 0
    bits=(st.st_mode>>shift)&7
    return (bits&mask)==mask

def accessible(path,uid,gids,mask):
    paths=ancestors(path)
    for parent in paths[:-1]:
        st=get_st(parent)
        if not stat.S_ISDIR(st.st_mode):stop('non-directory ancestor '+str(parent))
        if not permit(st,uid,gids,1):return False
    st=get_st(paths[-1])
    return permit(st,uid,gids,mask)

def verify(uid=None,gids=None,prod=PROD):
    if uid is None:
        account=pwd.getpwnam(USER)
        uid=account.pw_uid
        gids=set(os.getgrouplist(USER,account.pw_gid))
    if uid==0:stop('QA user is root')
    if accessible(prod/'public_html/Neptune',uid,gids,3):stop('QA could write production app directory')
    for path in (prod/'neptune_secure/config.php',Path('/etc/scout/db.env'),Path('/etc/scout/neptune.env')):
        if accessible(path,uid,gids,4):stop('QA could read protected secret: '+str(path))
    print('PASS: QA isolation enforced by restrictive owner/group/mode bits (ACL-free paths)')

if __name__=='__main__':
    if len(sys.argv)!=1:stop('no arguments accepted')
    try:verify()
    except (OSError,KeyError) as ex:stop(str(ex))
