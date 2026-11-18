@extends('messaging.layout')
@section('title', __('Enlace no válido'))
@section('content')
    <h1>{{ __('Este enlace ya no funciona') }}</h1>
    <p>{{ __('Puede que esté incompleto o que haya vencido. Si querías darte de baja, responde «baja» al mensaje o escríbele al negocio.') }}</p>
@endsection
