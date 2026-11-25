#!/usr/bin/env python3
"""Read configuration/permission evidence only. No deployment or role chaining."""
import importlib.util
import json
import os
import re
from pathlib import Path
import urllib.request
import urllib.error

ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('remote',ROOT/'remote.py');r=importlib.util.module_from_spec(s);s.loader.exec_module(r)

def main():
    result=dict(schema_version=1,execution=dict(commit=os.environ['GITHUB_SHA'],run_id=os.environ['GITHUB_RUN_ID']),
        PPB_AUTO_DEPLOY=os.environ.get('PPB_AUTO_DEPLOY_VALUE','')=='true',
        PPB_AUTO_DEPLOY_configured=bool(os.environ.get('PPB_AUTO_DEPLOY_VALUE','')),
        PPB_SUBSCRIPTION_RUNNER_READY=os.environ.get('PPB_SUBSCRIPTION_RUNNER_READY_VALUE','')=='true',
        publisher_token_present=os.environ.get('PPB_PUBLISH_TOKEN_PRESENT')=='true',
        deploy_document_version_configured=bool(re.fullmatch('[1-9][0-9]*',os.environ.get('PPB_DEPLOY_DOCUMENT_VERSION_VALUE',''))),
        deploy_document_sha256_configured=bool(re.fullmatch('[a-f0-9]{64}',os.environ.get('PPB_DEPLOY_DOCUMENT_SHA256_VALUE',''))),
        aws_role_used=r.ROLE,assumed_deploy_role=False,production_mutations=0)
    reads={
        'wp_role_inline_policy':('iam','get-role-policy','--role-name','PPB-V2-GitHub-WP-Draft-Test-Role','--policy-name','PPB-WP-Draft-Runtime'),
        'controlled_deploy_document':('ssm','get-document','--name','PPB-V2-Controlled-Deploy','--document-version','$DEFAULT'),
        'preflight_document':('ssm','get-document','--name','PPB-V2-Preflight','--document-version','$DEFAULT')}
    result['read_authority']={}
    for name,args in reads.items():
        try:
            data=r.aws(*args)
            # Report only action names and explicit resource scope, no role credentials.
            if name=='wp_role_inline_policy':result['read_authority'][name]=dict(status='READ_SUCCESS',policy=data.get('PolicyDocument'))
            else:result['read_authority'][name]=dict(status='READ_SUCCESS',version=data.get('DocumentVersion'),content_sha256=__import__('hashlib').sha256(data.get('Content','').encode()).hexdigest())
        except r.Stop as exc:result['read_authority'][name]=dict(status=str(exc))
    request=urllib.request.Request('https://api.github.com/repos/Abel0513/ppb-v2/actions/runners',headers={'Authorization':'Bearer '+os.environ['GH_TOKEN'],'Accept':'application/vnd.github+json'})
    try:
        with urllib.request.urlopen(request,timeout=30) as response:data=json.load(response)
        result['runner_inventory']=[dict(status=x.get('status'),busy=x.get('busy'),labels=[l['name'] for l in x.get('labels',[])]) for x in data.get('runners',[])]
    except urllib.error.HTTPError as exc:result['runner_inventory']=dict(status='UNVERIFIED',http=exc.code)
    except OSError:result['runner_inventory']=dict(status='UNVERIFIED',reason='API_UNAVAILABLE')
    r.persist(Path('wp-result/v2-capabilities.json'),result)
    print(json.dumps(result,ensure_ascii=False))
if __name__=='__main__':main()
