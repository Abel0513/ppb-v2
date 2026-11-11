{{-- PPB U2 2026-11-03 | structural-only conversion to CSDA actual admin shell. --}}
{{-- PPB A3 | 2026-11-03 | Strictly static HTTP-safe UI preview --}}
@extends('ppb.layout')
@section('title','分析師與班表預覽')
@section('body')
<div class="admin-layout">@include('ppb.partials.sidebar')
<div class="admin-main">@include('ppb.partials.topbar')<main class="admin-content"><div class="admin-content-inner">
<div class="eyebrow">ANALYST & SCHEDULING</div><h1 class="heading">分析師與賽事班表</h1><p class="sub">分析師身分、賽事資訊與工作排班分開管理；代班保留歷程。</p>
<div class="banner">A3 靜態展示｜尚未匯入真實分析師或班表，不會透過 HTTP 提交任何資料。登入操作僅限日後自有 HTTPS 網域。</div>
<section class="cards"><div class="card"><div class="kpi-label">分析師主檔</div><div class="kpi-number">—</div><div class="kpi-note">等待正式建立／遷移</div></div>
<div class="card"><div class="kpi-label">賽事班表</div><div class="kpi-number">—</div><div class="kpi-note">依實際資料顯示</div></div>
<div class="card"><div class="kpi-label">影片排班</div><div class="kpi-number">—</div><div class="kpi-note">任務類別獨立保存</div></div>
<div class="card"><div class="kpi-label">代班紀錄</div><div class="kpi-number">—</div><div class="kpi-note">原負責人不被覆蓋</div></div></section>
<section class="blocks"><div class="card"><h2 class="card-title">一筆班表的資料結構</h2>
<div class="step"><div class="step-num">01</div><div><strong>建立賽事</strong><p>運動種類、聯盟、主客隊、開賽時間（台灣時間）</p></div></div>
<div class="step"><div class="step-num">02</div><div><strong>指派工作</strong><p>一般投稿／長篇分析／影片班次，皆綁定分析師 ID</p></div></div>
<div class="step"><div class="step-num">03</div><div><strong>代班保留前後人員</strong><p>原負責人維持不變；每次轉派記錄原因、時間、操作人</p></div></div></div>
<div class="card"><h2 class="card-title">權限與資料安全</h2>
<p class="small-note">只有經授予「查看班表」或「管理班表」權限的帳號，才能在 HTTPS 管理區查看／修改實際資料。</p>
<div class="roles"><div class="role">分析師主檔<small>獨立身分主鍵</small></div><div class="role">賽事<small>獨立賽事編號</small></div><div class="role">工作排班<small>任務種類獨立</small></div><div class="role">代班歷程<small>不可覆蓋</small></div></div>
<p class="footer-note">不觸發舊站、LINE、WordPress 或薪酬自動作業。</p></div></section>
</div></main></div></div>
@endsection
