import copy
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
def load(name,file):
    s=importlib.util.spec_from_file_location(name,ROOT/file);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
a=load('autonomy_test','autonomy.py');g=load('release_gate_test','release-gate.py');accept=load('manifest_accept_test','accept-v2.py')
SHA='a'*40
class Autonomy(unittest.TestCase):
    def pr(self):return dict(state='open',base={'ref':'main','sha':SHA},head={'ref':'codex/v2-feature','sha':'b'*40,'repo':{'full_name':a.REPO}},_verified_merge_base=SHA)
    def checks(self):return [dict(name=n,status='completed',conclusion='success',app={'slug':'github-actions'}) for n in a.CHECKS]
    def test_v2_database_and_dependency_changes_allowed_without_legacy_access(self):
        files=[{'filename':n} for n in ['app/Http/Controllers/New.php','database/migrations/new.php','composer.lock','resources/ppb/release.json']]
        result=a.validate_pr(self.pr(),files,self.checks(),SHA)
        self.assertTrue(result['migrations']);self.assertTrue(result['dependencies'])
    def test_workflow_ops_wp_and_credentials_cannot_auto_merge(self):
        for name in ['ops/deploy/controlled-deploy.py','.github/workflows/x.yml','Application/old.php','.env','public/uploads/file','app/db.php','database/dump.sql','../routes/web.php']:
            with self.assertRaises(RuntimeError):a.classify([{'filename':name}])
        p=self.pr();p['head']['ref']='codex/issue-12-wp-draft'
        with self.assertRaises(RuntimeError):a.validate_pr(p,[{'filename':'app/new.php'}],self.checks(),SHA)
    def test_draft_cannot_auto_merge(self):
        p=self.pr();p['draft']=True
        with self.assertRaisesRegex(RuntimeError,'DRAFT_PR'):a.validate_pr(p,[{'filename':'routes/web.php'}],self.checks(),SHA)
    def test_publication_waits_for_actual_acceptance(self):
        import json
        self.assertFalse(json.loads((ROOT/'V2_AUTOMATION_ACCEPTANCE.json').read_text())['ready'])
    def test_fork_stale_base_and_forged_checks_denied(self):
        for change in ['fork','base','check']:
            p=self.pr();checks=self.checks()
            if change=='fork':p['head']['repo']['full_name']='evil/fork'
            if change=='base':p['_verified_merge_base']='c'*40
            if change=='check':checks[0]['app']['slug']='evil-check'
            with self.assertRaises(RuntimeError):a.validate_pr(p,[{'filename':'app/a.php'}],checks,SHA)
    def test_release_requires_tested_actual_merge_parent(self):
        pr=self.pr();pr.update(merged=True,merge_commit_sha=SHA,merged_by={'login':'github-actions[bot]','type':'Bot'},_verified_parent_is_ancestor=True)
        self.assertTrue(g.gate(pr,SHA,[{'filename':'app/a.php'}],self.checks()))
        pr['_verified_parent_is_ancestor']=False
        with self.assertRaisesRegex(RuntimeError,'PARENT_NOT_TESTED'):g.gate(pr,SHA,[{'filename':'app/a.php'}],self.checks())
    def test_workflow_token_dispatch_is_explicit_and_scope_limited(self):
        text=(ROOT.parents[1]/'.github/workflows/ppb-v2-autonomous-merge.yml').read_text()
        self.assertIn('workflow_run:',text);self.assertIn('ref: main',text);self.assertNotIn('pull_request_target',text)
        self.assertIn('actions: write',text);self.assertNotIn('id-token: write',text)
        source=(ROOT/'autonomy.py').read_text()
        self.assertIn("'merge_method':'squash'",source)
        self.assertIn('expected_commit',source);self.assertIn('MAIN_MOVED_AT_MERGE_DO_NOT_DEPLOY',source)

class ManifestAcceptance(unittest.TestCase):
    def get(self,path):
        if path=='/admin/login':return 200,{},b'<input name="password"><input name="username">'
        if path=='/v2/example':return 200,{},b'expected fixture page'
        return 302,{'location':'https://v2.csdatw.xyz/admin/login'},b''
    def test_new_page_manifest_and_existing_four_baselines(self):
        result=accept.verify(SHA,{'commit':SHA},self.get,[dict(path='/v2/example',status=200,contains='fixture page')])
        self.assertEqual(result['CHECKS'],5);self.assertTrue(result['MANIFEST_CHECKS'][0]['matched'])
    def test_no_external_hosts_queries_commands_or_fake_status(self):
        for row in [dict(path='https://csdatw.com/',status=200),dict(path='/admin/../old',status=200),dict(path='/v2/example?cmd=x',status=200),dict(path='/v2/example',status=500),dict(path='/v2/example',status=200,shell='id')]:
            with self.assertRaises(ValueError):accept.verify(SHA,{'commit':SHA},self.get,[row])
    def test_false_content_and_old_route_regression_fail(self):
        with self.assertRaises(ValueError):accept.verify(SHA,{'commit':SHA},self.get,[dict(path='/v2/example',status=200,contains='wrong')])
        with self.assertRaises(ValueError):accept.verify(SHA,{'commit':SHA},lambda _: (200,{},b''),[])

class PrivilegeSplit(unittest.TestCase):
    def test_build_has_no_oidc_and_delivery_executes_no_application_code(self):
        text=(ROOT.parents[1]/'.github/workflows/ppb-v2-release-package.yml').read_text()
        build=text.split('  build:',1)[1].split('  deliver:',1)[0]
        deliver=text.split('  deliver:',1)[1].split('  acceptance-report:',1)[0]
        self.assertNotIn('id-token: write',build)
        self.assertNotIn('configure-aws-credentials',build)
        self.assertIn('id-token: write',deliver)
        self.assertNotIn('composer install',deliver)
        self.assertNotIn('php -r',deliver)
        self.assertNotIn('npm run',deliver)
        self.assertIn('release-client.py',deliver)
        self.assertIn('download-artifact',deliver)
