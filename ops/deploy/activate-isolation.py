#!/usr/bin/env python3
"""One reviewed activation using ONLY the existing V2 role/document/bucket.
Attempts the owner-authorized fixed-tool installation, not an arbitrary permission
probe. AccessDenied is returned with exact action/resource; never self-edits IAM.
"""
import importlib.util
import json
import os
from pathlib import Path
import time
ROOT=Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('release_client',ROOT/'release-client.py');c=importlib.util.module_from_spec(s);s.loader.exec_module(c)
RESOURCE='arn:aws:ssm:us-east-1:429724121147:document/PPB-V2-Controlled-Deploy'
ROLE='arn:aws:iam::429724121147:role/PPB-V2-GitHub-Deploy-Role'

def activate(call=c.aws,pause=time.sleep):
    result=dict(result='STOP',role=ROLE,resource=RESOURCE,ssm_command_id=None,stage='IDENTITY',database_isolation='UNVERIFIED')
    raw_call=call
    def call(*args):
        names={'get-document':'GetDocument','describe-document':'DescribeDocument','update-document':'UpdateDocument','send-command':'SendCommand','get-command-invocation':'GetCommandInvocation','list-document-versions':'ListDocumentVersions','update-document-default-version':'UpdateDocumentDefaultVersion','delete-document':'DeleteDocument'}
        if args[0]=='ssm':result['last_aws_action']='ssm:'+names.get(args[1],args[1])
        return raw_call(*args)
    out=Path('v2-result');out.mkdir(exist_ok=True)
    def save():
        p=out/'activation.pending';p.write_text(json.dumps(result,indent=2));p.replace(out/'activation.json')
    def content(version):return call('ssm','get-document','--name',c.DOC,'--document-version',version,'--document-format','JSON')
    def install_version(value):
        try:
            latest=content('$LATEST')
            if json.loads(latest['Content'])==value:return latest['DocumentVersion']
        except RuntimeError as exc:result['document_read_before_install']=str(exc)
        # A one-time administrator can preload both reviewed versions; daily
        # activation should reuse them rather than request UpdateDocument again.
        try:
            token=None
            for _ in range(20):
                args=['ssm','list-document-versions','--name',c.DOC]
                if token:args+=['--next-token',token]
                page=call(*args)
                for item in page.get('DocumentVersions',[]):
                    candidate=content(item['DocumentVersion'])
                    if json.loads(candidate['Content'])==value:return item['DocumentVersion']
                token=page.get('NextToken')
                if not token:break
        except RuntimeError as exc:result['version_read_before_install']=str(exc)
        # Authorized bounded write: create a reviewed non-default version only.
        result.update(stage='CREATE_REVIEWED_VERSION',required_action='ssm:UpdateDocument');save()
        value_path=out/'reviewed-document.json';value_path.write_text(json.dumps(value))
        response=call('ssm','update-document','--name',c.DOC,'--document-version','$LATEST','--document-format','JSON','--content','file://'+str(value_path.resolve()))
        version=response['DocumentDescription']['DocumentVersion']
        for _ in range(30):
            meta=call('ssm','describe-document','--name',c.DOC,'--document-version',version)['Document']
            if meta['Status']=='Active':break
            c.need(meta['Status'] not in {'Failed','Deleting'},'DOCUMENT_INSTALL_FAILED');pause(2)
        else:raise RuntimeError('DOCUMENT_ACTIVATION_TIMEOUT')
        c.need(json.loads(content(version)['Content'])==value,'DOCUMENT_READBACK_MISMATCH')
        return version
    def quarantine(bootstrap,version):
        # No SQL/OS service writes: retain only this reviewed, no-input bootstrap
        # while runtime certification is pending. Archive ALL prior bytes first.
        import hashlib
        versions=[];token=None
        for _ in range(20):
            args=['ssm','list-document-versions','--name',c.DOC]
            if token:args+=['--next-token',token]
            page=call(*args)
            for item in page.get('DocumentVersions',[]):
                prior=content(item['DocumentVersion'])
                if json.loads(prior['Content'])!=bootstrap:versions.append((item['DocumentVersion'],prior['Content']))
            token=page.get('NextToken')
            if not token:break
        else:raise RuntimeError('QUARANTINE_SCAN_LIMIT')
        result['quarantine_archives']=[];save()
        for old,raw in versions:
            path=out/'prior-document.json';path.write_text(raw)
            digest=hashlib.sha256(raw.encode()).hexdigest()
            key='releases/ssm-history/'+os.environ['GITHUB_RUN_ID']+'-attempt-'+os.environ.get('GITHUB_RUN_ATTEMPT','1')+'/version-'+old+'-'+digest+'.json'
            call('s3api','put-object','--bucket',c.BUCKET,'--key',key,'--body',str(path),'--if-none-match','*','--server-side-encryption','AES256')
            result['quarantine_archives'].append(dict(version=old,sha256=digest,key=key));save()
        if json.loads(content('$DEFAULT')['Content'])!=bootstrap:
            call('ssm','update-document-default-version','--name',c.DOC,'--document-version',version)
        c.need(json.loads(content('$DEFAULT')['Content'])==bootstrap,'QUARANTINE_DEFAULT_NOT_VERIFIED')
        result['retired_versions']=[];save()
        for old,_ in versions:
            call('ssm','delete-document','--name',c.DOC,'--document-version',old)
            result['retired_versions'].append(old);save()
        token=None;kept=[]
        for _ in range(20):
            args=['ssm','list-document-versions','--name',c.DOC]
            if token:args+=['--next-token',token]
            page=call(*args)
            for item in page.get('DocumentVersions',[]):
                c.need(json.loads(content(item['DocumentVersion'])['Content'])==bootstrap,'UNREVIEWED_CALLABLE_VERSION_REMAINS')
                kept.append(item['DocumentVersion'])
            token=page.get('NextToken')
            if not token:break
        else:raise RuntimeError('QUARANTINE_RECHECK_LIMIT')
        result.update(callable_versions=kept,quarantine_default_version=version,unsafe_prior_versions_callable=False)
        save()

    try:
        identity=call('sts','get-caller-identity');c.need(identity['Account']==c.ACCOUNT,'WRONG_ACCOUNT')
        result['caller_arn']=identity['Arn'];save()
        bootstrap=json.loads((ROOT/'PPB-V2-Controlled-Deploy.bootstrap.ssm.json').read_text())
        runtime=json.loads((ROOT/'PPB-V2-Controlled-Deploy.isolated.ssm.json').read_text())
        version=install_version(bootstrap);result['bootstrap_document_version']=version;save()
        meta=call('ssm','describe-document','--name',c.DOC,'--document-version',version)['Document']
        c.need(meta['HashType']=='Sha256','DOCUMENT_HASH_TYPE_INVALID')
        result.update(stage='INSTALL_INTENT',required_action='ssm:SendCommand');save()
        recovery=os.environ.get('PPB_BOOTSTRAP_RECOVERY_COMMAND_ID','')
        if recovery:
            import re
            c.need(re.fullmatch('[a-f0-9-]{36}',recovery),'RECOVERY_COMMAND_INVALID')
            command=recovery
        else:
            sent=call('ssm','send-command','--document-name',c.DOC,'--document-version',version,
                      '--document-hash',meta['Hash'],'--document-hash-type','Sha256','--instance-ids',c.INSTANCE,
                      '--max-concurrency','1','--max-errors','0','--timeout-seconds','120',
                      '--comment','Reviewed V2 bootstrap '+os.environ['GITHUB_RUN_ID'])
            command=sent['Command']['CommandId']
        result['ssm_command_id']=command;save();print('SSM_COMMAND_ID='+command,flush=True)
        for _ in range(200):
            try:inv=call('ssm','get-command-invocation','--command-id',command,'--instance-id',c.INSTANCE,'--plugin-name','installReviewedTools')
            except RuntimeError as exc:
                if str(exc)=='AWS_InvalidPluginName':
                    # A failed earlier plugin can mean the install plugin never exists.
                    result['pre_install_plugins']={}
                    for plugin in ['prepareFixedTools','downloadReviewedTools']:
                        try:
                            prior=call('ssm','get-command-invocation','--command-id',command,'--instance-id',c.INSTANCE,'--plugin-name',plugin)
                            result['pre_install_plugins'][plugin]={key:prior.get(key) for key in ['Status','ResponseCode','StandardOutputContent','StandardErrorContent']}
                            if prior['Status'] in {'Failed','Cancelled','TimedOut'}:
                                raise RuntimeError('BOOTSTRAP_PREREQUISITE_FAILED')
                        except RuntimeError as earlier:
                            if str(earlier) not in {'AWS_InvalidPluginName','AWS_InvocationDoesNotExist'}:raise
                    save();pause(3);continue
                if str(exc)=='AWS_InvocationDoesNotExist':pause(3);continue
                raise
            if inv['Status'] in {'Pending','InProgress','Delayed'}:pause(3);continue
            provisions=[line.split('=',1)[1] for line in inv.get('StandardOutputContent','').splitlines() if line.startswith('V2_PROVISION=')]
            if provisions:result['service_provision']=json.loads(provisions[-1]);save()
            c.need(inv['Status']=='Success' and inv['ResponseCode']==0,'FIXED_TOOL_INSTALL_FAILED')
            output=inv.get('StandardOutputContent','');c.need('V2_FIXED_TOOLS_INSTALLED=YES' in output,'TOOL_INSTALL_RECEIPT_MISSING')
            rows=[line.split('=',1)[1] for line in output.splitlines() if line.startswith('V2_INVENTORY=')]
            c.need(len(rows)==1,'INVENTORY_RECEIPT_MISSING')
            inventory=json.loads(rows[0]);result.update(inventory=inventory,fixed_tools_installed=True)
            if inventory.get('result')!='PASS':
                quarantine(bootstrap,version)
                result.update(result='TOOLS_INSTALLED_ISOLATION_PENDING',stage='SQL_OS_BOUNDARY_REQUIRED');save();return result
            c.need(inventory.get('isolation',{}).get('legacy_database_write') is False,'LEGACY_DATABASE_WRITE_NOT_EXCLUDED')
            result['database_isolation']='VERIFIED';break
        else:raise RuntimeError('BOOTSTRAP_OUTCOME_UNKNOWN_DO_NOT_RESEND')
        # Only an actually certified SQL/OS boundary can activate the runtime.
        runtime_version=install_version(runtime)
        if json.loads(content('$DEFAULT')['Content'])!=runtime:
            result.update(stage='SET_REVIEWED_DEFAULT',required_action='ssm:UpdateDocumentDefaultVersion');save()
            call('ssm','update-document-default-version','--name',c.DOC,'--document-version',runtime_version)
        c.need(json.loads(content('$DEFAULT')['Content'])==runtime,'DEFAULT_READBACK_MISMATCH')
        versions=[];token=None
        for _ in range(20):
            args=['ssm','list-document-versions','--name',c.DOC]
            if token:args+=['--next-token',token]
            page=call(*args);versions.extend(page.get('DocumentVersions',[]));token=page.get('NextToken')
            if not token:break
        else:raise RuntimeError('DOCUMENT_VERSION_SCAN_LIMIT')
        result['retired_versions']=[]
        for item in versions:
            old=item['DocumentVersion'];raw=content(old)['Content'];value=json.loads(raw)
            if value in [bootstrap,runtime]:continue
            # Archive bytes privately before retiring an unsafe callable version.
            p=out/'prior-document.json';p.write_text(raw)
            key='releases/ssm-history/'+os.environ['GITHUB_RUN_ID']+'/version-'+old+'.json'
            call('s3api','put-object','--bucket',c.BUCKET,'--key',key,'--body',str(p),'--if-none-match','*','--server-side-encryption','AES256')
            result.update(stage='RETIRE_UNSAFE_VERSION',required_action='ssm:DeleteDocument');save()
            call('ssm','delete-document','--name',c.DOC,'--document-version',old)
            result['retired_versions'].append(old);save()
        token=None
        for _ in range(20):
            args=['ssm','list-document-versions','--name',c.DOC]
            if token:args+=['--next-token',token]
            page=call(*args)
            for item in page.get('DocumentVersions',[]):c.need(json.loads(content(item['DocumentVersion'])['Content']) in [bootstrap,runtime],'UNREVIEWED_VERSION_REMAINS')
            token=page.get('NextToken')
            if not token:break
        else:raise RuntimeError('DOCUMENT_VERSION_RECHECK_LIMIT')
        c.need(json.loads(content('$DEFAULT')['Content'])==runtime,'RUNTIME_DEFAULT_CHANGED')
        result.update(result='ISOLATED_EXECUTOR_ACTIVATED',stage='VERIFIED',runtime_version=runtime_version)
        save();return result
    except Exception as exc:
        import re
        code=str(exc);result['reason']=code if re.fullmatch('[A-Za-z0-9_]{1,160}',code) else 'ACTIVATION_NOT_VERIFIED'
        result['failed_action']=result.get('last_aws_action');save();return result

def main():
    result=activate();print(json.dumps(result))
    return 0 if result['result']=='ISOLATED_EXECUTOR_ACTIVATED' else 2
if __name__=='__main__':raise SystemExit(main())
