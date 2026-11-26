@extends('ppb.legacy-admin.layout')
@section('title', '排班總覽')

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-overview-legacy.css') }}?v=20261126-2">
@endpush

@section('legacy-content')
@include('ppb.partials.overview-content')
@endsection
