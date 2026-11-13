#!/usr/bin/env python3
"""Fixed V2 lifecycle. Candidate: requires reviewed root-private isolation policy.
No arbitrary commands; migrations run only after effective SQL grant verification.
"""
import argparse
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import subprocess
import stat
import tempfile
import time
ROOT=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('boundary',ROOT/'boundary.py');b=importlib.util.module_from_spec(spec);spec.loader.exec_module(b)
PHP='/www/server/php/84/bin/php'
POLICY=Path('/www/ppb-v2-deploy/policy/isolation.json')
VHOSTS=['/www/server/panel/vhost/nginx/v2.csdatw.xyz-https.conf','/www/server/panel/vhost/nginx/v2.ppbaos.com.conf']
def vhost_digest(canonical=False,texts=None):
    values={p:Path(p).read_text() for p in VHOSTS} if texts is None else texts
    if canonical:
        spec=importlib.util.spec_from_file_location('fixed_nginx_lex',ROOT/'nginx_lex.py');lexer=importlib.util.module_from_spec(spec);spec.loader.exec_module(lexer)
        values={p:json.dumps(lexer.nginx_tokens(b.omit_empty_v2_extension(text)),ensure_ascii=True) for p,text in values.items()}
    else:
        values={p:Path(p).read_bytes() for p in VHOSTS} if texts is None else {p:text.encode() for p,text in values.items()}
    hashes={p:hashlib.sha256(value.encode() if isinstance(value,str) else value).hexdigest() for p,value in values.items()}
    return hashlib.sha256(json.dumps(hashes,sort_keys=True).encode()).hexdigest()
def metadata(env,call=subprocess.run):
    p=call([PHP,str(ROOT/'global-metadata.php')],input=json.dumps({'v2':env}),capture_output=True,text=True,timeout=60)
    b.need(p.returncode==0,'GLOBAL_METADATA_PROBE_FAILED')
    result=json.loads(p.stdout)
    b.need(result.get('result')=='PASS' and result.get('global_fk_visibility_proven') is True and result.get('v2_privileges_scoped') is True,'GLOBAL_METADATA_SCOPE_NOT_VERIFIED')
    return result
COMMANDS={
 'verify_feature':['ppb:runtime-check','--no-interaction'],
 'package_discover':['package:discover','--no-interaction'],
 'migrate':['migrate','--force','--no-interaction'],
 'migration_status':['migrate:status','--no-interaction'],
 'cache_config':['config:cache','--no-interaction'],
 'cache_routes':['route:cache','--no-interaction'],
 'cache_views':['view:cache','--no-interaction'],
 'queue_restart':['queue:restart','--no-interaction'],
 'schedule_run':['schedule:run','--no-interaction'],
}

def atomic(path,value):
    fd,name=tempfile.mkstemp(prefix='.v2-state-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as out:json.dump(value,out);out.flush();os.fsync(out.fileno())
        os.replace(name,path)
        directory=os.open(path.parent,os.O_DIRECTORY)
        try:os.fsync(directory)
        finally:os.close(directory)
    finally:
        if os.path.exists(name):os.unlink(name)

def audit(call=subprocess.run):
    b.need(os.geteuid()==0,'FIXED_SSM_ROOT_WRAPPER_REQUIRED')
    policy=b.validate_policy(b.private_json(POLICY))
    for value in [b.LIVE,b.PRIVATE]:
        p=Path(value);b.need(p.is_dir() and not p.is_symlink() and str(p.resolve())==value,'V2_PATH_NOT_VERIFIED')
    account=pwd.getpwnam(b.USER)
    b.need(account.pw_uid>0 and account.pw_gid>0,'V2_MUST_NOT_USE_ROOT_ACCOUNT')
    # All application-readable credentials must have been reviewed during bootstrap;
    # never infer isolation merely from a username/table prefix.
    env=b.read_env(Path(b.LIVE)/'.env',policy['env_sha256'])
    cached=Path(b.LIVE)/'bootstrap/cache/config.php'
    if cached.exists() or cached.is_symlink():
        st=cached.lstat()
        b.need(not cached.is_symlink() and cached.is_file() and st.st_uid==account.pw_uid and st.st_mtime>=POLICY.stat().st_mtime,'CONFIG_CACHE_REQUIRES_ISOLATED_REBUILD')
    global_scope=metadata(env,call)
    p=call([PHP,str(ROOT/'db-probe.php')],input=json.dumps(env),capture_output=True,text=True,timeout=30)
    b.need(p.returncode==0,'DATABASE_SCOPE_PROBE_FAILED')
    checks=b.validate_probe(policy,env,json.loads(p.stdout))
    service=call(['systemctl','show','ppb-v2-php.service','--property=ActiveState,User,ProtectSystem,NoNewPrivileges,CapabilityBoundingSet,ReadWritePaths,InaccessiblePaths,PrivateTmp,PrivateDevices,IPAddressDeny,BindReadOnlyPaths'],capture_output=True,text=True,timeout=15)
    b.need(service.returncode==0,'V2_WEB_SERVICE_ISOLATION_UNVERIFIED')
    b.validate_service(dict(line.split('=',1) for line in service.stdout.splitlines() if '=' in line))
    b.need(policy['version'] in {2,3} and vhost_digest(canonical=policy['version']==3)==policy['vhost_sha256'],'ACTUAL_V2_VHOSTS_CHANGED')
    paths=[('/etc/ppb-v2/php-fpm.conf','php_config_sha256'),('/etc/systemd/system/ppb-v2-php.service','php_unit_sha256')]
    configs=[]
    for filename,key in paths:
        path=Path(filename);st=path.lstat()
        b.need(path.is_file() and not path.is_symlink() and st.st_uid==0 and st.st_mode&0o022==0 and hashlib.sha256(path.read_bytes()).hexdigest()==policy[key],'WEB_CONFIG_CHANGED_RECHECK_BOUNDARY')
        configs.append(path.read_text())
    for vhost in VHOSTS:b.validate_web_config(b.omit_empty_v2_extension(Path(vhost).read_text()),configs[0])
    drops=call(['systemctl','show','ppb-v2-php.service','--property=DropInPaths','--value'],capture_output=True,text=True,timeout=15)
    b.need(drops.returncode==0 and not drops.stdout.strip(),'UNREVIEWED_V2_SERVICE_DROPINS')
    socket=Path(b.LIVE)/'storage/run/php-fpm.sock'
    b.need(stat.S_ISSOCK(socket.lstat().st_mode) and socket.lstat().st_uid==account.pw_uid,'ISOLATED_FPM_SOCKET_NOT_ACTIVE')
    return policy,env,checks

def inspect_host():
    b.need(os.geteuid()==0,'FIXED_SSM_ROOT_WRAPPER_REQUIRED')
    result={'result':'INVENTORY_ONLY_ISOLATION_UNVERIFIED','paths':{},'database_isolation':'UNVERIFIED'}
    for name in [b.LIVE,b.PRIVATE]:
        path=Path(name)
        result['paths'][name]={'exists':path.is_dir(),'symlink':path.is_symlink()}
        if path.exists():result['paths'][name]['uid']=path.lstat().st_uid
    try:
        account=pwd.getpwnam(b.USER);result['unix_user']={'name':b.USER,'uid':account.pw_uid,'gid':account.pw_gid}
    except KeyError:result['unix_user']={'name':b.USER,'exists':False}
    path=Path(b.LIVE)/'.env'
    if path.is_file() and not path.is_symlink():
        digest=hashlib.sha256(path.read_bytes()).hexdigest()
        try:
            env=b.read_env(path,digest,require_low_privilege=False)
            result['database_configuration']={key:env.get(key) for key in ['DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME']}
            result['env_sha256']=digest
            probe=subprocess.run([PHP,str(ROOT/'inventory-db.php')],input=json.dumps(env),capture_output=True,text=True,timeout=45)
            result['database_metadata']=json.loads(probe.stdout) if probe.returncode==0 else {'result':'METADATA_INVENTORY_FAILED'}
        except RuntimeError as exc:result['database_configuration']={'reason':str(exc)}
    result['env_key_names']=sorted(set(re.findall(r'^([A-Z][A-Z0-9_]*)=',path.read_text(),re.M))) if path.is_file() else []
    result['web']={}
    vhosts=[]
    for candidate in Path('/www/server/panel/vhost/nginx').glob('*.conf'):
        if candidate.is_file() and not candidate.is_symlink():
            text=candidate.read_text()
            if re.search(r'\bserver_name\s+[^;]*\bv2\.csdatw\.xyz\b',text):vhosts.append(str(candidate))
    result['v2_vhost_matches']=vhosts
    for filename in [*vhosts,'/www/server/php/84/etc/php-fpm.conf']:
        p=Path(filename)
        if p.is_file() and not p.is_symlink():
            # Only approved config keys; never emit certificate/private key/env values.
            lines=[line.strip() for line in p.read_text().splitlines() if re.match(r'^\s*(?:server_name|root|include|fastcgi_pass|listen|user|group|pid|daemonize)\s*(?:=|\s)',line)][:80]
            result['web'][filename]={'sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'directives':lines}
    for binary in [PHP,'/www/server/php/84/sbin/php-fpm','/www/server/nginx/sbin/nginx','/usr/bin/mysql','/www/server/mysql/bin/mysql']:
        result['paths'][binary]={'exists':Path(binary).is_file()}
    service=subprocess.run(['systemctl','show','ppb-v2-php.service','--property=LoadState,ActiveState,User,Group,FragmentPath'],capture_output=True,text=True,timeout=10)
    result['service']={line.split('=',1)[0]:line.split('=',1)[1] for line in service.stdout.splitlines() if '=' in line}
    result['policy_exists']=POLICY.exists()
    return result

def run(operation,commit,request_id,call=subprocess.run):
    b.need(operation in {'inventory',*COMMANDS},'OPERATION_NOT_ALLOWED')
    b.need(re.fullmatch('[a-f0-9]{40}',commit) and re.fullmatch('[A-Za-z0-9][A-Za-z0-9_-]{7,79}',request_id),'EXECUTION_ID_INVALID')
    if operation=='inventory' and not POLICY.exists():return inspect_host()
    policy,env,checks=audit(call)
    result=dict(operation=operation,commit=commit,request_id=request_id,isolation=checks,result='PASS')
    if operation=='inventory':return dict(result,program_writes=0,database_writes=0)
    state=json.loads((Path(b.PRIVATE)/'current-release.json').read_text())
    b.need(state.get('commit')==commit,'LIVE_RELEASE_MISMATCH')
    ledger=Path(b.PRIVATE)/'lifecycle'
    if not ledger.exists():ledger.mkdir(mode=0o700)
    st=ledger.lstat();b.need(st.st_uid==0 and st.st_mode&0o077==0 and ledger.is_dir() and not ledger.is_symlink(),'LEDGER_UNSAFE')
    lock=os.open(ledger/'lock',os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        record=ledger/(request_id+'.json')
        previous=b.private_json(record) if record.exists() or record.is_symlink() else None
        intent=dict(operation=operation,commit=commit,request_id=request_id)
        if operation!='migration_status':
            for other in ledger.glob('*.json'):
                prior=b.private_json(other)
                b.need(prior.get('phase')=='VERIFIED' or prior.get('intent',{}).get('operation')!='migrate','PRIOR_MIGRATION_OUTCOME_UNCERTAIN')
        if previous:
            b.need(previous.get('intent')==intent,'REQUEST_ID_CONFLICT')
            b.need(previous.get('phase')=='VERIFIED','PRIOR_OUTCOME_UNKNOWN_INSPECT_BEFORE_REPLAY')
            return dict(result,replayed=True)
        if operation=='migrate':
            # MySQL DDL autocommits: require a real private backup receipt for
            # this exact release. This tool never claims transaction rollback.
            receipt=b.private_json(Path(b.PRIVATE)/'db-backups'/('receipt-'+commit+'.json'))
            backup=Path(b.PRIVATE)/'db-backups'/receipt.get('file','')
            b.need(backup.parent==Path(b.PRIVATE)/'db-backups' and backup.is_file() and not backup.is_symlink(),'PRIVATE_DATABASE_BACKUP_REQUIRED')
            b.need(receipt.get('commit')==commit and receipt.get('database')==policy['database']
                   and 0 <= time.time()-receipt.get('created_at',0) <= 3600
                   and hashlib.sha256(backup.read_bytes()).hexdigest()==receipt.get('sha256'),'BACKUP_RECEIPT_INVALID')
        atomic(record,dict(intent=intent,phase='INTENT'))
        argv=b.sandbox_command([PHP,str(Path(b.LIVE)/'artisan'),*COMMANDS[operation]])
        argv.insert(argv.index('--'),'--setenv=PPB_V2_RELEASE_COMMIT='+commit)
        p=call(argv,capture_output=True,text=True,timeout=900)
        feature=None
        if operation=='verify_feature':
            lines=[line.split('=',1)[1] for line in p.stdout.splitlines() if line.startswith('V2_FEATURE_V1=')]
            b.need(len(lines)==1,'FEATURE_RECEIPT_MISSING')
            feature=json.loads(lines[0])
            b.need(feature.get('result')=='PASS' and feature.get('commit')==commit
                   and feature.get('database')==checks['database'] and feature.get('user')==checks['user']
                   and feature.get('transaction_rolled_back') is True and feature.get('database_writes_committed')==0,'LARAVEL_DATABASE_IDENTITY_MISMATCH')
        # Never export raw app output (may contain SQL/auth/session data).
        b.need(p.returncode==0,'LIFECYCLE_COMMAND_FAILED_PRIVATE_DIAGNOSIS_REQUIRED')
        _,_,after=audit(call)
        b.need({k:v for k,v in after.items() if k!='tables'}=={k:v for k,v in checks.items() if k!='tables'},'DATABASE_AUTHORITY_CHANGED_DURING_OPERATION')
        atomic(record,dict(intent=intent,phase='VERIFIED'))
        return dict(result,replayed=False,feature=feature)
    finally:os.close(lock)

def main():
    p=argparse.ArgumentParser();p.add_argument('operation',choices=['inventory',*COMMANDS]);p.add_argument('--commit',required=True);p.add_argument('--request-id',required=True);a=p.parse_args()
    try:result=run(a.operation,a.commit,a.request_id)
    except Exception as exc:
        reason=str(exc);result=dict(result='STOP',reason=reason if re.fullmatch('[A-Z0-9_]{1,160}',reason) else 'V2_LIFECYCLE_NOT_VERIFIED')
    print(json.dumps(result));return 0 if result['result']=='PASS' else 2
if __name__=='__main__':raise SystemExit(main())
