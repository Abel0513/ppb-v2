#!/usr/bin/env python3
"""Existing V2 OIDC/SSM role and document; JSON data only, no document mutation."""
import base64,hashlib,importlib.util,json,os,re,time
from pathlib import Path
ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('release_client',ROOT.parent/'deploy/release-client.py');c=importlib.util.module_from_spec(s);s.loader.exec_module(c)
def main():
    result=dict(result='STOP',ssm_command_id=None,legacy_file_writes=0,legacy_db_writes=0)
    out=Path('legacy-result');out.mkdir(exist_ok=True)
    def save():(out/'result.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
    save()
    try:
        c.need(c.aws('sts','get-caller-identity')['Account']==c.ACCOUNT,'ACCOUNT_MISMATCH')
        actual=c.aws('ssm','get-document','--name',c.DOC,'--document-version','$DEFAULT','--document-format','JSON')
        expected=json.loads((ROOT.parent/'deploy/PPB-V2-Controlled-Deploy.isolated.ssm.json').read_text())
        c.need(json.loads(actual['Content'])==expected,'REVIEWED_READER_NOT_INSTALLED')
        version=actual['DocumentVersion'];meta=c.aws('ssm','describe-document','--name',c.DOC,'--document-version',version)['Document']
        request=json.loads((ROOT/'request.json').read_text());encoded=base64.b64encode(json.dumps(request).encode()).decode()
        params={key:[value] for key,value in dict(Mode='legacy_readonly',LegacyRequest=encoded,Operation='audit',Commit=os.environ['GITHUB_SHA'],Sha256=hashlib.sha256(encoded.encode()).hexdigest(),RunId=os.environ['GITHUB_RUN_ID'],RunAttempt=os.environ['GITHUB_RUN_ATTEMPT'],Approval='NONE').items()}
        sent=c.aws('ssm','send-command','--document-name',c.DOC,'--document-version',version,'--document-hash',meta['Hash'],'--document-hash-type','Sha256','--instance-ids',c.INSTANCE,'--parameters',json.dumps(params),'--max-concurrency','1','--max-errors','0','--timeout-seconds','120')
        command=sent['Command']['CommandId'];result.update(ssm_command_id=command,document_version=version,document_hash=meta['Hash']);save();print('SSM_COMMAND_ID='+command,flush=True)
        for _ in range(80):
            try:inv=c.aws('ssm','get-command-invocation','--command-id',command,'--instance-id',c.INSTANCE,'--plugin-name','legacyReadonly')
            except RuntimeError as exc:
                if str(exc) in ['AWS_InvocationDoesNotExist','AWS_InvalidPluginName']:time.sleep(3);continue
                raise
            if inv['Status'] in ['Pending','InProgress','Delayed']:time.sleep(3);continue
            lines=[x.split('=',1)[1] for x in inv.get('StandardOutputContent','').splitlines() if x.startswith('PPB_LEGACY_READONLY_V1=')]
            c.need(len(lines)==1,'READONLY_RESULT_MISSING');result['remote']=json.loads(lines[0])
            c.need(inv['Status']=='Success' and inv['ResponseCode']==0 and result['remote'].get('result')=='PASS','READONLY_REMOTE_STOP')
            result['result']='PASS';save();print(json.dumps(result,ensure_ascii=False));return 0
        raise RuntimeError('OUTCOME_UNKNOWN_NO_MUTATION_RETRY')
    except Exception as exc:
        code=str(exc);result['reason']=code if re.fullmatch('[A-Z0-9_]{1,120}',code) else 'READONLY_CLIENT_STOP';save();print(json.dumps(result));return 2
if __name__=='__main__':raise SystemExit(main())
