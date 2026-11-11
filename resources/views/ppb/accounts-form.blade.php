{{-- PPB U2 2026-11-03 | structural-only conversion to CSDA actual admin shell. --}}
@extends('ppb.layout')
@section('title', $account ? '編輯帳號' : '新增帳號')
@section('body')
<div class="admin-layout">
    @include('ppb.partials.sidebar')
    <div class="admin-main">
        @include('ppb.partials.topbar')
        <main class="admin-content"><div class="admin-content-inner">
            <div class="eyebrow">ACCOUNT SETTINGS</div>
            <h1 class="heading">{{ $account ? '編輯帳號' : '新增帳號' }}</h1>
            <div class="card form-panel">
                @if($errors->any())<div class="error">請檢查輸入欄位：{{ $errors->first() }}</div>@endif
                <form method="post" action="{{ $account ? route('ppb.accounts.update', $account) : route('ppb.accounts.store') }}">
                    @csrf
                    @if($account)@method('PUT')@endif
                    <label class="field-label" for="name">姓名</label>
                    <input class="field" id="name" name="name" value="{{ old('name', $account?->name) }}" maxlength="100" required autocomplete="off">
                    <label class="field-label" for="username">登入帳號</label>
                    <input class="field" type="text" id="username" name="username" value="{{ old('username', $account?->username) }}" minlength="3" maxlength="64" pattern="[A-Za-z0-9_.-]{3,64}" required autocomplete="off" autocapitalize="none" spellcheck="false">
                    <p class="small-note">3～64 個英數字，可使用英文句點、底線及連字號。</p>
                    <label class="field-label" for="password">{{ $account ? '更改密碼（留空不修改）' : '初始密碼' }}</label>
                    <input class="field" id="password" name="password" type="password" minlength="10" maxlength="128" {{ $account ? '' : 'required' }} autocomplete="new-password">
                    <label class="field-label" for="password_confirmation">確認密碼</label>
                    <input class="field" id="password_confirmation" name="password_confirmation" type="password" minlength="10" maxlength="128" {{ $account ? '' : 'required' }} autocomplete="new-password">
                    <p class="small-note">最少 10 字元，建議使用密碼管理器產生隨機密碼。</p>
                    <div class="field-label">角色（可複選）</div>
                    <div class="option-row">
                        @foreach($roles as $role)
                            <label class="option"><input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(in_array($role->id, (array) old('roles', $selectedRoles)))> {{ $role->name }}</label>
                        @endforeach
                    </div>
                    <label class="option"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $account?->is_active ? '1' : ($account ? '0' : '1')) == '1')> 帳號啟用</label>
                    <div class="toolbar"><button class="action" type="submit">儲存帳號</button><a class="link" href="{{ route('ppb.accounts.index') }}">取消</a></div>
                </form>
            </div>
        </div></main>
    </div>
</div>
@endsection
