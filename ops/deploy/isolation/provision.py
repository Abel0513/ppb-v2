#!/usr/bin/env python3
"""Reviewed one-time V2-only FPM cutover. No DB writes, shared FPM changes or arbitrary input."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import time
ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('lifecycle',ROOT/'lifecycle.py');l=importlib.util.module_from_spec(s);s.loader.exec_module(l);b=l.b
LIVE=Path(b.LIVE);PRIVATE=Path(b.PRIVATE)
VHOSTS=[Path(x) for x in l.VHOSTS]
PHP_LOCATION='''location ~ [^/]\\.php(/|$) {
    try_files $uri =404;
    fastcgi_pass unix:/www/wwwroot/ppbaos.com/storage/run/php-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
'''
ALLOWED_SNIPPETS={
 '/www/server/panel/vhost/nginx/well-known/v2.ppbaos.com.conf',
 '/www/server/panel/vhost/rewrite/v2.ppbaos.com.conf',
 '/www/server/panel/vhost/nginx/extension/v2.ppbaos.com/*.conf'}

def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def checked_file(p):
    st=p.lstat();b.need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_mode&0o022==0,'CONFIG_FILE_NOT_ROOT_OWNED')
    return p.read_text()
def flatten(text,depth=0):
    b.need(depth<6,'VHOST_INCLUDE_DEPTH')
    def include(m):
        name=m.group(1).strip()
        if name=='enable-php-84.conf':return PHP_LOCATION
        if name in {'fastcgi_params','/www/server/nginx/conf/fastcgi_params'}:return 'include '+name+';'
        b.need(name in ALLOWED_SNIPPETS,'UNREVIEWED_V2_INCLUDE')
        files=sorted(Path('/').glob(name.lstrip('/'))) if '*' in name else [Path(name)]
        return '\n'.join(flatten(checked_file(p),depth+1) for p in files if p.exists())
    text=re.sub(r'(?m)^\s*include\s+([^;\n]+);',include,text)
    if depth==0 and re.search(r'\broot\s+/www/wwwroot/ppbaos\.com/public\s*;',text) and not re.search(r'\blocation\s+(?:(?:\^~|=)\s+)?/\s*\{',text):
        b.need(PHP_LOCATION in text,'V2_PHP_LOCATION_MISSING')
        text=text.replace(PHP_LOCATION,'location / { try_files $uri $uri/ /index.php?$query_string; }\n'+PHP_LOCATION,1)
    b.need(not re.search(r'\b(?:proxy_pass|content_by_lua|perl|root\s+/www/wwwroot/3\.231\.229\.26)\b',text),'V2_VHOST_UNREVIEWED_UPSTREAM')
    return text

def runtime_permissions(path,uid,gid,mode,root=LIVE):
    # Walk with openat/O_NOFOLLOW, so runtime symlinks cannot redirect root chown.
    parts=path.relative_to(root).parts
    fd=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:
        for i,part in enumerate(parts):
            flags=os.O_RDONLY|os.O_NOFOLLOW
            if i<len(parts)-1:flags|=os.O_DIRECTORY
            child=os.open(part,flags,dir_fd=fd);os.close(fd);fd=child
        st=os.fstat(fd)
        b.need(stat.S_ISDIR(st.st_mode) or (stat.S_ISREG(st.st_mode) and st.st_nlink==1),'RUNTIME_HARDLINK_OR_TYPE_UNSAFE')
        os.fchown(fd,uid,gid);os.fchmod(fd,mode)
    finally:os.close(fd)

def code_snapshot(root=LIVE):
    result={}
    for name in ['app','bootstrap','config','routes','resources','vendor','public','artisan']:
        start=root/name
        if not start.exists() or start.is_symlink():continue
        for base,dirs,files in os.walk(start,followlinks=False) if start.is_dir() else []:
            base=Path(base)
            dirs[:]=[x for x in dirs if not (base/x).is_symlink() and (base/x).relative_to(root).as_posix() not in {'bootstrap/cache','public/uploads','public/storage'}]
            for path in [base,*[base/x for x in files]]:
                if path.is_symlink():continue
                st=path.lstat();b.need(stat.S_ISDIR(st.st_mode) or (stat.S_ISREG(st.st_mode) and st.st_nlink==1),'CODE_PATH_TYPE_OR_LINK_UNSAFE')
                b.need(len(result)<40000,'CODE_PERMISSION_SNAPSHOT_LIMIT')
                result[str(path)]=(st.st_mode,st.st_uid,st.st_gid)
        if start.is_file():
            st=start.lstat();b.need(st.st_nlink==1,'CODE_HARDLINK_UNSAFE');result[str(start)]=(st.st_mode,st.st_uid,st.st_gid)
    return result

def normalize_code(snapshot,gid,root=LIVE,owner=0):
    for name,(mode,uid,oldgid) in snapshot.items():
        path=Path(name);public=path.relative_to(root).parts[0]=='public'
        wanted=(0o755 if public else 0o750) if stat.S_ISDIR(mode) else (0o644 if public else 0o640)
        runtime_permissions(path,owner,gid,wanted,root=root)

def command(args,code,timeout=60):
    p=subprocess.run(args,capture_output=True,text=True,timeout=timeout)
    if p.returncode:
        # Only fixed service/config diagnostics; never app/DB output to GitHub.
        (PRIVATE/'provision-diagnostics.json').write_text(json.dumps({'stage':code,'returncode':p.returncode,'stdout':p.stdout,'stderr':p.stderr}))
        os.chmod(PRIVATE/'provision-diagnostics.json',0o600)
        raise RuntimeError(code)
    return p

def health():
    spec=importlib.util.spec_from_file_location('fixed_acceptance',PRIVATE/'automation/accept-v2.py');a=importlib.util.module_from_spec(spec);spec.loader.exec_module(a)
    result=[]
    for path in ['/admin/login','/admin','/admin/games','/admin/accounts']:
        status,headers,body=a.get(path)
        (PRIVATE/'provision-http.json').write_text(json.dumps({'path':path,'status':status,'body_sha256':hashlib.sha256(body).hexdigest()}))
        if path=='/admin/login':b.need(status==200 and b'password' in body and b'username' in body,'V2_LOGIN_HTTP_CHECK_FAILED')
        else:b.need(status in {301,302,303,307,308} and '/admin/login' in headers.get('location',''),'V2_GUEST_REDIRECT_CHECK_FAILED')
        result.append({'path':path,'status':status})
    return result

def certify_inert_extension():
    policy=b.private_json(l.POLICY)
    if policy['version']!=2:return False
    receipt=b.private_json(PRIVATE/'v2-service-isolation.json');name=receipt['backup']
    b.need(bool(re.fullmatch('v2-fpm-cutover-[0-9]+',name)),'CUTOVER_BACKUP_ID_INVALID')
    expected={str(path):flatten((PRIVATE/'backups'/name/('original-'+str(i))).read_text()) for i,path in enumerate(VHOSTS)}
    b.need(l.vhost_digest(texts=expected)==policy['vhost_sha256'],'REVIEWED_BACKUP_POLICY_MISMATCH')
    # A real directive/value change cannot be certified by this operation.
    canonical=l.vhost_digest(canonical=True,texts=expected)
    b.need(l.vhost_digest(canonical=True)==canonical,'VHOST_EXPANDED_CONFIGURATION_CHANGED')
    backup=PRIVATE/'backups'/('policy-v2-before-inert-extension-'+str(time.time_ns())+'.json')
    l.atomic(backup,policy)
    upgraded=dict(policy,version=3,vhost_sha256=canonical)
    l.atomic(l.POLICY,upgraded)
    try:l.audit()
    except Exception:l.atomic(l.POLICY,policy);raise
    return True

def repair_reviewed_http_vhost():
    # Exact observed V2-only drift. No arbitrary fingerprint approval or path input.
    receipt=b.private_json(PRIVATE/'v2-service-isolation.json')
    name=receipt['backup'];b.need(bool(re.fullmatch('v2-fpm-cutover-[0-9]+',name)),'CUTOVER_BACKUP_ID_INVALID')
    policy=b.private_json(l.POLICY)
    expected={path:flatten((PRIVATE/'backups'/name/('original-'+str(i))).read_text()) for i,path in enumerate(VHOSTS)}
    fingerprint=hashlib.sha256(json.dumps({str(p):hashlib.sha256(text.encode()).hexdigest() for p,text in expected.items()},sort_keys=True).encode()).hexdigest()
    b.need(fingerprint==policy['vhost_sha256'],'REVIEWED_BACKUP_POLICY_MISMATCH')
    target=VHOSTS[1];current=checked_file(target)
    b.need(digest(VHOSTS[0])==hashlib.sha256(expected[VHOSTS[0]].encode()).hexdigest(),'HTTPS_VHOST_UNREVIEWED_DRIFT')
    if current==expected[target]:return {'result':'NO_CHANGE_REQUIRED'}
    b.need(hashlib.sha256(current.encode()).hexdigest()=='66aca258881a016a61e484430150354e7495336f322694907ee0eeea13a23a62','HTTP_VHOST_UNREVIEWED_DRIFT')
    b.need(hashlib.sha256(expected[target].encode()).hexdigest()=='37709c81c620b54e2555fbd0e1d723ab89e59d8fe2e9675bb530708e1def6d83','HTTP_REPAIR_TARGET_NOT_REVIEWED')
    other={str(p):digest(p) for p in target.parent.glob('*.conf') if p.is_file() and not p.is_symlink() and p not in VHOSTS}
    directory=PRIVATE/'backups'/('http-vhost-repair-'+str(time.time_ns()));directory.mkdir(mode=0o700)
    (directory/'before.conf').write_text(current);(directory/'before.conf').chmod(0o600)
    journal=directory/'journal.json';l.atomic(journal,{'phase':'INTENT','target':str(target),'before_sha256':digest(target)})
    try:
        target.write_text(expected[target])
        command(['/www/server/nginx/sbin/nginx','-t'],'V2_REPAIR_NGINX_TEST_FAILED')
        command(['/www/server/nginx/sbin/nginx','-s','reload'],'V2_REPAIR_NGINX_RELOAD_FAILED')
        time.sleep(2);scope=l.audit()[2];http=health()
        b.need(all(digest(Path(p))==value for p,value in other.items()),'NON_V2_VHOST_CHANGED')
        l.atomic(journal,{'phase':'VERIFIED','target':str(target),'after_sha256':digest(target),'other_vhosts_unchanged':True})
        return {'result':'PASS','backup':directory.name,'http':http,'isolation':scope,'other_vhosts_unchanged':True}
    except Exception:
        target.write_text(current)
        command(['/www/server/nginx/sbin/nginx','-t'],'V2_REPAIR_ROLLBACK_TEST_FAILED')
        command(['/www/server/nginx/sbin/nginx','-s','reload'],'V2_REPAIR_ROLLBACK_RELOAD_FAILED')
        l.atomic(journal,{'phase':'ROLLED_BACK','target':str(target)})
        raise

def provision():
    b.need(os.geteuid()==0,'FIXED_ROOT_SETUP_REQUIRED')
    if l.POLICY.exists():
        repaired=None
        try:policy,env,scope=l.audit()
        except RuntimeError as exc:
            if str(exc)!='ACTUAL_V2_VHOSTS_CHANGED':raise
            repaired={'inert_extension_certified':certify_inert_extension()};policy,env,scope=l.audit()
        return {'result':'PASS','already_configured':True,'isolation':scope,'http':health(),'database_writes':0,'vhost_repair':repaired}
    account=pwd.getpwnam('ppb-v2');b.need(account.pw_uid>0 and account.pw_gid>0,'DEDICATED_V2_ACCOUNT_MISSING')
    env_path=LIVE/'.env';env=b.read_env(env_path,digest(env_path))
    scope=l.metadata(env)
    # Verify TCP actor/grants BEFORE changing anything. PrivateTmp hides the shared /tmp socket.
    tcp=dict(env,DB_HOST='127.0.0.1');l.metadata(tcp)
    before=health()
    originals={p:(p.read_bytes(),p.lstat().st_mode,p.lstat().st_uid,p.lstat().st_gid) for p in [*VHOSTS,env_path]}
    pool=(PRIVATE/'units/php-fpm.conf').read_text()
    rendered={p:flatten(checked_file(p)) for p in VHOSTS}
    for text in rendered.values():b.validate_web_config(text,pool)
    config=Path('/etc/ppb-v2/php-fpm.conf');unit=Path('/etc/systemd/system/ppb-v2-php.service')
    b.need(not config.exists() and not config.is_symlink() and not unit.exists() and not unit.is_symlink(),'V2_SERVICE_CONFIG_COLLISION')
    # Full batch inventory before mutation; never follow application symlinks.
    metadata={};roots=[LIVE/'storage',LIVE/'bootstrap/cache']
    for parent in roots:
        b.need(parent.is_dir() and not parent.is_symlink(),'RUNTIME_DIRECTORY_NOT_REGULAR')
        for p in [parent,*parent.rglob('*')]:
            b.need(len(metadata)<30000,'RUNTIME_PERMISSION_SNAPSHOT_LIMIT')
            st=p.lstat()
            if stat.S_ISLNK(st.st_mode):continue
            if p==LIVE/'storage/run/php-fpm.sock' and stat.S_ISSOCK(st.st_mode):
                b.need(st.st_uid==account.pw_uid,'STALE_V2_SOCKET_OWNER_INVALID')
                continue # Runtime socket is recreated by FPM, not a permission-backup file.
            b.need(stat.S_ISDIR(st.st_mode) or stat.S_ISREG(st.st_mode),'UNEXPECTED_RUNTIME_FILE_TYPE')
            b.need(not stat.S_ISREG(st.st_mode) or st.st_nlink==1,'RUNTIME_HARDLINK_UNSAFE')
            metadata[str(p)]=(st.st_mode,st.st_uid,st.st_gid)
    code_metadata=code_snapshot()
    old_hosts={str(p):digest(p) for p in Path('/www/server/panel/vhost/nginx').glob('*.conf') if p.is_file() and not p.is_symlink() and p not in VHOSTS}
    backup=PRIVATE/'backups'/('v2-fpm-cutover-'+str(time.time_ns()));backup.mkdir(mode=0o700)
    for i,(p,(data,mode,uid,gid)) in enumerate(originals.items()):
        target=backup/('original-'+str(i));target.write_bytes(data);target.chmod(0o600)
    (backup/'manifest.json').write_text(json.dumps({'files':{str(p):{'mode':v[1],'uid':v[2],'gid':v[3]} for p,v in originals.items()},'runtime_metadata':metadata,'code_metadata':code_metadata,'other_vhost_sha256':old_hosts}))
    cached=LIVE/'bootstrap/cache/config.php';old_cache=cached.read_bytes() if cached.is_file() and not cached.is_symlink() else None
    if old_cache is not None:(backup/'inherited-config.php').write_bytes(old_cache)
    created=[];started=False;vhost_changed=False
    try:
        config.parent.mkdir(mode=0o755,exist_ok=True)
        directory=os.open(config.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:
            b.need(os.fstat(directory).st_uid==0,'V2_CONFIG_DIRECTORY_NOT_ROOT_OWNED')
            os.fchmod(directory,0o755) # Bootstrap umask077 otherwise strips directory traversal.
        finally:os.close(directory)
        config.write_text(pool);config.chmod(0o644);created.append(config)
        unit.write_bytes((PRIVATE/'units/ppb-v2-php.service').read_bytes());unit.chmod(0o644);created.append(unit)
        text=env_path.read_text();b.need(len(re.findall(r'^DB_HOST=.*$',text,re.M))==1,'DATABASE_HOST_SETTING_AMBIGUOUS')
        text=re.sub(r'^DB_HOST=.*$','DB_HOST=127.0.0.1',text,flags=re.M)
        env_path.write_text(text);os.chown(env_path,0,account.pw_gid);env_path.chmod(0o640)
        cached.unlink(missing_ok=True)
        normalize_code(code_metadata,account.pw_gid)
        for name,(mode,uid,gid) in metadata.items():
            p=Path(name)
            if p==cached:continue  # Deliberately backed up and removed above.
            runtime_permissions(p,account.pw_uid,account.pw_gid,0o770 if stat.S_ISDIR(mode) else 0o660)
        for p in [LIVE/'storage',LIVE/'storage/app']:
            if p.exists():p.chmod(0o711)
        public=LIVE/'storage/app/public'
        if public.is_dir() and not public.is_symlink():
            for p in [public,*public.rglob('*')]:
                if not p.is_symlink():p.chmod(0o755 if p.is_dir() else 0o644)
        run=LIVE/'storage/run';run.mkdir(mode=0o711,exist_ok=True);os.chown(run,account.pw_uid,account.pw_gid);run.chmod(0o711)
        command(b.sandbox_command(['/www/server/php/84/sbin/php-fpm','--test','--fpm-config',str(config)]),'V2_FPM_CONFIG_TEST_FAILED')
        command(['systemctl','daemon-reload'],'V2_UNIT_RELOAD_FAILED')
        started=True;command(['systemctl','start','ppb-v2-php.service'],'V2_FPM_START_FAILED')
        for _ in range(30):
            socket=run/'php-fpm.sock'
            if socket.exists():break
            time.sleep(1)
        b.need(socket.exists() and stat.S_ISSOCK(socket.lstat().st_mode) and socket.stat().st_uid==account.pw_uid,'V2_FPM_SOCKET_OWNER_INVALID')
        for p,text in rendered.items():p.write_text(text);p.chmod(stat.S_IMODE(originals[p][1]));vhost_changed=True
        nginx='/www/server/nginx/sbin/nginx'
        command([nginx,'-t'],'V2_NGINX_CONFIG_TEST_FAILED')
        command([nginx,'-s','reload'],'V2_NGINX_RELOAD_FAILED')
        time.sleep(2)
        after=health()
        b.need(all(Path(p).is_file() and digest(Path(p))==sha for p,sha in old_hosts.items()),'NON_V2_VHOST_CHANGED')
        policy={'version':2,'live':b.LIVE,'private':b.PRIVATE,'unix_user':'ppb-v2','database':'ppbaos','read_only_schemas':[],
                'env_sha256':digest(env_path),'vhost_sha256':l.vhost_digest(),'php_config_sha256':digest(config),'php_unit_sha256':digest(unit),'legacy_roots':[b.LEGACY]}
        l.POLICY.parent.mkdir(mode=0o700,exist_ok=True);l.atomic(l.POLICY,policy)
        policy,env,checked=l.audit()
        # Configure V2-only queue/scheduler templates without starting jobs or creating tables.
        for name in ['ppb-v2-queue.service','ppb-v2-scheduler.service','ppb-v2-scheduler.timer']:
            p=Path('/etc/systemd/system')/name;b.need(not p.exists() and not p.is_symlink(),'V2_WORKER_CONFIG_COLLISION')
            p.write_bytes((PRIVATE/'units'/name).read_bytes());p.chmod(0o644);created.append(p)
        command(['systemctl','daemon-reload'],'V2_WORKER_UNIT_RELOAD_FAILED')
        command(['systemctl','enable','ppb-v2-php.service'],'V2_FPM_ENABLE_FAILED')
        result={'result':'PASS','isolation':checked,'metadata_global':True,'before_http':before,'after_http':after,'backup':backup.name,'other_vhosts_unchanged':True,'database_writes':0,'queue_scheduler':'CONFIGURED_DISABLED_NO_JOBS_STARTED'}
        l.atomic(PRIVATE/'v2-service-isolation.json',result)
        return result
    except Exception:
        l.POLICY.unlink(missing_ok=True)
        for p,(data,mode,uid,gid) in originals.items():p.write_bytes(data);os.chmod(p,stat.S_IMODE(mode));os.chown(p,uid,gid)
        if old_cache is not None:cached.write_bytes(old_cache)
        for name,(mode,uid,gid) in {**code_metadata,**metadata}.items():
            p=Path(name)
            if p.exists() and not p.is_symlink():runtime_permissions(p,uid,gid,stat.S_IMODE(mode))
        if started:subprocess.run(['systemctl','disable','--now','ppb-v2-php.service'],capture_output=True,timeout=30)
        for p in reversed(created):p.unlink(missing_ok=True)
        subprocess.run(['systemctl','daemon-reload'],capture_output=True,timeout=30)
        if vhost_changed:
            command(['/www/server/nginx/sbin/nginx','-t'],'ROLLBACK_NGINX_TEST_FAILED')
            command(['/www/server/nginx/sbin/nginx','-s','reload'],'ROLLBACK_NGINX_RELOAD_FAILED')
        raise
if __name__=='__main__':
    try:result=provision()
    except Exception as exc:
        import traceback
        reason=str(exc);result={'result':'STOP','reason':reason if re.fullmatch('[A-Z0-9_]{1,160}',reason) else 'V2_PROVISION_NOT_VERIFIED','database_writes':0,'exception_type':type(exc).__name__,'frames':[{'file':Path(x.filename).name,'line':x.lineno,'function':x.name} for x in traceback.extract_tb(exc.__traceback__)[-7:]]}
    if result.get('reason') in {'V2_LOGIN_HTTP_CHECK_FAILED','V2_GUEST_REDIRECT_CHECK_FAILED'}:
        diagnostic=PRIVATE/'provision-http.json'
        if diagnostic.is_file():result['http_diagnostic']=json.loads(diagnostic.read_text())
        markers=[]
        for log in [LIVE/'storage/logs/php-fpm.log',LIVE/'storage/logs/laravel.log']:
            if log.is_file() and not log.is_symlink():
                with log.open('rb') as handle:
                    handle.seek(max(0,log.stat().st_size-16000));text=handle.read().decode(errors='replace')
                for token in ['Permission denied','open_basedir restriction','Primary script unknown','File not found','No input file specified','failed to open stream','No application encryption key','Unable to create cache directory']:
                    if token in text:markers.append(token)
                markers.extend(re.findall(r'Call to undefined function ([A-Za-z0-9_\\]+)\(',text))
                markers.extend('SQLSTATE['+x+']' for x in re.findall(r'SQLSTATE\[([A-Z0-9]+)\]',text))
        result['safe_error_markers']=sorted(set(markers))
    if result.get('reason') in {'V2_FPM_CONFIG_TEST_FAILED','V2_NGINX_CONFIG_TEST_FAILED'}:
        diagnostic=PRIVATE/'provision-diagnostics.json'
        if diagnostic.is_file():
            value=json.loads(diagnostic.read_text());result['fixed_config_diagnostics']={'stage':value['stage'],'stderr':value.get('stderr','')[-6000:]}
    if result.get('reason')=='V2_FPM_START_FAILED':
        p=subprocess.run(['journalctl','-u','ppb-v2-php.service','-n','25','--no-pager'],capture_output=True,text=True,timeout=15);result['startup_journal']=p.stdout[-6000:]
    print('V2_PROVISION='+json.dumps(result));raise SystemExit(0 if result['result']=='PASS' else 2)
