import importlib.util,json,os,shutil,subprocess,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
def module(name):
 s=importlib.util.spec_from_file_location('legacy_'+name,ROOT/(name+'.py'));m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
w=module('worker');d=module('dispatch')
class Files(unittest.TestCase):
 def test_traversal_credentials_and_non_source_denied(self):
  for path in ['../db.php','/etc/passwd','Application/Common/Conf/db.php','Application/Admin/Controller/auth.php','Application/Admin/View/.env','Application/Admin/View/x.sql','Application/Runtime/index.php']:
   self.assertFalse(w.valid(path),path)
 def test_safe_file_read_and_no_symlink_or_hardlink_escape(self):
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp);base=root/'Application/Admin/Controller';base.mkdir(parents=True)
   source=base/'AssessmentController.class.php';source.write_text('<?php $score=2;')
   self.assertEqual(w.read(str(source.relative_to(root)),root),source.read_bytes())
   (base/'Link.php').symlink_to(source)
   with self.assertRaises(OSError):w.read('Application/Admin/Controller/Link.php',root)
   os.link(source,base/'Hard.php')
   with self.assertRaisesRegex(RuntimeError,'UNSAFE'):w.read(str(source.relative_to(root)),root)
 def test_inventory_never_returns_sensitive_directories(self):
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp)
   for rel in ['Application/Admin/Controller/Score.php','Application/Admin/Conf/db.php','Application/Admin/View/.env','Application/Admin/Controller/line.php']:
    p=root/rel;p.parent.mkdir(parents=True,exist_ok=True);p.write_text('fixture')
   self.assertEqual(w.inventory(root),['Application/Admin/Controller/Score.php'])
 def test_query_cannot_select_shell_sql_or_paths(self):
  for q in [{'operation':'shell','path':'sh'},{'operation':'source','path':'/etc/passwd'},{'operation':'inventory','sql':'DROP TABLE x'},{'operation':'inventory','limit':5000}]:
   with self.assertRaises(RuntimeError):w.operate(q)
 def test_source_has_no_write_file_open_or_application_execution(self):
  text=(ROOT/'worker.py').read_text()
  self.assertNotIn('O_WRONLY',text);self.assertNotIn('shell=True',text)
  cmd=d.command({},['/www/wwwroot/3.231.229.26/Application/Common/Conf'])
  for setting in ['--property=ProtectSystem=strict','--property=NoNewPrivileges=true','--property=CapabilityBoundingSet=','--property=User=ppb-legacy-ro','--property=PrivateNetwork=true']:
   self.assertIn(setting,cmd)
  self.assertFalse(any('ReadWritePaths=' in x for x in cmd))
class Redaction(unittest.TestCase):
 def setUp(self):
  self.php=shutil.which('php')
  if not self.php:self.skipTest('PHP required')
 def safe(self,raw):
  p=subprocess.run([self.php,'-n',str(ROOT/'source-tokens.php')],input=raw,capture_output=True,text=True);self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)['source']
 def test_password_tokens_names_comments_and_html_never_exported(self):
  raw='''<p>Private Person private@example.com</p><?php
// secret abc987
$password='very-private'; $config['password']='short';
const WP_APP_PWD='key-forbidden';
$name='王小明'; $phone=13800138000;
$score=2.5; $row['analyst_id']=12; $model=M('analyst_pick');
'''
  safe=self.safe(raw)
  for secret in ['Private Person','private@example.com','abc987','very-private','short','key-forbidden','王小明','13800138000']:
   self.assertNotIn(secret,safe)
  self.assertIn('$score=2.5',safe);self.assertIn("M('analyst_pick')",safe)
  self.assertEqual(raw.count('\n'),safe.count('\n'))
 def test_sql_values_and_escaped_or_unbalanced_sql_fail_closed(self):
  raw='''<?php $sql="SELECT id,score FROM assessment WHERE name='PrivatePerson' /* internal secret */";'''
  safe=self.safe(raw);self.assertIn('assessment',safe);self.assertNotIn('PrivatePerson',safe);self.assertNotIn('internal secret',safe)
  for raw in [r'''<?php $sql='SELECT id FROM assessment WHERE name=\'PrivatePerson\'';''', '''<?php $sql='SELECT id FROM assessment WHERE name="PrivatePerson';''']:
   self.assertNotIn('PrivatePerson',self.safe(raw))
 def test_application_code_is_never_executed(self):
  safe=self.safe("<?php throw new Exception('must-not-execute'); file_put_contents('/tmp/no-create','x');")
  self.assertIn('file_put_contents',safe)
if __name__=='__main__':unittest.main()

class SQLBoundary(unittest.TestCase):
 def setUp(self):
  self.php=shutil.which('php')
  if not self.php:self.skipTest('PHP required')
 def php_case(self,body):
  code="require "+json.dumps(str(ROOT/'db-worker.php'))+";try{"+body+"}catch(Throwable $e){echo $e->getMessage();exit(2);}"
  return subprocess.run([self.php,'-r',code],capture_output=True,text=True)
 def test_column_select_and_metadata_privileges_are_the_only_grants(self):
  prefix="$c=['schema'=>'old','tables'=>['old.scores'=>['data_columns'=>['id','score']]]];"
  grants=['GRANT USAGE ON *.* TO u','GRANT REFERENCES, SHOW VIEW ON `old`.* TO u','GRANT SELECT (`id`, `score`) ON `old`.`scores` TO u']
  result=self.php_case(prefix+'echo json_encode(checkedGrants(json_decode('+json.dumps(json.dumps(grants))+',true),$c));')
  self.assertEqual(result.returncode,0,result.stdout+result.stderr)
  for bad in ['GRANT SELECT ON `old`.* TO u','GRANT INSERT ON `old`.`scores` TO u','GRANT SELECT (`password`) ON `old`.`scores` TO u','GRANT EXECUTE ON *.* TO u','GRANT FILE ON *.* TO u','GRANT SELECT (`id`) ON `other`.`scores` TO u','GRANT REFERENCES, SHOW VIEW ON `other`.* TO u']:
   result=self.php_case(prefix+'checkedGrants(json_decode('+json.dumps(json.dumps(grants+[bad]))+',true),$c);')
   self.assertEqual(result.returncode,2,bad)
 def test_request_filters_are_bound_and_cannot_be_sql(self):
  q={'columns':['id','score'],'filters':[{'column':'id','op':'=','value':"1 OR 1=1; DROP TABLE scores"}],'limit':5,'offset':0}
  body='$t=[\'schema\'=>\'old\',\'name\'=>\'scores\'];echo json_encode(selectPlan(json_decode('+json.dumps(json.dumps(q))+',true),$t,[\'id\',\'score\']));'
  result=self.php_case(body);self.assertEqual(result.returncode,0,result.stdout+result.stderr)
  sql,params=json.loads(result.stdout);self.assertNotIn('DROP',sql);self.assertEqual(params,[q['filters'][0]['value']])
  for changes in [{'columns':['password']},{'columns':['id; DROP TABLE scores']},{'limit':10000},{'offset':-1},{'filters':[{'column':'id','op':';DROP','value':1}]}]:
   bad=dict(q,**changes);result=self.php_case('$t=[\'schema\'=>\'old\',\'name\'=>\'scores\'];selectPlan(json_decode('+json.dumps(json.dumps(bad))+',true),$t,[\'id\',\'score\']);');self.assertEqual(result.returncode,2)
 def test_dba_policy_never_grants_free_text_or_pii_or_view_data(self):
  m=module('dba-once')
  self.assertTrue(m.safe_column('pay_analyst_pick','analyst_name','int','BASE TABLE'))
  self.assertTrue(m.safe_column('assessment','score','decimal','BASE TABLE'))
  for table,col,typ,kind in [('assessment','phone','bigint','BASE TABLE'),('assessment','secret','int','BASE TABLE'),('assessment','notes','text','BASE TABLE'),('assessment','label','varchar','BASE TABLE'),('assessment','score','int','VIEW'),('users','id','int','BASE TABLE')]:
   self.assertFalse(m.safe_column(table,col,typ,kind))
