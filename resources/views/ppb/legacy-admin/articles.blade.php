@extends('ppb.legacy-admin.layout')
@section('title', '分析文排班')

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/ppb/ppb-articles-legacy.css') }}?v=20261127-1">
@endpush

@section('legacy-content')
@include('ppb.partials.articles-content')
@endsection
