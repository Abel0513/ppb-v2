@extends('ppb.legacy-admin.layout')
@section('title', 'CSDA 用戶管理')

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-csda-users.css') }}?v=csda-20261126">
@endpush

@section('legacy-content')
@if($denied)
<p class="notice">CSDA 用戶管理含個人資料，需要排班管理及帳號管理權限才能檢視。</p>
@elseif(!$ready)
<p class="notice">CSDA 用戶資料目前未匯入 V2，因此此頁沒有用戶可供查詢。</p>
@else
<div class="ppb-csda" data-csda-readonly="1">
<div class="page">
  <div class="page-header"><span class="page-title">CSDA 用戶管理</span><span class="stat-label">V2 唯讀（舊站 2026-11-26 匯入）</span></div>
  <div class="stats">
    <div class="stat"><div class="stat-label">全部用戶</div><div class="stat-value">{{ $stats['all'] }} <span>位</span></div></div>
    <div class="stat"><div class="stat-label">個人</div><div class="stat-value">{{ $stats['person'] }} <span>位</span></div></div>
    <div class="stat"><div class="stat-label">群組</div><div class="stat-value">{{ $stats['group'] }} <span>個</span></div></div>
    <div class="stat"><div class="stat-label">已開通</div><div class="stat-value">{{ $stats['active'] }} <span>位</span></div></div>
    <div class="stat stat-paid"><div class="stat-label">付費會員</div><div class="stat-value">{{ $stats['paid'] }} <span>位</span></div></div>
  </div>
  <div class="toolbar">
    <div class="pills">
      @foreach($filters as $key => [$label])
        <a @class(['pill', 'active' => $filter === $key]) href="{{ route('ppb.csda.users', array_filter(['filter' => $key === 'all' ? null : $key, 'q' => $q !== '' ? $q : null])) }}">{{ $label }} <span class="cnt">{{ $counts[$key] }}</span></a>
      @endforeach
    </div>
    <form class="search-wrap" method="get" action="{{ route('ppb.csda.users') }}" role="search">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="22" y2="22"/></svg>
      @if($filter !== 'all')<input type="hidden" name="filter" value="{{ $filter }}">@endif
      <input type="text" name="q" value="{{ $q }}" placeholder="名稱 / LINE ID 搜尋..." aria-label="名稱 / LINE ID 搜尋">
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <colgroup><col style="width:10%"><col style="width:8%"><col style="width:22%"><col style="width:20%"><col style="width:8%"><col style="width:7%"><col style="width:7%"><col style="width:7%"><col style="width:7%"></colgroup>
      <thead><tr><th>名稱</th><th>類型</th><th>LINE ID</th><th>備註</th><th>監聽狀態</th><th>開通</th><th>付費</th><th>經銷商</th><th></th></tr></thead>
      <tbody>
      @foreach($users as $u)
        <tr>
          <td><span style="font-weight:600">{{ $u->name }}</span>@if($u->is_paid) <span class="paid-badge">★ 付費</span>@endif @if($u->is_dealer) <span class="dealer-badge">經銷</span>@endif</td>
          <td>@if((int) $u->type === 1)<span class="type-tag type-person">個人</span>@else<span class="type-tag type-group">群組</span>@endif</td>
          <td class="line-id">{{ $u->line_id }}</td>
          <td style="color:#888780;font-size:12px">{{ $u->remark ?? '—' }}</td>
          <td><span @class(['dot', 'dot-on' => $u->is_listen, 'dot-off' => !$u->is_listen])>{{ $u->is_listen ? '監聽中' : '未監聽' }}</span></td>
          @foreach(['is_active' => ['toggle-active', '已開通', '未開通'], 'is_paid' => ['toggle-paid', '付費', '未付費'], 'is_dealer' => ['toggle-dealer', '經銷商', '非經銷商']] as $field => [$class, $on, $off])
            <td><label class="toggle {{ $class }}" title="{{ $u->$field ? $on : $off }}（V2 唯讀）"><input type="checkbox" disabled @checked($u->$field) aria-label="{{ $u->$field ? $on : $off }}"><span class="slider"></span></label></td>
          @endforeach
          <td></td>
        </tr>
      @endforeach
      </tbody>
    </table>
    @if($users->isEmpty())<div class="empty" style="display:block">沒有符合的資料</div>@endif
    @if($users->total() > 0)
    <div class="pagination" style="display:flex">
      <span class="pagination-info">共 {{ $users->total() }} 筆，第 {{ $users->currentPage() }} / {{ $users->lastPage() }} 頁</span>
      <div class="pagination-btns">
        @if($users->onFirstPage())<button class="pg-btn" disabled>‹</button>@else<a class="pg-btn" href="{{ $users->previousPageUrl() }}" rel="prev">‹</a>@endif
        @foreach($users->getUrlRange(1, $users->lastPage()) as $number => $url)
          @if($number === $users->currentPage())<span class="pg-btn active" aria-current="page">{{ $number }}</span>@else<a class="pg-btn" href="{{ $url }}">{{ $number }}</a>@endif
        @endforeach
        @if($users->hasMorePages())<a class="pg-btn" href="{{ $users->nextPageUrl() }}" rel="next">›</a>@else<button class="pg-btn" disabled>›</button>@endif
      </div>
    </div>
    @endif
  </div>
</div>
</div>
@endif
@endsection
