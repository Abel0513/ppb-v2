"""Fixed SSM entry: data-only S3 requests; sanitized facts returned to Actions."""
import base64
import fcntl
import io
import json
import os
from pathlib import Path
import subprocess
import stat
import tempfile
import zipfile

PREFIX='PPB_WP_PARAMETERIZED_V1='

def main(bundle):
    import engine as e
    execution={k:os.environ['SSM_'+v] for k,v in {'operation':'Operation','commit':'Commit','run_id':'RunId','run_attempt':'RunAttempt','request_id':'RequestId'}.items()}
    result=dict(schema_version=1,execution=execution,result='STOP')
    try:
        path=Path('/www/ppb-v2-deploy/wp-test')/('parameterized-'+execution['run_id']+'-'+execution['run_attempt'])/'request.json'
        request=e.load(path)
        e.need(set(request)=={'execution','request'} and request['execution']==execution,'EXECUTION_MISMATCH')
        req=e.validate(request['request'])
        e.need(req['operation']==execution['operation'] and req['request_id']==execution['request_id'],'REQUEST_BINDING_MISMATCH')
        # Never create/delete/replace the original journal or lock.
        lock=os.open('/var/tmp/ppb-codex-issue12-new-expert-v1/lock',os.O_RDONLY|os.O_NOFOLLOW)
        try:
            st=os.fstat(lock)
            e.need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_mode&0o077==0,'ORIGINAL_LOCK_UNSAFE')
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with tempfile.TemporaryDirectory(prefix='ppb-wp-param-',dir='/var/tmp') as temp:
                root=Path(temp)
                with zipfile.ZipFile(io.BytesIO(base64.b64decode(bundle,validate=True))) as z:
                    for n in z.namelist():
                        e.need(n in {'worker.php','run.php','legacy.php','parser.php'},'FIXED_BUNDLE_INVALID')
                        (root/n).write_bytes(z.read(n));(root/n).chmod(0o600)
                def rpc(op,fields):
                    p=subprocess.run(['/www/server/php/84/bin/php',str(root/'worker.php')],
                        input=json.dumps(dict(operation=op,fields=fields),ensure_ascii=False),
                        capture_output=True,text=True,timeout=240)
                    data=json.loads(p.stdout)
                    e.need(p.returncode==0 and data.get('ok') is True,data.get('stop_code','WORKER_FAILED'))
                    return data['data']
                state=Path('/var/tmp/ppb-codex-issue12-acf-requests-v1')
                # This separate update ledger is private; original journal untouched.
                if not state.exists():state.mkdir(mode=0o700)
                value=e.process(req,state,rpc)
                if 'fields' in value:
                    value['fields']={k:v for k,v in value['fields'].items() if k in e.FIELDS}
                result.update(value)
        finally:os.close(lock)
    except Exception as exc:
        code=str(exc)
        import re
        result['stop_code']=code if re.fullmatch('[A-Z0-9_]{1,120}',code) else 'PARAMETERIZED_EXECUTION_FAILED'
    print(PREFIX+json.dumps(result,ensure_ascii=False,separators=(',',':')))
    return 0 if result['result'] in {'PASS','READ_VERIFIED'} else 2
