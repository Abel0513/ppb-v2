@extends('ppb.legacy-admin.layout')
@section('title', 'CSDA 用戶（唯讀）')

@section('legacy-content')
@if($denied)
  <p class="notice">CSDA 用戶資料需要排班管理及帳號管理權限才能檢視。</p>
@elseif(!$ready)
  <p class="notice">CSDA 用戶資料目前未匯入 V2，因此此頁沒有用戶可供查詢。</p>
@else
  <div data-csda-readonly="1">
    <p class="small-note">資料來源為 2026-11-26 舊站匯入快照。</p>
    <div class="cards ppb-user-stats">
      @foreach(['all' => '全部用戶', 'person' => '個人', 'group' => '群組', 'active' => '已開通', 'paid' => '付費會員'] as $key => $label)
        <div class="card"><div class="kpi-label">{{ $label }}</div><div class="kpi-number">{{ $stats[$key] }}</div></div>
      @endforeach
    </div>
    <div class="card">
      <div class="toolbar">
        <form class="search-row" method="get" action="{{ route('ppb.csda.users') }}" role="search">
          @if($filter !== 'all')<input type="hidden" name="filter" value="{{ $filter }}">@endif
          <input class="field" type="search" name="q" value="{{ $q }}" maxlength="100" placeholder="名稱／LINE ID" aria-label="搜尋名稱或 LINE ID">
          <button class="action" type="submit">搜尋</button>
        </form>
      </div>
      <nav class="ppb-segments ppb-list-tabs" aria-label="用戶類型">
        @foreach($filters as $key => [$label])
          <a href="{{ route('ppb.csda.users', array_filter(['filter' => $key === 'all' ? null : $key, 'q' => $q !== '' ? $q : null], fn ($value) => $value !== null)) }}"
            @if($filter === $key) aria-current="page" @endif>{{ $label }} {{ $counts[$key] }}</a>
        @endforeach
      </nav>
      <div class="table-scroll">
        <table class="data-table ppb-users-table">
          <thead><tr><th>名稱</th><th>類型</th><th>LINE ID</th><th>備註</th><th>監聽狀態</th><th>開通</th><th>付費</th><th>經銷商</th></tr></thead>
          <tbody>
          @forelse($users as $u)
            <tr>
              <td><strong>{{ $u->name }}</strong></td>
              <td>{{ (int) $u->type === 1 ? '個人' : '群組' }}</td>
              <td>{{ $u->line_id }}</td>
              <td>{{ $u->remark ?? '—' }}</td>
              @foreach(['is_listen' => ['監聽中', '未監聽'], 'is_active' => ['已開通', '未開通'], 'is_paid' => ['付費', '未付費'], 'is_dealer' => ['經銷商', '非經銷商']] as $field => [$on, $off])
                <td><span class="status {{ $u->$field ? 'enabled' : 'status-neutral' }}">{{ $u->$field ? $on : $off }}</span></td>
              @endforeach
            </tr>
          @empty
            <tr><td colspan="8" class="empty">沒有符合的資料</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
      @include('ppb.legacy-admin.pager', ['paginator' => $users])
    </div>
  </div>
@endif
@endsection
