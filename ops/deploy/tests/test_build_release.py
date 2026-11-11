#!/usr/bin/env python3
"""All tests use temporary local directories, no DB or AWS."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest

BUILDER = Path(__file__).resolve().parents[1] / 'build-release.py'


class BuildReleaseTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory(prefix='ppb-release-test-')
        self.addCleanup(tmp.cleanup)
        self.tmp = Path(tmp.name)
        self.root = self.tmp / 'repo'
        self.root.mkdir()
        subprocess.run(['git', 'init', '-q', str(self.root)], check=True)
        subprocess.run(['git', '-C', str(self.root), 'config', 'user.name', 'Offline Test'], check=True)
        subprocess.run(['git', '-C', str(self.root), 'config', 'user.email', 'test@invalid.local'], check=True)
        for p in ('artisan', 'composer.json', 'composer.lock', 'public/index.php', 'app/Example.php'):
            f = self.root / p
            f.parent.mkdir(parents=True, exist_ok=True)
            f.write_text('fixture\n')
        self.commit()
        vendor = self.root / 'vendor'
        vendor.mkdir()
        (vendor / 'autoload.php').write_text('fixture-autoload\n')
        (self.root / '.env').write_text('SECRET-DO-NOT-PACK')
        (self.root / 'storage').mkdir()
        (self.root / 'storage' / 'private.log').write_text('PRIVATE-DO-NOT-PACK')
        (self.root / 'public' / '.well-known').mkdir()
        (self.root / 'public' / '.well-known' / 'renewal').write_text('PRIVATE-CERT')
        self.out = self.tmp / 'a.tar.gz'

    def commit(self):
        subprocess.run(['git', '-C', str(self.root), 'add', '.'], check=True)
        subprocess.run(['git', '-C', str(self.root), 'commit', '-qm', 'fixture'], check=True)

    def build(self, out=None):
        return subprocess.run([sys.executable, str(BUILDER), '--root', str(self.root), '--output', str(out or self.out)], capture_output=True, text=True)

    def test_build_excludes_secrets_and_has_version_metadata(self):
        proc = self.build()
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        with tarfile.open(self.out, 'r:gz') as f:
            names = f.getnames()
            self.assertIn('.ppb-release.json', names)
            self.assertIn('vendor/autoload.php', names)
            self.assertIn('public/index.php', names)
            self.assertNotIn('.env', names)
            self.assertNotIn('storage/private.log', names)
            self.assertNotIn('public/.well-known/renewal', names)
            data = json.load(f.extractfile('.ppb-release.json'))
            self.assertEqual(data['format'], 'ppb-v2-release-v1')

    def test_deterministic_bytes(self):
        a = self.build()
        bpath = self.tmp / 'b.tar.gz'
        b = self.build(bpath)
        self.assertEqual((a.returncode, b.returncode), (0, 0), a.stderr + b.stderr)
        self.assertEqual(hashlib.sha256(self.out.read_bytes()).digest(), hashlib.sha256(bpath.read_bytes()).digest())

    def test_no_overwriting_existing_archive(self):
        self.assertEqual(self.build().returncode, 0)
        self.assertNotEqual(self.build().returncode, 0)

    def test_fails_on_tracked_secret_under_source_tree(self):
        p = self.root / 'app' / '.env.backup'
        p.write_text('SECRET')
        self.commit()
        self.assertNotEqual(self.build().returncode, 0)
        self.assertFalse(self.out.exists())

    def test_fails_on_vendor_symlink(self):
        (self.root / 'vendor' / 'linked').symlink_to(self.root / '.env')
        self.assertNotEqual(self.build().returncode, 0)


if __name__ == '__main__':
    unittest.main()
