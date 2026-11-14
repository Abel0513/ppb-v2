#!/usr/bin/env python3
"""Existing DBA's interactive LOCAL management session only. No login-path needed.
Never run via noninteractive Actions/SSM parameters or supply passwords to Codex.
"""
import getpass,hashlib,json,os,re,subprocess,sys,tempfile
from pathlib import Path
def quote(value):
    return '"'+value.replace('\\','\\\\').replace('"','\\"').replace('\n','\\n').replace('\r','\\r').replace('\t','\\t')+'"'
def run():
    if os.geteuid()!=0 or not sys.stdin.isatty():raise RuntimeError('EXISTING_INTERACTIVE_OS_AND_DBA_SESSION_REQUIRED')
    helper=Path('/usr/local/lib/ppb-legacy-ro/dba-once.py')
    expected=Path(__file__).with_name('dba-once.py')
    if not helper.is_file() or helper.is_symlink() or hashlib.sha256(helper.read_bytes()).digest()!=hashlib.sha256(expected.read_bytes()).digest():raise RuntimeError('INSTALLED_FIXED_DBA_TOOL_MISMATCH')
    user=input('Existing authorized MySQL DBA user (no default): ').strip()
    if not re.fullmatch('[A-Za-z0-9_.-]{1,32}',user):raise RuntimeError('EXPLICIT_EXISTING_DBA_USER_REQUIRED')
    password=getpass.getpass('Existing DBA password (local input only, not saved in history): ')
    if not password or '\x00' in password:raise RuntimeError('EXISTING_DBA_AUTHENTICATION_REQUIRED')
    fd,name=tempfile.mkstemp(prefix='.ppb-dba-once-',suffix='.cnf',dir='/root')
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,'w') as stream:
            stream.write('[client]\nuser='+quote(user)+'\npassword='+quote(password)+'\nsocket=/tmp/mysql.sock\n');stream.flush();os.fsync(stream.fileno())
        del password
        p=subprocess.run(['python3',str(helper),'--defaults-file',name],capture_output=True,text=True,timeout=120)
        try:receipt=json.loads(p.stdout)
        except ValueError:raise RuntimeError('DBA_RESULT_UNVERIFIED_DO_NOT_RETRY')
        allowed={'result','reason','schema','tables','password_exported','old_business_write_statements','new_principal'}
        print(json.dumps({k:v for k,v in receipt.items() if k in allowed}))
        return p.returncode
    finally:
        if Path(name).exists():Path(name).unlink()
if __name__=='__main__':
    try:raise SystemExit(run())
    except Exception:
        print('{"result":"BLOCKED","reason":"EXISTING_DBA_LOCAL_SETUP_NOT_VERIFIED"}');raise SystemExit(2)
