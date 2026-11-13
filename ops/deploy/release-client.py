#!/usr/bin/env python3
"""Existing OIDC/S3/SSM release channel; fail closed on uninstalled isolation tools."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import time

ACCOUNT='429724121147';REGION='us-east-1';INSTANCE='i-0135d6b2fac7a54c2';DOC='PPB-V2-Controlled-Deploy';BUCKET='ppb-v2-release-artifacts-429724121147-use1'

def need(ok,code):
    if not ok:raise RuntimeError(code)

def aws(*args):
    p=subprocess.run(['aws',*args,'--region',REGION,'--output','json','--no-cli-pager'],capture_output=True,text=True,timeout=90,env=dict(os.environ,AWS_PAGER='',AWS_MAX_ATTEMPTS='1'))
    if p.returncode:
        m=re.search(r'\(([A-Za-z0-9_.-]+)\)',p.stderr);raise RuntimeError('AWS_'+(m.group(1) if m else 'CLI_FAILED'))
    return json.loads(p.stdout or '{}')

def execute(operation,commit,run,attempt,archive,call=aws,pause=time.sleep):
    result=dict(result='STOP',commit=commit,operation=operation,run_id=run,run_attempt=attempt,ssm_command_id=None)
    root=Path('v2-result');root.mkdir(exist_ok=True)
    def save():
        tmp=root/'result.pending';tmp.write_text(json.dumps(result,indent=2));tmp.replace(root/'result.json')
    save()
    try:
        need(operation in {'audit','deploy'} and re.fullmatch('[a-f0-9]{40}',commit) and re.fullmatch('[1-9][0-9]*',run) and re.fullmatch('[1-9][0-9]*',attempt),'EXECUTION_INVALID')
        need(call('sts','get-caller-identity')['Account']==ACCOUNT,'WRONG_ACCOUNT')
        meta=call('ssm','describe-document','--name',DOC,'--document-version','$DEFAULT')['Document']
        content=call('ssm','get-document','--name',DOC,'--document-version',meta['DocumentVersion'],'--document-format','JSON')
        expected=json.loads((Path(__file__).parent/'PPB-V2-Controlled-Deploy.isolated.ssm.json').read_text())
        result.update(document_version=meta['DocumentVersion'],document_hash=meta['Hash'])
        need(json.loads(content['Content'])==expected,'ISOLATED_V2_EXECUTOR_NOT_INSTALLED')
        need(meta['HashType']=='Sha256' and re.fullmatch('[a-f0-9]{64}',meta['Hash']),'DOCUMENT_HASH_INVALID')
        # IAM SendCommand scopes to document ARN, not a verified JSON body.
        # Old versions evaluating app autoloaders as root must not remain callable.
        approved=[expected,json.loads((Path(__file__).parent/'PPB-V2-Controlled-Deploy.bootstrap.ssm.json').read_text())]
        token=None;seen=[]
        for _ in range(20):
            args=['ssm','list-document-versions','--name',DOC]
            if token:args+=['--next-token',token]
            page=call(*args)
            for item in page.get('DocumentVersions',[]):
                version=item['DocumentVersion'];seen.append(version)
                prior=call('ssm','get-document','--name',DOC,'--document-version',version,'--document-format','JSON')
                need(json.loads(prior['Content']) in approved,'UNSAFE_OR_UNREVIEWED_OLD_SSM_VERSION_STILL_CALLABLE')
            token=page.get('NextToken')
            if not token:break
        else:raise RuntimeError('DOCUMENT_VERSION_SCAN_LIMIT')
        need(meta['DocumentVersion'] in seen,'DOCUMENT_VERSION_INVENTORY_INCOMPLETE')

        digest=hashlib.sha256(Path(archive).read_bytes()).hexdigest()
        key=f'releases/{commit}/run-{run}-attempt-{attempt}.tar.gz'
        call('s3api','put-object','--bucket',BUCKET,'--key',key,'--body',str(archive),'--if-none-match','*','--server-side-encryption','AES256')
        parameters={k:[v] for k,v in dict(Commit=commit,Sha256=digest,RunId=run,RunAttempt=attempt,Operation=operation,Approval='DEPLOY-PPB-V2' if operation=='deploy' else 'NONE').items()}
        result['stage']='SEND_INTENT';save()
        response=call('ssm','send-command','--document-name',DOC,'--document-version',meta['DocumentVersion'],'--document-hash',meta['Hash'],'--document-hash-type','Sha256','--instance-ids',INSTANCE,'--parameters',json.dumps(parameters),'--max-concurrency','1','--max-errors','0','--timeout-seconds','120')
        command=response['Command']['CommandId'];result['ssm_command_id']=command;save();print('SSM_COMMAND_ID='+command,flush=True)
        for _ in range(390):
            try:inv=call('ssm','get-command-invocation','--command-id',command,'--instance-id',INSTANCE,'--plugin-name','controlledRelease')
            except RuntimeError as exc:
                if str(exc) in {'AWS_InvocationDoesNotExist','AWS_ThrottlingException'}:pause(5);continue
                raise
            need(inv.get('CommandId')==command and inv.get('InstanceId')==INSTANCE,'INVOCATION_TARGET_MISMATCH')
            if inv['Status'] in {'Pending','InProgress','Delayed','Cancelling'}:pause(5);continue
            lines=[x.split('=',1)[1] for x in inv.get('StandardOutputContent','').splitlines() if x.startswith('PPB_V2_RELEASE_V1=')]
            need(len(lines)==1,'RELEASE_RESULT_MISSING')
            data=json.loads(lines[0]);result['remote']=data
            need(data.get('commit')==commit,'FOREIGN_RELEASE_RESULT')
            need(inv['Status']=='Success' and inv['ResponseCode']==0,'REMOTE_RELEASE_STOP')
            expected_result='AUDIT_PASS' if operation=='audit' else 'PPB_V2_ISOLATED_RELEASE_PASS'
            need(data.get('result')==expected_result,'RELEASE_NOT_VERIFIED')
            checks=data.get('isolation',{})
            need(checks.get('legacy_database_write') is False and checks.get('v2_database_write') is True,'DATABASE_BOUNDARY_NOT_VERIFIED')
            if operation=='deploy':
                accepted=data.get('acceptance',{})
                need(accepted.get('COMMIT')==commit and accepted.get('RESULT')=='PPB_V2_FUNCTIONAL_BASELINE_PASS'
                     and type(accepted.get('CHECKS')) is int and accepted['CHECKS']>=4,'FUNCTIONAL_ACCEPTANCE_NOT_VERIFIED')
            result.update(result='PASS',stage='VERIFIED');save();return result
        raise RuntimeError('REMOTE_OUTCOME_UNKNOWN_DO_NOT_RESEND')
    except Exception as exc:
        reason=str(exc);result['reason']=reason if re.fullmatch('[A-Za-z0-9_]{1,160}',reason) else 'RELEASE_CLIENT_FAILED';save();return result

def main():
    operation=os.environ['PPB_OPERATION']
    if operation=='package_only':
        commit=os.environ['GITHUB_SHA'];run=os.environ['GITHUB_RUN_ID'];attempt=os.environ['GITHUB_RUN_ATTEMPT']
        need(re.fullmatch('[a-f0-9]{40}',commit) and re.fullmatch('[1-9][0-9]*',run) and re.fullmatch('[1-9][0-9]*',attempt),'PACKAGE_ID_INVALID')
        need(aws('sts','get-caller-identity')['Account']==ACCOUNT,'WRONG_ACCOUNT')
        archive=Path(os.environ['RELEASE_ARCHIVE']);key=f'releases/{commit}/run-{run}-attempt-{attempt}.tar.gz'
        aws('s3api','put-object','--bucket',BUCKET,'--key',key,'--body',str(archive),'--if-none-match','*','--server-side-encryption','AES256')
        result=dict(result='PACKAGE_STORED',commit=commit,key=key,ssm_command_id=None,database_writes=0)
        Path('v2-result').mkdir(exist_ok=True);Path('v2-result/result.json').write_text(json.dumps(result))
        print(json.dumps(result));return 0
    result=execute(operation,os.environ['GITHUB_SHA'],os.environ['GITHUB_RUN_ID'],os.environ['GITHUB_RUN_ATTEMPT'],Path(os.environ['RELEASE_ARCHIVE']))
    print(json.dumps(result))
    with open(os.environ['GITHUB_STEP_SUMMARY'],'a') as out:out.write('\nV2 controlled result: '+json.dumps(result)+'\n')
    return 0 if result['result']=='PASS' else 1
if __name__=='__main__':raise SystemExit(main())
