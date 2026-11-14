#!/usr/bin/env python3
"""Existing DBA's interactive LOCAL management session only. No login-path needed.
Never run via noninteractive Actions/SSM parameters or supply passwords to Codex.
"""
import getpass,hashlib,json,os,re,subprocess,sys,tempfile
from pathlib import Path
def quote(value):
    return '"'+value.replace('\\','\\\\').replace('"','\\"').replace('\n','\\n').replace('\r','\\r').replace('\t','\\t')+'"'
def run(apply=False):
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
        allowed={'result','reason','stage','mysql_error_code','sqlstate','schema','tables','password_exported','old_business_write_statements','new_principal','version','actor','required_privileges_proven','readonly_account_absent','private_files_absent','init_command','sql_writes'}
        # Default is READ ONLY. Apply requires an explicit flag, and always repeats
        # the real preflight with the same local credential before any mutation.
        modes=[['--preflight-only']]+([[]] if apply else [])
        for mode in modes:
            p=subprocess.run(['python3',str(helper),'--defaults-file',name,*mode],capture_output=True,text=True,timeout=120)
            try:receipt=json.loads(p.stdout)
            except ValueError:raise RuntimeError('DBA_RESULT_UNVERIFIED_DO_NOT_RETRY')
            print(json.dumps({k:v for k,v in receipt.items() if k in allowed}),flush=True)
            if p.returncode:return p.returncode
            if mode and receipt.get('result')!='READONLY_DBA_PREFLIGHT_PASS':raise RuntimeError('DBA_PREFLIGHT_NOT_VERIFIED')
        return 0
    finally:
        if Path(name).exists():Path(name).unlink()
if __name__=='__main__':
    import argparse
    parser=argparse.ArgumentParser();parser.add_argument('--apply',action='store_true');args=parser.parse_args()
    try:raise SystemExit(run(args.apply))
    except Exception as exc:
        code=str(exc);print(json.dumps(dict(result='BLOCKED',stage='LOCAL_INTERACTIVE_OR_RECEIPT',reason=code if re.fullmatch('[A-Z0-9_]{1,100}',code) else 'EXISTING_DBA_LOCAL_SETUP_NOT_VERIFIED')));raise SystemExit(2)
