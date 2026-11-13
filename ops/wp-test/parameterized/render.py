#!/usr/bin/env python3
"""Render candidate fixed operations. Does not install or change the active v1."""
import base64
import io
import json
from pathlib import Path
import sys
import zipfile
ROOT=Path(__file__).resolve().parent

def document():
    sources={'worker.php':(ROOT/'worker.php').read_bytes()}
    sources.update({n:(ROOT.parent/'interface/runtime'/n).read_bytes() for n in ['run.php','legacy.php','parser.php']})
    b=io.BytesIO()
    with zipfile.ZipFile(b,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for n,data in sorted(sources.items()):
            i=zipfile.ZipInfo(n,(2026,11,13,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;z.writestr(i,data)
    payload=base64.b64encode(b.getvalue()).decode()
    engine=base64.b64encode((ROOT/'engine.py').read_bytes()).decode()
    # Preinstalled code only: no S3 code, module paths, target URLs or shell input.
    code="import types,sys,base64\ne=types.ModuleType('engine');sys.modules['engine']=e\nexec(base64.b64decode("+repr(engine)+"),e.__dict__)\n"+(ROOT/'entry.py').read_text()+"\nraise SystemExit(main("+repr(payload)+"))\n"
    p={k:dict(type='String',interpolationType='ENV_VAR',allowedPattern=v) for k,v in {
        'Commit':'^[a-f0-9]{40}$','RunId':'^[1-9][0-9]{0,19}$','RunAttempt':'^[1-9][0-9]{0,19}$',
        'RequestId':'^[a-zA-Z0-9][a-zA-Z0-9_-]{7,79}$'}.items()}
    p['Operation']=dict(type='String',interpolationType='ENV_VAR',allowedValues=['read','update_acf','verify'])
    return dict(schemaVersion='2.2',description='Fixed WP ACF operations; one authorized Expert, one field, separate idempotency ledger; no create',parameters=p,mainSteps=[
        dict(action='aws:runShellScript',name='prepareParameterized',inputs=dict(timeoutSeconds='30',runCommand=[
            'set -eu','umask 077','test "$(id -u)" = 0',
            'test -d /www/ppb-v2-deploy && test ! -L /www/ppb-v2-deploy',
            'test -d /www/ppb-v2-deploy/wp-test && test ! -L /www/ppb-v2-deploy/wp-test',
            'p="/www/ppb-v2-deploy/wp-test/parameterized-${SSM_RunId}-${SSM_RunAttempt}"','test ! -e "$p"','install -d -m 700 "$p"'])),
        dict(action='aws:downloadContent',name='downloadRequestOnly',inputs=dict(sourceType='S3',sourceInfo=json.dumps(dict(path='https://s3.us-east-1.amazonaws.com/ppb-v2-release-artifacts-429724121147-use1/wp-test/parameterized/{{Commit}}/{{RunId}}-{{RunAttempt}}/request.json')),destinationPath='/www/ppb-v2-deploy/wp-test/parameterized-{{RunId}}-{{RunAttempt}}')),
        dict(action='aws:runShellScript',name='parameterizedWp',inputs=dict(timeoutSeconds='1200',runCommand=[
            'set -eu','umask 077','chmod 600 "/www/ppb-v2-deploy/wp-test/parameterized-${SSM_RunId}-${SSM_RunAttempt}/request.json"',"python3 - <<'PPB_FIXED'",*code.splitlines(),'PPB_FIXED']))])
if __name__=='__main__':
    data=json.dumps(document(),ensure_ascii=False,indent=2)+'\n'
    assert len(data.encode())<64000,'SSM size limit'
    target=ROOT/'document.json'
    if '--check' in sys.argv:assert target.read_text()==data
    else:target.write_text(data)
