@extends('ppb.legacy-admin.layout')
@section('title', '分析文排班')

@section('legacy-content')
@php
  $weekdays = ['日','一','二','三','四','五','六'];
  $previousDate = null;
@endphp
<section class="card">
  @if($month)
    <h2 class="card-title">{{ (int) substr($month, 0, 4) }} 年 {{ (int) substr($month, 5) }} 月</h2>
  @endif
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>推薦日期</th><th>賽事</th><th>分析師</th><th>對戰組合</th></tr></thead>
      <tbody>
      @forelse($games as $game)
        @php
          $date = $game->recommended_at?->timezone('Asia/Taipei');
          $dateKey = $date?->format('Y-m-d');
          $analyst = $game->assignments->first()?->currentAnalyst;
        @endphp
        <tr>
          <td>@if($date && $previousDate !== $dateKey){{ $date->format('m/d') }}（{{ $weekdays[$date->dayOfWeek] }}）@endif</td>
          <td><strong>{{ $game->display_code }}</strong></td>
          <td>{{ ($analyst?->display_name ?: $analyst?->name) ?? '未指派' }}</td>
          <td>@include('ppb.legacy-admin.matchup')</td>
        </tr>
        @php
          $previousDate = $dateKey;
        @endphp
      @empty
        <tr><td colspan="4" class="empty">目前查詢條件下沒有賽事。</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @include('ppb.legacy-admin.pager', ['paginator' => $pager, 'unit' => '個月'])
</section>
@endsection
