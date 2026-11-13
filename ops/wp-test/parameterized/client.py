#!/usr/bin/env python3
"""Data-only client. Never installs a document or uploads branch code to EC2."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import time
ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('remote',ROOT.parent/'remote.py');r=importlib.util.module_from_spec(s);s.loader.exec_module(r)
s=importlib.util.spec_from_file_location('engine',ROOT/'engine.py');e=importlib.util.module_from_spec(s);s.loader.exec_module(e)

def invoke(req,call=r.aws,pause=time.sleep):
    e.validate(req)
    execution=dict(operation=req['operation'],commit=os.environ['GITHUB_SHA'],run_id=os.environ['GITHUB_RUN_ID'],run_attempt=os.environ['GITHUB_RUN_ATTEMPT'],request_id=req['request_id'])
    r.initial(execution['commit'],execution['run_id'],execution['run_attempt'])
    result=dict(result='STOP',execution=execution,ssm_command_id=None,expert_id=req['expert_id'])
    def save():r.persist(Path('wp-result/parameterized-result.json'),result)
    save()
    try:
        e.need(call('sts','get-caller-identity')['Account']==r.ACCOUNT,'ACCOUNT_MISMATCH')
        meta=call('ssm','describe-document','--name',r.DOCUMENT,'--document-version','$DEFAULT')['Document']
        actual=call('ssm','get-document','--name',r.DOCUMENT,'--document-version',meta['DocumentVersion'],'--document-format','JSON')
        result['installed_ssm_version']=meta['DocumentVersion'];result['installed_ssm_hash']=meta['Hash']
        approval=json.loads((ROOT/'installed-approval.json').read_text())
        e.need(meta['DocumentVersion']==approval['document_version'] and meta['Hash']==approval['document_sha256'],'APPROVED_INSTALLED_VERSION_OR_SHA_MISMATCH')
        e.need(json.loads(actual['Content'])==json.loads((ROOT/'document.json').read_text()),'PARAMETERIZED_INTERFACE_NOT_INSTALLED')
        request=dict(execution=execution,request=req)
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/'request.json';p.write_text(json.dumps(request,ensure_ascii=False))
            key=f"wp-test/parameterized/{execution['commit']}/{execution['run_id']}-{execution['run_attempt']}/request.json"
            call('s3api','put-object','--bucket',r.BUCKET,'--key',key,'--body',str(p),'--if-none-match','*','--server-side-encryption','AES256')
        params={k:[execution[v]] for k,v in dict(Operation='operation',Commit='commit',RunId='run_id',RunAttempt='run_attempt',RequestId='request_id').items()}
        result['stage']='SEND_INTENT';save()
        sent=call('ssm','send-command','--document-name',r.DOCUMENT,'--document-version',meta['DocumentVersion'],
            '--document-hash',meta['Hash'],'--document-hash-type','Sha256','--instance-ids',r.INSTANCE,
            '--parameters',json.dumps(params),'--max-concurrency','1','--max-errors','0','--timeout-seconds','120')
        command=sent['Command']['CommandId'];result['ssm_command_id']=command;save()
        print('SSM_COMMAND_ID='+command,flush=True)
        for _ in range(260):
            invs=call('ssm','list-command-invocations','--command-id',command,'--instance-id',r.INSTANCE,'--details').get('CommandInvocations',[])
            if not invs:pause(5);continue
            e.need(len(invs)==1,'INVOCATION_COUNT_INVALID');inv=invs[0]
            e.need(inv['InstanceId']==r.INSTANCE and inv['CommandId']==command and inv['DocumentName']==r.DOCUMENT,'INVOCATION_MISMATCH')
            if inv['Status'] in ['Pending','InProgress','Delayed','Cancelling']:pause(5);continue
            plugins=[p for p in inv.get('CommandPlugins',[]) if p['Name']=='parameterizedWp']
            lines=[x.split('=',1)[1] for p in plugins for x in p.get('Output','').splitlines() if x.startswith('PPB_WP_PARAMETERIZED_V1=')]
            e.need(len(lines)==1,'PARAM_RESULT_MISSING');data=json.loads(lines[0]);result['remote']=data
            e.need(data.get('execution')==execution,'FOREIGN_RESULT')
            e.need(inv['Status']=='Success' and plugins[0].get('ResponseCode')==0,'REMOTE_STOP')
            e.need(data.get('expert_id')==req['expert_id'] and data.get('request_id')==req['request_id'],'TARGET_MISMATCH')
            if req['operation']!='read':
                checks=data.get('checks',{})
                e.need(data.get('result')=='PASS' and data.get('fields')==req['fields'] and checks=={
                    'readback_http':200,'create_http':201,'acf_matched':43,'untouched_acf_matched':43-len(req['fields']),'analysts_verified':6,'media_verified':6,
                    'reference_30436_unchanged':True,'journal_unchanged':True,'fingerprint_unchanged':True},'VERIFICATION_INCOMPLETE')
            else:e.need(data.get('result')=='READ_VERIFIED' and data.get('wp_write_requests')==0 and data.get('readback_http')==200 and data.get('create_http')==201,'READ_NOT_VERIFIED')
            result.update(result=data['result'],stage='VERIFIED',wp_edit_url=f"https://csdatw.com/wp-admin/post.php?post={req['expert_id']}&action=edit");save();return result
        raise RuntimeError('OUTCOME_UNKNOWN_DO_NOT_RESEND')
    except (r.Stop,RuntimeError,ValueError,KeyError,OSError) as exc:
        result['stop_code']=str(exc) if isinstance(exc,(r.Stop,RuntimeError)) else 'CLIENT_FAILED';save();return result

def main():
    p=argparse.ArgumentParser();p.add_argument('operation',choices=['read','update_acf','verify']);p.add_argument('--request',type=Path);p.add_argument('--expert-id',type=int);p.add_argument('--request-id');p.add_argument('--fields-json');args=p.parse_args()
    req=json.loads(args.request.read_text()) if args.request else dict(operation=args.operation,expert_id=args.expert_id,request_id=args.request_id,fields={})
    if args.expert_id is not None:req['expert_id']=args.expert_id
    if args.request_id is not None:req['request_id']=args.request_id
    if args.fields_json is not None:req['fields']=json.loads(args.fields_json)
    req['operation']=args.operation
    if args.operation=='read':req['fields']={}
    result=invoke(req)
    print(json.dumps(result,ensure_ascii=False))
    return 0 if result['result'] in ['PASS','READ_VERIFIED'] else 1
if __name__=='__main__':raise SystemExit(main())
