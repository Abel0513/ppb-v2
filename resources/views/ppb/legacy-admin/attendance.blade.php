@extends('ppb.legacy-admin.layout')
@section('title', '出缺勤')

@section('legacy-content')
@if($unavailable)<p class="notice">舊系統出缺勤暫時無法讀取</p>@endif
@if(!$ready)<p class="notice">唯讀資料暫時無法取得。</p>@endif
<section class="card">
  <h2 class="card-title">{{ $month }} 出缺勤紀錄</h2>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>賽事</th><th>分析師</th><th>對戰組合</th><th>狀態</th><th>操作</th></tr></thead>
      <tbody>
      @forelse($attendance as $row)
        @php
          $game = $row['game'];
          $absent = $row['attendance_status'] === 2;
        @endphp
        <tr>
          <td><strong>{{ $row['code'] }}</strong></td>
          <td>{{ $row['name'] }}</td>
          <td>@if($game)@include('ppb.legacy-admin.matchup')@else—@endif</td>
          <td><span class="status {{ $absent ? 'disabled' : 'status-neutral' }}">{{ $absent ? '曠職' : '遲到' }}</span></td>
          <td>@if($row['correctionUrl'])<a class="link" href="{{ $row['correctionUrl'] }}" aria-label="V2 出勤登錄與更正">更正</a>@endif</td>
        </tr>
      @empty
        @if($ready)<tr><td colspan="5" class="empty">無資料</td></tr>@endif
      @endforelse
      </tbody>
    </table>
  </div>
  @include('ppb.legacy-admin.pager', ['paginator' => $pager, 'unit' => '個月'])
</section>
@endsection
