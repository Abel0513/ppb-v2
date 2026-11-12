#!/usr/bin/env python3
"""Fixed EC2 acceptance: local TLS/SNI, guest access boundaries, release lineage.
No SQL, Artisan, WP, LINE or dynamic test commands. Not business acceptance.
"""
import http.client
import json
from pathlib import Path
import re
import socket
import ssl
import sys

HOST='v2.csdatw.xyz'
class LocalHTTPS(http.client.HTTPSConnection):
    def connect(self):
        raw=socket.create_connection(('127.0.0.1',443),timeout=15)
        self.sock=ssl.create_default_context().wrap_socket(raw,server_hostname=HOST)

def verify(commit, state, get):
    if not re.fullmatch('[0-9a-f]{40}',commit) or state.get('commit')!=commit:
        raise ValueError('RELEASE_MISMATCH')
    login_status, login_headers, body=get('/admin/login')
    if login_status!=200 or b'name="password"' not in body or b'name="username"' not in body:
        raise ValueError('LOGIN_FORM_NOT_RENDERED')
    for path in ['/admin/games','/admin/manuscripts','/admin/accounts']:
        code, headers, _=get(path)
        if code!=302 or headers.get('location') not in ('/admin/login','https://'+HOST+'/admin/login'):
            raise ValueError('GUEST_ACCESS_BOUNDARY_FAILED')
    return {'RESULT':'PPB_V2_FUNCTIONAL_BASELINE_PASS','COMMIT':commit,'CHECKS':4,
            'BUSINESS_WP_DB_LIFF_ACCEPTANCE':'NOT_RUN'}

def get(path):
    conn=LocalHTTPS(HOST,timeout=15)
    try:
        conn.request('GET',path,headers={'Host':HOST,'Cache-Control':'no-cache'})
        response=conn.getresponse()
        return response.status, {k.lower():v for k,v in response.getheaders()},response.read(1_000_000)
    finally: conn.close()

if __name__=='__main__':
    try:
        state=json.loads(Path('/www/ppb-v2-deploy/current-release.json').read_text())
        print(json.dumps(verify(sys.argv[1],state,get)))
    except Exception:
        print('{"RESULT":"STOP","REASON":"EC2_ACCEPTANCE_FAILED"}')
        sys.exit(1)
