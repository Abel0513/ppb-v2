{{-- PPB U2 | SOURCE: CSDA Application/Admin/View/Public/topbar.tpl | auth kept on ppb guard. --}}
<header class="admin-topbar">
  <div class="admin-topbar-left">
    <button class="admin-mobile-menu-button" id="adminMobileMenuButton" type="button" aria-label="開啟後台選單">☰</button>
    {{-- PPB_UI3_20261104 | Keep the real page title, remove redundant subtitle. --}}
    @php
      // Section label for the heading breadcrumb, matching the sidebar groups.
      $ppbSection = match (true) {
          request()->routeIs('ppb.performance', 'ppb.performance.*') => ['報表', 'chart'],
          request()->routeIs('ppb.evaluations.*') => ['月評鑑與薪酬', 'doc'],
          request()->routeIs('ppb.rating.*') => ['薪資與評級', 'doc'],
          request()->routeIs('ppb.evalpreview.*') => ['月評鑑預覽', 'doc'],
          request()->routeIs('ppb.csda.*') => ['CSDA 用戶', 'user'],
          request()->routeIs('ppb.accounts.*', 'ppb.roles.*') => ['系統設定', 'gear'],
          default => ['作業管理', 'calendar'],
      };
      $ppbIcons = [
          'calendar' => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M4 10h16M9 3v5M15 3v5"/>',
          'chart' => '<path d="M4 4v16h16M8 16v-4m5 4V8m5 8V5"/>',
          'doc' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 7h6m-6 5h6m-6 5h4"/>',
          'user' => '<circle cx="12" cy="8" r="3"/><path d="M5 20v-2a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v2"/>',
          'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
      ];
    @endphp
    <div class="admin-page-heading">
      <span class="admin-page-icon" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $ppbIcons[$ppbSection[1]] !!}</svg></span>
      <div class="admin-page-heading-text">
        <p class="admin-page-breadcrumb"><span>管理後台</span><span>{{ $ppbSection[0] }}</span></p>
        <h1 class="admin-page-title">@yield('title', '排班管理')</h1>
      </div>
    </div>
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
