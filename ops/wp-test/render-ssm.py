#!/usr/bin/env python3
"""Render the fixed document from source; never accept runtime shell or URLs."""
import hashlib
import copy
import json
from pathlib import Path
import sys
ROOT = Path(__file__).resolve().parent
PARAMETERS = {
    'Commit': {'type': 'String', 'interpolationType': 'ENV_VAR', 'allowedPattern': '^[a-f0-9]{40}$'},
    'PackageSha256': {'type': 'String', 'interpolationType': 'ENV_VAR', 'allowedPattern': '^[a-f0-9]{64}$'},
    'RunId': {'type': 'String', 'interpolationType': 'ENV_VAR', 'allowedPattern': '^[1-9][0-9]{0,19}$'},
    'RunAttempt': {'type': 'String', 'interpolationType': 'ENV_VAR', 'allowedPattern': '^[1-9][0-9]{0,19}$'},
    'Approval': {'type': 'String', 'interpolationType': 'ENV_VAR', 'allowedValues': ['CREATE-ONE-EXPERT-DRAFT']},
}

def document():
    approved=hashlib.sha256((ROOT/'ppb_wpn2_verified.sh').read_bytes()).hexdigest()
    parameters=copy.deepcopy(PARAMETERS)
    parameters['PackageSha256']={'type':'String','interpolationType':'ENV_VAR','allowedValues':[approved]}
    prepare = '''set -eu
    test "$(id -u)" = 0
    : "${SSM_Commit:?}" "${SSM_PackageSha256:?}" "${SSM_RunId:?}" "${SSM_RunAttempt:?}" "${SSM_Approval:?}"
    test "$SSM_Approval" = CREATE-ONE-EXPERT-DRAFT
    test "$SSM_PackageSha256" = APPROVED_PACKAGE_SHA
    root=/www/ppb-v2-deploy
    test -d "$root" && test ! -L "$root"
    test ! -L "$root/wp-test"
    install -d -m 700 "$root/wp-test"
    download="$root/wp-test/ssm-incoming-${SSM_RunId}-${SSM_RunAttempt}"
    test ! -e "$download"
    install -d -m 700 "$download"'''
    run = '''set -eu
    : "${SSM_Commit:?}" "${SSM_PackageSha256:?}" "${SSM_RunId:?}" "${SSM_RunAttempt:?}" "${SSM_Approval:?}"
    test "$SSM_Approval" = CREATE-ONE-EXPERT-DRAFT
    test "$SSM_PackageSha256" = APPROVED_PACKAGE_SHA
    download="/www/ppb-v2-deploy/wp-test/ssm-incoming-${SSM_RunId}-${SSM_RunAttempt}"
    package="$download/run-${SSM_RunId}-attempt-${SSM_RunAttempt}.sh"
    test -d "$download" && test ! -L "$download"
    test -f "$package" && test ! -L "$package"
    chmod 600 "$package"
    printf '%s  %s\\n' APPROVED_PACKAGE_SHA "$package" | sha256sum -c - >"$download/integrity.log"
    export PPB_WP_GITHUB_COMMIT="$SSM_Commit" PPB_WP_GITHUB_RUN_ID="$SSM_RunId" PPB_WP_GITHUB_RUN_ATTEMPT="$SSM_RunAttempt"
    PPB_WP_PACKAGE_EXIT_CODE=0
    bash "$package" >"$download/execution.log" 2>&1 || PPB_WP_PACKAGE_EXIT_CODE=$?
    export PPB_WP_PACKAGE_EXIT_CODE
    python3 - <<'PY_COLLECT'
'''
    prepare=prepare.replace('APPROVED_PACKAGE_SHA',approved)
    run=run.replace('APPROVED_PACKAGE_SHA',approved)
    clean = lambda s: [line[4:] if line.startswith('    ') else line for line in s.splitlines()]
    # Python heredoc must retain its own indentation; normalize only shell prefix.
    commands = clean(run.split("    python3 - <<'PY_COLLECT'\n", 1)[0])
    commands += ["python3 - <<'PY_COLLECT'", *(ROOT / 'result-envelope.py').read_text().splitlines(), 'PY_COLLECT']
    return dict(schemaVersion='2.2', description='Issue 12: pinned WPF6 package, one Expert draft, correlated result only',
                parameters=parameters, mainSteps=[
                    dict(action='aws:runShellScript', name='prepareWpTestDownload',
                         inputs=dict(timeoutSeconds='30', runCommand=clean(prepare))),
                    dict(action='aws:downloadContent', name='downloadPinnedWpPackage', inputs=dict(
                        sourceType='S3', sourceInfo=json.dumps(dict(path='https://s3.us-east-1.amazonaws.com/ppb-v2-release-artifacts-429724121147-use1/wp-test/{{Commit}}/run-{{RunId}}-attempt-{{RunAttempt}}.sh'), separators=(',', ':')),
                        destinationPath='/www/ppb-v2-deploy/wp-test/ssm-incoming-{{RunId}}-{{RunAttempt}}')),
                    dict(action='aws:runShellScript', name='expertDraftTest', inputs=dict(timeoutSeconds='720', runCommand=commands))])

if __name__ == '__main__':
    target = ROOT / 'PPB-V2-WP-Draft-Test.ssm.json'
    text = json.dumps(document(), ensure_ascii=False, indent=2) + '\n'
    if '--check' in sys.argv:
        assert target.read_text() == text, 'SSM document differs from source'
    else:
        target.write_text(text)
