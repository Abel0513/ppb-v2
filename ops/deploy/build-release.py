#!/usr/bin/env python3
"""Create a source-and-vendor PPB V2 release tar.gz without production secrets.

Uses git-tracked application source plus separately installed vendor/.
Does not execute PHP, connect to DB or invoke any external service.
"""
import argparse
import gzip
import io
import json
import pathlib
import re
import subprocess
import sys
import tarfile

ROOT_DIRS = {'app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes'}
ROOT_FILES = {'artisan', 'composer.json', 'composer.lock'}
PROTECTED_PUBLIC = {'.well-known', 'storage', 'uploads', 'files', 'media'}
PROTECTED_EXACT = {'.env', '.npmrc', 'auth.json'}
PROTECTED_SUFFIXES = ('.key', '.pem', '.p12', '.pfx', '.sql', '.sqlite', '.zip')
REQUIRED = {'artisan', 'composer.json', 'composer.lock', 'public/index.php', 'vendor/autoload.php'}
MAX_SIZE = 500 * 1024 * 1024
MAX_COUNT = 20000


class ReleaseError(Exception):
    pass


def git(root, *args):
    result = subprocess.run(['git', '-C', str(root), *args], check=True, capture_output=True)
    return result.stdout


def is_protected(name):
    parts = pathlib.PurePosixPath(name).parts
    lower = [p.lower() for p in parts]
    if any(p.startswith('.env') or p in PROTECTED_EXACT or p.endswith(PROTECTED_SUFFIXES) for p in lower):
        return True
    if lower and lower[0] == 'public' and len(lower) > 1 and lower[1] in PROTECTED_PUBLIC:
        return True
    if lower[:2] == ['bootstrap', 'cache']:
        return True
    return False


def application_files(root):
    names = []
    for raw in git(root, 'ls-files', '-z').split(b'\0'):
        if not raw:
            continue
        rel = raw.decode('utf-8', errors='strict')
        parts = pathlib.PurePosixPath(rel).parts
        allowed = (len(parts) == 1 and rel in ROOT_FILES) or (len(parts) > 1 and parts[0] in ROOT_DIRS)
        if not allowed:
            continue
        if is_protected(rel):
            raise ReleaseError('protected tracked application path: ' + rel)
        path = root / rel
        if path.is_symlink() or not path.is_file() or not path.resolve().is_relative_to(root):
            raise ReleaseError('unsafe tracked application file: ' + rel)
        names.append((rel, path))
    return names


def vendor_files(root):
    vendor = root / 'vendor'
    if vendor.is_symlink() or not (vendor / 'autoload.php').is_file():
        raise ReleaseError('vendor/autoload.php missing or vendor symlink')
    names = []
    for path in vendor.rglob('*'):
        rel = path.relative_to(root).as_posix()
        if rel.startswith('vendor/bin/'):
            continue  # Composer bin proxies are not needed by the PHP runtime.
        if path.is_symlink():
            raise ReleaseError('symlink in vendor: ' + rel)
        if not path.is_file():
            continue
        if is_protected(rel):
            raise ReleaseError('protected vendor file: ' + rel)
        names.append((rel, path))
    return names


def tarinfo(name, size, mtime):
    info = tarfile.TarInfo(name)
    info.size = size
    info.mtime = mtime
    info.uid = info.gid = 0
    info.uname = info.gname = ''
    info.mode = 0o644
    return info


def build(root, output):
    root = root.resolve(strict=True)
    if output.resolve().is_relative_to(root):
        raise ReleaseError('archive output must be outside the repository')
    sha = git(root, 'rev-parse', 'HEAD').decode().strip()
    if not re.fullmatch(r'[0-9a-f]{40}', sha):
        raise ReleaseError('invalid git commit ID')
    commit_timestamp = int(git(root, 'show', '-s', '--format=%ct', 'HEAD').decode().strip())
    files = application_files(root) + vendor_files(root)
    files.sort(key=lambda item: item[0])
    names = {name for name, _ in files}
    if REQUIRED - names:
        raise ReleaseError('missing required release files: ' + ','.join(sorted(REQUIRED - names)))
    if len(files) > MAX_COUNT - 1:
        raise ReleaseError('too many package files')
    if sum(path.stat().st_size for _, path in files) > MAX_SIZE:
        raise ReleaseError('uncompressed package exceeds maximum')
    output.parent.mkdir(parents=True, exist_ok=True)
    if output.exists() or output.is_symlink():
        raise ReleaseError('refusing to overwrite an existing release file')
    plan = {'migrate': False, 'cache': [], 'queue_restart': False}
    plan_file = root / 'resources/ppb/release.json'
    if plan_file.exists():
        if plan_file.is_symlink() or 'resources/ppb/release.json' not in names:
            raise ReleaseError('release plan must be a tracked regular data file')
        plan = json.loads(plan_file.read_text())
        if (not isinstance(plan, dict) or set(plan) != {'migrate','cache','queue_restart'}
                or type(plan['migrate']) is not bool or type(plan['queue_restart']) is not bool
                or not isinstance(plan['cache'], list) or not all(isinstance(x,str) for x in plan['cache'])
                or len(plan['cache']) != len(set(plan['cache'])) or set(plan['cache']) - {'config','routes','views'}):
            raise ReleaseError('invalid data-only lifecycle plan')
    manifest = (json.dumps({'format': 'ppb-v2-release-v1', 'commit': sha, 'builder': 'git-tracked-source-plus-composer-cache-compatible-vendor', 'operations': plan}, sort_keys=True) + '\n').encode()
    try:
        with output.open('xb') as stream:
            with gzip.GzipFile(filename='', fileobj=stream, mode='wb', mtime=commit_timestamp) as compressed:
                with tarfile.open(fileobj=compressed, mode='w', format=tarfile.PAX_FORMAT) as tar:
                    with io.BytesIO(manifest) as data:
                        tar.addfile(tarinfo('.ppb-release.json', len(manifest), commit_timestamp), data)
                    for rel, path in files:
                        with path.open('rb') as src:
                            tar.addfile(tarinfo(rel, path.stat().st_size, commit_timestamp), src)
    except BaseException:
        output.unlink(missing_ok=True)
        raise
    print('COMMIT=' + sha)
    print('PACKAGE_FILES=' + str(len(files)))
    print('RESULT=PPB_V2_RELEASE_ARCHIVE_BUILT')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=pathlib.Path, required=True)
    parser.add_argument('--output', type=pathlib.Path, required=True)
    args = parser.parse_args()
    try:
        build(args.root, args.output)
    except (ReleaseError, OSError, subprocess.CalledProcessError) as exc:
        print('RESULT=PPB_V2_RELEASE_BUILD_FAIL', file=sys.stderr)
        print('REASON=' + str(exc), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
