{{-- PPB U2 2026-11-03 | structural-only conversion to CSDA actual admin shell. --}}
@extends('ppb.layout')
@section('title', '管理中心')
@section('body')
<div class="admin-layout">
    @include('ppb.partials.sidebar')
    <div class="admin-main">
        @include('ppb.partials.topbar')
        <main class="admin-content"><div class="admin-content-inner">
            <div class="eyebrow">ADMINISTRATION OVERVIEW</div>
            <h1 class="heading">歡迎回來，{{ $account->name }}</h1>
            <p class="sub">已通過身份驗證與權限檢查，這是新版管理中心的第一版。</p>
            <div class="banner">第三階段已建立分析師主檔、賽事排班及代班歷程；實際資料尚待建立或核對遷入。</div>
            <div class="card"><h2 class="card-title">目前帳號權限</h2>
                <div class="roles">
                    @foreach($roleNames as $roleName)
                        <div class="role">{{ $roleName }}<small>已授權角色</small></div>
                    @endforeach
                </div>
            </div>
        </div></main>
    </div>
</div>
@endsection
