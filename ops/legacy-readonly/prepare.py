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
    hashes={};before={}
    names=['worker.py','source-tokens.php','db-worker.php','dba-once.py','evaluation-domains.py','bridge.py','bridge-health.py','evaluation-snapshot.php','offline-ui/structure.py','offline-ui/php-inline.php']
    for name in names:
        source=Path(__file__).parent/name;data=source.read_bytes();target=PUBLIC/name
        if target.parent!=PUBLIC:
            if target.parent.exists():
                st=target.parent.lstat();need(stat.S_ISDIR(st.st_mode) and st.st_uid==0 and not target.parent.is_symlink() and st.st_mode&0o022==0,'INSTALL_DIRECTORY_UNSAFE')
            else:target.parent.mkdir(mode=0o755)
            os.chown(target.parent,0,0);target.parent.chmod(0o755)
        if target.exists() or target.is_symlink():
            st=target.lstat();need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_nlink==1 and st.st_mode&0o022==0,'INSTALL_FILE_UNSAFE')
            need(hashlib.sha256(target.read_bytes()).hexdigest() in [previous.get(name),hashlib.sha256(data).hexdigest()],'UNREVIEWED_PUBLIC_TOOL_COLLISION')
            before[name]=hashlib.sha256(target.read_bytes()).hexdigest()
        target.write_bytes(data);os.chown(target,0,0);target.chmod(0o644);hashes[name]=hashlib.sha256(data).hexdigest()
    backup=CONFIG/'catalog.before-evaluation-read-v1.json'
    if backup.exists():
        st=backup.lstat();need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_nlink==1 and st.st_mode&0o022==0,'ORIGINAL_CATALOG_BACKUP_UNSAFE')
        metadata=json.loads(backup.read_text());need(set(metadata)=={'schema','created_at','tables'} and isinstance(metadata['tables'],dict),'ORIGINAL_CATALOG_METADATA_REQUIRED')
        hashes['original_catalog_sha256']=hashlib.sha256(backup.read_bytes()).hexdigest()
    need(not record.is_symlink(),'RUNTIME_MANIFEST_UNSAFE');record.write_text(json.dumps(hashes));record.chmod(0o640);os.chown(record,0,account.pw_gid)
    import importlib.util
    spec=importlib.util.spec_from_file_location('management_probe',Path(__file__).with_name('management-probe.py'))
    probe=importlib.util.module_from_spec(spec);spec.loader.exec_module(probe)
    absent={n:not (CONFIG/n).exists() and not (CONFIG/n).is_symlink() for n in ['credentials.json','catalog.json']}
    management=probe.run() if all(absent.values()) else {'result':'SKIPPED_EXISTING_READER_CONFIG'}
    spec=importlib.util.spec_from_file_location('reader_bridge_install',Path(__file__).with_name('bridge-install.py'));bridge=importlib.util.module_from_spec(spec);spec.loader.exec_module(bridge)
    application_bridge=bridge.install()
    return dict(result='READER_TOOLS_INSTALLED',user=USER,uid=account.pw_uid,legacy_file_changes=0,database_changes=0,db_ready=True if application_bridge.get('result')=='PASS' else None,db_readiness='VERIFIED_BY_V2_BRIDGE' if application_bridge.get('result')=='PASS' else 'VERIFY_WITH_FIXED_READER',management_probe=management,dba_tool_before_sha256=before.get('dba-once.py'),dba_tool_after_sha256=hashes['dba-once.py'],private_config_absent=absent,application_bridge=application_bridge)
if __name__=='__main__':print('LEGACY_READER_INSTALL='+json.dumps(run()))
