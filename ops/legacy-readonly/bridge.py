#!/usr/bin/env python3
"""V2-only Unix-socket transport for the SAME immutable nonroot SQL reader.
No credentials to V2, source-file access, network endpoint, arbitrary SQL or shell.
"""
import hashlib,json,os,pwd,socket,stat,struct,subprocess
from pathlib import Path
PUBLIC=Path('/usr/local/lib/ppb-legacy-ro');CONFIG=Path('/etc/ppb-legacy-ro')
TABLES={'pay.pay_analyst_profiles','pay.pay_analyst_pick','pay.pay_analyst_monthly_evaluation','pay.pay_analyst_monthly_rating','pay.pay_schedule','pay.pay_attendance_record','pay.pay_video_schedule'}
def need(ok,reason):
 if not ok:raise RuntimeError(reason)
def validate(q):
 need(isinstance(q,dict),'REQUEST_SCHEMA')
 need(q.get('operation') in {'db_permissions','select','video_count','evaluation_snapshot'},'APPLICATION_OPERATION_DENIED')
 if q['operation']=='select':need(q.get('table') in TABLES,'APPLICATION_TABLE_DENIED')
 return q
def execute(q):
 validate(q);need(os.geteuid()==pwd.getpwnam('ppb-legacy-ro').pw_uid,'NONROOT_READER_REQUIRED')
 manifest=CONFIG/'runtime.json';s=manifest.lstat();need(stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_mode&0o022==0,'READER_MANIFEST_UNSAFE')
 hashes=json.loads(manifest.read_text())
 for name in ['db-worker.php','bridge.py','evaluation-snapshot.php']:
  path=PUBLIC/name;s=path.lstat();need(stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_nlink==1 and s.st_mode&0o022==0 and hashlib.sha256(path.read_bytes()).hexdigest()==hashes[name],'READER_CODE_CHANGED')
 result=subprocess.run(['/www/server/php/84/bin/php','-d','auto_prepend_file=','-d','auto_append_file=','-d','display_errors=0','-d','log_errors=0',str(PUBLIC/'db-worker.php')],input=json.dumps(q),capture_output=True,text=True,timeout=25)
 need(len(result.stdout.encode())<=1048576,'RESPONSE_LIMIT')
 value=json.loads(result.stdout);need(isinstance(value,dict),'READER_RESPONSE_SCHEMA')
 return value
def handle(connection,allowed_uid,executor=execute):
 try:
  pid,uid,gid=struct.unpack('3i',connection.getsockopt(socket.SOL_SOCKET,socket.SO_PEERCRED,12))
  need(uid==allowed_uid and uid>0,'APPLICATION_PEER_DENIED')
  connection.settimeout(5);raw=b''
  while b'\n' not in raw:
   part=connection.recv(4096);need(bool(part),'REQUEST_INCOMPLETE');raw+=part;need(len(raw)<=12000,'REQUEST_LIMIT')
  line,rest=raw.split(b'\n',1);need(not rest.strip(),'ONE_REQUEST_REQUIRED')
  out=executor(validate(json.loads(line)))
 except Exception as exc:
  reason=str(exc);out={'result':'STOP','reason':reason if reason.replace('_','').isalnum() and len(reason)<100 else 'APPLICATION_READER_UNAVAILABLE'}
 connection.sendall(json.dumps(out,separators=(',',':')).encode()+b'\n')
def serve():
 need(os.environ.get('LISTEN_PID')==str(os.getpid()) and os.environ.get('LISTEN_FDS')=='1','SOCKET_ACTIVATION_REQUIRED')
 need(os.geteuid()==pwd.getpwnam('ppb-legacy-ro').pw_uid,'NONROOT_READER_REQUIRED')
 server=socket.socket(fileno=3);allowed=pwd.getpwnam('ppb-v2').pw_uid
 while True:
  connection,address=server.accept()
  try:handle(connection,allowed)
  except (OSError,TimeoutError):pass
  finally:connection.close()
if __name__=='__main__':serve()
