@php
    [$ar, $ag, $ab] = sscanf($accent, '#%02x%02x%02x');
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="{{ $accent }}">
    <title>@yield('title') · {{ $business }}</title>
    <style>
        :root { color-scheme: light; --fg: #0f172a; --muted: #5b6474; --bg: #f5f6f9; --card: #ffffff; --line: #e3e7ee; --line-strong: #c9d0db;
            --accent: {{ $accent }}; --accent-fg: {{ $accentFg }}; --accent-rgb: {{ $ar }}, {{ $ag }}, {{ $ab }};
            --soft: rgba(var(--accent-rgb), .07); --soft-2: rgba(var(--accent-rgb), .14); --ok: #047857; --err: #b91c1c; --wa: #128c3a; }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { min-height: 100dvh; font: 16px/1.5 Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: var(--fg); -webkit-text-size-adjust: 100%;
            background: radial-gradient(1200px 500px at 50% -120px, rgba(var(--accent-rgb), .16), transparent 70%), var(--bg); }
        main { max-width: 600px; margin: 0 auto; padding: 18px 14px 40px; }
        header.brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 6px 0 18px; font-size: 15px; min-height: 36px; }
        header.brand img { max-height: 40px; max-width: 180px; object-fit: contain; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 20px; padding: 22px 18px; box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 12px 32px -12px rgba(15, 23, 42, .12); }
        @media (min-width: 560px) { .card { padding: 30px 30px 26px; } }
        h1 { font-size: 24px; line-height: 1.25; margin: 0 0 6px; letter-spacing: -.01em; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); font-size: 15px; }
        .small { font-size: 13px; }
        a { color: var(--accent); }
        :focus-visible { outline: 3px solid rgba(var(--accent-rgb), .55); outline-offset: 2px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; border-radius: 12px; padding: 12px 20px; font: inherit; font-weight: 600;
            cursor: pointer; background: var(--accent); color: var(--accent-fg); text-decoration: none; min-height: 50px; transition: transform .15s ease, box-shadow .15s ease, opacity .15s; }
        .btn:hover { box-shadow: 0 6px 18px -6px rgba(var(--accent-rgb), .6); }
        .btn:active { transform: translateY(1px); }
        .btn[disabled] { opacity: .6; cursor: progress; }
        .btn.ghost { background: transparent; color: var(--fg); border: 1px solid var(--line-strong); }
        .btn.ghost:hover { box-shadow: none; background: #f8fafc; }
        .btn.wa { background: var(--wa); color: #fff; }
        .block { width: 100%; }
        .sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
        .preview-bar { position: sticky; top: 0; z-index: 2; margin: -18px -14px 14px; padding: 8px 14px; background: #111827; color: #fff; font-size: 13px; text-align: center; }
        footer { margin-top: 18px; text-align: center; color: var(--muted); font-size: 12px; }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
    </style>
    @yield('head')
</head>
<body>
<main>
    @if ($preview)
        <div class="preview-bar" role="note">{{ __('Vista previa: así lo ve tu cliente. Nada de lo que escribas se guarda.') }}</div>
    @endif
    <header class="brand">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $business }}" referrerpolicy="no-referrer">
        @else
            <strong>{{ $business }}</strong>
        @endif
    </header>
    @yield('content')
    <footer>{{ __('Cotizaciones con :brand', ['brand' => $poweredBy]) }}</footer>
</main>
@yield('scripts')
</body>
</html>
