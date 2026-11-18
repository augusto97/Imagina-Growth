<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') · {{ $brand ?? 'Imagina Growth' }}</title>
    <style>
        :root { color-scheme: light dark; --fg: #111827; --muted: #6b7280; --bg: #f9fafb; --card: #ffffff; --line: #e5e7eb; --accent: #2563eb; --ok: #047857; --warn: #b45309; --soft: #f3f4f6; }
        @media (prefers-color-scheme: dark) { :root { --fg: #f3f4f6; --muted: #9ca3af; --bg: #0b0f17; --card: #111827; --line: #1f2937; --accent: #60a5fa; --ok: #34d399; --warn: #fbbf24; --soft: #0f1623; } }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: var(--bg); color: var(--fg); }
        main { max-width: 760px; margin: 32px auto; padding: 0 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 24px; margin-bottom: 16px; }
        .brand { font-size: 13px; color: var(--muted); margin: 0 0 6px; letter-spacing: .02em; }
        h1 { font-size: 22px; margin: 0 0 6px; line-height: 1.3; }
        h2 { font-size: 15px; margin: 0 0 12px; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); font-size: 13px; }
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
        .kpi { border: 1px solid var(--line); border-radius: 10px; padding: 14px; }
        .kpi .label { color: var(--muted); font-size: 12px; margin: 0 0 4px; }
        .kpi .value { font-size: 20px; font-weight: 600; margin: 0; font-variant-numeric: tabular-nums; }
        .kpi .sub { color: var(--muted); font-size: 12px; margin: 2px 0 0; }
        .copy { display: flex; gap: 8px; margin-bottom: 8px; }
        .copy input { flex: 1; min-width: 0; font: 13px ui-monospace, SFMono-Regular, Menlo, monospace; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--line); background: var(--soft); color: var(--fg); }
        button, .btn { display: inline-block; border: 0; border-radius: 8px; padding: 8px 14px; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; background: var(--accent); color: #fff; text-decoration: none; }
        button.secondary { background: transparent; color: var(--fg); border: 1px solid var(--line); }
        button:focus-visible, a:focus-visible, input:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        a { color: var(--accent); }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip { font: 13px ui-monospace, SFMono-Regular, Menlo, monospace; border: 1px solid var(--line); border-radius: 999px; padding: 4px 10px; background: var(--soft); }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; color: var(--muted); font-weight: 500; padding: 6px 8px; border-bottom: 1px solid var(--line); }
        td { padding: 8px; border-bottom: 1px solid var(--line); }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .status { font-size: 12px; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--line); white-space: nowrap; }
        .status.approved, .status.paid { color: var(--ok); }
        .status.pending { color: var(--warn); }
        .status.void { color: var(--muted); text-decoration: line-through; }
        .scroll { overflow-x: auto; }
        ul.plain { list-style: none; padding: 0; margin: 0; }
        ul.plain li { padding: 6px 0; border-bottom: 1px solid var(--line); }
        ul.plain li:last-child { border-bottom: 0; }
    </style>
</head>
<body>
<main>
    @yield('content')
    <p class="muted" style="text-align:center">{{ __('Panel del creador de :brand', ['brand' => $brand ?? 'Imagina Growth']) }}</p>
</main>
</body>
</html>
