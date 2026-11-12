#!/usr/bin/env python3
"""PPB V2 controlled in-place release transaction (not a database migration).

This tool never reads or modifies legacy WP/DB and never writes V2 .env, storage,
bootstrap/cache or untracked public files. It makes a private per-file rollback
journal BEFORE changing managed files; interrupted runs fail closed until recovered.
It is NOT an atomic whole-directory swap; see CONTROLLED_DEPLOY.md.
"""
import argparse
import filecmp
import hashlib
import json
import os
from pathlib import Path
import re
import runpy
import shutil
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
import time
from types import SimpleNamespace
from datetime import datetime, timezone
import fcntl

LIVE_DEFAULT = Path('/www/wwwroot/ppbaos.com')
PRIVATE_DEFAULT = Path('/www/ppb-v2-deploy')
SHA_RE = re.compile(r'^[a-f0-9]{64}$')
COMMIT_RE = re.compile(r'^[a-f0-9]{40}$')
RUN_RE = re.compile(r'^[1-9][0-9]{0,19}$')
PROTECTED = ('.env', 'storage/', 'bootstrap/cache/', 'public/.well-known/',
             'public/storage/', 'public/uploads/', 'public/files/', 'public/media/')
ROOT_FILES = {'artisan', 'composer.json', 'composer.lock'}
ROOT_DIRS = {'app', 'bootstrap', 'config', 'database', 'lang', 'public',
             'resources', 'routes', 'vendor'}


class DeployError(Exception):
    pass


def fail(message):
    raise DeployError(message)


def digest_file(path):
    h = hashlib.sha256()
    with path.open('rb') as fh:
        for block in iter(lambda: fh.read(1024 * 1024), b''):
            h.update(block)
    return h.hexdigest()


def safe_rel(rel):
    parts = rel.split('/')
    if (not rel or rel.startswith('/') or '\\' in rel or
            any(p in ('', '.', '..') for p in parts)):
        fail('unsafe relative file path')
    if any(part.startswith('.env') for part in parts):
        fail('protected environment file')
    if rel in ROOT_FILES:
        return True
    if parts[0] not in ROOT_DIRS or len(parts) < 2:
        fail('release path is not deployable: ' + rel)
    if any(rel == prefix.rstrip('/') or rel.startswith(prefix) for prefix in PROTECTED):
        fail('protected live path: ' + rel)
    return True


def checked_path(root, rel, allow_missing=True):
    safe_rel(rel)
    current = root
    for component in rel.split('/'):
        current = current / component
        if current.is_symlink():
            fail('symlink in target path: ' + rel)
        if current.exists() and current != root / rel and not current.is_dir():
            fail('non-directory ancestor in path: ' + rel)
    if not allow_missing and not current.is_file():
        fail('expected regular file missing: ' + rel)
    if current.exists() and not current.is_file():
        fail('non-regular file target: ' + rel)
    return current


def atomic_json(path, value):
    tmp = path.with_name('.' + path.name + '.pending')
    if tmp.exists() or tmp.is_symlink():
        fail('stale pending metadata file: ' + str(tmp))
    try:
        with tmp.open('x', encoding='utf-8') as fp:
            json.dump(value, fp, sort_keys=True, ensure_ascii=True)
            fp.write('\n')
            fp.flush()
            os.fsync(fp.fileno())
        tmp.chmod(0o600)
        os.replace(tmp, path)
    finally:
        tmp.unlink(missing_ok=True)


def load_json(path):
    if not path.exists():
        return None
    if not path.is_file() or path.is_symlink():
        fail('unsafe state metadata file: ' + str(path))
    with path.open('r', encoding='utf-8') as fp:
        return json.load(fp)


def clone_file_atomic(source, destination, old_stat=None):
    destination.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
    fd, scratch = tempfile.mkstemp(prefix='.ppb-next-', dir=destination.parent)
    try:
        with os.fdopen(fd, 'wb') as output, source.open('rb') as input_fp:
            shutil.copyfileobj(input_fp, output, 1024 * 1024)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(scratch, stat.S_IMODE(old_stat.st_mode) if old_stat else 0o644)
        if old_stat:
            os.chown(scratch, old_stat.st_uid, old_stat.st_gid)
        os.replace(scratch, destination)
    finally:
        if os.path.exists(scratch):
            os.unlink(scratch)


class ControlledDeploy:
    def __init__(self, live=LIVE_DEFAULT, private=PRIVATE_DEFAULT,
                 health=None, require_host=True):
        self.live = Path(live)
        self.private = Path(private)
        self.releases = self.private / 'releases'
        self.backups = self.private / 'backups'
        self.state_path = self.private / 'current-release.json'
        self.pending_path = self.private / 'pending-deployment.json'
        self.lock_path = self.private / '.deployment.lock'
        self.health = health if health is not None else self.http_health
        self.require_host = require_host

    @staticmethod
    def http_health():
        # Resolve to LOCAL nginx, not a proxy, DNS answer or legacy host.
        cmd = ['curl', '--silent', '--show-error', '--noproxy', '*',
               '--proto', '=https', '--connect-timeout', '4', '--max-time', '12',
               '--resolve', 'v2.csdatw.xyz:443:127.0.0.1',
               '--output', '/dev/null', '--write-out', '%{http_code}',
               'https://v2.csdatw.xyz/']
        result = subprocess.run(cmd, text=True, capture_output=True, timeout=15)
        if result.returncode != 0 or result.stdout not in ('200', '301', '302', '307', '308'):
            fail('local HTTPS Laravel health check failed; HTTP=' + result.stdout[:3])
        return result.stdout

    def verify_host(self):
        if self.require_host:
            if os.geteuid() != 0:
                fail('must be invoked by root through approved SSM document')
            if str(self.live) != str(LIVE_DEFAULT) or str(self.private) != str(PRIVATE_DEFAULT):
                fail('unexpected production paths')
        for directory in (self.live, self.private, self.releases, self.backups):
            if not directory.is_dir() or directory.is_symlink():
                fail('missing or symlink directory: ' + str(directory))
        if self.private.stat().st_mode & 0o077 or self.releases.stat().st_mode & 0o077:
            fail('private deployment directories must not allow group/other access')
        for path in ('artisan', 'composer.json', 'composer.lock', 'public/index.php',
                     'vendor/autoload.php', '.env'):
            p = self.live / path
            if not p.is_file() or p.is_symlink():
                fail('live prerequisite missing or symlink: ' + path)
        if not (self.live / 'storage').is_dir() or (self.live / 'storage').is_symlink():
            fail('existing live storage must be a real directory')
        if self.live.resolve() == self.private.resolve() or self.private.resolve().is_relative_to(self.live.resolve()):
            fail('private and live paths overlap')
        if self.require_host:
            # Existing audited baseline is mandatory before first write.
            markers = list(self.backups.glob('baseline-*/.snapshot-complete'))
            if not any((p.parent / 'artisan').is_file() and (p.parent / '.env').is_file() for p in markers):
                fail('verified baseline snapshot not present')

    def lock(self):
        self.verify_host()
        fd = os.open(self.lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as exc:
            os.close(fd)
            raise DeployError('another deployment is already running') from exc
        return fd

    def package(self, archive, sha, commit):
        if not SHA_RE.fullmatch(sha) or not COMMIT_RE.fullmatch(commit):
            fail('invalid release hash or commit identifier')
        if not archive.is_file() or archive.is_symlink():
            fail('missing or symlink archive')
        if not archive.resolve().is_relative_to(self.releases.resolve()):
            fail('archive must be inside private releases directory')
        if digest_file(archive) != sha:
            fail('archive SHA256 mismatch')
        validation = runpy.run_path(str(Path(__file__).with_name('check-release.py')))
        validation['check_archive'](archive)
        with tarfile.open(archive, 'r:gz') as tf:
            try:
                manifest = json.load(tf.extractfile('.ppb-release.json'))
            except (KeyError, ValueError, TypeError, AttributeError) as exc:
                raise DeployError('missing or invalid release manifest') from exc
            if manifest.get('commit') != commit or manifest.get('format') != 'ppb-v2-release-v1':
                fail('release commit/format mismatch')
            names = []
            for m in tf:
                if not m.isfile() or m.name == '.ppb-release.json':
                    continue
                safe_rel(m.name)
                names.append(m.name)
        return tuple(sorted(names))

    def ensure_candidate(self, archive, sha, commit, names):
        candidate = self.releases / ('candidate-' + commit[:12])
        if not candidate.exists() and not candidate.is_symlink():
            staging = runpy.run_path(str(Path(__file__).with_name('stage-release.py')))
            staging['stage'](archive, sha, commit, self.releases, self.live)
        if not candidate.is_dir() or candidate.is_symlink():
            fail('candidate directory missing or symlink')
        # A cached candidate is not trusted without full byte verification.
        with tarfile.open(archive, 'r:gz') as tf:
            for m in tf:
                if not m.isfile():
                    continue
                p = candidate / m.name
                if not p.is_file() or p.is_symlink() or p.stat().st_size != m.size:
                    fail('candidate differs from archive: ' + m.name)
                fp = tf.extractfile(m)
                h = hashlib.sha256()
                for block in iter(lambda: fp.read(1024 * 1024), b''):
                    h.update(block)
                if digest_file(p) != h.hexdigest():
                    fail('candidate file checksum mismatch: ' + m.name)
        return candidate

    def plan(self, candidate, names, delete_allowed=False):
        previous = load_json(self.state_path) or {}
        previous_files = set(previous.get('managed_files', []))
        action = []
        for rel in names:
            source = checked_path(candidate, rel, allow_missing=False)
            dest = checked_path(self.live, rel)
            if dest.exists() and filecmp.cmp(source, dest, shallow=False):
                continue
            if rel == 'public/assets' or rel.startswith('public/assets/'):
                if dest.exists():
                    fail('public/assets differs; requires a separate asset-change approval: ' + rel)
            action.append({'kind': 'replace' if dest.exists() else 'add', 'path': rel})
        removals = sorted(previous_files - set(names))
        if removals and not delete_allowed:
            fail('deleted managed paths require explicit separate approval; count=' + str(len(removals)))
        for rel in removals:
            safe_rel(rel)
            if rel.startswith('public/assets/'):
                fail('removal of public/assets is forbidden')
            p = checked_path(self.live, rel)
            if p.exists():
                action.append({'kind': 'delete', 'path': rel})
        return action

    def syntax_check(self, candidate, actions):
        php_files = [str(candidate / a['path']) for a in actions
                     if a['kind'] != 'delete' and a['path'].endswith('.php')]
        if len(php_files) > 1000:
            fail('too many changed PHP files to lint in one deployment')
        for php_file in php_files:
            result = subprocess.run(['php', '-l', php_file], text=True, capture_output=True, timeout=12)
            if result.returncode:
                fail('PHP syntax check failed: ' + str(Path(php_file).name))
        print('PHP_LINT_PASSED=' + str(len(php_files)))

    def audit(self, archive, sha, commit, delete_allowed=False):
        if self.pending_path.exists():
            fail('unfinished deployment found; rollback must run first')
        names = self.package(archive, sha, commit)
        candidate = self.ensure_candidate(archive, sha, commit, names)
        actions = self.plan(candidate, names, delete_allowed)
        self.syntax_check(candidate, actions)
        counts = {kind: sum(a['kind'] == kind for a in actions) for kind in ('replace', 'add', 'delete')}
        print('COMMIT=' + commit)
        print('RELEASE_FILES=' + str(len(names)))
        print('REPLACE_FILES=' + str(counts['replace']))
        print('ADD_FILES=' + str(counts['add']))
        print('DELETE_FILES=' + str(counts['delete']))
        print('PRESERVED=.env,storage,bootstrap/cache,public/.well-known,unmanaged files')
        print('DATABASE_MIGRATIONS_EXECUTED=NO')
        print('RESULT=PPB_V2_CONTROLLED_AUDIT_PASS')
        return candidate, names, actions

    def restore(self, txn, journal):
        original = txn / 'original'
        # Restore original file modes AND uid/gid recorded before deployment.
        metadata = journal.get('original_metadata', {})
        for a in journal['actions']:
            rel = a['path']
            dest = checked_path(self.live, rel)
            if a['kind'] in ('replace', 'delete'):
                backup_file = checked_path(original, rel, allow_missing=False)
                m = metadata.get(rel)
                if not isinstance(m, dict) or set(m) != {'st_mode', 'st_uid', 'st_gid'}:
                    fail('rollback metadata missing: ' + rel)
                clone_file_atomic(backup_file, dest, SimpleNamespace(**m))
        for a in reversed(journal['actions']):
            if a['kind'] == 'add':
                dest = checked_path(self.live, a['path'])
                dest.unlink(missing_ok=True)
                parent = dest.parent
                while parent != self.live:
                    try:
                        parent.rmdir()
                    except OSError:
                        break
                    parent = parent.parent
        old_state = txn / 'state-before.json'
        if journal['had_prior_state']:
            if not old_state.is_file() or old_state.is_symlink():
                fail('transaction missing previous state; manual recovery required')
            clone_file_atomic(old_state, self.state_path, old_state.stat())
        else:
            self.state_path.unlink(missing_ok=True)
        journal['phase'] = 'rolled_back'
        atomic_json(txn / 'journal.json', journal)
        self.pending_path.unlink(missing_ok=True)
        print('RESULT=PPB_V2_PROGRAM_ROLLBACK_PASS')
        print('DATABASE_ROLLBACK_PERFORMED=NO')

    def apply(self, candidate, names, actions, sha, commit):
        before = self.health()  # must pass before any live write
        now = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
        txn_name = 'txn-' + now + '-' + commit[:12]
        txn = self.backups / txn_name
        if txn.exists():
            fail('transaction already exists; retry later')
        txn.mkdir(mode=0o700)
        original = txn / 'original'
        original.mkdir(mode=0o700)
        had_prior_state = self.state_path.exists()
        if had_prior_state:
            shutil.copy2(self.state_path, txn / 'state-before.json')
        journal = {
            'phase': 'prepared', 'commit': commit, 'sha256': sha,
            'had_prior_state': had_prior_state, 'actions': actions,
            'timestamp_utc': now, 'health_before': before,
        }
        # Back up ALL prior live files and original ownership before any write.
        journal['original_metadata'] = {}
        for a in actions:
            if a['kind'] in ('replace', 'delete'):
                source = checked_path(self.live, a['path'], allow_missing=False)
                st = source.stat()
                journal['original_metadata'][a['path']] = {
                    'st_mode': st.st_mode, 'st_uid': st.st_uid, 'st_gid': st.st_gid,
                }
                dest = original / a['path']
                dest.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                shutil.copy2(source, dest, follow_symlinks=False)
                if digest_file(source) != digest_file(dest):
                    fail('pre-write backup checksum mismatch: ' + a['path'])
        atomic_json(txn / 'journal.json', journal)
        atomic_json(self.pending_path, {'transaction': txn_name})
        journal['phase'] = 'applying'
        atomic_json(txn / 'journal.json', journal)
        started = False
        try:
            for a in actions:
                rel, kind = a['path'], a['kind']
                dest = checked_path(self.live, rel)
                if kind == 'delete':
                    dest.unlink()
                else:
                    src = checked_path(candidate, rel, allow_missing=False)
                    prior_stat = dest.stat() if dest.exists() else None
                    clone_file_atomic(src, dest, prior_stat)
                started = True
            if self.health() != before:
                fail('HTTPS health response changed unexpectedly')
            new_state = {
                'commit': commit, 'sha256': sha,
                'managed_files': list(names),
                'last_transaction': txn_name,
                'timestamp_utc': now,
            }
            atomic_json(self.state_path, new_state)
            journal['phase'] = 'complete'
            atomic_json(txn / 'journal.json', journal)
            self.pending_path.unlink(missing_ok=True)
            print('LIVE_APPLICATION_DEPLOYED=YES')
            print('DATABASE_MIGRATIONS_EXECUTED=NO')
            print('RESULT=PPB_V2_CONTROLLED_DEPLOY_PASS')
        except BaseException:
            if started or self.pending_path.exists():
                try:
                    self.restore(txn, journal)
                except BaseException as restore_exc:
                    print('ROLLBACK_FAILED=' + str(restore_exc), file=sys.stderr)
                    print('MANUAL_RECOVERY_REQUIRED=' + txn_name, file=sys.stderr)
            raise

    def rollback_latest(self):
        pending = load_json(self.pending_path)
        if pending:
            name = pending.get('transaction')
        else:
            state = load_json(self.state_path)
            if not state:
                fail('no successful V2 deployment available to roll back')
            name = state.get('last_transaction')
        if not isinstance(name, str) or not re.fullmatch(r'txn-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}', name):
            fail('invalid rollback transaction name')
        txn = self.backups / name
        journal = load_json(txn / 'journal.json')
        if not journal or journal['phase'] == 'rolled_back':
            fail('transaction missing or already rolled back')
        self.restore(txn, journal)
        print('RESTORED_TRANSACTION=' + name)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--operation', required=True, choices=['audit', 'deploy', 'rollback'])
    parser.add_argument('--archive', type=Path)
    parser.add_argument('--sha256')
    parser.add_argument('--commit')
    parser.add_argument('--run-id')
    parser.add_argument('--run-attempt')
    parser.add_argument('--confirmation', default='')
    args = parser.parse_args()
    obj = ControlledDeploy()
    lockfd = None
    try:
        lockfd = obj.lock()
        if args.operation == 'rollback':
            if args.confirmation != 'ROLLBACK-PPB-V2':
                fail('explicit manual rollback confirmation required')
            obj.rollback_latest()
            return 0
        if not args.archive or not args.sha256 or not args.commit:
            fail('archive, SHA256 and commit are mandatory')
        if not RUN_RE.fullmatch(args.run_id or '') or not RUN_RE.fullmatch(args.run_attempt or ''):
            fail('invalid GitHub Actions run identity')
        expected_archive = (obj.releases / ('ssm-incoming-' + args.run_id + '-' + args.run_attempt)
                            / ('run-' + args.run_id + '-attempt-' + args.run_attempt + '.tar.gz'))
        if args.archive != expected_archive:
            fail('archive path does not match pinned SSM download')
        candidate, names, actions = obj.audit(args.archive, args.sha256, args.commit)
        if args.operation == 'deploy':
            if args.confirmation != 'DEPLOY-PPB-V2':
                fail('explicit manual production deployment confirmation required')
            obj.apply(candidate, names, actions, args.sha256, args.commit)
        return 0
    except (DeployError, OSError, ValueError, tarfile.TarError, subprocess.TimeoutExpired) as exc:
        print('REASON=' + str(exc), file=sys.stderr)
        print('RESULT=PPB_V2_CONTROLLED_DEPLOY_FAIL', file=sys.stderr)
        return 1
    finally:
        if lockfd is not None:
            fcntl.flock(lockfd, fcntl.LOCK_UN)
            os.close(lockfd)


if __name__ == '__main__':
    sys.exit(main())
