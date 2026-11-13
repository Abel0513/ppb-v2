import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
import remote as r
spec = importlib.util.spec_from_file_location('envelope', ROOT / 'result-envelope.py')
e = importlib.util.module_from_spec(spec); spec.loader.exec_module(e)
spec = importlib.util.spec_from_file_location('setup', ROOT / 'admin-setup.py')
a = importlib.util.module_from_spec(spec); spec.loader.exec_module(a)
from importlib.machinery import SourceFileLoader
render = SourceFileLoader('render_ssm', str(ROOT / 'render-ssm.py')).load_module()

COMMIT = 'a' * 40
COMMAND = '12345678-1234-1234-1234-123456789012'
EXECUTION = dict(commit=COMMIT, run_id='12345', run_attempt='1')

def envelope():
    return dict(schema_version=1, execution=EXECUTION, result='PASS', wp_expert_id=40001,
                wp_status='draft', post_type='expert', acf_matched=43, media_verified_image_count=6, readback_http=200,
                create_http=201, recommendations={k: 'Codex測試' for k in e.REC_KEYS},
                wp_edit_url='https://csdatw.com/wp-admin/post.php?post=40001&action=edit')

class AWS:
    def __init__(self):
        self.calls=[]; self.envelope=envelope(); self.status='Success'; self.rc=0; self.fail=None; self.doc=None
    def __call__(self,*args):
        self.calls.append(args)
        if self.fail==args[:2]:raise r.Stop('AWS_AccessDeniedException')
        if args[:2]==('sts','get-caller-identity'):return dict(Account=r.ACCOUNT)
        if args[:2]==('ssm','describe-document'):return {'Document':dict(DocumentVersion='2',Hash='f'*64,HashType='Sha256')}
        if args[:2]==('ssm','get-document'):return dict(Content=json.dumps(self.doc or json.loads((ROOT/'PPB-V2-WP-Draft-Test.ssm.json').read_text())))
        if args[:2]==('s3api','put-object'):return {}
        if args[:2]==('ssm','send-command'):return {'Command':{'CommandId':COMMAND}}
        if args[:2]==('ssm','list-command-invocations'):
            return dict(CommandInvocations=[dict(CommandId=COMMAND,InstanceId=r.INSTANCE,Status=self.status,
                CommandPlugins=[dict(Name='expertDraftTest',ResponseCode=self.rc,
                    Output=r.PREFIX+json.dumps(self.envelope))])])
        raise AssertionError(args)

class RemoteTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.path=Path(self.tmp.name)/'result.json';self.result=r.initial(COMMIT,'12345','1');self.aws=AWS()
    def go(self,**kwargs):r.execute(self.result,self.path,call=self.aws,**kwargs)
    def test_full_remote_orchestration_correlates_all_ids(self):
        self.go();self.assertEqual(self.result['result'],'PASS');self.assertEqual(self.result['ssm_command_id'],COMMAND)
        self.assertEqual(self.result['wp_expert_id'],40001)
        send=[c for c in self.aws.calls if c[:2]==('ssm','send-command')];self.assertEqual(len(send),1)
        self.assertIn('--document-version',send[0]);self.assertIn('--document-hash',send[0])
        self.assertIn('--if-none-match',next(c for c in self.aws.calls if c[:2]==('s3api','put-object')))
    def test_document_drift_stops_before_upload_or_send(self):
        self.aws.doc={'bad':True}
        with self.assertRaisesRegex(r.Stop,'DOCUMENT_DIFFERS'):self.go()
        self.assertFalse(any(c[0]=='s3api' or c[:2]==('ssm','send-command') for c in self.aws.calls))
    def test_access_denied_preserves_stage_without_command(self):
        self.aws.fail=('ssm','describe-document')
        with self.assertRaisesRegex(r.Stop,'AccessDenied'):self.go()
        self.assertEqual(json.loads(self.path.read_text())['stage'],'SSM_DOCUMENT')
        self.assertIsNone(self.result['ssm_command_id'])
    def test_wrong_account_has_no_side_effects(self):
        with self.assertRaisesRegex(r.Stop,'WRONG_AWS_ACCOUNT'):r.execute(self.result,self.path,call=lambda *a:{'Account':'1'})
    def test_stale_run_result_never_passes(self):
        self.aws.envelope['execution']=dict(EXECUTION,run_attempt='99')
        with self.assertRaisesRegex(r.Stop,'STALE_OR_FOREIGN'):self.go()
        self.assertEqual(self.result['result'],'STOP')
        self.assertEqual(self.result['ssm_command_id'],COMMAND)
    def test_30436_can_never_be_new_id(self):
        self.aws.envelope['wp_expert_id']=30436
        with self.assertRaisesRegex(r.Stop,'NEW_ID_INVALID'):self.go()
    def test_http_success_with_bad_six_recommendations_rejected(self):
        self.aws.envelope['recommendations'][e.REC_KEYS[0]]='wrong'
        with self.assertRaisesRegex(r.Stop,'SIX_RECOMMENDATIONS'):self.go()
    def test_public_status_rejected(self):
        self.aws.envelope['wp_status']='publish'
        with self.assertRaisesRegex(r.Stop,'NOT_EXPERT_DRAFT'):self.go()
    def test_missing_acf_count_rejected(self):
        self.aws.envelope['acf_matched']=42
        with self.assertRaisesRegex(r.Stop,'ACF_READBACK'):self.go()
    def test_unknown_ssm_outcome_never_resends(self):
        self.aws.status='InProgress';time=[0]
        def sleep(s):time[0]+=s
        with self.assertRaisesRegex(r.Stop,'OUTCOME_UNKNOWN'):self.go(timeout=10,clock=lambda:time[0],sleep=sleep)
        self.assertEqual(len([c for c in self.aws.calls if c[:2]==('ssm','send-command')]),1)
        self.assertEqual(json.loads(self.path.read_text())['ssm_command_id'],COMMAND)
    def test_remote_stop_envelope_is_preserved(self):
        self.aws.status='Failed';self.aws.rc=1
        self.aws.envelope=dict(schema_version=1,execution=EXECUTION,result='STOP',stop_code='WP_AUTH_FAILED',wp_expert_id=None)
        with self.assertRaisesRegex(r.Stop,'SSM_OR_WP'):self.go()
        self.assertEqual(self.result['remote_stop_code'],'WP_AUTH_FAILED')
    def test_send_error_is_not_retried(self):
        self.aws.fail=('ssm','send-command')
        with self.assertRaises(r.Stop):self.go()
        self.assertEqual(len([c for c in self.aws.calls if c[:2]==('ssm','send-command')]),1)
        self.assertEqual(json.loads(self.path.read_text())['stage'],'SSM_SEND_INTENT')
    def test_truncated_or_duplicate_output_rejected(self):
        for output in [r.PREFIX+'{',r.PREFIX+json.dumps(envelope())+'\n'+r.PREFIX+json.dumps(envelope())]:
            with self.assertRaises((r.Stop,ValueError)):r.validate_envelope(output,self.result)
    def test_extra_raw_remote_content_not_exported(self):
        self.aws.envelope['authorization']='fixture-secret'
        with self.assertRaisesRegex(r.Stop,'UNEXPECTED_KEYS'):self.go()
        self.assertNotIn('fixture-secret',self.path.read_text())

class CollectorTests(unittest.TestCase):
    def report(self):
        return dict(execution=copy.deepcopy(EXECUTION),result='PASS',wp_test_success=True,new_expert_id=40001,ACF_MATCHED=43,
            final_readback=dict(expected_keys=43,present_keys=43,all_43_values_match=True),
            recommendations={k:'Codex測試' for k in e.REC_KEYS},READBACK_HTTP=200,CREATE_HTTP=201,
            media_verified_image_count=6,analysts=[dict(id=i) for i in range(2,8)],
            edit_url='https://csdatw.com/wp-admin/post.php?post=40001&action=edit',password='must never export')
    def test_fixed_collector_omits_credentials_and_correlates(self):
        v=e.collect(self.report(),EXECUTION,0);self.assertEqual(v['result'],'PASS')
        self.assertNotIn('password',json.dumps(v));self.assertNotIn('must never export',json.dumps(v))
    def test_stale_report_after_early_package_failure_is_stop(self):
        report=self.report();report['execution']['run_id']='other'
        self.assertEqual(e.collect(report,dict(EXECUTION,run_id='12345'),2)['result'],'STOP')
    def test_failed_script_cannot_reuse_pass_report(self):
        self.assertEqual(e.collect(self.report(),EXECUTION,2)['result'],'STOP')
    def test_missing_media_or_43_evidence_rejected(self):
        for key,value in [('media_verified_image_count',5),('ACF_MATCHED',42),('wp_test_success',False)]:
            report=self.report();report[key]=value
            self.assertEqual(e.collect(report,EXECUTION,0)['result'],'STOP')

class InfrastructureTests(unittest.TestCase):
    def test_document_is_reproducible_and_fixed(self):
        doc=json.loads((ROOT/'PPB-V2-WP-Draft-Test.ssm.json').read_text())
        self.assertEqual(doc,render.document())
        self.assertEqual(set(doc['parameters']),{'Commit','PackageSha256','RunId','RunAttempt','Approval'})
        for step in doc['mainSteps']:
            if step['action']=='aws:runShellScript':
                subprocess.run(['sh','-n'],input='\n'.join(step['inputs']['runCommand']),text=True,check=True)
        run='\n'.join(doc['mainSteps'][-1]['inputs']['runCommand'])
        self.assertIn('sha256sum -c',run);self.assertIn('execution.log',run)
        self.assertNotIn('runner.py',run);self.assertNotIn('artisan',run)
        python=run.split("python3 - <<'PY_COLLECT'\n",1)[1].rsplit('\nPY_COLLECT',1)[0]
        compile(python,'collector','exec')
    def test_wp_role_cannot_use_deploy_or_arbitrary_shell(self):
        trust,runtime,read=a.policies();text=json.dumps(runtime)
        self.assertNotIn('Controlled-Deploy',text);self.assertNotIn('AWS-RunShellScript',text)
        self.assertNotIn('iam:',text);self.assertNotIn('CreateDocument',text)
        subjects=trust['Statement'][0]['Condition']['StringEquals']['token.actions.githubusercontent.com:sub']
        self.assertEqual(subjects,['repo:Abel0513@55256582/ppb-v2@1454285732:ref:refs/heads/codex/issue-12-wp-draft','repo:Abel0513@55256582/ppb-v2@1454285732:ref:refs/heads/main'])
        self.assertNotIn('pull_request',json.dumps(trust));self.assertNotIn('*',json.dumps(trust))
        self.assertEqual(read['Statement'][0]['Action'],['s3:GetObject'])
    def test_only_same_repo_branch_push_can_execute_automatically(self):
        workflow=(ROOT.parents[1]/'.github/workflows/ppb-v2-wp-draft-test.yml').read_text()
        for text in ["github.event_name == 'push'", "github.ref == 'refs/heads/codex/issue-12-wp-draft'", "github.repository_id == '1454285732'",'if: always()','actions/upload-artifact@v4']:
            self.assertIn(text,workflow)
        self.assertNotIn('pull_request_target:',workflow)
        self.assertNotIn('PPB-V2-GitHub-Deploy-Role',workflow)

class SetupTests(unittest.TestCase):
    def setUp(self):self.calls=[]
    def aws(self,*args):
        self.calls.append(args)
        if args[:2]==('sts','get-caller-identity'):return {'Account':r.ACCOUNT}
        if args[:2]==('iam','get-open-id-connect-provider'):return {'ClientIDList':['sts.amazonaws.com']}
        if args[:2]==('ec2','describe-instances'):return {'Reservations':[{'Instances':[dict(InstanceId=r.INSTANCE,IamInstanceProfile={'Arn':f'arn:aws:iam::{r.ACCOUNT}:instance-profile/existing-profile'})]}]}
        if args[:2]==('iam','get-instance-profile'):return {'InstanceProfile':{'Roles':[{'RoleName':'ExistingEc2Role','Arn':f'arn:aws:iam::{r.ACCOUNT}:role/ExistingEc2Role'}]}}
        if args[:2]==('iam','get-role'):raise r.Stop('AWS_NoSuchEntity')
        if args[:2]==('ssm','describe-document'):raise r.Stop('AWS_InvalidDocument')
        if args[:2]==('ssm','create-document'):return {'DocumentDescription':{'DocumentVersion':'1'}}
        if args[:2]==('ssm','list-document-versions'):return {'DocumentVersions':[{'DocumentVersion':'1'}]}
        if args[:2]==('ssm','get-document'):return {'DocumentVersion':'1','Content':(ROOT/'PPB-V2-WP-Draft-Test.ssm.json').read_text()}
        if args[:2] in [('iam','create-role'),('iam','put-role-policy'),('ssm','update-document-default-version')]:return {}
        raise AssertionError(args)
    def test_plan_discovers_real_attached_role_and_never_writes(self):
        result=a.setup(False,self.aws)
        self.assertEqual(result['instance_role_arn'],f'arn:aws:iam::{r.ACCOUNT}:role/ExistingEc2Role')
        self.assertFalse(any(c[1].startswith(('create','put','update','send')) for c in self.calls))
    def test_apply_reuses_provider_and_does_not_install_ec2_runner(self):
        result=a.setup(True,self.aws)
        self.assertEqual(result['document_version'],'1')
        writes=[c for c in self.calls if c[1].startswith(('create','put','update'))]
        self.assertEqual([c[:2] for c in writes],[('iam','create-role'),('iam','put-role-policy'),('iam','put-role-policy'),('ssm','create-document'),('ssm','update-document-default-version'),('iam','put-role-policy')])
        self.assertFalse(any(c[:2]==('ssm','send-command') for c in self.calls))
        self.assertNotIn('PPB-V2-GitHub-Deploy-Role',json.dumps(self.calls))
        self.assertFalse(any('create-open-id-connect-provider' in c for c in self.calls))
