@php
    $analystName = $archivedName ?? ($slotAssignment?->currentAnalyst?->display_name ?: ($slotAssignment?->currentAnalyst?->name ?: '未指派'));
@endphp
@if($slotAssignment || isset($archivedName))
  <button type="button" class="ppb-shift-name" data-open-rating
    @if($slotAssignment) data-rating-url="{{ route('ppb.games.assignment-rating', [$game, $slotAssignment], false) }}" @endif
    data-game="{{ $game->display_code }}" data-matchup="{{ $game->matchup_text }}"
    data-time="{{ $recommendAt?->format('Y/m/d H:i') }} ｜ {{ $plays[$game->play_type] ?? $game->legacyPrediction() ?? '' }} {{ $game->play_value }}"
    data-analyst="{{ $analystName }}" data-recommendation="{{ ($pick['recommend_pick'] ?? null) ?: '尚未推薦' }}"
    @if($colors) style="@if($colors['background']!==null)background-color:{{ $colors['background'] }};@endif color:{{ $colors['color'] }}" @endif>{{ $analystName }}</button>
@else
  <span class="ppb-shift-name">{{ $analystName }}</span>
@endif
