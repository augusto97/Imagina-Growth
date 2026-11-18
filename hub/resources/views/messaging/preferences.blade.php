@extends('messaging.layout')
@php($labels = ['email' => __('Correo'), 'whatsapp' => 'WhatsApp', 'sms' => 'SMS'])
@section('title', __('Tus preferencias'))
@section('content')
    <h1>{{ __('Elige qué quieres recibir') }}</h1>
    @if ($saved)
        <p class="ok" role="status">{{ __('Guardamos tus preferencias. Ya están aplicadas.') }}</p>
    @endif
    <form method="post" action="{{ $action }}">
        @if (count($channels) > 0)
            <fieldset>
                <legend>{{ __('Por dónde') }}</legend>
                @foreach ($channels as $channel => $on)
                    <label class="option"><input type="checkbox" name="channels[]" value="{{ $channel }}" @checked($on)> {{ $labels[$channel] ?? $channel }}</label>
                @endforeach
            </fieldset>
        @endif
        <fieldset>
            <legend>{{ __('Sobre qué') }}</legend>
            @foreach ($topics as $key => $label)
                <label class="option"><input type="checkbox" name="topics[]" value="{{ $key }}" @checked(in_array($key, $chosenTopics, true))> {{ __($label) }}</label>
            @endforeach
        </fieldset>
        <fieldset>
            <legend><label for="frequency">{{ __('Con qué frecuencia') }}</label></legend>
            <select id="frequency" name="frequency">
                <option value="normal" @selected($frequency === 'normal')>{{ __('Cuando haya algo para mí') }}</option>
                <option value="weekly" @selected($frequency === 'weekly')>{{ __('Máximo una vez por semana') }}</option>
                <option value="monthly" @selected($frequency === 'monthly')>{{ __('Máximo una vez al mes') }}</option>
            </select>
        </fieldset>
        <div class="row">
            <button type="submit">{{ __('Guardar') }}</button>
            <button type="submit" name="all" value="1" class="secondary">{{ __('Darme de baja de todo') }}</button>
        </div>
    </form>
    <hr>
    <p class="muted">{{ __('Los avisos de tus pedidos (confirmación, envío) te seguirán llegando.') }}</p>
@endsection
