#!/usr/bin/env python3
"""Build the self-contained EC2 command payload without credentials or network."""
import base64
import hashlib
import io
from pathlib import Path
import sys
import zipfile

root = Path(__file__).resolve().parent
target = root.parents[1] / 'ops/wp-test/ppb_wpn2_verified.sh'
files = sorted(p for p in root.iterdir() if p.is_file() and p.name != 'SHA256SUMS')
manifest = ''.join(hashlib.sha256(p.read_bytes()).hexdigest() + '  ' + p.name + '\n' for p in files)
if '--check' in sys.argv:
    assert (root / 'SHA256SUMS').read_text() == manifest, 'manifest differs'
else:
    (root / 'SHA256SUMS').write_text(manifest)
files.append(root / 'SHA256SUMS')
buf = io.BytesIO()
with zipfile.ZipFile(buf, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for p in sorted(files):
        info = zipfile.ZipInfo(p.name, date_time=(2026, 11, 13, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o600 << 16
        z.writestr(info, p.read_bytes())
payload = buf.getvalue()
digest = hashlib.sha256(payload).hexdigest()
header = r'''#!/usr/bin/env bash
# WPN2: one new Expert draft; original WPF6 connector; no existing article writes.
set -euo pipefail
umask 077
WPN2_TMP="$(mktemp -d /var/tmp/ppb-wpn2-package.XXXXXXXX)"
trap 'rm -rf -- "$WPN2_TMP"; echo "WPN2_REPORT=/var/tmp/ppb-codex-issue12-new-expert-v1/report.json"' EXIT
python3 - "$0" "$WPN2_TMP" <<'PY_EXTRACT'
import base64, hashlib, io, pathlib, sys, zipfile
source = pathlib.Path(sys.argv[1]).read_bytes()
payload = base64.b64decode(source.split(b'\n__WPN2_PAYLOAD__\n', 1)[1])
assert hashlib.sha256(payload).hexdigest() == 'DIGEST', 'STOP: PACKAGE_SHA_MISMATCH'
with zipfile.ZipFile(io.BytesIO(payload)) as z:
    for info in z.infolist():
        assert '/' not in info.filename and '\\' not in info.filename and not info.filename.startswith('.'), 'STOP: PACKAGE_PATH'
        p = pathlib.Path(sys.argv[2]) / info.filename
        p.write_bytes(z.read(info))
        p.chmod(0o600)
PY_EXTRACT
bash "$WPN2_TMP/run.sh"
exit 0
__WPN2_PAYLOAD__
'''.replace('DIGEST', digest)
result = header + base64.encodebytes(payload).decode()
if '--check' in sys.argv:
    assert target.read_text() == result, 'package differs'
    print('WPN2_PACKAGE_REPRODUCIBLE_PASS')
else:
    target.write_text(result)
    print('WPN2_PACKAGE_SHA256=' + hashlib.sha256(result.encode()).hexdigest())
