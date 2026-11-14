"""Offline regression: reviewed V2-only PHP-FPM cache and isolation repair."""
import importlib.util
import json
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / 'isolation' / 'enable-reader-open-basedir-once.py'
SPEC = importlib.util.spec_from_file_location('ppb_reader_fpm_one_time_r2', SOURCE)
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)


class PpbReaderFpmRecoveryR2Tests(unittest.TestCase):
    def test_only_exact_socket_is_allowlisted(self):
        initial = '[ppb-v2]\n' + M.OLD + '\n'
        expected = M.planned(initial)
        self.assertEqual(expected, '[ppb-v2]\n' + M.NEW + '\n')
        self.assertNotIn(':/run:', expected)
        self.assertEqual(expected.replace(M.NEW, M.OLD), initial)

    def test_reject_preexpanded_and_duplicate_base_dir(self):
        for initial in (M.NEW, M.OLD+'\n'+M.OLD, M.OLD+'\n'+M.NEW):
            with self.subTest(initial=initial), self.assertRaises(RuntimeError):
                M.planned(initial)

    def test_audit_requires_success_and_no_writes(self):
        success = SimpleNamespace(returncode=0, stdout=json.dumps({
            'result':'PASS','database_writes':0,'program_writes':0}))
        with patch.object(M.subprocess, 'run', return_value=success) as run:
            M.audit_isolation()
        self.assertEqual(run.call_args.args[0][1], M.LIFECYCLE)
        self.assertIn('inventory',run.call_args.args[0])
        bad = SimpleNamespace(returncode=2, stdout=json.dumps({
            'result':'STOP','reason':'CONFIG_CACHE_REQUIRES_ISOLATED_REBUILD'}))
        with patch.object(M.subprocess, 'run', return_value=bad):
            with self.assertRaisesRegex(RuntimeError, 'CONFIG_CACHE_REQUIRES_ISOLATED_REBUILD'):
                M.audit_isolation()

    def test_audit_denies_missing_or_positive_write_receipt(self):
        for data in ({'result':'PASS','program_writes':0},
                     {'result':'PASS','program_writes':0,'database_writes':1}):
            with self.subTest(data=data):
                with patch.object(M.subprocess,'run',return_value=SimpleNamespace(
                    returncode=0,stdout=json.dumps(data))):
                    with self.assertRaisesRegex(RuntimeError,'V2_ISOLATION_WRITES_UNEXPECTED'):
                        M.audit_isolation()


if __name__ == '__main__':
    unittest.main()
