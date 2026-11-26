@extends('quotes.layout')

@section('title', __('Cotizador no disponible'))

@section('content')
    <div class="card" style="text-align:center">
        <h1>{{ __('Este cotizador no está disponible') }}</h1>
        <p class="muted">{{ __('Puede que el enlace esté incompleto, que el cotizador esté en pausa o que hayan pasado muchos intentos seguidos. Inténtalo más tarde o escríbele directamente al negocio.') }}</p>
    </div>
@endsection
