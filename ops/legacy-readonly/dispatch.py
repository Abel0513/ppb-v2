#!/usr/bin/env python3
"""Fixed root dispatcher: creates a mount/UID boundary, never reads app content."""
import base64,hashlib,json,os,pwd,re,stat,subprocess,sys
from pathlib import Path
LEGACY=Path('/www/wwwroot/3.231.229.26');PUBLIC=Path('/usr/local/lib/ppb-legacy-ro');USER='ppb-legacy-ro'
DENY=re.compile(r'(^|/)(?:\.[^/]*|conf|config|runtime|vendor|node_modules|uploads?|backups?|logs?|cache|storage|certs?|keys?)(/|$)|(?:db|line|database|password|secret|credential|token|auth|backup|dump|certificate|private)[^/]*\.(?:php|json|ini|cnf|sql|pem|key|zip|gz)$',re.I)
def need(ok,code):
    if not ok:raise RuntimeError(code)
def command(q,denied,socket=None):
    properties=['User='+USER,'Group='+USER,'ProtectSystem=strict','ProtectHome=true','PrivateTmp=true','PrivateDevices=true','PrivateNetwork=true','NoNewPrivileges=true','CapabilityBoundingSet=','AmbientCapabilities=','RestrictSUIDSGID=true','RestrictNamespaces=true','ProtectKernelTunables=true','ProtectKernelModules=true','ProtectControlGroups=true','UMask=0077','MemoryMax=256M','TasksMax=12','RuntimeMaxSec=60','RestrictAddressFamilies=AF_UNIX','BindReadOnlyPaths='+str(LEGACY),'InaccessiblePaths=/www/wwwroot/ppbaos.com /www/ppb-v2-deploy /www/server/data /www/server/panel -/etc/mysql -/etc/my.cnf '+ ' '.join('-'+p for p in denied)]
    if socket:properties.append('BindReadOnlyPaths='+socket+':/run/ppb-legacy-ro/mysql.sock')
    return ['systemd-run','--quiet','--wait','--pipe','--collect',*['--property='+p for p in properties],'--','/usr/bin/python3','-I',str(PUBLIC/'worker.py')]
def run(q):
    need(os.geteuid()==0,'FIXED_DISPATCH_ROOT_REQUIRED')
    account=pwd.getpwnam(USER);need(account.pw_uid>0,'NONROOT_READER_REQUIRED')
    need(LEGACY.is_dir() and not LEGACY.is_symlink(),'LEGACY_ROOT_UNSAFE')
    manifest=Path('/etc/ppb-legacy-ro/runtime.json');st=manifest.lstat();need(st.st_uid==0 and not manifest.is_symlink() and st.st_mode&0o022==0,'RUNTIME_MANIFEST_UNSAFE')
    hashes=json.loads(manifest.read_text())
    for name in ['worker.py','source-tokens.php','db-worker.php','dba-once.py']:
        p=PUBLIC/name;s=p.lstat();need(stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_nlink==1 and s.st_mode&0o022==0 and hashlib.sha256(p.read_bytes()).hexdigest()==hashes[name],'READER_CODE_CHANGED')
    # Filename-only inventory creates OS-level deny mounts; no secret file opened.
    denied=[]
    for base,dirs,files in os.walk(LEGACY,followlinks=False):
        need(len(denied)<3000,'CREDENTIAL_MOUNT_LIMIT')
        rel=Path(base).relative_to(LEGACY).as_posix()
        for name in list(dirs)+files:
            p=Path(base)/name
            if p.is_symlink() or DENY.search((rel+'/' if rel!='.' else '')+name):
                need(not any(x.isspace() for x in str(p)),'UNSAFE_MOUNT_PATH')
                denied.append(str(p))
                if name in dirs:dirs.remove(name)
    check=subprocess.run(command({'operation':'permissions'},denied),input='{"operation":"permissions"}',capture_output=True,text=True,timeout=70)
    proof=json.loads(check.stdout);need(check.returncode==0 and proof.get('result')=='PASS' and proof['data'].get('legacy_mount_readonly') is True,'OS_READONLY_NOT_VERIFIED')
    socket=None
    if q.get('operation') in {'db_permissions','schema','select'}:
        p=Path('/etc/ppb-legacy-ro/credentials.json');need(p.is_file() and not p.is_symlink() and p.stat().st_uid==0 and p.stat().st_mode&0o027==0,'READONLY_DBA_PROVISIONING_REQUIRED')
        config=json.loads(p.read_text());socket=config.get('socket','')
        need(config.get('user')=='ppb_legacy_ro' and isinstance(socket,str) and socket.startswith('/') and not any(c.isspace() for c in socket) and stat.S_ISSOCK(Path(socket).stat().st_mode),'DB_SOCKET_NOT_VERIFIED')
    result=subprocess.run(command(q,denied,socket),input=json.dumps(q),capture_output=True,text=True,timeout=70)
    value=json.loads(result.stdout);need(result.returncode==0 and value.get('result')=='PASS','READER_OPERATION_STOP')
    return dict(result='PASS',os_boundary=proof['data'],data=value['data'],legacy_file_writes=0,legacy_db_writes=0)
if __name__=='__main__':
    try:
        encoded=os.environ['SSM_LegacyRequest'];need(len(encoded)<=14000,'REQUEST_TOO_LARGE')
        raw=base64.b64decode(encoded,validate=True);need(len(raw)<=10000,'REQUEST_TOO_LARGE')
        q=json.loads(raw);print('PPB_LEGACY_READONLY_V1='+json.dumps(run(q),ensure_ascii=False,separators=(',',':')))
    except Exception as exc:
        code=str(exc);print('PPB_LEGACY_READONLY_V1='+json.dumps(dict(result='STOP',reason=code if re.fullmatch('[A-Z0-9_]{1,100}',code) else 'READONLY_BOUNDARY_NOT_VERIFIED')));raise SystemExit(2)
