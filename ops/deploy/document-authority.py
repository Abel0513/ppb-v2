#!/usr/bin/env python3
"""Inspect own authority; publish reviewed document DATA only. No SSM/IAM writes."""
import fnmatch,hashlib,importlib.util,json,os,re,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('v2_client',ROOT/'release-client.py');c=importlib.util.module_from_spec(s);s.loader.exec_module(c)
ROLE='PPB-V2-GitHub-Deploy-Role';ROLE_ARN=f'arn:aws:iam::{c.ACCOUNT}:role/{ROLE}'
DOC_ARN=f'arn:aws:ssm:{c.REGION}:{c.ACCOUNT}:document/{c.DOC}'
ACTIONS=['ssm:UpdateDocument','ssm:UpdateDocumentDefaultVersion','ssm:DeleteDocument','ssm:GetDocument','ssm:DescribeDocument','ssm:ListDocumentVersions']
def reason(exc):
 value=str(exc);return value if re.fullmatch('[A-Za-z0-9_]{1,120}',value) else 'AUDIT_READ_FAILED'
def run(call=c.aws,publish=False):
 result=dict(result='DOCUMENT_AUTHORITY_AUDIT',ssm_command_id=None,ec2_commands=0,ssm_writes=0,iam_writes=0,role=ROLE_ARN,resource=DOC_ARN,reads={},candidates=[])
 identity=call('sts','get-caller-identity');c.need(identity['Account']==c.ACCOUNT and identity['Arn'].startswith(f'arn:aws:sts::{c.ACCOUNT}:assumed-role/{ROLE}/'),'WRONG_AUDIT_IDENTITY')
 result['caller_arn']=identity['Arn']
 expected={name:json.loads((ROOT/f'PPB-V2-Controlled-Deploy.{name}.ssm.json').read_text()) for name in ['bootstrap','isolated']}
 def read(key,*args):
  try:return call(*args)
  except RuntimeError as exc:result['reads'][key]=dict(status=reason(exc));return None
 versions=[];token=None
 for _ in range(20):
  args=['ssm','list-document-versions','--name',c.DOC]+(['--next-token',token] if token else [])
  page=read('document_versions',*args)
  if page is None:break
  for row in page.get('DocumentVersions',[]):
   version=row['DocumentVersion'];value=read('version_'+version,'ssm','get-document','--name',c.DOC,'--document-version',version,'--document-format','JSON')
   meta=read('version_meta_'+version,'ssm','describe-document','--name',c.DOC,'--document-version',version)
   if value is not None:
    doc=json.loads(value['Content']);versions.append(dict(version=version,is_default=row.get('IsDefaultVersion',False),matches=[name for name,v in expected.items() if v==doc],hash=meta['Document'].get('Hash') if meta else None,hash_type=meta['Document'].get('HashType') if meta else None))
  token=page.get('NextToken')
  if not token:result['version_scan_complete']=True;break
 else:result['version_scan_complete']=False
 result['versions']=versions
 for selector in ['$DEFAULT','$LATEST']:
  value=read(selector,'ssm','get-document','--name',c.DOC,'--document-version',selector,'--document-format','JSON')
  if value:result['reads'][selector]=dict(status='READ_SUCCESS',version=value['DocumentVersion'],matches=[name for name,v in expected.items() if v==json.loads(value['Content'])])
 role=read('own_role','iam','get-role','--role-name',ROLE)
 if role:result['reads']['own_role']=dict(status='READ_SUCCESS',arn=role['Role']['Arn'],permissions_boundary=role['Role'].get('PermissionsBoundary',{}).get('PermissionsBoundaryArn'))
 policy_names=read('inline_policy_names','iam','list-role-policies','--role-name',ROLE)
 if policy_names:
  result['reads']['inline_policy_names']=dict(status='READ_SUCCESS',names=policy_names.get('PolicyNames',[]))
  # Summarize only relevant permission shape, never dump arbitrary conditions.
  summaries=[]
  for name in policy_names.get('PolicyNames',[])[:30]:
   policy=read('inline_'+name,'iam','get-role-policy','--role-name',ROLE,'--policy-name',name)
   if not policy:continue
   doc=policy['PolicyDocument']
   if isinstance(doc,str):
    from urllib.parse import unquote
    doc=json.loads(unquote(doc))
   statements=doc.get('Statement',[]);statements=statements if isinstance(statements,list) else [statements]
   for st in statements:
    actions=st.get('Action',[]);actions=actions if isinstance(actions,list) else [actions]
    matched=[a for a in ACTIONS if any(fnmatch.fnmatchcase(a.lower(),str(p).lower()) for p in actions)]
    if matched or 'NotAction' in st:summaries.append(dict(policy=name,effect=st.get('Effect'),actions=matched,uses_not_action='NotAction' in st,resource=st.get('Resource'),condition_keys=sorted(st.get('Condition',{}))))
  result['inline_permission_summary']=summaries
 attached=read('attached_policy_names','iam','list-attached-role-policies','--role-name',ROLE)
 if attached:result['reads']['attached_policy_names']=dict(status='READ_SUCCESS',policies=attached.get('AttachedPolicies',[]),truncated=attached.get('IsTruncated',False))
 managed=[p['PolicyArn'] for p in (attached or {}).get('AttachedPolicies',[])[:30]]
 boundary=(role or {}).get('Role',{}).get('PermissionsBoundary',{}).get('PermissionsBoundaryArn')
 if boundary and boundary not in managed:managed.append(boundary)
 for arn in managed:
  meta=read('managed_meta_'+arn,'iam','get-policy','--policy-arn',arn)
  if not meta:continue
  version=read('managed_version_'+arn,'iam','get-policy-version','--policy-arn',arn,'--version-id',meta['Policy']['DefaultVersionId'])
  if not version:continue
  doc=version['PolicyVersion']['Document'];statements=doc.get('Statement',[]);statements=statements if isinstance(statements,list) else [statements]
  relevant=[]
  for st in statements[:100]:
   actions=st.get('Action',[]);actions=actions if isinstance(actions,list) else [actions]
   matched=[a for a in ACTIONS if any(fnmatch.fnmatchcase(a.lower(),str(p).lower()) for p in actions)]
   if matched or 'NotAction' in st:relevant.append(dict(effect=st.get('Effect'),actions=matched,uses_not_action='NotAction' in st,resource=st.get('Resource'),condition_keys=sorted(st.get('Condition',{}))))
  result.setdefault('managed_permission_summary',[]).append(dict(arn=arn,is_boundary=arn==boundary,statements=relevant))
 simulated=read('own_permission_simulation','iam','simulate-principal-policy','--policy-source-arn',ROLE_ARN,'--action-names',*ACTIONS,'--resource-arns',DOC_ARN)
 if simulated:result['simulation']=[dict(action=x['EvalActionName'],decision=x['EvalDecision'],missing_context=x.get('MissingContextValues',[]),boundary=x.get('PermissionsBoundaryDecisionDetail')) for x in simulated.get('EvaluationResults',[])]
 if publish:
  commit=os.environ['GITHUB_SHA'];c.need(re.fullmatch('[a-f0-9]{40}',commit),'COMMIT_INVALID')
  for name in expected:
   path=ROOT/f'PPB-V2-Controlled-Deploy.{name}.ssm.json';sha=hashlib.sha256(path.read_bytes()).hexdigest();key=f'releases/ssm-reviewed/{commit}/{name}-{sha}.json'
   try:call('s3api','put-object','--bucket',c.BUCKET,'--key',key,'--body',str(path),'--if-none-match','*','--server-side-encryption','AES256')
   except RuntimeError as exc:
    if str(exc)!='AWS_PreconditionFailed':result['candidates'].append(dict(kind=name,status=reason(exc)));continue
   status='VERIFIED_DATA_ONLY'
   try:
    with tempfile.TemporaryDirectory() as tmp:
     local=Path(tmp)/'candidate.json';call('s3api','get-object','--bucket',c.BUCKET,'--key',key,str(local));c.need(hashlib.sha256(local.read_bytes()).hexdigest()==sha,'S3_CANDIDATE_READBACK_MISMATCH')
   except RuntimeError as exc:status='CANDIDATE_READBACK_'+reason(exc)
   result['candidates'].append(dict(kind=name,status=status,bucket=c.BUCKET,key=key,sha256=sha))
  helper=ROOT/'preload-reviewed-documents.py';sha=hashlib.sha256(helper.read_bytes()).hexdigest();key=f'releases/ssm-reviewed/{commit}/preload-{sha}.py'
  try:
   call('s3api','put-object','--bucket',c.BUCKET,'--key',key,'--body',str(helper),'--if-none-match','*','--server-side-encryption','AES256')
   result['management_helper']=dict(status='PUBLISHED_NOT_EXECUTED',bucket=c.BUCKET,key=key,sha256=sha)
  except RuntimeError as exc:result['management_helper']=dict(status=reason(exc))
 return result
if __name__=='__main__':
 os.umask(0o077);out=Path('v2-result');out.mkdir(exist_ok=True)
 try:value=run(publish=True)
 except Exception as exc:value=dict(result='DOCUMENT_AUTHORITY_AUDIT_STOP',reason=reason(exc),ssm_command_id=None)
 (out/'document-authority.json').write_text(json.dumps(value,indent=2));print(json.dumps(value));raise SystemExit(0 if value['result']=='DOCUMENT_AUTHORITY_AUDIT' else 2)
