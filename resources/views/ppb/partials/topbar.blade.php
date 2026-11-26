{{-- PPB U2 | SOURCE: CSDA Application/Admin/View/Public/topbar.tpl | auth kept on ppb guard. --}}
<header class="admin-topbar">
  <div class="admin-topbar-left">
    <button class="admin-mobile-menu-button" id="adminMobileMenuButton" type="button" aria-label="開啟後台選單">☰</button>
    {{-- PPB_UI3_20261104 | Keep the real page title, remove redundant subtitle. --}}
    <div class="admin-page-heading"><h1 class="admin-page-title">@yield('title', '排班管理')</h1></div>
  </div>
  @if(auth('ppb')->user()?->hasPpbPermission('schedules.view'))
    <a class="admin-association" href="{{ route('ppb.games.index') }}">
      中華民國運動彩券發展協會 <small>CHINESE SPORTS LOTTERY DEVELOPMENT ASSOCIATION</small>
    </a>
  @endif
  <div class="admin-topbar-right" id="adminProfileWrapper">
    @if(auth('ppb')->user()?->hasPpbPermission('schedules.view') && request()->query('modal') !== '1')
      @php
        $ppbLastUnsettled = \App\Services\Ppb\LegacyUnsettledCount::lastKnown();
      @endphp
      <a class="link" href="{{ route('ppb.games.index') }}">! 未結算場次 <span id="ppbUnsettledCount" data-url="{{ route('ppb.games.unsettled-count', [], false) }}">{{ $ppbLastUnsettled ?? '—' }}</span></a>
      <script>
        (() => {
          const badge = document.getElementById('ppbUnsettledCount');
          // Bounded attempts: the badge always ends as a number or 讀取失敗, never a stuck dash.
          const load = async attempt => {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 20000);
            try {
              const response = await fetch(badge.dataset.url, {headers: {'Accept': 'application/json'}, signal: controller.signal});
              const data = response.ok ? await response.json() : null;
              if (!Number.isInteger(data?.count)) throw new Error('unsettled count unavailable');
              badge.textContent = data.count;
            } catch {
              if (attempt < 2) return setTimeout(() => load(attempt + 1), 2000 * (attempt + 1));
              if (!/^[0-9]+$/.test(badge.textContent)) badge.textContent = '讀取失敗';
            } finally {
              clearTimeout(timer);
            }
          };
          load(0);
        })();
      </script>
    @endif
    @if(auth('ppb')->check())
      <button class="admin-profile-button" id="adminProfileButton" type="button" aria-haspopup="true" aria-expanded="false">
        <div class="admin-profile-info"><p class="admin-profile-name">{{ auth('ppb')->user()->name }}</p></div>
        <div class="admin-profile-avatar">P</div><span class="admin-profile-arrow">⌄</span>
      </button>
      <div class="admin-profile-dropdown" id="adminProfileDropdown">
        <div class="admin-profile-dropdown-header"><p class="admin-profile-dropdown-name">{{ auth('ppb')->user()->name }}</p><p class="admin-profile-dropdown-account">{{ auth('ppb')->user()->username }}</p></div>
        <div class="admin-profile-dropdown-menu"><form method="POST" action="{{ route('ppb.logout') }}">@csrf<button class="admin-profile-menu-item logout" type="submit"><span class="admin-profile-menu-icon">↪</span><span>退出</span></button></form></div>
      </div>
    @else
      <span class="admin-topbar-preview">介面預覽</span>
    @endif
  </div>
</header>
<div class="admin-topbar-spacer" aria-hidden="true"></div>
