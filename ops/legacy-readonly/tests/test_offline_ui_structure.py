"""Synthetic UI fragments only: not legacy visual-parity or remote acceptance."""
import importlib.util,json,shutil,subprocess,sys,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('offline_ui_structure',ROOT/'offline-ui/structure.py');m=importlib.util.module_from_spec(spec);sys.modules[spec.name]=m;spec.loader.exec_module(m)
class OfflineUiStructure(unittest.TestCase):
 def review(self,kind):
  return m.Review(kind,labels=frozenset({'月評鑑','分析師','評分','儲存','短稿'}),dom_tokens=frozenset({'rating','modal','open','rating-open','rating-modal'}),controls=frozenset({'score'}),selectors=frozenset({'#rating-open','#rating-modal','.rating','.rating th'}),assets=frozenset({'Public/js/fixture.js','Public/css/fixture.css'}))
 def parse(self,source,kind='html',**kwargs):return m.parse_reviewed('fixtures/fragment',source,{'fixtures/fragment':self.review(kind)},**kwargs)
 def test_production_catalog_empty_unknown_paths_denied_and_no_remote_operation(self):
  self.assertEqual(m.PRODUCTION_CATALOG,{})
  with self.assertRaisesRegex(m.ReviewRequired,'NOT_REVIEWED'):m.parse_reviewed('unknown.tpl','<div/>')
  spec=importlib.util.spec_from_file_location('ui_worker_guard',ROOT/'worker.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
  with self.assertRaisesRegex(RuntimeError,'OPERATION_DENIED'):w.operate({'operation':'ui_source','path':'fixtures/fragment'})
  spec=importlib.util.spec_from_file_location('ui_bundle_guard',ROOT.parent/'deploy/isolation/build-bootstrap.py');b=importlib.util.module_from_spec(spec);spec.loader.exec_module(b)
  self.assertNotIn(ROOT/'offline-ui/structure.py',b.FILES.values());self.assertNotIn(ROOT/'offline-ui/php-inline.php',b.FILES.values())
 def test_dom_order_controls_and_dynamic_values_without_business_data(self):
  source='<div class="rating"><table><tr><th>分析師</th><td>{$analyst.name}</td></tr></table><label for="score">評分</label><input id="score" name="score" type="number" value="PRIVATE_DEFAULT"><button type="submit">儲存</button></div>'
  # Static identifiers must also be explicitly reviewed, independently of control names.
  r=self.review('html');r=m.Review('html',labels=r.labels,dom_tokens=r.dom_tokens|{'score'},controls=r.controls)
  result=m.parse_reviewed('fixtures/dom',source,{'fixtures/dom':r})
  self.assertEqual(result['status'],'PASS');text=json.dumps(result,ensure_ascii=False)
  self.assertNotIn('PRIVATE_DEFAULT',text);self.assertNotIn('analyst.name',text);self.assertIn('"type": "dynamic"',text)
  tags=[x['tag'] for x in result['structure'] if x['type']=='start'];self.assertEqual(tags,['div','table','tr','th','td','label','input','button'])
 def test_private_input_changes_cannot_become_a_hash_oracle(self):
  a=self.parse('<input name="score" value="first-secret-value">');b=self.parse('<input name="score" value="other-private-value-with-different-length">')
  self.assertEqual(a['status'],'PASS');self.assertEqual(a['sanitized_sha256'],b['sanitized_sha256'])
 def test_unknown_labels_attributes_urls_and_malformed_dom_fail_closed(self):
  for source in ['<td>Private Person</td>','<img src="https://private.example/token">','<div data-private="SECRET"></div>','<button onclick="sendSecret()">儲存</button>','<div></span>','<!-- unclosed','<input type="password">']:
   result=self.parse(source);self.assertEqual(result['status'],'REVIEW_REQUIRED');self.assertEqual(result['structure'],[])
   self.assertNotIn('Private Person',json.dumps(result));self.assertNotIn('private.example',json.dumps(result));self.assertNotIn('SECRET',json.dumps(result))
 def test_css_layout_media_color_and_fallback_order(self):
  source='.rating {display: block; display: grid; gap: 12px; color: #abc;} @media (max-width: 768px) {.rating {display: block;}}'
  result=self.parse(source,'css');self.assertEqual(result['status'],'PASS');self.assertEqual([d['value'] for d in result['structure'][0]['declarations'][:2]],['block','grid']);self.assertEqual(result['structure'][1]['media'],'(max-width: 768px)')
 def test_css_unknown_private_and_injection_syntax_never_echoes(self):
  for source in ['.rating {background-color:url(https://private.example/SECRET)}','@import "private.css";','.unknown {display: block;}','.rating {color: #abcde;}','.rating {width:calc(100% - 2px;}','.rating {content: "SECRET";}','.rating {color: sEcReT;}']:
   result=self.parse(source,'css');self.assertEqual(result['status'],'REVIEW_REQUIRED');self.assertEqual(result['structure'],[]);self.assertNotIn('SECRET',json.dumps(result))
 def test_jquery_modal_and_native_class_interactions_are_structural_only(self):
  sources=["$('#rating-open').on('click', function () { $('#rating-modal').modal('show'); });","document.querySelector('#rating-open').addEventListener('click', function () { document.querySelector('#rating-modal').classList.toggle('open'); });"]
  for source in sources:
   result=self.parse(source,'js');self.assertEqual(result['status'],'PASS');self.assertEqual(result['structure'][0]['selector'],'#rating-open');self.assertEqual(result['structure'][0]['event'],'click')
 def test_js_ajax_arbitrary_strings_template_literals_and_unknown_selectors_fail_closed(self):
  for source in ["fetch('https://private.example/SECRET');","$('#rating-open').on('click', function () { $.ajax('SECRET'); });","const key = `SECRET`;","$('#private').on('click', function () {});","eval('SECRET');"]:
   result=self.parse(source,'js');self.assertEqual(result['status'],'REVIEW_REQUIRED');self.assertEqual(result['structure'],[]);self.assertNotIn('SECRET',json.dumps(result))
 def test_php_lexer_never_executes_source_or_exports_private_program(self):
  php=shutil.which('php');self.assertIsNotNone(php,'PHP tokenizer is required, not a skipped test.')
  spec=importlib.util.spec_from_file_location('ui_php_lexer',ROOT/'worker.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
  def strip(source):
   result=subprocess.run(w.tokenizer_argv(php)+[str(ROOT/'offline-ui/php-inline.php')],input=source,text=True,capture_output=True,check=True)
   return json.loads(result.stdout)['inline_html']
  with tempfile.TemporaryDirectory() as tmp:
   marker=Path(tmp)/'must-not-exist'
   source='<h1>月評鑑</h1><?php file_put_contents('+repr(str(marker))+', "SECRET"); echo "?> private-secret"; ?><input name="score" value="BUSINESS_VALUE">'
   result=self.parse(source,php_strip=strip);self.assertEqual(result['status'],'PASS');self.assertFalse(marker.exists());self.assertNotIn('SECRET',json.dumps(result));self.assertNotIn('BUSINESS_VALUE',json.dumps(result))
  self.assertEqual(self.parse('<?php echo "SECRET"; ?>')['status'],'REVIEW_REQUIRED')
 def test_source_depth_output_and_profile_bounds(self):
  with self.assertRaisesRegex(m.ReviewRequired,'UI_SOURCE_LIMIT'):self.parse('x'*500001)
  self.assertEqual(self.parse('<div>'*33+'</div>'*33)['status'],'REVIEW_REQUIRED')
  with self.assertRaises(m.ReviewRequired):m.Review('html',assets=frozenset({'../private.css'}))
  with self.assertRaises(m.ReviewRequired):m.Review('html',dom_tokens=frozenset({'session_token'}))
