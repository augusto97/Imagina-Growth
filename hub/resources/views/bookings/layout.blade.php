<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ $business }}</title>
    <style>
        :root { color-scheme: light; --fg: #0f172a; --muted: #64748b; --bg: #f6f7f9; --card: #ffffff; --line: #e2e8f0; --accent: {{ $accent }}; --accent-fg: #ffffff; --ok: #047857; --err: #b91c1c; --soft: #f1f5f9; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: var(--bg); color: var(--fg); -webkit-text-size-adjust: 100%; }
        main { max-width: 560px; margin: 0 auto; padding: 16px 14px 40px; }
        header.brand { display: flex; align-items: center; gap: 10px; margin: 4px 0 14px; font-size: 15px; }
        header.brand img { max-height: 36px; max-width: 160px; object-fit: contain; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 18px; }
        .card + .card { margin-top: 12px; }
        h1 { font-size: 22px; line-height: 1.25; margin: 0 0 6px; }
        h2 { font-size: 16px; margin: 0 0 10px; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); font-size: 14px; }
        .small { font-size: 13px; }
        .meta { display: flex; flex-wrap: wrap; gap: 6px 14px; color: var(--muted); font-size: 14px; margin: 4px 0 0; padding: 0; list-style: none; }
        label.field { display: block; font-weight: 600; font-size: 14px; margin: 14px 0 6px; }
        input[type=text], input[type=tel], input[type=email], select, textarea { width: 100%; font: inherit; padding: 11px 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--fg); min-height: 46px; }
        textarea { min-height: 80px; resize: vertical; }
        button, .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; border-radius: 10px; padding: 12px 18px; font: inherit; font-weight: 600; cursor: pointer; background: var(--accent); color: var(--accent-fg); text-decoration: none; min-height: 48px; }
        .block { width: 100%; }
        .btn.secondary, button.secondary { background: transparent; color: var(--fg); border: 1px solid var(--line); }
        button.danger { background: transparent; color: var(--err); border: 1px solid var(--line); }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        a { color: var(--accent); }
        .error { color: var(--err); font-size: 14px; margin: 6px 0 0; }
        .alert { border: 1px solid var(--err); color: var(--err); border-radius: 10px; padding: 10px 12px; margin-bottom: 14px; font-size: 14px; }
        .notice { background: var(--soft); border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; }
        .notice.ok { color: var(--ok); font-weight: 600; }
        .days { display: flex; gap: 8px; overflow-x: auto; padding: 2px 2px 8px; margin: 0 -2px; scroll-snap-type: x proximity; }
        .day { flex: none; display: flex; flex-direction: column; align-items: center; justify-content: center; width: 64px; min-height: 64px; border: 1px solid var(--line); border-radius: 12px; text-decoration: none; color: var(--fg); scroll-snap-align: start; font-size: 13px; text-transform: capitalize; }
        .day strong { font-size: 15px; }
        .day[aria-current="date"] { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); color: var(--accent); }
        .slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 8px; margin: 6px 0 0; }
        .slot { position: relative; }
        .slot input { position: absolute; opacity: 0; inset: 0; }
        .slot span { display: flex; align-items: center; justify-content: center; min-height: 44px; border: 1px solid var(--line); border-radius: 10px; font-weight: 600; font-size: 15px; cursor: pointer; }
        .slot input:checked + span { background: var(--accent); color: var(--accent-fg); border-color: var(--accent); }
        .slot input:focus-visible + span { outline: 3px solid var(--accent); outline-offset: 2px; }
        .nav { display: flex; justify-content: space-between; gap: 8px; margin: 4px 0 6px; font-size: 14px; }
        .check { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; color: var(--muted); margin: 12px 0 0; }
        .check input { width: 20px; height: 20px; margin: 1px 0 0; accent-color: var(--accent); flex: none; }
        .row { display: flex; gap: 8px; flex-wrap: wrap; }
        .row > * { flex: 1 1 140px; }
        .service { display: block; text-decoration: none; color: inherit; border: 1px solid var(--line); border-radius: 12px; padding: 14px; margin: 0 0 10px; }
        .service:hover { border-color: var(--accent); }
        .sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
        footer { margin-top: 18px; text-align: center; color: var(--muted); font-size: 12px; }
    </style>
    @yield('head')
</head>
<body>
<main>
    <header class="brand">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $business }}" referrerpolicy="no-referrer">
        @else
            <strong>{{ $business }}</strong>
        @endif
    </header>
    @yield('content')
    <footer>{{ __('Reservas con :brand', ['brand' => $poweredBy]) }}</footer>
</main>
@yield('scripts')
</body>
</html>
