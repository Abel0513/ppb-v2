@extends('ppb.legacy-admin.layout')
@section('title', '影音班表')

@section('legacy-content')
@if($canManageVideos)
  <a class="action" href="{{ route('ppb.evaluations.index', ['month' => $month]) }}">＋ 新增班表</a>
@endif
@include('ppb.legacy-admin.month-tabs')
@if(!$ready || $unavailable)<p class="notice">唯讀資料暫時無法取得。</p>@endif
<div class="legacy-video-grid">
  @forelse($videos as $video)
    @php
      $date = \Carbon\CarbonImmutable::parse($video->scheduled_at, 'UTC')->tz('Asia/Taipei');
      $editor = $roster->get($video->guest_1);
    @endphp
    <section class="card legacy-date-group">
      <header>
        <h2>{{ $date->format('n/j') }}</h2>
        @if($canManageVideos && $editor)
          <a class="ppb-shift-action" aria-label="編輯" href="{{ route('ppb.evaluations.show', [$editor['type'], $editor['id'], 'month' => $month]) }}#evaluation-video-{{ $video->id }}">✏️</a>
        @endif
      </header>
      <h3>主持人 &amp; 來賓</h3>
      <div class="legacy-video-guests">
      @foreach(range(1, 3) as $slot)
        @php $guest = $roster->get($video->{'guest_'.$slot}); @endphp
        <p>來賓 {{ $slot }}
          @include('ppb.legacy-admin.name-pill', [
              'name' => $guest['label'] ?? '—',
              'colors' => $guest && $guest['type'] === 'legacy' ? config('ppb_legacy_colors.'.$guest['id']) : null,
          ])
        </p>
      @endforeach
      </div>
      @if($video->cancelled)<p>已取消</p>@endif
    </section>
  @empty
    @if($ready)<p class="notice">無資料</p>@endif
  @endforelse
</div>
@endsection
