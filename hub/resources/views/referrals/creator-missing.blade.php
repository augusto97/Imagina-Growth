@extends('referrals.layout')
@section('title', __('Enlace no válido'))
@section('content')
    <div class="card">
        <h1>{{ __('Este enlace ya no funciona') }}</h1>
        <p>{{ __('Puede que esté incompleto o que te hayan enviado uno más nuevo. Pídele a la marca que te envíe el enlace de tu panel otra vez.') }}</p>
    </div>
@endsection
