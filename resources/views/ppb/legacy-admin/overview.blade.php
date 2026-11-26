@extends('ppb.legacy-admin.layout')
@section('title', '排班總覽')

@section('legacy-content')
@php
  $weekdays = ['日','一','二','三','四','五','六'];
  $plays = [1=>'讓分',2=>'不讓分',3=>'大小分',4=>'特殊玩法',5=>'手動玩法'];
  $groups = $games->getCollection()->groupBy(fn ($game) => $game->recommended_at?->timezone('Asia/Taipei')->format('Y-m-d'));
@endphp
<div class="card">
  <form class="toolbar ppb-list-filters" method="get">
    <label class="field-label">賽事編號 <input class="field" name="match_no" value="{{ $matchNo }}" placeholder="例如：G31"></label>
    <label class="field-label">日期 <input class="field" name="match_date" type="date" value="{{ $matchDate }}"></label>
    <button class="action" type="submit">搜尋</button>
  </form>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>賽事編號</th><th>推薦時段</th><th>玩法</th><th>台彩賽事編號</th><th>對戰組合</th><th>分析文</th></tr></thead>
      @forelse($groups as $group)
        @php
          $date = $group->first()->recommended_at?->timezone('Asia/Taipei');
        @endphp
        <tbody>
        @if($date)<tr><th colspan="6" scope="rowgroup">{{ $date->format('m/d') }}（{{ $weekdays[$date->dayOfWeek] }}）</th></tr>@endif
        @foreach($group as $game)
          <tr>
            <td><strong>{{ $game->display_code }}</strong></td>
            <td>{{ $game->recommended_at?->timezone('Asia/Taipei')->format('H:i') ?? '—' }}</td>
            <td>{{ $plays[$game->play_type] ?? $game->legacyPrediction() ?? '玩法待補' }} {{ $game->play_value }}</td>
            <td>{{ $game->lotteryNumber() ?? '—' }}</td>
            <td>@include('ppb.legacy-admin.matchup')</td>
            <td>{{ $game->has_long_article ? '有' : '—' }}</td>
          </tr>
        @endforeach
        </tbody>
      @empty
        <tbody><tr><td colspan="6" class="empty">目前查詢條件下沒有賽事。請確認賽事編號或日期。</td></tr></tbody>
      @endforelse
    </table>
  </div>
  @include('ppb.legacy-admin.pager', ['paginator' => $games])
</div>
@endsection
