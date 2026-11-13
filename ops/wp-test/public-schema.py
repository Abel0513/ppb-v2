#!/usr/bin/env python3
"""Credential-free, read-only WP REST schema evidence. Never read article bodies."""
import json
from pathlib import Path
import urllib.request
import urllib.error

facts=[]
for path,method in [('/wp-json/wp/v2/types/expert','GET'),('/wp-json/wp/v2/expert','OPTIONS')]:
    item={'path':path,'method':method,'authenticated':False}
    request=urllib.request.Request('https://csdatw.com'+path,method=method,
        headers={'Accept':'application/json','User-Agent':'PPB-WP-ReadOnly-Schema-Diagnostic'})
    try:
        with urllib.request.urlopen(request,timeout=20) as response:
            item['http']=response.status
            body=json.loads(response.read(2000000))
        if isinstance(body,dict):
            item['response_keys']=sorted(body)
            item['rest_base']=body.get('rest_base')
            item['supports']=body.get('supports')
            schema=body.get('schema',{})
            properties=schema.get('properties',{}) if isinstance(schema,dict) else {}
            item['schema_properties']=sorted(properties)
            item['ownership_fields']={key:properties.get(key) for key in ['id','type','status','slug','title','content','author']}
            item['allowed_methods']=body.get('methods')
    except urllib.error.HTTPError as exc:
        item['http']=exc.code
    except (OSError,ValueError) as exc:
        item['error_type']=type(exc).__name__
    facts.append(item)
Path('wp-result').mkdir(exist_ok=True)
Path('wp-result/public-expert-schema.json').write_text(json.dumps(facts,ensure_ascii=False,indent=2))
print('PUBLIC_EXPERT_SCHEMA='+json.dumps(facts,ensure_ascii=False))
