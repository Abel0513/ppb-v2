{{-- PPB U2 2026-11-03 | structural-only conversion to CSDA actual admin shell. --}}
@extends('ppb.layout')
@section('title', '角色與權限')
@section('body')
<div class="admin-layout">
    @include('ppb.partials.sidebar')
    <div class="admin-main">@include('ppb.partials.topbar')
        <main class="admin-content"><div class="admin-content-inner"><div class="eyebrow">ROLE & PERMISSION MATRIX</div><h1 class="heading">角色與權限</h1>
        <p class="sub">依角色授予最小必要權限。系統管理員角色固定為完整權限，不允許透過表單修改。</p>
        @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
        <div class="grid-roles">
            @foreach($roles as $role)
            <div class="card">
                <h2 class="card-title">{{ $role->name }}</h2>
                <p class="sub">{{ $role->description }}</p>
                <div class="kpi-number">{{ $role->permissions_count }} <span class="small-note">項權限</span></div>
                @if($role->code === 'owner')<span class="status enabled">固定最高權限</span>
                @elseif(in_array($role->code, ['manager','analyst']))<a class="action" href="{{ route('ppb.roles.edit', $role) }}">設定權限</a>
                @endif
            </div>
            @endforeach
        </div>
        </div></main>
    </div>
</div>
@endsection
