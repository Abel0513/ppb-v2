"""Existing reviewed installer only: own V2 reader socket/service, no old services.
Updates only previously pinned own unit bytes; never overwrites a foreign unit.
"""
import hashlib,json,os,pwd,re,socket,stat,subprocess,tempfile
from pathlib import Path
CONFIG=Path('/etc/ppb-legacy-ro');SOCKET=Path('/run/ppb-v2-legacy-reader.sock')
def need(ok,reason):
 if not ok:raise RuntimeError(reason)
def units(mysql_socket):
 need(bool(re.fullmatch('/[A-Za-z0-9_./-]+',mysql_socket)) and '..' not in Path(mysql_socket).parts,'MYSQL_SOCKET_PATH_DENIED')
 return {'ppb-v2-legacy-reader.socket':'''[Unit]
Description=V2 fixed readonly database interface
[Socket]
ListenStream=/run/ppb-v2-legacy-reader.sock
SocketUser=root
SocketGroup=ppb-v2
SocketMode=0660
RemoveOnStop=true
[Install]
WantedBy=sockets.target
''','ppb-v2-legacy-reader.service':'''[Unit]
Description=V2 immutable nonroot readonly query worker
Requires=ppb-v2-legacy-reader.socket
After=ppb-v2-legacy-reader.socket
[Service]
User=ppb-legacy-ro
Group=ppb-legacy-ro
ExecStart=/usr/bin/python3 -I /usr/local/lib/ppb-legacy-ro/bridge.py
Restart=on-failure
NoNewPrivileges=true
CapabilityBoundingSet=
AmbientCapabilities=
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
PrivateDevices=true
PrivateNetwork=true
RestrictAddressFamilies=AF_UNIX
RestrictNamespaces=true
RestrictSUIDSGID=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
InaccessiblePaths=/www/wwwroot/3.231.229.26 /www/wwwroot/ppbaos.com /www/ppb-v2-deploy /www/server/data /www/server/panel -/etc/mysql -/etc/my.cnf
BindReadOnlyPaths='''+mysql_socket+''':/run/ppb-legacy-ro/mysql.sock
MemoryMax=256M
TasksMax=12
UMask=0077
'''}
def install():
 need(os.geteuid()==0,'REVIEWED_INSTALL_ROOT_REQUIRED')
 need(pwd.getpwnam('ppb-v2').pw_uid>0 and pwd.getpwnam('ppb-legacy-ro').pw_uid>0 and pwd.getpwnam('ppb-v2').pw_uid!=pwd.getpwnam('ppb-legacy-ro').pw_uid,'DISTINCT_NONROOT_IDENTITIES_REQUIRED')
 if not (CONFIG/'credentials.json').exists():return dict(result='SKIPPED_NO_READONLY_ACCOUNT')
 record=CONFIG/'bridge-install.json';old={}
 if record.exists():
  st=record.lstat();need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_mode&0o022==0,'BRIDGE_RECORD_UNSAFE');old=json.loads(record.read_text())
 need(not (SOCKET.exists() or SOCKET.is_symlink()) or bool(old),'FOREIGN_BRIDGE_SOCKET_COLLISION')
 credentials=json.loads((CONFIG/'credentials.json').read_text());mysql_socket=credentials['socket']
 need(stat.S_ISSOCK(Path(mysql_socket).stat().st_mode),'MYSQL_SOCKET_UNVERIFIED')
 values=units(mysql_socket);hashes={name:hashlib.sha256(text.encode()).hexdigest() for name,text in values.items()}
 previous={}
 for name,text in values.items():
  path=Path('/etc/systemd/system')/name
  if path.exists() or path.is_symlink():
   st=path.lstat();need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_mode&0o022==0 and hashlib.sha256(path.read_bytes()).hexdigest() in [old.get(name),hashes[name]],'FOREIGN_BRIDGE_UNIT_COLLISION')
   previous[name]=path.read_text()
 backup=CONFIG/('bridge-units-before-'+hashlib.sha256(json.dumps(previous,sort_keys=True).encode()).hexdigest()+'.json')
 if not backup.exists():backup.write_text(json.dumps(previous));backup.chmod(0o640)
 for name,text in values.items():
  path=Path('/etc/systemd/system')/name;fd,temp=tempfile.mkstemp(prefix='.ppb-bridge-',dir=path.parent)
  with os.fdopen(fd,'w') as stream:stream.write(text);stream.flush();os.fsync(stream.fileno())
  os.chmod(temp,0o644);os.replace(temp,path)
 record.write_text(json.dumps(hashes));record.chmod(0o640)
 subprocess.run(['systemctl','daemon-reload'],check=True,capture_output=True,timeout=15)
 subprocess.run(['systemctl','enable','--now','ppb-v2-legacy-reader.socket'],check=True,capture_output=True,timeout=15)
 # A previous worker must reload reviewed code; only this NEW V2-specific service.
 subprocess.run(['systemctl','reset-failed','ppb-v2-legacy-reader.service'],check=True,capture_output=True,timeout=15)
 subprocess.run(['systemctl','restart','ppb-v2-legacy-reader.service'],check=True,capture_output=True,timeout=15)
 # Root is not an application peer; prove rejection before the V2 UID smoke.
 with socket.socket(socket.AF_UNIX) as client:
  client.settimeout(10);client.connect(str(SOCKET));client.sendall(b'{"operation":"db_permissions"}\n');value=json.loads(client.recv(4096))
  need(value=={'result':'STOP','reason':'APPLICATION_PEER_DENIED'},'BRIDGE_ROOT_PEER_NOT_DENIED')
 properties=['User','ActiveState','NoNewPrivileges','ProtectSystem','CapabilityBoundingSet','PrivateNetwork']
 state=subprocess.run(['systemctl','show','ppb-v2-legacy-reader.service','--property='+','.join(properties)],capture_output=True,text=True,check=True,timeout=15).stdout
 entries=dict(line.split('=',1) for line in state.splitlines() if '=' in line)
 need(entries.get('User')=='ppb-legacy-ro' and entries.get('ActiveState')=='active' and entries.get('NoNewPrivileges')=='yes' and entries.get('ProtectSystem')=='strict' and entries.get('PrivateNetwork')=='yes' and not entries.get('CapabilityBoundingSet'),'BRIDGE_SERVICE_NOT_ISOLATED')
 p=subprocess.run(['systemd-run','--quiet','--wait','--pipe','--collect','--uid=ppb-v2','--gid=ppb-v2','--property=NoNewPrivileges=true','--property=CapabilityBoundingSet=','--property=PrivateTmp=true','--','/usr/bin/python3','-I','/usr/local/lib/ppb-legacy-ro/bridge-health.py'],capture_output=True,text=True,timeout=30)
 result=json.loads(p.stdout);need(p.returncode==0 and result.get('result')=='PASS','BRIDGE_V2_PEER_QUERY_FAILED')
 return dict(result='PASS',application_probe=result,root_peer_denied=True,service_user='ppb-legacy-ro',unit_sha256=hashes,legacy_writes=0,old_services_modified=False)
