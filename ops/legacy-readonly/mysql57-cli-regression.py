#!/usr/bin/env python3
"""Real5.7.44 client/server, socket only, tmpfs system schemas only. No test DB.
Fails rather than skips if Docker/image/client/version is unavailable.
"""
import importlib.util,json,subprocess,time,uuid
from pathlib import Path
spec=importlib.util.spec_from_file_location('dba_once',Path(__file__).with_name('dba-once.py'))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def command(args,timeout=120,**kwargs):
 return subprocess.run(args,capture_output=True,text=True,timeout=timeout,**kwargs)
def check(ok,reason):
 if not ok:raise RuntimeError(reason)
def run():
 name='ppb-cli57-'+uuid.uuid4().hex[:12];image='mysql:5.7.44@sha256:4bc6bc963e6d8443453676cae56536f4b8156d78bae03c0145cbe47c2aad73bb'
 try:
  p=command(['docker','run','-d','--name',name,'--network=none','--tmpfs','/var/lib/mysql:rw,nosuid,size=768m','-e','MYSQL_ALLOW_EMPTY_PASSWORD=yes',image,'--skip-networking'])
  check(p.returncode==0,'MYSQL57_CONTAINER_START_FAILED')
  args=['docker','exec','-i',name,*m.cli_args('mysql',['--no-defaults','--user=root','--skip-password'])]
  for _ in range(60):
   ready=command(args,input="SELECT 'CLI_PROBE_OK',VERSION(),CURRENT_USER();\n",timeout=5)
   if ready.returncode==0 and 'CLI_PROBE_OK\t5.7.44\troot@localhost' in ready.stdout:break
   time.sleep(1)
  else:raise RuntimeError('MYSQL57_SOCKET_NOT_READY')
  empty=[x if not x.startswith('--init-command=') else '--init-command=' for x in args]
  bad=command(empty,input='SELECT 42;\n',timeout=5)
  check(bad.returncode!=0 and 'ERROR 1065 (42000)' in bad.stderr,'EMPTY_INIT_1065_NOT_REPRODUCED')
  good=command(args,input="SELECT 'CLI_PROBE_OK',VERSION(),CURRENT_USER();\nSELECT COUNT(*) FROM mysql.user WHERE User='ppb_legacy_ro';\nSHOW DATABASES;\n",timeout=5)
  check(good.returncode==0,'SELECT1_INIT_FAILED')
  rows=good.stdout.strip().splitlines()
  check(rows[0]=='CLI_PROBE_OK\t5.7.44\troot@localhost' and rows[1]=='0','CLI_RESULT_POLLUTED_OR_ACCOUNT_CHANGED')
  check(set(rows[2:])=={'information_schema','mysql','performance_schema','sys'},'UNEXPECTED_NON_SYSTEM_DATABASE')
  stopped=command(args,input="SELECT definitely_missing_metadata_symbol; SELECT 'MUST_NOT_CONTINUE';\n",timeout=5)
  check(stopped.returncode!=0 and 'MUST_NOT_CONTINUE' not in stopped.stdout,'CLI_DID_NOT_STOP_AFTER_ERROR')
  version=command(['docker','exec',name,'mysql','--version'],timeout=5)
  check(version.returncode==0 and '5.7.44' in version.stdout,'CLIENT_VERSION_MISMATCH')
  # Exercise production subprocess error classification against the REAL client.
  def real_client(argv,**kwargs):
   return command(['docker','exec','-i',name,*argv],timeout=5,input=kwargs['input'])
  session=m.sql_session('mysql',['--no-defaults','--user=root','--skip-password'],None,real_client)
  check(session('REAL_QUERY','SELECT 42;').strip()=='42','PRODUCTION_SESSION_RESULT_MISMATCH')
  try:session('REAL_ERROR','SELECT definitely_missing_metadata_symbol;')
  except m.SQLFailure as exc:
   error=m.public_error(exc);check(error.get('stage')=='REAL_ERROR' and error.get('mysql_error_code')==1054 and error.get('sqlstate')=='42S22','REAL_ERROR_DIAGNOSTIC_MISMATCH')
  else:raise RuntimeError('SQL_ERROR_DID_NOT_STOP')
  inspect=command(['docker','image','inspect','--format','{{index .RepoDigests 0}}',image])
  check(inspect.returncode==0,'IMAGE_DIGEST_UNVERIFIED')
  print(json.dumps(dict(result='MYSQL57_REAL_CLI_PASS',client_server_version='5.7.44',empty_init_error=1065,fixed_init=m.INIT_COMMAND,init_result_pollution=False,safe_error_code=1054,application_databases_created=0,business_writes=0,image=inspect.stdout.strip())))
 finally:command(['docker','rm','-f',name],timeout=30)
if __name__=='__main__':
 try:run()
 except Exception as exc:
  reason=str(exc);print(json.dumps(dict(result='FAIL',reason=reason if reason.replace('_','').isalnum() and len(reason)<100 else 'CLI_REGRESSION_NOT_VERIFIED')));raise SystemExit(2)
