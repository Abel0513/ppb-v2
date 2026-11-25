#!/usr/bin/env python3
"""One existing-manager batch, not a new runner or an IAM grant. Dry-run by default."""
import argparse,hashlib,importlib.util,json,os,re,tempfile,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parent
ACCOUNT='429724121147';DOC='PPB-V2-Controlled-Deploy';INSTANCE='i-0135d6b2fac7a54c2'
BUCKET='ppb-v2-release-artifacts-429724121147-use1';SOURCE='7b4a7e45585f98b5c6bf04ff44c328130ba3998a'
BUNDLE='babf57824db0046930ada81db9174be3c9340bba3c15c866d59913e0f5a7f0b8'
PINS={'activate-isolation.py':'6f3502f6f127f6dbf05e053170b96cf414c8461c8df1d9f6e604805d18977cba','release-client.py':'5a4335b2e151c0a52b54463844f83d6a7926594a780072803faf1f3d065a6edd','PPB-V2-Controlled-Deploy.bootstrap.ssm.json':'6c0879a9b672cfcb1496d533701fca4494cbc66fe2438c659bbb3962739244e4','PPB-V2-Controlled-Deploy.isolated.ssm.json':'f63b5aa86b017223e3df17381d615da6a88fc989e70a7b15429ed5c328094b4d'}
PREVIOUS={'61':'05d73f4e07f94748f11bcd291114cd3c296e7fde2f0ad04c90508bf456a48a3a','62':'92db95e386608ba55a1f4ba231df94c6af09b1eaaaf965886a4382c62f6fde24'}
def need(value,code):
 if not value:raise RuntimeError(code)
def digest(data):return hashlib.sha256(data).hexdigest()
class ManagementApiError(RuntimeError):
 def __init__(self,code,action,resource):super().__init__(code);self.action=action;self.resource=resource
def load_activation():
 for name,sha in PINS.items():need(digest((ROOT/name).read_bytes())==sha,'MANAGEMENT_PACKET_PIN_MISMATCH')
 s=importlib.util.spec_from_file_location('approved_ui_source_activation',ROOT/'activate-isolation.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
def save(path,value):
 path=Path(path);need(not path.is_symlink(),'MANAGEMENT_JOURNAL_UNSAFE');path.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
 need(not path.parent.is_symlink() and path.parent.stat().st_mode&0o022==0,'MANAGEMENT_JOURNAL_DIRECTORY_UNSAFE')
 fd,temp=tempfile.mkstemp(prefix='.ui-source-',dir=path.parent)
 with os.fdopen(fd,'w') as f:json.dump(value,f);f.flush();os.fsync(f.fileno())
 os.replace(temp,path)
def identity_guard(raw):
 identity=raw('sts','get-caller-identity');need(identity['Account']==ACCOUNT,'WRONG_ACCOUNT')
 need('/PPB-V2-GitHub-Deploy-Role/' not in identity['Arn'],'EXISTING_SSM_MANAGER_REQUIRED_NO_RUNTIME_RETRY')
 need('/PPB-V2-GitHub-WP-Draft-Test-Role/' not in identity['Arn'],'WP_ROLE_NOT_AUTHORIZED_FOR_V2_MANAGEMENT')
 return identity
def wrap_call(source_call):
 def raw(*args):
  try:return source_call(*args)
  except RuntimeError as exc:
   names={'get-caller-identity':'GetCallerIdentity','get-object':'GetObject','put-object':'PutObject','get-document':'GetDocument','describe-document':'DescribeDocument','list-document-versions':'ListDocumentVersions','get-command-invocation':'GetCommandInvocation','update-document':'UpdateDocument','send-command':'SendCommand','update-document-default-version':'UpdateDocumentDefaultVersion','delete-document':'DeleteDocument'}
   resource=f'arn:aws:ssm:us-east-1:{ACCOUNT}:document/{DOC}'
   if args[0]=='s3api':resource='arn:aws:s3:::'+BUCKET+'/'+args[args.index('--key')+1]
   raise ManagementApiError(str(exc),('s3' if args[0]=='s3api' else args[0])+':'+names.get(args[1],args[1]),resource) from None
 return raw
def reviewed_objects():
 objects=[(f'releases/bootstrap/{BUNDLE}/fixed.tar.gz',BUNDLE)]
 objects += [(f'releases/ssm-reviewed/{SOURCE}/{kind}-{PINS[f"PPB-V2-Controlled-Deploy.{kind}.ssm.json"]}.json',PINS[f'PPB-V2-Controlled-Deploy.{kind}.ssm.json']) for kind in ['bootstrap','isolated']]
 return objects
def document_versions(raw):
 token=None
 for _ in range(20):
  page=raw('ssm','list-document-versions','--name',DOC,*(['--next-token',token] if token else []))
  yield from (entry['DocumentVersion'] for entry in page.get('DocumentVersions',[]))
  token=page.get('NextToken')
  if not token:return
 raise RuntimeError('DOCUMENT_SCAN_LIMIT')
def default_version(raw):
 return raw('ssm','get-document','--name',DOC,'--document-version','$DEFAULT','--document-format','JSON')['DocumentVersion']
def describe_version(raw,version):
 return raw('ssm','describe-document','--name',DOC,'--document-version',version)['Document']
def require_fresh_versions(default,hashes):
 need(default=='62','FRESH_DEFAULT_MUST_BE_V62')
 need(PREVIOUS.keys()<=hashes.keys(),'FRESH_PINNED_VERSION_MISSING')
 need(all(hashes[version]==sha for version,sha in PREVIOUS.items()),'FRESH_PINNED_HASH_MISMATCH')
def publish_data(apply=False,bundle_path=None,call=None):
 activation=load_activation();raw=wrap_call(call or activation.c.aws)
 identity_guard(raw)
 need(bundle_path is not None,'BUNDLE_PATH_REQUIRED')
 bundle=Path(bundle_path);need(bundle.is_file() and not bundle.is_symlink(),'BUNDLE_PATH_UNSAFE')
 need(digest(bundle.read_bytes())==BUNDLE,'BUNDLE_SHA_MISMATCH')
 payloads={
  f'releases/bootstrap/{BUNDLE}/fixed.tar.gz':bundle.read_bytes(),
  f'releases/ssm-reviewed/{SOURCE}/bootstrap-{PINS["PPB-V2-Controlled-Deploy.bootstrap.ssm.json"]}.json':(ROOT/'PPB-V2-Controlled-Deploy.bootstrap.ssm.json').read_bytes(),
  f'releases/ssm-reviewed/{SOURCE}/isolated-{PINS["PPB-V2-Controlled-Deploy.isolated.ssm.json"]}.json':(ROOT/'PPB-V2-Controlled-Deploy.isolated.ssm.json').read_bytes(),
 }
 planned=[{'key':key,'sha256':digest(data)} for key,data in payloads.items()]
 default=default_version(raw)
 hashes={}
 for version in document_versions(raw):
  if version in PREVIOUS:
   meta=describe_version(raw,version)
   hashes[version]=meta.get('Hash') if meta.get('HashType')=='Sha256' else None
 require_fresh_versions(default,hashes)
 if not apply:return dict(result='UI_SOURCE_S3_PUBLICATION_DRY_RUN',objects=planned,ec2_commands=0,iam_writes=0,document_writes=0,ssm_writes=0)
 verified=[]
 with tempfile.TemporaryDirectory() as tmp:
  for index,(key,data) in enumerate(payloads.items()):
   local=Path(tmp)/f'put-{index}';local.write_bytes(data)
   try:raw('s3api','put-object','--bucket',BUCKET,'--key',key,'--body',str(local),'--if-none-match','*','--server-side-encryption','AES256')
   except RuntimeError as exc:
    if str(exc)!='AWS_PreconditionFailed':raise
   readback=Path(tmp)/f'get-{index}';raw('s3api','get-object','--bucket',BUCKET,'--key',key,str(readback));need(digest(readback.read_bytes())==digest(data),'MANAGEMENT_S3_READBACK_HASH_MISMATCH')
   verified.append(dict(key=key,sha256=digest(data)))
 return dict(result='UI_SOURCE_S3_DATA_PUBLISHED',s3_verified=verified,ec2_commands=0,iam_writes=0,document_writes=0,ssm_writes=0)
def manage(apply=False,state_path='ui-source.state.json',call=None,pause=lambda _:None):
 activation=load_activation();raw=wrap_call(call or activation.c.aws)
 identity_guard(raw)
 docs={kind:json.loads((ROOT/f'PPB-V2-Controlled-Deploy.{kind}.ssm.json').read_text()) for kind in ['bootstrap','isolated']}
 verified=[]
 # Download all three fixed objects; no publication or root command until every hash passes.
 with tempfile.TemporaryDirectory() as tmp:
  for index,(key,sha) in enumerate(reviewed_objects()):
   local=Path(tmp)/str(index);raw('s3api','get-object','--bucket',BUCKET,'--key',key,str(local));need(digest(local.read_bytes())==sha,'MANAGEMENT_S3_READBACK_HASH_MISMATCH');verified.append(dict(key=key,sha256=sha))
 path=Path(state_path).absolute()
 state=None
 if path.exists() or path.is_symlink():
  need(not path.is_symlink() and path.stat().st_mode&0o077==0,'MANAGEMENT_JOURNAL_UNSAFE');state=json.loads(path.read_text());need(state.get('bundle')==BUNDLE and state.get('source')==SOURCE,'MANAGEMENT_JOURNAL_CONFLICT')
  need(not state.get('send_intent') or state.get('command_id'),'COMMAND_OUTCOME_UNKNOWN_DO_NOT_RESEND')
 versions={};hashes={}
 for version in document_versions(raw):
  doc=raw('ssm','get-document','--name',DOC,'--document-version',version,'--document-format','JSON');meta=describe_version(raw,version)
  value=json.loads(doc['Content']);need(meta['HashType']=='Sha256' and re.fullmatch('[a-f0-9]{64}',meta['Hash']) and (value in docs.values() or PREVIOUS.get(version)==meta['Hash']),'UNREVIEWED_CONCURRENT_DOCUMENT_VERSION')
  versions[version]=value;hashes[version]=meta['Hash']
 default=default_version(raw)
 if state is None:require_fresh_versions(default,hashes)
 if not apply:return dict(result='UI_SOURCE_MANAGEMENT_BATCH_DRY_RUN',s3_verified=verified,default_version=default,ec2_commands=0,iam_writes=0,document_writes=0)
 if state is None:
  state=dict(source=SOURCE,bundle=BUNDLE,phase='INTENT',request_id='ui-source-'+uuid.uuid4().hex,default_before=default,command_id=None,send_intent=False,bootstrap_verified=False)
  save(path,state)
 run=state['request_id'];archived=set();current_default=default
 def guarded(*args):
  nonlocal current_default
  op=args[:2]
  def arg(name):need(name in args,'MANAGEMENT_ARGUMENT_REQUIRED');return args[args.index(name)+1]
  if args[0]=='ssm':
   need(op[1] in {'get-document','describe-document','list-document-versions','get-command-invocation','update-document','update-document-default-version','delete-document','send-command'},'MANAGEMENT_ACTION_DENIED')
   if op[1]!='get-command-invocation':need(arg('--name' if op[1]!='send-command' else '--document-name')==DOC,'MANAGEMENT_RESOURCE_DENIED')
  if op==('ssm','update-document'):
   value=json.loads(Path(arg('--content').removeprefix('file://')).read_text());need(value in docs.values(),'MANAGEMENT_DOCUMENT_CONTENT_DENIED')
   if value==docs['isolated']:need(state['bootstrap_verified'],'BOOTSTRAP_NOT_VERIFIED')
   response=raw(*args);versions[response['DocumentDescription']['DocumentVersion']]=value;return response
  if op==('ssm','send-command'):
   version=arg('--document-version');need(versions.get(version)==docs['bootstrap'] and arg('--document-hash')==hashes.get(version) and arg('--instance-ids')==INSTANCE and '--parameters' not in args,'MANAGEMENT_COMMAND_SCOPE_DENIED')
   need(not state['send_intent'],'COMMAND_OUTCOME_UNKNOWN_DO_NOT_RESEND');state['send_intent']=True;save(path,state)
   response=raw(*args);state['command_id']=response['Command']['CommandId'];save(path,state);return response
  if op==('ssm','update-document-default-version'):
   need(state['bootstrap_verified'] and versions.get(arg('--document-version'))==docs['isolated'],'DEFAULT_REQUIRES_VERIFIED_RUNTIME')
   response=raw(*args);current_default=arg('--document-version');return response
  if op==('ssm','delete-document'):
   version=arg('--document-version');need(version in PREVIOUS and version in archived and version!=current_default and state['bootstrap_verified'],'DELETE_REQUIRES_EXACT_ARCHIVED_NONDEFAULT_VERSION');return raw(*args)
  if op==('s3api','put-object'):
   key=arg('--key');match=re.fullmatch(re.escape('releases/ssm-history/'+run+'/version-')+'([0-9]+)'+r'\.json',key)
   need(arg('--bucket')==BUCKET and match and match[1] in PREVIOUS and arg('--server-side-encryption')=='AES256' and arg('--if-none-match')=='*','MANAGEMENT_ARCHIVE_SCOPE_DENIED')
   version=match[1];need(json.loads(Path(arg('--body')).read_text())==versions[version],'MANAGEMENT_ARCHIVE_CONTENT_DENIED')
   try:response=raw(*args)
   except RuntimeError as exc:
    if str(exc)!='AWS_PreconditionFailed':raise
    with tempfile.TemporaryDirectory() as tmp:
     old=Path(tmp)/'archive.json';raw('s3api','get-object','--bucket',BUCKET,'--key',key,str(old));need(json.loads(old.read_text())==versions[version],'ARCHIVE_REPLAY_MISMATCH')
    response={}
   archived.add(version);return response
  need(args[0] in {'sts','ssm'} and op[1] in {'get-caller-identity','get-document','describe-document','list-document-versions','get-command-invocation'},'MANAGEMENT_ACTION_DENIED')
  if op==('ssm','get-command-invocation'):need(arg('--instance-id')==INSTANCE and arg('--command-id')==state['command_id'],'COMMAND_RECEIPT_SCOPE_DENIED')
  response=raw(*args)
  if op==('ssm','get-document'):versions[response['DocumentVersion']]=json.loads(response['Content'])
  if op==('ssm','describe-document'):hashes[arg('--document-version')]=response['Document']['Hash']
  if op==('ssm','get-command-invocation') and arg('--plugin-name')=='installReviewedTools':
   output=response.get('StandardOutputContent','') if response.get('Status')=='Success' and response.get('ResponseCode')==0 else '';rows=[json.loads(x.split('=',1)[1]) for x in output.splitlines() if x.startswith('V2_INVENTORY=')];readers=[json.loads(x.split('=',1)[1]) for x in output.splitlines() if x.startswith('LEGACY_READER_INSTALL=')]
   state['bootstrap_verified']=bool('V2_EXISTING_FPM_POLICY_PRESERVED=YES' in output and 'V2_FIXED_TOOLS_INSTALLED=YES' in output and len(rows)==1 and rows[0].get('result')=='PASS' and rows[0].get('isolation',{}).get('legacy_database_write') is False and len(readers)==1 and readers[0].get('legacy_file_changes')==0 and readers[0].get('database_changes')==0);save(path,state)
  return response
 try:
  if state.get('phase')=='VERIFIED':
   need(versions.get(default)==docs['isolated'],'VERIFIED_RUNTIME_CHANGED');guarded('ssm','get-command-invocation','--command-id',state['command_id'],'--instance-id',INSTANCE,'--plugin-name','installReviewedTools');need(state['bootstrap_verified'],'RECEIPT_NOT_VERIFIED');return dict(state['receipt'],replayed=True,new_ec2_commands=0)
  old=dict(os.environ);os.environ.update(GITHUB_RUN_ID=run,GITHUB_RUN_ATTEMPT='1')
  if state['command_id']:os.environ['PPB_BOOTSTRAP_RECOVERY_COMMAND_ID']=state['command_id']
  else:os.environ.pop('PPB_BOOTSTRAP_RECOVERY_COMMAND_ID',None)
  try:
   with tempfile.TemporaryDirectory() as tmp:
    cwd=os.getcwd();os.chdir(tmp)
    try:
     result=activation.activate(call=guarded,pause=pause)
     state['last_activation']={k:result.get(k) for k in ['result','stage','required_action','failed_action','reason','resource','ssm_command_id','bootstrap_document_version','runtime_version','retired_versions']};save(path,state)
     if result['result']=='ISOLATION_ALREADY_ACTIVE_VERIFIED':
      # Finish only a journaled manager cutover. The deploy-role activator stays read-only.
      need(state['command_id'] and state['bootstrap_verified'],'MANAGED_INSTALL_NOT_VERIFIED')
      guarded('ssm','get-command-invocation','--command-id',state['command_id'],'--instance-id',INSTANCE,'--plugin-name','installReviewedTools')
      need(state['bootstrap_verified'],'RECEIPT_NOT_VERIFIED')
      current_default=default_version(guarded)
      need(current_default==result['runtime_version'],'VERIFIED_RUNTIME_CHANGED')
      result['retired_versions']=[]
      prior_file=Path(tmp)/'prior-document.json'
      for version in PREVIOUS:
       if version not in versions:continue
       prior=guarded('ssm','get-document','--name',DOC,'--document-version',version,'--document-format','JSON')['Content']
       need(json.loads(prior)==versions[version],'MANAGEMENT_ARCHIVE_CONTENT_DENIED')
       prior_file.write_text(prior)
       guarded('s3api','put-object','--bucket',BUCKET,'--key',f'releases/ssm-history/{run}/version-{version}.json','--body',str(prior_file),'--if-none-match','*','--server-side-encryption','AES256')
       current_default=default_version(guarded)
       need(current_default==result['runtime_version'],'VERIFIED_RUNTIME_CHANGED')
       guarded('ssm','delete-document','--name',DOC,'--document-version',version)
       result['retired_versions'].append(version)
      need(PREVIOUS.keys().isdisjoint(document_versions(guarded)),'UNREVIEWED_VERSION_REMAINS')
    finally:os.chdir(cwd)
  finally:os.environ.clear();os.environ.update(old)
  need(result['result'] in {'ISOLATED_EXECUTOR_ACTIVATED','ISOLATION_ALREADY_ACTIVE_VERIFIED'},result.get('reason','MANAGED_INSTALL_NOT_VERIFIED'));need(state['bootstrap_verified'],'R2_PRESERVATION_NOT_VERIFIED')
  receipt=dict(result='MANAGED_UI_SOURCE_INSTALL_VERIFIED',bundle_sha256=BUNDLE,ssm_command_id=state['command_id'],bootstrap_version=result['bootstrap_document_version'],runtime_version=result['runtime_version'],r2_protected_bytes_unchanged=True,legacy_file_changes=0,legacy_db_changes=0,migrations=False,iam_changes=0,retired_versions=result.get('retired_versions',[]),s3_verified=verified)
  state.update(phase='VERIFIED',receipt=receipt);save(path,state);return receipt
 except Exception as exc:
  reason=str(exc);state.update(phase='STOP',reason=reason if re.fullmatch('[A-Za-z0-9_]{1,160}',reason) else 'MANAGEMENT_STOP');save(path,state);raise
if __name__=='__main__':
 import time
 p=argparse.ArgumentParser();p.add_argument('--apply',action='store_true');p.add_argument('--publish-data',action='store_true');p.add_argument('--bundle',type=Path);p.add_argument('--state',default='ui-source.state.json');a=p.parse_args();os.umask(0o077)
 try:
  if a.publish_data:print(json.dumps(publish_data(a.apply,a.bundle)))
  else:print(json.dumps(manage(a.apply,a.state,pause=time.sleep)))
 except Exception as exc:
  value=str(exc);report=dict(result='STOP',reason=value if re.fullmatch('[A-Za-z0-9_]{1,160}',value) else 'MANAGEMENT_STOP',receipt_state=a.state)
  if isinstance(exc,ManagementApiError):report.update(failed_action=exc.action,resource=exc.resource)
  journal=Path(a.state)
  if journal.is_file() and not journal.is_symlink() and journal.stat().st_size<100000:
   try:
    state=json.loads(journal.read_text())
    if state.get('bundle')==BUNDLE and state.get('source')==SOURCE:report.update(ssm_command_id=state.get('command_id'),last_activation=state.get('last_activation'))
   except (ValueError,OSError):pass
  print(json.dumps(report));raise SystemExit(2)
