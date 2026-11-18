@extends('bookings.layout')
@section('title', __('Agenda tu cita'))
@section('content')
    <div class="card">
        <h1>{{ $settings['page_title'] ?: __('Agenda tu cita con :business', ['business' => $business]) }}</h1>
        @if ($settings['intro'])
            <p class="muted">{{ $settings['intro'] }}</p>
        @endif
        <h2>{{ __('¿Qué servicio necesitas?') }}</h2>
        @foreach ($services as $s)
            <a class="service" href="{{ $s['url'] }}">
                <strong>{{ $s['name'] }}</strong>
                @if ($s['description'])
                    <div class="muted">{{ \Illuminate\Support\Str::limit($s['description'], 140) }}</div>
                @endif
                <ul class="meta">
                    <li>{{ __(':min min', ['min' => $s['duration']]) }}</li>
                    <li>{{ $s['location'] }}</li>
                    @if ($s['price'])<li>{{ $s['price'] }}</li>@endif
                </ul>
            </a>
        @endforeach
    </div>
@endsection
