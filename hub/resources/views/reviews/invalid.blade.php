@extends('reviews.layout')
@section('title', $expired ? __('Enlace vencido') : __('Enlace no válido'))
@section('content')
    @if ($expired)
        <h1>{{ __('Este enlace ya venció') }}</h1>
        <p class="muted">{{ __('Por seguridad, los enlaces para opinar duran unas semanas. Si quieres contarnos algo, escríbenos por nuestros canales de siempre.') }}</p>
    @else
        <h1>{{ __('No encontramos este enlace') }}</h1>
        <p class="muted">{{ __('Puede que esté incompleto. Ábrelo de nuevo desde el mensaje que te enviamos.') }}</p>
    @endif
@endsection
