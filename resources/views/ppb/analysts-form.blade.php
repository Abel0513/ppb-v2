{{-- PPB U2 2026-11-03 | structural-only conversion to CSDA actual admin shell. --}}
{{-- PPB AN2 2026-11-04 | Legacy COMMENT: 0 analyst / 1 analyst+reviewer / 2 reviewer / 3 host. --}}
{{-- PPB A3 | 2026-11-03 | Editable analyst profile --}}
@extends('ppb.layout')
@section('title',$analyst ? '編輯分析師' : '新增分析師')
@section('body')
<div class="admin-layout">@include('ppb.partials.sidebar')
<div class="admin-main">@include('ppb.partials.topbar')<main class="admin-content"><div class="admin-content-inner"><div class="eyebrow">ANALYST PROFILE</div><h1 class="heading">{{ $analyst ? '編輯分析師' : '新增分析師' }}</h1>
<div class="card form-panel">@if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ $analyst ? route('ppb.analysts.update',$analyst) : route('ppb.analysts.store') }}">@csrf @if($analyst)@method('PUT')@endif
<label class="field-label" for="name">姓名</label><input class="field" id="name" name="name" required maxlength="100" value="{{ old('name',$analyst?->name) }}">
<label class="field-label" for="display_name">對外顯示名稱（選填）</label><input class="field" id="display_name" name="display_name" maxlength="100" value="{{ old('display_name',$analyst?->display_name) }}">
<label class="field-label" for="status">狀態</label><select class="field" name="status" id="status"><option value="active" @selected(old('status',$analyst?->status ?? 'active')==='active')>啟用</option><option value="inactive" @selected(old('status',$analyst?->status)==='inactive')>停用</option></select>
<label class="field-label" for="user_type">身分類型（依舊版欄位備註）</label><select class="field" id="user_type" name="user_type">@foreach([0=>'0：分析師',1=>'1：核稿＋分析師',2=>'2：核稿',3=>'3：主持人'] as $type=>$label)<option value="{{ $type }}" @selected((string)old('user_type',$analyst?->user_type ?? 0)===(string)$type)>{{ $label }}</option>@endforeach</select>
<p class="small-note">排班目前可選：短文／BONUS／長文為類型 0、1；核稿為類型 1、2。類型 3 為主持人，不代表停用；主持人不參與分析稿或核稿指派。</p>
<label class="field-label" for="account_id">後台帳號關聯（選填）</label><select class="field" name="account_id" id="account_id"><option value="">不綁定</option>
@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string)old('account_id',$analyst?->account_id)===(string)$account->id)>{{ $account->name }} · {{ $account->email }}</option>@endforeach
</select><p class="small-note">僅可綁定具有「分析師」角色、未綁定其他分析師的帳號；沒有綁定不影響名冊身分。</p>
<label class="field-label" for="color">排班文字色（選填，#RRGGBB）</label><input class="field" id="color" name="color" maxlength="7" pattern="#[0-9a-fA-F]{6}" value="{{ old('color',$analyst?->color) }}">
<label class="field-label" for="background">排班背景色（選填，#RRGGBB；舊欄位 background，不是履歷）</label><input class="field" id="background" name="background" maxlength="7" pattern="#[0-9a-fA-F]{6}" value="{{ old('background',$analyst?->background) }}">
<label class="field-label" for="wp_photo">WordPress 照片附件 ID（選填）</label><input class="field" id="wp_photo" name="wp_photo" type="number" min="1" max="{{ PHP_INT_MAX }}" step="1" value="{{ old('wp_photo',$analyst?->wp_photo) }}">
<label class="field-label" for="author_id">WordPress 作者來源 ID（選填）</label><input class="field" id="author_id" name="author_id" type="number" min="0" max="{{ PHP_INT_MAX }}" step="1" value="{{ old('author_id',$analyst?->author_id) }}">
<label class="field-label" for="rating_level">主檔評級（不取代月份評級）</label><select class="field" id="rating_level" name="rating_level"><option value="">未提供</option>@foreach([0,1,2,3] as $level)<option value="{{ $level }}" @selected((string)old('rating_level',$analyst?->rating_level)===(string)$level)>{{ $level }}</option>@endforeach</select>
<label class="field-label" for="analyst_level">主檔層級（舊欄位 analyst_level，僅 0 或 1；文案尚未從舊來源解遮蔽）</label><select class="field" id="analyst_level" name="analyst_level"><option value="">未提供</option>@foreach([0,1] as $level)<option value="{{ $level }}" @selected((string)old('analyst_level',$analyst?->analyst_level)===(string)$level)>{{ $level }}</option>@endforeach</select>
@if($canPrivate)
<label class="field-label" for="line_id">LINE 身分來源關聯（選填）</label><input class="field" id="line_id" name="line_id" maxlength="255" autocomplete="off" value="{{ $analyst?->line_id }}">
<label class="field-label" for="line_name">LINE 顯示名稱（選填）</label><input class="field" id="line_name" name="line_name" maxlength="255" autocomplete="off" value="{{ $analyst?->line_name }}">
<p class="small-note">僅供受權管理者保存來源關聯，不等於已驗證 LINE 登入。</p>
@endif
<label class="field-label" for="internal_notes">內部備註（選填）</label><textarea class="field" name="internal_notes" id="internal_notes" maxlength="2000" style="height:110px;padding:12px">{{ old('internal_notes',$analyst?->internal_notes) }}</textarea>
<div class="toolbar" style="margin-top:22px"><button class="action" type="submit">{{ $analyst ? '儲存修改' : '建立分析師' }}</button><a class="link" href="{{ route('ppb.analysts.index') }}">取消</a></div></form>
</div></div></main></div></div>
@endsection
