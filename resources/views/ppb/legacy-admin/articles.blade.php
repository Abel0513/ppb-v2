@extends('ppb.legacy-admin.layout')
@section('title', '分析文班表總覽')

@section('legacy-content')
@php
  $weekdays = ['日','一','二','三','四','五','六'];
  $previousDay = null;
  $palette = config('ppb_legacy_colors', []);
@endphp
@if($month)
  <h2>{{ (int) substr($month, 0, 4) }} 年 {{ (int) substr($month, 5) }} 月</h2>
@endif
<section class="card legacy-date-group">
@forelse($games as $game)
  @php
    $date = $game->recommended_at?->timezone('Asia/Taipei');
    $analyst = $game->assignments->first()?->currentAnalyst;
  @endphp
  <div class="legacy-game-row">
    <span class="legacy-day">@if($date && $previousDay !== $date->day){{ $date->format('m/d') }}（{{ $weekdays[$date->dayOfWeek] }}）@endif</span>
    @include('ppb.legacy-admin.game-pill', ['code' => $game->display_code])
    @include('ppb.legacy-admin.name-pill', [
        'name' => ($analyst?->display_name ?: $analyst?->name) ?? '未指派',
        'colors' => $analyst?->scheduleColors($palette[$analyst->id] ?? null),
    ])
    <strong>@include('ppb.legacy-admin.matchup')</strong>
  </div>
  @php($previousDay = $date?->day)
@empty
  <p class="notice">目前查詢條件下沒有賽事。</p>
@endforelse
</section>
@include('ppb.legacy-admin.pager', ['games' => $pager, 'unit' => '個月'])
@endsection
