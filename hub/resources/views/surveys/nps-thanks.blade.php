@extends('reviews.layout')
@section('title', __('¡Gracias!'))
@section('content')
    <h1>{{ $thanks }}</h1>
    <p class="muted">{{ __('Ya puedes cerrar esta página.') }}</p>
@endsection
