#!/usr/bin/env python3
"""PPB V2 one-time, reviewed PHP-FPM open_basedir bridge fix.
Only changes /etc/ppb-v2/php-fpm.conf + its preexisting SHA256 field in
/www/ppb-v2-deploy/policy/isolation.json. Never touches legacy/app/DB/IAM.
Default: inspect-only. Requires explicit --apply for privileged mutation.
"""
import argparse
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import tempfile
import time

CONF = Path('/etc/ppb-v2/php-fpm.conf')
POLICY = Path('/www/ppb-v2-deploy/policy/isolation.json')
PRIVATE = Path('/www/ppb-v2-deploy')
SOCKET = Path('/run/ppb-v2-legacy-reader.sock')
FPM = '/www/server/php/84/sbin/php-fpm'
LIFECYCLE = '/www/ppb-v2-deploy/scripts/isolation/lifecycle.py'
SERVICE = 'ppb-v2-php.service'
LIVE = Path('/www/wwwroot/ppbaos.com')
CACHE = LIVE / 'bootstrap/cache/config.php'
BOUNDARY = PRIVATE / 'scripts/isolation/boundary.py'
RELEASE = PRIVATE / 'current-release.json'
PHP = '/www/server/php/84/bin/php'
EXPECTED_BEFORE_SHA = '68e97e3977c7beb695d5b5015be077acb817ab4a75761a6606d3bd47610bf357'
EXPECTED_AFTER_SHA = '9c6ae7cdabe12db131e64eec8ffb0c880550d0659cafa37e692a4135287a82ab'

BASE = '/www/wwwroot/ppbaos.com:/tmp:/etc/ssl/certs:/usr/share/zoneinfo'
OLD = 'php_admin_value[open_basedir] = ' + BASE
NEW = OLD + ':' + str(SOCKET)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def need(condition, reason):
    if not condition:
        raise RuntimeError(reason)


def regular_root(path):
    st = path.lstat()
    need(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and st.st_nlink == 1
         and not (st.st_mode & 0o022), 'ROOT_CONFIG_NOT_VERIFIED')
    return st


def planned(old):
    need(old.count(OLD) == 1, 'BASEDIR_ORIGINAL_DIRECTIVE_NOT_EXACT')
    need(old.count('php_admin_value[open_basedir]') == 1,
         'BASEDIR_MORE_THAN_ONE_DIRECTIVE')
    need(old.count(NEW) == 0, 'BASEDIR_ALREADY_EXPANDED_UNVERIFIED')
    result = old.replace(OLD, NEW)
    need(result.count(NEW) == 1 and result.replace(NEW, OLD) == old,
         'BASEDIR_TARGET_NOT_MINIMAL')
    return result


def atomic(path, data, mode):
    fd, temp = tempfile.mkstemp(prefix='.ppb-basedir-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(temp, mode)
        os.chown(temp, 0, 0)
        os.replace(temp, path)
        dirfd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(dirfd)
        finally:
            os.close(dirfd)
    finally:
        if os.path.lexists(temp):
            os.unlink(temp)


def fixed(cmd, code, timeout=40):
    p = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                       timeout=timeout, check=False)
    need(p.returncode == 0, code)



def v2_cache():
    """Rebuild only V2 Laravel config under the existing nonroot systemd sandbox."""
    for path in (BOUNDARY, RELEASE):
        regular_root(path)
    record = json.loads(RELEASE.read_text())
    commit = record.get('commit', '')
    need(isinstance(commit, str) and re.fullmatch('[a-f0-9]{40}', commit),
         'V2_RELEASE_FINGERPRINT_INVALID')
    spec = importlib.util.spec_from_file_location('ppb_boundary_reviewed', BOUNDARY)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    argv = module.sandbox_command([PHP, str(LIVE / 'artisan'), 'config:cache', '--no-interaction'])
    need('systemd-run' == argv[0] and '--uid=ppb-v2' in argv and '--gid=ppb-v2' in argv
         and '--property=NoNewPrivileges=true' in argv
         and '--property=ProtectSystem=strict' in argv
         and '--property=CapabilityBoundingSet=' in argv,
         'V2_SANDBOX_SCOPE_UNVERIFIED')
    argv.insert(argv.index('--'), '--setenv=PPB_V2_RELEASE_COMMIT=' + commit)
    fixed(argv, 'V2_NONROOT_CONFIG_CACHE_REBUILD_FAILED', timeout=120)
    st = CACHE.lstat()
    uid = pwd.getpwnam('ppb-v2').pw_uid
    need(stat.S_ISREG(st.st_mode) and st.st_uid == uid and st.st_nlink == 1,
         'V2_CACHE_IDENTITY_NOT_VERIFIED')
    need(st.st_mtime_ns >= POLICY.stat().st_mtime_ns,
         'V2_CACHE_NOT_NEWER_THAN_POLICY')


def audit_isolation():
    p = subprocess.run(['python3', LIFECYCLE, 'inventory', '--commit', '0'*40,
                        '--request-id', 'fpm-reader-socket-audit-r2'],
                       capture_output=True, text=True, timeout=90)
    try:
        receipt = json.loads(p.stdout)
    except (ValueError, TypeError):
        raise RuntimeError('V2_ISOLATION_RECEIPT_INVALID')
    if p.returncode or receipt.get('result') != 'PASS':
        reason = receipt.get('reason', '')
        need(isinstance(reason, str) and re.fullmatch('[A-Z0-9_]{1,100}', reason),
             'V2_ISOLATION_AUDIT_FAILED')
        raise RuntimeError('V2_ISOLATION_' + reason)
    need(receipt.get('database_writes') == 0 and receipt.get('program_writes') == 0,
         'V2_ISOLATION_WRITES_UNEXPECTED')


def apply(enable):
    need(os.geteuid() == 0, 'OS_ROOT_REQUIRED')
    os.umask(0o077)
    need(PRIVATE.is_dir() and not PRIVATE.is_symlink()
         and PRIVATE.stat().st_uid == 0, 'PRIVATE_DEPLOY_ROOT_UNVERIFIED')
    lock = os.open(PRIVATE / '.deployment.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        cs, ps = regular_root(CONF), regular_root(POLICY)
        old_conf, old_policy = CONF.read_bytes(), POLICY.read_bytes()
        policy = json.loads(old_policy)
        need(isinstance(policy, dict) and re.fullmatch('[a-f0-9]{64}',
             policy.get('php_config_sha256', '')), 'POLICY_SCHEMA_NOT_VERIFIED')
        need(policy['php_config_sha256'] == sha(old_conf), 'FPM_POLICY_FINGERPRINT_DRIFT')
        need(sha(old_conf) == EXPECTED_BEFORE_SHA, 'FPM_SOURCE_SHA_NOT_APPROVED')
        need(CONF.stat().st_gid == 0 and POLICY.stat().st_gid == 0,
             'ROOT_GROUP_SCOPE_NOT_VERIFIED')
        sst = SOCKET.lstat()
        uid = pwd.getpwnam('ppb-v2').pw_uid
        gid = pwd.getpwnam('ppb-v2').pw_gid
        need(stat.S_ISSOCK(sst.st_mode) and sst.st_uid == 0 and sst.st_gid == gid
             and stat.S_IMODE(sst.st_mode) == 0o660,
             'FIXED_BRIDGE_SOCKET_IDENTITY_NOT_VERIFIED')
        need('/run/ppb-v2-legacy-reader.sock' == str(SOCKET), 'SOCKET_SCOPE_DRIFT')
        old_text = old_conf.decode('utf-8')
        if NEW in old_text:
            need(old_text.count(NEW) == 1 and old_text.count('php_admin_value[open_basedir]') == 1,
                 'BASEDIR_REPEAT_UNVERIFIED')
            return {'result': 'ALREADY_APPLIED', 'config_sha256': sha(old_conf),
                    'policy_matches': True, 'no_legacy_writes': True}
        target = planned(old_text).encode('utf-8')
        need(sha(target) == EXPECTED_AFTER_SHA, 'FPM_TARGET_SHA_NOT_APPROVED')
        if not enable:
            return {'result': 'PREFLIGHT_PASS_APPLY_REQUIRED',
                    'before_sha256': sha(old_conf), 'after_sha256': sha(target),
                    'socket_exact_scope': True,
                    'cache_rebuild_required': CACHE.stat().st_mtime_ns < POLICY.stat().st_mtime_ns,
                    'migration': False,
                    'no_legacy_writes': True}
        new_policy = dict(policy)
        new_policy['php_config_sha256'] = sha(target)
        next_policy = (json.dumps(new_policy, sort_keys=True, separators=(',', ':')) + '\n').encode()
        backup_root = PRIVATE / 'backups'
        need(backup_root.is_dir() and not backup_root.is_symlink()
             and backup_root.stat().st_uid == 0, 'PRIVATE_BACKUP_ROOT_UNVERIFIED')
        backup = backup_root / ('fpm-reader-socket-' + str(time.time_ns()))
        backup.mkdir(mode=0o700)
        (backup / 'php-fpm.conf').write_bytes(old_conf)
        (backup / 'isolation.json').write_bytes(old_policy)
        cache_status = CACHE.lstat()
        need(stat.S_ISREG(cache_status.st_mode) and cache_status.st_uid == pwd.getpwnam('ppb-v2').pw_uid
             and cache_status.st_nlink == 1, 'V2_CACHE_IDENTITY_NOT_VERIFIED')
        (backup / 'config-cache.php.private').write_bytes(CACHE.read_bytes())
        os.chmod(backup / 'php-fpm.conf', 0o600)
        os.chmod(backup / 'isolation.json', 0o600)
        os.chmod(backup / 'config-cache.php.private', 0o600)
        changed = False
        try:
            # Repair the exact stale-cache state left by the prior rollback first.
            if CACHE.stat().st_mtime_ns < POLICY.stat().st_mtime_ns:
                v2_cache()
            audit_isolation()
            atomic(CONF, target, stat.S_IMODE(cs.st_mode))
            changed = True
            fixed([FPM, '--test', '--fpm-config', str(CONF)], 'FPM_CONFIG_SYNTAX_FAILED')
            atomic(POLICY, next_policy, stat.S_IMODE(ps.st_mode))
            fixed(['systemctl', 'reload', SERVICE], 'V2_FPM_RELOAD_FAILED')
            fixed(['systemctl', 'is-active', '--quiet', SERVICE], 'V2_FPM_NOT_ACTIVE')
            v2_cache()
            audit_isolation()
        except Exception:
            if changed:
                atomic(CONF, old_conf, stat.S_IMODE(cs.st_mode))
                atomic(POLICY, old_policy, stat.S_IMODE(ps.st_mode))
                fixed([FPM, '--test', '--fpm-config', str(CONF)],
                      'ROLLBACK_FPM_SYNTAX_FAILED')
                fixed(['systemctl', 'reload', SERVICE], 'ROLLBACK_FPM_RELOAD_FAILED')
                # Cache must be rebuilt after rolling policy back, not timestamp-forged.
                try:
                    v2_cache()
                    audit_isolation()
                except Exception:
                    raise RuntimeError('ROLLBACK_NEEDS_MANUAL_VERIFY')
            raise
        receipt = {'result': 'FPM_READER_SOCKET_BASEDIR_ENABLED_VERIFY_NEXT',
                   'before_sha256': sha(old_conf), 'after_sha256': sha(target),
                   'policy_matches': True, 'socket_exact_scope': True,
                   'config_cache_rebuilt_in_v2_sandbox': True,
                   'isolation_audit': 'PASS', 'migrations': False, 'old_business_writes': 0,
                   'backup': backup.name}
        (backup / 'receipt.json').write_text(json.dumps(receipt, sort_keys=True) + '\n')
        os.chmod(backup / 'receipt.json', 0o600)
        return receipt
    finally:
        os.close(lock)


if __name__ == '__main__':
    a = argparse.ArgumentParser()
    a.add_argument('--apply', action='store_true', help='Apply the reviewed V2-only change once')
    args = a.parse_args()
    try:
        print(json.dumps(apply(args.apply), sort_keys=True))
    except Exception as e:
        msg = str(e)
        print(json.dumps({'result': 'BLOCKED',
                          'reason': msg if re.fullmatch('[A-Z0-9_]{1,100}', msg)
                          else 'ONE_TIME_REVIEWED_CONFIG_FAILED'}))
        raise SystemExit(2)
