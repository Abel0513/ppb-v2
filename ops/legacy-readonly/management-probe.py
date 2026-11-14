#!/usr/bin/env python3
"""Fixed root-only connection metadata probe; no password discovery or SQL writes.
Only standard root client locations are stat'ed. mysql_config_editor output and
SHOW GRANTS stay in memory and are NEVER returned, logged or saved.
"""
import os,re,stat,subprocess
from pathlib import Path
MYSQL='/www/server/mysql/bin/mysql'
EDITOR='/www/server/mysql/bin/mysql_config_editor'
FILES=['/root/.my.cnf','/etc/mysql/admin.cnf','/etc/mysql/debian.cnf','/etc/my.cnf','/etc/mysql/my.cnf']
SOCKETS=['/tmp/mysql.sock','/var/lib/mysql/mysql.sock','/run/mysqld/mysqld.sock','/www/server/mysql/mysql.sock']
NAME=re.compile(r'[A-Za-z0-9_-]{1,80}')
def invoke(args,timeout=4):
    # Environment cannot inject an alternative login file, password, shell or pager.
    env={'HOME':'/root','USER':'root','LOGNAME':'root','PATH':'/usr/bin:/bin','LC_ALL':'C'}
    # MySQL5.7 lacks --no-login-paths. Its documented test-login-file override
    # prevents loading the root login file during the single passwordless probe.
    if '--skip-password' in args:env['MYSQL_TEST_LOGIN_FILE']='/dev/null'
    try:return subprocess.run(args,capture_output=True,text=True,timeout=timeout,env=env)
    except (OSError,subprocess.TimeoutExpired):return None
def profile_names(output):
    return sorted(set(m.group(1) for m in re.finditer(r'^\[([A-Za-z0-9_-]{1,80})\]\s*$',output,re.M)))
def admin_capability(grants):
    # Conservative proof: global ALL + GRANT OPTION covers CREATE USER and every
    # column/metadata grant needed by the fixed one-time utility. Less privilege
    # may suffice, but never infer it from missing/filtered metadata.
    for line in grants:
        if re.match(r'^GRANT ALL PRIVILEGES ON \*\.\* TO ',line,re.I) and re.search(r'\bWITH GRANT OPTION\s*$',line,re.I):return True
    return False
def failure(p):
    if p is None:return 'CLIENT_UNAVAILABLE_OR_TIMEOUT'
    text=p.stderr.lower()
    if 'unknown option' in text or 'unknown variable' in text:return 'CLIENT_OPTION_UNSUPPORTED'
    code=re.search(r'\bERROR\s+(\d{3,5})\b',p.stderr,re.I)
    return 'MYSQL_ERROR_'+code.group(1) if code else 'CLIENT_FAILED_NO_SAFE_ERROR_CODE'
def connection(options,socket,method):
    args=[MYSQL,*options,'--protocol=SOCKET','--socket='+socket,'--connect-timeout=2',
          '--batch','--raw','--skip-column-names','--local-infile=0',
          '--init-command=','--skip-tee','--skip-pager',
          '--execute=SELECT VERSION(), CURRENT_USER(), @@socket; SHOW GRANTS FOR CURRENT_USER;']
    p=invoke(args)
    if p is None or p.returncode:return dict(method=method,result='UNAVAILABLE',reason=failure(p))
    lines=p.stdout.splitlines()
    if not lines:return dict(method=method,result='UNVERIFIED')
    row=lines[0].split('\t')
    if len(row)!=3 or not re.fullmatch(r'5\.7\.[0-9]+(?:-[A-Za-z0-9_.-]+)?',row[0]) or not re.fullmatch(r'[A-Za-z0-9_.-]+@[A-Za-z0-9_.:%-]+',row[1]):return dict(method=method,result='UNVERIFIED')
    if os.path.realpath(row[2])!=os.path.realpath(socket):return dict(method=method,result='SOCKET_MISMATCH')
    # Do not export raw grants: MySQL5.7 can append authentication hashes.
    capable=admin_capability(lines[1:])
    plugin='UNVERIFIED'
    if capable:
        q=invoke(args[:-1]+["--execute=SELECT plugin FROM mysql.user WHERE CONCAT(User,'@',Host)=CURRENT_USER();"])
        if q is not None and q.returncode==0 and re.fullmatch(r'[A-Za-z0-9_]{1,64}\s*',q.stdout):plugin=q.stdout.strip()
    return dict(method=method,result='USABLE' if capable else 'CONNECTED_PRIVILEGES_NOT_PROVEN',
                version=row[0],actor=row[1],transport='UNIX_SOCKET',socket=socket,
                authentication_plugin=plugin,required_privileges_proven=capable)
def run():
    if os.geteuid()!=0:return dict(result='AUTHORIZED_ROOT_REQUIRED')
    output=dict(result='METADATA_PROBE_COMPLETE',passwords_exported=False,business_queries=0,sql_writes=0,
                conventional_files=[],login_paths=[],connections=[])
    for value in FILES:
        p=Path(value)
        try:
            s=p.lstat();safe=stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_nlink==1 and s.st_mode&0o077==0
            output['conventional_files'].append(dict(path=value,root_owned=s.st_uid==0,mode=oct(stat.S_IMODE(s.st_mode)),private_regular=safe,authentication_tested=False))
        except FileNotFoundError:pass
        except OSError:output['conventional_files'].append(dict(path=value,status='STAT_UNAVAILABLE'))
    p=invoke([EDITOR,'print','--all'])
    login=Path('/root/.mylogin.cnf')
    output['standard_login_file_present']=login.exists()
    try:
        s=login.lstat();output['standard_login_file_metadata']=dict(root_owned=s.st_uid==0,mode=oct(stat.S_IMODE(s.st_mode)),regular=stat.S_ISREG(s.st_mode),symlink=stat.S_ISLNK(s.st_mode),empty=s.st_size==0)
    except OSError:pass
    output['login_path_inventory']='VERIFIED' if p is not None and p.returncode==0 else 'UNAVAILABLE'
    # Never output warnings: client warnings can include private option values.
    if p is not None:
        warning=(p.stderr+'\n'+p.stdout).lower()
        output['login_file_ignored_warning']='ignored' in warning
        output['login_file_permissions_warning']=any(x in warning for x in ['world-writable','world writable','owner','permissions'])
        output['editor_return_code']=p.returncode
        output['editor_output_empty']=not bool(p.stdout.strip() or p.stderr.strip())
        if 'ignored' in warning:output['login_path_inventory']='FILE_IGNORED_NOT_VERIFIED'
    # Some5.7 builds print the masked profile listing to stderr, not stdout.
    names=profile_names(p.stdout+'\n'+p.stderr) if p is not None and p.returncode==0 else []
    output['login_paths']=names[:16];output['login_paths_truncated']=len(names)>16
    sockets=[]
    for value in SOCKETS:
        try:
            if stat.S_ISSOCK(Path(value).stat().st_mode) and os.path.realpath(value) not in [os.path.realpath(s) for s in sockets]:sockets.append(value)
        except OSError:pass
    output['socket_candidates']=sockets
    if len(sockets)!=1:
        output['connection_probe']='EXACTLY_ONE_LOCAL_SOCKET_REQUIRED';return output
    # One explicit credentialless local root attempt, not password guessing.
    output['connections'].append(connection(['--no-defaults','--user=root','--skip-password'],sockets[0],'credentialless-root'))
    # Login paths contain only client login parameters, not arbitrary init SQL.
    for name in names[:4]:output['connections'].append(connection(['--no-defaults','--login-path='+name],sockets[0],'login:'+name))
    output['untested_login_paths']=max(0,len(names)-4)
    return output
if __name__=='__main__':
    import json
    print(json.dumps(run()))
