import importlib.util,json,os,shutil,subprocess,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
def module(name):
 s=importlib.util.spec_from_file_location('legacy_'+name,ROOT/(name+'.py'));m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
w=module('worker');d=module('dispatch')
class ManagementProbe(unittest.TestCase):
 def test_profile_names_are_only_headers_not_passwords_or_options(self):
  m=module('management-probe')
  self.assertEqual(m.profile_names('[local_dba]\nuser=root\npassword=NEVER_EXPORT\n[bad/name]\n[client]\n'),['client','local_dba'])
 def test_privilege_proof_requires_global_all_with_grant_option(self):
  m=module('management-probe')
  self.assertTrue(m.admin_capability(["GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' IDENTIFIED BY PASSWORD '*PRIVATE' WITH GRANT OPTION"]))
  for grant in ["GRANT ALL PRIVILEGES ON old.* TO 'u'@'localhost' WITH GRANT OPTION","GRANT ALL PRIVILEGES ON *.* TO 'u'@'localhost'","GRANT SELECT ON *.* TO 'u'@'localhost' WITH GRANT OPTION"]:
   self.assertFalse(m.admin_capability([grant]))
 def test_authentication_errors_and_hashes_cannot_be_exported(self):
  m=module('management-probe')
  from types import SimpleNamespace
  p=SimpleNamespace(returncode=1,stdout='PRIVATE',stderr='PASSWORD_PRIVATE')
  with patch.object(m,'invoke',return_value=p):
   self.assertEqual(m.connection([], '/tmp/mysql.sock','credentialless-root'),{'method':'credentialless-root','result':'UNAVAILABLE','reason':'CLIENT_FAILED_NO_SAFE_ERROR_CODE'})
  p=SimpleNamespace(returncode=0,stdout="5.7.44-log\troot@localhost\t/tmp/mysql.sock\nGRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' IDENTIFIED BY PASSWORD '*PRIVATE' WITH GRANT OPTION\n",stderr='')
  with patch.object(m,'invoke',return_value=p):
   result=m.connection([], '/tmp/mysql.sock','credentialless-root')
   self.assertTrue(result['required_privileges_proven']);self.assertNotIn('PRIVATE',json.dumps(result))
 def test_mysql_failure_exports_only_fixed_reason_or_numeric_code(self):
  m=module('management-probe')
  from types import SimpleNamespace
  self.assertEqual(m.failure(SimpleNamespace(stderr="ERROR 1045: private-user SECRET")),'MYSQL_ERROR_1045')
  self.assertEqual(m.failure(SimpleNamespace(stderr="unknown option secret=value")),'CLIENT_OPTION_UNSUPPORTED')
 def test_mysql57_passwordless_probe_ignores_login_file_without_unsupported_option(self):
  m=module('management-probe')
  with patch.object(m.subprocess,'run') as run:
   m.invoke(['mysql','--no-defaults','--skip-password'])
   self.assertEqual(run.call_args.kwargs['env']['MYSQL_TEST_LOGIN_FILE'],'/dev/null')
  with patch.object(m.subprocess,'run') as run:
   m.invoke(['mysql','--no-defaults','--login-path=existing'])
   self.assertNotIn('MYSQL_TEST_LOGIN_FILE',run.call_args.kwargs['env'])
  self.assertNotIn("'--no-login-paths'",(ROOT/'management-probe.py').read_text())
 def test_probe_has_no_business_sql_or_mutation_and_no_application_paths(self):
  m=module('management-probe');text=(ROOT/'management-probe.py').read_text()
  self.assertNotIn('shell=True',text);self.assertNotIn('/www/wwwroot',text)
  for sql in ['CREATE USER \'','GRANT SELECT','ALTER USER','INSERT INTO','UPDATE mysql','DELETE FROM']:
   self.assertNotIn(sql,text)
  with patch.object(m.os,'geteuid',return_value=997):self.assertEqual(m.run(),{'result':'AUTHORIZED_ROOT_REQUIRED'})
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
  p=subprocess.run(w.tokenizer_argv(self.php)+[str(ROOT/'source-tokens.php')],input=raw,capture_output=True,text=True);self.assertEqual(p.returncode,0,p.stdout+p.stderr);return json.loads(p.stdout)['source']
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

 def test_array_values_stay_private_but_program_keys_and_format_context_remain(self):
  raw="<?php $keys=['private-array-value']; $x=$row['score_delivery_time']; $x=date('Y-m'); $n=I('post.score',0,'intval');"
  safe=self.safe(raw)
  self.assertNotIn('private-array-value',safe)
  for code in ["$row['score_delivery_time']","date('Y-m')","I('post.score',0,'intval')"]:self.assertIn(code,safe)
 def test_sql_month_formats_are_visible_but_literal_personal_data_is_not(self):
  safe=self.safe("<?php $m->field(\"SUM(CASE WHEN is_win=1 THEN 1 ELSE 0 END) AS win\"); $m->where(['_string'=>\"DATE_FORMAT(recommended_time,'%Y-%m')='PrivateName'\"]);")
  self.assertIn('SUM(CASE WHEN is_win=1',safe);self.assertIn('%Y-%m',safe);self.assertNotIn('PrivateName',safe)

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
  profile=prefix.replace('old.scores','old.analyst_profiles');profile_grants=[g.replace('`scores`','`analyst_profiles`') for g in grants]
  check=self.php_case(profile+'echo json_encode(checkedGrants(json_decode('+json.dumps(json.dumps(profile_grants))+',true),$c));');self.assertEqual(check.returncode,0,check.stdout+check.stderr)
  self.assertEqual(result.returncode,0,result.stdout+result.stderr)
  for bad in ['GRANT SELECT ON `old`.* TO u','GRANT INSERT ON `old`.`scores` TO u','GRANT SELECT (`password`) ON `old`.`scores` TO u','GRANT EXECUTE ON *.* TO u','GRANT FILE ON *.* TO u','GRANT SUPER ON *.* TO u','GRANT PROXY ON x TO u','GRANT SELECT (`id`) ON `old`.`scores` TO u WITH GRANT OPTION','GRANT SELECT (`id`) ON `other`.`scores` TO u','GRANT REFERENCES, SHOW VIEW ON `other`.* TO u']:
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

class DocumentContract(unittest.TestCase):
 def test_ssm_regex_is_compatible_with_bounded_repetition_engine(self):
  doc=json.loads((ROOT.parent/'deploy/PPB-V2-Controlled-Deploy.isolated.ssm.json').read_text())
  self.assertEqual(doc['parameters']['Mode']['default'],'release')
  self.assertEqual(doc['parameters']['Mode']['allowedValues'],['release','legacy_readonly'])
  for value in doc['parameters'].values():
   import re
   for count in re.findall(r'\{[0-9]+,([0-9]+)\}',value.get('allowedPattern','')):self.assertLessEqual(int(count),1000)
  self.assertIn("len(encoded)<=14000",(ROOT/'dispatch.py').read_text())
 def test_old_document_is_rejected_before_any_command(self):
  m=module('client');calls=[]
  def call(*args):
   calls.append(args)
   if args[:2]==('sts','get-caller-identity'):return {'Account':m.c.ACCOUNT}
   if args[:2]==('ssm','get-document'):return {'Content':'{}'}
   self.fail('Unexpected AWS operation')
  result=m.invoke({'operation':'inventory'},call)
  self.assertEqual(result['reason'],'REVIEWED_READER_NOT_INSTALLED');self.assertIsNone(result['ssm_command_id'])
  self.assertEqual(len(calls),2)

class DBAConnection(unittest.TestCase):
 def test_ephemeral_admin_requires_real_interactive_session_and_quotes_client_options(self):
  m=module('dba-authenticated-once')
  with patch.object(m.os,'geteuid',return_value=997):
   with self.assertRaisesRegex(RuntimeError,'EXISTING_INTERACTIVE'):m.run()
  with patch.object(m.os,'geteuid',return_value=0),patch.object(m.sys.stdin,'isatty',return_value=False):
   with self.assertRaisesRegex(RuntimeError,'EXISTING_INTERACTIVE'):m.run()
  self.assertEqual(m.quote('a"b\\c\nuser=other'), '"a\\"b\\\\c\\nuser=other"')
  self.assertNotIn("'--no-login-paths'",(ROOT/'dba-once.py').read_text())
 def test_no_implicit_connection_or_old_application_config_fallback(self):
  m=module('dba-once')
  for args in [{},{'defaults_file':'/www/wwwroot/3.231.229.26/Application/Common/Conf/db.php'},{'login_path':'../escape'},{'login_path':'a','defaults_file':'/root/.my.cnf'}]:
   with self.assertRaises(RuntimeError):m.management_options(**args)
  self.assertEqual(m.management_options(login_path='existing-approved-dba'),['--no-defaults','--login-path=existing-approved-dba'])

if __name__=='__main__':unittest.main()
