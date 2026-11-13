#!/usr/bin/env python3
"""User-authorized V2 application auto-merge. Trusted main controller, no agent token.
Control plane and WP changes remain out of this automatic application path.
"""
import importlib.util
import json
import os
from pathlib import Path
import re
ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('control',ROOT/'control.py');c=importlib.util.module_from_spec(s);s.loader.exec_module(c)
CHECKS={'Offline release guards (no DB, no AWS)','automation-tests','V2 isolation and lifecycle tests'}
REPO='Abel0513/ppb-v2'

def classify(files):
    names=[x['filename'] for x in files]
    c.need(0<len(names)<=200,'EMPTY_OR_EXCESSIVE_DIFF')
    for name in names:
        p=Path(name)
        c.need(not p.is_absolute() and '..' not in p.parts and not any(x.startswith('.') for x in p.parts),'PROTECTED_PATH')
        c.need(p.parts[0] in {'app','routes','resources','public','config','database','tests','docs'} or name in {'composer.json','composer.lock','package.json','package-lock.json'},'CONTROL_PLANE_REQUIRES_REVIEW')
        c.need(not any(x.lower() in {'line.php','db.php','credentials.json'} for x in p.parts)
               and not name.lower().endswith(('.sql','.sqlite','.pem','.key')),'CREDENTIAL_OR_LEGACY_FILE')
        c.need(not name.startswith(('public/uploads/','public/storage/','.env')),'RUNTIME_OR_SECRET_FILE')
    return dict(migrations=any(n.startswith('database/') for n in names),dependencies=any(n in {'composer.json','composer.lock','package.json','package-lock.json'} for n in names))

def validate_pr(pr,files,checks,main_sha):
    c.need(pr.get('state')=='open' and pr['base']['ref']=='main' and pr['head']['repo']['full_name']==REPO,'PR_SCOPE_MISMATCH')
    c.need(bool(re.fullmatch('codex/(?:v2-[a-z0-9-]+|issue-[1-9][0-9]*)',pr['head']['ref'])),'BRANCH_NOT_AUTHORIZED')
    c.need(pr.get('_verified_merge_base')==main_sha,'BASE_CHANGED_RETEST_REQUIRED')
    scope=classify(files)
    c.need(all(any(x['name']==name and x['status']=='completed' and x['conclusion']=='success'
                    and x['app']['slug']=='github-actions' for x in checks) for name in CHECKS),'CI_NOT_GREEN')
    return scope

def main():
    event=json.loads(Path(os.environ['GITHUB_EVENT_PATH']).read_text())
    run=event['workflow_run']
    if run.get('conclusion')!='success' or run.get('event')!='pull_request':return
    c.need(run['head_repository']['full_name']==REPO,'FOREIGN_RUN')
    matches=c.pages('pulls?state=open&head=Abel0513:'+run['head_branch'])
    c.need(len(matches)==1,'PR_NOT_UNIQUE');pr=c.api('pulls/'+str(matches[0]['number']))
    c.need(pr['head']['sha']==run['head_sha'],'STALE_RUN')
    files=c.pages('pulls/'+str(pr['number'])+'/files')
    # This policy never auto-merges its own privileged controller changes.
    if any(x['filename'].split('/')[0] not in {'app','routes','resources','public','config','database','tests','docs'} and x['filename'] not in {'composer.json','composer.lock','package.json','package-lock.json'} for x in files):return
    checks=c.api('commits/'+pr['head']['sha']+'/check-runs?per_page=100')['check_runs']
    main_sha=c.api('git/ref/heads/main')['object']['sha']
    pr['_verified_merge_base']=c.api('compare/'+main_sha+'...'+pr['head']['sha'])['merge_base_commit']['sha']
    validate_pr(pr,files,checks,main_sha)
    merged=c.api('pulls/'+str(pr['number'])+'/merge','PUT',{'sha':pr['head']['sha'],'merge_method':'squash'})
    c.need(merged.get('merged') is True,'MERGE_NOT_COMPLETED')
    c.need(c.api('commits/'+merged['sha'])['parents'][0]['sha']==main_sha,'MAIN_MOVED_AT_MERGE_DO_NOT_DEPLOY')
    # GITHUB_TOKEN merge events do not start another workflow. Explicit dispatch
    # is required; receipt fixes the merged SHA and source PR for release gate.
    c.api('actions/workflows/ppb-v2-release-package.yml/dispatches','POST',{
        'ref':'main','inputs':{'operation':'deploy','confirmation':'DEPLOY-PPB-V2','source_pr':str(pr['number']),'expected_commit':merged['sha']}})
    print(json.dumps(dict(result='MERGED_RELEASE_DISPATCHED',pr=pr['number'],commit=merged['sha'])))
if __name__=='__main__':main()
