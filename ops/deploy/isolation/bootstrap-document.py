#!/usr/bin/env python3
"""Management-only reviewed installation document; same SSM resource, no runtime input.
Does not provision DB grants or modify web/legacy services. Those remain explicit
one-time isolation prerequisites, never silently inferred from DB/table names.
"""
import hashlib
import importlib.util
import json
import sys
from pathlib import Path
import tempfile
ROOT=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('bundle_builder',ROOT/'build-bootstrap.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

def document():
    with tempfile.TemporaryDirectory() as tmp:
        archive=Path(tmp)/'fixed.tar.gz';m.build(archive);digest=hashlib.sha256(archive.read_bytes()).hexdigest()
    hashes={name:hashlib.sha256(path.read_bytes()).hexdigest() for name,path in m.FILES.items()}
    code='''import hashlib,io,json,os,stat,tarfile,tempfile,subprocess,fcntl
from pathlib import Path
root=Path('/www/ppb-v2-deploy')
assert root.is_dir() and not root.is_symlink() and root.stat().st_uid==0 and root.stat().st_mode&0o077==0
assert not (root/'pending-deployment.json').exists()
lock=os.open(root/'.deployment.lock',os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
archive=root/'bootstrap/APPROVED_SHA/fixed.tar.gz'
assert archive.is_file() and not archive.is_symlink() and archive.stat().st_size<5000000
assert hashlib.sha256(archive.read_bytes()).hexdigest()=='APPROVED_SHA'
expected=EXPECTED_HASHES
assert (root/'backups').is_dir() and not (root/'backups').is_symlink() and (root/'backups').stat().st_uid==0 and (root/'backups').stat().st_mode&0o077==0
backup=root/'backups/fixed-tools-APPROVED_SHA'
assert not backup.is_symlink()
backup.mkdir(parents=True,mode=0o700,exist_ok=True)
with tarfile.open(archive,'r:gz') as tf:
    members=tf.getmembers()
    assert len(members)==len(expected)+1 and len({x.name for x in members})==len(members)
    assert all(x.isfile() and x.name in set(expected)|{'SHA256.json'} and x.size<1000000 for x in members)
    assert json.load(tf.extractfile('SHA256.json'))==expected
    for name,digest in expected.items():
        data=tf.extractfile(name).read();assert hashlib.sha256(data).hexdigest()==digest
        target=root/name
        current=root
        for part in Path(name).parts[:-1]:
            current=current/part
            assert not current.is_symlink()
            current.mkdir(mode=0o700,exist_ok=True)
            assert current.stat().st_uid==0 and current.stat().st_mode&0o077==0
        assert not target.is_symlink()
        if target.exists() and target.read_bytes()!=data:
            old=backup/name;old.parent.mkdir(parents=True,mode=0o700,exist_ok=True)
            if not old.exists():old.write_bytes(target.read_bytes());old.chmod(0o600)
        fd,temp=tempfile.mkstemp(prefix='.fixed-',dir=target.parent)
        with os.fdopen(fd,'wb') as out:out.write(data);out.flush();os.fsync(out.fileno())
        os.replace(temp,target)
for name,digest in expected.items():assert hashlib.sha256((root/name).read_bytes()).hexdigest()==digest
print('V2_FIXED_TOOLS_INSTALLED=YES')
print('V2_DB_AND_SERVICE_ISOLATION=NOT_YET_VERIFIED')
p=subprocess.run(['python3',str(root/'scripts/isolation/lifecycle.py'),'inventory','--commit','COMMIT_VALUE','--request-id','bootstrap-inventory-APPROVED_SHORT'],capture_output=True,text=True,timeout=60)
# The fixed inventory returns only bounded configuration metadata, never secrets.
try:
    value=json.loads(p.stdout)
    print('V2_INVENTORY='+json.dumps(value))
except Exception:print('V2_INVENTORY=UNVERIFIED')
'''.replace('APPROVED_SHA',digest).replace('APPROVED_SHORT',digest[:16]).replace('EXPECTED_HASHES',repr(hashes)).replace('COMMIT_VALUE','0'*40)
    return digest,dict(schemaVersion='2.2',description='Reviewed one-time V2 fixed-tool install; no shell input/SQL grants/live app or old-system changes',parameters={},mainSteps=[
        dict(action='aws:runShellScript',name='prepareFixedTools',inputs=dict(timeoutSeconds='30',runCommand=['set -eu','umask 077','test "$(id -u)" = 0','test -d /www/ppb-v2-deploy && test ! -L /www/ppb-v2-deploy',f'test ! -L /www/ppb-v2-deploy/bootstrap',f'install -d -m 700 /www/ppb-v2-deploy/bootstrap/{digest}'])),
        dict(action='aws:downloadContent',name='downloadReviewedTools',inputs=dict(sourceType='S3',sourceInfo=json.dumps(dict(path='https://s3.us-east-1.amazonaws.com/ppb-v2-release-artifacts-429724121147-use1/releases/bootstrap/'+digest+'/fixed.tar.gz')),destinationPath='/www/ppb-v2-deploy/bootstrap/'+digest)),
        dict(action='aws:runShellScript',name='installReviewedTools',inputs=dict(timeoutSeconds='120',runCommand=['set -eu','umask 077',"python3 - <<'FIXED_INSTALL'",*code.splitlines(),'FIXED_INSTALL']))])
if __name__=='__main__':
    digest,d=document();target=ROOT.parent/'PPB-V2-Controlled-Deploy.bootstrap.ssm.json';text=json.dumps(d,indent=2)+'\n'
    if '--check' in sys.argv:assert target.read_text()==text
    else:target.write_text(text)
    print('BOOTSTRAP_S3_KEY=releases/bootstrap/'+digest+'/fixed.tar.gz')
