@extends('capture.layout')
@section('title', $status === 429 ? __('Demasiadas visitas seguidas') : __('Página no disponible'))
@section('content')
    <header class="brand"><span>{{ $business }}</span></header>
    <main style="max-width:560px;margin:0 auto">
        <div class="card">
            @if ($status === 429)
                <h1 style="font-size:24px">{{ __('Demasiadas visitas seguidas') }}</h1>
                <p style="color:var(--muted);margin:0">{{ __('Espera un minuto y vuelve a abrir la página.') }}</p>
            @else
                <h1 style="font-size:24px">{{ __('Esta página no está disponible') }}</h1>
                <p style="color:var(--muted);margin:0">{{ __('Revisa el enlace o escríbele directamente al negocio.') }}</p>
            @endif
        </div>
    </main>
@endsection
