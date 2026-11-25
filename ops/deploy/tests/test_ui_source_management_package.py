import hashlib,importlib.util,json,tempfile,unittest,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('ui_source_management_package',ROOT/'package-ui-source.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class ManagementPackage(unittest.TestCase):
 def test_reproducible_exact_source_only_package(self):
  with tempfile.TemporaryDirectory() as tmp:
   a=Path(tmp)/'a.zip';b=Path(tmp)/'b.zip';self.assertEqual(m.build(a),m.build(b))
   with zipfile.ZipFile(a) as z:
    self.assertEqual(set(z.namelist()),set(m.NAMES)|{'SHA256.json'})
    manifest=json.loads(z.read('SHA256.json'))
    self.assertEqual(manifest['format'],'PPB_UI_SOURCE_MANAGEMENT_V1')
    self.assertEqual(manifest['source_main'],'7b4a7e45585f98b5c6bf04ff44c328130ba3998a')
    for name,sha in manifest['files'].items():self.assertEqual(hashlib.sha256(z.read(name)).hexdigest(),sha)
    self.assertFalse(any('..' in Path(n).parts or Path(n).is_absolute() or n.endswith(('.env','.cnf','.key')) for n in z.namelist()))
    target=Path(tmp)/'standalone';z.extractall(target)
   spec=importlib.util.spec_from_file_location('standalone_ui_source_manager',target/'manage-ui-source.py');manager=importlib.util.module_from_spec(spec);spec.loader.exec_module(manager)
   activation=manager.load_activation();self.assertEqual(activation.ROOT,target);self.assertEqual(activation.c.DOC,'PPB-V2-Controlled-Deploy')
   (target/'release-client.py').write_text('UNREVIEWED')
   with self.assertRaisesRegex(RuntimeError,'PACKET_PIN_MISMATCH'):manager.load_activation()
