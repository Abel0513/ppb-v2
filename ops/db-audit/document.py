#!/usr/bin/env python3
"""Read-only fixed SSM diagnostic, same existing V2 document/resource; no remote installation."""
import base64
import json
from pathlib import Path
ROOT=Path(__file__).resolve().parent

def document():
    import hashlib
    body=(ROOT/'procedure.body.sql').read_text().rstrip()
    source=(ROOT/'probe.php').read_text().replace('EXPECTED_PROCEDURE_CRLF_SHA256',hashlib.sha256(body.replace('\n','\r\n').encode()).hexdigest()).replace('EXPECTED_PROCEDURE_SHA256',hashlib.sha256(body.encode()).hexdigest())
    php=base64.b64encode(source.encode().split(b'<?php',1)[1]).decode()
    # Existing reviewed .env parser only; never source or include app PHP.
    code=r'''import base64,hashlib,importlib.util,json,os,stat,subprocess
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
# Inspect only the two V2 vhosts and their reviewed cutover backup, never env backup.
policy=b.private_json(root/'policy/isolation.json')
receipt=b.private_json(root/'v2-service-isolation.json')
import re
name=receipt['backup'];assert re.fullmatch(r'v2-fpm-cutover-[0-9]+',name)
spec=importlib.util.spec_from_file_location('fixed_provision',root/'scripts/isolation/provision.py');v=importlib.util.module_from_spec(spec);spec.loader.exec_module(v)
files=[];expected_hashes={}
for i,path in enumerate(v.VHOSTS):
    old=(root/'backups'/name/('original-'+str(i))).read_text()
    expected=v.flatten(old);current=v.checked_file(path)
    expected_hashes[str(path)]=hashlib.sha256(expected.encode()).hexdigest()
    # Report only directive classes, not raw configuration/headers/certificate data.
    routing={'root_is_v2':bool(re.search(r'\broot\s+/www/wwwroot/ppbaos\.com/public\s*;',current)),
             'dedicated_socket':current.count('fastcgi_pass unix:/www/wwwroot/ppbaos.com/storage/run/php-fpm.sock;'),
             'shared_php_include':'enable-php-84.conf' in current,
             'front_controller':'/index.php?$query_string' in current}
    strip=lambda text:'\n'.join(line.strip() for line in text.splitlines() if line.strip() and not line.lstrip().startswith('#'))
    files.append({'path':str(path),'current_sha256':hashlib.sha256(current.encode()).hexdigest(),'expected_sha256':expected_hashes[str(path)],'same_bytes':current==expected,'same_noncomment_lines':strip(current)==strip(expected),'routing':routing})
print('V2_VHOST_STATE='+json.dumps({'policy_fingerprint':policy['vhost_sha256'],'actual_fingerprint':v.l.vhost_digest(),'expected_reconstructed_fingerprint':hashlib.sha256(json.dumps(expected_hashes,sort_keys=True).encode()).hexdigest(),'files':files}))

'''.replace('PHP_SOURCE',php)
    return dict(schemaVersion='2.2',description='Fixed metadata-only V2 audit; no SQL input, business data, DDL/DML, installation or service writes',parameters={},mainSteps=[dict(action='aws:runShellScript',name='fixedMetadataAudit',inputs=dict(timeoutSeconds='90',runCommand=['set -eu','umask 077',"python3 - <<'FIXED_METADATA'",*code.splitlines(),'FIXED_METADATA']))])
if __name__=='__main__':print(json.dumps(document(),indent=2))
