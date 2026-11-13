"""Collect actual fixed SSM plugin output; never substitute health for business tests."""
import json
import os
import subprocess
import time

def collect(command, sha, call, pause):
    for _ in range(36):
        response=call(command)
        if response['Status'] in ('Pending','InProgress','Delayed'):
            pause(5); continue
        if response['Status']!='Success' or response['ResponseCode']!=0:
            raise RuntimeError('STOP=EC2_FUNCTIONAL_ACCEPTANCE_FAILED_REVIEW_BACKUP_BEFORE_MANUAL_ROLLBACK')
        result=json.loads(response['StandardOutputContent'])
        if result.get('RESULT')!='PPB_V2_FUNCTIONAL_BASELINE_PASS' or result.get('COMMIT')!=sha or type(result.get('CHECKS')) is not int or result.get('CHECKS',0)<4:
            raise RuntimeError('STOP=INVALID_FUNCTIONAL_EVIDENCE')
        return result
    raise RuntimeError('STOP=EC2_ACCEPTANCE_TIMEOUT_NO_AUTOMATIC_REDEPLOY')

if __name__=='__main__':
    def call(command):
        p=subprocess.run(['aws','ssm','get-command-invocation','--command-id',command,'--instance-id','i-0135d6b2fac7a54c2','--plugin-name','functionalAcceptance','--output','json'],capture_output=True,text=True)
        if p.returncode: return {'Status':'Pending'}
        return json.loads(p.stdout)
    result=collect(os.environ['PPB_SSM_COMMAND_ID'],os.environ['GITHUB_SHA'],call,time.sleep)
    with open(os.environ['GITHUB_STEP_SUMMARY'],'a') as out:
        out.write('\nEC2 independent acceptance: '+json.dumps(result)+'\nBusiness WP/DB/LIFF acceptance is NOT_RUN.\n')
    print(json.dumps(result))
