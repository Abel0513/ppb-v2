@extends('ppb.legacy-admin.layout')
@section('title', $title)

@section('legacy-content')
@php
  $isAnalystList = $kind === 'analyst-list';
  $isPerformance = $kind === 'performance';
  $isRating = !$isAnalystList && !$isPerformance;
  $showSalary = in_array($kind, ['salary', 'rating-salary'], true);
  $showRatings = in_array($kind, ['rating', 'rating-salary'], true);
  $date = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Taipei');
  $headers = match($kind) {
      'analyst-list' => ['type' => '類型', 'line' => 'Line名稱', 'month_rate' => '本月勝率',
          'score' => '本月綜合評分', 'rating' => '分析師評級'],
      'performance' => ['month_rate' => '本月戰績', 'month_record' => '本月勝敗', 'recent_rate' => '近10場勝率',
          'recent_record' => '近10場勝敗', 'bonus_record' => 'BONUS勝敗', 'bonus_rate' => 'BONUS勝率'],
      default => [],
  };
  $salaryHeaders = ['short' => '短文分析', 'late' => '遲到', 'absent' => '曠職', 'long' => '長文分析',
      'video' => '影音費', 'total' => '合計薪資'];
  if ($showSalary) $headers += $salaryHeaders;
  if ($showRatings) $headers += [
      'next_rating' => $date->addMonth()->month.'月評級', 'rating' => $date->month.'月評級', 'change' => '評級異動',
      'score_0' => $date->month.'月評分', 'score_1' => $date->subMonth()->month.'月評分', 'score_2' => $date->subMonths(2)->month.'月評分',
  ];
  $palette = config('ppb_legacy_colors', []);
@endphp
<div class="legacy-report-controls">
@if(!$isPerformance)
<form class="card toolbar legacy-filters" data-report-filters>
  <input type="search" name="name" class="field" aria-label="分析師" placeholder="搜尋分析師名稱">
  @if($isAnalystList || $showRatings)
  <select class="field" name="rating" aria-label="所有評級">
    <option value="">所有評級</option>
    @foreach($showRatings ? ['S','A','B','C','無資料'] : ['S','A','B','C'] as $level)
      <option>{{ $level }}</option>
    @endforeach
  </select>
  @endif
  @if($showRatings)
  <select class="field" name="change" aria-label="所有異動">
    <option value="">所有異動</option>
    @foreach(['升級','維持','降級','無資料'] as $change)<option>{{ $change }}</option>@endforeach
  </select>
  @endif
  <button type="submit" class="action">搜尋</button>
  <button type="reset" class="link">重置</button>
  @if($showRatings)<button type="button" class="action" disabled>更新當月評級</button>@endif
  @if($isRating && $canSeeWork)
    <a class="link" href="{{ route('ppb.evaluations.index', ['month' => $month]) }}">月評鑑與薪酬</a>
  @endif
</form>
@endif
@include('ppb.legacy-admin.month-tabs', ['ratingTabs' => $isRating])
</div>
@if($unavailable)<p class="notice">唯讀資料暫時無法取得。</p>@endif
<div class="card table-scroll">
<table class="data-table" data-legacy-report="{{ $kind }}" @if($unavailable) data-roster-unavailable @endif>
  <thead><tr><th>分析師</th>
    @foreach($headers as $field => $header)
      <th @class(['legacy-salary-total' => $field === 'total']) @if(($isAnalystList && in_array($field, ['month_rate', 'score', 'rating'], true)) || ($isPerformance && $field === 'month_rate')) aria-sort="none" @endif>
        @if(($isAnalystList && in_array($field, ['month_rate', 'score', 'rating'], true)) || ($isPerformance && $field === 'month_rate'))
          <button type="button" class="legacy-sort" data-sort="{{ $field }}">{{ $header }} ⇅</button>
        @else
          {{ $header }}
        @endif
      </th>
    @endforeach
    @if($isRating && $canSeeWork)<th>作業</th>@endif
  </tr></thead>
  <tbody>
    @foreach($roster as $subject)
      @php
        $profile = $profiles->get($subject['native_id']);
        $fallback = $subject['type'] === 'legacy' ? ($palette[$subject['id']] ?? null) : null;
        $row = $values->get($subject['type'].':'.$subject['id'], []);
        $key = ['type' => $subject['type'], 'id' => $subject['id'], 'month' => $month];
      @endphp
      <tr data-report-values="{{ json_encode($row, JSON_THROW_ON_ERROR) }}" data-analyst-name="{{ $subject['label'] }}">
        <td>@include('ppb.legacy-admin.name-pill', ['name' => $subject['label'], 'colors' => $profile ? $profile->scheduleColors($fallback) : $fallback])</td>
        @foreach($headers as $field => $header)
          @if($field === 'type')
            <td>{{ \App\Services\Ppb\LegacyDisplayLabels::analystType($subject['analyst_level'] ?? null) }}</td>
          @elseif($field === 'line')
            <td>{{ $canSeeLineName ? $profile?->line_name : '' }}</td>
          @else
            @php
              $value = $row[$field] ?? null;
              $isRate = str_ends_with($field, '_rate') && is_numeric($value);
              $display = $value ?? ($field === 'rating' || str_starts_with($field, 'score_') ? '無資料' : (str_starts_with($field, 'recent_') ? '未達10場' : '未分析'));
            @endphp
            <td @class(['legacy-salary-total' => $field === 'total'])><span @class(['legacy-win-rate' => $isRate, 'legacy-negative' => is_numeric($value) && $value < 0])>{{ $display }}{{ $isRate ? '%' : '' }}</span></td>
          @endif
        @endforeach
        @if($isRating && $canSeeWork)<td><a class="link" href="{{ route('ppb.evaluations.show', $key) }}">評鑑・薪酬・稿件</a></td>@endif
      </tr>
    @endforeach
  </tbody>
  @if($showSalary)
    <tfoot><tr><th scope="row">總計</th>
      @foreach($headers as $field => $header)
        <td @if(isset($salaryHeaders[$field])) data-report-total="{{ $field }}" @endif>@if(isset($salaryHeaders[$field])){{ $unavailable ? '—' : number_format($values->sum($field)) }}@endif</td>
      @endforeach
      @if($canSeeWork)<td></td>@endif
    </tr></tfoot>
  @endif
</table>
</div>
@endsection

@section('legacy-scripts')
<script src="{{ asset('assets/ppb/ppb-legacy-admin.js') }}" defer></script>
@endsection
