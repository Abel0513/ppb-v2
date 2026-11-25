"""Exercise the actual SSM shell verifier against swapped branch packages."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from test_remote import a, ROOT

class ApprovedPackageTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.path=Path(self.tmp.name)
        self.doc=json.loads((ROOT/'PPB-V2-WP-Draft-Test.ssm.json').read_text())
        self.approved=hashlib.sha256((ROOT/'ppb_wpn2_verified.sh').read_bytes()).hexdigest()
    def verify(self,body,requested_sha):
        package=self.path/'run-12345-attempt-1.sh';package.write_bytes(body)
        commands=self.doc['mainSteps'][-1]['inputs']['runCommand']
        # Run the actual verification prefix only; neither the WP program nor
        # any root/production filesystem path is executed in this offline test.
        end=next(i for i,line in enumerate(commands) if line.startswith('export PPB_WP_GITHUB_COMMIT='))
        script='\n'.join(commands[:end])
        script=script.replace('download="/www/ppb-v2-deploy/wp-test/ssm-incoming-${SSM_RunId}-${SSM_RunAttempt}"', 'download="'+str(self.path)+'"')
        env=dict(os.environ,SSM_Commit='a'*40,SSM_PackageSha256=requested_sha,SSM_RunId='12345',SSM_RunAttempt='1',SSM_Approval='CREATE-ONE-EXPERT-DRAFT')
        return subprocess.run(['sh','-c',script],env=env,capture_output=True,text=True)
    def test_ssm_service_parameter_accepts_only_admin_pinned_digest(self):
        parameter=self.doc['parameters']['PackageSha256']
        self.assertEqual(parameter['allowedValues'],[self.approved])
        self.assertNotIn('allowedPattern',parameter)
    def test_branch_cannot_substitute_body_and_its_own_sha(self):
        body=b'echo UNAUTHORIZED_ROOT_PROGRAM\n'
        self.assertNotEqual(self.verify(body,hashlib.sha256(body).hexdigest()).returncode,0)
    def test_claiming_approved_sha_cannot_execute_swapped_body(self):
        self.assertNotEqual(self.verify(b'echo UNAUTHORIZED_ROOT_PROGRAM\n',self.approved).returncode,0)
    def test_reviewed_bytes_pass_verification_without_running_package(self):
        self.assertEqual(self.verify((ROOT/'ppb_wpn2_verified.sh').read_bytes(),self.approved).returncode,0)
    def test_old_unrestricted_document_versions_retired_before_role_enabled(self):
        from test_remote import SetupTests
        base=SetupTests();base.setUp();versions=['1','2'];calls=[]
        def aws(*args):
            calls.append(args)
            if args[:2]==('ssm','list-document-versions'):return {'DocumentVersions':[{'DocumentVersion':v} for v in versions]}
            if args[:2]==('ssm','delete-document'):
                versions.remove(args[args.index('--document-version')+1]);return {}
            return base.aws(*args)
        a.setup(True,aws)
        deleted=next(i for i,c in enumerate(calls) if c[:2]==('ssm','delete-document'))
        enabled=max(i for i,c in enumerate(calls) if c[:2]==('iam','put-role-policy') and c[c.index('--role-name')+1]==a.ROLE_NAME)
        self.assertLess(deleted,enabled)
        self.assertEqual(versions,['1'])
        policy=json.loads(calls[enabled][-1]);self.assertTrue(any(s['Effect']=='Allow' for s in policy['Statement']))
    def test_cleanup_failure_never_restores_send_command_permission(self):
        from test_remote import SetupTests
        base=SetupTests();base.setUp();policies=[]
        def aws(*args):
            if args[:2]==('iam','put-role-policy') and args[args.index('--role-name')+1]==a.ROLE_NAME:policies.append(json.loads(args[-1]))
            if args[:2]==('ssm','list-document-versions'):return {'DocumentVersions':[{'DocumentVersion':'1'},{'DocumentVersion':'2'}]}
            if args[:2]==('ssm','delete-document'):raise a.Stop('AWS_AccessDeniedException')
            return base.aws(*args)
        with self.assertRaises(a.Stop):a.setup(True,aws)
        self.assertEqual(len(policies),1)
        self.assertEqual(policies[0]['Statement'],[{'Effect':'Deny','Action':'ssm:SendCommand','Resource':'*'}])
    def test_existing_role_is_quarantined_before_new_trust_or_document_update(self):
        from test_remote import SetupTests
        base=SetupTests();base.setUp();calls=[];versions=['1']
        def aws(*args):
            calls.append(args)
            if args[:2]==('iam','get-role'):return {'Role':{'RoleName':a.ROLE_NAME}}
            if args[:2]==('iam','list-attached-role-policies'):return {'AttachedPolicies':[]}
            if args[:2]==('iam','list-role-policies'):return {'PolicyNames':['PPB-WP-Draft-Runtime']}
            if args[:2]==('iam','update-assume-role-policy'):return {}
            if args[:2]==('ssm','describe-document'):return {'Document':{'DocumentVersion':'1'}}
            if args[:2]==('ssm','list-document-versions'):return {'DocumentVersions':[{'DocumentVersion':v} for v in versions]}
            if args[:2]==('ssm','get-document'):
                if args[args.index('--document-version')+1]=='$LATEST':return {'DocumentVersion':'1','Content':'{}'}
                return {'Content':(ROOT/'PPB-V2-WP-Draft-Test.ssm.json').read_text()}
            if args[:2]==('ssm','update-document'):versions.append('2');return {'DocumentDescription':{'DocumentVersion':'2'}}
            if args[:2]==('ssm','delete-document'):versions.remove('1');return {}
            return base.aws(*args)
        a.setup(True,aws)
        writes=[c for c in calls if c[1].startswith(('put','update','delete','create'))]
        self.assertEqual(writes[0][:2],('iam','put-role-policy'))
        self.assertEqual(json.loads(writes[0][-1])['Statement'][0]['Effect'],'Deny')
        self.assertEqual(versions,['2'])
        self.assertEqual(json.loads(writes[-1][-1])['Statement'][0]['Effect'],'Allow')
