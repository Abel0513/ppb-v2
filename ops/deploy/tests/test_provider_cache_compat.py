#!/usr/bin/env python3
"""Regression: a deployment candidate must load every provider in live caches.

Runs SSM's fixed inline PHP in isolated TemporaryDirectory fixtures only.
Never contacts AWS, a database, WordPress, LINE or a real web host.
"""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

DOC = Path(__file__).resolve().parents[1] / 'PPB-V2-Controlled-Deploy.ssm.json'


class LiveProviderCacheCompatibility(unittest.TestCase):
    def setUp(self):
        if not shutil.which('php'):
            self.skipTest('PHP CLI unavailable for the offline provider-cache integration test')
        tmp = tempfile.TemporaryDirectory(prefix='ppb-provider-guard-')
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name)
        self.live = self.root / 'live'
        self.candidate = self.root / 'candidate'
        (self.live / 'bootstrap/cache').mkdir(parents=True)
        (self.candidate / 'vendor').mkdir(parents=True)
        self.provider = r'Laravel\Pail\PailServiceProvider'
        (self.candidate / 'vendor/autoload.php').write_text('<?php return true;\n')
        (self.live / 'bootstrap/cache/packages.php').write_text(
            "<?php return ['laravel/pail' => ['providers' => ['Laravel\\\\Pail\\\\PailServiceProvider']]];\n"
        )
        (self.live / 'bootstrap/cache/services.php').write_text(
            "<?php return ['providers' => ['Laravel\\\\Pail\\\\PailServiceProvider']];\n"
        )
        document = json.loads(DOC.read_text())
        steps = [x for x in document['mainSteps'] if x['name'] == 'controlledRelease']
        self.assertEqual(len(steps), 1)
        commands = steps[0]['inputs']['runCommand']
        guards = [x for x in commands if x.startswith("php -r '")]
        self.assertEqual(len(guards), 1)
        self.guard = guards[0]
        self.assertLess(commands.index(self.guard), commands.index(
            next(x for x in commands if x.startswith('exec python3 '))))
        self.assertIn('RESULT=PPB_V2_PROVIDER_CACHE_COMPAT_PASS', self.guard)

    def invoke(self):
        # Absolute fixture paths are controlled by this test, not the user.
        cmd = "candidate='" + str(self.candidate) + "'; live='" + str(self.live) + "'; " + self.guard
        return subprocess.run(['sh', '-c', cmd], capture_output=True,
                              text=True, timeout=8)

    def test_missing_live_cached_pail_provider_fails_before_writes(self):
        previous = (self.live / 'bootstrap/cache/packages.php').read_bytes()
        p = self.invoke()
        self.assertNotEqual(p.returncode, 0, p.stdout + p.stderr)
        self.assertIn('STOP=CANDIDATE_MISSING_CACHED_PROVIDER:' + self.provider, p.stderr)
        self.assertEqual((self.live / 'bootstrap/cache/packages.php').read_bytes(), previous)

    def test_matching_pail_provider_passes_without_touching_live_cache(self):
        (self.candidate / 'vendor/autoload.php').write_text(
            r'<?php namespace Laravel\Pail; class PailServiceProvider {}' + '\n'
        )
        previous = (self.live / 'bootstrap/cache/services.php').read_bytes()
        p = self.invoke()
        self.assertEqual(p.returncode, 0, p.stdout + p.stderr)
        self.assertIn('PROVIDER_CLASSES_CHECKED=2', p.stdout)
        self.assertIn('RESULT=PPB_V2_PROVIDER_CACHE_COMPAT_PASS', p.stdout)
        self.assertEqual((self.live / 'bootstrap/cache/services.php').read_bytes(), previous)

    def test_missing_live_services_cache_fails_closed(self):
        # Make Pail loadable so the test reaches the missing-services guard.
        (self.candidate / 'vendor/autoload.php').write_text(
            r'<?php namespace Laravel\Pail; class PailServiceProvider {}' + '\n'
        )
        (self.live / 'bootstrap/cache/services.php').unlink()
        p = self.invoke()
        self.assertNotEqual(p.returncode, 0)
        self.assertIn('STOP=LIVE_PROVIDER_CACHE_MISSING_OR_LINK:services.php', p.stderr)

    def test_inline_guard_and_document_are_posix_shell_compatible(self):
        document = json.loads(DOC.read_text())
        for step in document['mainSteps']:
            if step['action'] != 'aws:runShellScript':
                continue
            script = '\n'.join(step['inputs']['runCommand']) + '\n'
            result = subprocess.run(['sh', '-n'], input=script,
                                    capture_output=True, text=True, timeout=5)
            self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == '__main__':
    unittest.main()
