#!/usr/bin/env python3
"""GitHub OIDC -> private S3 -> fixed SSM -> correlated WP result. No SSH."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

ACCOUNT = '429724121147'
REGION = 'us-east-1'
INSTANCE = 'i-0135d6b2fac7a54c2'
BUCKET = 'ppb-v2-release-artifacts-429724121147-use1'
DOCUMENT = 'PPB-V2-WP-Draft-Test'
ROLE = f'arn:aws:iam::{ACCOUNT}:role/PPB-V2-GitHub-WP-Draft-Test-Role'
ROOT = Path(__file__).resolve().parent
PREFIX = 'PPB_WP_RESULT_V1='

class Stop(Exception):
    pass

def require(ok, code):
    if not ok:
        raise Stop(code)

def aws(*args):
    env = dict(os.environ, AWS_MAX_ATTEMPTS='1', AWS_RETRY_MODE='standard', AWS_PAGER='')
    p = subprocess.run(['aws', *args, '--region', REGION, '--output', 'json', '--no-cli-pager'],
                       capture_output=True, text=True, env=env, timeout=90)
    if p.returncode:
        # Never return CLI stderr, credentials, command output or raw WP bodies.
        code = re.search(r'\(([A-Za-z0-9_.-]+)\)', p.stderr)
        raise Stop('AWS_' + (code.group(1) if code else 'CLI_FAILED'))
    return json.loads(p.stdout or '{}')

def persist(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_suffix('.tmp')
    with temp.open('w') as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
        f.flush()
        os.fsync(f.fileno())
    temp.replace(path)

def initial(commit, run_id, attempt):
    require(bool(re.fullmatch('[a-f0-9]{40}', commit)), 'INVALID_COMMIT')
    require(bool(re.fullmatch('[1-9][0-9]{0,19}', run_id)), 'INVALID_RUN_ID')
    require(bool(re.fullmatch('[1-9][0-9]{0,19}', attempt)), 'INVALID_RUN_ATTEMPT')
    return dict(schema_version=1, result='STOP', stage='OIDC', commit=commit,
                github_run_id=run_id, github_run_attempt=attempt,
                actions_run_url=f'https://github.com/Abel0513/ppb-v2/actions/runs/{run_id}',
                ssm_command_id=None, wp_expert_id=None, stop_code='OIDC_NOT_COMPLETED')

def validate_envelope(output, result):
    lines = [line[len(PREFIX):] for line in output.splitlines() if line.startswith(PREFIX)]
    require(len(lines) == 1, 'SSM_RESULT_MISSING_OR_TRUNCATED')
    data = json.loads(lines[0])
    require(data.get('execution') == dict(commit=result['commit'], run_id=result['github_run_id'],
                                        run_attempt=result['github_run_attempt']), 'STALE_OR_FOREIGN_RESULT')
    require(data.get('schema_version') == 1, 'RESULT_SCHEMA_MISMATCH')
    # Only return the fixed collector's explicit schema, never raw remote stdout.
    keys = {'schema_version', 'execution', 'result', 'stop_code', 'wp_expert_id', 'candidate_expert_id',
            'wp_status', 'post_type', 'acf_matched', 'recommendations', 'create_http',
            'readback_http', 'wp_edit_url', 'reference_30436_unchanged', 'package_exit_code'}
    require(set(data) <= keys, 'RESULT_UNEXPECTED_KEYS')
    if data.get('result') == 'PASS':
        number = data.get('wp_expert_id')
        require(type(number) is int and number > 0 and number != 30436, 'NEW_ID_INVALID')
        require(data.get('wp_status') == 'draft' and data.get('post_type') == 'expert', 'NOT_EXPERT_DRAFT')
        require(data.get('acf_matched') == 43 and data.get('readback_http') == 200, 'ACF_READBACK_NOT_VERIFIED')
        require(isinstance(data.get('recommendations'), dict) and len(data['recommendations']) == 6
                and set(data['recommendations'].values()) == {'Codex測試'}, 'SIX_RECOMMENDATIONS_NOT_VERIFIED')
        require(data.get('wp_edit_url') in [f'https://{host}/wp-admin/post.php?post={number}&action=edit'
                                          for host in ['csdatw.com', 'www.csdatw.com']], 'EDIT_URL_INVALID')
    return data

def execute(result, path, timeout=900, call=aws, clock=time.monotonic, sleep=time.sleep):
    def save(stage):
        result['stage'] = stage
        persist(path, result)
    save('AWS_IDENTITY')
    require(call('sts', 'get-caller-identity').get('Account') == ACCOUNT, 'WRONG_AWS_ACCOUNT')
    save('SSM_DOCUMENT')
    meta = call('ssm', 'describe-document', '--name', DOCUMENT, '--document-version', '$DEFAULT')['Document']
    version = meta['DocumentVersion']
    require(re.fullmatch('[1-9][0-9]*', version) and meta.get('HashType') == 'Sha256'
            and re.fullmatch('[a-f0-9]{64}', meta.get('Hash', '')), 'DOCUMENT_METADATA_INVALID')
    actual = call('ssm', 'get-document', '--name', DOCUMENT, '--document-version', version, '--document-format', 'JSON')
    require(json.loads(actual['Content']) == json.loads((ROOT / 'PPB-V2-WP-Draft-Test.ssm.json').read_text()),
            'INSTALLED_SSM_DOCUMENT_DIFFERS_FROM_COMMIT')
    result.update(ssm_document_version=version, ssm_document_sha256=meta['Hash'])
    package = ROOT / 'ppb_wpn2_verified.sh'
    digest = hashlib.sha256(package.read_bytes()).hexdigest()
    key = f"wp-test/{result['commit']}/run-{result['github_run_id']}-attempt-{result['github_run_attempt']}.sh"
    save('S3_UPLOAD')
    call('s3api', 'put-object', '--bucket', BUCKET, '--key', key, '--body', str(package),
         '--if-none-match', '*', '--server-side-encryption', 'AES256', '--metadata', 'sha256=' + digest)
    parameters = {k: [v] for k, v in dict(Commit=result['commit'], PackageSha256=digest,
                  RunId=result['github_run_id'], RunAttempt=result['github_run_attempt'],
                  Approval='CREATE-ONE-EXPERT-DRAFT').items()}
    save('SSM_SEND_INTENT')
    # Exactly one SendCommand invocation. Ambiguous AWS responses are never resent.
    sent = call('ssm', 'send-command', '--document-name', DOCUMENT, '--document-version', version,
                '--document-hash', meta['Hash'], '--document-hash-type', 'Sha256', '--instance-ids', INSTANCE,
                '--parameters', json.dumps(parameters), '--timeout-seconds', '120',
                '--max-concurrency', '1', '--max-errors', '0',
                '--comment', f"WP draft GitHub run {result['github_run_id']} attempt {result['github_run_attempt']}")
    command = sent['Command']['CommandId']
    require(bool(re.fullmatch('[a-f0-9-]{36}', command)), 'COMMAND_ID_INVALID')
    result['ssm_command_id'] = command
    save('SSM_WAIT')
    if call is aws:
        print('SSM_COMMAND_ID=' + command, flush=True)
    deadline = clock() + timeout
    while clock() < deadline:
        try:
            response = call('ssm', 'list-command-invocations', '--command-id', command,
                            '--instance-id', INSTANCE, '--details')
        except Stop as e:
            if str(e) in ['AWS_ThrottlingException', 'AWS_InvocationDoesNotExist']:
                sleep(5)
                continue
            raise
        invocations = response.get('CommandInvocations', [])
        if not invocations:
            sleep(5)
            continue
        require(len(invocations) == 1, 'INVOCATION_COUNT_INVALID')
        inv = invocations[0]
        require(inv.get('CommandId') == command and inv.get('InstanceId') == INSTANCE, 'INVOCATION_TARGET_MISMATCH')
        require(inv.get('DocumentName', DOCUMENT) == DOCUMENT, 'INVOCATION_DOCUMENT_MISMATCH')
        result['ssm_steps'] = [dict(name=p['Name'], status=p.get('Status'), response_code=p.get('ResponseCode'))
                               for p in inv.get('CommandPlugins', [])]
        result['ssm_status'] = inv['Status']
        persist(path, result)
        if inv['Status'] in ['Pending', 'InProgress', 'Delayed', 'Cancelling']:
            sleep(5)
            continue
        plugins = [p for p in inv.get('CommandPlugins', []) if p['Name'] == 'expertDraftTest']
        if len(plugins) == 1 and plugins[0].get('Output'):
            envelope = validate_envelope(plugins[0]['Output'], result)
            result['wp'] = envelope
            result['wp_expert_id'] = envelope.get('wp_expert_id')
            if inv['Status'] == 'Success' and plugins[0].get('ResponseCode') == 0 and envelope.get('result') == 'PASS':
                result.update(result='PASS', stop_code=None)
                save('VERIFIED')
                return
            result['remote_stop_code'] = envelope.get('stop_code')
        raise Stop('SSM_OR_WP_VERIFICATION_FAILED')
    raise Stop('SSM_OUTCOME_UNKNOWN_DO_NOT_RESEND')

def summary(result, path):
    lines = ['## WordPress Expert 遠端驗收', '', '| 項目 | 結果 |', '|---|---|',
             f"| RESULT | {result['result']} |", f"| Actions Run | {result['actions_run_url']} |",
             f"| Commit | {result['commit']} |", f"| 階段 | {result['stage']} |",
             f"| SSM Command ID | {result.get('ssm_command_id') or '未取得'} |",
             f"| WP Expert ID | {result.get('wp_expert_id') or '未取得'} |",
             f"| STOP | {result.get('remote_stop_code') or result.get('stop_code') or '—'} |"]
    if result.get('wp'):
        wp = result['wp']
        lines.extend([f"| ACF | {wp.get('acf_matched', 0)}/43 |",
                      f"| 六推薦 | {len(wp.get('recommendations', {}))}/6 |",
                      f"| 後台網址 | {wp.get('wp_edit_url') or '未驗證'} |"])
    Path(path).write_text('\n'.join(lines) + '\n')

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('operation', choices=['init', 'execute', 'oidc-failed'])
    parser.add_argument('--result', default='wp-result/remote-result.json')
    args = parser.parse_args()
    path = Path(args.result)
    if args.operation == 'init':
        result = initial(os.environ['GITHUB_SHA'], os.environ['GITHUB_RUN_ID'], os.environ['GITHUB_RUN_ATTEMPT'])
    else:
        result = json.loads(path.read_text())
        if args.operation == 'oidc-failed':
            result['stop_code'] = 'AWS_OIDC_WP_ROLE_NOT_ASSUMED'
        else:
            try:
                execute(result, path)
            except (Stop, KeyError, ValueError, OSError, subprocess.SubprocessError) as e:
                result['stop_code'] = str(e) if isinstance(e, Stop) else 'REMOTE_CONTROLLER_' + type(e).__name__
    persist(path, result)
    summary(result, path.parent / 'summary.md')
    print(json.dumps(result, ensure_ascii=False))
    return 0 if args.operation == 'init' or result['result'] == 'PASS' else 1

if __name__ == '__main__':
    sys.exit(main())
