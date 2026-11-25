import importlib.util
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1] / 'isolation'
SPEC=importlib.util.spec_from_file_location('fpm_bridge_once',ROOT/'enable-reader-open-basedir-once.py')
FPM=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(FPM)

class ExactFpmBridgeScopeTest(unittest.TestCase):
    def test_exact_file_scope_without_broad_run_access(self):
        original='[global]\n'+FPM.OLD+'\n'
        wanted=FPM.planned(original)
        self.assertEqual(wanted,'[global]\n'+FPM.NEW+'\n')
        self.assertNotIn(':/run:',wanted)
        self.assertEqual(wanted.replace(FPM.NEW,FPM.OLD),original)

    def test_reject_duplicate_or_preexpanded_directives(self):
        for original in (FPM.OLD+'\n'+FPM.OLD+'\n',
                         FPM.NEW+'\n',FPM.OLD+'\n'+FPM.NEW+'\n'):
            with self.subTest(original=original):
                with self.assertRaises(RuntimeError):
                    FPM.planned(original)
