#!/usr/bin/env python3
"""Actual V2-role read-only audit. Never uploads/executes a candidate or writes DB."""
import importlib.util
import json
import os
from pathlib import Path
import time
ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('client',ROOT/'release-client.py');c=importlib.util.module_from_spec(s);s.loader.exec_module(c)

def main():
    os.umask(0o077)
    result=dict(commit=os.environ['GITHUB_SHA'],run_id=os.environ['GITHUB_RUN_ID'],
                auto_deploy_disabled=os.environ.get('PPB_AUTO_DEPLOY')=='false',
                new_executor_installed=False,database_isolation='UNVERIFIED',legacy_unchanged='NO_WRITE_OPERATIONS_REQUESTED_NOT_A_FULL_STATE_PROOF',reads={})
    result['identity']=c.aws('sts','get-caller-identity')
    c.need(result['identity']['Account']==c.ACCOUNT,'WRONG_ACCOUNT')
    for doc in ['PPB-V2-Controlled-Deploy','PPB-V2-Preflight']:
        try:
            value=c.aws('ssm','get-document','--name',doc,'--document-version','$DEFAULT','--document-format','JSON')
            data=json.loads(value['Content'])
            result['reads'][doc]=dict(status='READ_SUCCESS',version=value['DocumentVersion'],steps=[x['name'] for x in data.get('mainSteps',[])])
            if doc==c.DOC:result['new_executor_installed']=data==json.loads((ROOT/'PPB-V2-Controlled-Deploy.isolated.ssm.json').read_text())
        except RuntimeError as exc:result['reads'][doc]=dict(status=str(exc))
    try:
        policies=c.aws('iam','list-role-policies','--role-name','PPB-V2-GitHub-Deploy-Role')
        result['reads']['role_policy_names']=dict(status='READ_SUCCESS',names=policies.get('PolicyNames',[]))
    except RuntimeError as exc:result['reads']['role_policy_names']=dict(status=str(exc))
    # The only remote execution is the already-approved immutable preflight.
    sent=c.aws('ssm','send-command','--document-name','PPB-V2-Preflight','--instance-ids',c.INSTANCE,
              '--max-concurrency','1','--max-errors','0','--timeout-seconds','60',
              '--comment','V2 authority read-only run '+os.environ['GITHUB_RUN_ID'])
    command=sent['Command']['CommandId'];result['ssm_command_id']=command
    root=Path('v2-result');root.mkdir(exist_ok=True)
    (root/'authority.json').write_text(json.dumps(result,indent=2))
    print('SSM_COMMAND_ID='+command,flush=True)
    for _ in range(60):
        try:inv=c.aws('ssm','get-command-invocation','--command-id',command,'--instance-id',c.INSTANCE)
        except RuntimeError as exc:
            if str(exc)=='AWS_InvocationDoesNotExist':time.sleep(3);continue
            raise
        if inv['Status'] in {'Pending','InProgress','Delayed'}:time.sleep(3);continue
        result['preflight_status']=inv['Status'];result['preflight_exit']=inv['ResponseCode']
        # Only existing fixed PASS markers, not the raw host or secret-bearing logs.
        result['preflight_pass']=inv['Status']=='Success' and inv['ResponseCode']==0 and 'PPB_V2_PREFLIGHT_READONLY_PASS' in inv.get('StandardOutputContent','')
        break
    else:result['preflight_pass']=False;result['preflight_status']='OUTCOME_UNKNOWN_DO_NOT_RESEND'
    (root/'authority.json').write_text(json.dumps(result,indent=2))
    print(json.dumps(result))
    return 0 if result.get('preflight_pass') else 1
if __name__=='__main__':raise SystemExit(main())
