@extends('ppb.layout')
@section('title','評鑑唯讀明細')
@push('styles')<style>
.eval-preview-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.eval-preview-grid p{border:1px solid #d9e0e9;border-radius:8px;padding:12px;margin:0}
.eval-preview-section{margin-block:24px}
@media print{.admin-sidebar,.admin-topbar,.no-print{display:none!important}.admin-main{margin:0!important}}
</style>@endpush
@section('body')
<div class="admin-layout">@include('ppb.partials.sidebar')<div class="admin-main">@include('ppb.partials.topbar')
<main class="admin-content"><div class="admin-content-inner">
<h1 class="heading">{{ $report['subject']['label'] }}｜{{ $report['month'] }} 評鑑預覽</h1>
<div class="toolbar no-print"><a class="link" href="{{ route('ppb.evalpreview.index',['month'=>$report['month']]) }}">返回列表</a><button class="action" type="button" onclick="window.print()">列印預覽</button></div>
<p class="notice">只顯示舊系統唯讀來源；尚未包含 V2 人工評分、原生投稿、影片、缺勤更正。薪酬為試算，不代表正式應發金額。此頁沒有儲存入口。</p>
@php($now=$report['current'])
<section class="card eval-preview-section"><h2>本月評鑑</h2><div class="table-scroll"><table class="data-table">
<thead><tr><th>項目</th><th>統計口徑</th><th>評級口徑</th></tr></thead><tbody>
@foreach(['score_delivery_time'=>'交稿','score_compliance'=>'字數與格式','score_professionalism'=>'專業度','score_interaction_avg'=>'週互動平均','score_initiative'=>'主動性','score_winrate_bonus'=>'勝率加分','score_extra_adjustment'=>'額外調整','total_score'=>'總分'] as $f=>$label)
<tr><th>{{ $label }}</th><td>{{ $now['view'][$f]??'—' }}</td><td>{{ $now['rating'][$f]??'—' }}</td></tr>
@endforeach
</tbody></table></div><p class="sub">統計頁與評級計算對代班稿採不同口徑，仍沿用舊規則。</p></section>
<section class="card eval-preview-section"><h2>等級試算</h2><div class="eval-preview-grid">
<p>當月等級：<strong>{{ $report['level'] }}</strong></p><p>當月評級分數：<strong>{{ $now['rating']['total_score'] }}</strong></p>
<p>前月評級分數：<strong>{{ $report['previous']['rating']['total_score'] }}</strong></p>
<p>下月等級試算：<strong>{{ $now['next_rating']['rating_level'] }}</strong></p></div>
@if($now['next_rating']['is_terminate'])<p class="notice">符合舊規則終止條件；預覽不會自動停用分析師。</p>@endif
</section>
<section class="card eval-preview-section"><h2>舊來源薪酬試算</h2><div class="eval-preview-grid">
@foreach(['short'=>'短稿','long'=>'長稿','video'=>'影片','late'=>'遲到扣款','absent'=>'缺勤扣款','total'=>'合計'] as $f=>$label)
<p>{{ $label }}：<strong>{{ $now['salary'][$f] }}</strong></p>@endforeach
</div><p class="sub">短稿 {{ $now['counts']['salary_short'] }} 篇、長稿 {{ $now['counts']['salary_long'] }} 篇。原生 V2 資料未納入。</p></section>
<section class="card eval-preview-section"><h2>賽果表現</h2><div class="table-scroll"><table class="data-table">
<thead><tr><th>範圍</th><th>勝</th><th>負</th><th>勝率</th></tr></thead><tbody>
@foreach(['month'=>'當月一般短稿','bonus'=>'當月 BONUS','recent10'=>'最近十場'] as $key=>$label)
<tr><th>{{ $label }}</th><td>{{ $now['performance'][$key]['wins']??'—' }}</td><td>{{ $now['performance'][$key]['losses']??'—' }}</td>
<td>{{ isset($now['performance'][$key]['rate'])?$now['performance'][$key]['rate'].'%':'—' }}</td></tr>@endforeach
</tbody></table></div></section>
<p class="sub">來源指紋：{{ substr($report['source_sha256'],0,12) }}…（不回傳原始稿件或私有姓名）</p>
</div></main></div></div>
@endsection
