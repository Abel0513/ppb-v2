"""Offline protocol simulation only; never an EC2 installation acceptance."""
import contextlib,hashlib,importlib.util,io,json,os,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('ui_source_manager_batch',ROOT/'manage-ui-source.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
WRITE_CALLS={
 ('s3api','put-object'),('ssm','update-document'),('ssm','send-command'),
 ('ssm','update-document-default-version'),('ssm','delete-document'),
}
FRESH_STATE_FAILURES=[
 ({'default':'61'},'FRESH_DEFAULT_MUST_BE_V62'),
 ({'default':'63'},'FRESH_DEFAULT_MUST_BE_V62'),
 ({'missing':'61'},'FRESH_PINNED_VERSION_MISSING'),
 ({'missing':'62'},'FRESH_PINNED_VERSION_MISSING'),
 ({'wrong_hash':'61'},'FRESH_PINNED_HASH_MISMATCH'),
 ({'wrong_hash':'62'},'FRESH_PINNED_HASH_MISMATCH'),
]
class ManagerBatch(unittest.TestCase):
 def setUp(self):
  capture=contextlib.redirect_stdout(io.StringIO());capture.__enter__();self.addCleanup(capture.__exit__,None,None,None)
 def fake(self,mode='ok'):
  s=importlib.util.spec_from_file_location('phase_a_archive',ROOT/'isolation/build-bootstrap.py');b=importlib.util.module_from_spec(s);s.loader.exec_module(b)
  with tempfile.TemporaryDirectory() as tmp:
   p=Path(tmp)/'tools.tar.gz';b.build(p);bundle=p.read_bytes()
  calls=[];versions={'61':{'old_fixture':61},'62':{'old_fixture':62}};state={'default':'62'};archives={}
  def call(*args):
   calls.append(args);op=args[:2]
   def arg(name):return args[args.index(name)+1]
   if op==('sts','get-caller-identity'):return {'Account':m.ACCOUNT,'Arn':f'arn:aws:sts::{m.ACCOUNT}:assumed-role/'+('PPB-V2-GitHub-Deploy-Role' if mode=='runtime' else 'ExistingManager')+'/test'}
   if op==('s3api','get-object'):
    if mode=='s3-denied':raise RuntimeError('AWS_AccessDenied')
    key=arg('--key')
    if key.startswith('releases/ssm-history/'):data=archives[key]
    else:data=bundle if key.endswith('fixed.tar.gz') else (ROOT/('PPB-V2-Controlled-Deploy.'+('bootstrap' if '/bootstrap-' in key else 'isolated')+'.ssm.json')).read_bytes()
    Path(args[-1]).write_bytes(b'TAMPERED' if mode=='tamper' else data);return {}
   if op==('ssm','list-document-versions'):return {'DocumentVersions':[{'DocumentVersion':v} for v in versions if v!=state.get('missing')]}
   if op==('ssm','get-document'):
    version=arg('--document-version')
    if version=='$DEFAULT':
     version=state['default'];content=versions.get(version,{'unreviewed_default':version})
    else:
     version=max(versions,key=int) if version=='$LATEST' else version;content=versions[version]
    return {'DocumentVersion':version,'Content':json.dumps(content)}
   if op==('ssm','describe-document'):
    version=arg('--document-version')
    sha=m.PREVIOUS.get(version) or hashlib.sha256(json.dumps(versions[version]).encode()).hexdigest()
    if version==state.get('wrong_hash'):sha='0'*64
    return {'Document':{'HashType':'Sha256','Hash':sha,'Status':'Active'}}
   if op==('ssm','update-document'):
    v=str(int(max(versions,key=int))+1);versions[v]=json.loads(Path(arg('--content').removeprefix('file://')).read_text());return {'DocumentDescription':{'DocumentVersion':v}}
   if op==('ssm','send-command'):
    if mode=='unknown':raise RuntimeError('AWS_NETWORK_OUTCOME_UNKNOWN')
    return {'Command':{'CommandId':'12345678-1234-1234-1234-123456789abc'}}
   if op==('ssm','get-command-invocation'):
    if state.get('receipt_failed'):return {'Status':'Failed','ResponseCode':1,'StandardOutputContent':''}
    output='V2_FIXED_TOOLS_INSTALLED=YES\nLEGACY_READER_INSTALL={"legacy_file_changes":0,"database_changes":0}\nV2_INVENTORY={"result":"PASS","isolation":{"legacy_database_write":false}}\n'
    if mode!='missing-r2':output+='V2_EXISTING_FPM_POLICY_PRESERVED=YES\n'
    return {'Status':'Success','ResponseCode':0,'StandardOutputContent':output}
   if op==('ssm','update-document-default-version'):state['default']=arg('--document-version');return {}
   if op==('s3api','put-object'):
    key=arg('--key')
    if key.startswith('releases/ssm-history/'):
     if key in archives:raise RuntimeError('AWS_PreconditionFailed')
     archives[key]=Path(arg('--body')).read_bytes()
    return {}
   if op==('ssm','delete-document'):del versions[arg('--document-version')];return {}
   raise AssertionError('Unexpected management action '+str(op))
  return calls,call,state
 def test_complete_reviewed_protocol_and_replay_only_read(self):
  calls,call,state=self.fake()
  with tempfile.TemporaryDirectory() as tmp:
   journal=str(Path(tmp)/'state.json');result=m.manage(True,journal,call)
   self.assertEqual(result['result'],'MANAGED_UI_SOURCE_INSTALL_VERIFIED');self.assertTrue(result['r2_protected_bytes_unchanged']);self.assertEqual(result['retired_versions'],['61','62'])
   self.assertEqual(state['default'],result['runtime_version']);self.assertNotEqual(state['default'],'62')
   self.assertTrue(m.PREVIOUS.keys().isdisjoint(v['DocumentVersion'] for v in call('ssm','list-document-versions','--name',m.DOC)['DocumentVersions']))
   before=len(calls);replay=m.manage(True,journal,call);self.assertTrue(replay['replayed']);self.assertEqual(replay['new_ec2_commands'],0)
   self.assertFalse(any(a[:2] in WRITE_CALLS for a in calls[before:]))
  self.assertEqual(sum(a[:2]==('ssm','send-command') for a in calls),1);self.assertFalse(any(a[0]=='iam' for a in calls))
 def test_replay_requires_a_fresh_successful_receipt(self):
  calls,call,state=self.fake()
  with tempfile.TemporaryDirectory() as tmp:
   path=str(Path(tmp)/'state.json');m.manage(True,path,call);state['receipt_failed']=True
   with self.assertRaisesRegex(RuntimeError,'RECEIPT_NOT_VERIFIED'):m.manage(True,path,call)
  self.assertEqual(sum(a[:2]==('ssm','send-command') for a in calls),1)
 def test_resume_after_default_switch_archive_and_first_deletion(self):
  for operation in [('ssm','update-document-default-version'),('s3api','put-object'),('ssm','delete-document')]:
   with self.subTest(operation=operation),tempfile.TemporaryDirectory() as tmp:
    calls,call,state=self.fake();journal=Path(tmp)/'state.json'
    def interrupt(*args):
     response=call(*args)
     if args[:2]==operation:raise SystemExit('Simulated termination after write')
     return response
    with self.assertRaises(SystemExit):m.manage(True,journal,interrupt)
    self.assertNotEqual(state['default'],'62')
    self.assertEqual(json.loads(journal.read_text())['phase'],'INTENT')
    before=len(calls);result=m.manage(True,journal,call)
    self.assertEqual(result['result'],'MANAGED_UI_SOURCE_INSTALL_VERIFIED')
    self.assertFalse(any(a[:2] in {('ssm','send-command'),('ssm','update-document-default-version')} for a in calls[before:]))
 def test_interrupted_process_preserves_command_and_resumes_without_resending(self):
  calls,call,_=self.fake();old=os.getcwd()
  with tempfile.TemporaryDirectory() as tmp:
   os.chdir(tmp)
   def interrupt(*args):
    if args[:2]==('ssm','get-command-invocation'):raise SystemExit('Simulated termination after SendCommand')
    return call(*args)
   try:
    with self.assertRaises(SystemExit):m.manage(True,'state.json',interrupt)
    state=json.loads(Path('state.json').read_text());self.assertTrue(state['command_id']);self.assertTrue(state['send_intent'])
    result=m.manage(True,'state.json',call);self.assertEqual(result['result'],'MANAGED_UI_SOURCE_INSTALL_VERIFIED')
   finally:os.chdir(old)
  self.assertEqual(sum(a[:2]==('ssm','send-command') for a in calls),1)
 def test_s3_tamper_and_runtime_identity_stop_before_management_writes(self):
  for mode in ['tamper','runtime']:
   calls,call,_=self.fake(mode)
   with tempfile.TemporaryDirectory() as tmp:
    with self.assertRaises(RuntimeError):m.manage(True,str(Path(tmp)/'state.json'),call)
   self.assertFalse(any(a[:2] in [('ssm','update-document'),('ssm','send-command')] for a in calls))
 def test_s3_failure_has_safe_exact_action_and_resource(self):
  calls,call,_=self.fake('s3-denied')
  with self.assertRaises(m.ManagementApiError) as caught:m.manage(True,call=call)
  self.assertEqual(caught.exception.action,'s3:GetObject');self.assertEqual(caught.exception.resource,'arn:aws:s3:::'+m.BUCKET+'/releases/bootstrap/'+m.BUNDLE+'/fixed.tar.gz')
  self.assertFalse(any(a[:2]==('ssm','update-document') for a in calls))
 def test_missing_r2_receipt_prevents_default_switch_and_retirement(self):
  calls,call,state=self.fake('missing-r2')
  with tempfile.TemporaryDirectory() as tmp:
   with self.assertRaisesRegex(RuntimeError,'BOOTSTRAP_NOT_VERIFIED'):m.manage(True,str(Path(tmp)/'state.json'),call)
  self.assertEqual(state['default'],'62');self.assertFalse(any(a[:2] in [('ssm','update-document-default-version'),('ssm','delete-document')] for a in calls))
 def test_unknown_send_outcome_never_resends(self):
  calls,call,_=self.fake('unknown')
  with tempfile.TemporaryDirectory() as tmp:
   path=str(Path(tmp)/'state.json')
   with self.assertRaisesRegex(RuntimeError,'NETWORK_OUTCOME_UNKNOWN'):m.manage(True,path,call)
   with self.assertRaisesRegex(RuntimeError,'OUTCOME_UNKNOWN_DO_NOT_RESEND'):m.manage(True,path,call)
  self.assertEqual(sum(a[:2]==('ssm','send-command') for a in calls),1)
 def test_dry_run_never_changes_documents_or_calls_ec2(self):
  calls,call,_=self.fake();result=m.manage(False,call=call);self.assertEqual(result['result'],'UI_SOURCE_MANAGEMENT_BATCH_DRY_RUN');self.assertEqual(result['default_version'],'62');self.assertEqual(result['ec2_commands'],0)
  self.assertFalse(any(a[:2] in [('ssm','update-document'),('ssm','send-command'),('ssm','delete-document')] for a in calls))
 def test_fresh_manage_stops_before_writes_on_wrong_live_versions(self):
  for live_state,reason in FRESH_STATE_FAILURES:
   if reason=='FRESH_PINNED_HASH_MISMATCH':reason='UNREVIEWED_CONCURRENT_DOCUMENT_VERSION'
   for apply in [False,True]:
    with self.subTest(live_state=live_state,apply=apply),tempfile.TemporaryDirectory() as tmp:
     calls,call,state=self.fake();state.update(live_state);journal=Path(tmp)/'state.json'
     with self.assertRaisesRegex(RuntimeError,f'^{reason}$'):m.manage(apply,journal,call)
     self.assertFalse(any(a[:2] in WRITE_CALLS for a in calls))
     self.assertFalse(journal.exists())
 def test_publish_data_stops_before_writes_on_wrong_live_versions(self):
  with tempfile.TemporaryDirectory() as tmp:
   bundle=Path(tmp)/'fixed.tar.gz'
   s=importlib.util.spec_from_file_location('ui_source_bundle',ROOT/'isolation/build-bootstrap.py');b=importlib.util.module_from_spec(s);s.loader.exec_module(b);b.build(bundle)
   for live_state,reason in FRESH_STATE_FAILURES:
    for apply in [False,True]:
     with self.subTest(live_state=live_state,apply=apply):
      calls,call,state=self.fake();state.update(live_state)
      with self.assertRaisesRegex(RuntimeError,f'^{reason}$'):m.publish_data(apply,bundle,call)
      self.assertFalse(any(a[:2] in WRITE_CALLS for a in calls))
 def test_publish_data_dry_run_and_apply_are_s3_only(self):
  calls,call,_=self.fake()
  with tempfile.TemporaryDirectory() as tmp:
   bundle=Path(tmp)/'fixed.tar.gz'
   s=importlib.util.spec_from_file_location('ui_source_bundle',ROOT/'isolation/build-bootstrap.py');b=importlib.util.module_from_spec(s);s.loader.exec_module(b);b.build(bundle)
   dry=m.publish_data(False,bundle,call);self.assertEqual(dry['result'],'UI_SOURCE_S3_PUBLICATION_DRY_RUN')
   self.assertEqual(dry['ec2_commands'],0);self.assertEqual(dry['document_writes'],0);self.assertEqual(dry['ssm_writes'],0)
   self.assertFalse(any(a[:2]==('s3api','put-object') for a in calls))
   published=m.publish_data(True,bundle,call);self.assertEqual(published['result'],'UI_SOURCE_S3_DATA_PUBLISHED')
   self.assertEqual(len(published['s3_verified']),3)
   self.assertTrue(all(a[:2] in {
    ('sts','get-caller-identity'),('s3api','put-object'),('s3api','get-object'),
    ('ssm','get-document'),('ssm','describe-document'),('ssm','list-document-versions'),
   } for a in calls))
 def test_publish_data_rejects_runtime_role_and_tampered_bundle(self):
  calls,call,_=self.fake('runtime')
  with tempfile.TemporaryDirectory() as tmp:
   bundle=Path(tmp)/'fixed.tar.gz';bundle.write_bytes(b'not-the-reviewed-bundle')
   with self.assertRaisesRegex(RuntimeError,'EXISTING_SSM_MANAGER_REQUIRED_NO_RUNTIME_RETRY'):m.publish_data(True,bundle,call)
  self.assertFalse(any(a[:2]==('s3api','put-object') for a in calls))
  calls,call,_=self.fake()
  with tempfile.TemporaryDirectory() as tmp:
   bundle=Path(tmp)/'fixed.tar.gz';bundle.write_bytes(b'not-the-reviewed-bundle')
   with self.assertRaisesRegex(RuntimeError,'BUNDLE_SHA_MISMATCH'):m.publish_data(True,bundle,call)
  self.assertFalse(any(a[:2]==('s3api','put-object') for a in calls))
