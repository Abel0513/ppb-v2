#!/usr/bin/env bash
set -euo pipefail
umask 077
printf '%s\n' '使用現有 AWS 管理員身分。只填既有 DBA 連線名稱／檔案路徑，不填密碼。'
read -r -p '連線選擇（login:既有名稱 或 file:/root/既有私有MySQL設定檔）: ' PPB_DBA_HANDLE
python3 - "$PPB_DBA_HANDLE" <<'PPB_ADMIN'
import json,re,shlex,subprocess,sys,time
ACCOUNT='429724121147';REGION='us-east-1';INSTANCE='i-0135d6b2fac7a54c2'
def need(ok,code):
    if not ok:raise RuntimeError(code)
def aws(*args):
    p=subprocess.run(['aws',*args,'--region',REGION,'--output','json','--no-cli-pager'],capture_output=True,text=True,timeout=90)
    if p.returncode:
        m=re.search(r'\(([A-Za-z0-9_.-]+)\)',p.stderr);raise RuntimeError('AWS_'+(m.group(1) if m else 'CLI_FAILED'))
    return json.loads(p.stdout or '{}')
try:
    actor=aws('sts','get-caller-identity');need(actor['Account']==ACCOUNT,'AWS_ACCOUNT_MISMATCH')
    need(not any('/'+r+'/' in actor['Arn'] for r in ['PPB-V2-GitHub-Deploy-Role','PPB-V2-GitHub-WP-Draft-Test-Role']),'EXISTING_ADMINISTRATOR_REQUIRED')
    handle=sys.argv[1]
    if handle.startswith('login:'):
        value=handle[6:];need(bool(re.fullmatch('[A-Za-z0-9_-]{1,80}',value)),'INVALID_LOGIN_PATH');flag='--login-path'
    elif handle.startswith('file:'):
        value=handle[5:];need(bool(re.fullmatch('/(?:root|etc/mysql)/[A-Za-z0-9_./-]+',value)),'INVALID_PRIVATE_DEFAULTS_PATH');flag='--defaults-file'
    else:raise RuntimeError('EXPLICIT_EXISTING_DBA_CONNECTION_REQUIRED')
    command='exec python3 /usr/local/lib/ppb-legacy-ro/dba-once.py '+flag+' '+shlex.quote(value)
    commands=['set -eu','umask 077',"printf '%s  %s\\n' 53b7fddbaf6bd4d6602b3efbd23b8b40c51d05dfaafcbd9e09dabd15694cfc19 /usr/local/lib/ppb-legacy-ro/dba-once.py | sha256sum -c - >/dev/null",command]
    sent=aws('ssm','send-command','--document-name','AWS-RunShellScript','--instance-ids',INSTANCE,'--parameters',json.dumps({'commands':commands}),'--comment','One reviewed legacy readonly DBA provisioning; no business writes','--timeout-seconds','120')
    cid=sent['Command']['CommandId'];print('SSM_COMMAND_ID='+cid,flush=True)
    for _ in range(50):
        try:r=aws('ssm','get-command-invocation','--command-id',cid,'--instance-id',INSTANCE)
        except RuntimeError as exc:
            if str(exc)=='AWS_InvocationDoesNotExist':time.sleep(2);continue
            raise
        if r['Status'] in ['Pending','InProgress','Delayed']:time.sleep(2);continue
        lines=r.get('StandardOutputContent','').splitlines();rows=[]
        for line in lines:
            try:rows.append(json.loads(line))
            except ValueError:pass
        need(len(rows)==1,'DBA_RESULT_MISSING_CHECK_FIXED_TOOL_INSTALLATION')
        result=rows[0];safe={k:v for k,v in result.items() if k in ['result','reason','schema','tables','password_exported','old_business_write_statements','new_principal']}
        safe['ssm_command_id']=cid;print(json.dumps(safe,ensure_ascii=False))
        need(r['Status']=='Success' and r['ResponseCode']==0 and result.get('result')=='READONLY_DBA_PROVISIONED_VERIFY_NEXT','DBA_PROVISIONING_NOT_VERIFIED')
        break
    else:raise RuntimeError('OUTCOME_UNKNOWN_DO_NOT_RESEND')
except Exception as exc:
    code=str(exc);print('RESULT=BLOCKED; REASON='+ (code if re.fullmatch('[A-Za-z0-9_]{1,120}',code) else 'ADMIN_SETUP_NOT_VERIFIED'));raise SystemExit(2)
PPB_ADMIN
