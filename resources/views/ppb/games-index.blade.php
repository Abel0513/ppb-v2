{{-- PPB UI6 | 2026-11-04 | Zero-flow insert entry, existing ScheduleManager validation retained. --}}
@extends('ppb.layout')
@section('title','分析師輪值表')
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-schedule-dialogs.css') }}">
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-shift-ui4.css') }}?v=20261105-op1">
@endpush
@section('body')
@php
    $weekdays = ['日','一','二','三','四','五','六'];
    $canManage = !$readOnly && auth('ppb')->user()->hasPpbPermission('schedules.manage');
    $plays = [1=>'推 讓分',2=>'推 不讓分',3=>'推 大小分',4=>'特殊玩法',5=>'手動玩法'];
    $legacyColors = config('ppb_legacy_colors', []);
    $archivedPickByGameSlot = $pickDisplay['archived'] ?? [];
    $archivedAnalystIds = collect($archivedPickByGameSlot)->flatMap(fn ($slots) => array_values($slots))->pluck('analyst_id')->unique()->all();
    $pickColor = static fn ($pick) => match ((int) ($pick['is_win'] ?? 0)) {
        1 => '#22c55e', 2 => '#ef4444', default => '#2f66c9',
    };
    $archivedAnalysts = $archivedAnalystIds
        ? \App\Models\PpbAnalyst::query()->whereIn('id',array_values($archivedAnalystIds))
            ->get(['id','name','display_name'])->keyBy('id')
        : collect();
@endphp
<div class="admin-layout">@include('ppb.partials.sidebar')
  <div class="admin-main">@include('ppb.partials.topbar')
    <main class="admin-content ppb-shift-page"><div class="admin-content-inner ppb-shift-wrap">
      @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
      @if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
      {{-- PPB UI5 2026-11-04 | Old shift.tpl search fields / buttons, no extra filters. --}}
      <section class="ppb-shift-search-card" aria-label="搜尋賽事">
        <form method="get" action="{{ url()->current() }}" class="ppb-shift-search-form">
          <label class="ppb-shift-filter"><span>賽事編號</span><input type="text" name="match_no" value="{{ $matchNo }}" placeholder="例如：G31" maxlength="12" autocomplete="off"></label>
          <label class="ppb-shift-filter"><span>日期</span><input type="text" name="match_date" value="{{ $matchDate }}" placeholder="賽事推薦時間" aria-label="賽事推薦時間" onfocus="this.type='date'" onblur="if(!this.value)this.type='text'"></label>
          <button class="ppb-shift-search-btn" type="submit">搜尋</button>
          @if($canManage)
            <button type="button" class="ppb-shift-add-btn ppb-open-game-modal" aria-haspopup="dialog">新增賽事</button>
          @endif
        </form>
      </section>
      @if($canManage)
        {{-- Legacy mobile search was hidden. Keep add accessible on narrow screens. --}}
        <div class="ppb-shift-mobile-add"><button type="button" class="ppb-shift-add-btn ppb-open-game-modal" aria-haspopup="dialog">新增賽事</button></div>
      @endif
      @forelse($games as $game)
        @php
            $recommendAt = $game->recommended_at?->timezone('Asia/Taipei');
            $activeAssignments = $game->assignments->where('status','active');
            $short = $activeAssignments->where('kind','pick')->keyBy('slot_no');
            $bonus = $activeAssignments->firstWhere('kind','bonus');
            $long = $activeAssignments->firstWhere('kind','long');
            $reviewer = $reviewers->get($game->reviewer_analyst_id);
            $reviewerStyle = $reviewer?->scheduleColors($legacyColors[(int)$reviewer->id] ?? null);
            $isCancelled = $game->status === 'cancelled';
            $locked = !$readOnly && ($game->hasVerifiedWp() || $game->hasUnresolvedWp());
            // PPB INS2 | Every visible card boundary has a tiny entry. Controller
            // checks the FULL month/type number scope, including off-page games.
            // No JS/UI flag can bypass ScheduleManager's final transaction checks.
            $insertGap = $insertGaps[(int)$game->id] ?? null;
            $canShowInsert = $insertGap !== null;
            $canInsertSafely = $canShowInsert && ($insertGap['safe'] ?? false);
            $candidateDate = $recommendAt && $recommendAt->isFuture()
                ? $recommendAt->format('Y-m-d') : now('Asia/Taipei')->format('Y-m-d');
            $insertCreateUrl = $canInsertSafely ? route('ppb.games.create', [
                'modal' => '1', 'position' => $insertGap['position'],
                'anchor_id' => $insertGap['anchor_id'], 'game_type' => $game->game_type,
                'insert_month' => $game->month_key, 'match_date' => $candidateDate,
            ]) : null;
        @endphp
        <article id="ppb-game-{{ $game->id }}" class="ppb-shift-card" aria-label="賽事 {{ $game->display_code }}">
          @if($canShowInsert)
            @if($canInsertSafely)
              <button type="button" class="ppb-shift-insert ppb-open-game-modal"
                data-create-url="{{ $insertCreateUrl }}"
                aria-label="在 {{ $game->display_code }} 前方的卡片間插入賽事" title="在此插入賽事">＋ 在此插入</button>
            @else
              <button type="button" class="ppb-shift-insert ppb-shift-insert-hold ppb-insert-hold-open"
                data-reason="{{ $insertGap['reason'] }}"
                aria-label="查看 {{ $game->display_code }} 前方為何無法插入賽事" title="查看此處插入限制">＋ 在此插入</button>
            @endif
          @endif
          <div class="ppb-shift-header">
            <div class="ppb-shift-header-first">
              <span class="ppb-shift-tag {{ (int)$game->game_type === 1 ? 'ppb-shift-tag-g' : 'ppb-shift-tag-e' }}">{{ $game->display_code }}</span>
              @if($recommendAt)
                <span class="ppb-shift-time {{ $recommendAt->format('H') === '11' ? 'ppb-shift-time-11' : 'ppb-shift-time-16' }}">{{ $recommendAt->format('m/d') }}({{ $weekdays[$recommendAt->dayOfWeek] }}) {{ $recommendAt->format('H') }}點</span>
              @endif
              <span class="ppb-shift-number" title="台彩賽事編號">{{ $game->lotteryNumber() ?? '—' }}</span>
            </div>
            <strong class="ppb-shift-matchup">{{ $game->matchup_text ?: trim(($game->league ?: '').' '.($game->away_team ?: '').' @ '.($game->home_team ?: '')) }}</strong>
            <div class="ppb-shift-legends" aria-label="勝敗說明">
              <span class="ppb-shift-legend ppb-shift-win">勝</span>
              <span class="ppb-shift-legend ppb-shift-lose">敗</span>
              <span class="ppb-shift-legend ppb-shift-pending">未結算</span>
            </div>
            <div class="ppb-shift-reviewer">核稿：<span @if($reviewerStyle) style="@if($reviewerStyle['background']!==null)background-color:{{ $reviewerStyle['background'] }};@endif color:{{ $reviewerStyle['color'] }}" @endif>{{ $reviewer ? ($reviewer->display_name ?: $reviewer->name) : '未指派' }}</span></div>
            {{-- PPB OP1 2026-11-05 | Compact actions, preserving original routes and permissions. --}}
            @unless($readOnly)
            <div class="ppb-shift-actions">
              @if($canManage && !$isCancelled)
                <a class="ppb-shift-action ppb-open-game-modal" href="{{ route('ppb.games.edit',$game) }}"
                  data-create-url="{{ route('ppb.games.edit',[$game,'modal'=>'1']) }}"
                  data-dialog-title="修改賽事" aria-label="修改賽事" title="修改賽事">✏️</a>
                  <a class="ppb-shift-action" href="{{ route('ppb.games.show',$game) }}#delete-game"
                    aria-label="刪除賽事" title="刪除賽事">🗑️</a>
              @endif
              <details class="ppb-shift-manage">
                <summary class="ppb-shift-action ppb-shift-manage-toggle" aria-label="管理 {{ $game->display_code }}" title="管理賽事">⋯</summary>
                <div class="ppb-shift-manage-menu">
                  @if($canManage)
                    @if(!$isCancelled)
                      <button type="button" class="ppb-shift-cancel-open"
                        data-cancel-url="{{ route('ppb.games.cancel',$game) }}"
                        data-game-code="{{ $game->display_code }}"
                        data-revision="{{ \App\Services\Ppb\ScheduleVersion::game($game) }}"
                        data-wp-hold="{{ $locked ? '1' : '0' }}">取消賽事</button>
                    @endif
                  @endif
                  <a href="{{ route('ppb.games.show',$game) }}">歷史紀錄／進階管理</a>
                  @if($locked)
                    <small>WP 已鎖定或待核對；不可任意改號或插入。</small>
                  @endif
                </div>
              </details>
            </div>
            @endunless
          </div>
          <div class="ppb-shift-prediction">{{ $plays[$game->play_type] ?? '玩法待補' }} {{ $game->play_value ?: '' }} @if($game->market_type) <span class="ppb-shift-market">／{{ (int)$game->market_type===2 ? '三路盤' : '兩路盤' }}</span>@endif</div>
          @for($slot=1;$slot<=5;$slot++)
            @php
              $assignment=$short->get($slot);
              $nameColor=$assignment?->currentAnalyst?->scheduleColors($legacyColors[(int)$assignment->current_analyst_id] ?? null);
              $legacyPreview = $pickDisplay['assigned'][$assignment?->id] ?? [];
              $legacyArchived = $assignment ? null : ($archivedPickByGameSlot[(int)$game->id][$slot] ?? null);
              $archiveAnalyst = $legacyArchived ? $archivedAnalysts->get((int)$legacyArchived['analyst_id']) : null;
              $archiveName = $legacyArchived ? ($archiveAnalyst ? ($archiveAnalyst->display_name ?: $archiveAnalyst->name) : '歷史分析師 #'.$legacyArchived['analyst_id']) : null;
              if($legacyArchived) {
                  $legacyPreview = $legacyArchived['preview'];
                  $nameColor=$legacyColors[(int)$legacyArchived['analyst_id']] ?? null;
              }
            @endphp
            <div class="ppb-shift-row">
              <span class="ppb-shift-label">推薦＋短文</span>
              @include('ppb.partials.schedule-rating-trigger', ['slotAssignment' => $assignment, 'archivedName' => $archiveName, 'pick' => $legacyPreview, 'colors' => $nameColor])
              <span class="ppb-shift-pick" style="background-color:{{ $pickColor($legacyPreview) }}">{{ ($assignment || $legacyArchived) ? (($legacyPreview['recommend_pick'] ?? null) ?: '尚未推薦') : '尚未指派' }}</span>
              <span class="ppb-shift-note">{{ ($legacyPreview['short_analysis'] ?? null) ?: (($assignment || $legacyArchived) ? '分析內容…' : '—') }}</span>
            </div>
          @endfor
          @php
            $bonusColor=$bonus?->currentAnalyst?->scheduleColors($legacyColors[(int)$bonus->current_analyst_id] ?? null);
            $legacyBonus = $pickDisplay['assigned'][$bonus?->id] ?? [];
          @endphp
          <div class="ppb-shift-row">
            <span class="ppb-shift-label ppb-shift-label-bonus">BONUS</span>
            @include('ppb.partials.schedule-rating-trigger', ['slotAssignment' => $bonus, 'archivedName' => null, 'pick' => $legacyBonus, 'colors' => $bonusColor])
            <span class="ppb-shift-pick" style="background-color:{{ $pickColor($legacyBonus) }}">{{ $bonus ? (($legacyBonus['recommend_pick'] ?? null) ?: '尚未推薦') : '尚未指派' }}</span>
            <span class="ppb-shift-note">{{ ($legacyBonus['short_analysis'] ?? null) ?: ($bonus ? '分析內容…' : '—') }}</span>
          </div>
          @if($game->has_long_article)
            @php
              $longColor=$long?->currentAnalyst?->scheduleColors($legacyColors[(int)$long->current_analyst_id] ?? null);
            @endphp
            <div class="ppb-shift-row ppb-shift-row-long">
              <span class="ppb-shift-label ppb-shift-label-long">分析文</span>
              @include('ppb.partials.schedule-rating-trigger', ['slotAssignment' => $long, 'archivedName' => null, 'pick' => $pickDisplay['assigned'][$long?->id] ?? [], 'colors' => $longColor])
              {{-- PPB CT6 | Old is_long=1 pick.recommend_pick stores WP URL; short_analysis is the WP post ID. --}}
              @if(isset($longArticleLinks[(int)$game->id]))
                <a class="ppb-shift-long-note" style="color:#0000ee;text-decoration:underline" href="{{ $longArticleLinks[(int)$game->id] }}" target="_blank" rel="noopener noreferrer" title="開啟原 WordPress 分析文章">{{ $longArticleLinks[(int)$game->id] }}</a>
              @else
                <span class="ppb-shift-long-note">尚未提供長文連結</span>
              @endif
            </div>
          @endif
          {{-- PPB CT6 | WP lock is enforced by the backend and explained only in management actions. --}}
          @if($isCancelled)
            <div class="ppb-shift-footnotes"><span>已取消{{ $game->active_number ? '（保留編號）' : '（已釋出現行編號）' }}</span></div>
          @endif
        </article>
      @empty
        <div class="ppb-shift-empty">目前查詢條件下沒有賽事。請確認賽事編號或日期。</div>
      @endforelse
      {{-- PPB PG2 2026-11-05 | Old shift.tpl style: total / current page + compact numbered navigation. --}}
      @if($canManage)
      {{-- PPB OP1 2026-11-05 | Submit to existing protected cancel route. No state mutation via JS. --}}
      <dialog id="ppbCancelGameDialog" class="ppb-shift-cancel-dialog" aria-labelledby="ppbCancelTitle">
        <form id="ppbCancelGameForm" method="post">
          @csrf
          <input type="hidden" name="revision_token" value="">
          <h2 id="ppbCancelTitle">取消賽事 <span id="ppbCancelCode"></span></h2>
          <p id="ppbCancelWpNote" class="ppb-shift-cancel-notice" hidden>此場有 WP 鎖定或待確認紀錄；系統將依既有後端規則審查，不能保證可以取消或釋出編號。</p>
          <label for="ppbCancelReason">取消原因（必填）</label>
          <textarea id="ppbCancelReason" name="reason" maxlength="255" rows="3" required placeholder="請說明取消原因"></textarea>
          <div class="ppb-shift-cancel-buttons">
            <button type="button" id="ppbCancelClose">返回</button>
            <button type="submit" class="ppb-shift-cancel-confirm">確認取消</button>
          </div>
        </form>
      </dialog>
      @endif
      @include('ppb.legacy-admin.pager')
      @if($canManage)
      {{-- PPB INS2 | Explain blocked positions; never pretend a protected renumber worked. --}}
      <dialog id="ppbInsertHoldDialog" class="ppb-shift-cancel-dialog ppb-insert-hold-dialog" aria-labelledby="ppbInsertHoldTitle">
        <h2 id="ppbInsertHoldTitle">此處目前無法安全插入</h2>
        <p id="ppbInsertHoldReason"></p>
        <p>已發表或狀態不明的 WP 賽事、已有投稿／代班歷史的賽事都不得自動改號。您仍可使用「新增賽事」安排到同月份、同 G／E 類型最後。</p>
        <div class="ppb-shift-cancel-buttons"><button type="button" id="ppbInsertHoldClose">返回班表</button></div>
      </dialog>
      {{-- PPB UI5 | Keep existing secured V2 form and backend validation inside a legacy-style modal. --}}
      <dialog id="ppbAddGameDialog" class="ppb-shift-modal" aria-labelledby="ppbAddGameTitle">
        <div class="ppb-shift-modal-header"><strong id="ppbAddGameTitle">新增賽事</strong><button type="button" class="ppb-shift-modal-close" aria-label="關閉新增賽事視窗">×</button></div>
        <iframe class="ppb-shift-modal-frame" title="新增賽事表單" data-src="{{ route('ppb.games.create', ['modal'=>'1']) }}" referrerpolicy="same-origin"></iframe>
      </dialog>
      <script>
      /* PPB OP1 2026-11-05 | Menu and secured cancel dialog; original submit route preserved. */
      (function () {
        const dlg=document.getElementById('ppbCancelGameDialog');
        const form=document.getElementById('ppbCancelGameForm');
        if (!dlg || !form) return;
        const code=dlg.querySelector('#ppbCancelCode');
        const note=dlg.querySelector('#ppbCancelWpNote');
        const reason=dlg.querySelector('#ppbCancelReason');
        document.querySelectorAll('.ppb-shift-cancel-open').forEach(btn => {
          btn.addEventListener('click', function () {
            if (!btn.dataset.cancelUrl) return;
            form.action=btn.dataset.cancelUrl;
            form.reset();
            form.elements.revision_token.value=btn.dataset.revision;
            code.textContent=btn.dataset.gameCode || '';
            note.hidden=btn.dataset.wpHold!=='1';
            if (!dlg.open) dlg.showModal();
            reason.focus();
            btn.closest('details')?.removeAttribute('open');
          });
        });
        dlg.querySelector('#ppbCancelClose').addEventListener('click',()=>dlg.close());
        dlg.addEventListener('click',(event)=>{if(event.target===dlg)dlg.close();});
        dlg.addEventListener('close',()=>{form.removeAttribute('action');form.reset();});
        document.addEventListener('click',(event)=>{
          document.querySelectorAll('.ppb-shift-manage[open]').forEach(menu=>{
            if(!menu.contains(event.target))menu.removeAttribute('open');
          });
        });
      })();
      /* PPB INS2 | Visible blocked gap opens a non-mutating explanation. */
      (function () {
        const dlg=document.getElementById('ppbInsertHoldDialog');
        const reason=document.getElementById('ppbInsertHoldReason');
        if(!dlg || !reason)return;
        document.querySelectorAll('.ppb-insert-hold-open').forEach(button=>{
          button.addEventListener('click',()=>{
            reason.textContent=button.dataset.reason || '此處涉及不能安全順延的賽事。';
            if(!dlg.open)dlg.showModal();
          });
        });
        dlg.querySelector('#ppbInsertHoldClose').addEventListener('click',()=>dlg.close());
        dlg.addEventListener('click',event=>{if(event.target===dlg)dlg.close();});
      })();
      /* PPB UI5 2026-11-04 | No external APIs, writes or privileges from this UI script. */
      (function () {
        const dialog=document.getElementById('ppbAddGameDialog');
        if(!dialog)return;
        const frame=dialog.querySelector('iframe');
        const close=()=>{dialog.close();};
        dialog.addEventListener('close',()=>frame.removeAttribute('src'));
        document.querySelectorAll('.ppb-open-game-modal').forEach(button=>button.addEventListener('click',event=>{
          event.preventDefault();
          button.closest('details')?.removeAttribute('open');
          const title=button.dataset.dialogTitle || '新增賽事';
          dialog.querySelector('strong').textContent=title;
          frame.title=title;
          // Switching from a gap insertion to the ordinary add button must reset
          // the context: never accidentally submit a stale anchor.
          const nextUrl=button.dataset.createUrl || frame.dataset.src;
          if(frame.getAttribute('src')!==nextUrl)frame.setAttribute('src',nextUrl);
          if(!dialog.open)dialog.showModal();
        }));
        dialog.querySelector('.ppb-shift-modal-close').addEventListener('click',close);
        dialog.addEventListener('click',event=>{if(event.target===dialog)close();});
        frame.addEventListener('load',()=>{
          try {
            const loc=frame.contentWindow.location;
            // V2 form POST redirects to a protected game details page on success.
            if(loc.origin!==window.location.origin)return;
            frame.contentDocument.querySelector('[data-close-schedule-form]')?.addEventListener('click',event=>{
              event.preventDefault();
              close();
            });
            if(/^\/admin\/games\/\d+\/?$/.test(loc.pathname)){
              window.location.assign(loc.href);
            } else if(loc.pathname==='/admin/games'){
              // PPB INS1: keep the #ppb-game-ID fragment after safe insertion.
              window.location.assign(loc.href);
            }
          } catch (_) { /* A non-local frame will not be navigated. */ }
        });
      })();
      </script>
      @endif

      @include('ppb.partials.schedule-rating')
    </div></main>
  </div>
</div>
<script src="{{ asset('assets/ppb/ppb-schedule-dialogs.js') }}" defer></script>
@endsection
