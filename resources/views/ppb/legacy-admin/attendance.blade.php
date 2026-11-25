@extends('ppb.legacy-admin.layout')
@section('title', '出缺勤紀錄表')

@section('legacy-content')
<h2>{{ $month }} 出缺勤紀錄</h2>
@if(!$ready || $unavailable)<p class="notice">唯讀資料暫時無法取得。</p>@endif
<div class="card table-scroll">
  <table class="data-table">
    <thead><tr><th>賽事</th><th>分析師</th><th>對戰組合</th><th>狀態</th></tr></thead>
    <tbody>
    @forelse($attendance as $game)
      @php($analyst = $roster->get($game->subject_type.':'.$game->subject_id))
      <tr>
        <td>@include('ppb.legacy-admin.game-pill', ['code' => ((int) $game->game_type === 1 ? 'G' : 'E').$game->game_number])</td>
        <td>@include('ppb.legacy-admin.name-pill', [
            'name' => $analyst['label'] ?? '—',
            'colors' => $game->subject_type === 'legacy' ? config('ppb_legacy_colors.'.$game->subject_id) : null,
        ])</td>
        <td>@include('ppb.legacy-admin.matchup')</td>
        <td><span class="{{ (int) $game->attendance_status === 2 ? 'legacy-absent' : 'legacy-late' }}">{{ (int) $game->attendance_status === 2 ? '曠職' : '遲到' }}</span></td>
      </tr>
    @empty
      @if($ready)<tr><td colspan="4">無資料</td></tr>@endif
    @endforelse
    </tbody>
  </table>
</div>
@include('ppb.legacy-admin.pager', ['games' => $pager, 'unit' => '個月'])
@endsection
