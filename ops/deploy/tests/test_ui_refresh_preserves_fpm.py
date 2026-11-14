"""Execute the generated installer only in a temporary tree with no real commands."""
import importlib.util,json,os,subprocess,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]

class ReaderRefresh(unittest.TestCase):
 def test_existing_r2_files_never_reprovisioned_or_changed(self):
  spec=importlib.util.spec_from_file_location('ui_bootstrap',ROOT/'isolation/bootstrap-document.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
  digest,doc=m.document();lines=doc['mainSteps'][2]['inputs']['runCommand'];code='\n'.join(lines[3:-1])
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp)/'private';root.mkdir(mode=0o700);(root/'backups').mkdir(mode=0o700)
   archive=root/'bootstrap'/digest/'fixed.tar.gz';archive.parent.mkdir(parents=True);m.m.build(archive)
   fpm=Path(tmp)/'fpm.conf';unit=Path(tmp)/'fpm.service';policy=root/'policy/isolation.json';policy.parent.mkdir()
   for p in [fpm,unit,policy]:p.write_text('REVIEWED_R2_BYTES');p.chmod(0o600)
   code=code.replace("Path('/www/ppb-v2-deploy')",'Path('+repr(str(root))+')').replace("Path('/etc/ppb-v2/php-fpm.conf')",'Path('+repr(str(fpm))+')').replace("Path('/etc/systemd/system/ppb-v2-php.service')",'Path('+repr(str(unit))+')')
   calls=[]
   def run(argv,**kwargs):
    calls.append(argv)
    if any(str(x).endswith(('prepare-os.py','provision.py')) for x in argv):raise AssertionError('Existing R2 must not be provisioned.')
    return subprocess.CompletedProcess(argv,0,json.dumps({'result':'PASS'}),'')
   real_stat=os.stat;real_lstat=os.lstat
   def owned(fn):
    def check(*args,**kwargs):
     st=list(fn(*args,**kwargs));st[4]=0;return os.stat_result(st)
    return check
   with patch('os.stat',owned(real_stat)),patch('os.lstat',owned(real_lstat)),patch('subprocess.run',run):exec(compile(code,'fixed-ui-refresh','exec'),{})
   for p in [fpm,unit,policy]:self.assertEqual(p.read_text(),'REVIEWED_R2_BYTES')
   self.assertTrue(any('inventory' in argv for argv in calls))
