#!/usr/bin/env python3
"""Offline regression tests for AWS SSM step output, no AWS or production writes."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[3]
WORKFLOW = ROOT / '.github/workflows/ppb-v2-release-package.yml'
SSM_DOC = ROOT / 'ops/deploy/PPB-V2-Controlled-Deploy.ssm.json'
ANCHOR = '      - name: Audit or deploy pinned version through fixed SSM document\n'
COMMIT = 'a' * 40
SHA = 'b' * 64


class SsmOutputCheck(unittest.TestCase):
    def setUp(self):
        ctx = tempfile.TemporaryDirectory(prefix='ppb-ssm-stdout-test-')
        self.addCleanup(ctx.cleanup)
        self.temp = Path(ctx.name)
        self.bin = self.temp / 'bin'
        self.bin.mkdir()
        self.aws_calls = self.temp / 'calls.txt'
        self.summary = self.temp / 'summary.txt'
        fake = self.bin / 'aws'
        fake.write_text("""#!/bin/sh
printf '%s\\n' "$*" >> "$SSM_TEST_CALLS"
case "$*" in
  *"ssm send-command"*) printf 'mock-command-id\\n' ;;
  *"ssm get-command-invocation"*)
    case "$*" in
      *"--plugin-name controlledRelease"*) ;;
      *) echo "Missing SSM plugin name" >&2; exit 51 ;;
    esac
    case "$*" in
      *"--query Status"*) printf '%s\\n' "$SSM_TEST_STATUS" ;;
      *) cat "$SSM_TEST_RESPONSE" ;;
    esac ;;
  *) echo "Unexpected AWS invocation" >&2; exit 52 ;;
esac
""")
        fake.chmod(0o700)
        workflow = WORKFLOW.read_text()
        self.assertIn(ANCHOR, workflow)
        step = workflow.split(ANCHOR, 1)[1].split('        run: |\n', 1)[1]
        self.script = textwrap.dedent(step)
        self.assertTrue(self.script.startswith('set -euo pipefail'))
        doc = json.loads(SSM_DOC.read_text())
        self.assertIn('controlledRelease', [step['name'] for step in doc['mainSteps']])
        self.assertIn('--plugin-name controlledRelease', self.script)

    def run_step(self, operation='audit', output=None, status='Success', code=0):
        if output is None:
            output = (
                f'COMMIT={COMMIT}\n'
                'REPLACE_FILES=6\nADD_FILES=0\nDELETE_FILES=0\n'
                'DATABASE_MIGRATIONS_EXECUTED=NO\n'
                f'RESULT=PPB_V2_CONTROLLED_{operation.upper()}_PASS\n'
            )
            if operation == 'deploy':
                output += 'LIVE_APPLICATION_DEPLOYED=YES\n'
        response = self.temp / 'response.json'
        response.write_text(json.dumps({
            'Status': status,
            'ResponseCode': code,
            'StandardOutputContent': output,
            'StandardErrorContent': '',
        }))
        env = os.environ.copy()
        env.update({
            'PATH': str(self.bin) + os.pathsep + env['PATH'],
            'SSM_TEST_CALLS': str(self.aws_calls),
            'SSM_TEST_RESPONSE': str(response),
            'SSM_TEST_STATUS': status,
            'GITHUB_SHA': COMMIT,
            'GITHUB_RUN_ID': '1234',
            'GITHUB_RUN_ATTEMPT': '1',
            'RELEASE_SHA256': SHA,
            'GITHUB_STEP_SUMMARY': str(self.summary),
            'PPB_OPERATION': operation,
            'AWS_REGION': 'us-east-1',
            'PPB_INSTANCE_ID': 'i-0135d6b2fac7a54c2',
        })
        return subprocess.run(
            ['bash', '-c', self.script], text=True, capture_output=True,
            env=env, cwd=ROOT, timeout=12
        )

    def test_ssm_document_shell_and_first_deploy_guard(self):
        """The first live deployment must be limited to the six audited files."""
        doc = json.loads(SSM_DOC.read_text())
        for step in doc['mainSteps']:
            if step['action'] != 'aws:runShellScript':
                continue
            script = '\n'.join(step['inputs']['runCommand']) + '\n'
            parsed = subprocess.run(['sh', '-n'], input=script, text=True,
                                    capture_output=True, timeout=5)
            self.assertEqual(parsed.returncode, 0, parsed.stderr)
        release = next(s for s in doc['mainSteps']
                       if s['name'] == 'controlledRelease')
        commands = '\n'.join(release['inputs']['runCommand'])
        self.assertIn('if [ "$SSM_Operation" = deploy ] && [ ! -e "$state" ]; then', commands)
        self.assertIn('FIRST_DEPLOYMENT_STRICT_SCOPE_CHECK=START', commands)
        self.assertIn('REPLACE_FILES=3', commands)
        self.assertIn('ADD_FILES=0', commands)
        self.assertIn('DELETE_FILES=0', commands)
        self.assertIn('RESULT=PPB_V2_CONTROLLED_AUDIT_PASS', commands)
        self.assertIn('RESULT=PPB_V2_FIRST_DEPLOYMENT_THREE_OF_SIX_SCOPE_PASS', commands)
        expected = (
            'vendor/composer/autoload_classmap.php',
            'vendor/composer/autoload_files.php',
            'vendor/composer/autoload_psr4.php',
            'vendor/composer/autoload_static.php',
            'vendor/composer/installed.json',
            'vendor/composer/installed.php',
        )
        for rel in expected:
            self.assertIn(rel, commands)
        self.assertLess(commands.index('FIRST_DEPLOYMENT_STRICT_SCOPE_CHECK=START'),
                        commands.index('exec python3 /www/ppb-v2-deploy/scripts/controlled-deploy.py'))
        self.assertIn('if ! cmp -s "$candidate/$rel" "$live/$rel"', commands)
        self.assertIn('approved_diff_count=$((approved_diff_count + 1))', commands)
        self.assertIn('test "$approved_diff_count" -eq 3', commands)
        self.assertIn('STOP=FIRST_DEPLOYMENT_DIFFERENCES_OUTSIDE_APPROVED_SIX', commands)

    def test_audit_step_output_visible_and_verified(self):
        p = self.run_step()
        self.assertEqual(p.returncode, 0, p.stdout + p.stderr)
        self.assertIn('RESULT=PPB_V2_SSM_AUDIT_PASS', p.stdout)
        self.assertIn('REPLACE_FILES=6', self.summary.read_text())
        self.assertIn('--plugin-name controlledRelease', self.aws_calls.read_text())

    def test_deploy_requires_live_confirmation(self):
        p = self.run_step(operation='deploy', output=(
            f'COMMIT={COMMIT}\nDATABASE_MIGRATIONS_EXECUTED=NO\n'
            'RESULT=PPB_V2_CONTROLLED_DEPLOY_PASS\n'
        ))
        self.assertNotEqual(p.returncode, 0)
        self.assertIn('without live deployment confirmation', p.stdout + p.stderr)

    def test_success_without_audit_marker_fails(self):
        p = self.run_step(output=f'COMMIT={COMMIT}\nDATABASE_MIGRATIONS_EXECUTED=NO\n')
        self.assertNotEqual(p.returncode, 0)
        self.assertIn('missing expected result', p.stdout + p.stderr)

    def test_wrong_commit_fails(self):
        p = self.run_step(output=(
            'COMMIT=' + 'c' * 40 + '\nDATABASE_MIGRATIONS_EXECUTED=NO\n'
            'RESULT=PPB_V2_CONTROLLED_AUDIT_PASS\n'
        ))
        self.assertNotEqual(p.returncode, 0)

    def test_nonzero_command_code_fails(self):
        p = self.run_step(code=1)
        self.assertNotEqual(p.returncode, 0)
        self.assertIn('step failed', p.stdout + p.stderr)


if __name__ == '__main__':
    unittest.main()
