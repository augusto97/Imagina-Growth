@extends('capture.layout')
@section('title', $title)
@section('content')
    <header class="brand">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $business }}" referrerpolicy="no-referrer" width="160" height="48">
        @else
            <span>{{ $business }}</span>
        @endif
    </header>
    <main style="max-width:560px;margin:0 auto">
        <div class="card done" role="status">
            <div class="mark" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>
            <h1 style="font-size:26px">{{ $title }}</h1>
            @if ($message !== '')<p style="color:var(--muted);margin:0 0 6px">{{ $message }}</p>@endif
            @if ($whatsapp)
                <a class="btn wa" href="{{ $whatsapp['url'] }}" rel="noopener">@include('capture._wa_icon'){{ $whatsapp['label'] }}</a>
            @endif
            <p class="legal"><a href="{{ $back }}">{{ __('Volver a la página') }}</a></p>
        </div>
    </main>
@endsection
