#!/usr/bin/env python3
"""Fixed root-only socket ACL. No shell, app code, SQL, credential or path arguments."""
import os
from pathlib import Path
import pwd
import socket
import stat
import struct
import sys
import time
PATH=Path('/www/wwwroot/ppbaos.com/storage/run/php-fpm.sock')
def apply(path,owner,nginx):
    fd=os.open(path,os.O_PATH|os.O_NOFOLLOW)
    try:
        st=os.fstat(fd)
        if not stat.S_ISSOCK(st.st_mode) or st.st_uid!=owner or st.st_nlink!=1:raise RuntimeError('V2_SOCKET_IDENTITY_NOT_VERIFIED')
        entries=[(0x01,6,0xffffffff),(0x02,6,nginx),(0x04,0,0xffffffff),(0x10,6,0xffffffff),(0x20,0,0xffffffff)]
        acl=struct.pack('<I',2)+b''.join(struct.pack('<HHI',*x) for x in entries)
        target='/proc/self/fd/'+str(fd)
        os.setxattr(target,'system.posix_acl_access',acl)
        if os.getxattr(target,'system.posix_acl_access')!=acl:raise RuntimeError('V2_SOCKET_ACL_READBACK_FAILED')
    finally:os.close(fd)
if __name__=='__main__':
    if os.geteuid()!=0 or len(sys.argv)!=1:raise RuntimeError('FIXED_SOCKET_ROOT_HELPER_REQUIRED')
    owner=pwd.getpwnam('ppb-v2').pw_uid;nginx=pwd.getpwnam('www').pw_uid
    for _ in range(100):
        try:
            st=PATH.lstat()
            if not stat.S_ISSOCK(st.st_mode) or st.st_uid!=owner:raise RuntimeError('V2_SOCKET_IDENTITY_NOT_VERIFIED')
            with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as probe:probe.settimeout(.2);probe.connect(str(PATH))
            apply(PATH,owner,nginx);break
        except (FileNotFoundError,ConnectionRefusedError):time.sleep(.1)
    else:raise RuntimeError('V2_SOCKET_START_TIMEOUT')
