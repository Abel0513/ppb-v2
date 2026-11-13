#!/usr/bin/env python3
"""Fixed V2 release coordinator. Installed beside existing trusted deploy tools.
Application code never executes as root. Requires verified nonroot web/CLI + SQL
isolation before program deployment, backup, migration or cache work.
"""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
HERE=Path(__file__).resolve().parent

def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
l=module('lifecycle',HERE/'lifecycle.py');b=l.b
c=module('controlled',HERE.parent/'controlled-deploy.py')

DEFAULT_PLAN={'migrate':False,'cache':[],'queue_restart':False}

def validate_plan(value):
    b.need(isinstance(value,dict) and set(value)==set(DEFAULT_PLAN),'RELEASE_PLAN_SCHEMA')
    b.need(type(value['migrate']) is bool and type(value['queue_restart']) is bool,'RELEASE_PLAN_BOOLEAN')
    b.need(isinstance(value['cache'],list) and all(isinstance(x,str) for x in value['cache']) and len(value['cache'])==len(set(value['cache']))
           and set(value['cache'])<={'config','routes','views'},'RELEASE_PLAN_CACHE')
    return value

def backup(env,commit,call=subprocess.run):
    root=Path(b.PRIVATE)/'db-backups'
    if not root.exists():root.mkdir(mode=0o700)
    st=root.lstat();b.need(root.is_dir() and not root.is_symlink() and st.st_uid==os.geteuid() and st.st_mode&0o077==0,'BACKUP_DIRECTORY_UNSAFE')
    receipt=root/('receipt-'+commit+'.json')
    destination=root/('before-'+commit+'-'+str(time.time_ns())+'.sql')
    # Credentials stay in a private temporary defaults file, never argv/log/artifact.
    fd,name=tempfile.mkstemp(prefix='.mysql-',dir=root)
    try:
        values={'host':env['DB_HOST'],'port':env.get('DB_PORT','3306'),'user':env['DB_USERNAME'],'password':env['DB_PASSWORD']}
        with os.fdopen(fd,'w') as conf:
            conf.write('[client]\n')
            for key,value in values.items():
                b.need('\n' not in value and '\r' not in value and '\x00' not in value,'MYSQL_DEFAULT_VALUE_INVALID')
                conf.write(key+'="'+value.replace('\\','\\\\').replace('"','\\"')+'"\n')
        binary=next((x for x in ['/www/server/mysql/bin/mysqldump','/usr/bin/mysqldump'] if Path(x).is_file()),None)
        b.need(binary is not None,'MYSQL_DUMP_BINARY_MISSING')
        version=call([binary,'--version'],capture_output=True,text=True,timeout=15)
        b.need(version.returncode==0,'MYSQL_DUMP_VERSION_UNVERIFIED')
        extra=[] if 'mariadb' in version.stdout.lower() else ['--set-gtid-purged=OFF']
        with destination.open('xb') as output:
            os.chmod(destination,0o600)
            p=call([binary,'--defaults-extra-file='+name,'--single-transaction','--skip-lock-tables','--no-tablespaces',*extra,'--routines','--events','--triggers','--databases',env['DB_DATABASE']],stdout=output,stderr=subprocess.PIPE,timeout=600)
            output.flush();os.fsync(output.fileno())
        b.need(p.returncode==0 and destination.stat().st_size>0,'PRIVATE_DATABASE_BACKUP_FAILED')
        digest=hashlib.sha256()
        with destination.open('rb') as src:
            for chunk in iter(lambda:src.read(1048576),b''):digest.update(chunk)
        l.atomic(receipt,dict(file=destination.name,commit=commit,database=env['DB_DATABASE'],created_at=time.time(),sha256=digest.hexdigest()))
    finally:Path(name).unlink(missing_ok=True)

def release(args):
    policy,env,isolation=l.audit()
    pending=Path(b.PRIVATE)/'lifecycle'
    if pending.is_dir():
        for record in pending.glob('*.json'):
            prior=b.private_json(record)
            b.need(prior.get('phase')=='VERIFIED' or prior.get('intent',{}).get('operation')!='migrate','PRIOR_MIGRATION_OUTCOME_UNCERTAIN')
    obj=c.ControlledDeploy(allow_assets_changes=True)
    fd=obj.lock()
    try:
        candidate,names,actions=obj.audit(args.archive,args.sha256,args.commit,delete_allowed=True)
        plan=validate_plan(json.loads((candidate/'.ppb-release.json').read_text()).get('operations',DEFAULT_PLAN))
        if args.operation=='audit':return dict(result='AUDIT_PASS',commit=args.commit,isolation=isolation,plan=plan)
        b.need(args.confirmation=='DEPLOY-PPB-V2','DEPLOY_CONFIRMATION_REQUIRED')
        if plan['migrate']:backup(env,args.commit)
        obj.apply(candidate,names,actions,args.sha256,args.commit)
        results=[]
        if plan['migrate']:results.append(l.run('migrate',args.commit,'release-'+args.commit+'-migrate'))
        for target in plan['cache']:results.append(l.run('cache_'+target,args.commit,'release-'+args.commit+'-cache-'+target))
        if plan['queue_restart']:results.append(l.run('queue_restart',args.commit,'release-'+args.commit+'-queue'))
        # Fixed HTTP acceptance must be available before deployment is called PASS.
        a=module('acceptance',HERE.parents[1]/'automation/accept-v2.py')
        state=json.loads((Path(b.PRIVATE)/'current-release.json').read_text())
        manifest_path=Path(b.LIVE)/'resources/ppb/acceptance.json'
        manifest=json.loads(manifest_path.read_text()) if manifest_path.is_file() and not manifest_path.is_symlink() else []
        acceptance=a.verify(args.commit,state,a.get,manifest)
        return dict(result='PPB_V2_ISOLATED_RELEASE_PASS',commit=args.commit,isolation=isolation,
                    migrations=plan['migrate'],lifecycle=results,acceptance=acceptance)
    finally:os.close(fd)

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--operation',choices=['audit','deploy'],required=True)
    parser.add_argument('--archive',type=Path,required=True);parser.add_argument('--sha256',required=True)
    parser.add_argument('--commit',required=True);parser.add_argument('--run-id',required=True);parser.add_argument('--run-attempt',required=True)
    parser.add_argument('--confirmation',default='NONE');args=parser.parse_args()
    os.umask(0o077)
    try:
        b.need(c.COMMIT_RE.fullmatch(args.commit) and c.SHA_RE.fullmatch(args.sha256) and c.RUN_RE.fullmatch(args.run_id) and c.RUN_RE.fullmatch(args.run_attempt),'EXECUTION_CORRELATION_INVALID')
        expected=Path(b.PRIVATE)/'releases'/('ssm-incoming-'+args.run_id+'-'+args.run_attempt)/('run-'+args.run_id+'-attempt-'+args.run_attempt+'.tar.gz')
        b.need(args.archive==expected,'ARCHIVE_PATH_MISMATCH')
        result=release(args)
    except Exception as exc:
        # Raw app/DB messages and backup contents remain private; never export them.
        reason=str(exc)
        result=dict(result='STOP',reason=reason if __import__('re').fullmatch('[A-Z0-9_]{1,160}',reason) else 'ISOLATED_RELEASE_FAILED',commit=args.commit)
    print('PPB_V2_RELEASE_V1='+json.dumps(result,separators=(',',':')))
    return 0 if result['result'] in {'AUDIT_PASS','PPB_V2_ISOLATED_RELEASE_PASS'} else 2
if __name__=='__main__':raise SystemExit(main())
