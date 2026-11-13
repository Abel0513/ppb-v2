#!/usr/bin/env python3
"""One-time V2-only OS preparation. No DB, app ownership, vhost or running-service changes."""
import grp
import os
from pathlib import Path
import pwd
import subprocess
import sys
ROOT=Path('/www/ppb-v2-deploy')
def prepare():
    if os.geteuid()!=0:raise RuntimeError('ROOT_FIXED_PREPARATION_REQUIRED')
    try:account=pwd.getpwnam('ppb-v2')
    except KeyError:
        subprocess.run(['/usr/sbin/useradd','--system','--no-create-home','--home-dir','/nonexistent','--shell','/usr/sbin/nologin','--user-group','ppb-v2'],check=True,timeout=20)
        account=pwd.getpwnam('ppb-v2')
    if account.pw_uid==0 or account.pw_gid==0 or account.pw_shell not in ['/usr/sbin/nologin','/sbin/nologin']:raise RuntimeError('V2_UNIX_IDENTITY_UNSAFE')
    if grp.getgrgid(account.pw_gid).gr_name!='ppb-v2' or set(os.getgrouplist('ppb-v2',account.pw_gid))!={account.pw_gid}:raise RuntimeError('V2_GROUP_SCOPE_UNSAFE')
    # Templates remain private until DB ownership is independently certified.
    # No chown of existing live files, no FPM start and no shared nginx reload.
    print('V2_OS_ACCOUNT_PREPARED=YES')
if __name__=='__main__':prepare()
