@extends('bookings.layout')
@section('title', __('Agenda'))
@section('content')
    <div class="center">
        <div class="card hero">
            <span class="mark neutral">@include('bookings._icon', ['name' => 'calendar'])</span>
            <h1>{{ __('Esta página no está disponible') }}</h1>
            <p>{{ __('Revisa el enlace o escríbele directamente al negocio.') }}</p>
        </div>
    </div>
@endsection
