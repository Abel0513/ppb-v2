{{-- PPB U2 2026-11-03 | structural-only conversion to CSDA actual admin shell. --}}
@extends('ppb.layout')
@section('title', '調整角色權限')
@section('body')
<div class="admin-layout">
    @include('ppb.partials.sidebar')
    <div class="admin-main">@include('ppb.partials.topbar')
        <main class="admin-content"><div class="admin-content-inner"><div class="eyebrow">PERMISSION SETTINGS</div><h1 class="heading">{{ $role->name }}權限設定</h1>
        <p class="sub">核對後儲存，即時生效。沒有勾選的權限不會授予。</p>
        <div class="card form-panel">
            @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ route('ppb.roles.update', $role) }}">@csrf @method('PUT')
                @foreach($permissions as $category => $group)
                    <h2 class="group-title">{{ $category }}</h2>
                    <div class="option-row">
                        @foreach($group as $permission)
                            <label class="option"><input name="permissions[]" type="checkbox" value="{{ $permission->id }}" @checked(in_array($permission->id, (array) old('permissions', $selected)))> {{ $permission->name }}</label>
                        @endforeach
                    </div>
                @endforeach
                <div class="toolbar"><button class="action" type="submit">儲存權限</button><a class="link" href="{{ route('ppb.roles.index') }}">取消</a></div>
            </form>
        </div>
        </div></main>
    </div>
</div>
@endsection
