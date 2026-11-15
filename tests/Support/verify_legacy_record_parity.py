#!/usr/bin/env python3
"""Consume two actual fixed-reader receipts on stdin; output no business rows.
This proves ONLY the two observed per-record totals, not monthly selection parity.
No DB connection, legacy PHP execution, fixture persistence or source mutation.
"""
import hashlib,json,re,subprocess,sys
from decimal import Decimal
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
FIELDS=['score_delivery_time','score_word_count','score_format','score_typos','score_semantic_clarity','score_reference_accuracy','score_logic_consistency','score_language_appropriateness','score_professional_bonus']
def need(ok,reason):
    if not ok:raise RuntimeError(reason)
def verify(payload):
    need(str(payload.get('run_id','')).isdigit(),'ACTUAL_RUN_ID_REQUIRED')
    receipts=payload.get('results',[]);need(len(receipts)==2,'TWO_READ_RECEIPTS_REQUIRED')
    samples=[];commands=[];pins=[]
    for receipt in receipts:
        need(receipt.get('result')=='PASS' and receipt.get('legacy_file_writes')==0 and receipt.get('legacy_db_writes')==0,'READ_RECEIPT_NOT_PASS')
        command=receipt.get('ssm_command_id','');need(bool(re.fullmatch('[0-9a-f-]{36}',command)),'SSM_RECEIPT_REQUIRED');commands.append(command)
        pins.append((receipt.get('document_version'),receipt.get('document_hash')))
        data=receipt.get('remote',{}).get('data',{})
        need(data.get('db_actor')=='ppb_legacy_ro@localhost' and data.get('transaction_readonly') is True and data.get('old_db_write_grants') is False and data.get('global_privileges') is False,'READONLY_BOUNDARY_NOT_PROVEN')
        need(data.get('table')=='pay.pay_analyst_pick','SOURCE_TABLE_MISMATCH')
        rows=data.get('rows',[]);need(0<len(rows)<=50,'NONEMPTY_BOUNDED_SAMPLE_REQUIRED')
        for row in rows:
            need(set(row)==set(FIELDS+['id','recommended_time','is_win']),'UNEXPECTED_ROW_FIELDS')
            need(type(row['id']) is int and all(type(row[k]) is int for k in FIELDS),'INTEGER_RATING_SCHEMA_MISMATCH')
        samples.append(rows)
    need(commands[0]!=commands[1] and pins[0]==pins[1],'INDEPENDENT_SAME_RUNTIME_READ_REQUIRED')
    canonical=lambda rows:json.dumps(rows,sort_keys=True,separators=(',',':')).encode()
    need(canonical(samples[0])==canonical(samples[1]),'INPUT_CHANGED_NO_PARITY_CLAIM')
    rows=samples[0]
    # Independent source-formula oracle; do not call old controllers/endpoints.
    expected=[]
    for row in rows:
        values={k:Decimal(row[k]) for k in FIELDS}
        listing=sum(values.values())
        monthly=values[FIELDS[0]]+(values[FIELDS[1]]+values[FIELDS[2]])/2+sum(values[k] for k in FIELDS[3:])
        expected.append((listing,monthly if monthly>0 else None))
    code='require $argv[1]; $c=new App\\Services\\Ppb\\Evaluation\\LegacyScheduleCalculator; $rows=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo json_encode(array_map(fn($r)=>[$c->legacyListTotal($r),$c->monthlyRecordTotal($r)],$rows),JSON_THROW_ON_ERROR);'
    process=subprocess.run(['php','-r',code,str(ROOT/'app/Services/Ppb/Evaluation/LegacyScheduleCalculator.php')],input=json.dumps(rows),capture_output=True,text=True,timeout=10)
    need(process.returncode==0,'V2_CALCULATOR_EXECUTION_FAILED')
    actual=json.loads(process.stdout);need(len(actual)==len(expected),'RECORD_COUNT_MISMATCH')
    for wanted,got in zip(expected,actual):
        need(len(got)==2,'CALCULATOR_OUTPUT_SCHEMA')
        for a,b in zip(wanted,got):need((a is None and b is None) or (a is not None and b is not None and a==Decimal(str(b))),'RECORD_TOTAL_PARITY_MISMATCH')
    return dict(result='PASS',scope='REAL_RECORD_TOTALS_ONLY',records=len(rows),comparisons=len(rows)*2,
                independent_repeat_read=True,input_sha256=hashlib.sha256(canonical(rows)).hexdigest(),
                source_run='https://github.com/Abel0513/ppb-v2/actions/runs/'+str(payload['run_id']),
                ssm_command_ids=commands,document_version=pins[0][0],business_rows_in_report=0,
                legacy_writes=0,full_monthly_parity='NOT_VERIFIED')
if __name__=='__main__':
    try:print(json.dumps(verify(json.load(sys.stdin))))
    except Exception as exc:
        reason=str(exc);print(json.dumps(dict(result='STOP',reason=reason if re.fullmatch('[A-Z0-9_]{1,100}',reason) else 'PARITY_NOT_VERIFIED')));raise SystemExit(2)
