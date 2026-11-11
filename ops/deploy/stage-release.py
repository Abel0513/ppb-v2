#!/usr/bin/env python3
"""PPB V2: stage an already downloaded/pinned release privately, without deployment.

Requires SHA256 + immutable 40-hex commit. Never writes to the live application,
database, nginx, storage, .env, service configs or Cron. No network access.
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
import sys
import tarfile
import tempfile

CHECK = runpy.run_path(str(Path(__file__).with_name('check-release.py')))
validate_archive = CHECK['check_archive']


class StageError(Exception):
    pass


def sha256(path):
    digest = hashlib.sha256()
    with path.open('rb') as fp:
        for block in iter(lambda: fp.read(1048576), b''):
            digest.update(block)
    return digest.hexdigest()


def stage(archive: Path, expected_sha256: str, commit: str,
          staging_root: Path, live_root: Path):
    if not re.fullmatch(r'[0-9a-f]{64}', expected_sha256):
        raise StageError('SHA256 must be exactly 64 lowercase hex characters')
    if not re.fullmatch(r'[0-9a-f]{40}', commit):
        raise StageError('commit must be exactly 40 lowercase hex characters')
    if not staging_root.is_dir() or staging_root.is_symlink():
        raise StageError('staging root missing or symlink')
    if staging_root.stat().st_mode & 0o077:
        raise StageError('staging root must have private permissions (700)')
    if not live_root.is_dir() or live_root.is_symlink():
        raise StageError('live root missing or symlink')
    stage_real, live_real = staging_root.resolve(), live_root.resolve()
    if stage_real == live_real or stage_real.is_relative_to(live_real) or live_real.is_relative_to(stage_real):
        raise StageError('staging and live roots must be separate')
    if not archive.is_file() or archive.is_symlink():
        raise StageError('release archive must be regular file, not symlink')
    if archive.resolve().is_relative_to(live_real):
        raise StageError('release archive cannot be inside live root')
    if sha256(archive) != expected_sha256:
        raise StageError('downloaded archive SHA256 does not match pinned value')

    try:
        members, uncompressed = validate_archive(archive)
    except Exception as exc:
        raise StageError('unsafe or incomplete release archive: ' + str(exc)) from exc

    with tarfile.open(archive, 'r:gz') as tar:
        try:
            manifest_member = tar.getmember('.ppb-release.json')
            if not manifest_member.isfile() or manifest_member.size > 4096:
                raise StageError('invalid release manifest')
            manifest_fp = tar.extractfile(manifest_member)
            if manifest_fp is None:
                raise StageError('cannot read release manifest')
            manifest = json.load(manifest_fp)
        except (KeyError, ValueError, UnicodeError, json.JSONDecodeError) as exc:
            raise StageError('release manifest missing or invalid') from exc
        if manifest.get('format') != 'ppb-v2-release-v1' or manifest.get('commit') != commit:
            raise StageError('release manifest does not match approved Git commit')

    target = staging_root / ('candidate-' + commit[:12])
    if target.exists() or target.is_symlink():
        raise StageError('candidate already exists; refusing to overwrite')

    old_umask = os.umask(0o077)
    pending = None
    try:
        pending = Path(tempfile.mkdtemp(prefix='.ppb-stage-partial-', dir=staging_root))
        count = 0
        unchanged = changed = new = 0
        diff_examples = []
        with tarfile.open(archive, 'r:gz') as tar:
            for member in tar:
                rel = member.name
                while rel.startswith('./'):
                    rel = rel[2:]
                if rel in ('', '.'):
                    continue
                dest = pending / rel
                if member.isdir():
                    dest.mkdir(parents=True, exist_ok=True, mode=0o700)
                elif member.isfile():
                    dest.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                    src = tar.extractfile(member)
                    if src is None:
                        raise StageError('cannot read archived file')
                    with src, dest.open('xb') as out:
                        shutil.copyfileobj(src, out, 1048576)
                    count += 1
                    if rel == '.ppb-release.json':
                        continue
                    live_file = live_root / rel
                    if live_file.is_symlink() or not live_file.is_file():
                        new += 1
                        outcome = 'NEW'
                    elif filecmp.cmp(dest, live_file, shallow=False):
                        unchanged += 1
                        outcome = None
                    else:
                        changed += 1
                        outcome = 'DIFFERENT'
                    if outcome and len(diff_examples) < 15:
                        diff_examples.append(outcome + '=' + rel)
                else:
                    raise StageError('unsafe archive member during extraction')
        if count < 6:
            raise StageError('incomplete candidate')
        # The immutable completed candidate is a PRIVATE staging directory.
        # No live path is opened for writing.
        pending.rename(target)
        pending = None
    finally:
        os.umask(old_umask)
        if pending is not None and pending.exists():
            shutil.rmtree(pending)

    print('APPROVED_COMMIT=' + commit)
    print('ARCHIVE_SHA256=' + expected_sha256)
    print('ARCHIVE_ENTRIES=' + str(members))
    print('ARCHIVE_UNPACKED_BYTES=' + str(uncompressed))
    print('CANDIDATE_FILES=' + str(count))
    print('LIVE_FILES_SAME=' + str(unchanged))
    print('LIVE_FILES_DIFFERENT=' + str(changed))
    print('LIVE_FILES_NOT_PRESENT=' + str(new))
    for entry in diff_examples:
        print('DIFF_SAMPLE_' + entry)
    print('STAGED_CANDIDATE=' + str(target))
    print('WARNING=Deletion and runtime behavior are NOT assessed by this staging report')
    print('LIVE_APPLICATION_MODIFIED=NO')
    print('DATABASE_MODIFIED=NO')
    print('RESULT=PPB_V2_PRIVATE_STAGE_PASS')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive', type=Path, required=True)
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--commit', required=True)
    parser.add_argument('--staging-root', type=Path, default=Path('/www/ppb-v2-deploy/releases'))
    parser.add_argument('--live-root', type=Path, default=Path('/www/wwwroot/ppbaos.com'))
    args = parser.parse_args()
    try:
        stage(args.archive, args.sha256, args.commit, args.staging_root, args.live_root)
    except (StageError, OSError, tarfile.TarError) as exc:
        print('RESULT=PPB_V2_PRIVATE_STAGE_FAIL', file=sys.stderr)
        print('REASON=' + str(exc), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
