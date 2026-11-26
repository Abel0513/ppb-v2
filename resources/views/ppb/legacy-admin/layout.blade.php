@extends('ppb.layout')

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-v2-lists.css') }}?v=20261126">
@endpush

@section('body')
<div class="admin-layout">
  @include('ppb.partials.sidebar')
  <div class="admin-main">
    @include('ppb.partials.topbar')
    <main class="admin-content">
      <div class="admin-content-inner">@yield('legacy-content')</div>
    </main>
  </div>
</div>
@yield('legacy-scripts')
@endsection
