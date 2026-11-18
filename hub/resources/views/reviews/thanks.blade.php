@extends('reviews.layout')
@section('title', __('¡Gracias!'))
@section('content')
    <h1>{{ __('¡Gracias por tu reseña!') }}</h1>
    @if ($review !== null)
        <p class="muted">
            @if ($published)
                {{ __('Ya está publicada.') }}
            @else
                {{ __('La revisaremos y la publicaremos pronto.') }}
            @endif
        </p>
        @if ($points > 0)
            <p>{{ $published ? __('Sumaste :points puntos.', ['points' => $points]) : __('Tienes :points puntos en camino: se suman cuando la publiquemos.', ['points' => $points]) }}</p>
        @endif
    @endif

    @if ($google)
        <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">
        <h2>{{ __('¿Nos ayudas también en Google?') }}</h2>
        <p class="muted">{{ __('Tu opinión en Google ayuda a que más personas nos encuentren.') }}</p>
        <a class="btn block" href="{{ $google }}" target="_blank" rel="noopener noreferrer">{{ __('Dejar una reseña en Google') }}</a>
    @endif

    @if (count($pending) > 0)
        <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">
        <h2>{{ __('¿Calificas algo más de tu pedido?') }}</h2>
        @foreach ($pending as $p)
            <p style="margin-bottom:8px"><a class="btn secondary block" href="{{ $formUrl }}?p={{ urlencode($p['id']) }}">{{ $p['name'] }}</a></p>
        @endforeach
    @endif
@endsection
