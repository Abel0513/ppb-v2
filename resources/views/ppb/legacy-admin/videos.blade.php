@extends('ppb.legacy-admin.layout')
@section('title', '影音排班')

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-schedule-dialogs.css') }}?v=v2-style-20261126">
@endpush

@section('legacy-content')
@if($canManageVideos)
  <div class="toolbar"><button type="button" class="action" data-open-video>＋ 新增影音排班</button></div>
@endif
@include('ppb.legacy-admin.month-tabs')
@if(!$ready)<p class="notice">影音排班資料暫時無法取得。</p>@endif
@php $weekLabels = [1 => '第一周', '第二周', '第三周', '第四周', '第五周']; @endphp
<div class="ppb-video-grid">
  @forelse($videos as $video)
    @php
      $date = \Carbon\CarbonImmutable::parse($video->scheduled_at, 'UTC')->tz('Asia/Taipei');
      $editor = $roster->get($video->guest_1);
      $fields = [
          'video_id' => $video->id, 'video_revision' => $video->revision, 'scheduled_at' => $date->format('Y-m-d\TH:i'),
          'host_name' => $video->host_name ?? null, 'editor_name' => $video->editor_name ?? null, 'week_no' => $video->week_no ?? null,
          'sport_label' => $video->sport_label ?? null, 'guest_1' => $video->guest_1, 'guest_2' => $video->guest_2,
          'guest_3' => $video->guest_3, 'cancelled' => (int) $video->cancelled,
      ];
    @endphp
    <section class="card">
      <header class="toolbar">
        <h2 class="card-title">{{ $date->format('n/j') }}{{ isset($weekLabels[$video->week_no ?? 0]) ? '（'.$weekLabels[$video->week_no].'）' : '' }}</h2>
        @if($canManageVideos && $editor)
          <div class="ppb-video-actions">
            <button type="button" class="admin-button admin-button-secondary" data-open-video="{{ json_encode($fields, JSON_THROW_ON_ERROR) }}">編輯</button>
            @unless($video->cancelled)
              <button type="button" class="admin-button admin-button-secondary" data-cancel-video="{{ json_encode($fields, JSON_THROW_ON_ERROR) }}">取消排班</button>
            @endunless
          </div>
        @endif
      </header>
      <dl class="ppb-video-people">
        @if(!empty($video->sport_label))
          <dt>推薦賽事</dt><dd>{{ $video->sport_label }}</dd>
        @endif
        <dt>主持人</dt><dd>{{ $video->host_name ?? '—' }}</dd>
        @foreach(range(1, 3) as $slot)
          @php
            $guest = $roster->get($video->{'guest_'.$slot});
          @endphp
          <dt>來賓 {{ $slot }}</dt><dd>{{ $guest['label'] ?? '—' }}</dd>
        @endforeach
        <dt>剪輯師</dt><dd>{{ $video->editor_name ?? '—' }}</dd>
      </dl>
      @if($video->cancelled)<p class="status disabled">已取消</p>@endif
    </section>
  @empty
    @if($ready)<p class="notice">無資料</p>@endif
  @endforelse
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
