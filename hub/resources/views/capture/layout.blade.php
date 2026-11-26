<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="{{ $indexable ? 'index, follow' : 'noindex, nofollow' }}">
    <meta name="theme-color" content="{{ $accent }}">
    <title>@yield('title') · {{ $business }}</title>
    @hasSection('description')<meta name="description" content="@yield('description')">@endif
    {{-- Página de captura (M12): CSS propio en línea, sin fuentes ni librerías externas; tres diseños (`t-split`, `t-hero`, `t-centered`). --}}
    <style>
        :root { color-scheme: light; --fg: #0f172a; --muted: #475569; --bg: #f8fafc; --card: #fff; --line: #dbe2ea; --field: #c5cfdb; --accent: {{ $accent }}; --accent-fg: {{ $accentFg }};
            --accent-soft: color-mix(in srgb, var(--accent) 10%, #fff); --accent-line: color-mix(in srgb, var(--accent) 30%, #fff); --err: #b91c1c; --ok: #047857; --radius: 18px;
            --shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 16px 40px -18px rgba(15, 23, 42, .28); }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
        body { margin: 0; font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: var(--fg); background: var(--bg); min-height: 100vh; }
        img { max-width: 100%; }
        a { color: var(--accent); }
        .sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        svg.i { width: 20px; height: 20px; flex: none; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 18px; }

        /* Marca */
        .brand { display: flex; align-items: center; gap: 10px; min-height: 64px; font-weight: 700; font-size: 17px; }
        .brand img { max-height: 44px; max-width: 200px; object-fit: contain; }
        .notice { background: color-mix(in srgb, var(--accent) 10%, #fff); color: var(--fg); border: 1px solid var(--accent-line); border-radius: 12px; padding: 10px 12px; font-size: 14px; margin: 0 0 14px; }

        /* Primer bloque: el título, lo que obtiene la persona y el formulario */
        .hero { position: relative; padding: 8px 0 36px; }
        .t-split .hero, .t-centered .hero { background-image: radial-gradient(1200px 520px at 0% -10%, color-mix(in srgb, var(--accent) 16%, transparent), transparent 70%), radial-gradient(900px 480px at 110% 0%, color-mix(in srgb, var(--accent) 10%, transparent), transparent 70%); }
        .hero-in { display: grid; gap: 24px; }
        .eyebrow { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; background: var(--accent-soft); color: color-mix(in srgb, var(--accent) 70%, #0f172a); border: 1px solid var(--accent-line); font-size: 13px; font-weight: 700; letter-spacing: .01em; margin: 6px 0 12px; }
        h1 { font-size: clamp(30px, 5vw, 50px); line-height: 1.08; letter-spacing: -.025em; margin: 0 0 14px; }
        .lead p { margin: 0 0 12px; color: var(--muted); font-size: 18px; white-space: pre-line; max-width: 60ch; }
        .benefits { list-style: none; padding: 0; margin: 18px 0 0; display: grid; gap: 10px; }
        .benefits li { display: flex; gap: 10px; align-items: flex-start; font-size: 16px; font-weight: 500; }
        .benefits .tick { width: 24px; height: 24px; border-radius: 999px; background: var(--accent); color: var(--accent-fg); display: inline-flex; align-items: center; justify-content: center; flex: none; margin-top: 1px; }
        .benefits .tick svg.i { width: 14px; height: 14px; stroke-width: 3; }
        .media img { width: 100%; height: auto; border-radius: var(--radius); aspect-ratio: 16 / 10; object-fit: cover; background: #e2e8f0; display: block; }
        .lead .rating-line { display: inline-flex; align-items: center; gap: 8px; margin: 20px 0 0; font-size: 15px; color: var(--muted); }
        .rating-line .stars { color: #f59e0b; letter-spacing: 1px; font-size: 16px; }
        .rating-line strong { color: var(--fg); }

        @media (min-width: 880px) {
            .hero { padding: 20px 0 64px; }
            .t-split .hero-in { grid-template-columns: minmax(0, 1.05fr) minmax(0, .95fr); grid-template-areas: "lead form" "media form"; grid-template-rows: auto 1fr; column-gap: 56px; row-gap: 26px; align-items: start; }
            .t-split .lead { grid-area: lead; } .t-split .media { grid-area: media; } .t-split .form-col { grid-area: form; position: sticky; top: 20px; }
        }

        /* Destacada: franja de color (o la imagen con un velo) con el formulario a la derecha */
        .t-hero .hero { background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 70%, #0f172a)); color: var(--accent-fg); overflow: hidden; }
        .t-hero .hero.has-img { color: #fff; background: #0f172a; }
        .t-hero .hero-bg { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: .38; }
        .t-hero .hero > * { position: relative; }
        .t-hero .brand { color: inherit; }
        .t-hero .lead p { color: inherit; opacity: .9; }
        .t-hero .eyebrow { background: color-mix(in srgb, currentColor 14%, transparent); color: inherit; border-color: color-mix(in srgb, currentColor 30%, transparent); }
        .t-hero .benefits .tick { background: color-mix(in srgb, currentColor 18%, transparent); color: inherit; }
        .t-hero .rating-line { color: inherit; opacity: .92; }
        .t-hero .rating-line strong { color: inherit; }
        .t-hero .notice { color: var(--fg); }
        @media (min-width: 880px) { .t-hero .hero-in { grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); column-gap: 56px; align-items: center; } }

        /* Sencilla: una columna centrada */
        .t-centered .hero-in { max-width: 680px; margin: 0 auto; text-align: center; }
        .t-centered .lead p { margin-left: auto; margin-right: auto; }
        .t-centered .benefits { justify-items: center; }
        .t-centered .benefits li { text-align: left; }
        .t-centered .brand { justify-content: center; }
        .t-centered .form-col { text-align: left; }

        /* Formulario */
        .card { background: var(--card); color: var(--fg); border: 1px solid var(--line); border-radius: var(--radius); padding: 22px 18px; box-shadow: var(--shadow); }
        @media (min-width: 480px) { .card { padding: 28px 26px; } }
        .form-title { font-size: 20px; line-height: 1.3; margin: 0 0 4px; }
        .form-sub { color: var(--muted); font-size: 14px; margin: 0 0 16px; }
        label.field { display: block; font-weight: 600; font-size: 15px; margin: 16px 0 6px; }
        form label.field:first-of-type { margin-top: 0; }
        .req { color: var(--muted); font-weight: 400; font-size: 13px; }
        input[type=text], input[type=tel], input[type=email], select, textarea { width: 100%; font: inherit; padding: 12px 14px; min-height: 50px; border-radius: 12px; border: 1px solid var(--field); background: #fff; color: var(--fg); }
        textarea { min-height: 96px; resize: vertical; }
        input:focus, select:focus, textarea:focus { outline: 3px solid color-mix(in srgb, var(--accent) 40%, transparent); outline-offset: 1px; border-color: var(--accent); }
        [aria-invalid="true"] { border-color: var(--err) !important; background: #fef2f2; }
        .error { color: var(--err); font-size: 14px; margin: 6px 0 0; }
        .alert:focus { outline: 2px solid var(--err); outline-offset: 2px; }
        .alert { border: 1px solid #fecaca; background: #fef2f2; color: var(--err); border-radius: 12px; padding: 10px 12px; margin: 0 0 12px; font-size: 15px; }
        .check { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; color: var(--muted); margin: 10px 0 0; line-height: 1.45; }
        .check input { width: 20px; height: 20px; margin: 1px 0 0; accent-color: var(--accent); flex: none; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; width: 100%; min-height: 54px; border: 0; border-radius: 12px; padding: 12px 18px; font: inherit; font-weight: 700; font-size: 17px; cursor: pointer; text-decoration: none; background: var(--accent); color: var(--accent-fg); box-shadow: 0 10px 24px -12px var(--accent); }
        .btn:disabled { cursor: not-allowed; opacity: .8; }
        .btn:focus-visible, a:focus-visible, summary:focus-visible { outline: 3px solid var(--fg); outline-offset: 2px; }
        .btn.wa { background: #fff; color: #075e54; border: 1px solid #b7e4cf; margin-top: 12px; box-shadow: none; }
        .btn.wa svg { width: 22px; height: 22px; flex: none; }
        .or { text-align: center; color: var(--muted); font-size: 14px; margin: 14px 0 0; }
        .legal { color: var(--muted); font-size: 13px; margin: 16px 0 0; }
        .legal a, footer a { color: inherit; }
        .hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }

        /* Bloques de abajo */
        .block { padding: 48px 0; }
        .block + .block { padding-top: 0; }
        .block h2 { font-size: clamp(22px, 3.4vw, 30px); line-height: 1.2; letter-spacing: -.015em; margin: 0 0 6px; }
        .block .sub { color: var(--muted); margin: 0 0 20px; }
        .t-centered .block h2, .t-centered .block .sub { text-align: center; }
        .reviews-grid { display: grid; gap: 14px; }
        @media (min-width: 760px) { .reviews-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .review { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 18px; }
        .review .stars { color: #f59e0b; letter-spacing: 1px; }
        .review p { margin: 8px 0 10px; }
        .review footer { margin: 0; text-align: left; font-size: 14px; color: var(--muted); font-weight: 600; }
        .faq { max-width: 760px; }
        .t-centered .faq { margin: 0 auto; }
        details { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 0 18px; }
        details + details { margin-top: 10px; }
        summary { cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; gap: 12px; font-weight: 600; padding: 16px 0; }
        summary::-webkit-details-marker { display: none; }
        summary::after { content: "+"; font-size: 22px; line-height: 1; color: var(--accent); flex: none; }
        details[open] summary::after { content: "–"; }
        details p { margin: 0 0 16px; color: var(--muted); white-space: pre-line; }
        .final { text-align: center; }
        .final .box { background: var(--accent-soft); border: 1px solid var(--accent-line); border-radius: var(--radius); padding: 30px 20px; }
        .final .btn { width: auto; min-width: 240px; }

        /* Botón fijo del celular: lleva al formulario y se esconde cuando el formulario se ve */
        .sticky-cta { position: fixed; left: 12px; right: 12px; bottom: 12px; z-index: 5; display: none; }
        @media (max-width: 879px) { .sticky-cta { display: flex; } body.has-sticky { padding-bottom: 84px; } }
        .sticky-cta[hidden] { display: none; }

        .done { text-align: center; padding: 36px 22px; }
        .done .mark { width: 64px; height: 64px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; background: color-mix(in srgb, var(--accent) 14%, #fff); color: var(--accent); margin-bottom: 10px; box-shadow: 0 0 0 8px color-mix(in srgb, var(--accent) 7%, transparent); }
        .done h1 { font-size: clamp(24px, 4vw, 32px); }
        footer.site { margin: 0; padding: 26px 0 30px; text-align: center; color: var(--muted); font-size: 12px; }
        @media (prefers-reduced-motion: no-preference) { .btn { transition: filter .15s; } .btn:hover { filter: brightness(.95); } }
    </style>
</head>
<body class="t-{{ $template ?? 'split' }} {{ ($sticky ?? false) ? 'has-sticky' : '' }}">
@yield('content')
<footer class="site">{{ __('Hecho con :brand', ['brand' => $poweredBy]) }}</footer>
@yield('scripts')
</body>
</html>
