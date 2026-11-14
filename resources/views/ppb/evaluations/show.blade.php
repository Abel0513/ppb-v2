@extends('ppb.layout')
@section('title','評鑑明細')
@push('styles')<style>.eval-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px}.eval-grid label{display:flex;flex-direction:column;gap:5px}.eval-score{width:90px}.eval-section{margin-block:24px}details summary{cursor:pointer}@media print{.admin-sidebar,.admin-topbar,button,.no-print,form.rate-form{display:none!important}.admin-main{margin:0!important}.table-scroll{overflow:visible}}</style>@endpush
@section('body')
<div class="admin-layout">@include('ppb.partials.sidebar')<div class="admin-main">@include('ppb.partials.topbar')<main class="admin-content"><div class="admin-content-inner">
@php($current=$report['current'])
@php($manage=auth('ppb')->user()->hasPpbPermission('evaluations.manage'))
<h1 class="heading">{{ $report['subject']['label'] }}・{{ $report['month'] }} 評鑑</h1>
<div class="toolbar no-print"><a class="link" href="{{ route('ppb.evaluations.index',['month'=>$report['month']]) }}">分析師列表</a><a class="link" href="{{ route('ppb.evaluations.show',[$report['subject']['type'],$report['subject']['id'],'month'=>$report['month'],'format'=>'csv']) }}">薪酬 CSV</a><button class="action" type="button" onclick="window.print()">列印</button></div>
<form method="get" class="search-row no-print"><input class="field" type="month" name="month" value="{{ $report['month'] }}" required><button class="action">切換月份</button></form>
@if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(!$report['native_attendance_video_supported'])<p class="notice">舊系統缺勤及影片採已核對的唯讀來源。V2 原生缺勤與影片登錄尚待整合，原生薪酬不能視為完整結算。</p>@endif
<section class="card eval-section"><h2>當月評鑑</h2><div class="table-scroll"><table class="data-table"><thead><tr><th>項目</th><th>統計頁</th><th>評級計算</th></tr></thead><tbody>
@foreach(['score_delivery_time'=>'交稿','score_compliance'=>'字數／格式','score_professionalism'=>'專業度','score_interaction_avg'=>'週互動平均','score_initiative'=>'主動性','score_winrate_bonus'=>'勝率加分','score_extra_adjustment'=>'額外調整','total_score'=>'總分'] as $field=>$label)
<tr><th>{{ $label }}</th><td>{{ $current['view'][$field] ?? '—' }}</td><td>{{ $current['rating'][$field] ?? '—' }}</td></tr>@endforeach
</tbody></table></div><p>統計頁勝率包含代班稿；評級計算排除代班稿。兩者沿用原系統的不同口徑。</p></section>
<section class="card eval-section"><h2>互動與人工評分</h2>
@if($manage)<form method="post" action="{{ route('ppb.evaluations.save',[$report['subject']['type'],$report['subject']['id']]) }}">
@include('ppb.evaluations.request')<input type="hidden" name="operation" value="manual"><div class="eval-grid">
@foreach(range(1,5) as $week)<label>第 {{ $week }} 週<input class="field" type="number" step="0.01" min="-9999" max="9999" name="score_interaction_w{{ $week }}" value="{{ old('score_interaction_w'.$week,$report['manual']['score_interaction_w'.$week]) }}"></label>@endforeach
@foreach(['score_interaction_feedback'=>'互動回饋','score_initiative'=>'主動性','score_extra_adjustment'=>'額外調整'] as $field=>$label)<label>{{ $label }}<input class="field" type="number" min="-9999" max="9999" name="{{ $field }}" value="{{ old($field,$report['manual'][$field]) }}" required></label>@endforeach
</div><p>留白不計入平均，0 分會計入；回饋加在各週平均之後。</p><button class="action">儲存人工評分</button></form>
@else<p>您目前只有檢視權限。</p>@endif</section>
<section class="card eval-section"><h2>評級與升降級</h2><p>本月等級：{{ $current['level']===null?'尚無歷史紀錄（薪酬採原規則等級 2）':$current['level'] }}；前月評級分數：{{ $report['previous']['rating']['total_score'] }}；下月試算：{{ $current['next_rating']['rating_level'] }}；已保存下月等級：{{ $report['saved_next_rating'] ?? '未保存' }}。</p>
@if($current['next_rating']['is_terminate'])<p class="notice">已達原規則終止條件，請管理者審核處理；本頁不自動停用分析師。</p>@endif
@if($manage)<form method="post" action="{{ route('ppb.evaluations.save',[$report['subject']['type'],$report['subject']['id']]) }}">@include('ppb.evaluations.request')<input type="hidden" name="operation" value="rebuild"><button class="action">確認並保存下月評級</button></form>@endif</section>
<section class="card eval-section"><h2>薪酬與缺勤</h2><div class="eval-grid">@foreach(['short'=>'短稿','long'=>'長稿','video'=>'影片','late'=>'遲到扣款','absent'=>'缺勤扣款','total'=>'合計'] as $field=>$label)<p>{{ $label }}：<strong>{{ $current['salary'][$field] }}</strong></p>@endforeach</div>
<p>短稿 {{ $current['counts']['salary_short'] }} 篇；長稿 {{ $current['counts']['salary_long'] }} 篇；代班缺席 {{ $current['counts']['absences'] }} 次。薪酬計件與評分選取分開。</p>
@foreach($current['deductions']['deductions'] as $amount)<span>缺勤／遲到 {{ $amount }}　</span>@endforeach</section>
<section class="card eval-section"><h2>當月稿件與評分</h2>
@forelse($current['selected']['records'] as $pick)<details><summary>{{ $pick['recommended_time'] }}・{{ $pick['_source_type'] }} #{{ $pick['_source_id'] }}・{{ (int)$pick['is_long']===1?'長稿':((int)$pick['is_bonus']===1?'BONUS':'短稿') }}・{{ [0=>'未結算',1=>'勝',2=>'負'][(int)$pick['is_win']]??'未結算' }}</summary>
@if($manage)<form class="rate-form" method="post" action="{{ route('ppb.evaluations.save',[$report['subject']['type'],$report['subject']['id']]) }}">@include('ppb.evaluations.request')<input type="hidden" name="operation" value="rate"><input type="hidden" name="source_type" value="{{ $pick['_source_type'] }}"><input type="hidden" name="source_id" value="{{ $pick['_source_id'] }}"><div class="eval-grid">
@foreach(['score_delivery_time'=>'交稿時間','score_word_count'=>'字數','score_format'=>'格式','score_typos'=>'錯字','score_semantic_clarity'=>'語意清晰','score_reference_accuracy'=>'引用正確','score_logic_consistency'=>'邏輯一致','score_language_appropriateness'=>'用語適切','score_professional_bonus'=>'專業加分'] as $field=>$label)<label>{{ $label }}<input class="field eval-score" type="number" name="{{ $field }}" value="{{ $pick[$field]??0 }}" min="-32768" max="32767" required></label>@endforeach
@if($pick['_source_type']==='v2')<label>結算<select name="result_state"><option value="0" @selected((int)$pick['is_win']===0)>未結算</option><option value="1" @selected((int)$pick['is_win']===1)>勝</option><option value="2" @selected((int)$pick['is_win']===2)>負</option></select></label>@endif
</div><button class="action">儲存本稿 V2 評分</button></form>@else<p>評分：@foreach(App\Services\Ppb\Evaluation\LegacyScheduleCalculator::RATING_FIELDS as $field) {{ $pick[$field]??0 }} @endforeach</p>@endif</details>
@empty<p>本月份沒有稿件。</p>@endforelse</section>
</div></main></div></div>
@endsection
