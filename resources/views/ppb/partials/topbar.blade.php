{{-- PPB U2 | SOURCE: CSDA Application/Admin/View/Public/topbar.tpl | auth kept on ppb guard. --}}
<header class="admin-topbar">
  <div class="admin-topbar-left">
    <button class="admin-mobile-menu-button" id="adminMobileMenuButton" type="button" aria-label="開啟後台選單">☰</button>
    {{-- PPB_UI3_20261104 | Keep the real page title, remove redundant subtitle. --}}
    <div class="admin-page-heading"><h1 class="admin-page-title">@yield('title', '排班管理')</h1></div>
  </div>
  <div class="admin-topbar-right">
    @if(auth('ppb')->user()?->hasPpbPermission('schedules.view') && request()->query('modal') !== '1')
      @php
        $ppbLastUnsettled = \App\Services\Ppb\LegacyUnsettledCount::lastKnown();
      @endphp
      <div class="admin-unsettled" id="ppbUnsettled">
        <button class="admin-unsettled-button" id="ppbUnsettledButton" type="button" aria-haspopup="dialog" aria-expanded="false" aria-controls="ppbUnsettledDropdown">
          <span>! 未結算場次</span>
          <span class="admin-unsettled-count" id="ppbUnsettledCount" data-url="{{ route('ppb.games.unsettled-count', [], false) }}">{{ $ppbLastUnsettled ?? '—' }}</span>
        </button>
        <div class="admin-unsettled-dropdown" id="ppbUnsettledDropdown" role="dialog" aria-labelledby="ppbUnsettledTitle" tabindex="-1" hidden>
          <div class="admin-unsettled-header"><strong id="ppbUnsettledTitle">未結算場次</strong><span>待處理</span></div>
          <p class="admin-unsettled-status" id="ppbUnsettledStatus" role="status">載入中…</p>
          <ul class="admin-unsettled-games" id="ppbUnsettledGames"></ul>
        </div>
      </div>
      <script src="{{ asset('assets/ppb/ppb-unsettled.js') }}?v=20261126" defer></script>
    @endif
    @if(auth('ppb')->check())
      <div id="adminProfileWrapper">
        <button class="admin-profile-button" id="adminProfileButton" type="button" aria-haspopup="true" aria-expanded="false">
          <div class="admin-profile-info"><p class="admin-profile-name">{{ auth('ppb')->user()->name }}</p></div>
          <div class="admin-profile-avatar">P</div><span class="admin-profile-arrow">⌄</span>
        </button>
        <div class="admin-profile-dropdown" id="adminProfileDropdown">
          <div class="admin-profile-dropdown-header"><p class="admin-profile-dropdown-name">{{ auth('ppb')->user()->name }}</p><p class="admin-profile-dropdown-account">{{ auth('ppb')->user()->username }}</p></div>
          <div class="admin-profile-dropdown-menu"><form method="POST" action="{{ route('ppb.logout') }}">@csrf<button class="admin-profile-menu-item logout" type="submit"><span class="admin-profile-menu-icon">↪</span><span>安全登出</span></button></form></div>
        </div>
      </div>
    @else
      <span class="admin-topbar-preview">介面預覽</span>
    @endif
  </div>
</header>
<div class="admin-topbar-spacer" aria-hidden="true"></div>
