import importlib.util,json,socket,struct,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def module(name):
 spec=importlib.util.spec_from_file_location(name,ROOT/(name+'.py'));m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
b=module('bridge');installer=module('bridge-install')
class Connection:
 def __init__(self,uid,query):self.uid=uid;self.query=json.dumps(query).encode()+b'\n';self.output=b''
 def getsockopt(self,*a):return struct.pack('3i',123,self.uid,456)
 def settimeout(self,value):pass
 def recv(self,size):data=self.query;self.query=b'';return data
 def sendall(self,data):self.output+=data
class Bridge(unittest.TestCase):
 def test_only_application_uid_can_reach_the_fixed_reader(self):
  for uid in [0,997,9999]:
   calls=[];connection=Connection(uid,{'operation':'db_permissions'})
   b.handle(connection,1001,lambda q:calls.append(q))
   self.assertFalse(calls);self.assertEqual(json.loads(connection.output),{'result':'STOP','reason':'APPLICATION_PEER_DENIED'})
  calls=[];connection=Connection(1001,{'operation':'db_permissions'})
  b.handle(connection,1001,lambda q:(calls.append(q) or {'result':'PASS','data':{'safe':True}}))
  self.assertEqual(len(calls),1);self.assertEqual(json.loads(connection.output)['result'],'PASS')
 def test_source_shell_and_unrelated_business_tables_are_not_application_operations(self):
  for q in [{'operation':'source','path':'Application/X.php'},{'operation':'shell'},{'operation':'select','table':'pay.pay_order','columns':['id']},{'operation':'select','table':'pay.pay_admin','columns':['id']}]:
   with self.assertRaises(RuntimeError):b.validate(q)
 def test_unit_is_nonroot_socket_only_and_cannot_access_either_application_tree(self):
  values=installer.units('/tmp/mysql.sock');unit=values['ppb-v2-legacy-reader.service'];sock=values['ppb-v2-legacy-reader.socket']
  for setting in ['User=ppb-legacy-ro','Group=ppb-legacy-ro','ProtectSystem=strict','ProtectHome=true','PrivateNetwork=true','RestrictAddressFamilies=AF_UNIX','NoNewPrivileges=true','CapabilityBoundingSet=']:
   self.assertIn(setting,unit)
  self.assertNotIn('ReadWritePaths=',unit);self.assertIn('/www/wwwroot/3.231.229.26',unit);self.assertIn('/www/wwwroot/ppbaos.com',unit)
  self.assertIn('SocketGroup=ppb-v2',sock);self.assertIn('SocketMode=0660',sock)
 def test_socket_path_cannot_inject_unit_properties_or_escape(self):
  for path in ['/tmp/mysql.sock\nUser=root','/tmp/mysql.sock:/etc/passwd','/tmp/../etc/mysql.sock','relative.sock']:
   with self.assertRaises(RuntimeError):installer.units(path)
 def test_raw_exception_and_input_are_not_returned(self):
  connection=Connection(1001,{'operation':'db_permissions'})
  def fail(q):raise RuntimeError('password private@example.com')
  b.handle(connection,1001,fail)
  self.assertEqual(json.loads(connection.output),{'result':'STOP','reason':'APPLICATION_READER_UNAVAILABLE'})
