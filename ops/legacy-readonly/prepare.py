#!/usr/bin/env python3
"""Reviewed installer: own user/code/config only. NEVER chmod/chown/write legacy."""
import hashlib,json,os,pwd,grp,stat,subprocess
from pathlib import Path
USER='ppb-legacy-ro';PUBLIC=Path('/usr/local/lib/ppb-legacy-ro');CONFIG=Path('/etc/ppb-legacy-ro')
def need(ok,code):
    if not ok:raise RuntimeError(code)
def run():
    need(os.geteuid()==0,'INSTALL_ROOT_REQUIRED')
    try:account=pwd.getpwnam(USER)
    except KeyError:
        subprocess.run(['useradd','--system','--user-group','--no-create-home','--home-dir','/nonexistent','--shell','/usr/sbin/nologin',USER],check=True,capture_output=True)
        account=pwd.getpwnam(USER)
    need(account.pw_uid>0 and account.pw_dir=='/nonexistent' and account.pw_shell in ['/usr/sbin/nologin','/sbin/nologin'],'READER_ACCOUNT_COLLISION')
    need(grp.getgrgid(account.pw_gid).gr_name==USER,'READER_PRIMARY_GROUP_COLLISION')
    need(not any(USER in group.gr_mem and group.gr_gid!=account.pw_gid for group in grp.getgrall()),'READER_SUPPLEMENTARY_GROUP_DENIED')
    for path,mode in [(Path('/run/ppb-legacy-ro'),0o755),(PUBLIC,0o755),(CONFIG,0o750)]:
        if path.exists():
            st=path.lstat();need(stat.S_ISDIR(st.st_mode) and st.st_uid==0 and not path.is_symlink() and st.st_mode&0o022==0,'INSTALL_DIRECTORY_UNSAFE')
        else:path.mkdir(mode=mode)
        os.chown(path,0,account.pw_gid);path.chmod(mode)
    record=CONFIG/'runtime.json';previous=json.loads(record.read_text()) if record.exists() and not record.is_symlink() else {}
    hashes={}
    for name in ['worker.py','source-tokens.php','db-worker.php','dba-once.py']:
        source=Path(__file__).with_name(name);data=source.read_bytes();target=PUBLIC/name
        if target.exists() or target.is_symlink():
            st=target.lstat();need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_nlink==1 and st.st_mode&0o022==0,'INSTALL_FILE_UNSAFE')
            need(hashlib.sha256(target.read_bytes()).hexdigest() in [previous.get(name),hashlib.sha256(data).hexdigest()],'UNREVIEWED_PUBLIC_TOOL_COLLISION')
        target.write_bytes(data);os.chown(target,0,0);target.chmod(0o644);hashes[name]=hashlib.sha256(data).hexdigest()
    need(not record.is_symlink(),'RUNTIME_MANIFEST_UNSAFE');record.write_text(json.dumps(hashes));record.chmod(0o640);os.chown(record,0,account.pw_gid)
    import importlib.util
    spec=importlib.util.spec_from_file_location('management_probe',Path(__file__).with_name('management-probe.py'))
    probe=importlib.util.module_from_spec(spec);spec.loader.exec_module(probe)
    return dict(result='READER_TOOLS_INSTALLED',user=USER,uid=account.pw_uid,legacy_file_changes=0,database_changes=0,db_ready=False,management_probe=probe.run())
if __name__=='__main__':print('LEGACY_READER_INSTALL='+json.dumps(run()))
