@extends('ppb.layout')
@section('title','月評鑑與薪酬')
@section('body')
<div class="admin-layout">@include('ppb.partials.sidebar')<div class="admin-main">@include('ppb.partials.topbar')<main class="admin-content"><div class="admin-content-inner">
<h1 class="heading">月評鑑與薪酬</h1><p class="sub">選擇評鑑月份與分析師。舊資料唯讀，評分修改及升降級紀錄保存在 V2。</p>
<form method="get" class="search-row"><label>評鑑月份 <input class="field" type="month" name="month" value="{{ $month }}" required></label><button class="action">查詢</button></form>
<div class="card table-scroll"><table class="data-table"><thead><tr><th>分析師</th><th>資料來源</th><th>V2 身分關聯</th><th>作業</th></tr></thead><tbody>
@forelse($subjects as $subject)<tr><td>{{ $subject['label'] }}</td><td>{{ $subject['type']==='legacy'?'舊系統 #'.$subject['id']:'V2 #'.$subject['id'] }}</td><td>{{ $subject['mapped']?'已驗證':'尚無唯一對應，分開計算' }}</td><td><a class="link" href="{{ route('ppb.evaluations.show',[$subject['type'],$subject['id'],'month'=>$month]) }}">評鑑・薪酬・稿件</a></td></tr>
@empty<tr><td colspan="4">目前沒有可評鑑分析師。</td></tr>@endforelse
</tbody></table></div></div></main></div></div>
@endsection
