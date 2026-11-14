import importlib.util,json,os,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('document_authority',ROOT/'document-authority.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)

class DocumentAuthority(unittest.TestCase):
 def fake(self):
  calls=[];objects={}
  def call(*args):
   calls.append(args);op=args[:2]
   if op==('sts','get-caller-identity'):return {'Account':m.c.ACCOUNT,'Arn':f'arn:aws:sts::{m.c.ACCOUNT}:assumed-role/{m.ROLE}/test'}
   if op==('ssm','list-document-versions'):return {'DocumentVersions':[{'DocumentVersion':'60','IsDefaultVersion':True}]}
   if op==('ssm','get-document'):return {'DocumentVersion':'60','Content':'{"old":true}'}
   if op==('ssm','describe-document'):return {'Document':{'Hash':'a'*64,'HashType':'Sha256'}}
   if op==('s3api','put-object'):
    objects[args[args.index('--key')+1]]=Path(args[args.index('--body')+1]).read_bytes();return {}
   if op==('s3api','get-object'):
    Path(args[-1]).write_bytes(objects[args[args.index('--key')+1]]);return {}
   if args[0]=='iam':raise RuntimeError('AWS_AccessDenied')
   raise AssertionError('Unexpected authority or mutation: '+str(op))
  return calls,call
 def test_read_denial_retains_actual_document_inventory_without_writes(self):
  calls,call=self.fake();r=m.run(call)
  self.assertTrue(r['version_scan_complete']);self.assertEqual(r['versions'][0]['version'],'60');self.assertEqual(r['versions'][0]['matches'],[])
  self.assertEqual(r['reads']['own_role']['status'],'AWS_AccessDenied');self.assertEqual(r['ec2_commands'],0)
  self.assertTrue(all(a[1].startswith(('get-','list-','describe-','simulate-')) for a in calls));self.assertFalse(r['candidates'])
 def test_only_reviewed_document_data_is_published_and_read_back(self):
  calls,call=self.fake()
  with patch.dict(os.environ,{'GITHUB_SHA':'f'*40}):r=m.run(call,publish=True)
  self.assertEqual(len(r['candidates']),2);self.assertTrue(all(x['status']=='VERIFIED_DATA_ONLY' for x in r['candidates']))
  self.assertEqual([x['kind'] for x in r['candidates']],['bootstrap','isolated'])
  for args in calls:
   self.assertNotIn(args[:2],[('ssm','update-document'),('ssm','send-command'),('iam','put-role-policy')])
   if args[:2]==('s3api','put-object'):self.assertIn('--if-none-match',args);self.assertIn('AES256',args)
 def test_wrong_identity_stops_before_any_aws_resource_read(self):
  with self.assertRaisesRegex(RuntimeError,'WRONG_AUDIT_IDENTITY'):m.run(lambda *a:{'Account':'000000000000','Arn':'foreign'})
