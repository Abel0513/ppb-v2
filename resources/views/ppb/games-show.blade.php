{{-- PPB B1 2026-11-04 | Game permanent identity and WP publication lock. --}}
@extends('ppb.layout')
@section('title','賽事歷史與進階管理')
@section('body')
@php $wpLocked=$game->hasVerifiedWp(); $ready=$game->wp_ready; $sortedAssignments=$game->assignments->sortBy(fn($a)=>sprintf('%s-%02d',$a->kind,$a->slot_no??0)); @endphp
<div class="admin-layout">@include('ppb.partials.sidebar')
<div class="admin-main">@include('ppb.partials.topbar')<main class="admin-content"><div class="admin-content-inner">
<div class="eyebrow">HISTORICAL RECORD</div><h1 class="heading">{{ $game->display_code }}｜{{ $game->matchup_text }}</h1>
<p class="sub">永久賽事 ID #{{ $game->id }} · {{ $game->recommended_at?->timezone('Asia/Taipei')->format('Y/m/d H:i') }} 推薦時段 · {{ $game->game_type===1?'G 日常賽事':'E 額外加場' }}</p>
@if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
@if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
<div class="card" style="margin-bottom:18px"><h2 class="card-title">編號與 WP 上傳狀態</h2>
<div style="display:flex;flex-wrap:wrap;gap:10px;margin:12px 0 14px">
@if($wpLocked)<span class="admin-badge admin-badge-danger">已有 WP 文章成功發布｜編號鎖定</span>@elseif($game->hasUnresolvedWp())<span class="admin-badge admin-badge-neutral">WP 狀態待查｜暫停編號異動</span>@else<span class="admin-badge admin-badge-info">未記錄 WP 成功發布｜編號可調整</span>@endif
@if($game->status==='cancelled')<span class="admin-badge admin-badge-neutral">已取消｜{{ $game->active_number?'保留編號':'釋出編號' }}</span>@elseif($ready)<span class="admin-badge admin-badge-success">人員已補齊</span>@else<span class="admin-badge admin-badge-neutral">待補人員，不符合 WP 上傳門檻</span>@endif
</div>
<p class="small-note">指派完整度：短文 {{ $game->assignments->where('kind','pick')->where('status','active')->count() }}/5；BONUS {{ $game->assignments->where('kind','bonus')->where('status','active')->count() }}/1；核稿 {{ $game->reviewer_analyst_id ? '已選' : '未選' }}；長文 {{ $game->has_long_article ? ($game->assignments->where('kind','long')->where('status','active')->count().'/1') : '未安排' }}。</p>
<p class="small-note">WP 系統尚未接入本階段的實際上傳功能。人員完整度是上傳前檢查，不能當成文章已成功發布。</p>
<p class="small-note">玩法：{{ [1=>'讓分',2=>'不讓分',3=>'大小分',4=>'特殊玩法',5=>'手動玩法'][$game->play_type] ?? '—' }} {{ $game->play_value }}／盤口：{{ [1=>'兩路盤',2=>'三路盤'][$game->market_type] ?? '—' }}。{{ $game->starts_at ? '實際開賽：'.$game->starts_at->timezone('Asia/Taipei')->format('Y-m-d H:i') : '尚未設定實際開賽時間' }}</p>
@if($game->status==='cancelled')<p class="small-note">取消原因：{{ $game->cancel_reason }}</p>@endif
</div>
<div class="card" style="margin-bottom:18px"><h2 class="card-title">分析師指派</h2><p class="small-note">5 位短文不得重複。BONUS 與長文可兼任；已有投稿、代班歷程時不能直接覆寫既有任務。</p>
<div class="table-scroll"><table class="data-table"><thead><tr><th>角色</th><th>原負責人</th><th>現任負責人</th><th>狀態</th><th>代班紀錄</th></tr></thead><tbody>
@forelse($sortedAssignments as $a)
<tr><td><strong>{{ $a->kind==='pick'?'短文 '.$a->slot_no:($a->kind==='bonus'?'BONUS':($a->kind==='long'?'長文':'影片')) }}</strong></td>
<td>{{ $a->originalAnalyst?->display_name ?: $a->originalAnalyst?->name }}</td>
<td>{{ $a->currentAnalyst?->display_name ?: $a->currentAnalyst?->name }}</td>
<td>{{ $a->status }}</td><td>{{ $a->substitutions->count() }} 次</td></tr>
@if($a->substitutions->isNotEmpty())<tr><td colspan="5"><div class="small-note">@foreach($a->substitutions as $s)<div>{{ $s->changed_at?->timezone('Asia/Taipei')->format('Y-m-d H:i') }} · {{ $s->fromAnalyst?->name }} → {{ $s->toAnalyst?->name }} · {{ $s->reason }}</div>@endforeach</div></td></tr>@endif
@empty<tr><td colspan="5" class="empty">尚未安排人員，可在「編輯賽事」補齊。</td></tr>@endforelse
</tbody></table></div>
<p class="small-note">核稿人員：{{ $game->reviewer_analyst_id ? (\Illuminate\Support\Facades\DB::table('ppb_analysts')->where('id',$game->reviewer_analyst_id)->value('name') ?: '人員不存在') : '尚未指派' }}</p>
</div>
@if(auth('ppb')->user()->hasPpbPermission('schedules.manage'))
<div class="card" style="margin-bottom:18px"><h2 class="card-title">賽事管理</h2>
<div class="toolbar" style="margin:12px 0 0">
@if($game->status!=='cancelled' && !$wpLocked && !$game->hasUnresolvedWp())<a class="action" href="{{ route('ppb.games.edit',$game) }}">編輯／補齊分析師</a>@endif
<a class="action" href="{{ route('ppb.manuscripts.game',$game) }}">稿件投稿／核稿</a>
<a class="link" href="{{ route('ppb.games.index',['month'=>$game->month_key,'type'=>$game->game_type]) }}">返回列表</a></div>
@if($game->status!=='cancelled')
<div style="margin-top:18px;border-top:1px solid #e3e7f1;padding-top:16px">
<form method="post" action="{{ route('ppb.games.cancel',$game) }}" class="toolbar" onsubmit="return confirm('確定取消這場賽事？投稿及歷史紀錄將保留。')">@csrf
<label class="field-label" style="flex:1;min-width:200px">取消原因（必填）<input class="field" name="reason" maxlength="255" required placeholder="請說明取消原因"></label>
<button class="action" type="submit">取消賽事</button></form>
@if(!$wpLocked && !$game->submissions()->exists() && !$game->publications()->exists() && !$game->assignments()->whereHas('substitutions')->exists())
<form method="post" action="{{ route('ppb.games.destroy',$game) }}" style="margin-top:12px" onsubmit="return confirm('永久刪除此場尚無投稿的賽事？操作不可復原，確認後會整理安全可動的 G/E 編號。')">@csrf @method('DELETE')
<button class="admin-button admin-button-danger" type="submit">刪除無投稿賽事</button>
</form>@endif
</div>@endif</div>@endif
{{-- PPB CT2 2026-11-05 | Historical manuscript evidence, never a publishing claim. --}}
@if($canSeeManuscripts)
<div class="card" style="margin-bottom:18px">
<h2 class="card-title">舊版稿件與 WordPress 對帳</h2>
<p class="small-note">以下為 2026/10/15 起舊資料庫原始欄位的唯讀保存。短文及 BONUS 分別顯示推薦選擇／分析內容；長文若經 WordPress 公開 API 驗證發布，才顯示對應全文。其他 WP 候選仍屬待查，不能當作未發布。</p>
@forelse($legacyPicks as $legacyPick)
<div style="border-top:1px solid #e3e7f1;padding:12px 0;overflow-wrap:anywhere">
<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:6px">
<strong>{{ $legacyPick->kind==='pick'?'短文':($legacyPick->kind==='bonus'?'BONUS':'長文') }}</strong>
<span class="small-note">來源投稿 #{{ $legacyPick->source_pick_id }} · 舊分析師 ID {{ $legacyPick->legacy_analyst_id }}</span>
@if(isset($archivedPickIds[(int)$legacyPick->source_pick_id]))
<span class="admin-badge admin-badge-neutral">歷史重複指派封存</span>
@endif
</div>
@if($legacyPick->kind==='long')
@php $verifiedArticle=$wpVerifiedByPick->get((int)$legacyPick->source_pick_id); @endphp
@if($verifiedArticle)
<p class="small-note"><span class="admin-badge admin-badge-success">已核實 WP 發布｜整場編號永久鎖定</span>　文章 #{{ $verifiedArticle->wp_post_id }} · 發布 {{ $verifiedArticle->published_gmt }} UTC · 最近修改 {{ $verifiedArticle->modified_gmt }} UTC</p>
<p><a class="link" href="{{ $verifiedArticle->wp_link }}" target="_blank" rel="noopener noreferrer">檢視 WordPress 原文章</a></p>
<details style="margin:10px 0"><summary style="cursor:pointer">展開已接入的長文全文（唯讀）</summary><div style="white-space:pre-wrap;overflow-wrap:anywhere;padding:12px 0">{{ $verifiedArticle->body_text }}</div></details>
@else
<p class="small-note">WP 候選文章 ID：{{ $legacyPick->wp_post_candidate ?: '未取得' }}（尚無已驗證的發布證據，維持待查）</p>
@endif
@if(trim((string)$legacyPick->recommend_pick)!=='')
<div class="small-note">舊版長文參考欄位：</div>
<div style="white-space:pre-wrap">{{ $legacyPick->recommend_pick }}</div>
@endif
@if(!$verifiedArticle)<p class="small-note">尚未取得經驗證的長文正文，禁止把候選文章 ID 當作全文。</p>@endif
@else
@if(trim((string)$legacyPick->recommend_pick)!=='')
<div class="small-note">推薦選擇</div><div style="white-space:pre-wrap">{{ $legacyPick->recommend_pick }}</div>
@endif
@if(trim((string)$legacyPick->short_analysis)!=='')
<div class="small-note">分析內容</div><div style="white-space:pre-wrap">{{ $legacyPick->short_analysis }}</div>
@else <div class="small-note">舊版尚無分析內容</div> @endif
@endif
</div>
@empty
<p class="small-note">本場沒有符合範圍的歷史稿件。</p>
@endforelse
</div>
@endif
<div class="card"><h2 class="card-title">異動紀錄</h2><div class="table-scroll"><table class="data-table"><thead><tr><th>時間</th><th>事件</th><th>資料</th></tr></thead><tbody>
@forelse($events as $e)<tr><td>{{ $e->created_at }}</td><td>{{ $e->event }}</td><td style="max-width:470px;overflow-wrap:anywhere"><small>{{ $e->detail_json }}</small></td></tr>
@empty<tr><td colspan="3" class="empty">尚無異動紀錄。</td></tr>@endforelse
</tbody></table></div></div>
</div></main></div></div>
@endsection
