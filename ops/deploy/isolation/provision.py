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
    b.need(not re.search(r'\b(?:proxy_pass|content_by_lua|perl|root\s+/www/wwwroot/3\.231\.229\.26)\b',text),'V2_VHOST_UNREVIEWED_UPSTREAM')
    return text

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
        if path=='/admin/login':b.need(status==200 and b'password' in body and b'username' in body,'V2_LOGIN_HTTP_CHECK_FAILED')
        else:b.need(status in {301,302,303,307,308} and '/admin/login' in headers.get('location',''),'V2_GUEST_REDIRECT_CHECK_FAILED')
        result.append({'path':path,'status':status})
    return result

def provision():
    b.need(os.geteuid()==0,'FIXED_ROOT_SETUP_REQUIRED')
    if l.POLICY.exists():
        policy,env,scope=l.audit()
        return {'result':'PASS','already_configured':True,'isolation':scope,'http':health(),'database_writes':0}
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
            b.need(stat.S_ISDIR(st.st_mode) or stat.S_ISREG(st.st_mode),'UNEXPECTED_RUNTIME_FILE_TYPE')
            metadata[str(p)]=(st.st_mode,st.st_uid,st.st_gid)
    old_hosts={str(p):digest(p) for p in Path('/www/server/panel/vhost/nginx').glob('*.conf') if p.is_file() and not p.is_symlink() and p not in VHOSTS}
    backup=PRIVATE/'backups'/('v2-fpm-cutover-'+str(time.time_ns()));backup.mkdir(mode=0o700)
    for i,(p,(data,mode,uid,gid)) in enumerate(originals.items()):
        target=backup/('original-'+str(i));target.write_bytes(data);target.chmod(0o600)
    (backup/'manifest.json').write_text(json.dumps({'files':{str(p):{'mode':v[1],'uid':v[2],'gid':v[3]} for p,v in originals.items()},'runtime_metadata':metadata,'other_vhost_sha256':old_hosts}))
    cached=LIVE/'bootstrap/cache/config.php';old_cache=cached.read_bytes() if cached.is_file() and not cached.is_symlink() else None
    if old_cache is not None:(backup/'inherited-config.php').write_bytes(old_cache)
    created=[];started=False;vhost_changed=False
    try:
        config.parent.mkdir(mode=0o755,exist_ok=True)
        config.write_text(pool);config.chmod(0o644);created.append(config)
        unit.write_bytes((PRIVATE/'units/ppb-v2-php.service').read_bytes());unit.chmod(0o644);created.append(unit)
        text=env_path.read_text();b.need(len(re.findall(r'^DB_HOST=.*$',text,re.M))==1,'DATABASE_HOST_SETTING_AMBIGUOUS')
        text=re.sub(r'^DB_HOST=.*$','DB_HOST=127.0.0.1',text,flags=re.M)
        env_path.write_text(text);os.chown(env_path,0,account.pw_gid);env_path.chmod(0o640)
        cached.unlink(missing_ok=True)
        for name,(mode,uid,gid) in metadata.items():
            p=Path(name);b.need(not p.is_symlink(),'RUNTIME_PATH_CHANGED')
            os.chown(p,account.pw_uid,account.pw_gid)
            p.chmod(0o770 if stat.S_ISDIR(mode) else 0o660)
        for p in [LIVE/'storage',LIVE/'storage/app']:
            if p.exists():p.chmod(0o711)
        public=LIVE/'storage/app/public'
        if public.is_dir() and not public.is_symlink():
            for p in [public,*public.rglob('*')]:
                if not p.is_symlink():p.chmod(0o755 if p.is_dir() else 0o644)
        run=LIVE/'storage/run';run.mkdir(mode=0o711,exist_ok=True);os.chown(run,account.pw_uid,account.pw_gid);run.chmod(0o711)
        command(['/www/server/php/84/sbin/php-fpm','--test','--fpm-config',str(config)],'V2_FPM_CONFIG_TEST_FAILED')
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
        for name,(mode,uid,gid) in metadata.items():
            p=Path(name)
            if p.exists() and not p.is_symlink():os.chmod(p,stat.S_IMODE(mode));os.chown(p,uid,gid)
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
        reason=str(exc);result={'result':'STOP','reason':reason if re.fullmatch('[A-Z0-9_]{1,160}',reason) else 'V2_PROVISION_NOT_VERIFIED','database_writes':0}
    if result.get('reason')=='V2_FPM_START_FAILED':
        p=subprocess.run(['journalctl','-u','ppb-v2-php.service','-n','25','--no-pager'],capture_output=True,text=True,timeout=15);result['startup_journal']=p.stdout[-6000:]
    print('V2_PROVISION='+json.dumps(result));raise SystemExit(0 if result['result']=='PASS' else 2)
