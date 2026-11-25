@extends('ppb.legacy-admin.layout')
@section('title', '出缺勤紀錄表')

@section('legacy-content')
<h2>{{ $month }} 出缺勤紀錄</h2>
@if($unavailable)<p class="notice">舊系統出缺勤暫時無法讀取</p>@endif
@if(!$ready)<p class="notice">唯讀資料暫時無法取得。</p>@endif
<div class="card table-scroll">
  <table class="data-table">
    <thead><tr><th></th><th>賽事</th><th>分析師</th><th>對戰組合</th><th>狀態</th></tr></thead>
    <tbody>
    @forelse($attendance as $row)
      @php
        $game = $row['game'];
        $absent = $row['attendance_status'] === 2;
      @endphp
      <tr>
        <td>@if($row['correctionUrl'])<a class="link" href="{{ $row['correctionUrl'] }}" aria-label="V2 出勤登錄與更正">🗑️</a>@endif</td>
        <td>@include('ppb.legacy-admin.game-pill', ['code' => $row['code']])</td>
        <td>@include('ppb.legacy-admin.name-pill', ['name' => $row['name'], 'colors' => $row['colors']])</td>
        <td>@if($game)@include('ppb.legacy-admin.matchup')@else—@endif</td>
        <td><span class="{{ $absent ? 'legacy-absent' : 'legacy-late' }}">{{ $absent ? '曠職' : '遲到' }}</span></td>
      </tr>
    @empty
      @if($ready)<tr><td colspan="5">無資料</td></tr>@endif
    @endforelse
    </tbody>
  </table>
</div>
@include('ppb.legacy-admin.pager', ['games' => $pager, 'unit' => '個月'])
@endsection
