import json,shutil,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class Snapshot(unittest.TestCase):
 def test_month_validation_happens_before_database_access(self):
  # A PDO stub also works with the minimal no-extension PHP syntax checker.
  code='''if(!class_exists('PDO')){class PDO{}} class ProbePDO extends PDO {function __construct(){}} require $argv[1]; $pdo=new ProbePDO; foreach([['analyst_id'=>2,'month'=>'2026-13'],['analyst_id'=>'2','month'=>'2026-05'],['analyst_id'=>2,'month'=>'2026-05 OR 1']] as $q){try{evaluationSnapshot($pdo,$q,['evaluation_read_v1'=>true],[]);exit(3);}catch(RuntimeException $e){echo $e->getMessage()."\\n";}}'''
  p=subprocess.run([shutil.which('php'),'-r','function must($v,$c){if(!$v)throw new RuntimeException($c);} '+code,str(ROOT/'evaluation-snapshot.php')],capture_output=True,text=True)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stdout.splitlines(),['EVALUATION_MONTH_DENIED','ANALYST_ID_DENIED','EVALUATION_MONTH_DENIED'])
 def test_atomic_snapshot_and_stable_bounded_global_recent(self):
  source=(ROOT/'evaluation-snapshot.php').read_text();db=(ROOT/'db-worker.php').read_text()
  self.assertIn('SNAPSHOT_REQUIRES_TRANSACTIONAL_TABLES',source)
  self.assertIn('SNAPSHOT_ENGINE_VISIBILITY_INCOMPLETE',source)
  self.assertIn('ORDER BY recommended_time DESC,id DESC LIMIT 20',source)
  self.assertIn('SNAPSHOT_COVERAGE_LIMIT',source)
  self.assertIn('READ ONLY',db);self.assertIn('REPEATABLE READ',db);self.assertIn("$pdo->rollBack()",db)
  self.assertNotIn('SELECT *',source);self.assertNotIn('analyst_name,',source)
 def test_summary_is_only_remote_operation_raw_snapshot_is_local_v2_only(self):
  source=(ROOT/'worker.py').read_text();bridge=(ROOT/'bridge.py').read_text()
  self.assertIn("if op=='evaluation_summary':q=dict(q,operation='evaluation_snapshot',summary_only=True)",source)
  self.assertIn("'evaluation_snapshot'",bridge)
  self.assertNotIn("'evaluation_snapshot'",source.split("op=q.get('operation');need(op in ")[1].split('},')[0])
