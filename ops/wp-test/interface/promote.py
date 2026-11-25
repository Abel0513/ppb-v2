#!/usr/bin/env python3
"""Reviewed protected-main job only; no branch runtime package is installed."""
import importlib.util
import json
import os
import time
from pathlib import Path
ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('remote',ROOT.parent/'remote.py');r=importlib.util.module_from_spec(s);s.loader.exec_module(r)

def promote(call=r.aws,parameterized=False):
    r.require(os.environ.get('GITHUB_REF')=='refs/heads/main' and os.environ.get('GITHUB_EVENT_NAME') in {'workflow_dispatch','push'},'PROTECTED_MAIN_REQUIRED')
    actor=call('sts','get-caller-identity')
    r.require(actor['Account']==r.ACCOUNT and '/PPB-V2-WP-Interface-Promoter/' in actor['Arn'],'WP_PROMOTION_ROLE_REQUIRED')
    target=ROOT.parent/'parameterized/document.json' if parameterized else ROOT/'document.json'
    local=json.loads(target.read_text())
    old=call('ssm','get-document','--name',r.DOCUMENT,'--document-version','$DEFAULT','--document-format','JSON')
    if json.loads(old['Content'])==local:version=old['DocumentVersion']
    else:
        changed=call('ssm','update-document','--name',r.DOCUMENT,'--document-version','$LATEST','--document-format','JSON',
                     '--content','file://'+str(target))
        version=changed['DocumentDescription']['DocumentVersion']
    for _ in range(30):
        meta=call('ssm','describe-document','--name',r.DOCUMENT,'--document-version',version)['Document']
        if meta.get('Status')=='Active':break
        r.require(meta.get('Status') not in ['Failed','Deleting'],'PROMOTION_DOCUMENT_FAILED')
        time.sleep(2)
    else:raise r.Stop('PROMOTION_DOCUMENT_NOT_ACTIVE')
    actual=call('ssm','get-document','--name',r.DOCUMENT,'--document-version',version,'--document-format','JSON')
    r.require(json.loads(actual['Content'])==local,'PROMOTION_READBACK_MISMATCH')
    call('ssm','update-document-default-version','--name',r.DOCUMENT,'--document-version',version)
    default=call('ssm','get-document','--name',r.DOCUMENT,'--document-version','$DEFAULT','--document-format','JSON')
    r.require(default['DocumentVersion']==version and json.loads(default['Content'])==local,'PROMOTION_DEFAULT_READBACK_MISMATCH')
    print('FIXED_INTERFACE_PROMOTED_VERSION='+version)
if __name__=='__main__':
    import argparse
    p=argparse.ArgumentParser();p.add_argument('--parameterized',action='store_true');a=p.parse_args();promote(parameterized=a.parameterized)
