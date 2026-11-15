{{-- PPB B1 2026-11-04 | CSDA U2 shell, old scheduling fields retained. --}}
@extends('ppb.layout')
@section('title',$game ? '編輯賽事' : '建立賽事')
{{-- PPB UI5 2026-11-04 | Legacy-style add modal uses existing safe V2 form and backend routes. --}}
@if(!$game && request()->query('modal')==='1')
@push('styles')
<style>
  html,body{background:#fff!important;margin:0!important;min-height:0!important}
  .admin-sidebar,.admin-sidebar-reopen-button,.admin-sidebar-overlay,.admin-topbar,.admin-topbar-spacer{display:none!important}
  .admin-layout{display:block!important;min-height:0!important;background:#fff!important}
  .admin-main{display:block!important;width:100%!important;margin:0!important;max-width:none!important;min-width:0!important}
  .admin-content{padding:0!important;margin:0!important;background:#fff!important;min-height:0!important}
  .admin-content-inner{max-width:none!important;padding:15px 18px 25px!important;margin:0!important}
  .admin-content-inner > .eyebrow,.admin-content-inner > .heading,.admin-content-inner > .sub{display:none!important}
  .admin-content-inner > .card.form-panel{max-width:none!important;margin:0!important;border:0!important;box-shadow:none!important;padding:0!important}
  .ppb-sched-grid{grid-template-columns:1fr!important}
  .ppb-fieldset{margin:10px 0 20px!important}
  .admin-profile-dropdown{display:none!important}
  @media(max-width:640px){.admin-content-inner{padding:12px 14px 22px!important}}
</style>
@endpush
@endif

@section('body')
@php
 $isEdit = (bool)$game;
 // PPB UI6: insertion only from an explicit same-origin gap link; the service
 // still validates the anchor and every possibly affected G/E number.
 $fromInvalidSubmit = $errors->any() && in_array(old('position'), ['before','after'], true);
 $insertAnchor = (string) request()->query('anchor_id', $fromInvalidSubmit ? old('anchor_id', '') : '');
 $insertMonth = (string) request()->query('insert_month', $fromInvalidSubmit ? old('insert_month', '') : '');
 $insertType = (string) request()->query('game_type', $fromInvalidSubmit ? old('game_type', '1') : '1');
 $insertPosition = (string) request()->query('position', $fromInvalidSubmit ? old('position', '') : '');
 $isInsert = !$isEdit && ((request()->query('modal') === '1' && in_array($insertPosition, ['before','after'], true)) || $fromInvalidSubmit)
     && preg_match('/^[1-9][0-9]*$/D', $insertAnchor) === 1
     && preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D', $insertMonth) === 1
     && in_array($insertType, ['1','2'], true);
 $requestedInsertDate = (string) request()->query('match_date', '');
 $insertDate = $isInsert && preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])-([0-3][0-9])$/D', $requestedInsertDate) === 1
     && substr($requestedInsertDate, 0, 7) === $insertMonth
     && checkdate((int)substr($requestedInsertDate, 5, 2), (int)substr($requestedInsertDate, 8, 2), (int)substr($requestedInsertDate, 0, 4))
         ? $requestedInsertDate : now('Asia/Taipei')->format('Y-m-d');
 $dateValue = old('match_date',$game?->recommended_at?->timezone('Asia/Taipei')->format('Y-m-d') ?? ($isInsert ? $insertDate : now('Asia/Taipei')->format('Y-m-d')));
 $timeValue = old('match_time',$game?->recommended_at?->timezone('Asia/Taipei')->format('H:i') ?? '');
 $isManual = old('matchup_mode',$game?->matchup_mode ?? 'manual') === 'manual';
 $playType = (string)old('play_type',$game?->play_type ?? '');
 $playValue = old('play_value', in_array($game?->play_type,[1,2,3],true)?$game?->play_value:'');
 $special = old('special_play',$game?->play_type===4?$game?->play_value:'');
 $manualPlay = old('manual_play',$game?->play_type===5?$game?->play_value:'');
@endphp
<div class="admin-layout">@include('ppb.partials.sidebar')
<div class="admin-main">@include('ppb.partials.topbar')<main class="admin-content"><div class="admin-content-inner">
<div class="eyebrow">SCHEDULE EDITOR</div><h1 class="heading">{{ $isEdit ? '編輯賽事 '.$game->display_code : '建立賽事' }}</h1>
<p class="sub">建立時可以先不選分析師；補齊 5 位短文、1 位 BONUS、核稿人及選用的長文人員後，才符合 WP 上傳條件。</p>
<div class="card form-panel" style="max-width:1000px">
@if($errors->any())<div class="error" style="margin-bottom:16px"><strong>請修正以下資料：</strong><ul style="margin:5px 0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($game && ($game->hasVerifiedWp() || $game->hasUnresolvedWp()))<div class="error">此賽事已有 WP 發布或待查紀錄，暫停一般編輯，以免破壞發布與歷史關聯。<a class="link" href="{{ route('ppb.games.show',$game) }}">返回詳情</a></div>@else
<form method="post" action="{{ $isEdit ? route('ppb.games.update',$game) : route('ppb.games.store') }}" id="ppbScheduleForm">@csrf @if($isEdit)@method('PUT')@endif
@if($isEdit)<input type="hidden" name="revision_token" value="{{ old('revision_token', \App\Services\Ppb\ScheduleVersion::game($game)) }}">@endif
<h2 class="card-title" style="margin:0 0 16px">1. 排班資料</h2>
<div class="ppb-sched-grid">
<label class="field-label">賽事類型
@if($isEdit || $isInsert)<input type="hidden" name="game_type" value="{{ $isEdit ? $game->game_type : $insertType }}">@endif
<select class="field" name="game_type" id="gameType" @disabled($isEdit || $isInsert)><option value="1" @selected((string)old('game_type',$game?->game_type ?? ($isInsert ? $insertType : '1'))==='1')>G 日常賽事</option><option value="2" @selected((string)old('game_type',$game?->game_type ?? ($isInsert ? $insertType : '1'))==='2')>E 額外加場</option></select></label>
<label class="field-label">推薦日期 <input class="field" type="date" name="match_date" id="matchDate" value="{{ $dateValue }}" required></label>
<label class="field-label">推薦時段 <select class="field" name="match_time" required><option value="">請選擇</option><option value="11:00" @selected($timeValue==='11:00')>11:00</option><option value="16:00" @selected($timeValue==='16:00')>16:00</option></select></label>
<label class="field-label">核稿人員（建立時可後補）<select class="field" name="reviewer_analyst_id"><option value="">尚未指派</option>@foreach($reviewers as $reviewer)<option value="{{ $reviewer->id }}" @selected((string)old('reviewer_analyst_id',$game?->reviewer_analyst_id)===(string)$reviewer->id)>{{ $reviewer->display_name ?: $reviewer->name }}</option>@endforeach</select></label>
<label class="field-label">實際開賽時間（選填，不等於推薦時段）<input class="field" type="datetime-local" name="starts_local" value="{{ old('starts_local',$game?->starts_at?->timezone('Asia/Taipei')->format('Y-m-d\TH:i')) }}"></label>
</div>
@if(!$isEdit)
{{-- PPB UI6: ordinary add is append. A gap entry carries an explicit anchor;
     this is only a UI handoff to the existing, guarded ScheduleManager::create. --}}
<input type="hidden" name="position" value="{{ $isInsert ? $insertPosition : 'append' }}">
@if($isInsert)
<input type="hidden" name="anchor_id" value="{{ $insertAnchor }}">
<input type="hidden" name="insert_month" value="{{ $insertMonth }}">
<div class="ppb-insert-context" role="status">這場賽事將插入 {{ $insertMonth }} 的 {{ $insertType === '1' ? 'G' : 'E' }} 編號序列（參考賽事{{ $insertPosition === 'before' ? '之前' : '之後' }}）。伺服器仍會於提交時重新檢查 WP、投稿與編號衝突。</div>
@endif
@endif
<hr class="ppb-hr"><h2 class="card-title" style="margin:0 0 16px">2. 對戰組合（依舊版選項）</h2>
{{-- PPB FX1 | 舊版操作：先選運動，或選手動填寫；既有 V2 後端欄位不變 --}}
@php
  $fixtureSportValue = $isManual ? 'manual' : (string)($fixtures->firstWhere('id',old('fixture_id',$game?->fixture_id))?->sport ?? '');
  $sportChoice = old('sport_choice', $game ? $fixtureSportValue : '');
@endphp
<div class="ppb-sched-grid">
  <label class="field-label">對戰組合
    <select class="field" name="sport_choice" id="fixtureSport" required>
      <option value="">請選擇運動類型</option>
      <option value="manual" @selected($sportChoice==='manual')>手動填寫</option>
      @foreach($fixtures->pluck('sport')->unique() as $sport)
        <option value="{{ $sport }}" @selected($sportChoice===(string)$sport)>{{ $sport }}</option>
      @endforeach
    </select>
  </label>
</div>
<input type="hidden" name="matchup_mode" id="matchMode" value="{{ $sportChoice==='manual'?'manual':'fixture' }}">
<div id="fixtureSection" class="ppb-sched-grid">
  <label class="field-label">對戰組合
    <select class="field" name="fixture_id" id="fixtureSelect">
      <option value="">請選擇對戰組合</option>
      @foreach($fixtures as $fixture)
        <option value="{{ $fixture->id }}" data-sport="{{ $fixture->sport }}" @selected((string)old('fixture_id',$game?->fixture_id)===(string)$fixture->id)>{{ $fixture->starts_at ? \Carbon\Carbon::parse($fixture->starts_at, 'UTC')->timezone('Asia/Taipei')->format('m/d') : '' }}｜{{ $fixture->league }} {{ $fixture->away_team . '@' . $fixture->home_team }}</option>
      @endforeach
    </select>
  </label>
</div>
@if($fixtures->isEmpty())<p class="small-note">目前尚無可選賽程。可使用「手動填寫」；賽程同步狀態應由管理端確認。</p>@endif
<div id="manualSection" class="ppb-sched-grid">
<label class="field-label">聯賽<input class="field" type="text" name="manual_league" maxlength="50" value="{{ old('manual_league',$isManual?$game?->league:'') }}" placeholder="例如：美國職籃"></label>
<label class="field-label">對戰組合<input class="field" type="text" name="manual_matchup" maxlength="255" value="{{ old('manual_matchup',$isManual && $game ? (($game->away_team==='—'?'':$game->away_team).'@'.($game->home_team==='—'?'':$game->home_team)) : '') }}" placeholder="例如：湖人@勇士"></label>
</div>
<hr class="ppb-hr"><h2 class="card-title" style="margin:0 0 16px">3. 玩法與盤口</h2>
<div class="ppb-sched-grid">
<label class="field-label">玩法類型<select class="field" id="playType" name="play_type" required><option value="">請選擇玩法</option>@foreach([5=>'手動填寫',1=>'讓分',2=>'不讓分',3=>'大小分',4=>'特殊玩法'] as $id=>$label)<option value="{{ $id }}" @selected($playType===(string)$id)>{{ $label }}</option>@endforeach</select></label>
<label class="field-label ppb-play" data-play="1,3">讓分數值／大小分基準<input class="field" name="play_value" maxlength="160" value="{{ $playValue }}" placeholder="例如 +1.5 / 215.5"></label>
<label class="field-label ppb-play" data-play="1,2,3">盤口類型<select class="field" name="market_type"><option value="">請選擇</option><option value="1" @selected((string)old('market_type',$game?->market_type)==='1')>兩路盤</option><option value="2" @selected((string)old('market_type',$game?->market_type)==='2')>三路盤</option></select></label>
<label class="field-label ppb-play" data-play="4">特殊玩法<input class="field" name="special_play" maxlength="160" value="{{ $special }}" placeholder="例如角球數、第一節勝負"></label>
<label class="field-label ppb-play" data-play="5">手動填寫玩法<input class="field" name="manual_play" maxlength="160" value="{{ $manualPlay }}" placeholder="例如看盤決定"></label>
</div>
<hr class="ppb-hr"><h2 class="card-title" style="margin:0 0 16px">4. 分析師指派</h2>
<p class="small-note">可留空稍後補齊；短文 5 位互不重複。BONUS、長文可以與短文兼任。</p>
<div class="ppb-sched-grid">
@for($n=1;$n<=5;$n++)
<label class="field-label">短文分析師 {{ $n }}<select class="field" name="short[{{ $n }}]" data-short-slot><option value="">尚未指派</option>@foreach($analysts as $analyst)<option value="{{ $analyst->id }}" @selected((string)old('short.'.$n,$selected['short'][$n]??'')===(string)$analyst->id)>{{ $analyst->display_name ?: $analyst->name }}</option>@endforeach</select></label>
@endfor
<label class="field-label">BONUS 分析師<select class="field" name="bonus_id"><option value="">尚未指派</option>@foreach($analysts as $analyst)<option value="{{ $analyst->id }}" @selected((string)old('bonus_id',$selected['bonus']??'')===(string)$analyst->id)>{{ $analyst->display_name ?: $analyst->name }}</option>@endforeach</select></label>
</div>
<label class="field-label" style="display:flex;gap:8px;align-items:center;margin-top:18px"><input type="checkbox" name="has_long_article" id="hasLong" value="1" @checked((bool)old('has_long_article',$game?->has_long_article ?? false))> 本場有長文</label>
<label class="field-label" id="longField">長文分析師<select class="field" name="long_id"><option value="">尚未指派</option>@foreach($analysts as $analyst)<option value="{{ $analyst->id }}" @selected((string)old('long_id',$selected['long']??'')===(string)$analyst->id)>{{ $analyst->display_name ?: $analyst->name }}</option>@endforeach</select></label>
<label class="field-label">備註<textarea class="field" name="notes" maxlength="3000" style="min-height:90px">{{ old('notes',$game?->notes) }}</textarea></label>
<div class="toolbar" style="margin-top:24px"><button class="action" type="submit">{{ $isEdit?'儲存變更':'建立賽事' }}</button><a class="link" href="{{ $isEdit?route('ppb.games.show',$game):route('ppb.games.index') }}">返回</a></div>
</form>@endif
</div></div></main></div></div>
<style>.ppb-sched-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 18px;margin-bottom:16px}.ppb-sched-grid .field-label{min-width:0}.ppb-sched-grid .field{width:100%}.ppb-fieldset{border:1px solid #dce3ed;border-radius:12px;margin:12px 0 24px;padding:16px}.ppb-fieldset legend{padding:0 8px;font-weight:700}.ppb-hr{border:0;border-top:1px solid #e6ebf2;margin:26px 0 20px}.ppb-insert-context{font-size:12px;padding:8px 10px;margin:8px 0 15px;color:#48576c;background:#f6f8fc;border-left:2px solid #7183ab;border-radius:4px}[hidden]{display:none!important}@media(max-width:680px){.ppb-sched-grid{grid-template-columns:1fr}}</style>
<script>
(function(){
const q=(s)=>document.querySelector(s);
const mode=q('#matchMode'),sport=q('#fixtureSport'),fixture=q('#fixtureSelect');
function fixtureFilter(){if(!fixture||!sport)return;Array.from(fixture.options).forEach((o,i)=>{if(i===0)return;o.hidden=!!sport.value&&o.dataset.sport!==sport.value});const selected=fixture.selectedOptions[0];if(selected&&selected.hidden)fixture.value='';}
function matchupMode(){if(!mode||!sport)return;const manual=sport.value==='manual';mode.value=manual?'manual':'fixture';q('#manualSection').hidden=!manual;q('#fixtureSection').hidden=manual||!sport.value;if(fixture)fixture.required=!manual&&!!sport.value;}
function playMode(){const value=q('#playType')?.value||'';document.querySelectorAll('.ppb-play').forEach(x=>x.hidden=!x.dataset.play.split(',').includes(value));}
function longMode(){const x=q('#longField');if(x)x.hidden=!q('#hasLong').checked;}
if(sport)sport.addEventListener('change',()=>{fixtureFilter();matchupMode()});if(q('#playType'))q('#playType').addEventListener('change',playMode);
if(q('#hasLong'))q('#hasLong').addEventListener('change',longMode);

if(fixture?.selectedOptions[0]?.dataset.sport && sport?.value!=='manual')sport.value=fixture.selectedOptions[0].dataset.sport;
fixtureFilter();matchupMode();playMode();longMode();
q('#ppbScheduleForm')?.addEventListener('submit',function(e){
 const vals=Array.from(document.querySelectorAll('[data-short-slot]')).map(s=>s.value).filter(Boolean);
 if(new Set(vals).size!==vals.length){e.preventDefault();alert('短文五個位置不可指派同一位分析師。');return;}
 const insertMonth=@json($isInsert ? $insertMonth : '');
 const insertType=@json($isInsert ? $insertType : '');
 if(insertMonth && (q('#matchDate')?.value||'').slice(0,7)!==insertMonth){
   e.preventDefault();alert('插入賽事的推薦日期必須與參考賽事同月份。');return;
 }
 if(insertType && q('#gameType')?.value!==insertType){
   e.preventDefault();alert('插入賽事的 G／E 類型必須與參考賽事相同。');return;
 }
});
})();
</script>
@endsection
