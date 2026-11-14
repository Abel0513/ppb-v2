import hashlib,importlib.util,json,os,stat,tempfile,unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('evaluation_domains',ROOT/'evaluation-domains.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Extension(unittest.TestCase):
 def fixture(self,root):
  tables={}
  for table,columns in m.PLAN.items():tables['pay.'+table]={'schema':'pay','name':table,'kind':'BASE TABLE','data_columns':['id'],'types':dict({'id':'int'},**{c:'varchar' for c in columns})}
  catalog={'schema':'pay','tables':tables}
  (root/'catalog.json').write_text(json.dumps(catalog));(root/'catalog.json').chmod(0o640)
  (root/'credentials.json').write_text('{"synthetic":"not read by extension"}');(root/'credentials.json').chmod(0o640)
  return catalog
 def execute(self,root,catalog,bad_domain=False,bad_grants=False,fail_grant=None):
  calls=[];original=Path.stat;gid=os.getgid()
  def metadata(path,*args,**kwargs):
   result=original(path,*args,**kwargs)
   if path==root or path.parent==root:return SimpleNamespace(st_mode=result.st_mode,st_uid=0,st_gid=gid,st_nlink=result.st_nlink)
   return result
  def sql(stage,query):
   calls.append((stage,query))
   if stage=='EXTENSION_VERSION':return '5.7.44-log\n'
   if stage=='EXTENSION_DBA_GRANTS':return "GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' WITH GRANT OPTION\n"
   if stage=='EXTENSION_ACCOUNT':return '1\n'
   if stage=='EXTENSION_READER_GRANTS':
    grants=['GRANT USAGE ON *.* TO u','GRANT REFERENCES, SHOW VIEW ON `pay`.* TO u']
    grants += ['GRANT SELECT ('+','.join('`'+c+'`' for c in t['data_columns']+t.get('join_columns',[]))+') ON `pay`.`'+t['name']+'` TO u' for t in catalog['tables'].values()]
    return '\n'.join(grants+(['GRANT INSERT ON `pay`.* TO u'] if bad_grants else []))
   if stage=='EXTENSION_COLUMN_METADATA':return 'varchar\n'
   if stage=='EXTENSION_DOMAIN_VALIDATION':return '1\n' if bad_domain else '0\n'
   if stage=='EXTENSION_COLUMN_GRANT':
    if fail_grant and sum(s==stage for s,q in calls)==fail_grant:raise RuntimeError('SYNTHETIC_GRANT_FAILURE')
    return ''
   raise AssertionError(stage)
  def need(ok,reason):
   if not ok:raise RuntimeError(reason)
  api=SimpleNamespace(CONFIG=root,need=need,ident=lambda v:'`'+v+'`',management_options=lambda *a:[],sql_session=lambda *a:sql)
  with patch.object(m.os,'geteuid',return_value=0),patch.object(m.grp,'getgrnam',return_value=SimpleNamespace(gr_gid=gid)),patch.object(Path,'stat',metadata),patch.object(m.os,'chown'),patch.object(m.os,'fchown'):
   try:result=m.enable(api,'explicit-dba','mysql',None)
   except Exception as exc:return None,calls,exc
  return result,calls,None
 def test_success_preserves_original_catalog_and_credentials_and_only_adds_column_select(self):
  with tempfile.TemporaryDirectory() as temp:
   root=Path(temp);catalog=self.fixture(root);before=(root/'catalog.json').read_bytes();secret=(root/'credentials.json').read_bytes()
   result,calls,error=self.execute(root,catalog);self.assertIsNone(error)
   self.assertEqual(result['typed_columns'],4);self.assertEqual(result['private_join_columns'],4)
   self.assertEqual((root/'catalog.before-evaluation-read-v1.json').read_bytes(),before)
   self.assertEqual((root/'credentials.json').read_bytes(),secret)
   self.assertTrue(json.loads((root/'catalog.json').read_text())['evaluation_read_v1'])
   grants=[q for s,q in calls if s=='EXTENSION_COLUMN_GRANT'];self.assertEqual(len(grants),6)
   self.assertTrue(all(q.startswith('GRANT SELECT (') and '.*' not in q for q in grants))
 def test_bad_domain_stops_before_any_grant_or_catalog_replacement(self):
  with tempfile.TemporaryDirectory() as temp:
   root=Path(temp);catalog=self.fixture(root);before=(root/'catalog.json').read_bytes()
   result,calls,error=self.execute(root,catalog,bad_domain=True)
   self.assertIn('DOMAIN_NOT_PROVEN',str(error));self.assertFalse(any(s=='EXTENSION_COLUMN_GRANT' for s,q in calls))
   self.assertEqual((root/'catalog.json').read_bytes(),before);self.assertFalse((root/'catalog.before-evaluation-read-v1.json').exists())
 def test_partial_grant_keeps_original_catalog_and_backup_and_stops(self):
  with tempfile.TemporaryDirectory() as temp:
   root=Path(temp);catalog=self.fixture(root);before=(root/'catalog.json').read_bytes()
   result,calls,error=self.execute(root,catalog,fail_grant=2)
   self.assertIsNotNone(error);self.assertEqual(sum(s=='EXTENSION_COLUMN_GRANT' for s,q in calls),2)
   self.assertEqual((root/'catalog.json').read_bytes(),before)
   self.assertEqual((root/'catalog.before-evaluation-read-v1.json').read_bytes(),before)
 def test_unexpected_write_grant_prevents_extension(self):
  with tempfile.TemporaryDirectory() as temp:
   root=Path(temp);catalog=self.fixture(root)
   result,calls,error=self.execute(root,catalog,bad_grants=True)
   self.assertIsNotNone(error);self.assertFalse(any(s=='EXTENSION_COLUMN_GRANT' for s,q in calls))
