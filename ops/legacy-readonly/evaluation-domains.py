"""Fixed one-time DBA delta for verified evaluation keys and PRIVATE video joins.
No CREATE USER, old business DDL/DML, credential export or generic text output.
"""
import grp,hashlib,json,os,re,stat,tempfile
from pathlib import Path
PLAN={
 'pay_analyst_pick':{'analyst_name':'numeric_id'},
 'pay_analyst_monthly_evaluation':{'eval_month':'year_month'},
 'pay_analyst_monthly_rating':{'rating_month':'year_month'},
 'pay_schedule':{'market_type':'market_code'},
 'pay_analyst_profiles':{'analyst_name':'private_join'},
 'pay_video_schedule':{'guest_1_name':'private_join','guest_2_name':'private_join','guest_3_name':'private_join'},
}
PATTERNS={'numeric_id':'^[0-9]{1,18}$','year_month':'^[0-9]{4}-(0[1-9]|1[0-2])$','market_code':'^[0-9]{0,3}$'}
def enable(dba,login_path,mysql,run,defaults_file=None):
 dba.need(os.geteuid()==0,'AUTHORIZED_OS_ADMIN_REQUIRED')
 config=dba.CONFIG;catalog_path=config/'catalog.json';credential_path=config/'credentials.json'
 dba.need(config.is_dir() and not config.is_symlink() and config.stat().st_uid==0 and config.stat().st_mode&0o022==0,'EXISTING_READER_DIRECTORY_UNSAFE')
 for path in [catalog_path,credential_path]:
  s=path.lstat();dba.need(stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_gid==grp.getgrnam('ppb-legacy-ro').gr_gid and s.st_nlink==1 and s.st_mode&0o027==0,'EXISTING_READER_CONFIG_UNSAFE')
 before=catalog_path.read_bytes();catalog=json.loads(before);schema=catalog['schema'];dba.ident(schema)
 sql=dba.sql_session(mysql,dba.management_options(login_path,defaults_file),defaults_file,run)
 version=sql('EXTENSION_VERSION','SELECT VERSION();').strip();dba.need(version.startswith('5.7.'),'MYSQL57_REQUIRED')
 grants=sql('EXTENSION_DBA_GRANTS','SHOW GRANTS FOR CURRENT_USER;').splitlines()
 dba.need(any(re.match(r'^GRANT ALL PRIVILEGES ON \*\.\* TO ',g) and re.search(r'\bWITH GRANT OPTION\s*$',g) for g in grants),'DBA_REQUIRED_PRIVILEGES_NOT_PROVEN')
 dba.need(sql('EXTENSION_ACCOUNT',"SELECT COUNT(*) FROM mysql.user WHERE User='ppb_legacy_ro' AND Host='localhost';").strip()=='1','EXACT_EXISTING_READER_REQUIRED')
 old_grants=sql('EXTENSION_READER_GRANTS',"SHOW GRANTS FOR 'ppb_legacy_ro'@'localhost';").splitlines()
 for grant in old_grants:
  dba.need('WITH GRANT OPTION' not in grant,'READER_GRANT_OPTION_DENIED')
  if grant.startswith('GRANT USAGE ON *.* TO '):continue
  match=re.match(r'^GRANT (REFERENCES, SHOW VIEW|SHOW VIEW, REFERENCES) ON `([^`]+)`\.\* TO ',grant)
  if match:dba.need(match[2]==schema,'READER_SCOPE_MISMATCH');continue
  match=re.match(r'^GRANT SELECT \(([^)]+)\) ON `([^`]+)`\.`([^`]+)` TO ',grant)
  dba.need(bool(match),'EXISTING_READER_GRANTS_NOT_REVIEWED')
  key=match[2]+'.'+match[3];dba.need(key in catalog['tables'],'READER_SCOPE_MISMATCH')
  entry=catalog['tables'][key];allowed=set(entry['data_columns']+entry.get('join_columns',[]))
  dba.need(set(c.strip(' `') for c in match[1].split(','))<=allowed,'UNRECORDED_GRANT_STOP_POSSIBLE_PARTIAL_EXTENSION')
 if catalog.get('evaluation_read_v1'):
  return dict(result='EVALUATION_READ_ALREADY_ENABLED_VERIFY_NEXT',old_business_write_statements=0,password_exported=False)
 backup=config/'catalog.before-evaluation-read-v1.json'
 dba.need(not backup.exists() and not backup.is_symlink(),'EXTENSION_BACKUP_COLLISION_STOP')
 # Verify every field/domain BEFORE issuing any GRANT. Never return values.
 statements=[]
 for table,columns in PLAN.items():
  key=schema+'.'+table;dba.need(key in catalog['tables'] and catalog['tables'][key]['kind']=='BASE TABLE','REQUIRED_BASE_TABLE_NOT_REVIEWED')
  entry=catalog['tables'][key]
  for column,domain in columns.items():
   dtype=sql('EXTENSION_COLUMN_METADATA',"SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='"+schema+"' AND TABLE_NAME='"+table+"' AND COLUMN_NAME='"+column+"';").strip()
   dba.need(dtype in ['char','varchar'] and entry['types'].get(column)==dtype,'EVALUATION_COLUMN_TYPE_MISMATCH')
   if domain=='private_join':entry.setdefault('join_columns',[]).append(column)
   else:
    bad=sql('EXTENSION_DOMAIN_VALIDATION','SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(*) FROM '+dba.ident(schema)+'.'+dba.ident(table)+' WHERE '+dba.ident(column)+" IS NOT NULL AND "+dba.ident(column)+" NOT REGEXP '"+PATTERNS[domain]+"';").strip()
    dba.need(bad=='0','EVALUATION_KEY_DOMAIN_NOT_PROVEN')
    entry['data_columns'].append(column);entry.setdefault('text_domains',{})[column]=domain
  statements.append('GRANT SELECT ('+','.join(dba.ident(c) for c in columns)+') ON '+dba.ident(schema)+'.'+dba.ident(table)+" TO 'ppb_legacy_ro'@'localhost';")
 # Preserve the ORIGINAL catalog. Fail closed on partial grants; no force/revoke.
 fd=os.open(backup,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o640)
 with os.fdopen(fd,'wb') as stream:stream.write(before);stream.flush();os.fsync(stream.fileno())
 os.chown(backup,0,catalog_path.stat().st_gid)
 for statement in statements:sql('EXTENSION_COLUMN_GRANT',statement)
 catalog['evaluation_read_v1']=True
 fd,temp=tempfile.mkstemp(prefix='.catalog-evaluation-',dir=config)
 try:
  os.fchmod(fd,0o640);os.fchown(fd,0,catalog_path.stat().st_gid)
  with os.fdopen(fd,'w') as stream:json.dump(catalog,stream);stream.flush();os.fsync(stream.fileno())
  os.replace(temp,catalog_path)
 finally:
  if Path(temp).exists():Path(temp).unlink()
 return dict(result='EVALUATION_READ_ENABLED_VERIFY_NEXT',schema=schema,typed_columns=4,private_join_columns=4,
             original_catalog_sha256=hashlib.sha256(before).hexdigest(),old_business_write_statements=0,password_exported=False)
