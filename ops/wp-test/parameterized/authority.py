#!/usr/bin/env python3
"""Probe exact existing WP document update authority with identical content only.
No new document/code, no SendCommand, IAM, V2 role or WP writes.
"""
import importlib.util
import json
from pathlib import Path
import tempfile
ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('remote',ROOT.parent/'remote.py');r=importlib.util.module_from_spec(s);s.loader.exec_module(r)
def probe(call=r.aws):
    actor=call('sts','get-caller-identity')
    r.require(actor['Account']==r.ACCOUNT and '/PPB-V2-GitHub-WP-Draft-Test-Role/' in actor['Arn'],'WP_ROLE_REQUIRED')
    current=call('ssm','get-document','--name',r.DOCUMENT,'--document-version','$LATEST','--document-format','JSON')
    result=dict(role=r.ROLE,resource=f'arn:aws:ssm:{r.REGION}:{r.ACCOUNT}:document/{r.DOCUMENT}',action='ssm:UpdateDocument',document_version=current['DocumentVersion'],probe='EXACT_UNCHANGED_LATEST_CONTENT',document_changed=False,wp_writes=0)
    with tempfile.TemporaryDirectory() as tmp:
        p=Path(tmp)/'unchanged.json';p.write_text(current['Content'])
        try:
            call('ssm','update-document','--name',r.DOCUMENT,'--document-version','$LATEST','--document-format','JSON','--content','file://'+str(p))
            raise r.Stop('UNEXPECTED_IDENTICAL_CONTENT_UPDATE')
        except r.Stop as exc:
            code=str(exc)
            result['result']='AUTHORIZED_NO_CHANGE' if code=='AWS_DuplicateDocumentContent' else 'BLOCKED'
            result['aws_code']=code
    after=call('ssm','get-document','--name',r.DOCUMENT,'--document-version','$LATEST','--document-format','JSON')
    r.require(after['Content']==current['Content'] and after['DocumentVersion']==current['DocumentVersion'],'WP_DOCUMENT_CHANGED_UNEXPECTEDLY')
    r.persist(Path('wp-result/authority.json'),result)
    return result
if __name__=='__main__':print(json.dumps(probe()))
