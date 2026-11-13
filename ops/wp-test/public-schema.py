#!/usr/bin/env python3
"""Credential-free, read-only WP REST schema evidence. Never read article bodies."""
import json
from pathlib import Path
import urllib.request
import urllib.error

facts=[]
for path,method in [('/wp-json/wp/v2/types/expert','GET'),('/wp-json/wp/v2/expert','OPTIONS'),('/wp-json/wp/v2/taxonomies/expert','GET'),('/wp-json/wp/v2/expert?per_page=1&_fields=id,type,status,title,content,author,taxonomy,name','GET')]:
    item={'path':path,'method':method,'authenticated':False}
    request=urllib.request.Request('https://csdatw.com'+path,method=method,
        headers={'Accept':'application/json','User-Agent':'PPB-WP-ReadOnly-Schema-Diagnostic'})
    try:
        with urllib.request.urlopen(request,timeout=20) as response:
            item['http']=response.status
            body=json.loads(response.read(2000000))
        if isinstance(body,list):
            item['public_sample_count']=len(body)
            item['public_sample_shapes']=[{'keys':sorted(row),'type':row.get('type'),'status':row.get('status'),'taxonomy':row.get('taxonomy'),'has_title':'title' in row,'has_content':'content' in row,'has_author':'author' in row} for row in body if isinstance(row,dict)]
        if isinstance(body,dict):
            item['response_keys']=sorted(body)
            item['rest_base']=body.get('rest_base')
            item['supports']=body.get('supports')
            schema=body.get('schema',{})
            properties=schema.get('properties',{}) if isinstance(schema,dict) else {}
            item['schema_properties']=sorted(properties)
            item['ownership_fields']={key:properties.get(key) for key in ['id','type','status','slug','title','content','author']}
            item['allowed_methods']=body.get('methods')
            item['endpoints']=[{'methods':e.get('methods'),'argument_names':sorted(e.get('args',{})),'required_arguments':[k for k,v in e.get('args',{}).items() if isinstance(v,dict) and v.get('required')]} for e in body.get('endpoints',[]) if isinstance(e,dict)]
    except urllib.error.HTTPError as exc:
        item['http']=exc.code
    except (OSError,ValueError) as exc:
        item['error_type']=type(exc).__name__
    facts.append(item)
Path('wp-result').mkdir(exist_ok=True)
Path('wp-result/public-expert-schema.json').write_text(json.dumps(facts,ensure_ascii=False,indent=2))
print('PUBLIC_EXPERT_SCHEMA='+json.dumps(facts,ensure_ascii=False))
