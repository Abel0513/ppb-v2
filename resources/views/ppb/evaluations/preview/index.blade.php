@extends('ppb.layout')
@section('title','評鑑唯讀預覽')
@section('body')
<div class="admin-layout">@include('ppb.partials.sidebar')<div class="admin-main">@include('ppb.partials.topbar')
<main class="admin-content"><div class="admin-content-inner">
<h1 class="heading">月評鑑與薪酬｜唯讀預覽</h1>
<p class="notice">此頁只讀取舊系統已核對資料，不啟用保存、薪酬結算、PDF 發送或 V2 原生更正；不是正式薪酬明細。</p>
<form method="get" class="search-row"><label>評鑑月份 <input class="field" type="month" name="month" value="{{ $month }}" required></label><button class="action">查詢</button></form>
@if($previewError)
<div class="notice" role="alert" data-preview-error="{{ $previewError }}">
  <strong>唯讀資料暫時無法取得。</strong> 目前沒有成功讀取分析師名冊，所以下方不顯示任何推測資料。
  <a class="link" href="{{ route('ppb.evalpreview.health') }}">查看受保護的唯讀診斷結果</a>，確認故障階段後再修復。
</div>
@endif
<div class="card table-scroll"><table class="data-table"><thead><tr><th>分析師</th><th>來源</th><th>本月評分</th><th>前月評分</th><th>目前等級</th><th>下月試算</th><th>舊來源薪酬試算</th><th>明細</th></tr></thead><tbody>
@forelse($subjects as $s)
<tr data-preview-summary="{{ route('ppb.evalpreview.show',['id'=>$s['id'],'month'=>$month,'format'=>'summary']) }}">
<td>{{ $s['label'] }}@if(($s['status']??null)==='inactive') <small>（歷史）</small>@endif</td>
<td>舊系統 #{{ $s['id'] }}@if(!$s['mapped']) <small>（尚未對應 V2）</small>@endif</td>
@foreach(['score','previous_score','level','next_level','salary'] as $field)<td data-preview-field="{{ $field }}">讀取中</td>@endforeach
<td><a class="link" href="{{ route('ppb.evalpreview.show',['id'=>$s['id'],'month'=>$month]) }}">查看唯讀明細</a></td>
</tr>
@empty<tr><td colspan="8">{{ $previewError ? '資料讀取失敗，未載入分析師。' : '無符合資格的舊系統分析師。' }}</td></tr>@endforelse
</tbody></table></div>
<div class="pager">@if($page>1)<a class="link" href="{{ route('ppb.evalpreview.index',['month'=>$month,'page'=>$page-1]) }}">上一頁</a>@endif
<span>第 {{ $page }} 頁／{{ $total }} 位（每頁 5 位）</span>
@if($page*5<$total)<a class="link" href="{{ route('ppb.evalpreview.index',['month'=>$month,'page'=>$page+1]) }}">下一頁</a>@endif</div>
<p class="sub">統計數據使用獨立受限查詢逐位載入；查詢失敗顯示「未取得」，不以 0 假裝成功。</p>
</div></main></div></div>
<script src="{{ asset('assets/ppb/ppb-evaluation-preview.js') }}?v=p1" defer></script>
@endsection
