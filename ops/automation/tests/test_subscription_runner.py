"""Issue #14 ChatGPT subscription-only workflow invariants (static, offline)."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
WORKFLOW = ROOT / '.github/workflows/ppb-codex-automation.yml'
README = ROOT / 'ops/automation/README.md'

class SubscriptionRunnerWorkflowTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text()
        cls.guide = README.read_text()

    def test_chatgpt_login_not_platform_api(self):
        self.assertNotIn('openai/codex-action@', self.workflow)
        self.assertNotIn('secrets.OPENAI_API_KEY', self.workflow)
        self.assertIn('forced_login_method=chatgpt', self.workflow)
        self.assertIn('codex -c forced_login_method=chatgpt --ask-for-approval never exec', self.workflow)
        self.assertNotIn('exec --sandbox workspace-write --ask-for-approval', self.workflow)
        self.assertIn('--sandbox workspace-write', self.workflow)
        self.assertIn('codex -c forced_login_method=chatgpt login status', self.workflow)
        self.assertIn('API_KEY_AUTH_DISALLOWED', self.workflow)

    def test_dedicated_runner_gate_before_reservation(self):
        self.assertIn('ppb-codex-subscription', self.workflow)
        self.assertIn("vars.PPB_SUBSCRIPTION_RUNNER_READY == 'true'", self.workflow)
        self.assertIn('CODEX_GITHUB_TOKEN', self.workflow)
        self.assertIn('before reserving a task', self.workflow)
        self.assertIn('run: python3 ops/automation/control.py prepare', self.workflow)

    def test_pr_protection_and_limits_retained(self):
        self.assertIn('permissions:\n  contents: read', self.workflow)
        self.assertIn("group: ppb-codex-", self.workflow)
        self.assertIn('persist-credentials: false', self.workflow)
        self.assertIn('candidate.patch', self.workflow)
        self.assertIn('control.py', self.workflow)
        self.assertIn('@codex review', (ROOT/'ops/automation/control.py').read_text())
        self.assertIn('No merge/deploy executed', self.workflow)

    def test_documentation_no_api_secret_required(self):
        self.assertIn('codex login --device-auth', self.guide)
        self.assertIn('不需要建立 `OPENAI_API_KEY`', self.guide)
        self.assertIn('人工 merge', self.guide)
        self.assertIn('PPB_AUTO_DEPLOY', self.guide)

if __name__ == '__main__':
    unittest.main()
