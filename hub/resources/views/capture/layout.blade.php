<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="{{ $indexable ? 'index, follow' : 'noindex, nofollow' }}">
    <meta name="theme-color" content="{{ $accent }}">
    <title>@yield('title') · {{ $business }}</title>
    @hasSection('description')<meta name="description" content="@yield('description')">@endif
    <style>
        :root { color-scheme: light; --fg: #0f172a; --muted: #475569; --bg: #f8fafc; --card: #fff; --line: #dbe2ea; --accent: {{ $accent }}; --accent-fg: {{ $accentFg }}; --err: #b91c1c; --ok: #047857; --radius: 16px; }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body { margin: 0; font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: var(--fg); background: var(--bg);
            background-image: radial-gradient(1200px 520px at 0% -10%, color-mix(in srgb, var(--accent) 16%, transparent), transparent 70%), radial-gradient(900px 480px at 110% 0%, color-mix(in srgb, var(--accent) 10%, transparent), transparent 70%); min-height: 100vh; }
        .wrap { max-width: 1060px; margin: 0 auto; padding: 20px 16px 40px; }
        header.brand { display: flex; align-items: center; gap: 10px; min-height: 44px; margin-bottom: 18px; font-weight: 700; font-size: 17px; }
        header.brand img { max-height: 48px; max-width: 200px; object-fit: contain; }
        .grid { display: grid; gap: 22px; }
        @media (min-width: 880px) { .grid { grid-template-columns: minmax(0, 1.05fr) minmax(0, .95fr); grid-template-areas: "lead form" "media form"; grid-template-rows: auto 1fr; column-gap: 48px; row-gap: 0; align-items: start; }
            .lead { grid-area: lead; } .media { grid-area: media; } .form-col { grid-area: form; position: sticky; top: 20px; } }
        h1 { font-size: clamp(28px, 4.6vw, 44px); line-height: 1.12; letter-spacing: -.02em; margin: 4px 0 14px; }
        .lead p { margin: 0 0 12px; color: var(--muted); font-size: 17px; white-space: pre-line; }
        .hero { width: 100%; height: auto; border-radius: var(--radius); aspect-ratio: 16 / 10; object-fit: cover; background: #e2e8f0; display: block; }
        @media (min-width: 880px) { .hero { margin-top: 10px; } }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: var(--radius); padding: 22px 18px; box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 12px 32px -12px rgba(15, 23, 42, .18); }
        @media (min-width: 480px) { .card { padding: 26px 24px; } }
        .card h2 { font-size: 19px; margin: 0 0 4px; }
        label.field { display: block; font-weight: 600; font-size: 15px; margin: 16px 0 6px; }
        form label.field:first-of-type { margin-top: 0; }
        .req { color: var(--muted); font-weight: 400; font-size: 13px; }
        input[type=text], input[type=tel], input[type=email], select, textarea { width: 100%; font: inherit; padding: 12px 14px; min-height: 48px; border-radius: 12px; border: 1px solid #c5cfdb; background: #fff; color: var(--fg); }
        textarea { min-height: 96px; resize: vertical; }
        input:focus, select:focus, textarea:focus { outline: 3px solid color-mix(in srgb, var(--accent) 45%, transparent); outline-offset: 1px; border-color: var(--accent); }
        [aria-invalid="true"] { border-color: var(--err) !important; }
        .error { color: var(--err); font-size: 14px; margin: 6px 0 0; }
        .alert:focus { outline: 2px solid var(--err); outline-offset: 2px; }
        .alert { border: 1px solid var(--err); background: #fef2f2; color: var(--err); border-radius: 12px; padding: 10px 12px; margin: 0 0 6px; font-size: 15px; }
        .check { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; color: var(--muted); margin: 10px 0 0; line-height: 1.45; }
        .check input { width: 20px; height: 20px; margin: 1px 0 0; accent-color: var(--accent); flex: none; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; width: 100%; min-height: 52px; border: 0; border-radius: 12px; padding: 12px 18px; font: inherit; font-weight: 700; font-size: 17px; cursor: pointer; text-decoration: none; background: var(--accent); color: var(--accent-fg); }
        .btn:focus-visible, a:focus-visible { outline: 3px solid var(--fg); outline-offset: 2px; }
        .btn.wa { background: #fff; color: #075e54; border: 1px solid #b7e4cf; margin-top: 12px; }
        .btn.wa svg { width: 22px; height: 22px; flex: none; }
        .or { text-align: center; color: var(--muted); font-size: 14px; margin: 14px 0 0; }
        .legal { color: var(--muted); font-size: 13px; margin: 16px 0 0; }
        .legal a, footer a { color: inherit; }
        .hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
        .notice { background: color-mix(in srgb, var(--accent) 10%, #fff); border: 1px solid color-mix(in srgb, var(--accent) 30%, #fff); border-radius: 12px; padding: 10px 12px; font-size: 14px; margin-bottom: 14px; }
        .done { text-align: center; padding: 34px 22px; }
        .done .mark { width: 56px; height: 56px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; background: color-mix(in srgb, var(--accent) 14%, #fff); color: var(--accent); margin-bottom: 8px; }
        footer { margin-top: 28px; text-align: center; color: var(--muted); font-size: 12px; }
        .sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        @media (prefers-reduced-motion: no-preference) { .btn { transition: filter .15s; } .btn:hover { filter: brightness(.95); } }
    </style>
</head>
<body>
<div class="wrap">
    @yield('content')
    <footer>{{ __('Hecho con :brand', ['brand' => $poweredBy]) }}</footer>
</div>
@yield('scripts')
</body>
</html>
