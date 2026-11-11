#!/usr/bin/env python3
"""Offline safety checks. Creates only temporary files, never databases."""
import io
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'check-release.py'
CHECK_LIVE = Path(__file__).resolve().parents[1] / 'check-live.sh'
REQUIRED = ('artisan', 'composer.json', 'composer.lock', 'public/index.php', 'vendor/autoload.php')


class GuardTest(unittest.TestCase):
    def setUp(self):
        self.ctx = tempfile.TemporaryDirectory(prefix='ppb-guard-test-')
        self.addCleanup(self.ctx.cleanup)
        self.tmp = Path(self.ctx.name)
        self.archive = self.tmp / 'release.tar.gz'

    def make(self, entries=REQUIRED, special=None):
        with tarfile.open(self.archive, 'w:gz') as tar:
            for name in entries:
                content = b'file-content'
                info = tarfile.TarInfo(name)
                info.size = len(content)
                tar.addfile(info, io.BytesIO(content))
            if special:
                info = tarfile.TarInfo(special)
                info.type = tarfile.SYMTYPE
                info.linkname = '/etc/passwd'
                tar.addfile(info)

    def check(self, expected):
        p = subprocess.run([sys.executable, str(SCRIPT), str(self.archive)],
                           text=True, capture_output=True)
        self.assertEqual(p.returncode, expected, p.stdout + p.stderr)
        return p.stdout + p.stderr

    def test_valid(self):
        self.make()
        self.assertIn('RESULT=PPB_V2_RELEASE_CHECK_PASS', self.check(0))

    def test_normal_tar_directories(self):
        with tarfile.open(self.archive, 'w:gz') as tar:
            for dirname in ('./', './public/'):
                info = tarfile.TarInfo(dirname)
                info.type = tarfile.DIRTYPE
                tar.addfile(info)
            for name in REQUIRED:
                content = b'file-content'
                info = tarfile.TarInfo('./' + name)
                info.size = len(content)
                tar.addfile(info, io.BytesIO(content))
        self.check(0)

    def test_missing_entry(self):
        self.make(REQUIRED[:-1])
        self.check(1)

    def test_parent_traversal(self):
        self.make((*REQUIRED, '../root/.env'))
        self.check(1)

    def test_dot_env(self):
        self.make((*REQUIRED, '.env'))
        self.check(1)

    def test_protected_public(self):
        self.make((*REQUIRED, 'public/.well-known/example'))
        self.check(1)

    def test_public_storage(self):
        self.make((*REQUIRED, 'public/storage/upload.bin'))
        self.check(1)

    def test_secrets(self):
        self.make((*REQUIRED, 'config/private-key.pem'))
        self.check(1)

    def test_symlink(self):
        self.make(special='resources/unsafe')
        self.check(1)

    def test_duplicate(self):
        self.make((*REQUIRED, 'artisan'))
        self.check(1)

    def test_reject_empty_archive(self):
        self.archive.write_bytes(b'')
        self.check(1)

    def test_live_guard_in_private_fixture(self):
        live = self.tmp / 'live'
        deploy = self.tmp / 'deploy'
        for name in ('public', 'vendor', 'storage'):
            (live / name).mkdir(parents=True, exist_ok=True)
        for name in ('artisan', '.env', 'composer.json', 'composer.lock',
                     'public/index.php', 'vendor/autoload.php'):
            (live / name).write_text('fixture')
        for name in ('backups', 'scripts'):
            (deploy / name).mkdir(parents=True, exist_ok=True)
            (deploy / name).chmod(0o700)
        deploy.chmod(0o700)
        baseline = deploy / 'backups' / 'baseline-TEST'
        baseline.mkdir()
        for name in ('.env', 'artisan', '.snapshot-complete'):
            (baseline / name).write_text('fixture')
        env = os.environ.copy()
        env.update(PPB_LIVE_ROOT=str(live), PPB_DEPLOY_ROOT=str(deploy))
        p = subprocess.run(['bash', str(CHECK_LIVE)], env=env, capture_output=True, text=True)
        self.assertEqual(p.returncode, 0, p.stdout + p.stderr)
        self.assertIn('RESULT=PPB_V2_LIVE_GUARD_PASS', p.stdout)
        (live / '.env').unlink()
        failed = subprocess.run(['bash', str(CHECK_LIVE)], env=env, capture_output=True, text=True)
        self.assertNotEqual(failed.returncode, 0)


if __name__ == '__main__':
    unittest.main()
