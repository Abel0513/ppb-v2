"""Historical three-file bootstrap scope remains tested; new releases use isolated client."""
import json,os,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[3]
SSM_DOC=ROOT/'ops/deploy/PPB-V2-Controlled-Deploy.ssm.json'
COMMIT='a'*40
class HistoricalSsmScope(unittest.TestCase):
    def setUp(self):
        temp=tempfile.TemporaryDirectory();self.addCleanup(temp.cleanup);self.temp=Path(temp.name)
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
    def test_first_deployment_file_gate_with_real_temp_files(self):
        """Allow exactly three changed files from the original six, never others."""
        doc = json.loads(SSM_DOC.read_text())
        release = next(s for s in doc['mainSteps'] if s['name'] == 'controlledRelease')
        commands = release['inputs']['runCommand']
        start = next(i for i, item in enumerate(commands)
                     if item.startswith('  for required in "REPLACE_FILES=3"'))
        end = next(i for i, item in enumerate(commands)
                   if 'RESULT=PPB_V2_FIRST_DEPLOYMENT_THREE_OF_SIX_SCOPE_PASS' in item)
        scope = '\n'.join(commands[start:end + 1])
        approved = (
            'vendor/composer/autoload_classmap.php',
            'vendor/composer/autoload_files.php',
            'vendor/composer/autoload_psr4.php',
            'vendor/composer/autoload_static.php',
            'vendor/composer/installed.json',
            'vendor/composer/installed.php',
        )
        candidate = self.temp / 'candidate'
        live = self.temp / 'live'
        for i, rel in enumerate(approved):
            cp, lp = candidate / rel, live / rel
            cp.parent.mkdir(parents=True, exist_ok=True)
            lp.parent.mkdir(parents=True, exist_ok=True)
            cp.write_bytes(b'new' if i < 3 else b'same')
            lp.write_bytes(b'old' if i < 3 else b'same')

        def call(replacements=3):
            env = os.environ.copy()
            env.update({
                'candidate': str(candidate), 'live': str(live),
                'SSM_Commit': COMMIT,
                'audit_output': (
                    f'COMMIT={COMMIT}\nREPLACE_FILES={replacements}\n'
                    'ADD_FILES=0\nDELETE_FILES=0\n'
                    'RESULT=PPB_V2_CONTROLLED_AUDIT_PASS\n'
                ),
            })
            return subprocess.run(['sh', '-c', 'set -eu\n' + scope],
                                  env=env, capture_output=True, text=True, timeout=5)

        p = call()
        self.assertEqual(p.returncode, 0, p.stdout + p.stderr)
        self.assertEqual(p.stdout.count('APPROVED_DIFF_FILE='), 3)
        self.assertIn('RESULT=PPB_V2_FIRST_DEPLOYMENT_THREE_OF_SIX_SCOPE_PASS', p.stdout)

        # An unapproved path is changed, but the six approved paths only show 2
        # differences. Audit count 3 must NOT authorize such a plan.
        (candidate / approved[2]).write_bytes(b'old')
        p = call()
        self.assertNotEqual(p.returncode, 0)
        self.assertIn('STOP=FIRST_DEPLOYMENT_DIFFERENCES_OUTSIDE_APPROVED_SIX', p.stdout)
        (candidate / approved[2]).write_bytes(b'new')

        # Any extra difference must fail the fixed three-file audit count gate.
        p = call(replacements=4)
        self.assertNotEqual(p.returncode, 0)
        self.assertIn('STOP=FIRST_DEPLOYMENT_SCOPE_MISMATCH:REPLACE_FILES=3', p.stdout)
