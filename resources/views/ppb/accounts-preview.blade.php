{{-- PPB U2 2026-11-03 | structural-only conversion to CSDA actual admin shell. --}}
@extends('ppb.layout')
@section('title', '帳號權限介面預覽')
@section('body')
<div class="admin-layout">
    @include('ppb.partials.sidebar')
    <div class="admin-main">@include('ppb.partials.topbar')
        <main class="admin-content"><div class="admin-content-inner"><div class="eyebrow">ACCOUNT ADMINISTRATION</div><h1 class="heading">帳號與角色管理</h1>
            <p class="sub">可維護管理人員及分析師帳號，授予作業權限，並保留操作紀錄。</p>
            <div class="banner">A2 開發預覽｜下方為靜態版型，沒有使用任何真實人員資料；HTTP 不開放登入及儲存操作。</div>
            <section class="cards">
                <div class="card"><div class="kpi-label">管理員與人員帳號</div><div class="kpi-number">—</div><div class="kpi-note">正式登入後顯示</div></div>
                <div class="card"><div class="kpi-label">已啟用帳號</div><div class="kpi-number">—</div><div class="kpi-note">依實際狀態計算</div></div>
                <div class="card"><div class="kpi-label">系統角色</div><div class="kpi-number">3</div><div class="kpi-note">系統管理員／管理人員／分析師</div></div>
                <div class="card"><div class="kpi-label">基礎權限</div><div class="kpi-number">16</div><div class="kpi-note">可依角色授權</div></div>
            </section>
            <div class="blocks"><section class="card"><h2 class="card-title">帳號管理功能</h2>
                <div class="step"><div class="step-num">01</div><div><strong>新增人員</strong><p>姓名、登入帳號、密碼、啟用狀態與角色</p></div></div>
                <div class="step"><div class="step-num">02</div><div><strong>編輯與停用</strong><p>停用後立即失去授權；保留操作紀錄</p></div></div>
                <div class="step"><div class="step-num">03</div><div><strong>角色授權</strong><p>依功能指派權限，系統管理員不可隨意降權</p></div></div>
            </section>
            <section class="card"><h2 class="card-title">角色授權架構</h2><div class="roles">
                <div class="role">系統管理員<small>完整權限</small></div>
                <div class="role">管理人員<small>依授權使用</small></div>
                <div class="role">分析師<small>限定功能</small></div>
            </div><p class="footer-note">登入、實際人員清單與編輯表單僅在自有 HTTPS 網域開放。</p></section></div>
        </div></main>
    </div>
</div>
@endsection
