#!/usr/bin/env python3
"""Fixed SSM collector: expose only correlated, bounded verification facts."""
import json
import os
from pathlib import Path
import re
import stat
import sys

REPORT = Path('/var/tmp/ppb-codex-issue12-new-expert-v1/report.json')
REC_KEYS = ['分析師1推薦', '分析師2推薦', '分析師3推薦', '分析師4推薦', '分析師5推薦', 'Bonus推薦投注']

def collect(report, execution, exit_code):
    result = dict(schema_version=1, execution=execution, result='STOP',
                  package_exit_code=exit_code, wp_expert_id=None)
    if not isinstance(report,dict):
        return dict(result,stop_code='REPORT_SCHEMA_INVALID')
    if report.get('execution') != execution:
        return dict(result, stop_code='CURRENT_EXECUTION_REPORT_MISSING')
    candidate=report.get('candidate_expert_id')
    if type(candidate) is int and candidate>0 and candidate!=30436:
        result['candidate_expert_id']=candidate
    audit=report.get('ownership_audit',[])
    if isinstance(audit,list) and audit:
        last=audit[-1]
        if isinstance(last,dict) and isinstance(last.get('checks'),dict):
            result['ownership_checks']={k:v for k,v in last['checks'].items() if type(v) is bool}
    evidence=report.get('core_author_evidence')
    if isinstance(evidence,dict):
        result['core_author_evidence']={k:evidence.get(k) for k in ['expected_author','mode','independently_exposed','author_supported']}
    code = report.get('stop_code', 'WP_VERIFICATION_NOT_COMPLETE')
    result['stop_code'] = code if re.fullmatch('[A-Za-z0-9_]{1,120}', str(code)) else 'REMOTE_STOP'
    if 'media_verified_image_count' not in report:
        return dict(result,stop_code='MEDIA_VERIFIED_IMAGE_COUNT_MISSING')
    if type(report['media_verified_image_count']) is not int:
        return dict(result,stop_code='MEDIA_VERIFIED_IMAGE_COUNT_INVALID')
    final = report.get('final_readback', {})
    recs = report.get('recommendations', {})
    number = report.get('new_expert_id')
    analysts = report.get('analysts', [])
    if not isinstance(final,dict) or not isinstance(recs,dict) or not isinstance(analysts,list) or not all(isinstance(p,dict) for p in analysts):
        return dict(result,stop_code='REPORT_SCHEMA_INVALID')
    valid = (exit_code == 0 and report.get('result') == 'PASS' and report.get('wp_test_success') is True
             and type(number) is int and number > 0 and number != 30436
             and report.get('ACF_MATCHED') == 43 and final.get('expected_keys') == 43
             and final.get('present_keys') == 43 and final.get('all_43_values_match') is True
             and set(recs) == set(REC_KEYS) and all(v == 'Codex測試' for v in recs.values())
             and report.get('READBACK_HTTP') == 200 and report.get('media_verified_image_count') == 6
             and len(analysts) == 6 and {p.get('id') for p in analysts} == set(range(2, 8)))
    if valid:
        allowed_urls = [f'https://{host}/wp-admin/post.php?post={number}&action=edit'
                        for host in ['csdatw.com', 'www.csdatw.com']]
        if report.get('edit_url') not in allowed_urls:
            return dict(result, stop_code='EDIT_URL_NOT_VERIFIED')
        result.update(result='PASS', stop_code=None, wp_expert_id=number, wp_status='draft',
                      post_type='expert', acf_matched=43, recommendations=recs,
                      media_verified_image_count=report['media_verified_image_count'],
                      create_http=report.get('CREATE_HTTP'), readback_http=200,
                      wp_edit_url=report['edit_url'],
                      reference_30436_unchanged=report.get('reference_30436', {}).get('readback_unchanged'))
    return result

def main():
    execution = dict(commit=os.environ['SSM_Commit'], run_id=os.environ['SSM_RunId'],
                     run_attempt=os.environ['SSM_RunAttempt'])
    code = int(os.environ['PPB_WP_PACKAGE_EXIT_CODE'])
    report = {}
    try:
        st = REPORT.lstat()
        if stat.S_ISREG(st.st_mode) and st.st_uid == os.geteuid() and st.st_size <= 200000 and st.st_mode & 0o077 == 0:
            report = json.loads(REPORT.read_text())
        result = collect(report, execution, code)
    except (OSError, ValueError, TypeError):
        result = dict(schema_version=1, execution=execution, result='STOP',
                      stop_code='REPORT_UNAVAILABLE_OR_INVALID', wp_expert_id=None, package_exit_code=code)
    print('PPB_WP_RESULT_V1=' + json.dumps(result, ensure_ascii=False, separators=(',', ':')))
    return 0 if result['result'] == 'PASS' else 1

if __name__ == '__main__':
    sys.exit(main())
