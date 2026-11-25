#!/usr/bin/env python3
"""Administrator-only publication of two reviewed NON-DEFAULT SSM versions.
Default is a dry-run. No IAM changes, SendCommand, default changes or EC2 login.
"""
import argparse,hashlib,json,os,re,subprocess,tempfile,time
from pathlib import Path
ACCOUNT='429724121147';REGION='us-east-1';DOC='PPB-V2-Controlled-Deploy'
BUCKET='ppb-v2-release-artifacts-429724121147-use1'
PINS={'bootstrap':'6c0879a9b672cfcb1496d533701fca4494cbc66fe2438c659bbb3962739244e4','isolated':'f63b5aa86b017223e3df17381d615da6a88fc989e70a7b15429ed5c328094b4d'}
def need(ok,code):
 if not ok:raise RuntimeError(code)
def aws(*args):
 p=subprocess.run(['aws',*args,'--region',REGION,'--output','json','--no-cli-pager'],capture_output=True,text=True,timeout=90,env=dict(os.environ,AWS_PAGER='',AWS_MAX_ATTEMPTS='1'))
 if p.returncode:
  code=re.search(r'\(([A-Za-z0-9_]+)\)',p.stderr);raise RuntimeError('AWS_'+(code[1] if code else 'CLI_FAILED'))
 return json.loads(p.stdout or '{}')
def preload(source_commit,apply=False,call=aws,pause=time.sleep,result=None):
 result=result if result is not None else {}
 result.update(result='STOP',created_versions=[],reused_versions=[],ec2_commands=0,iam_writes=0,default_changes=0)
 need(bool(re.fullmatch('[a-f0-9]{40}',source_commit)),'SOURCE_COMMIT_INVALID')
 identity=call('sts','get-caller-identity');need(identity['Account']==ACCOUNT,'WRONG_ACCOUNT')
 # Do not repeat the established denied write under the daily runtime identity.
 if apply:need('/PPB-V2-GitHub-Deploy-Role/' not in identity['Arn'],'EXISTING_SSM_MANAGER_REQUIRED')
 before=call('ssm','get-document','--name',DOC,'--document-version','$DEFAULT','--document-format','JSON');result['default_before']=before['DocumentVersion']
 with tempfile.TemporaryDirectory() as tmp:
  candidates={}
  for kind,sha in PINS.items():
   path=Path(tmp)/(kind+'.json');key=f'releases/ssm-reviewed/{source_commit}/{kind}-{sha}.json'
   call('s3api','get-object','--bucket',BUCKET,'--key',key,str(path));need(hashlib.sha256(path.read_bytes()).hexdigest()==sha,'REVIEWED_DOCUMENT_SHA_MISMATCH')
   candidates[kind]=(path,json.loads(path.read_text()))
  existing={};token=None
  for _ in range(20):
   page=call('ssm','list-document-versions','--name',DOC,*(['--next-token',token] if token else []))
   for entry in page.get('DocumentVersions',[]):
    version=entry['DocumentVersion'];value=call('ssm','get-document','--name',DOC,'--document-version',version,'--document-format','JSON');existing[version]=json.loads(value['Content'])
   token=page.get('NextToken')
   if not token:break
  else:raise RuntimeError('VERSION_SCAN_LIMIT')
  for kind,(path,expected) in candidates.items():
   matches=[version for version,value in existing.items() if value==expected]
   if matches:result['reused_versions'].append(dict(kind=kind,version=matches[0]));continue
   if not apply:result.setdefault('needed_publications',[]).append(kind);continue
   response=call('ssm','update-document','--name',DOC,'--document-version','$LATEST','--document-format','JSON','--content','file://'+str(path))
   version=response['DocumentDescription']['DocumentVersion'];result['created_versions'].append(dict(kind=kind,version=version,approved_sha256=PINS[kind]))
   for _ in range(30):
    meta=call('ssm','describe-document','--name',DOC,'--document-version',version)['Document']
    if meta['Status']=='Active':break
    need(meta['Status'] not in ['Failed','Deleting'],'PUBLICATION_FAILED');pause(1)
   else:raise RuntimeError('PUBLICATION_TIMEOUT')
   value=call('ssm','get-document','--name',DOC,'--document-version',version,'--document-format','JSON');need(json.loads(value['Content'])==expected,'PUBLICATION_READBACK_MISMATCH')
 after=call('ssm','get-document','--name',DOC,'--document-version','$DEFAULT','--document-format','JSON')
 need(after['DocumentVersion']==before['DocumentVersion'] and json.loads(after['Content'])==json.loads(before['Content']),'DEFAULT_UNEXPECTEDLY_CHANGED')
 result.update(result='REVIEWED_DOCUMENTS_PRELOADED' if apply else 'REVIEWED_DOCUMENT_PUBLICATION_DRY_RUN',default_unchanged=True,tools_installed=False)
 return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--source-commit',required=True);p.add_argument('--apply',action='store_true');a=p.parse_args();result={}
 try:preload(a.source_commit,a.apply,result=result)
 except Exception as exc:
  value=str(exc);result['reason']=value if re.fullmatch('[A-Za-z0-9_]{1,120}',value) else 'PUBLICATION_STOP'
 print(json.dumps(result));raise SystemExit(0 if result.get('default_unchanged') else 2)
