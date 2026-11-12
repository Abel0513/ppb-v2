#!/usr/bin/env python3
"""Offline controlled-deploy tests: all paths are TemporaryDirectory fixtures.

Never accesses AWS, /www, production DB, WP, LINE, nginx or any live site.
"""
import hashlib
import io
import importlib.util
import json
from pathlib import Path
import stat
import tarfile
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'controlled-deploy.py'
spec = importlib.util.spec_from_file_location('ppb_controlled_deploy', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
ControlledDeploy = module.ControlledDeploy
DeployError = module.DeployError
COMMIT = 'f' * 40
REQUIRED = {
    'artisan': b'console\n',
    'composer.json': b'{"name":"fixture/project"}\n',
    'composer.lock': b'{}\n',
    'public/index.php': b'<?php echo "hello";\n',
    'vendor/autoload.php': b'<?php return true;\n',
    'routes/web.php': b'<?php return true;\n',
}


class DeploymentFixture(unittest.TestCase):
    def setUp(self):
        t = tempfile.TemporaryDirectory(prefix='ppb-control-')
        self.addCleanup(t.cleanup)
        self.tmp = Path(t.name)
        self.live = self.tmp / 'live'
        self.live.mkdir(mode=0o700)
        self.private = self.tmp / 'private'
        self.private.mkdir(mode=0o700)
        self.private.chmod(0o700)
        for part in ('releases', 'backups'):
            (self.private / part).mkdir(mode=0o700)
            (self.private / part).chmod(0o700)
        self.write_live('.env', b'PRIVATE-PRODUCTION-ENV', mode=0o640)
        self.write_live('storage/framework/sessions/do-not-change', b'SESSION', mode=0o600)
        self.write_live('public/.well-known/acme-challenge/keep', b'ACME', mode=0o644)
        self.write_live('public/assets/keep.css', b'CSS', mode=0o644)
        self.write_live('bootstrap/cache/old.php', b'CACHE', mode=0o644)
        for rel, content in REQUIRED.items():
            self.write_live(rel, content)
        self.filelist = dict(REQUIRED)
        self.archive = self.private / 'releases' / 'fixture.tar.gz'
        self.build()
        self.deploy = ControlledDeploy(live=self.live, private=self.private,
                                       health=lambda: '302', require_host=False)

    def write_live(self, rel, content, mode=0o644):
        path = self.live / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
        path.chmod(mode)

    def build(self, files=None, commit=COMMIT):
        files = files or self.filelist
        manifest = json.dumps({'format': 'ppb-v2-release-v1', 'commit': commit}).encode()
        with tarfile.open(self.archive, 'w:gz') as tar:
            for rel, data in {'.ppb-release.json': manifest, **files}.items():
                member = tarfile.TarInfo(rel)
                member.size = len(data)
                tar.addfile(member, io.BytesIO(data))
        self.sha = hashlib.sha256(self.archive.read_bytes()).hexdigest()

    def plan(self):
        self.deploy.verify_host()
        return self.deploy.audit(self.archive, self.sha, COMMIT)

    def test_audit_does_not_write_live(self):
        self.filelist['routes/web.php'] = b'<?php echo "new";\n'
        self.build()
        before = (self.live / 'routes/web.php').read_bytes()
        candidate, names, actions = self.plan()
        self.assertTrue(candidate.is_dir())
        self.assertEqual(actions, [{'kind': 'replace', 'path': 'routes/web.php'}])
        self.assertEqual((self.live / 'routes/web.php').read_bytes(), before)
        self.assertFalse((self.private / 'current-release.json').exists())

    def test_deploy_and_manual_rollback_preserve_runtime(self):
        self.filelist['routes/web.php'] = b'<?php echo "new";\n'
        self.filelist['app/NewController.php'] = b'<?php return 2;\n'
        self.build()
        candidate, names, actions = self.plan()
        (self.live / 'routes/web.php').chmod(0o640)
        original_stat = (self.live / 'routes/web.php').stat()
        self.deploy.apply(candidate, names, actions, self.sha, COMMIT)
        self.assertEqual((self.live / 'routes/web.php').read_bytes(), self.filelist['routes/web.php'])
        self.assertTrue((self.live / 'app/NewController.php').is_file())
        for rel, data in [
            ('.env', b'PRIVATE-PRODUCTION-ENV'),
            ('storage/framework/sessions/do-not-change', b'SESSION'),
            ('public/.well-known/acme-challenge/keep', b'ACME'),
            ('public/assets/keep.css', b'CSS'),
            ('bootstrap/cache/old.php', b'CACHE'),
        ]:
            self.assertEqual((self.live / rel).read_bytes(), data)
        self.deploy.rollback_latest()
        self.assertEqual((self.live / 'routes/web.php').read_bytes(), REQUIRED['routes/web.php'])
        restored = (self.live / 'routes/web.php').stat()
        self.assertEqual(stat.S_IMODE(restored.st_mode), stat.S_IMODE(original_stat.st_mode))
        self.assertEqual((restored.st_uid, restored.st_gid), (original_stat.st_uid, original_stat.st_gid))
        self.assertFalse((self.live / 'app/NewController.php').exists())
        self.assertFalse((self.private / 'current-release.json').exists())
        self.assertFalse((self.private / 'pending-deployment.json').exists())

    def test_failed_post_health_auto_rolls_back(self):
        self.filelist['routes/web.php'] = b'<?php echo 42;\n'
        self.build()
        candidate, names, actions = self.plan()
        calls = [0]
        def health():
            calls[0] += 1
            if calls[0] == 2:
                raise DeployError('simulated HTTP 500')
            return '302'
        self.deploy.health = health
        with self.assertRaises(DeployError):
            self.deploy.apply(candidate, names, actions, self.sha, COMMIT)
        self.assertEqual((self.live / 'routes/web.php').read_bytes(), REQUIRED['routes/web.php'])
        self.assertFalse((self.private / 'pending-deployment.json').exists())

    def test_rejects_diff_in_protected_public_assets(self):
        self.filelist['public/assets/keep.css'] = b'REPLACED'
        self.build()
        with self.assertRaises(DeployError):
            self.plan()
        self.assertEqual((self.live / 'public/assets/keep.css').read_bytes(), b'CSS')

    def test_rejects_unapproved_managed_deletion(self):
        candidate, names, actions = self.plan()
        self.deploy.apply(candidate, names, actions, self.sha, COMMIT)
        self.filelist.pop('routes/web.php')
        # Use a new commit to prevent reusing previous candidate.
        other_commit = 'e' * 40
        self.build(commit=other_commit)
        names = self.deploy.package(self.archive, self.sha, other_commit)
        cand = self.deploy.ensure_candidate(self.archive, self.sha, other_commit, names)
        with self.assertRaisesRegex(DeployError, 'deleted managed paths'):
            self.deploy.plan(cand, names)

    def test_rejects_malicious_symlink_target(self):
        (self.live / 'routes/web.php').unlink()
        (self.live / 'routes/web.php').symlink_to(self.live / '.env')
        with self.assertRaisesRegex(DeployError, 'symlink'):
            self.plan()

    def test_rejects_archive_hash_mismatch(self):
        with self.assertRaisesRegex(DeployError, 'SHA256'):
            self.deploy.package(self.archive, '0' * 64, COMMIT)

    def test_rejects_wrong_commit_in_manifest(self):
        with self.assertRaisesRegex(DeployError, 'commit'):
            self.deploy.package(self.archive, self.sha, 'e' * 40)

    def test_rejects_unfinished_transaction(self):
        (self.private / 'pending-deployment.json').write_text('{"transaction":"txn-20261112T000000Z-ffffffffffff"}')
        with self.assertRaisesRegex(DeployError, 'rollback must run first'):
            self.plan()

    def test_rejects_unprotected_private_directory(self):
        self.private.chmod(0o755)
        with self.assertRaisesRegex(DeployError, 'private'):
            self.deploy.verify_host()

    def test_deletion_approval_limits_to_previously_managed(self):
        candidate, names, actions = self.plan()
        self.deploy.apply(candidate, names, actions, self.sha, COMMIT)
        self.filelist.pop('routes/web.php')
        new_commit = 'e' * 40
        self.build(commit=new_commit)
        new_names = self.deploy.package(self.archive, self.sha, new_commit)
        new_candidate = self.deploy.ensure_candidate(self.archive, self.sha, new_commit, new_names)
        approved = self.deploy.plan(new_candidate, new_names, delete_allowed=True)
        self.assertEqual(approved, [{'kind': 'delete', 'path': 'routes/web.php'}])
        self.assertFalse(any(a['path'].startswith('storage/') for a in approved))


if __name__ == '__main__':
    unittest.main()
