import importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('preload_docs',ROOT/'preload-reviewed-documents.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)

class PreloadReviewed(unittest.TestCase):
 def fake(self,tamper=False,runtime=False):
  calls=[];versions={'58':{'old':True}}
  def call(*args):
   calls.append(args);op=args[:2]
   if op==('sts','get-caller-identity'):return {'Account':m.ACCOUNT,'Arn':f'arn:aws:sts::{m.ACCOUNT}:assumed-role/'+('PPB-V2-GitHub-Deploy-Role' if runtime else 'ExistingSSMManager')+'/test'}
   if op==('ssm','get-document'):
    v=args[args.index('--document-version')+1];v='58' if v=='$DEFAULT' else v;return {'DocumentVersion':v,'Content':json.dumps(versions[v])}
   if op==('ssm','list-document-versions'):return {'DocumentVersions':[{'DocumentVersion':v} for v in versions]}
   if op==('s3api','get-object'):
    kind='bootstrap' if '/bootstrap-' in args[args.index('--key')+1] else 'isolated';data=(ROOT/f'PPB-V2-Controlled-Deploy.{kind}.ssm.json').read_bytes();Path(args[-1]).write_bytes(b'{}' if tamper else data);return {}
   if op==('ssm','update-document'):
    v=str(60+len(versions));versions[v]=json.loads(Path(args[-1].removeprefix('file://')).read_text());return {'DocumentDescription':{'DocumentVersion':v}}
   if op==('ssm','describe-document'):return {'Document':{'Status':'Active'}}
   raise AssertionError('Unexpected management action '+str(op))
  return calls,call
 def test_apply_only_preloads_exact_two_versions_and_replay_does_not_write(self):
  calls,call=self.fake();r=m.preload('f'*40,True,call)
  self.assertEqual(r['result'],'REVIEWED_DOCUMENTS_PRELOADED');self.assertEqual(len(r['created_versions']),2);self.assertTrue(r['default_unchanged']);self.assertFalse(r['tools_installed'])
  r=m.preload('f'*40,True,call);self.assertEqual(r['created_versions'],[]);self.assertEqual(len(r['reused_versions']),2)
  self.assertEqual(sum(a[:2]==('ssm','update-document') for a in calls),2)
  self.assertFalse(any(a[:2] in [('ssm','send-command'),('ssm','update-document-default-version'),('ssm','delete-document')] or a[0]=='iam' for a in calls))
 def test_default_dry_run_and_tampered_data_never_update(self):
  calls,call=self.fake();r=m.preload('f'*40,False,call);self.assertEqual(r['needed_publications'],['bootstrap','isolated']);self.assertFalse(any(a[:2]==('ssm','update-document') for a in calls))
  calls,call=self.fake(tamper=True)
  with self.assertRaisesRegex(RuntimeError,'SHA_MISMATCH'):m.preload('f'*40,True,call)
  self.assertFalse(any(a[:2]==('ssm','update-document') for a in calls))
 def test_daily_role_cannot_repeat_the_denied_management_attempt(self):
  calls,call=self.fake(runtime=True)
  with self.assertRaisesRegex(RuntimeError,'SSM_MANAGER_REQUIRED'):m.preload('f'*40,True,call)
  self.assertEqual(len(calls),1)
