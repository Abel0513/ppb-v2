import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
ROOT=Path(__file__).parents[1]
def module(name,file):
    s=importlib.util.spec_from_file_location(name,ROOT/file); m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
c=module('control','control.py'); a=module('acceptance','accept-v2.py'); collect=module('collector','collect-acceptance.py')
SHA='a'*40
class Paths(unittest.TestCase):
    def test_allowed(self):
        for p in ['app/Models/PpbGame.php','tests/Feature/NewTest.php','database/migrations/change.php','docs/feature.md','composer.json','public/app.css','config/app.php']:
            self.assertTrue(c.allowed(p))
    def test_protected(self):
        for p in ['.github/workflows/a.yml','AGENTS.md','ops/deploy/a.py','../app/a','/app/a','app/../a','.env','app/.env','app/line.php','app/key.pem','Application/Legacy/a.php','storage/logs/a']:
            self.assertFalse(c.allowed(p),p)
    def test_modes(self):
        with patch.object(c.subprocess,'check_output',return_value=b'app/a.php\0'),patch.object(c,'git',return_value=':100644 120000 123 456 M\tapp/a.php'):
            with self.assertRaisesRegex(RuntimeError,'UNSAFE_FILE_MODE'): c.changed_paths(SHA)
class Trust(unittest.TestCase):
    def event(self): return {'repository':{'full_name':c.REPO},'action':'labeled','label':{'name':'codex-run'},'sender':{'login':'writer'},'issue':{'number':15}}
    def test_unknown_label_actor(self):
        with patch.object(c,'trusted',return_value=False):
            with self.assertRaisesRegex(RuntimeError,'UNTRUSTED_LABEL_ACTOR'): c.prepare(self.event(),'issues')
    def test_wrong_repository(self):
        e=self.event();e['repository']['full_name']='evil/repo'
        with self.assertRaisesRegex(RuntimeError,'WRONG_REPOSITORY'): c.prepare(e,'issues')
    def test_wrong_reviewer(self):
        e={'repository':{'full_name':c.REPO},'action':'submitted','review':{'user':{'login':'attacker'}}}
        with self.assertRaisesRegex(RuntimeError,'UNTRUSTED_REVIEWER'): c.prepare(e,'pull_request_review')
    def repair(self,reservations,head=SHA):
        e={'repository':{'full_name':c.REPO},'action':'submitted','review':{'user':{'login':c.REVIEW_BOT},'commit_id':SHA,'body':'[P1] Fix bug'},'pull_request':{'number':16}}
        pr={'number':16,'head':{'ref':'codex/issue-15','sha':head,'repo':{'full_name':c.REPO}},'base':{'ref':'main'},'state':'open'}
        with patch.object(c,'api',return_value=pr) as api,patch.object(c,'pages',side_effect=[[],reservations]):
            with self.assertRaises(RuntimeError) as error:c.prepare(e,'pull_request_review')
            return str(error.exception),api
    def test_budget_exhausted(self):
        rows=[{'user':{'login':'github-actions[bot]'},'body':'<!-- ppb-repair:x -->'}]*2
        err,api=self.repair(rows);self.assertIn('REPAIR_LIMIT_2',err);self.assertEqual(api.call_args[0][1],'POST')
    def test_same_head_not_repeated(self):
        err,_=self.repair([{'user':{'login':'github-actions[bot]'},'body':f'<!-- ppb-repair:{SHA} -->'}]);self.assertIn('HEAD_ALREADY',err)
    def test_stale_review(self):
        err,_=self.repair([],head='b'*40);self.assertIn('STALE_HEAD',err)
    def test_pr13_excluded(self):
        self.assertIsNone(c.BRANCH.fullmatch('codex/issue-12-wp-draft'))
class Deploy(unittest.TestCase):
    def event(self):return {'repository':{'full_name':c.REPO},'action':'closed','pull_request':{'merged':True,'base':{'ref':'main'},'merged_by':{'login':'owner','type':'User'},'merge_commit_sha':SHA,'number':15,'head':{'sha':SHA}}}
    def test_bot_merge_denied(self):
        e=self.event();e['pull_request']['merged_by']['type']='Bot'
        with self.assertRaisesRegex(RuntimeError,'NOT_HUMAN'): c.deploy_gate(e)
    def test_db_changes_require_manual(self):
        with patch.object(c,'trusted',return_value=True),patch.object(c,'api',return_value={'object':{'sha':SHA}}),patch.object(c,'pages',return_value=[{'filename':'database/migrations/a.php'}]):
            with self.assertRaisesRegex(RuntimeError,'DB_OR_DEPENDENCY'): c.deploy_gate(self.event())
    def test_workflow_only_no_deploy(self):
        with patch.object(c,'trusted',return_value=True),patch.object(c,'api',return_value={'object':{'sha':SHA}}),patch.object(c,'pages',return_value=[{'filename':'.github/workflows/a.yml'}]):
            with self.assertRaisesRegex(RuntimeError,'NO_APPLICATION_CHANGE'): c.deploy_gate(self.event())
    def test_checks_required(self):
        with patch.object(c,'trusted',return_value=True),patch.object(c,'api',side_effect=[{'object':{'sha':SHA}},{'check_runs':[]}]),patch.object(c,'pages',return_value=[{'filename':'app/a.php'}]):
            with self.assertRaisesRegex(RuntimeError,'REQUIRED_CI'):c.deploy_gate(self.event())
class Acceptance(unittest.TestCase):
    def get(self,path):
        if path=='/admin/login':return 200,{},b'<input name="password"><input name="username">'
        return 302,{'location':'https://v2.csdatw.xyz/admin/login'},b''
    def test_real_boundary_contract(self):
        v=a.verify(SHA,{'commit':SHA},self.get);self.assertEqual(v['CHECKS'],4);self.assertEqual(v['BUSINESS_WP_DB_LIFF_ACCEPTANCE'],'NOT_RUN')
    def test_wrong_release(self):
        with self.assertRaises(ValueError):a.verify(SHA,{'commit':'b'*40},self.get)
    def test_public_admin_rejected(self):
        with self.assertRaises(ValueError):a.verify(SHA,{'commit':SHA},lambda _: (200,{},b''))
    def test_collector_pending_then_success(self):
        v={'Status':'Success','ResponseCode':0,'StandardOutputContent':json.dumps(a.verify(SHA,{'commit':SHA},self.get))}
        with patch('time.sleep') as sleep:
            self.assertEqual(collect.collect('id',SHA,lambda _:v,sleep)['CHECKS'],4)
    def test_collector_does_not_call_failure_success(self):
        with self.assertRaises(RuntimeError):collect.collect('id',SHA,lambda _: {'Status':'Failed','ResponseCode':1},lambda _:None)
    def test_collector_timeout(self):
        with self.assertRaises(RuntimeError):collect.collect('id',SHA,lambda _: {'Status':'Pending'},lambda _:None)
class Documents(unittest.TestCase):
    def test_ssm_hash_and_shell(self):
        import hashlib
        d=json.loads((ROOT.parents[0]/'deploy/PPB-V2-Controlled-Deploy.isolated.ssm.json').read_text())
        text='\n'.join('\n'.join(s['inputs']['runCommand']) for s in d['mainSteps'] if 'runCommand' in s['inputs'])
        self.assertIn(hashlib.sha256((ROOT/'accept-v2.py').read_bytes()).hexdigest(),text)
        self.assertEqual(subprocess.run(['sh','-n'],input=text,text=True).returncode,0)
    def test_workflow_privilege_split(self):
        w=(ROOT.parents[1]/'.github/workflows/ppb-codex-automation.yml').read_text()
        develop=w.split('  develop:')[1].split('  publish:')[0]
        self.assertNotIn('CODEX_GITHUB_TOKEN',develop);self.assertNotIn('id-token: write',develop)
        self.assertIn('ppb-codex-subscription',develop)
        self.assertIn('forced_login_method=chatgpt',develop)
        self.assertIn('--sandbox workspace-write',develop)
        self.assertIn('API_KEY_AUTH_DISALLOWED',develop)
        self.assertNotIn('danger-full-access',w)
        self.assertNotIn('pull_request_target',w)
class Lifecycle(unittest.TestCase):
    def test_issue_creates_bounded_prompt_and_metadata(self):
        event={'repository':{'full_name':c.REPO},'action':'labeled','label':{'name':'codex-run'},'sender':{'login':'owner'},'issue':{'number':15}}
        issue={'number':15,'state':'open','title':'Task','body':'data; $(not shell)'}
        with tempfile.TemporaryDirectory() as t:
            old=os.getcwd()
            try:
                os.chdir(t)
                with patch.dict(os.environ,{'GITHUB_OUTPUT':t+'/output','GITHUB_RUN_ID':'123'}),patch.object(c,'trusted',return_value=True),patch.object(c,'pages',side_effect=[[],[]]),patch.object(c,'api',side_effect=[issue,{}, {'object':{'sha':SHA}}]):
                    c.prepare(event,'issues')
                meta=json.loads(Path('task.json').read_text());self.assertEqual(meta['base'],SHA);self.assertEqual(meta['branch'],'codex/issue-15')
                self.assertIn('$(not shell)',Path('prompt.txt').read_text())
            finally:os.chdir(old)
    def test_existing_issue_pr_not_recreated(self):
        event={'repository':{'full_name':c.REPO},'action':'labeled','label':{'name':'codex-run'},'sender':{'login':'owner'},'issue':{'number':15}}
        with patch.object(c,'trusted',return_value=True),patch.object(c,'api',return_value={'number':15,'state':'open'}),patch.object(c,'pages',return_value=[{'number':16}]):
            with self.assertRaisesRegex(RuntimeError,'TASK_ALREADY_HAS_PR'):c.prepare(event,'issues')
    def test_publish_rejects_moved_base_before_patch(self):
        with patch.object(c,'api',return_value={'object':{'sha':'b'*40}}):
            with self.assertRaisesRegex(RuntimeError,'BASE_MOVED'):c.publish({'branch':'codex/issue-15','base':SHA,'mode':'develop'},'no-file')
    def test_ci_from_fork_rejected(self):
        event={'repository':{'full_name':c.REPO},'workflow_run':{'name':'PPB automation tests','event':'pull_request','conclusion':'failure','head_repository':{'full_name':'evil/fork'}}}
        with self.assertRaisesRegex(RuntimeError,'NOT_ACTIONABLE_CI'):c.prepare(event,'workflow_run')
    def test_collector_wrong_commit(self):
        response={'Status':'Success','ResponseCode':0,'StandardOutputContent':json.dumps({'RESULT':'PPB_V2_FUNCTIONAL_BASELINE_PASS','COMMIT':'b'*40,'CHECKS':4})}
        with self.assertRaisesRegex(RuntimeError,'INVALID_FUNCTIONAL_EVIDENCE'):collect.collect('id',SHA,lambda _:response,lambda _:None)
    def test_real_git_patch_guard(self):
        with tempfile.TemporaryDirectory() as t:
            old=os.getcwd()
            try:
                os.chdir(t)
                subprocess.run(['git','init','-q'],check=True)
                subprocess.run(['git','config','user.email','offline@example.invalid'],check=True)
                subprocess.run(['git','config','user.name','offline'],check=True)
                Path('app').mkdir();Path('app/a.php').write_text('<?php\n')
                subprocess.run(['git','add','.'],check=True);subprocess.run(['git','commit','-qm','base'],check=True)
                base=c.git('rev-parse','HEAD')
                Path('app/a.php').write_text('<?php // change\n')
                self.assertEqual(c.changed_paths(base),['app/a.php'])
                Path('.github').mkdir();Path('.github/a').write_text('blocked')
                subprocess.run(['git','add','.'],check=True)
                with self.assertRaisesRegex(RuntimeError,'PROTECTED_PATH'):c.changed_paths(base)
            finally:os.chdir(old)
    def test_native_codex_badge_enters_repair(self):
        event={'repository':{'full_name':c.REPO},'action':'submitted','review':{'user':{'login':c.REVIEW_BOT},'commit_id':SHA,'body':'Summary'},'pull_request':{'number':16}}
        pr={'number':16,'head':{'ref':'codex/issue-15','sha':SHA,'repo':{'full_name':c.REPO}},'base':{'ref':'main'},'state':'open'}
        finding={'user':{'login':c.REVIEW_BOT},'commit_id':SHA,'body':'**![P1 Badge](https://img.shields.io/badge/P1-orange) Fix this**'}
        with patch.object(c,'api',return_value=pr),patch.object(c,'pages',side_effect=[[finding],[]]),patch.object(c,'output') as out,patch.object(c.Path,'write_text'):
            c.prepare(event,'pull_request_review')
            self.assertEqual(out.call_args[0][0]['mode'],'repair')
