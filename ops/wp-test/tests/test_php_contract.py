"""Execute real PHP producer -> real JSON file writer -> Python SSM collector.
WordPress HTTP alone is a fixture; these results never establish live WP success.
"""
import importlib.util
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import sys
import unittest

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
import remote
spec=importlib.util.spec_from_file_location('php_contract_collector',ROOT/'result-envelope.py')
collector=importlib.util.module_from_spec(spec);spec.loader.exec_module(collector)
EXECUTION=dict(commit='a'*40,run_id='12345',run_attempt='1')

class PhpPythonContractTests(unittest.TestCase):
    def report(self,case='success'):
        command=shlex.split(os.environ.get('PPB_TEST_PHP','php'))
        self.assertIsNotNone(shutil.which(command[0]),'PHP is required for cross-language integration tests')
        env=dict(os.environ,PPB_WP_GITHUB_COMMIT=EXECUTION['commit'],PPB_WP_GITHUB_RUN_ID=EXECUTION['run_id'],PPB_WP_GITHUB_RUN_ATTEMPT=EXECUTION['run_attempt'])
        p=subprocess.run([*command,str(ROOT.parents[1]/'tools/wpn2/test.php'),'--emit-report',case],env=env,capture_output=True,text=True,check=True,timeout=30)
        return json.loads(p.stdout)
    def test_php_persisted_report_passes_actual_python_collector_and_controller(self):
        report=self.report()
        self.assertIs(type(report['media_verified_image_count']),int)
        self.assertEqual(report['media_verified_image_count'],6)
        envelope=collector.collect(report,EXECUTION,0)
        self.assertEqual(envelope['result'],'PASS')
        result=remote.initial(EXECUTION['commit'],EXECUTION['run_id'],EXECUTION['run_attempt'])
        validated=remote.validate_envelope(remote.PREFIX+json.dumps(envelope),result)
        self.assertEqual(validated['acf_matched'],43)
        self.assertEqual(set(validated['recommendations']),set(collector.REC_KEYS))
        self.assertEqual(list(validated['recommendations'].values()),['Codex測試']*6)
    def test_actual_php_failed_media_reports_five_and_python_stops(self):
        report=self.report('media_failure')
        self.assertEqual(report['media_verified_image_count'],5)
        self.assertEqual(report['stop_code'],'SIX_MEDIA_NOT_VERIFIED')
        self.assertFalse(report['wp_test_success'])
        self.assertEqual(collector.collect(report,EXECUTION,2)['result'],'STOP')
    def test_missing_count_is_not_defaulted_to_six(self):
        report=self.report();del report['media_verified_image_count']
        self.assertEqual(collector.collect(report,EXECUTION,0)['result'],'STOP')
    def test_corrupted_real_report_never_passes(self):
        report=self.report()
        for field,value in [('media_verified_image_count',5),('ACF_MATCHED',42),('READBACK_HTTP',500)]:
            changed=dict(report,**{field:value})
            self.assertEqual(collector.collect(changed,EXECUTION,0)['result'],'STOP')
        report['recommendations'][collector.REC_KEYS[0]]='wrong'
        self.assertEqual(collector.collect(report,EXECUTION,0)['result'],'STOP')
    def test_previous_attempt_pass_cannot_satisfy_this_attempt(self):
        report=self.report()
        self.assertEqual(collector.collect(report,dict(EXECUTION,run_attempt='2'),0)['result'],'STOP')
