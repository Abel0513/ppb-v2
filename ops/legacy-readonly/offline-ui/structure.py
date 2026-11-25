"""Offline Phase B candidate. No file/network/DB reads or registered remote operation.

Catalogs are trusted reviewed code/data, NEVER request input. No production catalog
exists yet: exact paths and static literals need real ui_inventory/source review.
"""
import hashlib,json,re
from dataclasses import dataclass,field
from html.parser import HTMLParser

class ReviewRequired(ValueError):pass
def need(value,code):
 if not value:raise ReviewRequired(code)
@dataclass(frozen=True)
class Review:
 kind:str
 labels:frozenset=field(default_factory=frozenset)
 dom_tokens:frozenset=field(default_factory=frozenset)
 controls:frozenset=field(default_factory=frozenset)
 selectors:frozenset=field(default_factory=frozenset)
 assets:frozenset=field(default_factory=frozenset)
 def __post_init__(self):
  need(self.kind in {'html','css','js'},'REVIEW_KIND_INVALID')
  for token in self.dom_tokens|self.controls:
   need(bool(re.fullmatch('[A-Za-z_][A-Za-z0-9_-]{0,63}',token)) and not re.search('password|secret|credential|token|session|line_id',token,re.I),'REVIEW_TOKEN_INVALID')
  for asset in self.assets:
   need(bool(re.fullmatch('[A-Za-z0-9_./-]{1,200}',asset)) and not asset.startswith('/') and '..' not in asset.split('/'),'REVIEW_ASSET_INVALID')
  for selector in self.selectors:
   need(bool(re.fullmatch(r'[A-Za-z0-9_#.* >:+,\[\]=-]{1,160}',selector)) and not re.search('[a-f0-9]{24}|password|secret|token|line_id',selector,re.I),'REVIEW_SELECTOR_INVALID')

PRODUCTION_CATALOG={} # Deliberately empty until actual paths and per-file review.
MAX_SOURCE=500000
MAX_NODES=2000
MAX_OUTPUT=65536

def _css(source,review):
 need(len(source)<=MAX_SOURCE,'CSS_LIMIT')
 need(not re.search(r'url\s*\(|@import|expression\s*\(|javascript:|\\|[\x00-\x08]',source,re.I),'CSS_UNSAFE_OR_UNKNOWN')
 source=re.sub(r'/\*.*?\*/','',source,flags=re.S)
 need('/*' not in source and '*/' not in source,'CSS_COMMENT_UNCLOSED')
 properties=set('display position top right bottom left width min-width max-width height min-height max-height margin margin-top margin-right margin-bottom margin-left padding padding-top padding-right padding-bottom padding-left gap row-gap column-gap grid-template-columns grid-template-rows grid-column grid-row flex flex-direction flex-wrap align-items align-content justify-content order overflow overflow-x overflow-y color background-color border border-width border-style border-color border-radius border-collapse border-spacing box-sizing font-size font-weight line-height text-align vertical-align white-space visibility opacity z-index cursor table-layout'.split())
 words=set('none block inline inline-block flex inline-flex grid table table-row table-cell table-header-group table-row-group static relative absolute fixed sticky auto inherit initial unset normal bold bolder lighter left right center justify top bottom middle baseline hidden visible scroll collapse separate border-box content-box row column row-reverse column-reverse wrap nowrap wrap-reverse start end flex-start flex-end stretch space-between space-around space-evenly solid dashed dotted transparent currentcolor pointer default ellipsis repeat minmax calc rgb rgba fit-content min-content max-content black white red blue green gray grey px rem em vh vw fr print screen and only not all'.split())
 out=[]
 def blocks(text,media=None,depth=0):
  need(depth<=4,'CSS_DEPTH_LIMIT');pos=0
  while text[pos:].strip():
   start=text.find('{',pos);need(start!=-1,'CSS_BLOCK_REQUIRED');header=text[pos:start].strip();level=1;i=start+1
   while i<len(text) and level:
    level+=(text[i]=='{')-(text[i]=='}');i+=1
   need(level==0,'CSS_BLOCK_UNCLOSED');body=text[start+1:i-1];pos=i
   if header.startswith('@media'):
    query=header[6:].strip();need(not media and bool(re.fullmatch(r'(?:screen|print)|(?:(?:screen|print)\s+and\s+)?\((?:max|min)-width:\s*[0-9]{1,4}px\)',query)),'CSS_MEDIA_REVIEW_REQUIRED');blocks(body,query,depth+1);continue
   need(header in review.selectors and '{' not in body,'CSS_SELECTOR_REVIEW_REQUIRED')
   declarations=[]
   for declaration in body.split(';'):
    if not declaration.strip():continue
    need(':' in declaration,'CSS_DECLARATION_INVALID');prop,value=map(str.strip,declaration.split(':',1))
    need(prop in properties and len(value)<=160 and bool(re.fullmatch(r'[A-Za-z0-9#.%(),+*/ !\-]+',value)),'CSS_PROPERTY_REVIEW_REQUIRED')
    remaining=re.sub(r'#(?:[a-fA-F0-9]{8}|[a-fA-F0-9]{6}|[a-fA-F0-9]{4}|[a-fA-F0-9]{3})\b','',value);need('#' not in remaining,'CSS_COLOR_REVIEW_REQUIRED')
    balance=0
    for ch in remaining:
     balance+=(ch=='(')-(ch==')');need(balance>=0,'CSS_VALUE_UNBALANCED')
    need(balance==0,'CSS_VALUE_UNBALANCED')
    need(all(x in {'calc','repeat','minmax','rgb','rgba','fit-content'} for x in re.findall(r'([A-Za-z-]+)\(',remaining)),'CSS_FUNCTION_REVIEW_REQUIRED')
    need(all(word.lower() in words for word in re.findall('[A-Za-z_][A-Za-z_-]*',remaining) if word.lower()!='important'),'CSS_VALUE_REVIEW_REQUIRED')
    need(all(abs(float(n))<=10000 for n in re.findall(r'-?[0-9]+(?:\.[0-9]+)?',remaining)),'CSS_VALUE_LIMIT')
    declarations.append(dict(property=prop,value=value))
   out.append(dict(selector=header,media=media,declarations=declarations));need(len(out)<=MAX_NODES,'CSS_NODE_LIMIT')
 blocks(source);return out

def _js(source,review):
 # Complete small grammar, not a regex that echoes arbitrary JS snippets.
 tokens=[];pos=0
 while pos<len(source):
  if source[pos].isspace():pos+=1;continue
  if source.startswith('//',pos):end=source.find('\n',pos);pos=len(source) if end<0 else end+1;continue
  if source.startswith('/*',pos):end=source.find('*/',pos+2);need(end>=0,'JS_COMMENT_UNCLOSED');pos=end+2;continue
  ch=source[pos]
  if ch in "'\"":
   end=source.find(ch,pos+1);need(end>=0 and '\\' not in source[pos+1:end] and '\n' not in source[pos+1:end],'JS_STRING_REVIEW_REQUIRED');tokens.append(('string',source[pos+1:end]));pos=end+1;continue
  identifier=re.match('[A-Za-z_$][A-Za-z0-9_$]*',source[pos:])
  if identifier:tokens.append(('id',identifier[0]));pos+=len(identifier[0]);continue
  need(ch in '().{},;','JS_SYNTAX_REVIEW_REQUIRED');tokens.append((ch,ch));pos+=1
 i=0
 def take(value=None,kind=None):
  nonlocal i
  need(i<len(tokens),'JS_INCOMPLETE');token=tokens[i];i+=1
  need((value is None or token[1]==value) and (kind is None or token[0]==kind),'JS_GRAMMAR_REVIEW_REQUIRED');return token[1]
 def selected():
  if i<len(tokens) and tokens[i][1]=='$':take('$');take('(')
  else:take('document');take('.');take('querySelector');take('(')
  selector=take(kind='string');need(selector in review.selectors,'JS_SELECTOR_REVIEW_REQUIRED');take(')');return selector
 out=[]
 while i<len(tokens):
  selector=selected();take('.');method=take(kind='id');need(method in {'on','addEventListener'},'JS_BINDING_REVIEW_REQUIRED');take('(')
  event=take(kind='string');need(event in {'click','change','input','submit','shown.bs.modal','hidden.bs.modal'},'JS_EVENT_REVIEW_REQUIRED');take(',');take('function');take('(');take(')');take('{');actions=[]
  while i<len(tokens) and tokens[i][1]!='}':
   target=selected();take('.');action=take(kind='id')
   if action=='classList':
    take('.');verb=take(kind='id');need(verb in {'add','remove','toggle'},'JS_CLASS_ACTION_REVIEW_REQUIRED');take('(');value=take(kind='string');need(value in review.dom_tokens,'JS_CLASS_REVIEW_REQUIRED');take(')');action='classList.'+verb
   else:
    need(action in {'modal','collapse','tab'},'JS_ACTION_REVIEW_REQUIRED');take('(');value=take(kind='string');need(value in {'show','hide','toggle'} and (action!='tab' or value=='show'),'JS_ACTION_VALUE_REVIEW_REQUIRED');take(')')
   take(';');actions.append(dict(selector=target,action=action,value=value));need(len(actions)<=50,'JS_ACTION_LIMIT')
  take('}');take(')');take(';');out.append(dict(selector=selector,event=event,actions=actions));need(len(out)<=MAX_NODES,'JS_NODE_LIMIT')
 return out

class _Dom(HTMLParser):
 TAGS=set('html head body title meta link div section article main aside nav header footer h1 h2 h3 h4 h5 h6 p span strong em small br hr ul ol li dl dt dd table thead tbody tfoot tr th td caption colgroup col form label input select option button textarea a details summary dialog img'.split())
 VOID={'meta','link','br','hr','col','input','img'}
 CONTROLS={'if','elseif','else','volist','foreach','empty','notempty','eq','neq','switch','case','default','block'}
 def __init__(self,review):super().__init__(convert_charrefs=True);self.review=review;self.nodes=[];self.stack=[];self.buffer=None;self.internal=[]
 def add(self,node):self.nodes.append(node);need(len(self.nodes)<=MAX_NODES,'DOM_NODE_LIMIT')
 def handle_comment(self,data):pass
 def handle_decl(self,data):need(data.lower()=='doctype html','DOM_DECL_REVIEW_REQUIRED')
 def handle_pi(self,data):raise ReviewRequired('DOM_PROCESSING_INSTRUCTION_DENIED')
 def handle_starttag(self,tag,attrs):
  need(not self.buffer,'DOM_RAW_ELEMENT_NESTING')
  if tag in {'style','script'}:
   if tag=='style':need(not attrs or dict(attrs)=={'type':'text/css'},'DOM_STYLE_ATTRIBUTE_REVIEW_REQUIRED')
   if tag=='script' and attrs:
    allowed=dict(attrs);need(set(allowed)<={'src','defer','type'} and allowed.get('src') in self.review.assets,'DOM_SCRIPT_REFERENCE_REVIEW_REQUIRED');self.add(dict(type='asset',tag=tag,path=allowed['src']))
   self.buffer=tag;self.internal=[];return
  need(tag in self.TAGS|self.CONTROLS,'DOM_TAG_REVIEW_REQUIRED');safe={}
  for key,value in attrs:
   value=value or ''
   if tag in self.CONTROLS:continue # Never export server variable/condition values.
   if key=='value':continue # No dynamic/business/default input values.
   if key in {'id','for','name','class'}:
    values=value.split();allowed=self.review.controls if key=='name' else self.review.dom_tokens
    need(bool(values) and all(x in allowed for x in values),'DOM_TOKEN_REVIEW_REQUIRED');safe[key]=value
   elif key in {'src','href'}:
    need(value in self.review.assets or value.startswith('#') and value[1:] in self.review.dom_tokens,'DOM_ASSET_REVIEW_REQUIRED');safe[key]=value
   elif key in {'colspan','rowspan'}:need(bool(re.fullmatch('[1-9][0-9]?',value)),'DOM_SPAN_INVALID');safe[key]=value
   elif key in {'aria-expanded','aria-hidden'}:need(value in {'true','false'},'DOM_ARIA_INVALID');safe[key]=value
   elif key=='style':safe[key]=_css('__inline__ {'+value+'}',Review('css',selectors=frozenset({'__inline__'})))[0]['declarations']
   elif key in {'required','disabled','readonly','multiple','checked','selected'}:safe[key]=True
   elif key=='method':need(value.lower() in {'get','post'},'DOM_METHOD_INVALID');safe[key]=value.lower()
   elif key=='type':need(value in {'button','submit','text','number','month','date','datetime-local','checkbox','radio','hidden'},'DOM_CONTROL_TYPE_REVIEW_REQUIRED');safe[key]=value
   elif key=='role':need(value in {'dialog','button','tab','tabpanel','table','alert','navigation'},'DOM_ROLE_REVIEW_REQUIRED');safe[key]=value
   elif key=='data-toggle':need(value in {'modal','collapse','tab','dropdown'},'DOM_TOGGLE_INVALID');safe[key]=value
   elif key=='data-target':need(value.startswith('#') and value[1:] in self.review.dom_tokens,'DOM_TARGET_REVIEW_REQUIRED');safe[key]=value
   else:raise ReviewRequired('DOM_ATTRIBUTE_REVIEW_REQUIRED')
  self.add(dict(type='start',tag=tag,attrs=safe))
  if tag not in self.VOID:self.stack.append(tag);need(len(self.stack)<=32,'DOM_DEPTH_LIMIT')
 def handle_startendtag(self,tag,attrs):
  self.handle_starttag(tag,attrs)
  if tag not in self.VOID:self.handle_endtag(tag)
 def handle_endtag(self,tag):
  if self.buffer:
   need(tag==self.buffer,'DOM_RAW_ELEMENT_UNCLOSED');text=''.join(self.internal)
   if text.strip():self.add(dict(type='inline',kind=tag,structure=_css(text,self.review) if tag=='style' else _js(text,self.review)))
   self.buffer=None;self.internal=[];return
  need(bool(self.stack) and self.stack[-1]==tag,'DOM_BALANCE_REVIEW_REQUIRED');self.stack.pop();self.add(dict(type='end',tag=tag))
 def handle_data(self,data):
  if self.buffer:self.internal.append(data);return
  text=' '.join(data.split())
  if not text:return
  if text=='[DYNAMIC]':self.add(dict(type='dynamic'));return
  need(text in self.review.labels,'DOM_TEXT_REVIEW_REQUIRED');self.add(dict(type='text',value=text))

def parse_reviewed(path,source,catalog=None,php_strip=None):
 catalog=PRODUCTION_CATALOG if catalog is None else catalog
 need(path in catalog,'UI_SOURCE_NOT_REVIEWED');review=catalog[path]
 need(isinstance(source,str) and len(source.encode())<=MAX_SOURCE,'UI_SOURCE_LIMIT')
 try:
  if review.kind=='html':
   if '<?' in source:
    need(php_strip is not None,'PHP_TOKENIZER_REQUIRED');source=php_strip(source);need('<?' not in source,'PHP_BLOCK_NOT_STRIPPED')
   source=re.sub(r'\{\$[^{}]{1,1000}\}','[DYNAMIC]',source);need('{$' not in source,'TEMPLATE_SYNTAX_REVIEW_REQUIRED')
   comment_free=re.sub(r'<!--.*?-->','',source,flags=re.S);need('<!--' not in comment_free and '-->' not in comment_free,'DOM_COMMENT_UNCLOSED')
   parser=_Dom(review);parser.feed(source);parser.close();need(not parser.stack and not parser.buffer,'DOM_UNCLOSED');structure=parser.nodes
  elif review.kind=='css':structure=_css(source,review)
  else:structure=_js(source,review)
  content=dict(format='UI_STRUCTURE_OFFLINE_V1',coverage='reviewed_structure_subset_only',kind=review.kind,structure=structure)
  encoded=json.dumps(content,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode();need(len(encoded)<=MAX_OUTPUT,'UI_OUTPUT_LIMIT')
  return dict(content,status='PASS',sanitized_sha256=hashlib.sha256(encoded).hexdigest())
 except ReviewRequired as exc:
  # Unknown input never produces raw snippets or a secret-dependent source hash.
  content=dict(format='UI_STRUCTURE_OFFLINE_V1',coverage='reviewed_structure_subset_only',kind=review.kind,structure=[])
  encoded=json.dumps(content,sort_keys=True,separators=(',',':')).encode()
  return dict(content,status='REVIEW_REQUIRED',reason=str(exc),sanitized_sha256=hashlib.sha256(encoded).hexdigest())
