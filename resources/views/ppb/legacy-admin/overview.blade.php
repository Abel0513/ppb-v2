@extends('ppb.legacy-admin.layout')
@section('title', '賽事排班總覽')

@section('legacy-content')
<form class="card toolbar legacy-filters" method="get">
  <label>賽事編號 <input class="field" name="match_no" value="{{ $matchNo }}" placeholder="例如：G31"></label>
  <label>日期 <input class="field" name="match_date" type="date" value="{{ $matchDate }}"></label>
  <button class="action">搜尋</button>
</form>
@php
    $weekdays = ['日','一','二','三','四','五','六'];
    $plays = [1=>'推 讓分',2=>'推 不讓分',3=>'推 大小分',4=>'特殊玩法',5=>'手動填寫玩法'];
@endphp
@forelse($games->getCollection()->groupBy(fn ($game) => $game->recommended_at?->timezone('Asia/Taipei')->format('Y-m-d')) as $group)
  @php($date = $group->first()->recommended_at?->timezone('Asia/Taipei'))
  <section class="legacy-overview-group">
    @if($date)<h2>{{ $date->format('m/d') }}（{{ $weekdays[$date->dayOfWeek] }}）</h2>@endif
    @foreach($group as $game)
      @php($notes = json_decode((string) $game->notes, true))
      <div class="legacy-game-row">
        @include('ppb.legacy-admin.game-pill', ['code' => $game->display_code])
        @php($hour = $game->recommended_at?->timezone('Asia/Taipei')->format('G'))
        @if($hour)<span class="ppb-shift-time {{ $hour === '11' ? 'ppb-shift-time-11' : 'ppb-shift-time-16' }}">{{ $hour }}點</span>@endif
        <span class="legacy-play">{{ $plays[$game->play_type] ?? '玩法待補' }} {{ $game->play_value }}</span>
        @if(($notes['_ppb_legacy_game_v1'] ?? false) && isset($notes['lottery_no']))
          <span class="ppb-shift-number" title="台彩賽事編號">{{ $notes['lottery_no'] }}</span>
        @endif
        <strong>@include('ppb.legacy-admin.matchup')</strong>
        @if($game->has_long_article)<span class="ppb-shift-label ppb-shift-label-long">分析文</span>@endif
      </div>
    @endforeach
  </section>
@empty
  <div class="notice">目前查詢條件下沒有賽事。請確認賽事編號或日期。</div>
@endforelse
@include('ppb.legacy-admin.pager')
@endsection
