@extends('referrals.layout')
@section('title', __('Enlace vencido'))
@section('content')
    <div class="card">
        <p class="brand">{{ $brand }}</p>
        @if ($sent)
            <h1>{{ __('Te enviamos un enlace nuevo') }}</h1>
            <p>{{ __('Revisa tu correo: llega desde :brand en unos minutos.', ['brand' => $brand]) }}</p>
        @else
            <h1>{{ __('Tu enlace venció') }}</h1>
            <p>{{ __('Por seguridad, el enlace de tu panel vale :days días. Te podemos enviar uno nuevo al correo que registraste con la marca.', ['days' => $days]) }}</p>
            @if ($canResend)
                <form method="post" action="{{ $action }}"><button type="submit">{{ __('Enviarme un enlace nuevo') }}</button></form>
            @else
                <p class="muted">{{ __('No tenemos un correo tuyo registrado. Pídele a la marca un enlace nuevo.') }}</p>
            @endif
        @endif
    </div>
@endsection
