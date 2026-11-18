@extends('messaging.layout')
@php($channelLabel = ['email' => __('correo'), 'whatsapp' => 'WhatsApp', 'sms' => 'SMS'][$channel] ?? $channel)
@section('title', __('Darse de baja'))
@section('content')
    <h1>{{ __('¿Quieres dejar de recibir promociones por :channel?', ['channel' => $channelLabel]) }}</h1>
    <p>{{ __('Seguirás recibiendo los avisos de tus pedidos. Puedes volver a suscribirte cuando quieras.') }}</p>
    <form method="post" action="{{ $action }}">
        <div class="row">
            <button type="submit">{{ __('Darme de baja') }}</button>
            <button type="submit" name="all" value="1" class="secondary">{{ __('Darme de baja de todo') }}</button>
        </div>
    </form>
    <hr>
    <p class="muted"><a href="{{ $preferences }}">{{ __('Prefiero elegir qué recibir y con qué frecuencia') }}</a></p>
@endsection
