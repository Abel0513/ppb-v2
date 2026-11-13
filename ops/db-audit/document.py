#!/usr/bin/env python3
"""Read-only fixed SSM diagnostic, same existing V2 document/resource; no remote installation."""
import base64
import json
from pathlib import Path
ROOT=Path(__file__).resolve().parent

def document():
    import hashlib
    body=(ROOT/'procedure.body.sql').read_text().rstrip()
    source=(ROOT/'probe.php').read_text().replace('EXPECTED_PROCEDURE_SHA256',hashlib.sha256(body.encode()).hexdigest())
    php=base64.b64encode(source.encode().split(b'<?php',1)[1]).decode()
    # Existing reviewed .env parser only; never source or include app PHP.
    code='''import base64,hashlib,importlib.util,json,os,stat,subprocess
from pathlib import Path
assert os.geteuid()==0
root=Path('/www/ppb-v2-deploy')
source=root/'scripts/isolation/boundary.py'
assert source.is_file() and not source.is_symlink() and source.stat().st_uid==0 and source.stat().st_mode&0o077==0
spec=importlib.util.spec_from_file_location('fixed_boundary',source);b=importlib.util.module_from_spec(spec);spec.loader.exec_module(b)
path=Path('/www/wwwroot/ppbaos.com/.env')
env=b.read_env(path,hashlib.sha256(path.read_bytes()).hexdigest(),require_low_privilege=False)
assert env['DB_DATABASE']=='ppbaos' and env['DB_USERNAME']=='ppbaos'
inputs={'v2':env}
metadata=root/'policy/metadata-connection.json'
# Only this explicitly provisioned V2 management file, no credential discovery.
if metadata.exists():inputs['metadata']=b.private_json(metadata)
p=subprocess.run(['/www/server/php/84/bin/php','-r',base64.b64decode('PHP_SOURCE').decode()],input=json.dumps(inputs),capture_output=True,text=True,timeout=45)
try:report=json.loads(p.stdout)
except Exception:report={'result':'STOP','reason':'METADATA_OUTPUT_INVALID'}
print('V2_METADATA='+json.dumps(report))
'''.replace('PHP_SOURCE',php)
    return dict(schemaVersion='2.2',description='Fixed metadata-only V2 audit; no SQL input, business data, DDL/DML, installation or service writes',parameters={},mainSteps=[dict(action='aws:runShellScript',name='fixedMetadataAudit',inputs=dict(timeoutSeconds='90',runCommand=['set -eu','umask 077',"python3 - <<'FIXED_METADATA'",*code.splitlines(),'FIXED_METADATA']))])
if __name__=='__main__':print(json.dumps(document(),indent=2))
