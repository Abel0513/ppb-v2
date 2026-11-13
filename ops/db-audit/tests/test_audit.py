import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
def load(name,file):
    s=importlib.util.spec_from_file_location(name,ROOT/file);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
class Audit(unittest.TestCase):
    def test_document_is_fixed_and_has_no_install_or_mutation_interface(self):
        d=load('document_test','document.py').document()
        self.assertEqual(d['parameters'],{})
        self.assertEqual([x['name'] for x in d['mainSteps']],['fixedMetadataAudit'])
        body=json.dumps(d)
        for token in ['useradd','systemctl','chown','chmod','mysql.user','/root/','.my.cnf','DROP DATABASE']:
            self.assertNotIn(token,body)
    def test_filtered_empty_result_never_becomes_pass_and_sha_is_pinned(self):
        m=load('metadata_run_test','run.py');calls=[];value=m.d.document()
        def call(*args):
            calls.append(args)
            if args[:2]==('sts','get-caller-identity'):return {'Account':m.c.ACCOUNT}
            if args[:2]==('ssm','get-document'):return {'Content':json.dumps(value),'DocumentVersion':'8'}
            if args[:2]==('ssm','describe-document'):return {'Document':{'Status':'Active','Hash':'a'*64,'HashType':'Sha256'}}
            if args[:2]==('ssm','send-command'):return {'Command':{'CommandId':'fixture'}}
            if args[:2]==('ssm','get-command-invocation'):return {'Status':'Success','ResponseCode':0,'StandardOutputContent':'V2_METADATA={"result":"STOP","visible_cross_schema_fks":[],"global_fk_visibility_proven":false,"reason":"AUTHORIZED_METADATA_IDENTITY_NOT_PROVISIONED"}'}
            self.fail(str(args))
        with tempfile.TemporaryDirectory() as tmp,patch.dict(os.environ,GITHUB_RUN_ID='1'):
            old=os.getcwd();os.chdir(tmp)
            try:r=m.run(call,lambda _:None)
            finally:os.chdir(old)
        self.assertEqual(r['result'],'STOP')
        sent=next(x for x in calls if x[:2]==('ssm','send-command'))
        self.assertEqual(sent[sent.index('--document-hash')+1],'a'*64)
        self.assertFalse(any(x[0]=='iam' or x[:2]==('ssm','update-document-default-version') for x in calls))
if __name__=='__main__':unittest.main()
