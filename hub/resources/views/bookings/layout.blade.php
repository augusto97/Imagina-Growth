<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="{{ $accent }}">
    <title>@yield('title') · {{ $business }}</title>
    {{-- Página pública de reservas (M18): CSS propio y pequeño, sin fuentes ni librerías externas; se ve bien sin JavaScript. --}}
    <style>
        :root { color-scheme: light; --fg: #0f172a; --muted: #5b6678; --bg: #f3f5f8; --card: #fff; --line: #e3e8ef; --line-strong: #cbd4df;
            --accent: {{ $accent }}; --accent-fg: {{ $accentFg }}; --accent-soft: color-mix(in srgb, var(--accent) 9%, #fff); --accent-line: color-mix(in srgb, var(--accent) 38%, #fff);
            --ok: #047857; --ok-soft: #ecfdf5; --err: #b91c1c; --err-soft: #fef2f2; --radius: 16px;
            --shadow: 0 1px 2px rgba(15, 23, 42, .05), 0 10px 28px -16px rgba(15, 23, 42, .22); }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: var(--bg); color: var(--fg); min-height: 100vh; }
        a { color: var(--accent); }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        .sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        svg.i { width: 18px; height: 18px; flex: none; }

        /* Cabecera con la marca */
        .top { background: var(--card); border-bottom: 1px solid var(--line); box-shadow: inset 0 3px 0 var(--accent); }
        .top-in { max-width: 1040px; margin: 0 auto; padding: 14px 16px 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .brand { display: flex; align-items: center; gap: 10px; min-width: 0; color: var(--fg); text-decoration: none; font-weight: 700; font-size: 16px; }
        .brand img { max-height: 40px; max-width: 170px; object-fit: contain; }
        .brand .mono { width: 36px; height: 36px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: var(--accent); color: var(--accent-fg); font-size: 16px; flex: none; }
        .brand .name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .zone { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); font-size: 13px; white-space: nowrap; }
        @media (max-width: 420px) { .zone span { display: none; } }

        main { max-width: 1040px; margin: 0 auto; padding: 18px 14px 40px; }
        @media (min-width: 640px) { main { padding: 26px 16px 48px; } }

        /* Pasos */
        .steps { display: flex; gap: 6px; list-style: none; padding: 0; margin: 0 0 18px; counter-reset: s; }
        .steps li { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; gap: 6px; font-size: 12px; color: var(--muted); }
        .steps li::before { content: ""; height: 4px; border-radius: 999px; background: var(--line); }
        .steps li.done::before, .steps li.now::before { background: var(--accent); }
        .steps li.now { color: var(--fg); font-weight: 600; }
        .steps li span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        @media (min-width: 640px) { .steps li { font-size: 13px; } }

        .section-title { font-size: 16px; margin: 0 0 10px; }
        .intro h1 { font-size: clamp(24px, 4.2vw, 32px); line-height: 1.15; letter-spacing: -.02em; margin: 0 0 6px; }
        .intro { margin: 0 0 16px; }
        .intro p { color: var(--muted); margin: 0 0 6px; max-width: 62ch; white-space: pre-line; }

        /* Columnas: lo que se elige a la izquierda y el resumen a la derecha (arriba en el celular) */
        .layout { display: grid; gap: 14px; grid-template-areas: "sum" "main"; }
        .layout > .main { grid-area: main; min-width: 0; display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; align-content: start; }
        .layout > .sum { grid-area: sum; min-width: 0; }
        @media (min-width: 900px) { .layout { grid-template-columns: minmax(0, 1fr) 320px; grid-template-areas: "main sum"; gap: 20px; align-items: start; }
            .layout > .sum { position: sticky; top: 16px; } }

        .card { background: var(--card); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px 16px; box-shadow: var(--shadow); }
        @media (min-width: 640px) { .card { padding: 22px 22px; } }
        .card h2 { font-size: 17px; margin: 0 0 4px; display: flex; align-items: center; gap: 10px; }
        .card h2 .n { width: 26px; height: 26px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; background: var(--accent-soft); color: var(--accent); font-size: 13px; font-weight: 700; flex: none; }
        .card .lead { color: var(--muted); font-size: 14px; margin: 0 0 14px; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); }
        .small { font-size: 13px; }

        .chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0 0; padding: 0; list-style: none; }
        .chips li { display: inline-flex; align-items: center; gap: 6px; background: var(--bg); border: 1px solid var(--line); border-radius: 999px; padding: 4px 10px; font-size: 13px; color: #334155; }
        .chips svg.i { width: 15px; height: 15px; color: var(--muted); }
        .chips li.price { background: var(--accent-soft); border-color: var(--accent-line); color: var(--fg); font-weight: 600; }

        /* Servicios */
        .services { display: grid; gap: 12px; }
        @media (min-width: 700px) { .services { grid-template-columns: 1fr 1fr; } }
        .service { display: flex; flex-direction: column; gap: 4px; text-decoration: none; color: inherit; background: var(--card); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; box-shadow: var(--shadow); transition: border-color .15s, transform .15s; }
        .service:hover { border-color: var(--accent-line); }
        .service strong { font-size: 17px; }
        .service .desc { color: var(--muted); font-size: 14px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
        .service .go { margin-top: auto; padding-top: 12px; display: inline-flex; align-items: center; gap: 6px; color: var(--accent); font-weight: 600; font-size: 15px; }

        /* Resumen */
        .sum .card { padding: 16px; }
        .sum h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin: 0 0 6px; }
        .sum .svc { font-size: 17px; font-weight: 700; margin: 0; }
        .sum .desc { color: var(--muted); font-size: 14px; margin: 8px 0 0; white-space: pre-line; }
        .when { margin: 14px 0 0; padding: 12px; border-radius: 12px; background: var(--bg); border: 1px dashed var(--line-strong); display: flex; gap: 10px; align-items: flex-start; font-size: 14px; }
        .when.set { background: var(--accent-soft); border: 1px solid var(--accent-line); }
        .when strong { display: block; font-size: 15px; }
        .back { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; margin: 0 0 12px; text-decoration: none; font-weight: 600; }

        /* Selector de día y hora */
        .field-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 8px; margin: 0 0 14px; }
        .field-row > div { flex: 1 1 220px; }
        .cal-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin: 4px 0 8px; }
        .cal-head strong { font-size: 15px; }
        .arrows { display: flex; gap: 6px; }
        .arrow { width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--line); display: inline-flex; align-items: center; justify-content: center; color: var(--fg); background: var(--card); text-decoration: none; }
        .arrow[aria-disabled="true"] { opacity: .35; pointer-events: none; }
        .days { display: grid; grid-auto-flow: column; grid-auto-columns: 64px; gap: 8px; overflow-x: auto; padding: 2px 2px 10px; margin: 0 -2px; scroll-snap-type: x proximity; scrollbar-width: thin; }
        .day { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1px; min-height: 78px; border: 1px solid var(--line); border-radius: 14px; text-decoration: none; color: var(--fg); scroll-snap-align: start; background: var(--card); }
        .day:hover { border-color: var(--accent-line); }
        .day small { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
        .day strong { font-size: 21px; line-height: 1.1; }
        .day em { font-style: normal; font-size: 11px; color: var(--muted); }
        .day[aria-current="date"] { background: var(--accent); border-color: var(--accent); color: var(--accent-fg); }
        .day[aria-current="date"] small, .day[aria-current="date"] em { color: inherit; opacity: .85; }
        .empty { text-align: center; padding: 18px 10px; color: var(--muted); background: var(--bg); border-radius: 12px; }
        fieldset.slots { border: 0; padding: 0; margin: 10px 0 0; min-width: 0; }
        fieldset.slots legend { font-weight: 700; font-size: 16px; padding: 0; margin: 0 0 2px; }
        .period { margin: 12px 0 0; }
        .period h4 { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--muted); margin: 0 0 8px; }
        .grid-slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 8px; }
        .slot { position: relative; }
        .slot input { position: absolute; opacity: 0; inset: 0; margin: 0; }
        .slot span { display: flex; align-items: center; justify-content: center; min-height: 48px; border: 1px solid var(--line-strong); border-radius: 12px; font-weight: 600; font-size: 15px; cursor: pointer; background: var(--card); transition: background .12s, border-color .12s; }
        .slot span:hover { border-color: var(--accent); }
        .slot input:checked + span { background: var(--accent); color: var(--accent-fg); border-color: var(--accent); box-shadow: 0 6px 16px -8px var(--accent); }
        .slot input:focus-visible + span { outline: 3px solid var(--accent); outline-offset: 2px; }

        /* Formulario */
        label.field { display: block; font-weight: 600; font-size: 14px; margin: 14px 0 6px; }
        label.field .opt { color: var(--muted); font-weight: 400; }
        input[type=text], input[type=tel], input[type=email], select, textarea { width: 100%; font: inherit; padding: 12px 14px; border-radius: 12px; border: 1px solid var(--line-strong); background: var(--card); color: var(--fg); min-height: 48px; }
        input:focus, select:focus, textarea:focus { outline: 3px solid color-mix(in srgb, var(--accent) 35%, transparent); outline-offset: 1px; border-color: var(--accent); }
        [aria-invalid="true"] { border-color: var(--err) !important; background: var(--err-soft); }
        textarea { min-height: 88px; resize: vertical; }
        .two { display: grid; gap: 0 12px; }
        @media (min-width: 640px) { .two { grid-template-columns: 1fr 1fr; } }
        .error { color: var(--err); font-size: 14px; margin: 6px 0 0; display: flex; gap: 6px; align-items: flex-start; }
        .alert { display: flex; gap: 10px; align-items: flex-start; border: 1px solid #fecaca; background: var(--err-soft); color: var(--err); border-radius: 12px; padding: 11px 12px; margin: 0 0 12px; font-size: 14px; }
        .consents { margin: 16px 0 0; padding: 12px 14px; border-radius: 12px; background: var(--bg); }
        .consents p { margin: 0 0 4px; font-size: 13px; font-weight: 600; }
        .check { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; color: var(--muted); margin: 8px 0 0; line-height: 1.45; }
        .check input { width: 20px; height: 20px; margin: 1px 0 0; accent-color: var(--accent); flex: none; }
        button, .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; border-radius: 12px; padding: 12px 18px; font: inherit; font-weight: 700; cursor: pointer; background: var(--accent); color: var(--accent-fg); text-decoration: none; min-height: 50px; }
        button:disabled { opacity: .55; cursor: not-allowed; }
        .block { width: 100%; }
        .btn.secondary, button.secondary { background: var(--card); color: var(--fg); border: 1px solid var(--line-strong); font-weight: 600; }
        .btn.wa { background: #fff; color: #075e54; border: 1px solid #b7e4cf; font-weight: 600; }
        button.danger { background: transparent; color: var(--err); border: 1px solid #fecaca; font-weight: 600; }
        .submit { margin-top: 18px; }
        .submit .note { display: flex; gap: 8px; align-items: flex-start; color: var(--muted); font-size: 13px; margin: 10px 0 0; }
        .row { display: flex; gap: 8px; flex-wrap: wrap; }
        .row > * { flex: 1 1 160px; }
        .row form { display: flex; }
        .row form button { flex: 1; }

        /* Confirmación */
        .hero { text-align: center; padding: 8px 6px 18px; }
        .hero .mark { width: 64px; height: 64px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; background: var(--ok-soft); color: var(--ok); margin-bottom: 10px; box-shadow: 0 0 0 8px color-mix(in srgb, var(--ok) 8%, transparent); }
        .hero .mark svg.i { width: 30px; height: 30px; stroke-width: 2.6; }
        .hero .mark.neutral { background: var(--bg); color: var(--muted); box-shadow: none; }
        .hero h1 { font-size: clamp(22px, 4vw, 28px); line-height: 1.2; margin: 0 0 6px; letter-spacing: -.01em; }
        .hero p { color: var(--muted); margin: 0 auto; max-width: 48ch; }
        .ticket { display: grid; grid-template-columns: auto 1fr; gap: 16px; align-items: center; }
        .date { width: 76px; border-radius: 14px; overflow: hidden; border: 1px solid var(--line); text-align: center; background: var(--card); }
        .date small { display: block; background: var(--accent); color: var(--accent-fg); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; padding: 4px 0; }
        .date strong { display: block; font-size: 28px; line-height: 1.2; padding: 4px 0 0; }
        .date span { display: block; font-size: 12px; color: var(--muted); padding: 0 0 6px; text-transform: capitalize; }
        .ticket h2 { font-size: 19px; margin: 0; display: block; }
        .ticket .time { font-size: 16px; margin: 2px 0 0; }
        .badge { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 3px 10px; font-size: 12px; font-weight: 700; background: var(--bg); color: var(--muted); border: 1px solid var(--line); margin-top: 6px; }
        .badge.ok { background: var(--ok-soft); color: var(--ok); border-color: #a7f3d0; }
        .badge.off { background: var(--err-soft); color: var(--err); border-color: #fecaca; }
        .details { list-style: none; padding: 0; margin: 16px 0 0; display: grid; gap: 8px; border-top: 1px solid var(--line); padding-top: 14px; font-size: 14px; }
        .details li { display: flex; gap: 10px; align-items: center; }
        .details svg.i { color: var(--muted); }
        .actions h3 { font-size: 14px; margin: 0 0 10px; }
        .actions + .actions { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--line); }
        .notice { display: flex; gap: 10px; align-items: flex-start; background: var(--accent-soft); border: 1px solid var(--accent-line); border-radius: 12px; padding: 11px 12px; margin-bottom: 14px; font-size: 14px; }
        .center { max-width: 620px; margin: 0 auto; display: grid; gap: 14px; }
        footer { margin-top: 26px; text-align: center; color: var(--muted); font-size: 12px; }
        @media (prefers-reduced-motion: no-preference) { button, .btn { transition: filter .15s; } button:hover:not(:disabled), .btn:hover { filter: brightness(.96); } }
    </style>
    @yield('head')
</head>
<body>
<header class="top">
    <div class="top-in">
        <span class="brand">
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $business }}" referrerpolicy="no-referrer">
            @else
                <span class="mono" aria-hidden="true">{{ $initial }}</span><span class="name">{{ $business }}</span>
            @endif
        </span>
        <span class="zone" title="{{ __('Horas de :zone', ['zone' => $zone]) }}">@include('bookings._icon', ['name' => 'globe'])<span>{{ __('Hora de :zone', ['zone' => $zone]) }}</span></span>
    </div>
</header>
<main>
    @yield('content')
    <footer>{{ __('Reservas con :brand', ['brand' => $poweredBy]) }}</footer>
</main>
@yield('scripts')
</body>
</html>
