#!/usr/bin/env python3
"""One administrator installation of the reviewed WP-only fixed document.
No IAM changes, EC2 commands, S3 writes or WP execution. No GitHub credential.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time
ACCOUNT='429724121147'
REGION='us-east-1'
DOCUMENT='PPB-V2-WP-Draft-Test'
OLD_HASH='181510ff4e60c224de988c54368f3f868284f2c0d3a240ef320fd7340db7e7a6'
ALLOWED={('sts','get-caller-identity'),('ssm','get-document'),('ssm','describe-document'),('ssm','update-document'),('ssm','update-document-default-version')}
def need(ok,code):
    if not ok:raise RuntimeError(code)
def aws(*args):
    need(args[:2] in ALLOWED,'AWS_OPERATION_DENIED')
    p=subprocess.run(['aws',*args,'--region',REGION,'--output','json','--no-cli-pager'],capture_output=True,text=True,timeout=90,env=dict(os.environ,AWS_MAX_ATTEMPTS='1',AWS_RETRY_MODE='standard',AWS_PAGER=''))
    if p.returncode:
        m=re.search(r'\(([A-Za-z0-9_.-]+)\)',p.stderr)
        raise RuntimeError('AWS_'+(m.group(1) if m else 'CLI_FAILED'))
    return json.loads(p.stdout or '{}')
def install(raw,call=aws,pause=time.sleep):
    target=json.loads(raw)
    actor=call('sts','get-caller-identity')
    need(actor['Account']==ACCOUNT,'ACCOUNT_MISMATCH')
    need(not any('/'+role+'/' in actor['Arn'] for role in ['PPB-V2-GitHub-Deploy-Role','PPB-V2-GitHub-WP-Draft-Test-Role']),'EXISTING_ADMIN_OR_WP_PROMOTER_REQUIRED')
    def get(v):return call('ssm','get-document','--name',DOCUMENT,'--document-version',v,'--document-format','JSON')
    def meta(v):return call('ssm','describe-document','--name',DOCUMENT,'--document-version',v)['Document']
    old=get('$DEFAULT');oldmeta=meta(old['DocumentVersion'])
    changed=False
    if json.loads(old['Content'])==target:
        version=old['DocumentVersion']
    else:
        need(old['DocumentVersion']=='2' and oldmeta['Hash']==OLD_HASH,'UNEXPECTED_DEFAULT_STOP_NO_OVERWRITE')
        latest=get('$LATEST')
        if json.loads(latest['Content'])==target:version=latest['DocumentVersion']
        else:
            need(latest['DocumentVersion']==old['DocumentVersion'] and json.loads(latest['Content'])==json.loads(old['Content']),'UNEXPECTED_LATEST_STOP_NO_OVERWRITE')
            with tempfile.TemporaryDirectory(prefix='ppb-wp-reviewed-') as tmp:
                p=Path(tmp)/'document.json';p.write_bytes(raw);p.chmod(0o600)
                update=call('ssm','update-document','--name',DOCUMENT,'--document-version',latest['DocumentVersion'],'--document-format','JSON','--content','file://'+str(p))
            version=update['DocumentDescription']['DocumentVersion']
            changed=True
        for _ in range(30):
            state=meta(version)
            if state['Status']=='Active':break
            need(state['Status'] not in ['Failed','Deleting'],'DOCUMENT_NOT_ACTIVE');pause(2)
        else:raise RuntimeError('DOCUMENT_ACTIVE_TIMEOUT')
        need(json.loads(get(version)['Content'])==target,'CANDIDATE_READBACK_MISMATCH')
        current=get('$DEFAULT');currentmeta=meta(current['DocumentVersion'])
        need(current['DocumentVersion']==old['DocumentVersion'] and currentmeta['Hash']==oldmeta['Hash'],'DEFAULT_CHANGED_CONCURRENTLY_STOP')
        call('ssm','update-document-default-version','--name',DOCUMENT,'--document-version',version)
        changed=True
    final=get('$DEFAULT');state=meta(version)
    need(final['DocumentVersion']==version and json.loads(final['Content'])==target and state['Status']=='Active' and state.get('HashType')=='Sha256','FINAL_DEFAULT_NOT_VERIFIED')
    return dict(result='WP_PARAMETERIZED_INTERFACE_INSTALL_COMPLETE',account=ACCOUNT,document=DOCUMENT,previous_default=old['DocumentVersion'],document_version=version,document_sha256=state['Hash'],source_sha256=hashlib.sha256(raw).hexdigest(),document_changed=changed,iam_changes=0,ec2_commands_sent=0,wp_writes=0)
