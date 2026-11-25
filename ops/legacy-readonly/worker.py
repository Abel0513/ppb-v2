#!/usr/bin/env python3
"""No network, shell, imports or app execution from request data. Readonly FD walks."""
import hashlib,json,os,re,stat,subprocess,sys
from pathlib import Path,PurePosixPath
ROOT=Path('/www/wwwroot/3.231.229.26')
DENY=re.compile(r'(^|/)(?:\.[^/]*|conf|config|runtime|vendor|node_modules|uploads?|backups?|logs?|cache|storage|certs?|keys?|\.git)(/|$)|(?:db|line|database|password|secret|credential|token|auth|backup|dump|certificate|private)[^/]*\.(?:php|json|ini|cnf|sql|pem|key|zip|gz)$',re.I)
PHP='/www/server/php/84/bin/php'
EXT={'.php','.html','.htm','.js','.css'}
import importlib.util
_spec=importlib.util.spec_from_file_location('offline_ui_structure',Path(__file__).resolve().parent/'offline-ui'/'structure.py')
structure=importlib.util.module_from_spec(_spec);_spec.loader.exec_module(structure)
# Separate path-only UI operation. Does not widen source() or its tokenizer.
UI_TEMPLATES={'.tpl','.html','.htm','.php'}
UI_ASSETS={'.css','.js','.svg','.png','.jpg','.jpeg','.gif','.webp','.ico','.woff','.woff2','.ttf'}
UI_PRIVATE=re.compile(r'(^|/)(?:\.[^/]*|conf|config|runtime|vendor|node_modules|uploads?|backups?|logs?|cache|storage|certs?|keys?|secrets?)(/|$)|(?:password|passwd|secret|credential|token|database|private|certificate|(?:^|/)(?:db|line|auth)(?:[._-]|$))',re.I)
def ui_valid(path):
    if not isinstance(path,str) or not 1<=len(path)<=240:return False
    p=PurePosixPath(path)
    if p.is_absolute() or '..' in p.parts or len(p.parts)>16 or UI_PRIVATE.search(path) or DENY.search(path):return False
    parts=[x.lower() for x in p.parts];suffix=p.suffix.lower()
    if parts[0]=='public':
        return len(parts)>=3 and any(x in {'css','js','images','img','icons','fonts','assets'} for x in parts[1:-1]) and suffix in UI_ASSETS
    return suffix in UI_TEMPLATES|{'.css','.js'} and (parts[0] in {'tpl','template','templates','views','themes'} or parts[0]=='application' and any(x in {'view','views','tpl','template','templates','themes'} for x in parts[1:-1]))
def ui_stat(path,root):
    need(ui_valid(path),'UI_PATH_DENIED')
    fd=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:
        for i,part in enumerate(PurePosixPath(path).parts):
            child=os.open(part,os.O_RDONLY|os.O_NOFOLLOW|(os.O_DIRECTORY if i<len(PurePosixPath(path).parts)-1 else 0),dir_fd=fd)
            os.close(fd);fd=child
        s=os.fstat(fd)
        need(stat.S_ISREG(s.st_mode) and s.st_nlink==1 and s.st_size<=500000,'UI_FILE_UNSAFE')
        return s
    finally:os.close(fd)
def ui_read(path,root):
    need(ui_valid(path),'UI_PATH_DENIED')
    fd=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:
        parts=PurePosixPath(path).parts
        for i,part in enumerate(parts):
            child=os.open(part,os.O_RDONLY|os.O_NOFOLLOW|(os.O_DIRECTORY if i<len(parts)-1 else 0),dir_fd=fd)
            os.close(fd);fd=child
        s=os.fstat(fd)
        need(stat.S_ISREG(s.st_mode) and s.st_nlink==1 and s.st_size<=500000,'UI_FILE_UNSAFE')
        return os.read(fd,500001)
    finally:os.close(fd)
def php_inline(source):
    helper=Path(__file__).resolve().parent/'offline-ui'/'php-inline.php'
    need(helper.is_file() and not helper.is_symlink(),'PHP_INLINE_UNAVAILABLE')
    payload=source if isinstance(source,(bytes,bytearray)) else source.encode()
    p=subprocess.run(tokenizer_argv()+[str(helper)],input=payload,capture_output=True,timeout=8)
    need(p.returncode==0,'PHP_INLINE_FAILED')
    data=json.loads(p.stdout)
    need(isinstance(data,dict) and set(data)<= {'inline_html'} and isinstance(data.get('inline_html'),str),'PHP_INLINE_OUTPUT_INVALID')
    return data['inline_html']
def ui_source(q,root):
    need(set(q)<= {'operation','path'},'UI_SOURCE_SCHEMA')
    path=q.get('path','')
    need(path in structure.PRODUCTION_CATALOG,'UI_SOURCE_NOT_REVIEWED')
    need(ui_valid(path),'UI_PATH_DENIED')
    raw=ui_read(path,root)
    try:source=raw.decode('utf-8')
    except UnicodeDecodeError:raise RuntimeError('UI_SOURCE_ENCODING')
    # Intermediate php-inline stdout is consumed here and never returned.
    return structure.parse_reviewed(path,source,php_strip=php_inline)
def ui_inventory(q,root):
    need(set(q)<={'operation','offset','limit'},'UI_INVENTORY_SCHEMA')
    offset=q.get('offset',0);limit=q.get('limit',50)
    need(type(offset)is int and 0<=offset<=5000 and type(limit)is int and 1<=limit<=50,'UI_PAGE_LIMIT')
    rows=[];visited=0
    def fail(exc):raise RuntimeError('UI_INVENTORY_OS_ACCESS_DENIED')
    for base in sorted(root.iterdir()):
        if base.name.lower() not in {'application','tpl','template','templates','views','themes','public'} or base.is_symlink() or not base.is_dir():continue
        for parent,dirs,files in os.walk(base,followlinks=False,onerror=fail):
            rel=Path(parent).relative_to(root)
            dirs[:]=sorted(d for d in dirs if len(rel.parts)<15 and len((rel/d).as_posix())<=240 and not UI_PRIVATE.search((rel/d).as_posix()) and not DENY.search((rel/d).as_posix()) and not (Path(parent)/d).is_symlink())
            for name in sorted(files):
                visited+=1;need(visited<=20000,'UI_SCAN_LIMIT')
                path=(rel/name).as_posix()
                if not ui_valid(path):continue
                try:s=ui_stat(path,root)
                except OSError as exc:
                    if isinstance(exc,PermissionError):raise RuntimeError('UI_INVENTORY_OS_ACCESS_DENIED')
                    continue
                except RuntimeError:continue
                rows.append(dict(path=path,suffix=PurePosixPath(path).suffix.lower(),size=s.st_size))
                need(len(rows)<=5000,'UI_MATCH_LIMIT')
    rows.sort(key=lambda x:x['path'])
    return dict(format='UI_PATH_ONLY_V1',files=rows[offset:offset+limit],total=len(rows),next_offset=offset+limit if offset+limit<len(rows) else None,content_returned=False,source_writes=0,database_writes=0)
def need(ok,code):
    if not ok:raise RuntimeError(code)
def valid(path):
    if path=='ThinkPHP/Library/Think/Model.class.php':return True
    p=PurePosixPath(path)
    return isinstance(path,str) and len(path)<=240 and not p.is_absolute() and '..' not in p.parts and not DENY.search(path) and p.suffix.lower() in EXT and p.parts[0].lower() in {'application','tpl','template','templates','views','themes'} and (any(x.lower() in {'controller','model','view','views','tpl','template','templates','themes'} for x in p.parts) or path.startswith('Application/Common/Common/'))
def read(path,root=ROOT):
    need(valid(path),'SOURCE_PATH_DENIED')
    fd=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:
        for i,part in enumerate(PurePosixPath(path).parts):
            child=os.open(part,os.O_RDONLY|os.O_NOFOLLOW|(os.O_DIRECTORY if i<len(PurePosixPath(path).parts)-1 else 0),dir_fd=fd)
            os.close(fd);fd=child
        s=os.fstat(fd);need(stat.S_ISREG(s.st_mode) and s.st_nlink==1 and s.st_size<=500000,'SOURCE_FILE_UNSAFE')
        return os.read(fd,500001)
    finally:os.close(fd)
def tokenizer_argv(binary=PHP):
    probe=subprocess.run([binary,'-n','-r','exit(extension_loaded("tokenizer") ? 0 : 3);'],capture_output=True,timeout=5)
    need(probe.returncode in [0,3],'TOKENIZER_MODULE_PROBE_FAILED')
    return [binary,'-n']+(['-d','extension=tokenizer'] if probe.returncode==3 else [])
def sanitize(raw):
    p=subprocess.run(tokenizer_argv()+[str(Path(__file__).with_name('source-tokens.php'))],input=raw,capture_output=True,timeout=8)
    need(p.returncode==0,'TOKENIZER_FAILED')
    return json.loads(p.stdout)['source']
def inventory(root=ROOT):
    need(os.access(root/'Application',os.R_OK|os.X_OK),'SOURCE_ROOT_NOT_ACCESSIBLE')
    rows=[];count=0
    def fail(exc):raise RuntimeError('SOURCE_INVENTORY_INCOMPLETE')
    roots=[p for p in root.iterdir() if p.is_dir() and not p.is_symlink() and p.name.lower() in {'application','tpl','template','templates','views','themes'}]
    for base,dirs,files in (item for start in roots for item in os.walk(start,followlinks=False,onerror=fail)):
        rel=Path(base).relative_to(root).as_posix()
        dirs[:]=sorted(x for x in dirs if not DENY.search(rel+'/'+x) and not (Path(base)/x).is_symlink())
        for name in sorted(files):
            p=(Path(base)/name).relative_to(root).as_posix();count+=1
            need(count<30000,'SOURCE_INVENTORY_LIMIT')
            if valid(p) and not (root/p).is_symlink():rows.append(p)
    framework='ThinkPHP/Library/Think/Model.class.php'
    if (root/framework).is_file() and not (root/framework).is_symlink():rows.append(framework)
    return sorted(rows)
def operate(q,root=ROOT):
    need(isinstance(q,dict) and set(q)<= {'operation','path','query','offset','limit','table','columns','filters','analyst_id','start','end','month'},'REQUEST_SCHEMA')
    op=q.get('operation');need(op in {'inventory','source','search','permissions','db_permissions','schema','select','video_count','evaluation_summary','ui_inventory','ui_source'},'OPERATION_DENIED')
    if op=='ui_inventory':return ui_inventory(q,root)
    if op=='ui_source':return ui_source(q,root)
    if op in {'db_permissions','schema','select','video_count','evaluation_summary'}:
        need(Path('/etc/ppb-legacy-ro/credentials.json').exists(),'READONLY_DBA_PROVISIONING_REQUIRED')
        if op=='evaluation_summary':q=dict(q,operation='evaluation_snapshot',summary_only=True)
        p=subprocess.run([PHP,'-d','auto_prepend_file=','-d','auto_append_file=','-d','display_errors=0','-d','log_errors=0',str(Path(__file__).with_name('db-worker.php'))],input=json.dumps(q),capture_output=True,text=True,timeout=20)
        data=json.loads(p.stdout);need(p.returncode==0 and data.get('result')=='PASS',data.get('reason','READONLY_DB_STOP'));return data['data']
    offset=q.get('offset',0);limit=q.get('limit',30)
    need(type(offset)is int and 0<=offset<=30000 and type(limit)is int and 1<=limit<=(200 if op=='source' else 50),'PAGE_LIMIT')
    if op=='permissions':
        need(os.geteuid()>0,'NONROOT_REQUIRED')
        status=dict(line.split(':',1) for line in Path('/proc/self/status').read_text().splitlines() if ':' in line)
        need(int(status['CapEff'].strip(),16)==0 and status['NoNewPrivs'].strip()=='1','CAPABILITY_BOUNDARY_FAILED')
        flags=os.statvfs(root).f_flag
        need(bool(flags&os.ST_RDONLY),'LEGACY_MOUNT_NOT_READONLY')
        sudo=any(x.gr_name in {'sudo','wheel','www','mysql'} for x in __import__('grp').getgrall() if x.gr_gid in os.getgroups())
        need(not sudo,'UNSAFE_SUPPLEMENTARY_GROUP')
        return dict(uid=os.geteuid(),gid=os.getegid(),legacy_mount_readonly=True,no_privilege_groups=True,db_config_present=Path('/etc/ppb-legacy-ro/credentials.json').exists(),source_writes=0,database_writes=0)
    if op=='inventory':
        rows=inventory(root);return dict(paths=rows[offset:offset+limit],total=len(rows),next_offset=offset+limit if offset+limit<len(rows) else None)
    if op=='source':
        raw=read(q.get('path',''),root);safe=sanitize(raw);lines=safe.splitlines();return dict(path=q['path'],sanitized_sha256=hashlib.sha256(safe.encode()).hexdigest(),total_lines=len(lines),lines=[{'line':i+1,'text':line} for i,line in enumerate(lines) if offset<=i<offset+limit],redacted=True)
    needle=q.get('query','');need(isinstance(needle,str) and 1<=len(needle)<=60 and '\n' not in needle and not re.search('password|secret|token|credential',needle,re.I),'SEARCH_DENIED')
    rows=[]
    for path in inventory(root):
        try:
            raw=read(path,root)
            if needle.casefold() not in raw.decode('utf-8','replace').casefold() and needle.casefold() not in path.casefold():continue
            text=sanitize(raw).splitlines()
            # Paths/line coordinates only for matches hidden by conservative redaction.
            for i,line in enumerate(raw.decode('utf-8','replace').splitlines()):
                if needle.casefold() in line.casefold():rows.append({'path':path,'line':i+1,'text':text[i][:350] if i<len(text) else '[REDACTED]'})
                if len(rows)>=offset+limit:return dict(matches=rows[offset:offset+limit],truncated=True)
        except (OSError,RuntimeError):continue
    return dict(matches=rows[offset:offset+limit],truncated=False)
if __name__=='__main__':
    try:
        need(os.geteuid()>0,'NONROOT_REQUIRED')
        q=json.loads(sys.stdin.read(12001));out=dict(result='PASS',data=operate(q),source_writes=0,database_writes=0)
        raw=json.dumps(out,ensure_ascii=False);need(len(raw.encode())<18000,'RESULT_TOO_LARGE');print(raw)
    except Exception as exc:
        code=str(exc);print(json.dumps(dict(result='STOP',reason=code if re.fullmatch('[A-Z0-9_]{1,100}',code) else 'READONLY_OPERATION_FAILED')));raise SystemExit(2)
