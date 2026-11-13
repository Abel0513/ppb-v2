#!/usr/bin/env python3
"""Read one existing controlled-release result; never upload, install or SendCommand."""
import importlib.util
import json
from pathlib import Path
import re
ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('release_client_recovery',ROOT/'release-client.py');c=importlib.util.module_from_spec(s);s.loader.exec_module(c)
def recover(request,call=c.aws,pause=c.time.sleep):
    out=Path('v2-result');out.mkdir(exist_ok=True)
    result=dict(result='STOP',operation='deploy',commit=request['commit'],ssm_command_id=request['command_id'],collection_only=True,send_command_requested=False)
    def save():(out/'recovered.json').write_text(json.dumps(result,indent=2))
    try:
        c.need(re.fullmatch('[a-f0-9]{40}',request['commit']) and re.fullmatch('[a-f0-9-]{36}',request['command_id']),'RECOVERY_ID_INVALID')
        c.need(re.fullmatch('[1-9][0-9]*',request['document_version']) and re.fullmatch('[a-f0-9]{64}',request['document_hash']),'RECOVERY_PIN_INVALID')
        c.need(call('sts','get-caller-identity')['Account']==c.ACCOUNT,'WRONG_ACCOUNT')
        meta=call('ssm','describe-document','--name',c.DOC,'--document-version',request['document_version'])['Document']
        c.need(meta['Hash']==request['document_hash'] and meta['HashType']=='Sha256','RECOVERY_DOCUMENT_HASH_MISMATCH')
        body=call('ssm','get-document','--name',c.DOC,'--document-version',request['document_version'],'--document-format','JSON')
        c.need(json.loads(body['Content'])==json.loads((ROOT/'PPB-V2-Controlled-Deploy.isolated.ssm.json').read_text()),'RECOVERY_DOCUMENT_NOT_REVIEWED')
        result.update(document_version=request['document_version'],document_hash=meta['Hash']);save()
        return c.collect(result,call,pause,save)
    except Exception as exc:
        reason=str(exc);result['reason']=reason if re.fullmatch('[A-Za-z0-9_]{1,160}',reason) else 'RECOVERY_NOT_VERIFIED';save();return result
if __name__=='__main__':
    request=json.loads((ROOT/'recovery-request.json').read_text());r=recover(request);print(json.dumps(r));raise SystemExit(0 if r['result']=='PASS' else 2)
