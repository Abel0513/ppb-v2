#!/usr/bin/env python3
"""PPB V2 one-time, reviewed PHP-FPM open_basedir bridge fix.
Only changes /etc/ppb-v2/php-fpm.conf + its preexisting SHA256 field in
/www/ppb-v2-deploy/policy/isolation.json. Never touches legacy/app/DB/IAM.
Default: inspect-only. Requires explicit --apply for privileged mutation.
"""
import argparse
import fcntl
import hashlib
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
        if OLD not in old_text and NEW in old_text:
            need(old_text.count(NEW) == 1, 'BASEDIR_REPEAT_UNVERIFIED')
            return {'result': 'ALREADY_APPLIED', 'config_sha256': sha(old_conf),
                    'policy_matches': True, 'no_legacy_writes': True}
        target = planned(old_text).encode('utf-8')
        need(sha(target) != sha(old_conf), 'NO_TARGET_CHANGE')
        if not enable:
            return {'result': 'PREFLIGHT_PASS_APPLY_REQUIRED',
                    'before_sha256': sha(old_conf), 'after_sha256': sha(target),
                    'socket_exact_scope': True, 'migration': False,
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
        os.chmod(backup / 'php-fpm.conf', 0o600)
        os.chmod(backup / 'isolation.json', 0o600)
        changed = False
        try:
            atomic(CONF, target, stat.S_IMODE(cs.st_mode))
            changed = True
            fixed([FPM, '--test', '--fpm-config', str(CONF)], 'FPM_CONFIG_SYNTAX_FAILED')
            atomic(POLICY, next_policy, stat.S_IMODE(ps.st_mode))
            fixed(['systemctl', 'reload', SERVICE], 'V2_FPM_RELOAD_FAILED')
            fixed(['systemctl', 'is-active', '--quiet', SERVICE], 'V2_FPM_NOT_ACTIVE')
            fixed(['python3', LIFECYCLE, 'inventory', '--commit', '0'*40,
                   '--request-id', 'fpm-socket-readonly-audit'],
                  'V2_ISOLATION_AUDIT_FAILED', timeout=90)
        except Exception:
            if changed:
                atomic(CONF, old_conf, stat.S_IMODE(cs.st_mode))
                atomic(POLICY, old_policy, stat.S_IMODE(ps.st_mode))
                fixed([FPM, '--test', '--fpm-config', str(CONF)],
                      'ROLLBACK_FPM_SYNTAX_FAILED')
                fixed(['systemctl', 'reload', SERVICE], 'ROLLBACK_FPM_RELOAD_FAILED')
            raise
        receipt = {'result': 'FPM_READER_SOCKET_BASEDIR_ENABLED_VERIFY_NEXT',
                   'before_sha256': sha(old_conf), 'after_sha256': sha(target),
                   'policy_matches': True, 'socket_exact_scope': True,
                   'migrations': False, 'old_business_writes': 0,
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
