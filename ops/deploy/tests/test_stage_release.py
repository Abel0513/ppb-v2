#!/usr/bin/env python3
"""Test private-only PPB staging. No AWS, real production paths or databases."""
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'stage-release.py'
COMMIT = 'deaa3d53278f2bc43c7691cf9bd8f4ea6f078f48'
REQUIRED = ('artisan', 'composer.json', 'composer.lock', 'public/index.php', 'vendor/autoload.php')


class PrivateStageTest(unittest.TestCase):
    def setUp(self):
        t = tempfile.TemporaryDirectory(prefix='ppb-stage-test-')
        self.addCleanup(t.cleanup)
        self.tmp = Path(t.name)
        self.staging_root = self.tmp / 'private-releases'
        self.staging_root.mkdir(mode=0o700)
        self.staging_root.chmod(0o700)
        self.live_root = self.tmp / 'live'
        self.live_root.mkdir(mode=0o700)
        (self.live_root / 'artisan').write_bytes(b'old')
        (self.live_root / '.env').write_bytes(b'PRIVATE-PRODUCTION-CONFIG')
        self.archive = self.tmp / 'release.tar.gz'
        self.make_archive()

    def make_archive(self, commit=COMMIT, extra=None):
        manifest = json.dumps({'format': 'ppb-v2-release-v1', 'commit': commit}).encode()
        entries = {name: b'new content' for name in REQUIRED}
        entries['.ppb-release.json'] = manifest
        if extra:
            entries.update(extra)
        with tarfile.open(self.archive, 'w:gz') as tar:
            for name, content in entries.items():
                info = tarfile.TarInfo(name)
                info.size = len(content)
                tar.addfile(info, io.BytesIO(content))

    def call(self, commit=COMMIT, sha=None, root=None):
        sha = sha or hashlib.sha256(self.archive.read_bytes()).hexdigest()
        result = subprocess.run([
            sys.executable, str(SCRIPT),
            '--archive', str(self.archive),
            '--sha256', sha,
            '--commit', commit,
            '--staging-root', str(root or self.staging_root),
            '--live-root', str(self.live_root),
        ], capture_output=True, text=True)
        return result

    def test_stages_verified_release_without_modifying_live(self):
        old_artisan = (self.live_root / 'artisan').read_bytes()
        old_env = (self.live_root / '.env').read_bytes()
        p = self.call()
        self.assertEqual(p.returncode, 0, p.stderr)
        self.assertIn('RESULT=PPB_V2_PRIVATE_STAGE_PASS', p.stdout)
        self.assertIn('LIVE_FILES_DIFFERENT=1', p.stdout)
        self.assertEqual((self.live_root / 'artisan').read_bytes(), old_artisan)
        self.assertEqual((self.live_root / '.env').read_bytes(), old_env)
        candidate = self.staging_root / ('candidate-' + COMMIT[:12])
        self.assertTrue((candidate / '.ppb-release.json').is_file())
        self.assertTrue((candidate / 'vendor/autoload.php').is_file())
        self.assertFalse((candidate / '.env').exists())
        self.assertFalse((candidate / 'storage').exists())

    def test_refuses_checksum_mismatch(self):
        p = self.call(sha='0' * 64)
        self.assertNotEqual(p.returncode, 0)
        self.assertFalse(any(self.staging_root.iterdir()))

    def test_refuses_commit_mismatch(self):
        p = self.call(commit='1' * 40)
        self.assertNotEqual(p.returncode, 0)
        self.assertFalse(any(self.staging_root.iterdir()))

    def test_refuses_reuse_candidate_directory(self):
        self.assertEqual(self.call().returncode, 0)
        self.assertNotEqual(self.call().returncode, 0)

    def test_refuses_private_staging_root_with_unsafe_permissions(self):
        self.staging_root.chmod(0o755)
        self.assertNotEqual(self.call().returncode, 0)

    def test_refuses_staging_inside_live(self):
        subdir = self.live_root / 'not-allowed'
        subdir.mkdir(mode=0o700)
        self.assertNotEqual(self.call(root=subdir).returncode, 0)

    def test_refuses_traversal_without_writing_files(self):
        self.make_archive(extra={'../escape': b'evil'})
        self.assertNotEqual(self.call().returncode, 0)
        self.assertFalse(any(self.staging_root.iterdir()))
        self.assertFalse((self.tmp / 'escape').exists())

    def test_refuses_secret_in_archive(self):
        self.make_archive(extra={'.env': b'secret'})
        self.assertNotEqual(self.call().returncode, 0)
        self.assertFalse(any(self.staging_root.iterdir()))

    def test_refuses_symlink_in_archive(self):
        self.make_archive()
        with tarfile.open(self.archive, 'w:gz') as tar:
            for name in REQUIRED:
                data = b'new content'
                info = tarfile.TarInfo(name)
                info.size = len(data)
                tar.addfile(info, io.BytesIO(data))
            manifest = json.dumps({'format': 'ppb-v2-release-v1', 'commit': COMMIT}).encode()
            info = tarfile.TarInfo('.ppb-release.json')
            info.size = len(manifest)
            tar.addfile(info, io.BytesIO(manifest))
            symlink = tarfile.TarInfo('public/link')
            symlink.type = tarfile.SYMTYPE
            symlink.linkname = '../.env'
            tar.addfile(symlink)
        self.assertNotEqual(self.call().returncode, 0)
        self.assertFalse(any(self.staging_root.iterdir()))

    def test_refuses_symlink_candidate_root(self):
        alias = self.tmp / 'link'
        alias.symlink_to(self.staging_root, target_is_directory=True)
        self.assertNotEqual(self.call(root=alias).returncode, 0)


if __name__ == '__main__':
    unittest.main()
