#!/usr/bin/env python3
"""Build reviewed fixed-tool artifact only. No install, credentials or remote execution."""
import argparse
import hashlib
import gzip
import io
import json
from pathlib import Path
import tarfile
ROOT=Path(__file__).resolve().parent
FILES={
    **{'scripts/legacy-readonly/'+n:ROOT.parents[1]/'legacy-readonly'/n for n in ['prepare.py','dispatch.py','worker.py','source-tokens.php','db-worker.php','dba-once.py','management-probe.py','dba-authenticated-once.py','evaluation-domains.py','bridge.py','bridge-health.py','bridge-install.py','evaluation-snapshot.php']},
    **{'scripts/legacy-readonly/offline-ui/'+n:ROOT.parents[1]/'legacy-readonly'/'offline-ui'/n for n in ['structure.py','php-inline.php']},
    'units/metadata-delegation.sql':ROOT.parents[1]/'db-audit/DBA_ONCE.mysql57.sql',
    **{'units/'+n:ROOT/'units'/n for n in ['ppb-v2-php.service','php-fpm.conf','ppb-v2-queue.service','ppb-v2-scheduler.service','ppb-v2-scheduler.timer']},
    **{'scripts/isolation/'+n:ROOT/n for n in ['boundary.py','nginx_lex.py','socket-acl.py','provision.py','global-metadata.php','prepare-os.py','inventory-db.php','db-probe.php','lifecycle.py','release.py']},
    **{'scripts/'+n:ROOT.parent/n for n in ['controlled-deploy.py','stage-release.py','check-release.py']},
    'automation/accept-v2.py':ROOT.parents[1]/'automation/accept-v2.py',
}
def build(path):
    manifest={n:hashlib.sha256(p.read_bytes()).hexdigest() for n,p in FILES.items()}
    raw=io.BytesIO()
    with tarfile.open(fileobj=raw,mode='w') as archive:
        for name,source in sorted(FILES.items()):
            data=source.read_bytes();i=tarfile.TarInfo(name);i.mode=0o600;i.mtime=0
            i.size=len(data);archive.addfile(i,io.BytesIO(data))
        data=json.dumps(manifest,sort_keys=True).encode();i=tarfile.TarInfo('SHA256.json');i.size=len(data);i.mode=0o600;i.mtime=0
        archive.addfile(i,io.BytesIO(data))
    with path.open('wb') as stream:
        with gzip.GzipFile(filename='',fileobj=stream,mode='wb',mtime=0) as compressed:compressed.write(raw.getvalue())
    print('REVIEWED_FIXED_TOOLS_SHA256='+hashlib.sha256(path.read_bytes()).hexdigest())
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('output',type=Path);a=p.parse_args();build(a.output)
