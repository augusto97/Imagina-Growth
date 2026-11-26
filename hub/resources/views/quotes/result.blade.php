@extends('quotes.layout')

@section('title', $name)

@section('head')
    <style>
        .result { text-align: center; }
        .badge { width: 56px; height: 56px; margin: 4px auto 14px; border-radius: 50%; display: grid; place-items: center; background: var(--soft-2); color: var(--accent);
            animation: pop .5s cubic-bezier(.2, .9, .3, 1.3) both; }
        .badge svg { width: 28px; height: 28px; }
        .label { color: var(--muted); font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; margin: 0 0 6px; }
        .amount { font-size: clamp(28px, 8vw, 40px); line-height: 1.15; font-weight: 750; letter-spacing: -.02em; font-variant-numeric: tabular-nums; margin: 0 0 6px;
            animation: reveal .7s .15s ease both; }
        .for { color: var(--muted); margin: 0 0 18px; animation: fade .5s .35s ease both; }
        .incl { list-style: none; margin: 0 0 18px; padding: 0; display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; animation: fade .5s .45s ease both; }
        .incl li { padding: 5px 11px; border-radius: 99px; background: var(--soft); font-size: 13px; }
        .msg { background: #f8fafc; border: 1px solid var(--line); border-radius: 14px; padding: 12px 14px; font-size: 14px; color: var(--fg); text-align: left; margin: 0 0 18px;
            animation: fade .5s .55s ease both; }
        .msg p:last-child { margin: 0; }
        .actions { display: grid; gap: 10px; animation: fade .5s .65s ease both; }
        .ref { margin: 14px 0 0; color: var(--muted); font-size: 12px; }
        @keyframes pop { from { transform: scale(.4); opacity: 0; } to { transform: none; opacity: 1; } }
        @keyframes reveal { from { opacity: 0; filter: blur(8px); transform: translateY(8px); } to { opacity: 1; filter: none; transform: none; } }
        @keyframes fade { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    </style>
@endsection

@section('content')
    <div class="card result" role="status">
        <div class="badge" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <p class="label">{{ __('Tu precio estimado') }}</p>
        <p class="amount">{{ $range }}</p>
        <p class="for">{{ $name }}</p>
        @if ($lines !== [])
            <ul class="incl" aria-label="{{ __('Incluye') }}">
                @foreach ($lines as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        @endif
        <div class="msg">
            <p>{{ $message }}</p>
            @if ($note)
                <p class="small" style="color:var(--muted)">{{ $note }}</p>
            @endif
        </div>
        <div class="actions">
            @if ($whatsapp)
                <a class="btn wa block" href="{{ $whatsapp }}" target="_blank" rel="noopener">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z"/></svg>
                    {{ __('Hablar por WhatsApp') }}
                </a>
            @endif
            <a class="btn ghost block" href="{{ $again }}">{{ __('Cotizar de nuevo') }}</a>
        </div>
        @if ($reference)
            <p class="ref">{{ __('Referencia: :ref', ['ref' => $reference]) }}</p>
        @endif
    </div>
@endsection
