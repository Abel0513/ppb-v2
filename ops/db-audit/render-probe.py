#!/usr/bin/env python3
import hashlib
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parent
body=(ROOT/'procedure.body.sql').read_text().rstrip()
source=(ROOT/'probe.php').read_text().replace('EXPECTED_PROCEDURE_CRLF_SHA256',hashlib.sha256(body.replace('\n','\r\n').encode()).hexdigest()).replace('EXPECTED_PROCEDURE_SHA256',hashlib.sha256(body.encode()).hexdigest())
p=ROOT.parent/'deploy/isolation/global-metadata.php'
if '--check' in sys.argv:assert p.read_text()==source
else:p.write_text(source)

lexer=ROOT.parent/'deploy/isolation/nginx_lex.py'
if '--check' in sys.argv:assert lexer.read_text()==(ROOT/'nginx_lex.py').read_text()
else:lexer.write_text((ROOT/'nginx_lex.py').read_text())
