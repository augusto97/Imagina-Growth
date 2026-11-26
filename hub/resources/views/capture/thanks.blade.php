@extends('capture.layout')
@section('title', $title)
@section('content')
    <div class="wrap">
        <header class="brand" style="justify-content:center">
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $business }}" referrerpolicy="no-referrer" width="160" height="48">
            @else
                <span>{{ $business }}</span>
            @endif
        </header>
        <main style="max-width:560px;margin:12px auto 0">
            @if ($preview ?? false)<p class="notice" role="status">{{ __('Vista previa: así se verá la página cuando la publiques. Los envíos de esta vista no se guardan.') }}</p>@endif
            <div class="card done" role="status">
                <div class="mark" aria-hidden="true"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>
                <h1>{{ $title }}</h1>
                @if ($message !== '')<p style="color:var(--muted);margin:0 0 6px;white-space:pre-line">{{ $message }}</p>@endif
                @if ($whatsapp)
                    <a class="btn wa" href="{{ $whatsapp['url'] }}" rel="noopener" style="margin-top:18px">@include('capture._wa_icon'){{ $whatsapp['label'] }}</a>
                @endif
                <p class="legal"><a href="{{ $back }}">{{ __('Volver a la página') }}</a></p>
            </div>
        </main>
    </div>
@endsection
