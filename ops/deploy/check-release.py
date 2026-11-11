#!/usr/bin/env python3
"""Read-only validation of a PPB V2 release tarball, before staging or deployment.

No extraction, production directory access, database access or network access.
"""
import argparse
import pathlib
import sys
import tarfile

MAX_ARCHIVE_BYTES = 150 * 1024 * 1024
MAX_UNPACKED_BYTES = 500 * 1024 * 1024
MAX_MEMBERS = 20000
REQUIRED_FILES = {
    'artisan', 'composer.json', 'composer.lock',
    'public/index.php', 'vendor/autoload.php',
}
FORBIDDEN_ROOTS = {'.git', '.github', '.ssh', '.codex', 'storage', 'node_modules'}
FORBIDDEN_PUBLIC_SUBTREES = {'.well-known', 'storage', 'uploads', 'files', 'media'}
FORBIDDEN_FILE_NAMES = {'.npmrc', 'auth.json'}
FORBIDDEN_SUFFIXES = ('.pem', '.p12', '.pfx', '.key', '.sql', '.sqlite', '.zip')


class UnsafeRelease(Exception):
    pass


def check_archive(archive: pathlib.Path) -> tuple[int, int]:
    if not archive.is_file() or archive.is_symlink():
        raise UnsafeRelease('release archive must be a regular file (not a symlink)')
    if archive.stat().st_size > MAX_ARCHIVE_BYTES:
        raise UnsafeRelease('release archive exceeds compressed size limit')
    if archive.stat().st_size == 0:
        raise UnsafeRelease('release archive is empty')

    found_files: set[str] = set()
    encountered: set[str] = set()
    total_size = 0
    count = 0

    try:
        with tarfile.open(archive, 'r:gz') as tar:
            for member in tar:
                raw = member.name
                if raw in ('.', './') and member.isdir():
                    continue
                while raw.startswith('./'):
                    raw = raw[2:]
                if member.isdir():
                    raw = raw.rstrip('/')
                parts = raw.split('/')
                if not raw or raw.startswith('/') or '\\' in raw or any(
                    p in ('', '.', '..') for p in parts
                ):
                    raise UnsafeRelease('unsafe archive entry path')
                if len(raw) > 255:
                    raise UnsafeRelease('entry path exceeds limit')
                if not (member.isfile() or member.isdir()):
                    raise UnsafeRelease('archive contains links or special files')
                if raw in encountered:
                    raise UnsafeRelease('archive contains duplicate entries')
                encountered.add(raw)
                count += 1
                if count > MAX_MEMBERS:
                    raise UnsafeRelease('too many archive entries')

                lower_parts = [p.lower() for p in parts]
                if lower_parts[0] in FORBIDDEN_ROOTS:
                    raise UnsafeRelease('release includes a forbidden top-level directory')
                if (lower_parts[0] == 'public' and len(parts) >= 2
                        and lower_parts[1] in FORBIDDEN_PUBLIC_SUBTREES):
                    raise UnsafeRelease('release includes a protected public path')
                if (any(p.startswith('.env') for p in lower_parts)
                        or any(p in FORBIDDEN_FILE_NAMES for p in lower_parts)
                        or lower_parts[-1].endswith(FORBIDDEN_SUFFIXES)):
                    raise UnsafeRelease('release contains a protected or secret-like path')
                if member.isfile():
                    total_size += member.size
                    if total_size > MAX_UNPACKED_BYTES:
                        raise UnsafeRelease('release exceeds unpacked size limit')
                    found_files.add(raw)
    except (tarfile.TarError, EOFError, OSError) as exc:
        raise UnsafeRelease('release is not a readable gzip tarball') from exc

    missing = REQUIRED_FILES - found_files
    if missing:
        raise UnsafeRelease('release missing required program files: ' + ', '.join(sorted(missing)))
    return count, total_size


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('archive', type=pathlib.Path)
    args = parser.parse_args()
    try:
        count, size = check_archive(args.archive)
    except UnsafeRelease as exc:
        print('RESULT=PPB_V2_RELEASE_CHECK_FAIL', file=sys.stderr)
        print(f'REASON={exc}', file=sys.stderr)
        return 1
    print(f'ENTRIES={count}')
    print(f'UNPACKED_BYTES={size}')
    print('RESULT=PPB_V2_RELEASE_CHECK_PASS')
    return 0


if __name__ == '__main__':
    sys.exit(main())
