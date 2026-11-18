<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ $brand ?? 'Imagina Growth' }}</title>
    <style>
        :root { color-scheme: light dark; --fg: #111827; --muted: #6b7280; --bg: #f9fafb; --card: #ffffff; --line: #e5e7eb; --accent: #2563eb; --ok: #047857; }
        @media (prefers-color-scheme: dark) { :root { --fg: #f3f4f6; --muted: #9ca3af; --bg: #0b0f17; --card: #111827; --line: #1f2937; --accent: #60a5fa; --ok: #34d399; } }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: var(--bg); color: var(--fg); }
        main { max-width: 480px; margin: 48px auto; padding: 0 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 28px; }
        .brand { font-size: 13px; color: var(--muted); margin: 0 0 8px; letter-spacing: .02em; }
        h1 { font-size: 20px; margin: 0 0 12px; line-height: 1.3; }
        p { margin: 0 0 16px; }
        .muted { color: var(--muted); font-size: 13px; }
        button, .btn { display: inline-block; border: 0; border-radius: 8px; padding: 10px 16px; font: inherit; font-weight: 600; cursor: pointer; background: var(--accent); color: #fff; text-decoration: none; }
        button.secondary { background: transparent; color: var(--fg); border: 1px solid var(--line); }
        button:focus-visible, a:focus-visible, input:focus-visible, select:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        a { color: var(--accent); }
        fieldset { border: 0; padding: 0; margin: 0 0 20px; }
        legend { font-weight: 600; margin-bottom: 8px; }
        label.option { display: flex; gap: 10px; align-items: center; padding: 8px 0; }
        select { font: inherit; padding: 8px; border-radius: 8px; border: 1px solid var(--line); background: var(--card); color: var(--fg); }
        .ok { color: var(--ok); font-weight: 600; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        hr { border: 0; border-top: 1px solid var(--line); margin: 20px 0; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <p class="brand">{{ $brand ?? 'Imagina Growth' }}</p>
        @yield('content')
    </div>
</main>
</body>
</html>
