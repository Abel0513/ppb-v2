"""The metadata audit must not become an application release or fixed install."""
import fnmatch,importlib.util,json,os,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('metadata_release_gate',ROOT/'release-gate.py');g=importlib.util.module_from_spec(s);s.loader.exec_module(g)
FILES=['.github/workflows/ppb-v2-authority-audit.yml','ops/deploy/document-authority.py','ops/deploy/preload-reviewed-documents.py','ops/deploy/tests/test_document_authority.py','ops/deploy/tests/test_preload_documents.py','ops/automation/tests/test_document_audit_scope.py']
class MetadataAuditScope(unittest.TestCase):
 def test_actual_release_gate_rejects_this_control_plane_change_before_aws_or_release(self):
  with tempfile.TemporaryDirectory() as tmp:
   event=Path(tmp)/'event.json';output=Path(tmp)/'output';event.write_text(json.dumps({'action':'closed','pull_request':{'number':101}}))
   with patch.dict(os.environ,{'GITHUB_EVENT_PATH':str(event),'GITHUB_OUTPUT':str(output)}),patch.object(g.c,'pages',return_value=[{'filename':name} for name in FILES]),patch.object(g.c,'api') as api:
    g.main();api.assert_not_called()
   self.assertEqual(output.read_text(),'approved=false\n')
 def test_no_changed_path_triggers_existing_fixed_install_workflow(self):
  text=(ROOT.parents[1]/'.github/workflows/ppb-v2-activate-isolation.yml').read_text()
  import ast
  patterns=ast.literal_eval(next(line.split('paths:',1)[1].strip() for line in text.splitlines() if line.strip().startswith('paths:')))
  self.assertFalse(any(fnmatch.fnmatchcase(name,p) for name in FILES for p in patterns))
