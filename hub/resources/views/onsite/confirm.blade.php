@extends('messaging.layout')
@section('title', $state === 'invalid' ? __('Enlace no válido') : __('Suscripción confirmada'))
@section('content')
    @if ($state === 'confirmed')
        <h1 class="ok" role="status">{{ __('¡Listo! Confirmaste tu suscripción') }}</h1>
        <p>{{ __('Desde ahora recibirás nuestras ofertas y novedades por correo. Puedes darte de baja cuando quieras desde cualquier correo.') }}</p>
    @elseif ($state === 'already')
        <h1 class="ok" role="status">{{ __('Tu suscripción ya estaba confirmada') }}</h1>
        <p>{{ __('No tienes que hacer nada más. Puedes darte de baja cuando quieras desde cualquier correo.') }}</p>
    @else
        <h1>{{ __('Este enlace ya no funciona') }}</h1>
        <p>{{ __('Puede que esté incompleto o que haya vencido. Vuelve a suscribirte en la tienda y te enviaremos un enlace nuevo.') }}</p>
    @endif
    @if (! empty($shop))
        <p><a class="btn" href="{{ $shop }}">{{ __('Volver a la tienda') }}</a></p>
    @endif
@endsection
