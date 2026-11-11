{{-- PPB U2 2026-11-03 | Source-aligned with CSDA Index/index.tpl; all KPIs remain placeholders. --}}
@extends('ppb.layout')
@section('title', '管理面板')
@section('body')
<div class="admin-layout">@include('ppb.partials.sidebar')
<div class="admin-main">@include('ppb.partials.topbar')
<main class="admin-content"><div class="admin-content-inner">
<section class="dashboard-welcome"><div class="dashboard-welcome-content"><span class="dashboard-welcome-label">PPB MANAGEMENT SYSTEM</span><h2 class="dashboard-welcome-title">皮皮寶管理後台</h2><p class="dashboard-welcome-description">分析師、賽事排班、投稿、評鑑與薪酬作業的統一管理中心。此畫面為介面預覽，數字沒有使用正式資料。</p></div></section>
<section class="dashboard-stats-grid">
 <article class="dashboard-stat-card"><div class="dashboard-stat-header"><p class="dashboard-stat-label">分析師人數</p><div class="dashboard-stat-icon">人</div></div><p class="dashboard-stat-value">—</p><p class="dashboard-stat-note">正式資料尚未建立或遷移</p></article>
 <article class="dashboard-stat-card"><div class="dashboard-stat-header"><p class="dashboard-stat-label">本月賽事班表</p><div class="dashboard-stat-icon">▦</div></div><p class="dashboard-stat-value">—</p><p class="dashboard-stat-note">A3 資料表已建立</p></article>
 <article class="dashboard-stat-card"><div class="dashboard-stat-header"><p class="dashboard-stat-label">待審核投稿</p><div class="dashboard-stat-icon">文</div></div><p class="dashboard-stat-value">—</p><p class="dashboard-stat-note">投稿模組尚未建立</p></article>
 <article class="dashboard-stat-card"><div class="dashboard-stat-header"><p class="dashboard-stat-label">月評鑑進度</p><div class="dashboard-stat-icon">✓</div></div><p class="dashboard-stat-value">—</p><p class="dashboard-stat-note">評鑑模組尚未建立</p></article>
</section>
<section class="dashboard-panel-grid">
 <div class="dashboard-panel"><div class="dashboard-panel-header"><div><h3 class="dashboard-panel-title">快速入口</h3><p class="dashboard-panel-description">目前已建立的介面預覽</p></div></div><div class="dashboard-panel-body"><div class="dashboard-quick-actions">
  <a class="dashboard-quick-action" href="{{ route('ppb.preview.accounts') }}"><div class="dashboard-quick-action-icon">人</div><div><p class="dashboard-quick-action-title">帳號管理</p><p class="dashboard-quick-action-text">帳號清單與角色權限的預覽頁面</p></div></a>
  <a class="dashboard-quick-action" href="{{ route('ppb.preview.operations') }}"><div class="dashboard-quick-action-icon">▦</div><div><p class="dashboard-quick-action-title">排班管理</p><p class="dashboard-quick-action-text">分析師、賽事與代班作業的預覽頁面</p></div></a>
 </div></div></div>
 <div class="dashboard-panel"><div class="dashboard-panel-header"><div><h3 class="dashboard-panel-title">系統建置狀態</h3><p class="dashboard-panel-description">此畫面不讀取舊站資料</p></div></div><div class="dashboard-panel-body"><ul class="dashboard-account-list">
  <li class="dashboard-account-item"><span class="dashboard-account-label">框架</span><span class="dashboard-account-value">Laravel 13</span></li>
  <li class="dashboard-account-item"><span class="dashboard-account-label">帳號權限</span><span class="dashboard-account-value">A1、A2 已部署</span></li>
  <li class="dashboard-account-item"><span class="dashboard-account-label">分析師／班表</span><span class="dashboard-account-value">A3 已部署</span></li>
  <li class="dashboard-account-item"><span class="dashboard-account-label">正式登入</span><span class="dashboard-account-value">HTTP 環境停用</span></li>
 </ul></div></div>
</section>
</div></main></div></div>
@endsection
