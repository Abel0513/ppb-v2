{{-- PPB U2 | SOURCE: CSDA Application/Admin/View/Public/sidebar.tpl | menus changed only for PPB roles/routes. --}}
@php
    // PPB_UI3_20261104 | Actual authenticated V2 navigation (no public preview paths).
    $ppbUser = auth('ppb')->user();
    $ppbCanSchedules = $ppbUser && $ppbUser->hasPpbPermission('schedules.view');
    // Only a real active owner role may see System Settings; permissions alone are insufficient.
    $ppbIsOwner = $ppbUser && $ppbUser->is_active
        && $ppbUser->roles()->where('ppb_roles.code', 'owner')->exists();
    $ppbHome = route('ppb.games.index');
    $groups = [];
    if ($ppbCanSchedules) {
        $groups['schedules'] = ['排班管理', '▦', [
            ['ppb.schedule.overview', '賽事排班總覽'],
            ['ppb.schedule.articles', '分析文排班總覽'],
            ['ppb.schedule.videos', '影音排班'],
            ['ppb.schedule.attendance', '出缺勤紀錄表'],
            ['ppb.analyst-list', '分析師列表'],
            ['ppb.performance', '戰績排行榜'],
        ]];
        $groups['analyst-pages'] = ['分析師頁面', '◆', [
            ['ppb.games.readonly', '分析師輪值表'],
            ['ppb.performance', '戰績排行榜'],
        ]];
    }
    $rating = [];
    if ($ppbUser?->hasPpbPermission('evaluations.view')) {
        $rating = [
            ['ppb.rating.index', '薪資/評級異動'],
            ['ppb.rating.salary', '分析師薪資'],
            ['ppb.rating.change', '分析師評級異動'],
        ];
    }
    if ($ppbIsOwner && $ppbUser->hasPpbPermission('accounts.manage')) {
        $rating[] = ['ppb.evalpreview.index', '月評鑑預覽（唯讀）', 'ppb.evalpreview.*'];
    }
    if ($rating) $groups['rating'] = ['薪資評級', '＄', $rating];
    if ($ppbCanSchedules) {
        $groups['csda'] = ['運彩小虎爺', '♕', [
            ['ppb.csda.users', '用戶管理'],
            ['ppb.csda.users', '定時廣播'],
        ]];
    }
    if ($ppbIsOwner || $ppbCanSchedules) {
        $settings = $ppbCanSchedules ? [['ppb.analysts.index', '分析師資料', 'ppb.analysts.*']] : [];
        if ($ppbIsOwner) {
            $settings[] = ['ppb.accounts.index', '帳號管理', 'ppb.accounts.*'];
            $settings[] = ['ppb.roles.index', '角色與權限', 'ppb.roles.*'];
        }
        $groups['system'] = ['系統設定', '⚙', $settings];
    }
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
    @if($ppbCanSchedules)
      <a class="admin-menu-item {{ request()->routeIs('ppb.games.index', 'ppb.dashboard') ? 'active' : '' }}" href="{{ $ppbHome }}">
        <span class="admin-menu-icon" aria-hidden="true">⌂</span><span>首页</span>
      </a>
    @endif
    @foreach($groups as $key => [$title, $icon, $items])
      <div class="admin-menu-group" data-admin-menu-group="{{ $key }}">
        <button class="admin-menu-group-title" type="button" data-admin-menu-group-toggle="1" aria-expanded="true">
          <span class="admin-menu-icon" aria-hidden="true">{{ $icon }}</span><span>{{ $title }}</span><span class="admin-menu-group-arrow">›</span>
        </button>
        <div class="admin-submenu">
          @foreach($items as $item)
            <a class="admin-submenu-item {{ request()->routeIs($item[2] ?? $item[0]) ? 'active' : '' }}" href="{{ route($item[0]) }}">{{ $item[1] }}</a>
          @endforeach
        </div>
      </div>
    @endforeach
  </nav>
</aside>
<button class="admin-sidebar-reopen-button" id="adminSidebarReopenButton" type="button" aria-label="顯示側欄">☰</button>
<div class="admin-sidebar-overlay" id="adminSidebarOverlay"></div>
