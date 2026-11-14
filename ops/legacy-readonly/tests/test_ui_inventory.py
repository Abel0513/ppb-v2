import importlib.util,os,tempfile,unittest
from pathlib import Path
s=importlib.util.spec_from_file_location('ui_worker',Path(__file__).resolve().parents[1]/'worker.py');w=importlib.util.module_from_spec(s);s.loader.exec_module(w)

class UiInventory(unittest.TestCase):
 def test_tpl_and_scoped_public_assets_are_metadata_only(self):
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp)
   allowed=['Application/Admin/View/AnalystRating/salary.tpl','Public/css/rating.css','Public/js/rating.js','Public/assets/icons/calendar.svg','Public/Admin/js/modal.js']
   denied=['Public/other/private.js','Public/uploads/export.css','Public/css/.hidden.css','Application/Admin/Conf/password.tpl','Application/Admin/View/db.php','Public/css/api-token.js','Public/css/data.json']
   for name in allowed+denied:
    p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text('NEVER_EXPORT_CONTENT')
   report=w.operate({'operation':'ui_inventory'},root)
   self.assertEqual([x['path'] for x in report['files']],sorted(allowed));self.assertFalse(report['content_returned'])
   self.assertNotIn('NEVER_EXPORT_CONTENT',str(report));self.assertFalse(w.valid(allowed[0]));self.assertFalse(w.valid('Public/css/rating.css'))
 def test_links_oversized_and_traversal_never_pass(self):
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp);base=root/'Application/Admin/View';base.mkdir(parents=True)
   p=base/'base.tpl';p.write_text('private');os.link(p,base/'hard.tpl');(base/'link.tpl').symlink_to(p)
   (base/'big.tpl').write_bytes(b'x'*500001)
   self.assertEqual(w.ui_inventory({'operation':'ui_inventory'},root)['total'],0)
   for path in ['../x.tpl','/etc/passwd','Public/css/../../private.tpl','Application/Admin/View/.env','Application/Admin/View/'+('a/'*17)+'x.tpl']:
    self.assertFalse(w.ui_valid(path))
 def test_pages_and_query_schema_are_bounded(self):
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp);base=root/'Application/Admin/View';base.mkdir(parents=True)
   for i in range(3):(base/f'{i}.tpl').write_text('x')
   page=w.ui_inventory({'operation':'ui_inventory','limit':2},root);self.assertEqual(page['total'],3);self.assertEqual(page['next_offset'],2)
   self.assertEqual(len(w.ui_inventory({'operation':'ui_inventory','offset':2,'limit':2},root)['files']),1)
   for extra in [{'limit':51},{'offset':-1},{'path':'/etc/passwd'},{'limit':True}]:
    with self.assertRaises(RuntimeError):w.ui_inventory({'operation':'ui_inventory',**extra},root)
