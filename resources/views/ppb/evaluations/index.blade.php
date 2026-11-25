@extends('ppb.layout')
@section('title','月評鑑與薪酬')
@section('body')
<div class="admin-layout">@include('ppb.partials.sidebar')<div class="admin-main">@include('ppb.partials.topbar')<main class="admin-content"><div class="admin-content-inner">
<h1 class="heading">月評鑑與薪酬</h1><p class="sub">選擇評鑑月份與分析師。舊資料唯讀，評分修改及升降級紀錄保存在 V2。</p>
<form method="get" class="search-row"><label>評鑑月份 <input class="field" type="month" name="month" value="{{ $month }}" required></label><button class="action">查詢</button></form>
<div class="card table-scroll"><table class="data-table"><thead><tr><th>分析師</th><th>資料來源</th><th>V2 身分關聯</th><th>本月分數</th><th>前月</th><th>前二月</th><th>本月等級</th><th>下月試算</th><th>薪酬</th><th>月勝率</th><th>近十場</th><th>BONUS</th><th>作業</th></tr></thead><tbody>
@forelse($subjects as $subject)<tr data-evaluation-summary="{{ route('ppb.evaluations.show',[$subject['type'],$subject['id'],'month'=>$month,'format'=>'summary']) }}"><td>{{ $subject['label'] }}@if(($subject['status']??null)==='inactive') <small>（停用，保留歷史）</small>@endif</td><td>{{ $subject['type']==='legacy'?'舊系統 #'.$subject['id']:'V2 #'.$subject['id'] }}</td><td>{{ $subject['mapped']?'已驗證':'尚無唯一對應，分開計算' }}</td>@foreach(['score','previous_score','earlier_score','level','next_level','salary','month_rate','recent_rate','bonus_rate'] as $cell)<td data-summary-cell="{{ $cell }}">讀取中</td>@endforeach<td><a class="link" href="{{ route('ppb.evaluations.show',[$subject['type'],$subject['id'],'month'=>$month]) }}">評鑑・薪酬・稿件</a></td></tr>
@empty<tr><td colspan="13">目前沒有可評鑑分析師。</td></tr>@endforelse
</tbody></table></div><div class="pager">@if($page>1)<a class="link" href="{{ route('ppb.evaluations.index',['month'=>$month,'page'=>$page-1]) }}">上一頁</a>@endif <span>第 {{ $page }} 頁／共 {{ $total }} 位（每頁 5 位）</span> @if($page*5<$total)<a class="link" href="{{ route('ppb.evaluations.index',['month'=>$month,'page'=>$page+1]) }}">下一頁</a>@endif</div><p class="sub">逐位載入真實統計；讀取失敗不以 0 代替。無有效評分時總分沿用舊規則預設 60。薪酬可進入明細核對及匯出。</p></div></main></div></div>
<script src="{{ asset('assets/ppb/ppb-evaluations.js') }}?v=ui-restore" defer></script>
@endsection
