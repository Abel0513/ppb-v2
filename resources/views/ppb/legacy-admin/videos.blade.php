@extends('ppb.legacy-admin.layout')
@section('title', '影音排班')

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-schedule-dialogs.css') }}?v=v2-style-20261126">
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-videos-legacy.css') }}?v=20261127-2">
@endpush

@section('legacy-content')
@php
  $weekLabels = [1 => '第一周', '第二周', '第三周', '第四周', '第五周'];
  $people = config('ppb_video_people', []);
  $legacyColors = config('ppb_legacy_colors', []);
  $analysts = \App\Models\PpbAnalyst::whereIn('id', $roster->pluck('native_id')->filter()->all())->get()->keyBy('id');
  $person = function (?string $name, ?array $colors = null) use ($people) {
      $name = trim((string) $name);
      if ($name === '') return null;
      $colors = $colors ?: ($people['colors'][$name] ?? $people['fallback'] ?? ['background' => '#f3f4f6', 'color' => '#374151']);
      $file = 'assets/ppb/people/'.substr(sha1($name), 0, 12).'.webp';
      return ['name' => $name, 'initials' => mb_substr($name, 0, 2),
          'photo' => is_file(public_path($file)) ? asset($file) : null,
          'style' => 'background:'.($colors['background'] ?? '#f3f4f6').';color:'.($colors['color'] ?? '#374151')];
  };
  $guest = function (?string $key) use ($roster, $analysts, $legacyColors, $person) {
      $subject = $key ? $roster->get($key) : null;
      if (! $subject) return null;
      $analyst = $subject['native_id'] ? $analysts->get($subject['native_id']) : null;
      $colors = $analyst?->scheduleColors($legacyColors[(int) $analyst->id] ?? null);
      return $person($subject['label'], $colors && $colors['background'] ? $colors : null);
  };
@endphp
<div class="ppb-vs">
<div class="ppb-vs-header">
  <div class="title">影音<span>班表</span></div>
  @if($canManageVideos)<button type="button" class="btn-add" data-open-video>＋ 新增班表</button>@endif
</div>
@include('ppb.legacy-admin.month-tabs', ['plainTabs' => true])
@if(!$ready)<p class="notice">影音排班資料暫時無法取得。</p>@endif
<div class="ppb-vs-grid">
  @forelse($videos as $video)
    @php
      $date = \Carbon\CarbonImmutable::parse($video->scheduled_at, 'UTC')->tz('Asia/Taipei');
      $editor = $roster->get($video->guest_1);
      $fields = [
          'video_id' => $video->id, 'video_revision' => $video->revision ?? null, 'scheduled_at' => $date->format('Y-m-d\TH:i'),
          'host_name' => $video->host_name ?? null, 'editor_name' => $video->editor_name ?? null, 'week_no' => $video->week_no ?? null,
          'sport_label' => $video->sport_label ?? null, 'guest_1' => $video->guest_1, 'guest_2' => $video->guest_2,
          'guest_3' => $video->guest_3, 'cancelled' => (int) $video->cancelled,
      ];
      $sport = trim((string) ($video->sport_label ?? ''));
      $members = [['p' => $person($video->host_name ?? null), 'role' => '主持人']];
      foreach (range(1, 3) as $slot) $members[] = ['p' => $guest($video->{'guest_'.$slot}), 'role' => '來賓 '.$slot];
      $cutter = $person($video->editor_name ?? null);
    @endphp
    <section class="card {{ $video->cancelled ? 'is-cancelled' : '' }}">
      <div class="card-head">
        <div class="card-meta">
          @if($sport !== '')<span class="sport-tag {{ strtoupper($sport) === 'NBA' ? 'nba' : 'other' }}">{{ $sport }}</span>@endif
          <span class="card-date">{{ $date->format('n/j') }}{{ isset($weekLabels[$video->week_no ?? 0]) ? '（'.$weekLabels[$video->week_no].'）' : '' }}</span>
          @if($video->cancelled)<span class="cancelled-tag">已取消</span>@endif
        </div>
        @if($canManageVideos && $editor)
          <div class="card-actions">
            <button type="button" class="action-btn edit" title="編輯" aria-label="編輯" data-open-video="{{ json_encode($fields, JSON_THROW_ON_ERROR) }}">✏️</button>
            @unless($video->cancelled)
              <button type="button" class="action-btn del" title="取消排班" aria-label="取消排班" data-cancel-video="{{ json_encode($fields, JSON_THROW_ON_ERROR) }}">🗑️</button>
            @endunless
          </div>
        @endif
      </div>
      <div class="card-body">
        <div class="section-label">主持人 &amp; 來賓</div>
        <div class="persons">
          @foreach($members as $member)
            <div class="person-card">
              @if($member['p'])
                <div class="avatar" style="{{ $member['p']['style'] }}">@if($member['p']['photo'])<img src="{{ $member['p']['photo'] }}" alt="{{ $member['p']['name'] }}" loading="lazy">@else{{ $member['p']['initials'] }}@endif</div>
                <span class="person-name">{{ $member['p']['name'] }}</span>
              @else
                <div class="avatar avatar-empty">—</div>
                <span class="person-name">—</span>
              @endif
              <span class="person-role">{{ $member['role'] }}</span>
            </div>
          @endforeach
        </div>
        <div class="card-divider"></div>
        <div class="editor-row">
          @if($cutter)
            <div class="editor-avatar" style="{{ $cutter['style'] }}">@if($cutter['photo'])<img src="{{ $cutter['photo'] }}" alt="{{ $cutter['name'] }}" loading="lazy">@else{{ $cutter['initials'] }}@endif</div>
          @else
            <div class="editor-avatar avatar-empty">—</div>
          @endif
          <div class="editor-info"><span class="editor-name">{{ $cutter['name'] ?? '—' }}</span><span class="editor-role">剪輯師</span></div>
        </div>
      </div>
    </section>
  @empty
    @if($ready)<p class="empty-msg">本月尚無班表</p>@endif
  @endforelse
</div>
</div>
@if($canManageVideos)
<dialog class="ppb-schedule-dialog" id="ppbVideoDialog" aria-labelledby="ppbVideoTitle">
  <header class="ppb-dialog-header"><h2 id="ppbVideoTitle">新增影音排班</h2><button type="button" class="admin-button admin-button-secondary ppb-dialog-icon" data-dialog-close aria-label="關閉">×</button></header>
  <p role="status" data-dialog-status></p>
  <form data-video-form>
    @csrf
    <input type="hidden" name="month" value="{{ $month }}">
    <input type="hidden" name="schedule_scope" value="video">
    <input type="hidden" name="operation" value="input">
    <input type="hidden" name="input_kind" value="video">
    <input type="hidden" name="video_id">
    <input type="hidden" name="video_revision">
    <input type="hidden" name="cancelled" value="0">
    <div data-video-fields>
      <label class="field-label">日期<input class="field" type="datetime-local" name="scheduled_at" required></label>
      <label class="field-label">週次<select class="field" name="week_no">
        <option value="">請選擇週次</option>
        @foreach($weekLabels as $number => $label)<option value="{{ $number }}">{{ $label }}</option>@endforeach
      </select></label>
      <label class="field-label">推薦賽事<input class="field" name="sport_label" maxlength="160"></label>
      <h3>人員配置</h3>
      <label class="field-label">主持人<input class="field" name="host_name" maxlength="100"></label>
      @foreach(range(1, 3) as $slot)
        <label class="field-label">來賓 {{ $slot }}<select class="field" name="guest_{{ $slot }}" @required($slot === 1)>
          <option value="">請選擇來賓 {{ $slot }}</option>
          @foreach($roster as $key => $subject)
            <option value="{{ $key }}" data-receipt-url="{{ route('ppb.schedule.videos.receipt', [$subject['type'], $subject['id'], 'month' => $month], false) }}">{{ $subject['label'] }}</option>
          @endforeach
        </select></label>
      @endforeach
      <label class="field-label">剪輯師<input class="field" name="editor_name" maxlength="100"></label>
    </div>
    <footer><button class="action" type="submit">建立排班</button><button class="admin-button admin-button-secondary" type="button" data-dialog-close>取消</button></footer>
  </form>
</dialog>
@endif
@endsection

@section('legacy-scripts')
<script src="{{ asset('assets/ppb/ppb-schedule-dialogs.js') }}?v=v2-style-20261126" defer></script>
@endsection
