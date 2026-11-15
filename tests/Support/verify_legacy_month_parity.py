#!/usr/bin/env python3
"""Actual fixed-reader receipts -> independent source-rule oracle -> V2 kernels.
No business rows are printed or retained; no DB or legacy controller execution.
"""
import json,sys,re,subprocess,hashlib
from decimal import Decimal,ROUND_HALF_UP
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
FIELDS=['score_delivery_time','score_word_count','score_format','score_typos','score_semantic_clarity','score_reference_accuracy','score_logic_consistency','score_language_appropriateness','score_professional_bonus']
AUTO=['score_delivery_time','score_compliance','score_professionalism']
def need(value,code):
 if not value:raise RuntimeError(code)
def rounded(value,places=0):return float(Decimal(str(value)).quantize(Decimal(10)**-places,rounding=ROUND_HALF_UP))
def oracle(groups,manual,attendance,videos,level,month,analyst_id=2):
 selected={k:[] for k in ['rated','cache_update','absences','settled_view','settled_rating','regular','bonus','salary_short','salary_long','records']}
 for period,own,win,bonus,long,sub,joined,market,*tail in groups:
  if period!=month:continue
  scores=tail[:-1];count=tail[-1]
  for _ in range(count):
   row=dict(zip(FIELDS,scores));row.update(is_win=win,is_bonus=bonus,is_long=long,market_type=market)
   if sub==analyst_id and sub>1 and bonus==0:selected['absences'].append(row)
   if not own:continue
   selected['records'].append(row)
   if scores[0]>0:
    selected['cache_update'].append(row)
    if win in [1,2] and long==0 and sub==0:selected['rated'].append(row)
   if win in [1,2] and bonus==0 and long==0:
    selected['settled_view'].append(row)
    if sub==0:selected['settled_rating'].append(row);selected['regular'].append(row)
   if win in [1,2] and bonus==1 and sub==0:selected['bonus'].append(row)
   if joined:
    if long==1:selected['salary_long'].append(row)
    elif long==0 and sub==0 and win in [1,2]:selected['salary_short'].append(row)
 def monthly(picks,settled,wins):
  absent=len(selected['absences']);n=len(picks)+absent
  delivery=rounded((sum(p[FIELDS[0]] for p in picks)+absent*20)/n+(10 if absent==0 else 0)) if n else None
  word=(sum(p[FIELDS[1]] for p in picks)+absent*40)/n+(5 if sum(p[FIELDS[1]]==60 for p in picks)/n>=.95 else 0) if n else None
  fmt=(sum(p[FIELDS[2]] for p in picks)+absent*40)/n+(5 if sum(p[FIELDS[2]]==60 for p in picks)/n>=.95 else 0) if n else None
  compliance=rounded((word+fmt)/2) if n else None
  professional=rounded(sum(sum(p[f] for f in FIELDS[3:]) for p in picks)/n) if n else None
  rate=wins/(settled+absent)*100 if settled>=12 else 0
  bonus=next((v for threshold,v in [(71,4),(70,3),(65,2),(60,1)] if settled>=12 and rate>=threshold),0)
  interaction=manual['score_interaction_avg'];initiative=int(manual['score_initiative']);extra=int(manual['score_extra_adjustment'])
  total=rounded(Decimal(str(delivery))*Decimal('0.3')+Decimal(str(compliance))*Decimal('0.2')+Decimal(str(professional))*Decimal('0.3')+Decimal(str(rounded(float(interaction))))*Decimal('0.1')+initiative+bonus+extra) if all(v is not None for v in [delivery,compliance,professional,interaction]) else 60
  return dict(score_delivery_time=delivery,score_compliance=compliance,score_professionalism=professional,score_interaction_avg=interaction,score_initiative=initiative,score_winrate_bonus=bonus,score_initiative_winrate_total=initiative+bonus,score_extra_adjustment=extra,total_score=total)
 reports={}
 for profile,denom in [('view','settled_view'),('rating','settled_rating')]:reports[profile]=monthly(selected['rated'],len(selected[denom]),sum(p['is_win']==1 for p in selected[denom]))
 cached=monthly(selected['cache_update'],0,0)
 if not selected['cache_update']:
  for f in AUTO:cached[f]=manual.get(f)
 reports['cached']={f:cached[f] for f in AUTO}
 rules={3:[60,60,70,50],2:[50,50,70,30],1:[25,25,30,20],0:[20,15,25,10]};rule=rules.get(level,rules[2]);short=0
 for p in selected['salary_short']:
  base,win=(rule[2],rule[3]) if p['market_type']==2 else (rule[0],rule[1]);short+=(0 if p['is_bonus']==1 else base)+(win if p['is_win']==1 else 0)
 late=attendance.get(month+':1',0);absent=sum(n for k,n in attendance.items() if k.startswith(month+':') and k!=month+':1')
 salary=dict(short=short,long=len(selected['salary_long'])*400,video=videos*400,late=-50*late,absent=(-100-200*(absent-1)) if absent else 0);salary['total']=sum(salary.values())
 reports.update(salary=salary,counts={k:len(v) for k,v in selected.items()},wins=dict(regular=sum(p['is_win']==1 for p in selected['regular']),bonus=sum(p['is_win']==1 for p in selected['bonus'])))
 return reports
PHP=r'''
foreach(['LegacyScheduleCalculator','LegacyRatingCalculator','LegacyMonthSelector','EvaluationReport'] as $name)require $argv[1].'/app/Services/Ppb/Evaluation/'.$name.'.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$rows=[];$schedules=[];$id=0;
foreach($input['groups'] as $g){[$month,$own,$win,$bonus,$long,$sub,$joined,$market]=$g;$scores=array_slice($g,8,9);$count=$g[17];$gid=++$id;
 if($joined)$schedules['legacy:'.$gid]=['market_type'=>$market];
 for($i=0;$i<$count;$i++)$rows[]=array_combine(App\Services\Ppb\Evaluation\LegacyScheduleCalculator::RATING_FIELDS,$scores)+['_source_type'=>'legacy','_source_id'=>$i,'_author_key'=>$own?'legacy:'.$input['analyst_id']:'legacy:other','_absence_key'=>($sub===$input['analyst_id']&&$sub>1)?'legacy:'.$input['analyst_id']:null,'_is_substitution'=>$sub===null?null:$sub!==0,'_schedule_key'=>'legacy:'.$gid,'recommended_time'=>$month.'-01 00:00:00','is_win'=>$win,'is_bonus'=>$bonus,'is_long'=>$long];
}
$selector=new App\Services\Ppb\Evaluation\LegacyMonthSelector;$reporter=new App\Services\Ppb\Evaluation\EvaluationReport(new App\Services\Ppb\Evaluation\LegacyScheduleCalculator,new App\Services\Ppb\Evaluation\LegacyRatingCalculator);$out=[];
foreach($input['periods'] as $month=>$period){$attendance=[];foreach($input['attendance'] as $k=>$count)if(str_starts_with($k,$month.':'))for($i=0;$i<$count;$i++)$attendance[]=['attendance_status'=>(int)explode(':',$k)[1]];
 $report=$reporter->calculate($selector->select($rows,'legacy:'.$input['analyst_id'],$month,$schedules),$period['manual'],$schedules,$attendance,$period['videos'],$period['level']);$report['cached']=array_intersect_key($report['cached'],array_flip(['score_delivery_time','score_compliance','score_professionalism']));unset($report['deductions'],$report['salary_rows']);$out[$month]=$report;
}
$months=array_keys($input['periods']);[$prior,$current]=$months;$base=$input['periods'][$current]['level']??$input['profile_level'];
$out['next_rating']=(new App\Services\Ppb\Evaluation\LegacyRatingCalculator)->rating($base,$input['periods'][$prior]['level'],(int)$out[$current]['rating']['total_score'],(int)$out[$prior]['rating']['total_score']);
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
'''
def analyze_snapshot(a):
 need(a.get('summary_flag_nulls_preserved') is True,'SUMMARY_FLAG_PRECISION_NOT_PROVEN')
 a=dict(a);a['attendance_counts']=dict(a['attendance_counts'])
 need(sum(g[17] for g in a['pick_groups'])==a['raw_picks'],'GROUP_COVERAGE_NOT_PROVEN')
 periods={};expected={}
 for month in [a['previous_month'],a['month']]:
  manual={f:None for f in AUTO};manual.update(score_interaction_avg=40,score_initiative=0,score_extra_adjustment=0)
  for e in a['evaluations']:
   if e['eval_month']==month:manual.update({k:e[k] for k in manual})
  level=next((int(r['rating_level']) for r in a['ratings'] if r['rating_month']==month),None)
  periods[month]=dict(manual=manual,videos=a['video_counts'][month],level=level)
  expected[month]=oracle(a['pick_groups'],manual,a['attendance_counts'],a['video_counts'][month],level,month,a['analyst_id'])
 current_month=a['month'];prior_month=a['previous_month'];base=periods[current_month]['level']
 if base is None:base=int(a['profile']['rating_level'])
 old_base=periods[prior_month]['level'];score=int(expected[current_month]['rating']['total_score']);old_score=int(expected[prior_month]['rating']['total_score']);reset=old_base is not None and old_base!=base
 next_level=base;terminate=False
 if 0<score<40:next_level=max(0,base-1);terminate=True
 elif 0<score<50:next_level=max(0,base-1)
 elif not reset and old_score>=68 and score>=68:next_level=min(3,base+1)
 elif not reset and 0<old_score<60 and 0<score<60:next_level=max(0,base-1)
 expected['next_rating']=dict(rating_level=next_level,is_reset=reset,is_terminate=terminate)
 p=subprocess.run(['php','-r',PHP,str(ROOT)],input=json.dumps(dict(analyst_id=a['analyst_id'],groups=a['pick_groups'],attendance=a['attendance_counts'],periods=periods,profile_level=int(a['profile']['rating_level']))),capture_output=True,text=True,timeout=15)
 need(p.returncode==0,'V2_MONTHLY_CALCULATOR_FAILED');actual=json.loads(p.stdout);comparisons=0
 def compare(left,right):
  nonlocal comparisons
  need(type(left) is dict and set(left)==set(right),'MONTHLY_OUTPUT_KEYS_MISMATCH')
  for k,v in left.items():
   if isinstance(v,dict):compare(v,right[k])
   else:
    got=right[k]
    if isinstance(v,bool):need(type(got) is bool and got==v,'MONTHLY_RATING_FLAG_MISMATCH');comparisons+=1;continue
    need((v is None and got is None) or (v is not None and got is not None and Decimal(str(v))==Decimal(str(got))),'MONTHLY_PARITY_MISMATCH_'+k.upper());comparisons+=1
 compare(expected,actual)
 return dict(comparisons=comparisons,months=list(periods))
def verify(payload):
 results=payload['results'];need(len(results)==4 and str(payload['run_id']).isdigit(),'ACTUAL_BATCH_REQUIRED');snapshots=[];commands=[];pins=[]
 for receipt in results:
  need(receipt.get('result')=='PASS' and receipt.get('legacy_file_writes')==0 and receipt.get('legacy_db_writes')==0,'READ_RECEIPT_NOT_PASS')
  need(re.fullmatch('[0-9a-f-]{36}',receipt.get('ssm_command_id','')),'ACTUAL_SSM_RECEIPT_REQUIRED')
  commands.append(receipt['ssm_command_id']);pins.append((receipt['document_version'],receipt['document_hash']));data=receipt['remote']['data']
  need(data['db_actor']=='ppb_legacy_ro@localhost' and data['transaction_readonly'] is True and data['old_db_write_grants'] is False and data['global_privileges'] is False and data['database_writes']==0,'READONLY_BOUNDARY_NOT_PROVEN')
  if 'snapshot' in data:
   need(data['transaction_isolation']=='REPEATABLE-READ','TRANSACTION_ISOLATION_NOT_PROVEN');s=data['snapshot'];need(s['transactional_tables_verified']==7 and s['private_names_returned']==0 and s['analyst_id']==2,'SNAPSHOT_BOUNDARY_NOT_PROVEN');snapshots.append(s)
 need(len(set(pins))==1 and re.fullmatch('[a-f0-9]{64}',pins[0][1]),'SAME_PINNED_RUNTIME_REQUIRED')
 need(len(snapshots)==3 and len(set(commands))==4,'INDEPENDENT_READS_REQUIRED');a,b,prior=snapshots
 a['attendance_counts']=dict(a['attendance_counts'])
 need(a['month']=='2026-05' and a['source_sha256']==b['source_sha256'],'REPEAT_SNAPSHOT_CHANGED')
 need(sum(g[17] for g in a['pick_groups'])==a['raw_picks'],'GROUP_COVERAGE_NOT_PROVEN')
 canon=lambda x:json.dumps(x,sort_keys=True,separators=(',',':'))
 need(canon(sorted([g for g in a['pick_groups'] if g[0]=='2026-04']))==canon(sorted([g for g in prior['pick_groups'] if g[0]=='2026-04'])),'OVERLAP_MONTH_SOURCE_CHANGED')
 analyzed=analyze_snapshot(a);comparisons=analyzed['comparisons']
 return dict(result='PASS',scope='REAL_TWO_MONTH_SOURCE_SELECTION_SCORES_GRADE_AND_SALARY',analyst_id=2,months=analyzed['months'],comparisons=comparisons,source_sha256=a['source_sha256'],document_version=pins[0][0],document_hash=pins[0][1],independent_repeat_read=True,overlap_month_agrees=True,transactional_tables_verified=7,source_run='https://github.com/Abel0513/ppb-v2/actions/runs/'+str(payload['run_id']),ssm_command_ids=commands,legacy_writes=0,business_rows_in_report=0,live_video_positive_case=any(int(n)>0 for n in a['video_counts'].values()),live_late_positive_case=any(k.endswith(':1') and n>0 for k,n in a['attendance_counts'].items()),admin_persistence='NOT_VERIFIED',deployed_ui='NOT_VERIFIED')
def verify_cohort(payload):
 roster=payload['roster'];need(roster['result']=='PASS' and roster['remote']['data']['table']=='pay.pay_analyst_profiles','ACTUAL_ROSTER_REQUIRED')
 profiles=roster['remote']['data']['rows'];need(0<len(profiles)<50,'COMPLETE_BOUNDED_ROSTER_REQUIRED')
 expected={int(p['id']) for p in profiles if int(p['user_type']) in [0,1]};seen={};commands=[];pins=set();runs=[];positive_video=False;positive_late=False
 for batch in payload['batches']:
  need(str(batch['run_id']).isdigit(),'ACTUAL_RUN_REQUIRED');runs.append('https://github.com/Abel0513/ppb-v2/actions/runs/'+str(batch['run_id']))
  for receipt in batch['results']:
   need(receipt['result']=='PASS' and receipt['legacy_file_writes']==0 and receipt['legacy_db_writes']==0,'READ_RECEIPT_NOT_PASS')
   command=receipt['ssm_command_id'];need(re.fullmatch('[0-9a-f-]{36}',command),'ACTUAL_SSM_RECEIPT_REQUIRED');commands.append(command)
   pins.add((receipt['document_version'],receipt['document_hash']));d=receipt['remote']['data']
   need(d['db_actor']=='ppb_legacy_ro@localhost' and d['transaction_readonly'] is True and d['old_db_write_grants'] is False and d['global_privileges'] is False and d['database_writes']==0,'READONLY_BOUNDARY_NOT_PROVEN')
   if 'snapshot' not in d:continue
   a=d['snapshot'];identity=int(a['analyst_id']);need(identity in expected and a['month']==payload['month'],'SUBJECT_MONTH_NOT_IN_COHORT')
   need(d['transaction_isolation']=='REPEATABLE-READ' and a['transactional_tables_verified']==7 and a['private_names_returned']==0,'SNAPSHOT_BOUNDARY_NOT_PROVEN')
   if identity in seen:
    need(seen[identity]['source_sha256']==a['source_sha256'],'REPEAT_COHORT_SNAPSHOT_CHANGED');continue
   result=analyze_snapshot(a);positive_video|=any(int(n)>0 for n in a['video_counts'].values());positive_late|=any(k.endswith(':1') and n>0 for k,n in dict(a['attendance_counts']).items())
   seen[identity]=dict(analyst_id=identity,source_sha256=a['source_sha256'],comparisons=result['comparisons'],source_rows=a['raw_picks'])
 need(set(seen)==expected,'COHORT_INCOMPLETE');need(len(set(commands))==len(commands),'COMMAND_REUSE_NOT_INDEPENDENT')
 need(len(pins)==1,'RUNTIME_CHANGED_DURING_COHORT')
 return dict(result='PASS',scope='REAL_COMPLETE_NUMERIC_ANALYST_COHORT_TWO_MONTH_FORMULA_PARITY',month=payload['month'],analysts=len(seen),analysts_with_rows=sum(x['source_rows']>0 for x in seen.values()),comparisons=sum(x['comparisons'] for x in seen.values()),cohort_sha256=hashlib.sha256(json.dumps([seen[k] for k in sorted(seen)],sort_keys=True).encode()).hexdigest(),live_video_positive_case=positive_video,live_late_positive_case=positive_late,source_runs=runs,ssm_command_ids=commands,business_rows_in_report=0,legacy_writes=0,live_v2_identity_mapping='NOT_VERIFIED',production_persistence='NOT_VERIFIED',deployed_ui='NOT_VERIFIED')
if __name__=='__main__':
 try:print(json.dumps((verify_cohort if '--cohort' in sys.argv else verify)(json.load(sys.stdin))))
 except Exception as exc:
  reason=str(exc);print(json.dumps(dict(result='STOP',reason=reason if re.fullmatch('[A-Z0-9_]{1,120}',reason) else 'MONTHLY_PARITY_NOT_VERIFIED')));raise SystemExit(2)
