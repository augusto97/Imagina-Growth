@extends('messaging.layout')
@php($channelLabel = ['email' => __('correo'), 'whatsapp' => 'WhatsApp', 'sms' => 'SMS'][$channel] ?? $channel)
@section('title', __('Baja confirmada'))
@section('content')
    <h1 class="ok" role="status">{{ __('Listo, te diste de baja') }}</h1>
    <p>{{ $all ? __('No te enviaremos más promociones por ningún medio.') : __('No te enviaremos más promociones por :channel.', ['channel' => $channelLabel]) }}</p>
    <p class="muted">{{ __('El cambio ya está aplicado. Si fue un error, puedes volver a suscribirte en tus preferencias.') }}</p>
    <p><a href="{{ $preferences }}">{{ __('Ver mis preferencias') }}</a></p>
@endsection
