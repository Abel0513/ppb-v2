@extends('ppb.legacy-admin.layout')
@section('title', '排班總覽')

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-overview-legacy.css') }}?v=20261126-1">
@endpush

@section('legacy-content')
@php
  $weekdays = ['日','一','二','三','四','五','六'];
  // Same wording as the rotation table (games-index): 推 讓分 / 推 不讓分 / 推 大小分.
  $plays = [1=>'推 讓分',2=>'推 不讓分',3=>'推 大小分',4=>'特殊玩法',5=>'手動玩法'];
  $groups = $games->getCollection()->groupBy(fn ($game) => $game->recommended_at?->timezone('Asia/Taipei')->format('Y-m-d'));
@endphp
<div class="ppb-ov">
  <form class="search-card" method="get" action="{{ url()->current() }}" aria-label="搜尋賽事">
    <div class="search-row">
      <label class="search-field"><span class="search-label">賽事編號</span><input type="text" name="match_no" value="{{ $matchNo }}" placeholder="例如：G31" maxlength="12" autocomplete="off"></label>
      <label class="search-field"><span class="search-label">日期</span><input type="text" name="match_date" value="{{ $matchDate }}" placeholder="賽事推薦時間" aria-label="賽事推薦時間" onfocus="this.type='date'" onblur="if(!this.value)this.type='text'"></label>
      <button type="submit" class="search-btn">搜尋</button>
    </div>
  </form>

  <div class="ov-panel">
    @forelse($groups as $group)
      @php
        $date = $group->first()->recommended_at?->timezone('Asia/Taipei');
      @endphp
      <section class="date-group">
        @if($date)<div class="date-header"><span class="date-label">{{ $date->format('m/d') }}（{{ $weekdays[$date->dayOfWeek] }}）</span></div>@endif
        @foreach($group as $game)
          @php
            $at = $game->recommended_at?->timezone('Asia/Taipei');
            $play = $plays[$game->play_type] ?? $game->legacyPrediction() ?? '玩法待補';
            $playText = trim($play.' '.($game->play_value ?: ''));
          @endphp
          <a class="ss-row" href="{{ route('ppb.games.show', $game) }}">
            <span class="tag {{ $game->game_type === 1 ? 'tag-g' : 'tag-e' }}">{{ $game->display_code }}</span>
            <span class="time-tag {{ $at && $at->format('H') === '11' ? 'time-11' : 'time-16' }}">{{ $at ? $at->format('G').'點' : '—' }}</span>
            <span class="play-tag" title="{{ $playText }}">{{ $playText }}</span>
            <span class="lottery" title="台彩賽事編號">{{ $game->lotteryNumber() ?? '—' }}</span>
            <span class="matchup">@include('ppb.legacy-admin.matchup')@if($game->has_long_article)<span class="article-tag">分析文</span>@endif</span>
          </a>
        @endforeach
      </section>
    @empty
      <p class="ov-empty">目前查詢條件下沒有賽事。請確認賽事編號或日期。</p>
    @endforelse
  </div>
  @include('ppb.legacy-admin.pager', ['paginator' => $games])
</div>
@endsection
