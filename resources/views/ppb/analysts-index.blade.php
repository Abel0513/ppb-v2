{{-- PPB U2 2026-11-03 | structural-only conversion to CSDA actual admin shell. --}}
{{-- PPB AN2 | 2026-11-04 | Legacy analyst type labels from column COMMENT; status distinct from role. --}}
@extends('ppb.layout')
@section('title','分析師資料')
@section('body')
<div class="admin-layout">@include('ppb.partials.sidebar')
<div class="admin-main">@include('ppb.partials.topbar')<main class="admin-content"><div class="admin-content-inner">
<div class="eyebrow">ANALYST DIRECTORY</div><h1 class="heading">分析師資料</h1><p class="sub">分析師身分與登入帳號分開管理；不使用舊系統混用的識別欄位。</p>
@if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
<div class="card"><div class="toolbar"><form method="get" class="search-row"><input name="q" class="field" value="{{ $keyword }}" maxlength="80" placeholder="搜尋姓名／顯示名稱"><button class="action" type="submit">搜尋</button></form>
@if(auth('ppb')->user()->hasPpbPermission('schedules.manage'))<a class="action" href="{{ route('ppb.analysts.create') }}">＋ 新增分析師</a>@endif</div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>ID</th><th>姓名</th><th>對外顯示</th><th>後台帳號（選用）</th><th>身份</th><th>狀態</th><th></th></tr></thead><tbody>
@forelse($analysts as $analyst)
<tr><td>#{{ $analyst->id }}</td><td><strong>{{ $analyst->name }}</strong></td><td>{{ $analyst->display_name ?: '—' }}</td><td>{{ $analyst->account?->username ?: '未綁定' }}</td><td>{{ [0=>'分析師',1=>'核稿＋分析師',2=>'核稿',3=>'主持人'][(int)$analyst->user_type] ?? '未定義' }}</td><td><span class="status {{ $analyst->status === 'active' ? 'enabled' : 'disabled' }}">{{ $analyst->status === 'active' ? '啟用' : '停用' }}</span></td><td>@if(auth('ppb')->user()->hasPpbPermission('schedules.manage'))<a class="link" href="{{ route('ppb.analysts.edit',$analyst) }}">編輯</a>@endif</td></tr>
@empty<tr><td colspan="7" class="empty">目前尚無分析師資料。等待正式建立或經核對後遷入。</td></tr>@endforelse
</tbody></table></div><div class="pager">{{ $analysts->links() }}</div></div>
</div></main></div></div>
@endsection
