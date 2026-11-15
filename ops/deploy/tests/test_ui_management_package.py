import hashlib,importlib.util,json,tempfile,unittest,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('management_package',ROOT/'package-ui-management.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class ManagementPackage(unittest.TestCase):
 def test_reproducible_exact_source_only_package(self):
  with tempfile.TemporaryDirectory() as tmp:
   a=Path(tmp)/'a.zip';b=Path(tmp)/'b.zip';self.assertEqual(m.build(a),m.build(b))
   with zipfile.ZipFile(a) as z:
    self.assertEqual(set(z.namelist()),set(m.NAMES)|{'SHA256.json'})
    manifest=json.loads(z.read('SHA256.json'))
    for name,sha in manifest['files'].items():self.assertEqual(hashlib.sha256(z.read(name)).hexdigest(),sha)
    self.assertFalse(any('..' in Path(n).parts or Path(n).is_absolute() or n.endswith(('.env','.cnf','.key')) for n in z.namelist()))
