#!/usr/bin/env python3
"""Authorized release provenance; automatic app/db/dependency changes stay V2-only."""
import importlib.util
import json
import os
from pathlib import Path
ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('autonomy',ROOT/'autonomy.py');a=importlib.util.module_from_spec(s);s.loader.exec_module(a)
c=a.c

def gate(pr,current,files,checks):
    c.need(pr.get('merged') is True and pr['base']['ref']=='main' and pr['head']['repo']['full_name']==a.REPO,'NOT_MERGED_V2_PR')
    c.need(current==pr['merge_commit_sha'],'MERGE_NOT_CURRENT_MAIN')
    c.need(bool(__import__('re').fullmatch('codex/(?:v2-[a-z0-9-]+|issue-[1-9][0-9]*)',pr['head']['ref'])),'UNAUTHORIZED_RELEASE_BRANCH')
    a.classify(files)
    c.need(pr.get('_verified_parent_is_ancestor') is True,'MERGE_PARENT_NOT_TESTED')
    c.need(all(any(x['name']==name and x['status']=='completed' and x['conclusion']=='success' and x['app']['slug']=='github-actions' for x in checks) for name in a.CHECKS),'CI_NOT_GREEN')
    actor=pr.get('merged_by',{})
    c.need((actor.get('type')=='User' and c.trusted(actor['login'])) or actor.get('login')=='github-actions[bot]','MERGER_NOT_AUTHORIZED')
    return True

def main():
    event=json.loads(Path(os.environ['GITHUB_EVENT_PATH']).read_text())
    inputs=event.get('inputs',{})
    if event.get('action')=='closed':pr=event['pull_request']
    elif inputs.get('source_pr'):
        c.need(os.environ.get('PPB_AUTO_DEPLOY')!='false','AUTOMATIC_RELEASE_DISABLED')
        c.need(str(inputs['source_pr']).isdigit(),'INVALID_SOURCE_PR')
        pr=c.api('pulls/'+str(int(inputs['source_pr'])))
    else:
        # Explicit workflow_dispatch keeps the existing operator-approved route.
        c.need(os.environ.get('GITHUB_EVENT_NAME')=='workflow_dispatch','UNKNOWN_RELEASE_EVENT')
        c.need(os.environ.get('GITHUB_REF')=='refs/heads/main','NOT_MAIN')
        c.need(c.trusted(os.environ['GITHUB_ACTOR']),'UNTRUSTED_OPERATOR')
        c.need(c.api('git/ref/heads/main')['object']['sha']==os.environ['GITHUB_SHA'],'MAIN_MOVED_REDISPATCH_REQUIRED')
        with open(os.environ['GITHUB_OUTPUT'],'a') as out:out.write('approved=true\n')
        return
    files=c.pages('pulls/'+str(pr['number'])+'/files')
    # Privileged control-plane PRs are merged/reviewed separately, never treated
    # as an ordinary application release (including this installation PR).
    try:
        scope=a.classify(files)
        if not scope['application']:raise RuntimeError('NO_APPLICATION_CHANGE')
    except RuntimeError:
        with open(os.environ['GITHUB_OUTPUT'],'a') as out:out.write('approved=false\n')
        return
    c.need(json.loads((ROOT/'V2_AUTOMATION_ACCEPTANCE.json').read_text())['ready'] or a.acceptance_proof(pr,files),'V2_AUTOMATIC_PUBLICATION_PENDING_REAL_ACCEPTANCE')
    current=c.api('git/ref/heads/main')['object']['sha']
    if inputs.get('expected_commit'):c.need(inputs['expected_commit']==current,'DISPATCH_COMMIT_MOVED')
    parent=c.api('commits/'+current)['parents'][0]['sha']
    pr['_verified_parent_is_ancestor']=c.api('compare/'+parent+'...'+pr['head']['sha'])['merge_base_commit']['sha']==parent
    checks=c.api('commits/'+pr['head']['sha']+'/check-runs?per_page=100')['check_runs']
    gate(pr,current,files,checks)
    with open(os.environ['GITHUB_OUTPUT'],'a') as out:out.write('approved=true\n')
if __name__=='__main__':main()
