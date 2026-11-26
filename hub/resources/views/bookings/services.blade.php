@extends('bookings.layout')
@section('title', __('Agenda tu cita'))
@section('content')
    @include('bookings._steps', ['step' => 1])
    <div class="intro">
        <h1>{{ $settings['page_title'] ?: __('Agenda tu cita con :business', ['business' => $business]) }}</h1>
        <p>{{ $settings['intro'] ?: __('Elige el servicio, el día y la hora que te queden mejor. Te confirmamos al instante.') }}</p>
    </div>
    <h2 class="section-title">{{ __('¿Qué servicio necesitas?') }}</h2>
    <div class="services">
        @foreach ($services as $s)
            <a class="service" href="{{ $s['url'] }}">
                <strong>{{ $s['name'] }}</strong>
                @if ($s['description'])
                    <span class="desc">{{ $s['description'] }}</span>
                @endif
                <ul class="chips">
                    <li>@include('bookings._icon', ['name' => 'clock']){{ __(':min min', ['min' => $s['duration']]) }}</li>
                    <li>@include('bookings._icon', ['name' => $s['location_kind']]){{ $s['location'] }}</li>
                    @if ($s['price'])<li class="price">{{ $s['price'] }}</li>@endif
                </ul>
                <span class="go">{{ __('Ver horarios') }} @include('bookings._icon', ['name' => 'arrow'])</span>
            </a>
        @endforeach
    </div>
@endsection
