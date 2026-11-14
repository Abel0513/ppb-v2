#!/usr/bin/env python3
"""Existing DBA management connection only; no password search/export or business DML.
Run locally on EC2 via an existing authorized DBA/OS-management channel. Requires
an explicitly named existing MySQL login-path (not a newly requested password).
Creates only ppb_legacy_ro@localhost with column SELECT; private config stays local.
"""
import argparse,datetime,grp,json,os,re,secrets,stat,subprocess,tempfile
from pathlib import Path
CONFIG=Path('/etc/ppb-legacy-ro');ACCOUNT='ppb_legacy_ro';ANCHORS={'pay_analyst_profiles','pay_analyst_pick','pay_schedule'}
SENSITIVE=re.compile(r'password|passwd|pwd|secret|token|credential|auth|line|openid|unionid|mobile|phone|email|address|birth|dob|id_card|bank|session|cookie|ip_addr|(?:^|_)ip(?:_|$)|wechat|telegram|qq|idno|real_name|nickname|avatar|photo',re.I)
TABLE_DENY=re.compile(r'password|secret|token|credential|auth|session|cookie|bank|payment|config|setting|log|user|account|line',re.I)
NUMERIC={'tinyint','smallint','mediumint','int','bigint','decimal','float','double','bit'};DATES={'date','datetime','timestamp','time','year'}
def need(ok,code):
 if not ok:raise RuntimeError(code)
def ident(value):
 need(bool(re.fullmatch('[A-Za-z_][A-Za-z0-9_]{0,63}',value)),'IDENTIFIER_DENIED');return '`'+value+'`'
def safe_column(name,column,typ,kind):
 return kind=='BASE TABLE' and not TABLE_DENY.search(name) and not SENSITIVE.search(column) and typ in NUMERIC|DATES
def management_options(login_path=None,defaults_file=None):
 need(bool(login_path)!=bool(defaults_file),'ONE_EXPLICIT_EXISTING_DBA_CONNECTION_REQUIRED')
 if login_path:
  need(bool(re.fullmatch('[A-Za-z0-9_-]{1,80}',login_path)),'EXPLICIT_EXISTING_DBA_LOGIN_PATH_REQUIRED')
  return ['--no-defaults','--login-path='+login_path]
 path=Path(defaults_file);resolved=path.resolve()
 need(path.is_absolute() and not path.is_symlink() and any(resolved==Path(base) or Path(base) in resolved.parents for base in ['/root','/etc/mysql']),'ADMIN_DEFAULTS_PATH_DENIED')
 st=path.stat();need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_nlink==1 and st.st_mode&0o077==0,'ADMIN_DEFAULTS_FILE_UNSAFE')
 return ['--defaults-file='+str(resolved),'--no-login-paths']
def bootstrap(login_path,mysql,run=subprocess.run,defaults_file=None):
 need(os.geteuid()==0,'AUTHORIZED_OS_ADMIN_REQUIRED')
 connection=management_options(login_path,defaults_file)
 need(CONFIG.is_dir() and not CONFIG.is_symlink() and CONFIG.stat().st_uid==0,'READER_INSTALL_REQUIRED')
 for name in ['credentials.json','catalog.json']:need(not (CONFIG/name).exists() and not (CONFIG/name).is_symlink(),'READONLY_SETUP_COLLISION_STOP')
 def sql(query):
  p=run([mysql,*connection,'--protocol=SOCKET','--batch','--raw','--skip-column-names','--connect-timeout=5'],input=query,text=True,capture_output=True,timeout=30)
  need(p.returncode==0,'DBA_SQL_FAILED_STOP_NO_FORCE');return p.stdout
 need(sql('SELECT VERSION();').startswith('5.7.'),'MYSQL57_REQUIRED')
 need(sql("SELECT COUNT(*) FROM mysql.user WHERE User='ppb_legacy_ro';").strip()=='0','READONLY_USER_EXISTS_STOP')
 raw=sql("SELECT TABLE_SCHEMA,TABLE_NAME FROM information_schema.TABLES WHERE TABLE_TYPE='BASE TABLE' AND TABLE_NAME IN ('pay_analyst_profiles','pay_analyst_pick','pay_schedule');")
 candidates={}
 for line in raw.splitlines():
  schema,name=line.split('\t');candidates.setdefault(schema,set()).add(name)
 schemas=[s for s,names in candidates.items() if names==ANCHORS and s not in ['ppbaos','mysql','sys','information_schema','performance_schema']]
 need(len(schemas)==1,'LEGACY_SCHEMA_NOT_UNIQUELY_VERIFIED');schema=schemas[0];ident(schema)
 raw=sql("SELECT c.TABLE_NAME,c.COLUMN_NAME,c.DATA_TYPE,c.IS_NULLABLE,c.COLUMN_KEY,c.COLUMN_TYPE,t.TABLE_TYPE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA='"+schema+"' ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION;")
 tables={}
 for line in raw.splitlines():
  name,column,typ,nullable,key,fulltype,kind=line.split('\t');ident(name);ident(column)
  entry=tables.setdefault(schema+'.'+name,dict(schema=schema,name=name,kind=kind,columns=[],types={},data_columns=[],indexes=[]))
  entry['columns'].append(dict(name=column,type=typ,nullable=nullable,key=key));entry['types'][column]=typ
  # Independent column grants exclude all free text and privacy-sensitive names.
  if safe_column(name,column,typ,kind):entry['data_columns'].append(column)
 raw=sql("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='"+schema+"' ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX;")
 for line in raw.splitlines():
  name,index,nonunique,sequence,column=line.split('\t')
  if schema+'.'+name in tables:tables[schema+'.'+name]['indexes'].append(dict(name=index,nonunique=int(nonunique),sequence=int(sequence),column=column))
 need(0<len(tables)<500,'METADATA_SIZE_LIMIT')
 allowed={k:v for k,v in tables.items() if v['data_columns']};need(bool(allowed),'NO_SAFE_BUSINESS_COLUMNS')
 password=secrets.token_urlsafe(36);socket=sql('SELECT @@socket;').strip()
 need(socket.startswith('/') and '\n' not in socket and Path(socket).exists() and stat.S_ISSOCK(Path(socket).stat().st_mode),'MYSQL_SOCKET_NOT_VERIFIED')
 statements=["CREATE USER 'ppb_legacy_ro'@'localhost' IDENTIFIED BY '"+password+"' WITH MAX_USER_CONNECTIONS 2 MAX_QUERIES_PER_HOUR 5000 ACCOUNT LOCK;"]
 statements.append('GRANT REFERENCES, SHOW VIEW ON '+ident(schema)+".* TO 'ppb_legacy_ro'@'localhost';")
 for table in allowed.values():statements.append('GRANT SELECT ('+','.join(ident(c) for c in table['data_columns'])+') ON '+ident(schema)+'.'+ident(table['name'])+" TO 'ppb_legacy_ro'@'localhost';")
 # No legacy schema DDL/DML; never search/read an old password file.
 # Account creation is isolated: a collision/error must never lock someone else's account.
 sql(statements[0])
 sql('\n'.join(statements[1:])) # New principal remains LOCKED if any grant fails.
 gid=grp.getgrnam('ppb-legacy-ro').gr_gid
 for name,value in [('catalog.json',dict(schema=schema,created_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),tables=tables)),('credentials.json',dict(user=ACCOUNT,password=password,socket=socket))]:
  fd=os.open(CONFIG/name,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o640)
  with os.fdopen(fd,'w') as out:json.dump(value,out);out.flush();os.fsync(out.fileno())
  os.chown(CONFIG/name,0,gid);os.chmod(CONFIG/name,0o640)
 sql("ALTER USER 'ppb_legacy_ro'@'localhost' ACCOUNT UNLOCK;")
 return dict(result='READONLY_DBA_PROVISIONED_VERIFY_NEXT',schema=schema,tables=len(allowed),password_exported=False,old_business_write_statements=0,new_principal=ACCOUNT+'@localhost')
if __name__=='__main__':
 p=argparse.ArgumentParser();group=p.add_mutually_exclusive_group(required=True);group.add_argument('--login-path');group.add_argument('--defaults-file');p.add_argument('--mysql',default='/www/server/mysql/bin/mysql');a=p.parse_args()
 try:print(json.dumps(bootstrap(a.login_path,a.mysql,defaults_file=a.defaults_file)))
 except Exception as exc:
  code=str(exc);print(json.dumps(dict(result='BLOCKED',reason=code if re.fullmatch('[A-Z0-9_]{1,100}',code) else 'DBA_SETUP_NOT_VERIFIED')));raise SystemExit(2)
