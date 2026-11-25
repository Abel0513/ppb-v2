"""Installed SSM code only. S3 carries data, never executable candidate code."""
import base64
import fcntl
import hashlib
import io
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile
import zipfile

JOURNAL = Path('/var/tmp/ppb-codex-issue12-new-expert-v1')
OPERATIONS = {'journal', 'resume'}
PREFIX = 'PPB_WP_INTERFACE_V1='

def require(ok, code):
    if not ok:
        raise RuntimeError(code)

def private_json(path):
    s = path.lstat()
    require(stat.S_ISREG(s.st_mode) and s.st_uid == 0 and s.st_mode & 0o077 == 0
            and s.st_size <= 200000, 'PRIVATE_STATE_INVALID')
    return json.loads(path.read_text())

def receipt(folder):
    s = JOURNAL.lstat()
    require(stat.S_ISDIR(s.st_mode) and s.st_uid == 0 and s.st_mode & 0o077 == 0, 'JOURNAL_UNSAFE')
    j = private_json(JOURNAL / 'journal.json')
    require(j.get('phase') in ['CREATE_INTENT', 'CREATED', 'ACF_INTENT', 'VERIFIED']
            and j.get('create_http') == 201, 'EXISTING_CREATE_RECEIPT_REQUIRED')
    require(j.get('slug') == 'ppb-codex-issue12-new-expert-v1'
            and j.get('marker') == 'PPB-CODEX-ISSUE12-NEW-EXPERT-V1', 'JOURNAL_MARKER_INVALID')
    before = hashlib.sha256((JOURNAL / 'journal.json').read_bytes()).hexdigest()
    php = '/www/server/php/84/bin/php'
    p = subprocess.run([php, str(folder / 'inspect.php'), str(folder)], capture_output=True, text=True, timeout=100)
    lines = [x.split('=', 1)[1] for x in p.stdout.splitlines() if x.startswith('PPB_JOURNAL_RECEIPT_V1=')]
    require(len(lines) == 1, 'JOURNAL_RECEIPT_MISSING')
    r = json.loads(lines[0])
    require(p.returncode == 0 and r.get('ok') is True and r.get('wp_write_requests') == 0, 'EXISTING_EXPERT_NOT_VERIFIED')
    require(before == hashlib.sha256((JOURNAL / 'journal.json').read_bytes()).hexdigest(), 'JOURNAL_CHANGED_DURING_READ')
    return r

def validate_request(request, expected):
    require(isinstance(request,dict) and set(request)=={'operation','commit','run_id','run_attempt'} and request == expected, 'REQUEST_MISMATCH')
    require(request['operation'] in OPERATIONS, 'OPERATION_DENIED')
    require(bool(re.fullmatch('[a-f0-9]{40}', request['commit'])), 'COMMIT_INVALID')
    for k in ['run_id', 'run_attempt']:
        require(bool(re.fullmatch('[1-9][0-9]{0,19}', request[k])), 'RUN_INVALID')

def execute(bundle):
    expected = dict(operation=os.environ['SSM_Operation'], commit=os.environ['SSM_Commit'],
                    run_id=os.environ['SSM_RunId'], run_attempt=os.environ['SSM_RunAttempt'])
    incoming = Path('/www/ppb-v2-deploy/wp-test') / ('interface-' + expected['run_id'] + '-' + expected['run_attempt'])
    # SSM validates numeric path components before the download; no user-controlled paths.
    request = private_json(incoming / 'request.json')
    validate_request(request, expected)
    require(os.geteuid() == 0, 'ROOT_REQUIRED_FOR_LEGACY_READ_ONLY_CONNECTOR')
    with tempfile.TemporaryDirectory(prefix='ppb-fixed-interface-', dir='/var/tmp') as tmp:
        folder = Path(tmp)
        with zipfile.ZipFile(io.BytesIO(base64.b64decode(bundle, validate=True))) as z:
            for info in z.infolist():
                require(info.filename in {'run.php', 'legacy.php', 'parser.php', 'inspect.php', 'collector.py'}, 'BUNDLE_PATH_DENIED')
                p = folder / info.filename
                p.write_bytes(z.read(info)); p.chmod(0o600)
        # Separate interface lock, so WPN2 can retain its own permanent lock.
        lock = os.open('/var/tmp/ppb-wp-fixed-interface.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            ls=os.fstat(lock)
            require(stat.S_ISREG(ls.st_mode) and ls.st_uid==0 and ls.st_mode & 0o077==0, 'INTERFACE_LOCK_UNSAFE')
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            r = receipt(folder)  # Always authenticated GET before any resume. Never initialize journal.
            result = dict(schema_version=1, execution=expected, journal=r, result='READ_ONLY_VERIFIED')
            if expected['operation'] == 'resume':
                env = dict(os.environ, PPB_WP_GITHUB_COMMIT=expected['commit'], PPB_WP_GITHUB_RUN_ID=expected['run_id'],
                           PPB_WP_GITHUB_RUN_ATTEMPT=expected['run_attempt'])
                # Reviewed embedded WPN2; NOT S3 or branch PHP. Existing CREATE_INTENT cannot POST again.
                p = subprocess.run(['/www/server/php/84/bin/php', str(folder / 'run.php')], env=env,
                                   capture_output=True, timeout=650)
                env['PPB_WP_PACKAGE_EXIT_CODE'] = str(p.returncode)
                c = subprocess.run(['python3', str(folder / 'collector.py')], env=env,
                                   capture_output=True, text=True, timeout=15)
                lines = [x.split('=',1)[1] for x in c.stdout.splitlines() if x.startswith('PPB_WP_RESULT_V1=')]
                require(len(lines) == 1, 'COLLECTOR_RESULT_MISSING')
                result['wp'] = json.loads(lines[0]); result['result'] = result['wp']['result']
            return result
        finally:
            os.close(lock)

def main(bundle):
    try:
        result = execute(bundle)
    except Exception as e:
        code = str(e)
        result = dict(schema_version=1, result='STOP', execution=dict(operation=os.environ.get('SSM_Operation'),commit=os.environ.get('SSM_Commit'),run_id=os.environ.get('SSM_RunId'),run_attempt=os.environ.get('SSM_RunAttempt')), stop_code=code if re.fullmatch('[A-Z0-9_]{1,120}', code) else 'INTERFACE_FAILED')
    print(PREFIX + json.dumps(result, ensure_ascii=False, separators=(',', ':')))
    return 0 if result['result'] in {'PASS', 'READ_ONLY_VERIFIED'} else 2
