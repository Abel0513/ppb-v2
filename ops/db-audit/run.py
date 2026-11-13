#!/usr/bin/env python3
"""Same reviewed V2 role/document, fixed metadata-only command with service SHA pin."""
import importlib.util
import json
import os
from pathlib import Path
import time
ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('release',ROOT.parent/'deploy/release-client.py');c=importlib.util.module_from_spec(s);s.loader.exec_module(c)
s=importlib.util.spec_from_file_location('metadata_document',ROOT/'document.py');d=importlib.util.module_from_spec(s);s.loader.exec_module(d)
def run(call=c.aws,pause=time.sleep):
    out=Path('v2-result');out.mkdir(exist_ok=True)
    result={'result':'STOP','ssm_command_id':None,'program_writes':0,'database_writes':0}
    def save():(out/'metadata.json').write_text(json.dumps(result,indent=2))
    try:
        c.need(call('sts','get-caller-identity')['Account']==c.ACCOUNT,'WRONG_ACCOUNT')
        value=d.document();path=out/'fixed-metadata-document.json';path.write_text(json.dumps(value))
        latest=call('ssm','get-document','--name',c.DOC,'--document-version','$LATEST','--document-format','JSON')
        if json.loads(latest['Content'])==value:version=latest['DocumentVersion']
        else:
            response=call('ssm','update-document','--name',c.DOC,'--document-version','$LATEST','--document-format','JSON','--content','file://'+str(path.resolve()))
            version=response['DocumentDescription']['DocumentVersion']
        result['document_version']=version;save()
        for _ in range(30):
            meta=call('ssm','describe-document','--name',c.DOC,'--document-version',version)['Document']
            if meta['Status']=='Active':break
            c.need(meta['Status'] not in {'Failed','Deleting'},'DOCUMENT_FAILED');pause(2)
        else:raise RuntimeError('DOCUMENT_NOT_ACTIVE')
        actual=call('ssm','get-document','--name',c.DOC,'--document-version',version,'--document-format','JSON')
        c.need(json.loads(actual['Content'])==value and meta['HashType']=='Sha256','REVIEWED_DOCUMENT_CHANGED')
        result['document_sha256']=meta['Hash'];save()
        sent=call('ssm','send-command','--document-name',c.DOC,'--document-version',version,'--document-hash',meta['Hash'],'--document-hash-type','Sha256','--instance-ids',c.INSTANCE,'--max-concurrency','1','--max-errors','0','--timeout-seconds','120','--comment','V2 fixed metadata read-only '+os.environ['GITHUB_RUN_ID'])
        command=sent['Command']['CommandId'];result['ssm_command_id']=command;save();print('SSM_COMMAND_ID='+command,flush=True)
        for _ in range(60):
            try:inv=call('ssm','get-command-invocation','--command-id',command,'--instance-id',c.INSTANCE,'--plugin-name','fixedMetadataAudit')
            except RuntimeError as exc:
                if str(exc) in {'AWS_InvalidPluginName','AWS_InvocationDoesNotExist'}:pause(3);continue
                raise
            if inv['Status'] in {'Pending','InProgress','Delayed'}:pause(3);continue
            c.need(inv['Status']=='Success' and inv['ResponseCode']==0,'FIXED_METADATA_EXECUTION_FAILED')
            rows=[x.split('=',1)[1] for x in inv.get('StandardOutputContent','').splitlines() if x.startswith('V2_METADATA=')]
            c.need(len(rows)==1,'METADATA_RECEIPT_MISSING')
            result['metadata']=json.loads(rows[0]);result['reason']=result['metadata'].get('reason')
            if result['metadata'].get('result')=='PASS' and result['metadata'].get('global_fk_visibility_proven') is True and result['metadata'].get('v2_object_metadata_coverage_proven') is True:result['result']='PASS'
            save();return result
        raise RuntimeError('METADATA_OUTCOME_UNKNOWN')
    except Exception as exc:
        import re
        code=str(exc);result['reason']=code if re.fullmatch('[A-Za-z0-9_]{1,160}',code) else 'AUDIT_NOT_VERIFIED';save();return result
if __name__=='__main__':
    value=run();print(json.dumps(value));raise SystemExit(0 if value['result']=='PASS' else 2)
