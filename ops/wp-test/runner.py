#!/usr/bin/env python3
"""Issue 12: single-use, fail-closed Expert draft test. Standard library only."""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path('/www/ppb-v2-deploy/wp-test')
TEXT = 'Codex測試'
class Stop(Exception):
    pass

def require(ok, reason):
    if not ok:
        raise Stop(reason)

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def private_json(path):
    require(path.is_file() and not path.is_symlink(), 'MISSING_PRIVATE_FILE')
    st = path.stat()
    require(st.st_uid == 0 and st.st_mode & 0o077 == 0, 'PRIVATE_FILE_PERMISSIONS')
    return json.loads(path.read_text())

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

class WP:
    def __init__(self, config, authorization):
        self.base = config['origin']
        self.route = config['route']
        self.authorization = authorization
        self.opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    def type_info(self):
        req = urllib.request.Request(self.base + '/wp-json/wp/v2/types/expert?context=edit', headers={'Authorization': self.authorization})
        with self.opener.open(req, timeout=30) as response:
            return response.status, json.loads(response.read(2000000))

    def media_info(self, media_id):
        require(type(media_id) is int and media_id > 0, 'INVALID_MEDIA_ID')
        req = urllib.request.Request(self.base + '/wp-json/wp/v2/media/' + str(media_id) + '?context=edit', headers={'Authorization': self.authorization})
        try:
            with self.opener.open(req, timeout=30) as response:
                return response.status, json.loads(response.read(2000000))
        except urllib.error.HTTPError as exc:
            return exc.code, None

    def request(self, method, suffix='', payload=None):
        require(method == 'GET' or (method == 'POST' and suffix == '' and payload['status'] == 'draft' and 'id' not in payload), 'FORBIDDEN_REQUEST')
        raw = None if payload is None else json.dumps(payload, ensure_ascii=False).encode()
        req = urllib.request.Request(self.base + self.route + suffix, data=raw, method=method,
            headers={'Authorization': self.authorization, 'Content-Type': 'application/json', 'Cache-Control': 'no-cache'})
        try:
            with self.opener.open(req, timeout=30) as response:
                return response.status, json.loads(response.read(2_000_001))
        except urllib.error.HTTPError as exc:
            # Never print response bodies/headers (can contain credentials or private content).
            return exc.code, None


def validate(c):
    require(re.fullmatch(r'https://[a-zA-Z0-9.-]+', c['origin']) is not None, 'INVALID_ORIGIN')
    require(re.fullmatch(r'/wp-json/wp/v2/[a-z][a-z0-9_-]*', c['route']) is not None, 'INVALID_ROUTE')
    require(c['draft_hooks_reviewed'] is True and c['evidence_reference'].strip(), 'MISSING_HOOK_OR_SOURCE_REVIEW')
    require(c['reference_id'] == 30436, 'REFERENCE_MUST_BE_30436')
    require(type(c['author']) is int and c['author'] > 0, 'INVALID_AUTHOR')
    require(len(c['acf']) == 43 and set(c['acf']) == set(c['fields']), 'ACF_SCHEMA_NOT_43')
    for name, spec in c['fields'].items():
        require(re.fullmatch(r'field_[A-Za-z0-9_]+', spec['key']) is not None, 'INVALID_FIELD_KEY')
        require(spec['type'] in ('string', 'integer', 'boolean', 'array', 'object'), 'INVALID_FIELD_TYPE')
        types = {'string': str, 'integer': int, 'boolean': bool, 'array': list, 'object': dict}
        require(type(c['acf'][name]) is types[spec['type']], 'FIELD_TYPE_MISMATCH')
    require(len({v['key'] for v in c['fields'].values()}) == 43, 'DUPLICATE_FIELD_KEYS')
    require(len(c['analysts']) == 6, 'SIX_ANALYSTS_REQUIRED')
    require([a['slot'] for a in c['analysts']] == ['pick1','pick2','pick3','pick4','pick5','bonus'], 'INVALID_SLOTS')
    require(sorted(a['source_id'] for a in c['analysts']) == [2,3,4,5,6,7], 'IDENTITY_SOURCE_MISMATCH')
    recommendations = []
    for a in c['analysts']:
        for key in ('id_field', 'photo_field', 'name_field', 'recommendation_field'):
            require(a[key] in c['acf'], 'MISSING_IDENTITY_FIELD')
        require(c['acf'][a['id_field']] == a['expected_id'], 'IDENTITY_MISMATCH')
        require(c['acf'][a['photo_field']] == a['expected_photo'] and bool(a['expected_photo']), 'PHOTO_MISMATCH')
        require(c['acf'][a['name_field']] == a['expected_name'] and bool(a['expected_name']), 'NAME_MISMATCH')
        require(type(c['acf'][a['recommendation_field']]) is str, 'RECOMMENDATION_TYPE')
        recommendations.append(a['recommendation_field'])
    require(len(set(recommendations)) == 6, 'DUPLICATE_RECOMMENDATIONS')



def writable_acf(c, wp, expected):
    """Keep standard readback evidence intact; serialize image fields as media IDs."""
    payload = dict(expected)
    photos = {a['photo_field'] for a in c['analysts']} | set(c.get('image_fields', []))
    require(photos <= set(expected), 'UNKNOWN_IMAGE_FIELD')
    light = None
    for field in photos:
        value = expected[field]
        url = None
        if type(value) is int:
            media_id = value
        elif isinstance(value, dict):
            ids = [value[k] for k in ('ID', 'id') if k in value]
            require(ids and all(type(i) is int and i > 0 and i == ids[0] for i in ids), 'INVALID_IMAGE_OBJECT_ID')
            media_id = ids[0]
            url = value.get('url')
        elif isinstance(value, str):
            url = value
            # Read raw attachment ID from the same reference; never infer from a URL/filename.
            if light is None:
                status, light = wp.request('GET', '/30436?context=edit&acf_format=light')
                require(status == 200 and light['id'] == 30436 and light['type'] == 'expert' and light['status'] == 'draft' and light['author'] == c['author'], 'RAW_REFERENCE_UNAVAILABLE')
            media_id = light['acf'].get(field)
        else:
            raise Stop('UNSUPPORTED_IMAGE_REPRESENTATION')
        require(type(media_id) is int and media_id > 0, 'IMAGE_ATTACHMENT_ID_REQUIRED')
        status, media = wp.media_info(media_id)
        require(status == 200 and media['id'] == media_id and media.get('type') == 'attachment' and str(media.get('mime_type', '')).startswith('image/'), 'IMAGE_MEDIA_NOT_VERIFIED')
        if url is not None:
            require(isinstance(url, str) and url == media.get('source_url'), 'IMAGE_URL_MEDIA_MISMATCH')
        payload[field] = media_id
    return payload


def run(c, wp, state_path, save):
    validate(c)
    type_status, metadata = wp.type_info()
    require(type_status == 200 and metadata['slug'] == 'expert' and metadata['rest_namespace'] == 'wp/v2' and '/wp-json/wp/v2/' + metadata['rest_base'] == c['route'], 'EXPERT_TYPE_NOT_VERIFIED')
    # Read only reference. Exact comparison prevents silently using stale identities or ACF.
    status, reference = wp.request('GET', '/30436?context=edit&acf_format=standard')
    require(status == 200 and reference['id'] == 30436 and reference['type'] == 'expert' and reference['status'] == 'draft', 'REFERENCE_NOT_SAFE')
    require(reference['acf'] == c['acf'] and reference['author'] == c['author'], 'REFERENCE_EVIDENCE_CHANGED')
    fingerprint = hashlib.sha256(json.dumps(reference, sort_keys=True).encode()).hexdigest()
    acf = dict(c['acf'])
    for a in c['analysts']:
        acf[a['recommendation_field']] = TEXT
    write_acf = writable_acf(c, wp, acf)
    slug = 'codex-issue-12-single-draft'
    if state_path.exists():
        state = json.loads(state_path.read_text())
        require(state['config_sha256'] == hashlib.sha256(json.dumps(c, sort_keys=True).encode()).hexdigest(), 'CONFIG_CHANGED_AFTER_ATTEMPT')
    else:
        # Detect an earlier article even if local journal was lost. Never adopt it or POST again.
        code, matches = wp.request('GET', '?context=edit&status=draft,publish,pending,private,future,trash&slug=' + slug)
        require(code == 200 and matches == [], 'EXISTING_OR_UNVERIFIABLE_TEST')
        state = {'phase': 'attempting', 'config_sha256': hashlib.sha256(json.dumps(c, sort_keys=True).encode()).hexdigest()}
        save(state)  # fsync before POST: crash/timeout must never result in a second POST.
        payload = {'status': 'draft', 'slug': slug, 'title': TEXT + ' ' + datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date().isoformat(), 'author': c['author'], 'acf': write_acf}
        try:
            code, created = wp.request('POST', payload=payload)
        except Exception:
            state['phase'] = 'uncertain'
            save(state)
            raise Stop('CREATE_UNCERTAIN_RECONCILE_ONLY')
        state['create_http'] = code
        if code != 201 or not isinstance(created, dict) or type(created.get('id')) is not int or created['id'] <= 0 or created['id'] == 30436:
            state['phase'] = 'uncertain'
            save(state)
            raise Stop('CREATE_FAILED_OR_UNCERTAIN')
        state.update(id=created['id'], phase='created')
        save(state)
    if 'id' not in state:
        code, matches = wp.request('GET', '?context=edit&status=draft&slug=' + slug)
        # Report candidates for manual reconciliation; never silently adopt an unknown POST.
        require(code == 200, 'RECONCILIATION_GET_FAILED')
        raise Stop('MANUAL_RECONCILIATION_REQUIRED_CANDIDATES_' + str(len(matches)))
    code, article = wp.request('GET', '/' + str(state['id']) + '?context=edit&acf_format=standard')
    require(code == 200 and article['id'] == state['id'] and article['type'] == 'expert' and article['status'] == 'draft', 'READBACK_NOT_DRAFT')
    require(article['author'] == c['author'] and article['acf'] == acf, 'READBACK_ACF_OR_AUTHOR_MISMATCH')
    code2, after = wp.request('GET', '/30436?context=edit&acf_format=standard')
    require(code2 == 200 and hashlib.sha256(json.dumps(after, sort_keys=True).encode()).hexdigest() == fingerprint, 'REFERENCE_CHANGED')
    state['phase'] = 'verified'
    save(state)
    return {'RESULT': 'PASS', 'NEW_WP_EXPERT_ID': state['id'], 'POST_TYPE': 'expert', 'WP_STATUS': 'draft',
            'CREATE_HTTP': state.get('create_http'), 'READBACK_HTTP': code, 'ACF_MATCHED': 43,
            'RECOMMENDATIONS': {a['recommendation_field']: article['acf'][a['recommendation_field']] for a in c['analysts']},
            'WP_EDIT_URL': c['origin'] + '/wp-admin/post.php?post=' + str(state['id']) + '&action=edit',
            'REFERENCE_30436_UNCHANGED': True, 'UNEXPECTED_SIDE_EFFECTS': 'NOT_OBSERVABLE_FROM_REST'}


def main():
    require(len(sys.argv) == 4 and sys.argv[3] == 'CREATE-ONE-EXPERT-DRAFT', 'UNAUTHORIZED')
    commit, sha = sys.argv[1:3]
    require(re.fullmatch('[0-9a-f]{40}', commit) and re.fullmatch('[0-9a-f]{64}', sha), 'INVALID_PIN')
    require(os.geteuid() == 0 and ROOT.is_dir() and not ROOT.is_symlink(), 'INVALID_INSTALL')
    require(ROOT.stat().st_uid == 0 and ROOT.stat().st_mode & 0o022 == 0, 'UNSAFE_INSTALL')
    approval = private_json(ROOT / 'approved.json')
    require(approval['commit'] == commit and approval['runner_sha256'] == sha == digest(Path(__file__)), 'UNAPPROVED_VERSION')
    require(approval['config_sha256'] == digest(ROOT / 'evidence.json'), 'UNAPPROVED_EVIDENCE')
    c = private_json(ROOT / 'evidence.json')
    auth = private_json(ROOT / 'credentials.json')
    with (ROOT / 'issue-12.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state = ROOT / 'issue-12-state.json'
        def save(value):
            temp = ROOT / 'state.tmp'
            fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
            with os.fdopen(fd, 'w') as out:
                json.dump(value, out)
                out.flush()
                os.fsync(out.fileno())
            os.replace(temp, state)
            fd = os.open(ROOT, os.O_DIRECTORY)
            os.fsync(fd)
            os.close(fd)
        print(json.dumps(run(c, WP(c, auth['authorization']), state, save), ensure_ascii=False))


def direct_main():
    """One approved terminal execution, using existing private evidence/auth only."""
    require(os.geteuid() == 0, 'ROOT_REQUIRED_FOR_EXISTING_PRIVATE_CONFIGURATION')
    require(ROOT.is_dir() and not ROOT.is_symlink(), 'EXISTING_WP_AUTHORIZATION_AND_EVIDENCE_DIRECTORY_MISSING')
    require(ROOT.stat().st_uid == 0 and ROOT.stat().st_mode & 0o022 == 0, 'UNSAFE_PRIVATE_CONFIGURATION')
    c = private_json(ROOT / 'evidence.json')
    auth = private_json(ROOT / 'credentials.json')
    if (ROOT / 'approved.json').exists():
        approval = private_json(ROOT / 'approved.json')
        require(approval['config_sha256'] == digest(ROOT / 'evidence.json'), 'UNAPPROVED_EVIDENCE')
    lock_path = ROOT / 'issue-12.lock'
    fd = os.open(lock_path, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state = ROOT / 'issue-12-state.json'
        require(not state.is_symlink(), 'UNSAFE_JOURNAL')
        def save(value):
            temp = ROOT / 'state.tmp'
            fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
            with os.fdopen(fd, 'w') as out:
                json.dump(value, out)
                out.flush()
                os.fsync(out.fileno())
            os.replace(temp, state)
            fd = os.open(ROOT, os.O_DIRECTORY)
            os.fsync(fd)
            os.close(fd)
        result = run(c, WP(c, auth['authorization']), state, save)
        print(json.dumps(result, ensure_ascii=False))


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        # Unexpected exception text may contain secrets. Only fixed Stop codes are emitted.
        print(json.dumps({'RESULT': 'STOP', 'REASON': str(exc) if isinstance(exc, Stop) else 'INTERNAL_OR_TRANSPORT_ERROR'}))
        sys.exit(1)
