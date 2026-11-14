{{-- PPB U2 | SOURCE: CSDA Application/Admin/View/Public/sidebar.tpl | menus changed only for PPB roles/routes. --}}
@php
    // PPB_UI3_20261104 | Actual authenticated V2 navigation (no public preview paths).
    $ppbUser = auth('ppb')->user();
    $ppbCanSchedules = $ppbUser && $ppbUser->hasPpbPermission('schedules.view');
    // Only a real active owner role may see System Settings; permissions alone are insufficient.
    $ppbIsOwner = $ppbUser && $ppbUser->is_active
        && $ppbUser->roles()->where('ppb_roles.code', 'owner')->exists();
    $ppbHome = route('ppb.games.index');
@endphp
<aside class="admin-sidebar" id="adminSidebar" aria-label="後台主選單">
  <div class="admin-sidebar-brand">
    <a class="admin-sidebar-brand-link" href="{{ $ppbHome }}">
      <img class="admin-sidebar-brand-logo" src="{{ asset('assets/ppb/robot.webp') }}" alt="皮皮寶機器人" width="44" height="44">
      <div class="admin-sidebar-brand-text">
        <div class="admin-sidebar-brand-name-row"><p class="admin-sidebar-brand-title">皮皮寶管理後台</p></div>
      </div>
    </a>
    <button class="admin-sidebar-collapse-button" id="adminSidebarCollapseButton" type="button" aria-label="隱藏側欄">‹</button>
  </div>
  <nav class="admin-sidebar-menu" aria-label="主選單">
    <p class="admin-sidebar-label">MAIN</p>
    @if($ppbCanSchedules)
    <div class="admin-menu-group" data-admin-menu-group="operations">
      <button class="admin-menu-group-title" type="button" data-admin-menu-group-toggle="1" aria-expanded="true">
        <span class="admin-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M4 10h16M9 3v5M15 3v5M8 15h3M14 15h3"/></svg></span><span>作業管理</span><span class="admin-menu-group-arrow">›</span>
      </button>
      <div class="admin-submenu">
        <a class="admin-submenu-item {{ request()->routeIs('ppb.games.*') || request()->routeIs('ppb.dashboard') ? 'active' : '' }}" href="{{ route('ppb.games.index') }}">排班管理</a>
        <a class="admin-submenu-item {{ request()->routeIs('ppb.analysts.*') ? 'active' : '' }}" href="{{ route('ppb.analysts.index') }}">分析師資料</a>
      </div>
    </div>
    @endif
    <a class="admin-menu-item" aria-disabled="true"><span class="admin-menu-icon" aria-hidden="true">✧</span><span>投稿與出勤（未建置）</span></a>
    {{-- PPB EVALPREVIEW | Owner-only GET preview, not the unpublished assessment writer. --}}
    @if($ppbIsOwner && $ppbUser->hasPpbPermission('accounts.manage'))
    <a class="admin-menu-item {{ request()->routeIs('ppb.evalpreview.*') ? 'active' : '' }}" href="{{ route('ppb.evalpreview.index') }}">
      <span class="admin-menu-icon" aria-hidden="true">▤</span><span>月評鑑預覽（唯讀）</span>
    </a>
    @endif
    <a class="admin-menu-item" aria-disabled="true"><span class="admin-menu-icon" aria-hidden="true">▤</span><span>月評鑑與薪酬（未建置）</span></a>
    {{-- PPB_UI3_20261104 | Owner-only menu; AccountController/RoleController also enforce owner server-side. --}}
    @if($ppbIsOwner)
    <div class="admin-menu-group" data-admin-menu-group="system">
      <button class="admin-menu-group-title" type="button" data-admin-menu-group-toggle="1" aria-expanded="true">
        <span class="admin-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></svg></span><span>系統設定</span><span class="admin-menu-group-arrow">›</span>
      </button>
      <div class="admin-submenu">
        <a class="admin-submenu-item {{ request()->routeIs('ppb.accounts.*') ? 'active' : '' }}" href="{{ route('ppb.accounts.index') }}">帳號管理</a>
        <a class="admin-submenu-item {{ request()->routeIs('ppb.roles.*') ? 'active' : '' }}" href="{{ route('ppb.roles.index') }}">角色與權限</a>
      </div>
    </div>
    @endif
  </nav>
</aside>
<button class="admin-sidebar-reopen-button" id="adminSidebarReopenButton" type="button" aria-label="顯示側欄">☰</button>
<div class="admin-sidebar-overlay" id="adminSidebarOverlay"></div>
