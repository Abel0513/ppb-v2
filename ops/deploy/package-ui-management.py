#!/usr/bin/env python3
"""Deterministic, credential-free management handoff; never calls AWS."""
import argparse,hashlib,json,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parent
NAMES=['manage-ui-phase-a.py','activate-isolation.py','release-client.py','PPB-V2-Controlled-Deploy.bootstrap.ssm.json','PPB-V2-Controlled-Deploy.isolated.ssm.json','UI_MANAGEMENT_BATCH.md']
def build(output):
 files={name:(ROOT/name).read_bytes() for name in NAMES}
 manifest={'format':'PPB_UI_PHASE_A_MANAGEMENT_V1','source_main':'5c5b533e9825d474743e1ed5e590c1410446fe7f','files':{name:hashlib.sha256(data).hexdigest() for name,data in files.items()}}
 files['SHA256.json']=(json.dumps(manifest,sort_keys=True,indent=2)+'\n').encode()
 with zipfile.ZipFile(output,'w',compression=zipfile.ZIP_DEFLATED) as z:
  for name,data in sorted(files.items()):
   info=zipfile.ZipInfo(name,(1980,1,1,0,0,0));info.external_attr=0o100600<<16;info.compress_type=zipfile.ZIP_DEFLATED;z.writestr(info,data)
 return hashlib.sha256(Path(output).read_bytes()).hexdigest()
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('output',type=Path);a=p.parse_args();print('MANAGEMENT_PACKET_SHA256='+build(a.output))
