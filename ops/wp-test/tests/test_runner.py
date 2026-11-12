import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
s=importlib.util.spec_from_file_location('runner',Path(__file__).parents[1]/'runner.py')
r=importlib.util.module_from_spec(s); s.loader.exec_module(r)
def fixture():
    acf={}; analysts=[]
    for i,slot in enumerate(['pick1','pick2','pick3','pick4','pick5','bonus'],2):
        a={'slot':slot,'source_id':i,'expected_id':i,'expected_photo':100+i,'expected_name':'Fixture'+str(i)}
        for role,v in [('id',i),('photo',100+i),('name','Fixture'+str(i)),('recommendation','original')]:
            k=slot+'_'+role; acf[k]=v; a[role+'_field']=k
        analysts.append(a)
    acf.update({'other'+str(i):'preserved' for i in range(19)})
    return dict(origin='https://example.invalid',route='/wp-json/wp/v2/expert',reference_id=30436,author=9,draft_hooks_reviewed=True,evidence_reference='OFFLINE ONLY',acf=acf,analysts=analysts,fields={k:dict(key='field_'+k,type='integer' if type(v)is int else 'string') for k,v in acf.items()})
class Fake:
    def __init__(self,c):
        self.ref=dict(id=30436,type='expert',status='draft',author=9,acf=copy.deepcopy(c['acf'])); self.posts=[]; self.calls=[]; self.fail=None; self.bad=None
    def type_info(self): return 200,dict(slug='expert',rest_namespace='wp/v2',rest_base='expert')
    def request(self,m,suffix='',payload=None):
        self.calls.append((m,suffix))
        if m=='POST':
            self.posts.append(dict(payload,id=40000,type='expert'))
            if self.fail=='timeout': raise TimeoutError()
            if self.fail=='http': return 500,None
            return 201,self.posts[0]
        if suffix.startswith('/30436'): return 200,copy.deepcopy(self.ref)
        if suffix.startswith('?'): return 200,copy.deepcopy(self.posts)
        p=copy.deepcopy(self.posts[0])
        if self.bad=='status': p['status']='publish'
        if self.bad=='acf': p['acf']['other1']='wrong'
        return 200,p
class Tests(unittest.TestCase):
    def setUp(self):
        t=tempfile.TemporaryDirectory(); self.addCleanup(t.cleanup); self.path=Path(t.name)/'state'; self.c=fixture(); self.wp=Fake(self.c)
    def save(self,s): self.path.write_text(json.dumps(s))
    def go(self): return r.run(self.c,self.wp,self.path,self.save)
    def test_create_readback_preserve(self):
        v=self.go(); self.assertEqual(v['ACF_MATCHED'],43); self.assertEqual(list(v['RECOMMENDATIONS'].values()),[r.TEXT]*6); self.assertEqual(self.wp.posts[0]['acf']['other1'],'preserved'); self.assertEqual(self.wp.posts[0]['author'],9)
    def test_retry(self):
        self.go(); self.go(); self.assertEqual(len(self.wp.posts),1)
    def test_timeout(self):
        self.wp.fail='timeout'
        with self.assertRaises(r.Stop): self.go()
        with self.assertRaisesRegex(r.Stop,'MANUAL_RECONCILIATION'): self.go()
        self.assertEqual(len(self.wp.posts),1)
    def test_http_error(self):
        self.wp.fail='http'
        with self.assertRaises(r.Stop): self.go()
        with self.assertRaises(r.Stop): self.go()
        self.assertEqual(len(self.wp.posts),1)
    def test_bad_acf(self):
        self.wp.bad='acf'
        with self.assertRaises(r.Stop): self.go()
    def test_bad_status(self):
        self.wp.bad='status'
        with self.assertRaises(r.Stop): self.go()
    def test_reference_not_draft(self):
        self.wp.ref['status']='publish'
        with self.assertRaises(r.Stop): self.go()
        self.assertEqual(self.wp.posts,[])
    def test_identity_changed(self):
        self.wp.ref['acf']['pick1_id']=99
        with self.assertRaises(r.Stop): self.go()
        self.assertEqual(self.wp.posts,[])
    def test_invalid_schema(self):
        self.c['acf'].pop('other1')
        with self.assertRaises(r.Stop): self.go()
        self.assertEqual(self.wp.calls,[])
    def test_unreviewed_hooks(self):
        self.c['draft_hooks_reviewed']=False
        with self.assertRaises(r.Stop): self.go()
    def test_unauthorized(self):
        with patch.object(r.sys,'argv',['runner']):
            with self.assertRaisesRegex(r.Stop,'UNAUTHORIZED'): r.main()
    def test_only_create(self):
        self.go(); self.assertEqual([x for x in self.wp.calls if x[0]!='GET'],[('POST','')])
    def test_existing_slug(self):
        self.wp.posts=[{'id':999}]
        with self.assertRaises(r.Stop): self.go()
        self.assertNotIn(('POST',''),self.wp.calls)
    def test_transport_forbids_updates(self):
        wp=r.WP(self.c,'unused')
        for m,s,p in [('DELETE','/30436',None),('POST','/30436',{'status':'draft'}),('POST','',{'status':'publish'})]:
            with self.assertRaises(r.Stop): wp.request(m,s,p)

    def test_wrong_post_type(self):
        self.wp.type_info=lambda: (200,dict(slug='post',rest_namespace='wp/v2',rest_base='posts'))
        with self.assertRaises(r.Stop): self.go()
        self.assertEqual(self.wp.posts,[])
    def test_changed_evidence_on_retry(self):
        self.go(); self.c['evidence_reference']='changed'
        with self.assertRaises(r.Stop): self.go()
        self.assertEqual(len(self.wp.posts),1)
    def test_ssm_fixed_command(self):
        import subprocess
        doc=json.loads((Path(__file__).parents[1]/'PPB-V2-WP-Draft-Test.ssm.json').read_text())
        self.assertEqual(set(doc['parameters']),{'Commit','RunnerSha256','Approval'})
        commands='\n'.join(doc['mainSteps'][0]['inputs']['runCommand'])
        self.assertEqual(subprocess.run(['sh','-n'],input=commands,text=True).returncode,0)
        self.assertIn('/usr/bin/python3 -I runner.py',commands)
        self.assertNotIn('curl',commands)
    def test_workflow_has_manual_gate_and_pins(self):
        workflow=(Path(__file__).parents[3]/'.github/workflows/ppb-v2-wp-draft-test.yml').read_text()
        for guard in ["github.event_name == 'workflow_dispatch'", "github.ref == 'refs/heads/main'", 'environment: ppb-wp-draft-test', '--document-version', '--document-hash', '--plugin-name expertDraftTest']:
            self.assertIn(guard,workflow)
    def test_redirects_refused(self):
        self.assertIsNone(r.NoRedirect().redirect_request(None,None,302,'',{},'https://elsewhere.invalid'))
