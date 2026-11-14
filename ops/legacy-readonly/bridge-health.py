#!/usr/bin/env python3
"""Fixed nonroot IPC acceptance. Returns only metadata, never cookies/credentials."""
import json,os,pwd,socket
def check():
 if os.geteuid()!=pwd.getpwnam('ppb-v2').pw_uid:raise RuntimeError('V2_NONROOT_PROBE_REQUIRED')
 def request(query):
  with socket.socket(socket.AF_UNIX) as connection:
   connection.settimeout(15);connection.connect('/run/ppb-v2-legacy-reader.sock');connection.sendall(json.dumps(query).encode()+b'\n');data=b''
   while b'\n' not in data:
    part=connection.recv(4096)
    if not part or len(data)>100000:raise RuntimeError('BRIDGE_RESULT_LIMIT')
    data+=part
  return json.loads(data)
 result=request({'operation':'db_permissions'});value=result.get('data',{})
 if not(result.get('result')=='PASS' and value.get('db_actor')=='ppb_legacy_ro@localhost' and value.get('transaction_readonly') is True and value.get('old_db_write_grants') is False and value.get('global_privileges') is False):raise RuntimeError('BRIDGE_READONLY_NOT_PROVEN')
 for query,reason in [({'operation':'select','table':'pay.pay_analyst_profiles','columns':['analyst_name'],'limit':1},'SELECT_COLUMNS_DENIED'),({'operation':'select','table':'pay.pay_analyst_profiles','columns':['id'],'filters':[{'column':'analyst_name','op':'=','value':'SYNTHETIC_PRIVACY_PROBE'}],'limit':1},'FILTER_DENIED')]:
  denied=request(query)
  if denied!={'result':'STOP','reason':reason}:raise RuntimeError('PRIVATE_NAME_POLICY_NOT_PROVEN')
 if not(value.get('evaluation_domains_enabled') is True and len(value.get('typed_key_grants',{}))==4 and all(value['typed_key_grants'].values()) and len(value.get('private_join_grants',{}))==4 and all(value['private_join_grants'].values()) and value.get('grant_option') is False):raise RuntimeError('EXACT_EVALUATION_GRANTS_NOT_PROVEN')
 return dict(result='PASS',application_uid=os.geteuid(),db_actor=value['db_actor'],transaction_readonly=True,legacy_writes=0,credentials_exported=False,private_name_select_denied=True,private_name_filter_denied=True,typed_key_grants=4,private_join_grants=4,original_catalog_sha256=value['original_catalog_sha256'])
if __name__=='__main__':
 try:print(json.dumps(check()))
 except Exception:print('{"result":"STOP","reason":"V2_BRIDGE_NOT_VERIFIED"}');raise SystemExit(2)
