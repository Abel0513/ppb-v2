{{-- PPB W2 2026-11-06 | Separate from the compact original schedule card UI. --}}
@extends('ppb.layout')
@section('title','賽事稿件與核稿')
@section('body')
@php
    $labels=['pick'=>'短文','bonus'=>'BONUS','long'=>'長文'];
    $states=['legacy_imported'=>'歷史唯讀','draft'=>'草稿','submitted'=>'待核稿','approved'=>'已核准','changes_requested'=>'退回修改'];
    $approvedPickBodies=[];
    $approvedKinds=['pick'=>0,'bonus'=>0,'long'=>0];
    foreach($assignments as $candidate) {
        $candidateRows=$rows->get($candidate->id, collect());
        if($candidateRows->count()===1 && $candidateRows->first()->status==='approved') {
            $approvedKinds[$candidate->kind]=($approvedKinds[$candidate->kind]??0)+1;
            if($candidate->kind==='pick') $approvedPickBodies[]=trim((string)$candidateRows->first()->analysis_text);
        }
    }
    $shortDistinct=(count($approvedPickBodies)===5 && count(array_unique(array_map(fn($v)=>hash('sha256',$v),$approvedPickBodies)))===5);
    $allApproved=$game->wp_ready && $approvedKinds['pick']===5 && $approvedKinds['bonus']===1
        && (!$game->has_long_article || $approvedKinds['long']===1) && $shortDistinct;
@endphp
<div class="admin-layout">@include('ppb.partials.sidebar')
<div class="admin-main">@include('ppb.partials.topbar')
<main class="admin-content"><div class="admin-content-inner">
<div class="eyebrow">EDITORIAL WORKFLOW</div>
<h1 class="heading">{{ $game->display_code }}｜{{ $game->matchup_text ?: $game->away_team.' @ '.$game->home_team }}</h1>
<p class="sub">推薦時間：{{ $game->recommended_at?->timezone('Asia/Taipei')->format('Y/m/d H:i') }}（台灣）</p>
@if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
@if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
<div class="card" style="margin-bottom:18px">
<h2 class="card-title">投稿與核稿進度</h2>
<p class="small-note">短文已核准 {{ $approvedKinds['pick'] }}/5、BONUS {{ $approvedKinds['bonus'] }}/1、長文 {{ $game->has_long_article ? $approvedKinds['long'].'/1' : '未安排' }}。五篇短文內容{{ $shortDistinct?'互不相同':'尚未全部核准且確認彼此不同' }}。</p>
@if(!$editable)<p class="small-note">本場已取消、已發布或 WP 狀態待查，為避免覆蓋歷史稿件，本頁僅供查看。</p>@elseif($allApproved)
<p class="small-note">本頁稿件核稿門檻已達成。WordPress 實際發布功能尚未在此頁啟用。</p>
@else<p class="small-note">待補齊指派、內容及核稿後才可進入 WordPress 上傳流程；此頁不會直接上傳。</p>@endif
</div>
@forelse($assignments as $assignment)
@php
    $recordSet=$rows->get($assignment->id, collect());
    $record=$recordSet->count()===1?$recordSet->first():null;
    $conflict=$recordSet->count()>1;
    $isLegacy=$record && $record->status==='legacy_imported';
    $preview=$isLegacy?json_decode((string)$record->content_body,true):null;
    $legacyFields=is_array($preview) && ($preview['_ppb_legacy_pick_v1']??false);
    $recommend=$isLegacy?($legacyFields?(string)($preview['recommend_pick']??''):''):(string)($record->recommend_pick??'');
    $analysis=$isLegacy?($legacyFields?(string)($preview['short_analysis']??''):(string)$record->content_body):(string)($record->analysis_text??'');
    $writable=$canEdit[$assignment->id]??false;
@endphp
<div class="card" style="margin-bottom:18px">
<h2 class="card-title">{{ $labels[$assignment->kind]??$assignment->kind }}{{ $assignment->kind==='pick'?' '.$assignment->slot_no:'' }}｜{{ $assignment->currentAnalyst?->display_name ?: ($assignment->currentAnalyst?->name ?: '未指定分析師') }}</h2>
<p class="small-note">狀態：{{ $conflict?'同一指派多筆歷史紀錄（禁止自動覆蓋）':($states[$record->status??'']??($record?'需人工確認':'尚未投稿')) }}@if($record?->submitted_at) · 送稿 {{ $record->submitted_at }}@endif</p>
@if($conflict)
<p class="small-note">此指派目前有 {{ $recordSet->count() }} 筆投稿紀錄，須由管理員另外對帳，不可在此新建或改寫。</p>
@elseif($writable)
<form method="post" action="{{ route('ppb.manuscripts.save',$assignment) }}">
@csrf
<input type="hidden" name="revision_token" value="{{ $tokens[$assignment->id] }}">
@if($assignment->kind!=='long')
<label class="field-label" for="recommend-{{ $assignment->id }}">推薦選擇</label>
<textarea id="recommend-{{ $assignment->id }}" class="field" name="recommend_pick" rows="2" maxlength="3000" style="width:100%;margin:7px 0 14px">{{ $recommend }}</textarea>
@else
<p class="small-note">獨立長文先撰寫內容，WordPress 文章網址及回傳 ID 將在正式發布流程另外保存。</p>
@endif
<label class="field-label" for="analysis-{{ $assignment->id }}">{{ $assignment->kind==='long'?'長文內容':'分析內容' }}</label>
<textarea id="analysis-{{ $assignment->id }}" class="field" name="analysis_text" rows="8" maxlength="60000" style="width:100%;margin:7px 0 14px">{{ $analysis }}</textarea>
<div class="toolbar"><button class="action" name="mode" value="save" type="submit">儲存草稿</button><button class="action" name="mode" value="submit" type="submit" onclick="return confirm('確認正式送交核稿？送出後須由核稿人員退回才能再編輯。')">送交核稿</button></div>
</form>
@else
@if($isLegacy && $assignment->kind==='long')
@php
    $parsedHost=parse_url(trim($recommend), PHP_URL_HOST);
    $parsedScheme=parse_url(trim($recommend), PHP_URL_SCHEME);
    $safeHistoricalLongLink=$parsedScheme==='https' && in_array(strtolower((string)$parsedHost), ['csdatw.com','www.csdatw.com'], true);
@endphp
<p class="small-note">歷史長文：WP 網址／回傳 ID（非稿件本文）</p>
@if($safeHistoricalLongLink)<p><a class="link" href="{{ $recommend }}" target="_blank" rel="noopener noreferrer">查看 WordPress 長文</a></p>
@elseif($recommend!=='')<div style="overflow-wrap:anywhere">{{ $recommend }}</div>@endif
<p class="small-note">歷史 WP ID：{{ $analysis!==''?$analysis:'尚無記錄' }}</p>
@else
@if($assignment->kind!=='long' && $recommend!=='')<p class="small-note">推薦選擇</p><div style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $recommend }}</div>@endif
@if($analysis!=='')<p class="small-note" style="margin-top:12px">{{ $assignment->kind==='long'?'長文內容':'分析內容' }}</p><div style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $analysis }}</div>
@elseif(!$record)<p class="small-note">尚未投稿。</p>
@else<p class="small-note">這筆歷史紀錄沒有可直接顯示的分析文字，請查看原始稿件來源。</p>@endif
@endif
@endif
@if($record && !$isLegacy && $record->review_note)
<p class="small-note" style="margin-top:12px">核稿意見：{{ $record->review_note }}</p>
@endif
@if($record && $reviewer && $editable && !$isLegacy && !$conflict && $record->status==='submitted')
<form method="post" action="{{ route('ppb.manuscripts.review',$record->id) }}" style="margin-top:14px;border-top:1px solid #e3e7f1;padding-top:14px">
@csrf
<label class="field-label" for="review-{{ $record->id }}">核稿意見（退回時必填）</label>
<textarea id="review-{{ $record->id }}" class="field" name="review_note" maxlength="1000" rows="3" style="width:100%;margin:7px 0 14px"></textarea>
<div class="toolbar"><button class="action" name="decision" value="approved" type="submit">核准稿件</button><button class="action" name="decision" value="changes_requested" type="submit">退回修改</button></div>
</form>
@endif
</div>
@empty
<div class="card"><p class="small-note">目前沒有任何分析師指派，請先由排班管理補齊。</p></div>
@endforelse
<div class="toolbar"><a class="link" href="{{ route('ppb.manuscripts.index') }}">返回稿件列表</a>@if($admin)<a class="link" href="{{ route('ppb.games.show',$game) }}">返回賽事詳情</a>@endif</div>
</div></main></div></div>
@endsection
