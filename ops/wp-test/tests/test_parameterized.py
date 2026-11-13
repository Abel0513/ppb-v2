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
    return dict(fields={**{f'field{i}':i for i in range(42)},'Bonus推薦投注':'Codex測試'},
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
    def test_old_expert_create_extra_fields_and_malicious_requests_denied(self):
        for q in [dict(req(),expert_id=30436),dict(req(),operation='create'),dict(req(),fields={'分析師1':'fake'}),dict(req(),request_id='../escape'),dict(req(),fields={'Bonus推薦投注':'x\n'}),dict(req(),shell='id')]:
            with self.assertRaises(RuntimeError):e.process(q,self.root,self.rpc)
        self.assertEqual(self.calls,[])
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
$core=['id'=>30460,'type'=>'expert','status'=>'draft','slug'=>SLUG,'title'=>['raw'=>TITLE],'content'=>['raw'=>'<!-- '.MARKER.' -->']];
$fixture=['definition'=>['all_keys'=>$keys,'url'=>'https://csdatw.com','user'=>'fixture','password'=>'fixture'],'people'=>$people,
'journal'=>['id'=>30460,'phase'=>'VERIFIED','create_http'=>201,'slug'=>SLUG,'marker'=>MARKER,'fingerprint'=>str_repeat('b',64),'author'=>41]];
$GLOBALS['transport']=function($ctx,$path,$method,$payload)use(&$db,$core){
$db['calls'][]=[$path,$method];$body=null;
if($path==='/wp-json/wp/v2/users/me?context=edit')$body=['id'=>41];
elseif($path==='/wp-json/wp/v2/types/expert?context=edit')$body=['slug'=>'expert','rest_base'=>'expert','rest_namespace'=>'wp/v2','supports'=>['author'=>false,'editor'=>true]];
elseif(preg_match('~/users/(\d+)~',$path,$m))$body=['id'=>(int)$m[1]];
elseif(preg_match('~/media/(\d+)~',$path,$m))$body=['id'=>(int)$m[1],'media_type'=>'image','source_url'=>'https://csdatw.com/wp-content/uploads/'.$m[1].'.jpg'];
elseif($path===lookupPath())$body=[$core];
elseif($path==='/wp-json/wp/v2/expert/30460?context=edit')$body=$core;
elseif($path==='/wp-json/acf/v3/expert/30460'){
 if($method==='POST'){
   if(array_keys($payload['fields'])!==['Bonus推薦投注'])throw new RuntimeException('EXTRA_WRITE');
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
                p=subprocess.run([php,str(root/'bridge.php')],input=json.dumps(dict(operation=op,fields=fields),ensure_ascii=False),capture_output=True,text=True)
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
