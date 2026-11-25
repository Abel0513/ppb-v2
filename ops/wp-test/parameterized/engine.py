"""Fixed data-only WP operations. Candidate; installed v1 remains immutable.
State is outside the original WPN2 journal. No create operation is dispatched.
"""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import tempfile

OPS = {'read', 'update_acf', 'verify'}

def need(ok, code):
    if not ok: raise RuntimeError(code)

def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':'))

def digest(value):
    return hashlib.sha256(canonical(value).encode()).hexdigest()

def validate(req):
    need(isinstance(req, dict), 'REQUEST_INVALID')
    need(set(req) == {'operation', 'expert_id', 'request_id', 'fields'}, 'REQUEST_KEYS_INVALID')
    if req['operation'] == 'create': raise RuntimeError('CREATE_REQUIRES_SEPARATE_AUTHORIZATION')
    need(req['operation'] in OPS, 'OPERATION_DENIED')
    need(type(req['expert_id']) is int and 0 < req['expert_id'] < 2147483648 and req['expert_id'] != 30436, 'EXPERT_NOT_AUTHORIZED')
    need(isinstance(req['request_id'], str) and re.fullmatch('[a-zA-Z0-9][a-zA-Z0-9_-]{7,79}', req['request_id']), 'REQUEST_ID_INVALID')
    fields=req['fields']
    need(isinstance(fields, dict) and len(fields)<=43 and all(isinstance(k,str) and 1<=len(k.encode())<=150 and not re.search(r'[\x00-\x1f\x7f]',k) for k in fields), 'FIELD_NOT_AUTHORIZED')
    if req['operation'] != 'read': need(bool(fields), 'EMPTY_FIELDS')
    else: need(not fields, 'READ_FIELDS_FORBIDDEN')
    for value in fields.values():
        need(isinstance(value, str) and 1 <= len(value.encode()) <= 500
             and not re.search(r'[\x00-\x1f\x7f]', value), 'FIELD_VALUE_INVALID')
    return req

def save(path, data):
    raw=canonical(data).encode()
    need(len(raw)<500000,'REQUEST_RECORD_TOO_LARGE')
    fd,name=tempfile.mkstemp(prefix='.request-',dir=path.parent)
    tmp=Path(name)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(raw);out.flush();os.fsync(out.fileno())
        os.replace(tmp,path)
        directory=os.open(path.parent,os.O_DIRECTORY)
        try:os.fsync(directory)
        finally:os.close(directory)
    finally:
        if tmp.exists():tmp.unlink()

def load(path):
    s=path.lstat()
    need(stat.S_ISREG(s.st_mode) and s.st_uid==os.geteuid()
         and s.st_mode & 0o077 == 0 and s.st_size<500000, 'REQUEST_RECORD_UNSAFE')
    return json.loads(path.read_text())

def compare(before, after, fields):
    need(before.get('readback_http')==200 and after.get('readback_http')==200 and before.get('create_http')==201 and after.get('create_http')==201,'HTTP_RECEIPTS_MISSING')
    need(len(before['fields'])==43 and set(before['fields'])==set(after['fields']), 'ACF_43_SCHEMA_CHANGED')
    need(before['journal_sha256']==after['journal_sha256'] and before['fingerprint']==after['fingerprint'], 'ORIGINAL_JOURNAL_CHANGED')
    need(canonical(before['core'])==canonical(after['core']) and canonical(before['reference'])==canonical(after['reference']), 'CORE_OR_30436_CHANGED')
    need(canonical(before['identities'])==canonical(after['identities']) and canonical(before['media'])==canonical(after['media']), 'ANALYST_OR_MEDIA_CHANGED')
    for k, value in before['fields'].items():
        need(canonical(after['fields'][k])==canonical(fields.get(k,value)), 'ACF_VALUE_OR_UNTOUCHED_FIELD_CHANGED')
    return {'readback_http':200, 'create_http':201, 'acf_matched':43, 'untouched_acf_matched':43-len(fields),
            'analysts_verified':6, 'media_verified':6, 'reference_30436_unchanged':True,
            'journal_unchanged':True, 'fingerprint_unchanged':True}

def process(req, state_dir, rpc):
    validate(req)
    root=Path(state_dir)
    # Parent must already be a reviewed root-owned private directory.
    s=root.lstat()
    need(stat.S_ISDIR(s.st_mode) and s.st_uid==os.geteuid() and s.st_mode&0o077==0, 'STATE_DIR_UNSAFE')
    lock=os.open(root/'lock',os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        ls=os.fstat(lock)
        need(ls.st_uid==os.geteuid() and ls.st_mode&0o077==0 and stat.S_ISREG(ls.st_mode),'LOCK_UNSAFE')
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        return locked(req,root,rpc)
    finally:os.close(lock)

def locked(req, root, rpc):
    operation=req['operation'];fields=req['fields']
    path=root/(req['request_id']+'.json')
    intent=dict(expert_id=req['expert_id'], request_id=req['request_id'], fields=fields)
    record=load(path) if path.exists() or path.is_symlink() else None
    if record and operation!='read':
        need(record['intent']==intent, 'REQUEST_ID_CONFLICT')
    before=rpc('read', {})
    need(before.get('expert_id')==req['expert_id'],'RECEIPT_TARGET_MISMATCH')
    need(not set(fields)-set(before.get('writable_fields',[])),'FIELD_NOT_AUTHORIZED')
    if operation=='read':
        return dict(result='READ_VERIFIED',expert_id=req['expert_id'],request_id=req['request_id'],
                    fields=before['fields'],writable_fields=before['writable_fields'],readback_http=before['readback_http'],create_http=before['create_http'],journal_sha256=before['journal_sha256'],fingerprint=before['fingerprint'],wp_write_requests=0)
    if operation=='verify':
        need(record is not None, 'UPDATE_RECEIPT_REQUIRED')
        checks=compare(record['before'],before,fields)
        return dict(result='PASS',expert_id=req['expert_id'],request_id=req['request_id'],fields=fields,checks=checks,wp_write_requests=0)
    # One in-flight mutation per Expert across request IDs; an ambiguous operation
    # must be reconciled before another request can change the baseline.
    for other in root.glob('*.json'):
        if other != path:
            prior=load(other)
            need(prior.get('phase')=='VERIFIED','OTHER_UPDATE_OUTCOME_UNCERTAIN')
    if record:
        # Intent was durable before POST. Any repeat is GET-only, even after crash.
        checks=compare(record['before'],before,fields)
        record.update(phase='VERIFIED',checks=checks);save(path,record)
        return dict(result='PASS',expert_id=req['expert_id'],request_id=req['request_id'],fields=fields,checks=checks,wp_write_requests=0,reconciled=True)
    record=dict(intent=intent,phase='INTENT',before=before)
    save(path,record)  # Includes private 43-field baseline; never returned in logs.
    writes=0
    if any(before['fields'].get(k)!=v for k,v in fields.items()):
        writes=1
        try:
            receipt=rpc('update_acf',fields)
            record['update_receipt']=receipt
            save(path,record)
        except Exception:pass  # Ambiguous POST is followed by GET, never resent.
    after=rpc('read',{})
    checks=compare(before,after,fields)
    record.update(phase='VERIFIED',checks=checks);save(path,record)
    return dict(result='PASS',expert_id=req['expert_id'],request_id=req['request_id'],fields=fields,checks=checks,wp_write_requests=writes,reconciled=False,update_http=record.get('update_receipt',{}).get('http'))
