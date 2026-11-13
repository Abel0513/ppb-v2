#!/usr/bin/env python3
"""Build reviewed fixed executor. Run ONLY during reviewed interface promotion."""
import base64
import io
import json
from pathlib import Path
import sys
import zipfile
ROOT = Path(__file__).resolve().parent

def document():
    buf = io.BytesIO()
    sources = {name:(ROOT/'runtime'/name).read_bytes() for name in ['run.php','legacy.php','parser.php']}
    sources.update({'inspect.php':(ROOT/'inspect.php').read_bytes(), 'collector.py':(ROOT.parent/'result-envelope.py').read_bytes()})
    with zipfile.ZipFile(buf,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for name, data in sorted(sources.items()):
            i=zipfile.ZipInfo(name, date_time=(2026,11,13,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED
            z.writestr(i,data)
    payload=base64.b64encode(buf.getvalue()).decode()
    code=(ROOT/'executor.py').read_text()+'\nraise SystemExit(main('+repr(payload)+'))\n'
    params={k:dict(type='String',interpolationType='ENV_VAR',allowedPattern=p) for k,p in {
        'Commit':'^[a-f0-9]{40}$','RunId':'^[1-9][0-9]{0,19}$','RunAttempt':'^[1-9][0-9]{0,19}$'}.items()}
    params['Operation']=dict(type='String',interpolationType='ENV_VAR',allowedValues=['journal','resume'])
    base='/www/ppb-v2-deploy/wp-test/interface-{{RunId}}-{{RunAttempt}}'
    return dict(schemaVersion='2.2',description='Reviewed fixed WP interface v1; S3 JSON data only; no create operation', parameters=params,mainSteps=[
        dict(action='aws:runShellScript',name='prepareInterface',inputs=dict(timeoutSeconds='30',runCommand=[
            'set -eu','umask 077','test "$(id -u)" = 0',
            'test -d /www/ppb-v2-deploy && test ! -L /www/ppb-v2-deploy',
            'test ! -L /www/ppb-v2-deploy/wp-test',
            'install -d -m 700 /www/ppb-v2-deploy/wp-test',
            'p="/www/ppb-v2-deploy/wp-test/interface-${SSM_RunId}-${SSM_RunAttempt}"',
            'test ! -e "$p"','install -d -m 700 "$p"'])),
        dict(action='aws:downloadContent',name='downloadDataOnly',inputs=dict(sourceType='S3',sourceInfo=json.dumps(dict(path='https://s3.us-east-1.amazonaws.com/ppb-v2-release-artifacts-429724121147-use1/wp-test/interface/{{Commit}}/{{RunId}}-{{RunAttempt}}/request.json')),destinationPath=base)),
        dict(action='aws:runShellScript',name='fixedWpInterface',inputs=dict(timeoutSeconds='780',runCommand=[
            'set -eu','umask 077','chmod 600 "/www/ppb-v2-deploy/wp-test/interface-${SSM_RunId}-${SSM_RunAttempt}/request.json"',
            "python3 - <<'PPB_FIXED'",*code.splitlines(),'PPB_FIXED']))])

if __name__=='__main__':
    data=json.dumps(document(),ensure_ascii=False,indent=2)+'\n'
    assert len(data.encode())<64000, 'SSM document size limit'
    path=ROOT/'document.json'
    if '--check' in sys.argv: assert path.read_text()==data
    else: path.write_text(data)
