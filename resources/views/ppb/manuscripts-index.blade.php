{{-- PPB W2 2026-11-06 | Authenticated manuscript work list. --}}
@extends('ppb.layout')
@section('title','稿件管理')
@section('body')
<div class="admin-layout">@include('ppb.partials.sidebar')
<div class="admin-main">@include('ppb.partials.topbar')
<main class="admin-content"><div class="admin-content-inner">
<div class="eyebrow">MANUSCRIPTS</div><h1 class="heading">稿件投稿與核稿</h1>
<p class="sub">依既有賽事指派管理短文、BONUS、長文。歷史匯入稿件維持唯讀；此處不會向 WordPress 發布。</p>
@if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
@if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
<div class="card"><div class="table-scroll"><table class="data-table"><thead><tr><th>賽事</th><th>推薦時間（台灣）</th><th>對戰</th><th>稿件數</th><th>操作</th></tr></thead><tbody>
@forelse($games as $game)
<tr><td><strong>{{ $game->display_code }}</strong></td><td>{{ $game->recommended_at?->timezone('Asia/Taipei')->format('Y/m/d H:i') ?: '—' }}</td>
<td>{{ $game->matchup_text ?: $game->away_team.' @ '.$game->home_team }}</td><td>{{ $game->submissions_count }}</td>
<td><a class="link" href="{{ route('ppb.manuscripts.game',$game) }}">開啟稿件</a></td></tr>
@empty<tr><td colspan="5" class="empty">目前沒有可存取的稿件賽事。</td></tr>@endforelse
</tbody></table></div>
<div style="margin-top:12px">{{ $games->links() }}</div>
</div>
@if($admin)<p class="small-note"><a class="link" href="{{ route('ppb.games.index') }}">返回排班管理</a></p>@endif
</div></main></div></div>
@endsection
