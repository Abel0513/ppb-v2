import copy
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]/'parameterized'
s=importlib.util.spec_from_file_location('param_engine',ROOT/'engine.py');e=importlib.util.module_from_spec(s);s.loader.exec_module(e)

def req(op='update_acf',rid='bonus30460-request01',value='Codex測試123'):
    return dict(operation=op,expert_id=30460,request_id=rid,fields={} if op=='read' else {'Bonus推薦投注':value})

def snapshot():
    return dict(expert_id=30460,writable_fields=['Bonus推薦投注','分析師1推薦投注'],readback_http=200,create_http=201,fields={**{f'field{i}':i for i in range(42)},'Bonus推薦投注':'Codex測試'},
                journal_sha256='a'*64,fingerprint='b'*64,core={'id':30460},reference={'id':30436},
                identities=list(range(6)),media=list(range(6)))

class Engine(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name);self.root.chmod(0o700)
        self.state=snapshot();self.calls=[];self.mode='normal'
    def tearDown(self):self.tmp.cleanup()
    def rpc(self,op,fields):
        self.calls.append((op,fields))
        if op=='update_acf':
            if self.mode=='failure':raise OSError('network lost before delivery')
            self.state['fields'].update(fields)
            if self.mode=='collateral':self.state['fields']['field1']='changed'
            if self.mode=='lost':raise OSError('network lost after delivery')
            if self.mode=='journal':self.state['journal_sha256']='c'*64
        return copy.deepcopy(self.state)
    def test_update_verify_repeat_no_second_write(self):
        first=e.process(req(),self.root,self.rpc)
        self.assertEqual(first['checks']['untouched_acf_matched'],42)
        self.assertEqual(first['wp_write_requests'],1)
        self.assertEqual(e.process(req(),self.root,self.rpc)['wp_write_requests'],0)
        self.assertEqual(e.process(req('verify'),self.root,self.rpc)['result'],'PASS')
        self.assertEqual(sum(op=='update_acf' for op,_ in self.calls),1)
    def test_read_never_writes(self):
        self.assertEqual(e.process(req('read'),self.root,self.rpc)['result'],'READ_VERIFIED')
        self.assertEqual(list(self.root.glob('*.json')),[])
    def test_lost_response_reconciles_no_repost(self):
        self.mode='lost';self.assertEqual(e.process(req(),self.root,self.rpc)['result'],'PASS')
        e.process(req(),self.root,self.rpc)
        self.assertEqual(sum(op=='update_acf' for op,_ in self.calls),1)
    def test_failed_or_crashed_intent_cannot_resend(self):
        self.mode='failure'
        for _ in range(2):
            with self.assertRaisesRegex(RuntimeError,'ACF_VALUE'):e.process(req(),self.root,self.rpc)
        self.assertEqual(sum(op=='update_acf' for op,_ in self.calls),1)
        with self.assertRaisesRegex(RuntimeError,'OTHER_UPDATE'):e.process(req(rid='different-request02'),self.root,self.rpc)
    def test_crash_after_delivery_before_record_update_is_reconciled(self):
        record=dict(intent={k:v for k,v in req().items() if k!='operation'},phase='INTENT',before=copy.deepcopy(self.state))
        e.save(self.root/(req()['request_id']+'.json'),record)
        self.state['fields']['Bonus推薦投注']='Codex測試123'
        self.assertTrue(e.process(req(),self.root,self.rpc)['reconciled'])
        self.assertEqual(sum(op=='update_acf' for op,_ in self.calls),0)
    def test_request_id_collision_and_verify_without_receipt(self):
        with self.assertRaisesRegex(RuntimeError,'UPDATE_RECEIPT'):e.process(req('verify'),self.root,self.rpc)
        e.process(req(),self.root,self.rpc)
        with self.assertRaisesRegex(RuntimeError,'REQUEST_ID_CONFLICT'):e.process(req(value='different'),self.root,self.rpc)
    def test_other_42_and_journal_are_protected(self):
        for mode,code in [('collateral','ACF_VALUE'),('journal','ORIGINAL_JOURNAL')]:
            self.mode=mode
            with self.assertRaisesRegex(RuntimeError,code):e.process(req(rid='test-request-'+mode),self.root,self.rpc)
            # Reset a different isolated ledger after the intentional failure.
            for p in self.root.glob('*.json'):p.unlink()
            self.state=snapshot()
    def test_receipt_bound_id_and_multiple_source_fields_are_parameters(self):
        self.state['expert_id']=98765
        q=dict(req(),expert_id=98765,fields={'Bonus推薦投注':'new bonus','分析師1推薦投注':'new analyst'})
        self.state['fields'].pop('field0');self.state['fields']['分析師1推薦投注']='old analyst'
        first=e.process(q,self.root,self.rpc)
        self.assertEqual(first['expert_id'],98765)
        self.assertEqual(first['checks']['untouched_acf_matched'],41)
        self.assertEqual(e.process(q,self.root,self.rpc)['wp_write_requests'],0)
        self.assertEqual(sum(op=='update_acf' for op,_ in self.calls),1)
    def test_request_id_does_not_authorize_a_different_expert(self):
        with self.assertRaisesRegex(RuntimeError,'RECEIPT_TARGET'):e.process(dict(req(),expert_id=98765),self.root,self.rpc)
        self.assertFalse(any(op=='update_acf' for op,_ in self.calls))
    def test_old_expert_create_extra_fields_and_malicious_requests_denied(self):
        for q in [dict(req(),expert_id=30436),dict(req(),operation='create'),dict(req(),fields={'分析師1':'fake'}),dict(req(),request_id='../escape'),dict(req(),fields={'Bonus推薦投注':'x\n'}),dict(req(),shell='id')]:
            with self.assertRaises(RuntimeError):e.process(q,self.root,self.rpc)
        self.assertTrue(all(op=='read' for op,_ in self.calls))
    def test_untouched_field_type_changes_are_not_accepted_as_equal(self):
        before=snapshot();after=copy.deepcopy(before)
        after['fields']['field1']=True
        with self.assertRaisesRegex(RuntimeError,'ACF_VALUE'):e.compare(before,after,{'Bonus推薦投注':'Codex測試'})
    def test_same_value_new_request_has_no_post(self):
        self.state['fields']['Bonus推薦投注']='Codex測試123'
        self.assertEqual(e.process(req(),self.root,self.rpc)['wp_write_requests'],0)
    def test_snapshot_changes_fail(self):
        base=snapshot()
        for key in ['fingerprint','core','reference','identities','media']:
            after=copy.deepcopy(base);after[key]='bad'
            with self.assertRaises(RuntimeError):e.compare(base,after,{'Bonus推薦投注':'Codex測試'})
    def test_symlink_state_record_denied(self):
        other=self.root/'elsewhere';other.write_text('{}');(self.root/(req()['request_id']+'.json')).symlink_to(other)
        with self.assertRaisesRegex(RuntimeError,'UNSAFE'):e.process(req(),self.root,self.rpc)

BRIDGE=r'''<?php
define('JOURNAL_DIR',__DIR__.'/never-created-journal');
require __DIR__.'/worker.php';
$keys=['賽事編號','日期','聯盟','賽事主隊','賽事客隊','合盤推薦','分析師Bonus','分析師bonus照片','分析師bonus狀態','Bonus推薦投注','Bonus推薦短文','有長文','長文連結'];
$groups=[];foreach(['','照片','狀態','推薦','推薦投注','推薦短文'] as $suffix){$g=[];for($i=1;$i<=5;$i++){$g[]='分析師'.$i.$suffix;$keys[]='分析師'.$i.$suffix;}$groups[]=$g;}
$people=[];for($i=2;$i<=7;$i++)$people[$i]=['id'=>$i,'name'=>'Source Analyst '.$i,'photo'=>(string)(100+$i),'author_id'=>200+$i];
$initial=array_fill_keys($keys,'fixture');
foreach(array_values($people) as $i=>$p){$name=$i<5?'分析師'.($i+1):'分析師Bonus';$photo=$i<5?$name.'照片':'分析師bonus照片';$initial[$name]=$p['name'];$initial[$photo]=(int)$p['photo'];}
$initial['Bonus推薦投注']='Codex測試';
$file=__DIR__.'/server.json';$db=is_file($file)?json_decode(file_get_contents($file),true):['fields'=>$initial,'calls'=>[]];
$fixtureId=(int)(getenv('FIXTURE_EXPERT_ID')?:30460);
$core=['id'=>$fixtureId,'type'=>'expert','status'=>'draft','slug'=>SLUG,'title'=>['raw'=>TITLE],'content'=>['raw'=>'<!-- '.MARKER.' -->']];
$fixture=['definition'=>['groups'=>$groups,'static'=>array_slice($keys,0,13),'all_keys'=>$keys,'url'=>'https://csdatw.com','user'=>'fixture','password'=>'fixture'],'people'=>$people,
'journal'=>['id'=>$fixtureId,'phase'=>'VERIFIED','create_http'=>201,'slug'=>SLUG,'marker'=>MARKER,'fingerprint'=>str_repeat('b',64),'author'=>41]];
$GLOBALS['transport']=function($ctx,$path,$method,$payload)use(&$db,$core,$fixtureId){
$db['calls'][]=[$path,$method];$body=null;
if($path==='/wp-json/wp/v2/users/me?context=edit')$body=['id'=>41];
elseif($path==='/wp-json/wp/v2/types/expert?context=edit')$body=['slug'=>'expert','rest_base'=>'expert','rest_namespace'=>'wp/v2','supports'=>['author'=>false,'editor'=>true]];
elseif(preg_match('~/users/(\d+)~',$path,$m))$body=['id'=>(int)$m[1]];
elseif(preg_match('~/media/(\d+)~',$path,$m))$body=['id'=>(int)$m[1],'media_type'=>'image','source_url'=>'https://csdatw.com/wp-content/uploads/'.$m[1].'.jpg'];
elseif($path===lookupPath())$body=[$core];
elseif($path==='/wp-json/wp/v2/expert/'.$fixtureId.'?context=edit')$body=$core;
elseif($path==='/wp-json/acf/v3/expert/'.$fixtureId){
 if($method==='POST'){
   if(array_diff(array_keys($payload['fields']),['Bonus推薦投注','分析師1推薦投注','分析師2推薦投注','分析師3推薦投注','分析師4推薦投注','分析師5推薦投注']))throw new RuntimeException('EXTRA_WRITE');
   $db['fields']=array_replace($db['fields'],$payload['fields']);
 }
 $body=['acf'=>$db['fields']];
}elseif($path==='/wp-json/wp/v2/expert/30436?context=edit')$body=['id'=>30436,'status'=>'private'];
elseif($path==='/wp-json/acf/v3/expert/30436')$body=['acf'=>['protected'=>'unchanged']];
else throw new RuntimeException('UNEXPECTED_ROUTE');
return ['status'=>200,'network_error'=>false,'json'=>$body];};
try{$input=json_decode(stream_get_contents(STDIN),true);$out=paramOperate($input,$fixture);file_put_contents($file,json_encode($db));echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(2);}
'''

class RealPHPIntegration(unittest.TestCase):
    def test_python_to_real_php_worker_to_python_one_field_only(self):
        php=shutil.which('php')
        if not php:self.skipTest('PHP required in CI')
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);state=root/'ledger';state.mkdir(mode=0o700)
            for n in ['run.php','legacy.php','parser.php']:shutil.copyfile(ROOT.parent/'interface/runtime'/n,root/n)
            shutil.copyfile(ROOT/'worker.php',root/'worker.php');(root/'bridge.php').write_text(BRIDGE)
            def rpc(op,fields):
                p=subprocess.run([php,str(root/'bridge.php')],input=json.dumps(dict(operation=op,expert_id=30460,fields=fields),ensure_ascii=False),capture_output=True,text=True)
                self.assertEqual(p.returncode,0,p.stderr)
                return json.loads(p.stdout)
            initial=rpc('read',{})
            outcome=e.process(req(),state,rpc)
            self.assertEqual(outcome['checks']['untouched_acf_matched'],42)
            self.assertEqual(outcome['result'],'PASS')
            e.process(req(),state,rpc);e.process(req('verify'),state,rpc)
            after=rpc('read',{})
            self.assertEqual(len(after['fields']),43)
            self.assertEqual(after['fields']['Bonus推薦投注'],'Codex測試123')
            self.assertEqual(initial['journal_sha256'],after['journal_sha256'])
            self.assertFalse((root/'never-created-journal').exists())
            calls=json.loads((root/'server.json').read_text())['calls']
            writes=[p for p,m in calls if m!='GET']
            self.assertEqual(writes,['/wp-json/acf/v3/expert/30460'])


class ParameterizedPHP(unittest.TestCase):
    def test_source_fields_and_receipt_id_not_compiled_into_worker(self):
        php=shutil.which('php')
        if not php:self.skipTest('PHP required in CI')
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);ledger=root/'ledger';ledger.mkdir(mode=0o700)
            for n in ['run.php','legacy.php','parser.php']:shutil.copyfile(ROOT.parent/'interface/runtime'/n,root/n)
            shutil.copyfile(ROOT/'worker.php',root/'worker.php');(root/'bridge.php').write_text(BRIDGE)
            def rpc(op,fields):
                p=subprocess.run([php,str(root/'bridge.php')],input=json.dumps(dict(operation=op,expert_id=98765,fields=fields),ensure_ascii=False),capture_output=True,text=True,env=dict(os.environ,FIXTURE_EXPERT_ID='98765'))
                self.assertEqual(p.returncode,0,p.stderr)
                return json.loads(p.stdout)
            q=dict(req(),expert_id=98765,fields={'Bonus推薦投注':'parameter bonus','分析師1推薦投注':'parameter analyst'})
            result=e.process(q,ledger,rpc)
            self.assertEqual(result['checks']['untouched_acf_matched'],41)
            self.assertEqual(e.process(q,ledger,rpc)['wp_write_requests'],0)
            self.assertEqual(e.process(dict(q,operation='verify'),ledger,rpc)['result'],'PASS')
            with self.assertRaisesRegex(RuntimeError,'FIELD_NOT_AUTHORIZED'):e.process(dict(q,request_id='denied-identity01',fields={'分析師1':'replacement'}),ledger,rpc)
            calls=json.loads((root/'server.json').read_text())['calls']
            self.assertEqual([path for path,method in calls if method!='GET'],['/wp-json/acf/v3/expert/98765'])
        for name in ['engine.py','worker.php','entry.py','client.py']:
            source=(ROOT/name).read_text()
            self.assertNotIn('30460',source)
            self.assertNotIn('Codex測試',source)

class CandidateClient(unittest.TestCase):
    def test_uninstalled_candidate_never_uploads_or_sends_command(self):
        from unittest.mock import patch
        spec=importlib.util.spec_from_file_location('param_client',ROOT/'client.py')
        c=importlib.util.module_from_spec(spec);spec.loader.exec_module(c)
        calls=[]
        def aws(*args):
            calls.append(args)
            if args[:2]==('sts','get-caller-identity'):return {'Account':c.r.ACCOUNT}
            if args[:2]==('ssm','describe-document'):return {'Document':{'DocumentVersion':'2','Hash':'a'*64}}
            if args[:2]==('ssm','get-document'):return {'Content':'{}'}
            self.fail('Unexpected AWS mutation')
        with tempfile.TemporaryDirectory() as tmp,patch.dict(os.environ,GITHUB_SHA='a'*40,GITHUB_RUN_ID='1',GITHUB_RUN_ATTEMPT='1'):
            old=os.getcwd()
            try:
                os.chdir(tmp);result=c.invoke(req(),aws)
            finally:os.chdir(old)
        self.assertEqual(result['stop_code'],'PARAMETERIZED_INTERFACE_NOT_INSTALLED')
        self.assertIsNone(result['ssm_command_id'])
        self.assertEqual(len(calls),3)
    def test_document_schema_and_reproducibility(self):
        spec=importlib.util.spec_from_file_location('param_render',ROOT/'render.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        d=module.document()
        self.assertEqual(d,json.loads((ROOT/'document.json').read_text()))
        self.assertEqual(d['parameters']['Operation']['allowedValues'],['read','update_acf','verify'])
        self.assertNotIn('create',d['parameters']['Operation']['allowedValues'])
        self.assertIn('request.json',d['mainSteps'][1]['inputs']['sourceInfo'])
        self.assertLess(len(json.dumps(d).encode()),64000)

class AuthorityProbe(unittest.TestCase):
    def test_denied_document_update_probe_does_not_change_code_or_send_commands(self):
        from unittest.mock import patch
        s=importlib.util.spec_from_file_location('wp_authority',ROOT/'authority.py');a=importlib.util.module_from_spec(s);s.loader.exec_module(a)
        calls=[]
        def call(*args):
            calls.append(args)
            if args[:2]==('sts','get-caller-identity'):return {'Account':a.r.ACCOUNT,'Arn':'arn:aws:sts::'+a.r.ACCOUNT+':assumed-role/PPB-V2-GitHub-WP-Draft-Test-Role/GitHubActions'}
            if args[:2]==('ssm','get-document'):return {'Content':'{"schemaVersion":"2.2"}','DocumentVersion':'2'}
            if args[:2]==('iam','get-role'):raise a.r.Stop('AWS_AccessDenied')
            if args[:2]==('ssm','update-document'):
                self.assertEqual(Path(args[-1][7:]).read_text(),'{"schemaVersion":"2.2"}')
                raise a.r.Stop('AWS_AccessDeniedException')
            self.fail('Unexpected authority operation')
        with tempfile.TemporaryDirectory() as tmp,patch.object(a.r,'persist'):
            result=a.probe(call)
        self.assertEqual(result['result'],'BLOCKED')
        self.assertFalse(result['document_changed'])
        self.assertEqual([x[0:2] for x in calls],[('sts','get-caller-identity'),('ssm','get-document'),('ssm','update-document'),('ssm','get-document'),('iam','get-role')])

class CliParameters(unittest.TestCase):
    def test_cli_supplies_id_fields_and_value_without_manifest_or_source_changes(self):
        from unittest.mock import patch
        s=importlib.util.spec_from_file_location('wp_param_cli',ROOT/'client.py');c=importlib.util.module_from_spec(s);s.loader.exec_module(c)
        args=['client.py','update_acf','--expert-id','98765','--request-id','parameter-request01','--fields-json','{"分析師1推薦投注":"any text","Bonus推薦投注":"another text"}']
        with patch('sys.argv',args),patch.object(c,'invoke',return_value={'result':'PASS'}) as invoke,patch('builtins.print'):
            self.assertEqual(c.main(),0)
        self.assertEqual(invoke.call_args.args[0],dict(operation='update_acf',expert_id=98765,request_id='parameter-request01',fields={'分析師1推薦投注':'any text','Bonus推薦投注':'another text'}))

class ParameterizedPromotion(unittest.TestCase):
    def test_reviewed_promotion_installs_candidate_not_old_resume_document(self):
        from unittest.mock import patch
        s=importlib.util.spec_from_file_location('wp_candidate_promote',ROOT.parent/'interface/promote.py');p=importlib.util.module_from_spec(s);s.loader.exec_module(p)
        local=json.loads((ROOT/'document.json').read_text());calls=[];default=False
        def call(*args):
            nonlocal default
            calls.append(args)
            if args[:2]==('sts','get-caller-identity'):return {'Account':p.r.ACCOUNT,'Arn':'arn:aws:sts::'+p.r.ACCOUNT+':assumed-role/PPB-V2-WP-Interface-Promoter/GitHubActions'}
            if args[:2]==('ssm','get-document'):
                if args[args.index('--document-version')+1]=='$DEFAULT' and not default:return {'Content':'{}','DocumentVersion':'2'}
                return {'Content':json.dumps(local),'DocumentVersion':'3'}
            if args[:2]==('ssm','update-document'):
                self.assertEqual(json.loads(Path(args[-1][7:]).read_text()),local)
                return {'DocumentDescription':{'DocumentVersion':'3'}}
            if args[:2]==('ssm','describe-document'):return {'Document':{'Status':'Active'}}
            if args[:2]==('ssm','update-document-default-version'):default=True;return {}
            self.fail('Unexpected promotion operation')
        with patch.dict(os.environ,GITHUB_REF='refs/heads/main',GITHUB_EVENT_NAME='workflow_dispatch'),patch('builtins.print'):
            p.promote(call,parameterized=True)
        self.assertTrue(default)
        self.assertTrue(all(x[0] in ['sts','ssm'] for x in calls))

class SixBetAcceptance(unittest.TestCase):
    def test_authorized_six_source_bet_fields_preserve_37_and_repeat_without_post(self):
        php=shutil.which('php')
        if not php:self.skipTest('PHP required in CI')
        q=json.loads((ROOT/'request.json').read_text())
        self.assertEqual(q['expert_id'],30460)
        self.assertEqual(set(q['fields']),{'分析師'+str(i)+'推薦投注' for i in range(1,6)}|{'Bonus推薦投注'})
        self.assertEqual(set(q['fields'].values()),{'Codex測試123'})
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);ledger=root/'ledger';ledger.mkdir(mode=0o700)
            for n in ['run.php','legacy.php','parser.php']:shutil.copyfile(ROOT.parent/'interface/runtime'/n,root/n)
            shutil.copyfile(ROOT/'worker.php',root/'worker.php');(root/'bridge.php').write_text(BRIDGE)
            def rpc(op,fields):
                p=subprocess.run([php,str(root/'bridge.php')],input=json.dumps(dict(operation=op,expert_id=q['expert_id'],fields=fields),ensure_ascii=False),capture_output=True,text=True)
                self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
            before=rpc('read',{})
            # These exact keys are independently produced by the real worker's source schema.
            self.assertTrue(set(q['fields'])<=set(before['writable_fields']))
            result=e.process(q,ledger,rpc)
            self.assertEqual(result['checks']['untouched_acf_matched'],37)
            for op in ['update_acf','verify']:
                self.assertEqual(e.process(dict(q,operation=op),ledger,rpc)['wp_write_requests'],0)
            after=rpc('read',{})
            for key in before['fields']:
                self.assertEqual(e.canonical(after['fields'][key]),e.canonical(q['fields'].get(key,before['fields'][key])))
            calls=json.loads((root/'server.json').read_text())['calls']
            self.assertEqual([path for path,method in calls if method!='GET'],['/wp-json/acf/v3/expert/30460'])

class OnceInstaller(unittest.TestCase):
    def test_admin_install_changes_only_wp_document_and_is_repeatable(self):
        s=importlib.util.spec_from_file_location('wp_once_install',ROOT/'install-once.py');a=importlib.util.module_from_spec(s);s.loader.exec_module(a)
        raw=(ROOT/'document.json').read_bytes();target=json.loads(raw);state={'default':'2','latest':'2'};calls=[]
        def call(*args):
            calls.append(args)
            self.assertIn(args[:2],a.ALLOWED)
            if args[:2]==('sts','get-caller-identity'):return {'Account':a.ACCOUNT,'Arn':'arn:aws:sts::'+a.ACCOUNT+':assumed-role/ExistingAdministrator/CloudShell'}
            self.assertEqual(args[args.index('--name')+1],a.DOCUMENT)
            if args[:2]==('ssm','update-document'):
                self.assertEqual(json.loads(Path(args[-1][7:]).read_text()),target);state['latest']='3';return {'DocumentDescription':{'DocumentVersion':'3'}}
            if args[:2]==('ssm','update-document-default-version'):state['default']='3';return {}
            v=args[args.index('--document-version')+1];v=state.get(v[1:].lower(),v) if v.startswith('$') else v
            if args[:2]==('ssm','describe-document'):return {'Document':{'Hash':a.OLD_HASH if v=='2' else 'a'*64,'HashType':'Sha256','Status':'Active'}}
            return {'Content':json.dumps({} if v=='2' else target),'DocumentVersion':v}
        first=a.install(raw,call)
        self.assertTrue(first['document_changed']);self.assertEqual(first['wp_writes'],0)
        self.assertFalse(a.install(raw,call)['document_changed'])
        self.assertEqual(sum(x[:2]==('ssm','update-document') for x in calls),1)
        self.assertEqual(sum(x[:2]==('ssm','update-document-default-version') for x in calls),1)
    def test_no_installer_access_for_v2_or_daily_wp_role_or_wrong_account(self):
        s=importlib.util.spec_from_file_location('wp_once_guard',ROOT/'install-once.py');a=importlib.util.module_from_spec(s);s.loader.exec_module(a)
        raw=(ROOT/'document.json').read_bytes()
        for role,account in [('PPB-V2-GitHub-Deploy-Role',a.ACCOUNT),('PPB-V2-GitHub-WP-Draft-Test-Role',a.ACCOUNT),('ExistingAdministrator','000000000000')]:
            calls=[]
            def call(*args):calls.append(args);return {'Account':account,'Arn':'arn:aws:sts::'+account+':assumed-role/'+role+'/session'}
            with self.assertRaises(RuntimeError):a.install(raw,call)
            self.assertEqual(calls,[('sts','get-caller-identity')])
