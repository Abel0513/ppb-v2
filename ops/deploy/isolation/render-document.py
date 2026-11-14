#!/usr/bin/env python3
"""Same controlled-deploy document/resource; replace root app evaluation with fixed coordinator."""
import hashlib
import json
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parent

def document():
    d=json.loads((ROOT.parent/'PPB-V2-Controlled-Deploy.ssm.json').read_text())
    # Retain existing validated prepare/download envelope and immutable S3 paths.
    d['description']='V2 isolated controlled release: fixed root file coordinator, nonroot lifecycle, scoped SQL grants, data-only acceptance'
    steps=d['mainSteps'][:2]
    files=['nginx_lex.py','provision.py','socket-acl.py','global-metadata.php','boundary.py','db-probe.php','lifecycle.py','release.py']
    lines=['set -eu','umask 077']
    for name in files:
        sha=hashlib.sha256((ROOT/name).read_bytes()).hexdigest()
        lines += [f'test -f /www/ppb-v2-deploy/scripts/isolation/{name} && test ! -L /www/ppb-v2-deploy/scripts/isolation/{name}',
                  f"printf '%s  %s\\n' {sha} /www/ppb-v2-deploy/scripts/isolation/{name} | sha256sum -c - >/dev/null"]
    for path in ['controlled-deploy.py','stage-release.py','check-release.py']:
        sha=hashlib.sha256((ROOT.parent/path).read_bytes()).hexdigest()
        lines += [f"printf '%s  %s\\n' {sha} /www/ppb-v2-deploy/scripts/{path} | sha256sum -c - >/dev/null"]
    # Installed acceptance lives under scripts/automation and is never taken from app PHP.
    sha=hashlib.sha256((ROOT.parents[1]/'automation/accept-v2.py').read_bytes()).hexdigest()
    lines += [f"printf '%s  %s\\n' {sha} /www/ppb-v2-deploy/automation/accept-v2.py | sha256sum -c - >/dev/null",
              'f="/www/ppb-v2-deploy/releases/ssm-incoming-${SSM_RunId}-${SSM_RunAttempt}/run-${SSM_RunId}-attempt-${SSM_RunAttempt}.tar.gz"',
              'exec python3 /www/ppb-v2-deploy/scripts/isolation/release.py --operation "$SSM_Operation" --archive "$f" --sha256 "$SSM_Sha256" --commit "$SSM_Commit" --run-id "$SSM_RunId" --run-attempt "$SSM_RunAttempt" --confirmation "$SSM_Approval"']
    steps.append(dict(action='aws:runShellScript',name='controlledRelease',inputs=dict(timeoutSeconds='1800',runCommand=lines)))
    d['parameters']['Mode']=dict(type='String',default='release',allowedValues=['release','legacy_readonly'],interpolationType='ENV_VAR')
    d['parameters']['LegacyRequest']=dict(type='String',default='e30=',allowedPattern='^[A-Za-z0-9+/=]+$',interpolationType='ENV_VAR')
    for step in steps:step['precondition']={'StringEquals':['{{ Mode }}','release']}
    legacy=ROOT.parents[1]/'legacy-readonly'
    guarded=['set -eu','umask 077']
    for name in ['prepare.py','dispatch.py','worker.py','source-tokens.php','db-worker.php','dba-once.py']:
        sha=hashlib.sha256((legacy/name).read_bytes()).hexdigest()
        guarded += [f"printf '%s  %s\\n' {sha} /www/ppb-v2-deploy/scripts/legacy-readonly/{name} | sha256sum -c - >/dev/null"]
    guarded += ['exec python3 -I /www/ppb-v2-deploy/scripts/legacy-readonly/dispatch.py']
    steps.append(dict(action='aws:runShellScript',name='legacyReadonly',precondition={'StringEquals':['{{ Mode }}','legacy_readonly']},inputs=dict(timeoutSeconds='180',runCommand=guarded)))
    d['mainSteps']=steps
    return d
if __name__=='__main__':
    data=json.dumps(document(),ensure_ascii=False,indent=2)+'\n';p=ROOT.parent/'PPB-V2-Controlled-Deploy.isolated.ssm.json'
    if '--check' in sys.argv:assert p.read_text()==data
    else:p.write_text(data)
