{{-- Shared 分析文排班 content (admin + public). Read-only; no admin links. --}}
@php
  $weekdays = ['日','一','二','三','四','五','六'];
  $legacyColors = config('ppb_legacy_colors', []);
  $previousDate = null;
@endphp
<div class="ppb-an">
  @if($month)
    <div class="month-title">{{ (int) substr($month, 0, 4) }} 年 {{ (int) substr($month, 5) }} 月</div>
  @endif
  @forelse($games as $game)
    @php
      $date = $game->recommended_at?->timezone('Asia/Taipei');
      $dateKey = $date?->format('Y-m-d');
      $assignment = $game->assignments->first();
      $analyst = $assignment?->currentAnalyst;
      $colors = $analyst?->scheduleColors($legacyColors[(int) $analyst->id] ?? null);
      $style = $colors ? trim(($colors['background'] ? 'background:'.$colors['background'].';' : '').($colors['color'] ? 'color:'.$colors['color'] : '')) : '';
      $newDate = $previousDate !== $dateKey;
    @endphp
    <div class="ss-row{{ $newDate && !$loop->first ? ' new-date' : '' }}">
      <span class="date-label">@if($date && $newDate){{ $date->format('m/d') }}（{{ $weekdays[$date->dayOfWeek] }}）@endif</span>
      <span class="tag {{ $game->game_type === 1 ? 'tag-g' : 'tag-e' }}">{{ $game->display_code }}</span>
      <span class="analyst-tag"@if($style !== '') style="{{ $style }}"@endif>{{ ($analyst?->display_name ?: $analyst?->name) ?? '未指派' }}</span>
      <span class="matchup">@include('ppb.legacy-admin.matchup')</span>
    </div>
    @php
      $previousDate = $dateKey;
    @endphp
  @empty
    <p class="an-empty">目前沒有分析文排班。</p>
  @endforelse
  @include('ppb.legacy-admin.pager', ['paginator' => $pager, 'unit' => '個月'])
</div>
