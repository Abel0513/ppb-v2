@extends('ppb.layout')

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-shift-ui4.css') }}?v=20261105-op1">
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-legacy-admin.css') }}">
@endpush

@section('body')
<div class="admin-layout">
  @include('ppb.partials.sidebar')
  <div class="admin-main">
    @include('ppb.partials.topbar')
    <main class="admin-content ppb-legacy-admin">
      <div class="admin-content-inner">@yield('legacy-content')</div>
    </main>
  </div>
</div>
@yield('legacy-scripts')
@endsection
