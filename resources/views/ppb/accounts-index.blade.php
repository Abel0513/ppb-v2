{{-- PPB U2 2026-11-03 | structural-only conversion to CSDA actual admin shell. --}}
@extends('ppb.layout')
@section('title', '帳號管理')
@section('body')
<div class="admin-layout">
    @include('ppb.partials.sidebar')
    <div class="admin-main">
        @include('ppb.partials.topbar')
        <main class="admin-content"><div class="admin-content-inner">
            <div class="eyebrow">ACCOUNT ADMINISTRATION</div>
            <h1 class="heading">帳號管理</h1>
            <p class="sub">建立、停用帳號及指派角色。系統管理員帳號使用獨立安全程序管理。</p>
            @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
            <div class="card">
                <div class="toolbar">
                    <form method="get" action="{{ route('ppb.accounts.index') }}" class="search-row">
                        <label for="search" class="sr-only">搜尋姓名或帳號</label>
                        <input id="search" class="field" name="q" maxlength="80" value="{{ $keyword }}" placeholder="搜尋姓名或帳號">
                        <button class="action" type="submit">搜尋</button>
                    </form>
                    <a class="action" href="{{ route('ppb.accounts.create') }}">＋ 新增帳號</a>
                </div>
                <div class="table-scroll"><table class="data-table">
                    <thead><tr><th>姓名 / 登入帳號</th><th>角色</th><th>狀態</th><th>最近登入</th><th>操作</th></tr></thead>
                    <tbody>
                    @forelse($accounts as $account)
                    <tr>
                        <td><strong>{{ $account->name }}</strong><small>{{ $account->username }}</small></td>
                        <td>{{ $account->roles->pluck('name')->join('、') ?: '未指派' }}</td>
                        <td><span class="status {{ $account->is_active ? 'enabled' : 'disabled' }}">{{ $account->is_active ? '啟用' : '停用' }}</span></td>
                        <td>{{ optional($account->last_login_at)->format('Y-m-d H:i') ?: '—' }}</td>
                        <td>
                            @if($account->roles->contains('code', 'owner'))
                                <span class="small-note">管理員保護</span>
                            @else
                                <a class="link" href="{{ route('ppb.accounts.edit', $account) }}">編輯</a>
                            @endif
                        </td>
                    </tr>
                    @empty<tr><td colspan="5" class="empty">尚無符合條件的帳號。</td></tr>@endforelse
                    </tbody>
                </table></div>
                <div class="pager">{{ $accounts->links() }}</div>
            </div>
        </div></main>
    </div>
</div>
@endsection
