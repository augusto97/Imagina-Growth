<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ $brand }}</title>
    <style>
        :root { color-scheme: light dark; --fg: #111827; --muted: #6b7280; --bg: #f6f7f9; --card: #ffffff; --line: #e5e7eb; --accent: #2563eb; --accent-fg: #ffffff; --star: #f59e0b; --star-off: #d1d5db; --ok: #047857; --err: #b91c1c; --soft: #f3f4f6; }
        @media (prefers-color-scheme: dark) { :root { --fg: #f3f4f6; --muted: #9ca3af; --bg: #0b0f17; --card: #111827; --line: #1f2937; --accent: #60a5fa; --accent-fg: #0b0f17; --star-off: #374151; --ok: #34d399; --err: #f87171; --soft: #1f2937; } }
        * { box-sizing: border-box; }
        body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: var(--bg); color: var(--fg); -webkit-text-size-adjust: 100%; }
        main { max-width: 520px; margin: 0 auto; padding: 20px 16px 40px; }
        header.brand { display: flex; align-items: center; gap: 10px; margin: 4px 0 16px; color: var(--muted); font-size: 14px; }
        header.brand img { max-height: 32px; max-width: 140px; object-fit: contain; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; }
        h1 { font-size: 22px; line-height: 1.25; margin: 0 0 6px; }
        h2 { font-size: 16px; margin: 0 0 8px; }
        p { margin: 0 0 14px; }
        .muted { color: var(--muted); font-size: 14px; }
        .small { font-size: 13px; }
        label.field { display: block; font-weight: 600; font-size: 14px; margin: 16px 0 6px; }
        input[type=text], textarea { width: 100%; font: inherit; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--fg); }
        textarea { min-height: 110px; resize: vertical; }
        button, .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; border-radius: 10px; padding: 13px 18px; font: inherit; font-weight: 600; cursor: pointer; background: var(--accent); color: var(--accent-fg); text-decoration: none; min-height: 48px; }
        .btn.block, button.block { width: 100%; }
        .btn.secondary { background: transparent; color: var(--fg); border: 1px solid var(--line); }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        a { color: var(--accent); }
        .error { color: var(--err); font-size: 14px; margin: 6px 0 0; }
        .alert { border: 1px solid var(--err); color: var(--err); border-radius: 10px; padding: 10px 12px; margin-bottom: 14px; font-size: 14px; }
        .ok { color: var(--ok); font-weight: 600; }
        .sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
        footer { margin-top: 18px; text-align: center; color: var(--muted); font-size: 12px; }
    </style>
    @yield('head')
</head>
<body>
<main>
    <header class="brand">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $brand }}" referrerpolicy="no-referrer">
        @else
            <strong>{{ $brand }}</strong>
        @endif
    </header>
    <div class="card">
        @yield('content')
    </div>
    <footer>{{ __('Formulario seguro de :brand', ['brand' => $brand]) }}</footer>
</main>
@yield('scripts')
</body>
</html>
