{{-- t60u 2026-11-27 | 分析師列表 restyled from the legacy AdminSchedule_analystlist page (read-only capture). --}}
@extends('ppb.legacy-admin.layout')
@section('title', $title)

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-analyst-list-legacy.css') }}?v=t60u-20261127">
@endpush

@section('legacy-content')
@php
  $selectedDate = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Taipei');
  $latest = \Carbon\CarbonImmutable::now('Asia/Taipei')->startOfMonth();
  $top = max($selectedDate, $latest);
  if ($selectedDate->lessThan($top->subMonths(5))) $top = $selectedDate;
  $canManageAnalysts = auth('ppb')->user()?->hasPpbPermission('schedules.manage');
  $fields = ['month_rate', 'score', 'rating'];
  $rateClass = fn ($value) => $value >= 55 ? 'rate-high' : ($value >= 45 ? 'rate-mid' : 'rate-low');
@endphp
<div class="ppb-al">
@if($unavailable)<p class="notice">讀取失敗</p>@endif
<div class="top-row">
  <form class="search-bar" data-report-filters>
    <input type="search" name="name" class="search-input" aria-label="分析師" placeholder="搜尋分析師名稱">
    <select class="search-select" name="rating" aria-label="所有評級">
      <option value="">所有評級</option>
      @foreach(['S','A','B','C'] as $level)<option>{{ $level }}</option>@endforeach
    </select>
    <button type="submit" class="search-btn">搜尋</button>
    <button type="reset" class="reset-btn">重置</button>
  </form>
  <nav class="tabs" aria-label="月份">
    @foreach(range(0, 5) as $offset)
      @php $tab = $top->subMonths($offset); @endphp
      <a class="tab {{ $tab->format('Y-m') === $month ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['month' => $tab->format('Y-m')]) }}"
        @if($tab->format('Y-m') === $month) aria-current="page" @endif>{{ $tab->format('Y/n月') }}</a>
    @endforeach
  </nav>
  @if($canManageAnalysts)
    <a class="manage-link" href="{{ route('ppb.analysts.index') }}">分析師資料管理</a>
  @endif
</div>
<div class="table-container">
<table data-legacy-report="{{ $kind }}" @if($unavailable) data-roster-unavailable @endif>
  <thead><tr>
    <th>分析師</th><th>類型</th><th>Line名稱</th>
    <th aria-sort="none"><button type="button" class="sort-btn" data-sort="month_rate">本月勝率 <span aria-hidden="true">⇅</span></button></th>
    <th aria-sort="none"><button type="button" class="sort-btn" data-sort="score">本月綜合評分 <span aria-hidden="true">⇅</span></button></th>
    <th aria-sort="none"><button type="button" class="sort-btn" data-sort="rating">分析師評級 <span aria-hidden="true">⇅</span></button></th>
  </tr></thead>
  <tbody>
    @foreach($roster as $subject)
      @php
        $profile = $profiles->get($subject['native_id']);
        $row = $values->get($subject['type'].':'.$subject['id']);
        $loading = $row === null;
        $pending = $loading || ($row['_stale'] ?? false);
        $failed = $row['failed'] ?? false;
        $key = ['type' => $subject['type'], 'id' => $subject['id'], 'month' => $month];
        $level = $subject['analyst_level'] ?? null;
      @endphp
      <tr data-report-values="{{ json_encode($row ?? [], JSON_THROW_ON_ERROR) }}" data-analyst-name="{{ $subject['label'] }}"
        @if($pending) data-report-url="{{ route('ppb.performance.summary', $key) }}" @endif @if($loading) aria-busy="true" @endif>
        <td>
          @if($canSeeWork)
            <a class="analyst-link" href="{{ route('ppb.evaluations.show', $key) }}">{{ $subject['label'] }}</a>
          @else
            <span class="analyst-link">{{ $subject['label'] }}</span>
          @endif
        </td>
        <td>
          @if((int) $level === 1 && $level !== null)
            <span class="type-senior">💎 &nbsp;資深分析師</span>
          @elseif($level !== null && (int) $level === 0)
            <span class="type-new">🌱 &nbsp;新銳分析師</span>
          @else
            <span class="no-data">無資料</span>
          @endif
        </td>
        <td>{{ $canSeeLineName ? ($profile?->line_name ?: ($subject['type'] === 'legacy' ? ($legacyLineNames[$subject['id']] ?? '') : '')) : '' }}</td>
        @foreach($fields as $field)
          @php
            $value = $row[$field] ?? null;
            $empty = $field === 'rating' ? '無資料' : '未分析';
            $display = $loading ? '載入中...' : ($failed ? '讀取失敗' : ($value ?? $empty));
            $class = $loading || $failed ? 'cell-pending' : ($value === null ? 'no-data'
                : ($field === 'month_rate' ? 'rate-badge '.$rateClass($value) : ($field === 'score' ? 'score' : 'rank')));
          @endphp
          <td><span class="{{ $class }}" data-report-field="{{ $field }}" data-empty="{{ $empty }}" data-legacy-style="{{ $field }}">{{ $display }}{{ $field === 'month_rate' && is_numeric($value) && !$loading && !$failed ? '%' : '' }}</span></td>
        @endforeach
      </tr>
    @endforeach
  </tbody>
</table>
</div>
</div>
@endsection

@section('legacy-scripts')
<script src="{{ asset('assets/ppb/ppb-legacy-admin.js') }}?v=t60u-20261127" defer></script>
@endsection
