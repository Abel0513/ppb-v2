@extends('ppb.legacy-admin.layout')
@section('title', '出缺勤紀錄表')

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-attendance-legacy.css') }}?v=20261127-2">
@endpush

@section('legacy-content')
@php
  $legacyColors = config('ppb_legacy_colors', []);
  $analysts = \App\Models\PpbAnalyst::whereIn('id', $attendance->pluck('native_id')->filter()->unique()->all())->get()->keyBy('id');
@endphp
<div class="ppb-att{{ $canCorrect ? ' can-correct' : '' }}">
@if($unavailable)<p class="notice">舊系統出缺勤暫時無法讀取</p>@endif
@if(!$ready)<p class="notice">唯讀資料暫時無法取得。</p>@endif
<div class="page-title"><span class="page-title-month">{{ $month }}</span> 出缺勤紀錄</div>
<div class="table-container">
  <div class="thead">
    <span class="c-action"></span>
    <span class="c-game">賽事</span>
    <span class="c-analyst">分析師</span>
    <span class="c-matchup">對戰組合</span>
    <span class="c-status">狀態</span>
  </div>
  @forelse($attendance as $row)
    @php
      $deleteTarget = $row['source'] === 'legacy'
        ? ['source' => 'legacy', 'legacy_record_id' => (string) $row['legacy_record_id']]
        : ['source' => 'v2', 'subject_type' => $row['subject_type'], 'subject_id' => (string) $row['subject_id'], 'game_id' => (string) $row['gid']];
      $game = $row['game'];
      $absent = $row['attendance_status'] === 2;
      $code = (string) $row['code'];
      $analyst = $row['native_id'] ? $analysts->get($row['native_id']) : null;
      $colors = $analyst?->scheduleColors($legacyColors[(int) $analyst->id] ?? null);
      $style = $colors ? trim(($colors['background'] ? 'background:'.$colors['background'].';' : '').($colors['color'] ? 'color:'.$colors['color'] : '')) : '';
    @endphp
    <div class="row">
      <div class="c-action">
        @if($canCorrect)
          <button class="btn-delete" type="button" data-attendance-delete="{{ json_encode($deleteTarget, JSON_THROW_ON_ERROR) }}" title="刪除" aria-label="刪除此筆出缺勤紀錄">🗑️</button>
        @endif
        @if($row['correctionUrl'])
          <a class="btn-action" href="{{ $row['correctionUrl'] }}" title="更正" aria-label="V2 出勤登錄與更正">✏️</a>
        @endif
      </div>
      <div class="c-game"><span class="tag {{ str_starts_with($code, 'E') ? 'tag-e' : 'tag-g' }}">{{ $code }}</span></div>
      <div class="c-analyst"><span class="analyst-tag"@if($style !== '') style="{{ $style }}"@endif>{{ $row['name'] }}</span></div>
      <div class="c-matchup">@if($game)@include('ppb.legacy-admin.matchup')@else—@endif</div>
      <div class="c-status"><span class="status-badge {{ $absent ? 'status-absent' : 'status-late' }}">{{ $absent ? '曠職' : '遲到' }}</span></div>
    </div>
  @empty
    @if($ready)<div class="empty-hint">本月無出缺勤紀錄</div>@endif
  @endforelse
</div>
<div class="pagination-bar">
  <div class="pagination-info">共 {{ $pager->total() }} 個月 / 第 {{ $pager->currentPage() }} 頁</div>
  @if($pager->hasPages())
    <div class="pagination-wrap">
      @unless($pager->onFirstPage())<a class="prev" href="{{ $pager->previousPageUrl() }}" rel="prev" aria-label="上一頁">‹</a>@endunless
      @foreach($pager->getUrlRange(1, $pager->lastPage()) as $number => $url)
        @if($number == $pager->currentPage())<span class="current" aria-current="page">{{ $number }}</span>@else<a class="num" href="{{ $url }}" aria-label="第 {{ $number }} 頁">{{ $number }}</a>@endif
      @endforeach
      @if($pager->hasMorePages())<a class="next" href="{{ $pager->nextPageUrl() }}" rel="next" aria-label="下一頁">›</a>@endif
    </div>
  @endif
</div>
@if($canCorrect)
  <dialog class="delete-dialog" id="attendance-delete-dialog" aria-labelledby="attendance-delete-title"
      aria-describedby="attendance-delete-description" data-url="{{ route('ppb.schedule.attendance.delete') }}" data-csrf="{{ csrf_token() }}">
    <div class="delete-icon" aria-hidden="true">⚠️</div>
    <div class="delete-title" id="attendance-delete-title">刪除後無法復原</div>
    <p id="attendance-delete-description">確定要刪除此筆紀錄？</p>
    <p class="delete-error" role="alert" hidden></p>
    <div class="delete-actions">
      <button type="button" data-cancel autofocus>取消</button>
      <button type="button" data-confirm>確定刪除</button>
    </div>
  </dialog>
@endif
</div>
@endsection
@section('legacy-scripts')
@if($canCorrect)
<script src="{{ asset('assets/ppb/ppb-attendance.js') }}?v=20261127-1" defer></script>
@endif
@endsection
