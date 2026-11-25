#!/usr/bin/env python3
"""One existing-manager batch, not a new runner or an IAM grant. Dry-run by default."""
import argparse,hashlib,importlib.util,json,os,re,tempfile,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parent
ACCOUNT='429724121147';DOC='PPB-V2-Controlled-Deploy';INSTANCE='i-0135d6b2fac7a54c2'
BUCKET='ppb-v2-release-artifacts-429724121147-use1';SOURCE='5c5b533e9825d474743e1ed5e590c1410446fe7f'
BUNDLE='361c04364fadace29c3fe06341e46236017d0cf6b10dcc92f9eaed7c9bd4003a'
PINS={'activate-isolation.py':'3b763e3cde7a182adaa88f51e3c29df70ec14e6f8b89be5d353c1ed34f5171e2','release-client.py':'5a4335b2e151c0a52b54463844f83d6a7926594a780072803faf1f3d065a6edd','PPB-V2-Controlled-Deploy.bootstrap.ssm.json':'567d9ff5798e08e82472c4595892e3d0ad904a9451930bb168d08feb5390d794','PPB-V2-Controlled-Deploy.isolated.ssm.json':'b16109a7334de36ba9d860ee96220fd00bb9b9be2237a1f832c5ccddee6e0768'}
PREVIOUS={'58':'ccf4b31f8d43be0e716286630e12f4b69f3a2ac4fc854756eda7a727e847a752','60':'aa5761afc82988a9f26df844647cb8e205f295e64bfc41e73e715550e62c5e35'}
def need(value,code):
 if not value:raise RuntimeError(code)
def digest(data):return hashlib.sha256(data).hexdigest()
class ManagementApiError(RuntimeError):
 def __init__(self,code,action,resource):super().__init__(code);self.action=action;self.resource=resource
def load_activation():
 for name,sha in PINS.items():need(digest((ROOT/name).read_bytes())==sha,'MANAGEMENT_PACKET_PIN_MISMATCH')
 s=importlib.util.spec_from_file_location('approved_ui_activation',ROOT/'activate-isolation.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
def save(path,value):
 path=Path(path);need(not path.is_symlink(),'MANAGEMENT_JOURNAL_UNSAFE');path.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
 need(not path.parent.is_symlink() and path.parent.stat().st_mode&0o022==0,'MANAGEMENT_JOURNAL_DIRECTORY_UNSAFE')
 fd,temp=tempfile.mkstemp(prefix='.ui-phase-a-',dir=path.parent)
 with os.fdopen(fd,'w') as f:json.dump(value,f);f.flush();os.fsync(f.fileno())
 os.replace(temp,path)
def manage(apply=False,state_path='ui-phase-a.state.json',call=None,pause=lambda _:None):
 activation=load_activation();source_call=call or activation.c.aws
 def raw(*args):
  try:return source_call(*args)
  except RuntimeError as exc:
   names={'get-caller-identity':'GetCallerIdentity','get-object':'GetObject','put-object':'PutObject','get-document':'GetDocument','describe-document':'DescribeDocument','list-document-versions':'ListDocumentVersions','get-command-invocation':'GetCommandInvocation','update-document':'UpdateDocument','send-command':'SendCommand','update-document-default-version':'UpdateDocumentDefaultVersion','delete-document':'DeleteDocument'}
   resource=f'arn:aws:ssm:us-east-1:{ACCOUNT}:document/{DOC}'
   if args[0]=='s3api':resource='arn:aws:s3:::'+BUCKET+'/'+args[args.index('--key')+1]
   raise ManagementApiError(str(exc),('s3' if args[0]=='s3api' else args[0])+':'+names.get(args[1],args[1]),resource) from None
 identity=raw('sts','get-caller-identity');need(identity['Account']==ACCOUNT,'WRONG_ACCOUNT')
 need('/PPB-V2-GitHub-Deploy-Role/' not in identity['Arn'],'EXISTING_SSM_MANAGER_REQUIRED_NO_RUNTIME_RETRY')
 need('/PPB-V2-GitHub-WP-Draft-Test-Role/' not in identity['Arn'],'WP_ROLE_NOT_AUTHORIZED_FOR_V2_MANAGEMENT')
 docs={kind:json.loads((ROOT/f'PPB-V2-Controlled-Deploy.{kind}.ssm.json').read_text()) for kind in ['bootstrap','isolated']}
 verified=[]
 # Download all three fixed objects; no publication or root command until every hash passes.
 with tempfile.TemporaryDirectory() as tmp:
  objects=[(f'releases/bootstrap/{BUNDLE}/fixed.tar.gz',BUNDLE)]
  objects += [(f'releases/ssm-reviewed/{SOURCE}/{kind}-{PINS[f"PPB-V2-Controlled-Deploy.{kind}.ssm.json"]}.json',PINS[f'PPB-V2-Controlled-Deploy.{kind}.ssm.json']) for kind in docs]
  for index,(key,sha) in enumerate(objects):
   local=Path(tmp)/str(index);raw('s3api','get-object','--bucket',BUCKET,'--key',key,str(local));need(digest(local.read_bytes())==sha,'MANAGEMENT_S3_READBACK_HASH_MISMATCH');verified.append(dict(key=key,sha256=sha))
 versions={};hashes={};token=None
 for _ in range(20):
  page=raw('ssm','list-document-versions','--name',DOC,*(['--next-token',token] if token else []))
  for entry in page.get('DocumentVersions',[]):
   version=entry['DocumentVersion'];doc=raw('ssm','get-document','--name',DOC,'--document-version',version,'--document-format','JSON');meta=raw('ssm','describe-document','--name',DOC,'--document-version',version)['Document']
   value=json.loads(doc['Content']);need(meta['HashType']=='Sha256' and re.fullmatch('[a-f0-9]{64}',meta['Hash']) and (value in docs.values() or PREVIOUS.get(version)==meta['Hash']),'UNREVIEWED_CONCURRENT_DOCUMENT_VERSION')
   versions[version]=value;hashes[version]=meta['Hash']
  token=page.get('NextToken')
  if not token:break
 else:raise RuntimeError('DOCUMENT_SCAN_LIMIT')
 default=raw('ssm','get-document','--name',DOC,'--document-version','$DEFAULT','--document-format','JSON')['DocumentVersion']
 if not apply:return dict(result='MANAGEMENT_BATCH_DRY_RUN',s3_verified=verified,default_version=default,ec2_commands=0,iam_writes=0,document_writes=0)
 path=Path(state_path).absolute()
 if path.exists():
  need(not path.is_symlink() and path.stat().st_mode&0o077==0,'MANAGEMENT_JOURNAL_UNSAFE');state=json.loads(path.read_text());need(state.get('bundle')==BUNDLE and state.get('source')==SOURCE,'MANAGEMENT_JOURNAL_CONFLICT')
  need(not state.get('send_intent') or state.get('command_id'),'COMMAND_OUTCOME_UNKNOWN_DO_NOT_RESEND')
 else:
  need(default in PREVIOUS,'EXISTING_INSTALL_RECEIPT_REQUIRED')
  state=dict(source=SOURCE,bundle=BUNDLE,phase='INTENT',request_id='ui-phase-a-'+uuid.uuid4().hex,default_before=default,command_id=None,send_intent=False,bootstrap_verified=False)
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
    finally:os.chdir(cwd)
  finally:os.environ.clear();os.environ.update(old)
  need(result['result']=='ISOLATED_EXECUTOR_ACTIVATED',result.get('reason','MANAGED_INSTALL_NOT_VERIFIED'));need(state['bootstrap_verified'],'R2_PRESERVATION_NOT_VERIFIED')
  receipt=dict(result='MANAGED_UI_PHASE_A_INSTALL_VERIFIED',bundle_sha256=BUNDLE,ssm_command_id=state['command_id'],bootstrap_version=result['bootstrap_document_version'],runtime_version=result['runtime_version'],r2_protected_bytes_unchanged=True,legacy_file_changes=0,legacy_db_changes=0,migrations=False,iam_changes=0,retired_versions=result.get('retired_versions',[]),s3_verified=verified)
  state.update(phase='VERIFIED',receipt=receipt);save(path,state);return receipt
 except Exception as exc:
  reason=str(exc);state.update(phase='STOP',reason=reason if re.fullmatch('[A-Za-z0-9_]{1,160}',reason) else 'MANAGEMENT_STOP');save(path,state);raise
if __name__=='__main__':
 import time
 p=argparse.ArgumentParser();p.add_argument('--apply',action='store_true');p.add_argument('--state',default='ui-phase-a.state.json');a=p.parse_args();os.umask(0o077)
 try:print(json.dumps(manage(a.apply,a.state,pause=time.sleep)))
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
