import importlib.util
import contextlib
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]/'interface'
def load(name):
    spec=importlib.util.spec_from_file_location('fixed_'+name,ROOT/(name+'.py'))
    m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m

class FixedInterface(unittest.TestCase):
    def test_request_whitelist_and_exact_equality(self):
        e=load('executor');r=dict(operation='journal',commit='a'*40,run_id='1',run_attempt='1')
        e.validate_request(r,r)
        for mutation in [dict(r,operation='create'),dict(r,shell='id'),dict(r,commit='$(id)'),dict(r,run_id='../x')]:
            with self.assertRaises(RuntimeError):e.validate_request(mutation,mutation)
        with self.assertRaises(RuntimeError):e.validate_request(dict(r,operation='resume'),r)

    def test_document_has_no_runtime_code_input(self):
        d=load('render').document()
        self.assertEqual(set(d['parameters']),{'Commit','RunId','RunAttempt','Operation'})
        self.assertEqual(d['parameters']['Operation']['allowedValues'],['journal','resume'])
        self.assertLess(len(json.dumps(d).encode()),64000)
        download=d['mainSteps'][1]['inputs']
        self.assertIn('request.json',download['sourceInfo'])
        self.assertNotIn('.sh',download['sourceInfo'])
        self.assertEqual(d,json.loads((ROOT/'document.json').read_text()))

    def test_missing_journal_never_runs_php_or_creates_state(self):
        e=load('executor')
        with tempfile.TemporaryDirectory() as tmp,patch.object(e,'JOURNAL',Path(tmp)/'absent'),patch.object(e.subprocess,'run') as run:
            with self.assertRaises(FileNotFoundError):e.receipt(Path(tmp))
            run.assert_not_called();self.assertFalse(e.JOURNAL.exists())

    def test_client_old_document_stops_before_upload_or_send(self):
        c=load('client');calls=[]
        def aws(*args):
            calls.append(args)
            if args[:2]==('sts','get-caller-identity'):return {'Account':c.r.ACCOUNT}
            if args[:2]==('ssm','describe-document'):return {'Document':{'DocumentVersion':'1','Hash':'a'*64}}
            return {'Content':'{}'}
        with tempfile.TemporaryDirectory() as tmp:
            r=c.r.initial('a'*40,'1','1')
            with self.assertRaisesRegex(c.r.Stop,'FIXED_INTERFACE_NOT_INSTALLED'):c.invoke('journal',r,Path(tmp)/'r.json',aws)
        self.assertFalse(any(a[0] in ['s3api'] or a[:2]==('ssm','send-command') for a in calls))

    def test_repeat_invocations_only_upload_data_and_pin_document(self):
        c=load('client');d=json.loads((ROOT/'document.json').read_text());sends=[];bodies=[]
        for runid in ['10','11']:
            req=dict(operation='journal',commit='a'*40,run_id=runid,run_attempt='1')
            def aws(*args):
                if args[:2]==('sts','get-caller-identity'):return {'Account':c.r.ACCOUNT}
                if args[:2]==('ssm','describe-document'):return {'Document':{'DocumentVersion':'3','Hash':'b'*64}}
                if args[:2]==('ssm','get-document'):return {'Content':json.dumps(d)}
                if args[:2]==('s3api','put-object'):
                    bodies.append(json.loads(Path(args[args.index('--body')+1]).read_text()));return {}
                if args[:2]==('ssm','send-command'):
                    sends.append(args);return {'Command':{'CommandId':'b7e8486b-8099-433a-95c0-bb59ab75abd6'}}
                result=dict(schema_version=1,execution=req,result='READ_ONLY_VERIFIED',journal=dict(ok=True,wp_write_requests=0))
                return {'CommandInvocations':[dict(CommandId='b7e8486b-8099-433a-95c0-bb59ab75abd6',InstanceId=c.r.INSTANCE,DocumentName=c.r.DOCUMENT,Status='Success',CommandPlugins=[dict(Name='fixedWpInterface',ResponseCode=0,Output='PPB_WP_INTERFACE_V1='+json.dumps(result))])]}
            with tempfile.TemporaryDirectory() as tmp:
                r=c.r.initial('a'*40,runid,'1')
                with contextlib.redirect_stdout(io.StringIO()):c.invoke('journal',r,Path(tmp)/'r.json',aws)
                self.assertEqual(r['result'],'READ_ONLY_VERIFIED')
        self.assertEqual(len(sends),2);self.assertEqual(len(bodies),2)
        for b in bodies:self.assertEqual(set(b),{'operation','commit','run_id','run_attempt'})
        for s in sends:
            self.assertEqual(s[s.index('--document-version')+1],'3')
            self.assertEqual(s[s.index('--document-hash')+1],'b'*64)

    def test_promotion_cannot_run_on_development_branch(self):
        p=load('promote')
        with patch.dict(os.environ,GITHUB_REF='refs/heads/codex/issue-12-wp-draft',GITHUB_EVENT_NAME='workflow_dispatch'):
            with self.assertRaisesRegex(p.r.Stop,'PROTECTED_MAIN_REQUIRED'):p.promote(lambda *a:self.fail('AWS called'))

    def test_promotion_role_cannot_send_commands_or_modify_iam(self):
        p=json.loads((ROOT/'promotion-policy.json').read_text())
        self.assertEqual(p['Statement'][0]['Resource'],'arn:aws:ssm:us-east-1:429724121147:document/PPB-V2-WP-Draft-Test')
        self.assertEqual(set(p['Statement'][0]['Action']),{'ssm:GetDocument','ssm:DescribeDocument','ssm:UpdateDocument','ssm:UpdateDocumentDefaultVersion'})

    def test_real_php_readonly_inspector_emits_receipt_without_changing_journal(self):
        import shutil
        import subprocess
        php=shutil.which('php')
        if not php:self.skipTest('PHP is required by CI')
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            j=dict(create_http=201,author=42,phase='CREATE_INTENT')
            path=root/'journal.json';path.write_text(json.dumps(j));before=path.read_bytes()
            # Network-free PHP transport fixture. Runs the actual installed inspector,
            # requiring GET and strict draft markers; any write call fails the test.
            stub="""<?php
const JOURNAL_DIR=__DIR__;const SLUG='ppb-codex-issue12-new-expert-v1';
const TITLE='Codex測試 2026-11-13';const MARKER='PPB-CODEX-ISSUE12-NEW-EXPERT-V1';
function stop($x){throw new Exception($x);}
function sourceDef(){return ['url'=>'https://fixture.invalid','user'=>'fixture','password'=>'fixture'];}
function wpCall($ctx,$path,$method){if($method!=='GET')throw new Exception('WRITE_DENIED');return ['status'=>200,'network_error'=>false,'json'=>['id'=>42]];}
function lookup($ctx){return [['id'=>40001,'type'=>'expert','status'=>'draft','slug'=>SLUG,'title'=>['raw'=>TITLE],'content'=>['raw'=>'<!-- '.MARKER.' -->']]];}
"""
            (root/'run.php').write_text(stub)
            p=subprocess.run([php,str(ROOT/'inspect.php'),str(root)],capture_output=True,text=True)
            self.assertEqual(p.returncode,0,p.stderr)
            result=json.loads(p.stdout.split('PPB_JOURNAL_RECEIPT_V1=',1)[1])
            self.assertEqual(result['candidate_expert_id'],40001)
            self.assertEqual(result['wp_write_requests'],0)
            self.assertEqual(path.read_bytes(),before)


    def test_artifact_result_files_have_the_same_current_execution(self):
        c=load('client')
        def invoke(operation,result,path):
            result.update(result='READ_ONLY_VERIFIED',stage='VERIFIED',stop_code=None,ssm_command_id='fixture-command')
        import sys
        with tempfile.TemporaryDirectory() as tmp, patch.dict(os.environ,GITHUB_SHA='a'*40,GITHUB_RUN_ID='22',GITHUB_RUN_ATTEMPT='1'), patch.object(sys,'argv',['client.py','journal']), patch.object(c,'prior',return_value=[]), patch.object(c,'invoke',side_effect=invoke):
            previous=os.getcwd()
            try:
                os.chdir(tmp)
                with contextlib.redirect_stdout(io.StringIO()):self.assertEqual(c.main(),0)
                a=json.loads(Path('wp-result/interface-result.json').read_text())
                b=json.loads(Path('wp-result/remote-result.json').read_text())
                self.assertEqual(a,b)
                self.assertEqual(b['result'],'READ_ONLY_VERIFIED')
            finally:os.chdir(previous)
