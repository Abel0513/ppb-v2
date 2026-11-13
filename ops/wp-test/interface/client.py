#!/usr/bin/env python3
"""Data-only remote interface client; never uploads executable code."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import time

ROOT=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('wpremote',ROOT.parent/'remote.py')
r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)

def invoke(operation, result, path, call=r.aws, sleep=time.sleep):
    r.require(call('sts','get-caller-identity').get('Account')==r.ACCOUNT,'WRONG_ACCOUNT')
    result['stage']='SSM_DOCUMENT';r.persist(path,result)
    meta=call('ssm','describe-document','--name',r.DOCUMENT,'--document-version','$DEFAULT')['Document']
    version=meta['DocumentVersion']
    actual=call('ssm','get-document','--name',r.DOCUMENT,'--document-version',version,'--document-format','JSON')
    expected=json.loads((ROOT/'document.json').read_text())
    r.require(json.loads(actual['Content'])==expected,'FIXED_INTERFACE_NOT_INSTALLED_ADMIN_ACTIVATION_REQUIRED')
    request=dict(operation=operation,commit=result['commit'],run_id=result['github_run_id'],run_attempt=result['github_run_attempt'])
    with tempfile.TemporaryDirectory() as d:
        body=Path(d)/'request.json';body.write_text(json.dumps(request))
        key=f"wp-test/interface/{request['commit']}/{request['run_id']}-{request['run_attempt']}/request.json"
        call('s3api','put-object','--bucket',r.BUCKET,'--key',key,'--body',str(body),
             '--if-none-match','*','--server-side-encryption','AES256')
    params={k:[v] for k,v in dict(Operation=operation,Commit=request['commit'],RunId=request['run_id'],RunAttempt=request['run_attempt']).items()}
    result.update(stage='SSM_SEND_INTENT',ssm_document_version=version);r.persist(path,result)
    sent=call('ssm','send-command','--document-name',r.DOCUMENT,'--document-version',version,
              '--document-hash',meta['Hash'],'--document-hash-type','Sha256','--instance-ids',r.INSTANCE,
              '--parameters',json.dumps(params),'--max-concurrency','1','--max-errors','0','--timeout-seconds','120')
    command=sent['Command']['CommandId']; result['ssm_command_id']=command;r.persist(path,result)
    print('SSM_COMMAND_ID='+command,flush=True)
    for _ in range(170):
        invs=call('ssm','list-command-invocations','--command-id',command,'--instance-id',r.INSTANCE,'--details').get('CommandInvocations',[])
        if not invs: sleep(5);continue
        r.require(len(invs)==1,'INVOCATION_COUNT_INVALID');inv=invs[0]
        r.require(inv['InstanceId']==r.INSTANCE and inv['CommandId']==command and inv['DocumentName']==r.DOCUMENT,'INVOCATION_MISMATCH')
        if inv['Status'] in ['Pending','InProgress','Delayed','Cancelling']:sleep(5);continue
        plugins=[p for p in inv.get('CommandPlugins',[]) if p['Name']=='fixedWpInterface']
        lines=[x.split('=',1)[1] for p in plugins for x in p.get('Output','').splitlines() if x.startswith('PPB_WP_INTERFACE_V1=')]
        r.require(len(lines)==1,'INTERFACE_RESULT_MISSING')
        data=json.loads(lines[0]);result['interface']=data
        r.require(data.get('execution')==request,'FOREIGN_INTERFACE_RESULT')
        r.require(inv['Status']=='Success' and all(p.get('ResponseCode')==0 for p in plugins),'INTERFACE_REMOTE_STOP')
        if operation=='resume':
            wp=r.validate_envelope(r.PREFIX+json.dumps(data['wp']),result)
            r.require(wp.get('result')=='PASS','WP_NOT_VERIFIED');result.update(wp=wp,wp_expert_id=wp['wp_expert_id'])
        else:
            j=data.get('journal',{})
            r.require(data.get('result')=='READ_ONLY_VERIFIED' and j.get('ok') is True
                      and j.get('wp_write_requests')==0,'JOURNAL_NOT_VERIFIED')
        result.update(result='PASS' if operation=='resume' else 'READ_ONLY_VERIFIED',stage='VERIFIED',stop_code=None)
        return
    raise r.Stop('SSM_OUTCOME_UNKNOWN_DO_NOT_RESEND')

def prior(call=r.aws):
    response=call('ssm','list-command-invocations','--command-id','b7e8486b-8099-433a-95c0-bb59ab75abd6','--instance-id',r.INSTANCE,'--details')
    # Historical sanitized envelope only; raw WP/PHP logs are never exported.
    safe=[]
    for inv in response.get('CommandInvocations',[]):
        item={k:inv.get(k) for k in ['CommandId','InstanceId','DocumentName','Status']}
        item['steps']=[{k:p.get(k) for k in ['Name','Status','ResponseCode']} for p in inv.get('CommandPlugins',[])]
        safe.append(item)
    return safe

def main():
    parser=argparse.ArgumentParser();parser.add_argument('operation',choices=['journal','resume']);args=parser.parse_args()
    path=Path('wp-result/interface-result.json')
    result=r.initial(os.environ['GITHUB_SHA'],os.environ['GITHUB_RUN_ID'],os.environ['GITHUB_RUN_ATTEMPT'])
    try:
        try:
            result['previous_ssm']=prior()
        except r.Stop as e:
            result['historical_lookup_stop']=str(e)
        invoke(args.operation,result,path)
    except (r.Stop,KeyError,ValueError,OSError) as e:
        result['stop_code']=str(e) if isinstance(e,r.Stop) else 'INTERFACE_CLIENT_FAILED'
    r.persist(path,result)
    r.summary(result,path.parent/'summary.md')
    print(json.dumps(result,ensure_ascii=False))
    return 0 if result['result'] in ['PASS','READ_ONLY_VERIFIED'] else 1
if __name__=='__main__':sys.exit(main())
