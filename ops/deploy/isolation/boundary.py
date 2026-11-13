"""Strict V2 isolation policy. No app bootstrap, SQL writes or credential output."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat

LIVE='/www/wwwroot/ppbaos.com'
PRIVATE='/www/ppb-v2-deploy'
LEGACY='/www/wwwroot/3.231.229.26'
USER='ppb-v2'

def need(ok,reason):
    if not ok:raise RuntimeError(reason)

def private_json(path):
    s=path.lstat()
    need(stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_mode&0o077==0 and s.st_size<200000,'POLICY_NOT_ROOT_PRIVATE')
    return json.loads(path.read_text())

def validate_policy(p):
    need(set(p)=={'version','live','private','unix_user','database','read_only_schemas','env_sha256','legacy_roots','vhost_sha256','php_config_sha256','php_unit_sha256'},'POLICY_SCHEMA_INVALID')
    need(p['version'] in {1,2,3} and p['live']==LIVE and p['private']==PRIVATE and p['unix_user']==USER,'POLICY_PATH_OR_USER_INVALID')
    need(isinstance(p['database'],str) and re.fullmatch('[A-Za-z0-9_]{1,64}',p['database']),'V2_DATABASE_NOT_ESTABLISHED')
    need(isinstance(p['read_only_schemas'],list) and all(isinstance(x,str) and re.fullmatch('[A-Za-z0-9_]{1,64}',x) for x in p['read_only_schemas']),'READONLY_SCHEMA_INVALID')
    need(p['database'] not in p['read_only_schemas'],'V2_LEGACY_SCHEMA_OVERLAP')
    need(isinstance(p['env_sha256'],str) and re.fullmatch('[a-f0-9]{64}',p['env_sha256']),'ENV_NOT_APPROVED')
    need(p['legacy_roots']==[LEGACY],'LEGACY_PATH_POLICY_MISMATCH')
    need(all(isinstance(p.get(k),str) and re.fullmatch('[a-f0-9]{64}',p[k]) for k in ['vhost_sha256','php_config_sha256','php_unit_sha256']),'WEB_ROUTING_NOT_APPROVED')
    return p

def read_env(path,expected,owner=0,require_low_privilege=True):
    # Parse data, never source shell/PHP or run application's autoloader as root.
    need(path.is_file() and not path.is_symlink(),'ENV_NOT_REGULAR')
    st=path.lstat();need(st.st_uid==owner and st.st_mode&0o022==0,'ENV_OWNER_OR_WRITE_MODE_UNSAFE')
    raw=path.read_bytes();need(len(raw)<200000 and hashlib.sha256(raw).hexdigest()==expected,'ENV_CHANGED_RECHECK_ISOLATION')
    result={}
    for line in raw.decode().splitlines():
        line=line.strip()
        if not line or line.startswith('#'):continue
        m=re.fullmatch(r'(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)',line)
        if not m:continue
        name,value=m.groups()
        if not name.startswith('DB_'):continue
        need(name not in result,'DUPLICATE_DATABASE_SETTING')
        if value.startswith(('"',"'")):
            quote=value[0];need(len(value)>=2 and value[-1]==quote,'UNSUPPORTED_ENV_QUOTING')
            value=value[1:-1];need('\\' not in value and '$' not in value,'ENV_INTERPOLATION_NEEDS_REVIEW')
        else:
            need(not re.search(r'[\s#$\\]',value),'ENV_INTERPOLATION_NEEDS_REVIEW')
        result[name]=value
    need(result.get('DB_CONNECTION') in {'mysql','mariadb'} and not result.get('DB_URL') and not result.get('DB_SOCKET'),'ONLY_EXPLICIT_TCP_MYSQL_SUPPORTED')
    need(re.fullmatch('[A-Za-z0-9_]{1,32}',result.get('DB_USERNAME','')),'DB_IDENTITY_INVALID')
    if require_low_privilege:need(result['DB_USERNAME'] not in {'root','mysql','admin'},'HIGH_PRIVILEGE_DB_IDENTITY')
    need(re.fullmatch('[a-zA-Z0-9._:-]+',result.get('DB_HOST','')) and re.fullmatch('[0-9]{1,5}',result.get('DB_PORT','3306')),'DATABASE_ENDPOINT_INVALID')
    need(result.get('DB_PASSWORD') is not None,'DB_PASSWORD_MISSING')
    return result

def exact_schema(raw):
    # MySQL schema GRANT '%'/'_' may be patterns even inside backticks.
    need(not re.search(r'(?<!\\)[_%]',raw),'WILDCARD_DATABASE_GRANT')
    return raw.replace('\\_','_').replace('\\%','%').replace('``','`')

def validate_grants(database,readonly,grants):
    need(isinstance(grants,list) and bool(grants),'DATABASE_GRANTS_MISSING')
    own_write=False;metadata_visible=False
    for grant in grants:
        need(isinstance(grant,str) and 'WITH GRANT OPTION' not in grant.upper(),'GRANT_OPTION_FORBIDDEN')
        m=re.fullmatch(r'GRANT (.+?) ON (`(?:``|[^`])+`|\*)\.(`(?:``|[^`])+`|\*) TO .+',grant,re.I)
        need(m is not None,'ROLES_OR_PROXY_GRANTS_UNVERIFIED')
        permissions,schema,table=m.groups();permissions={x.strip().upper() for x in permissions.split(',')}
        if schema=='*':
            need(table=='*' and permissions=={'USAGE'},'GLOBAL_DATABASE_PRIVILEGE_FORBIDDEN');continue
        schema=exact_schema(schema[1:-1])
        if schema==database:
            need(not permissions & {'FILE','SUPER','SYSTEM_USER','CREATE USER','PROXY'},'ADMIN_DATABASE_PRIVILEGE_FORBIDDEN')
            own_write=own_write or bool(permissions & {'ALL PRIVILEGES','CREATE','ALTER','INSERT','UPDATE','DELETE'})
            metadata_visible=metadata_visible or (table=='*' and ('ALL PRIVILEGES' in permissions or {'TRIGGER','EVENT','SHOW VIEW','EXECUTE','CREATE ROUTINE','ALTER ROUTINE'}<=permissions))
        else:
            need(schema in readonly and permissions <= {'SELECT','SHOW VIEW'},'WRITE_OR_EXECUTE_OUTSIDE_V2_DATABASE')
    need(own_write,'V2_WRITE_PRIVILEGE_MISSING')
    need(metadata_visible,'V2_OBJECT_METADATA_PRIVILEGES_INCOMPLETE')
    return {'v2_database_write':True,'legacy_database_write':False,'global_privileges':False}

def validate_probe(p,env,probe):
    validate_policy(p)
    need(env.get('DB_DATABASE')==p['database'] and probe.get('database')==p['database'],'ACTUAL_DATABASE_MISMATCH')
    need(probe.get('user')==env['DB_USERNAME'] and probe.get('roles') in [None,'NONE'],'ACTUAL_DATABASE_USER_OR_ROLES_MISMATCH')
    checks=validate_grants(p['database'],set(p['read_only_schemas']),probe.get('grants'))
    need(isinstance(probe.get('current_user'),str) and probe['current_user'].split('@',1)[0]==env['DB_USERNAME'],'DATABASE_ACTOR_UNVERIFIED')
    need(isinstance(probe.get('definers'),list) and all(x==probe['current_user'] for x in probe['definers']),'PRIVILEGED_OBJECT_DEFINER_OUTSIDE_V2')
    need(probe.get('cross_schema_foreign_keys')==0,'CROSS_SCHEMA_CASCADE_NOT_ISOLATED')
    # Transactional data tests/backups cannot silently assume MyISAM rollback.
    need(all(x=='InnoDB' for x in probe.get('table_engines',[])),'NONTRANSACTIONAL_V2_TABLES_REQUIRE_BACKUP_POLICY')
    return dict(checks,database=p['database'],user=env['DB_USERNAME'],tables=len(probe.get('table_engines',[])))

def sandbox_command(argv):
    need(isinstance(argv,list) and all(isinstance(x,str) and '\x00' not in x for x in argv),'ARGUMENT_INVALID')
    properties=[
        'ProtectSystem=strict','ProtectHome=true','PrivateTmp=true','PrivateDevices=true',
        'NoNewPrivileges=true','CapabilityBoundingSet=','AmbientCapabilities=',
        'RestrictSUIDSGID=true','ProtectKernelTunables=true','ProtectKernelModules=true',
        'ProtectControlGroups=true','RestrictNamespaces=true','UMask=0077',
        'ReadWritePaths='+LIVE,
        'BindReadOnlyPaths='+LIVE+'/.env',
        'InaccessiblePaths='+LEGACY+' /www/server/data /www/server/panel /www/ppb-v2-deploy -/run/mysqld -/etc/mysql -/etc/my.cnf',
        'IPAddressDeny=169.254.0.0/16 fd00:ec2::254/128',
        'RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX']
    return ['systemd-run','--quiet','--wait','--pipe','--collect','--uid='+USER,'--gid='+USER,
            '--working-directory='+LIVE,*['--property='+x for x in properties],'--',*argv]


def validate_service(properties):
    need(properties.get('ActiveState')=='active','V2_WEB_SERVICE_NOT_ACTIVE')
    need(properties.get('User')==USER and properties.get('ProtectSystem')=='strict'
         and properties.get('NoNewPrivileges')=='yes' and properties.get('CapabilityBoundingSet')=='',
         'V2_WEB_UID_OR_SANDBOX_UNVERIFIED')
    need(properties.get('ReadWritePaths','').split()==[LIVE],'V2_WEB_WRITE_SCOPE_TOO_WIDE')
    need({LEGACY,'/www/ppb-v2-deploy','/www/server/panel'} <= set(properties.get('InaccessiblePaths','').split()),'LEGACY_WEB_PATH_NOT_ISOLATED')
    need(properties.get('PrivateTmp')=='yes' and properties.get('PrivateDevices')=='yes','V2_WEB_NAMESPACE_UNVERIFIED')
    need({'169.254.0.0/16','fd00:ec2::254/128'} <= set(properties.get('IPAddressDeny','').split()),'INSTANCE_METADATA_NOT_BLOCKED')
    import shlex
    entries=shlex.split(properties.get('BindReadOnlyPaths',''))
    parts=entries[0].split(':') if len(entries)==1 else []
    need(bool(parts) and parts[0]==LIVE+'/.env' and len(parts)<=3
         and (len(parts)==1 or parts[1]==LIVE+'/.env')
         and (len(parts)<3 or parts[2] in {'rbind','norbind'}),'ENV_NOT_READONLY_IN_WEB_SANDBOX')
    return True


def validate_web_config(vhost,php_config):
    # A configured isolated unit is insufficient if nginx still routes to www's pool.
    active='\n'.join(line.split('#',1)[0] for line in vhost.splitlines())
    names=re.findall(r'\bserver_name\s+([^;]+);',active)
    need(len(names)==1 and 'v2.csdatw.xyz' in names[0].split() and set(names[0].split()) <= {'v2.csdatw.xyz','v2.ppbaos.com','3-231-229-26.sslip.io'},'V2_VHOST_NOT_EXCLUSIVE')
    need(re.search(r'\broot\s+/www/wwwroot/ppbaos\.com/public\s*;',active),'V2_WEB_ROOT_MISMATCH')
    fastcgi=re.findall(r'\bfastcgi_pass\s+([^;]+);',active)
    need(fastcgi==['unix:/www/wwwroot/ppbaos.com/storage/run/php-fpm.sock'],'V2_STILL_USES_SHARED_PHP_POOL')
    includes=re.findall(r'\binclude\s+([^;]+);',active)
    need(all(x in {'fastcgi_params','/www/server/nginx/conf/fastcgi_params','/etc/nginx/fastcgi_params'} for x in includes),'HIDDEN_SHARED_PHP_INCLUDE')
    active_php='\n'.join(line.split(';',1)[0] for line in php_config.splitlines())
    need(re.search(r'^\s*clear_env\s*=\s*yes\s*$',active_php,re.M) and not re.search(r'env\s*\[|^\s*include\s*=',active_php,re.M),'FPM_INHERITED_CREDENTIALS_UNVERIFIED')
    need(re.search(r'^\s*listen\s*=\s*/www/wwwroot/ppbaos\.com/storage/run/php-fpm\.sock\s*$',active_php,re.M),'FPM_SOCKET_MISMATCH')
    return True

def omit_empty_v2_extension(text):
    pattern=r'(?m)^[ \t]*include[ \t]+/www/server/panel/vhost/nginx/extension/v2\.ppbaos\.com/\*\.conf[ \t]*;[ \t]*(?:#[^\n]*)?$'
    if re.search(pattern,text):
        spec=importlib.util.spec_from_file_location('inert_extension_lexer',Path(__file__).with_name('nginx_lex.py'));lexer=importlib.util.module_from_spec(spec);spec.loader.exec_module(lexer)
        for path in Path('/www/server/panel/vhost/nginx/extension/v2.ppbaos.com').glob('*.conf'):
            st=path.lstat()
            need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_mode&0o022==0 and st.st_nlink==1,'V2_EXTENSION_FILE_NOT_ROOT_PROTECTED')
            need(not lexer.nginx_tokens(path.read_text()),'V2_EXTENSION_HAS_DIRECTIVES_REQUIRES_REVIEW')
        text=re.sub(pattern,'',text)
    return text
