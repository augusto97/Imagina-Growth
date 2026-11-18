@php
    use App\Domain\Intelligence\Insights\Money;
    $currency = (string) ($facts['moneda'] ?? 'COP');
    $money = (array) ($facts['dinero_generado'] ?? []);
    $modules = (array) ($money['por_modulo'] ?? []);
    $max = max(1, ...array_map(fn ($m) => (float) $m['ventas'], $modules ?: [['ventas' => 1]]));
    $fmt = fn ($k) => match ($k['unidad']) {
        'money' => Money::format($k['valor'], $currency),
        default => Money::number($k['valor']),
    };
    $fmtPrev = fn ($k) => match ($k['unidad']) {
        'money' => Money::format($k['anterior'], $currency),
        default => Money::number($k['anterior']),
    };
    $change = fn ($v) => $v === null ? '—' : (($v >= 0 ? '▲ ' : '▼ ').Money::percent(abs((float) $v) / 100));
    // Sitio corporativo (sin WooCommerce): las ventas son los leads ganados.
    $services = ($facts['modo'] ?? 'tienda') === 'servicios';
    $sales = fn (int $n) => $services
        ? ($n === 1 ? __('1 negocio cerrado') : __(':count negocios cerrados', ['count' => Money::number($n)]))
        : ($n === 1 ? __('1 pedido') : __(':count pedidos', ['count' => Money::number($n)]));
    $severity = ['critical' => __('Crítico'), 'warning' => __('Atención'), 'opportunity' => __('Oportunidad'), 'info' => __('Informativo')];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ __('Reporte mensual de :business · :month', ['business' => $facts['negocio'] ?? '', 'month' => $facts['mes'] ?? '']) }}</title>
<style>
    @page { margin: 34px 40px 48px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; line-height: 1.45; }
    h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; color: #6b7280; margin: 22px 0 8px; font-weight: bold; }
    .muted { color: #6b7280; }
    .head { width: 100%; border-bottom: 2px solid {{ $barColor }}; padding-bottom: 10px; }
    .head td { vertical-align: middle; }
    .brand { font-size: 18px; font-weight: bold; color: #111827; }
    .right { text-align: right; }
    .hero { margin-top: 18px; width: 100%; }
    .hero td { vertical-align: top; }
    .big { font-size: 30px; font-weight: bold; color: #111827; line-height: 1.1; }
    .tile { border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px; }
    .tile .label { font-size: 9px; text-transform: uppercase; color: #6b7280; }
    .tile .value { font-size: 15px; font-weight: bold; color: #111827; }
    table.list { width: 100%; border-collapse: collapse; }
    table.list th { text-align: left; font-size: 9px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 5px 4px; font-weight: normal; }
    table.list td { border-bottom: 1px solid #f3f4f6; padding: 6px 4px; vertical-align: middle; }
    .num { text-align: right; white-space: nowrap; }
    .track { width: 100%; height: 9px; }
    .bar { height: 9px; background: {{ $barColor }}; border-radius: 0 4px 4px 0; }
    .summary p { margin: 0 0 7px; }
    .pill { font-size: 8.5px; border: 1px solid #d1d5db; border-radius: 3px; padding: 1px 5px; color: #374151; }
    .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8.5px; color: #9ca3af; }
</style>
</head>
<body>
<div class="footer">
    {{ __('Preparado por :brand', ['brand' => $brand->name]) }}@if (! $brand->whiteLabel && $brand->name !== 'Imagina Growth') · {{ __('con Imagina Growth') }}@endif
    @if ($brand->supportEmail) · {{ $brand->supportEmail }}@endif
    · {{ $services ? __('Ventas atribuidas: cada lead ganado cuenta una sola vez, en el módulo que lo captó.') : __('Ventas atribuidas: cada pedido cuenta una sola vez, en el módulo que lo explica primero.') }}
</div>

<table class="head">
    <tr>
        <td>
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $brand->name }}" style="max-height: 34px; max-width: 180px;">
            @else
                <div class="brand">{{ $brand->name }}</div>
            @endif
        </td>
        <td class="right">
            <div style="font-size: 14px; font-weight: bold;">{{ __('Reporte mensual') }}</div>
            <div class="muted">{{ $facts['negocio'] ?? '' }} · {{ ucfirst((string) ($facts['mes'] ?? '')) }}</div>
        </td>
    </tr>
</table>

<table class="hero">
    <tr>
        <td style="width: 46%; padding-right: 14px;">
            <div class="muted">{{ __('Dinero que generó la plataforma') }}</div>
            <div class="big">{{ Money::format($money['total'] ?? 0, $currency) }}</div>
            <div class="muted">
                {{ $sales((int) ($money['pedidos'] ?? 0)) }} · {{ $services
                    ? __(':share de las ventas del negocio', ['share' => Money::percent((float) ($money['participacion_pct'] ?? 0) / 100)])
                    : __(':share de las ventas de la tienda', ['share' => Money::percent((float) ($money['participacion_pct'] ?? 0) / 100)]) }}
            </div>
            <div style="margin-top: 3px;">
                {{ __('Frente al mes anterior') }}: <strong>{{ $change($money['cambio_vs_anterior_pct'] ?? null) }}</strong>
                <span class="muted">({{ Money::format($money['mes_anterior'] ?? 0, $currency) }})</span>
            </div>
        </td>
        <td style="width: 27%; padding-right: 8px;">
            <div class="tile">
                <div class="label">{{ $services ? __('Ventas del negocio') : __('Ventas de la tienda') }}</div>
                <div class="value">{{ Money::format($money['ventas_tienda'] ?? 0, $currency) }}</div>
            </div>
        </td>
        <td style="width: 27%;">
            <div class="tile">
                @if ($services)
                    <div class="label">{{ __('Leads nuevos del mes') }}</div>
                    <div class="value">{{ Money::number((int) (collect((array) ($facts['kpis'] ?? []))->firstWhere('clave', 'leads_nuevos')['valor'] ?? 0)) }}</div>
                @else
                    <div class="label">{{ __('Valor futuro de los clientes (12 meses)') }}</div>
                    <div class="value">{{ Money::format($facts['predicciones']['valor_futuro_12m'] ?? 0, $currency) }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>

@if (trim((string) $report->summary) !== '')
    <h2>{{ __('Resumen del mes') }}</h2>
    <div class="summary">
        @foreach (preg_split('/\n{2,}/', trim((string) $report->summary)) as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
        @if ($report->summary_source === 'ai')
            <p class="muted" style="font-size: 8.5px;">{{ __('Redactado con IA a partir de tus cifras; cada número se verificó contra los datos.') }}</p>
        @endif
    </div>
@endif

<h2>{{ __('Dinero generado por módulo') }}</h2>
@if (count($modules) === 0)
    <p class="muted">{{ __('Este mes no hubo ventas atribuidas a un módulo.') }}</p>
@else
    <table class="list">
        <thead>
            <tr><th style="width: 30%;">{{ __('Módulo') }}</th><th style="width: 38%;"></th><th class="num">{{ __('Ventas') }}</th><th class="num">{{ $services ? __('Negocios') : __('Pedidos') }}</th><th class="num">{{ __('Del total') }}</th></tr>
        </thead>
        <tbody>
        @foreach ($modules as $m)
            <tr>
                <td>{{ $m['modulo'] }}</td>
                <td><div class="track"><div class="bar" style="width: {{ max(1, round((float) $m['ventas'] / $max * 100, 1)) }}%;"></div></div></td>
                <td class="num"><strong>{{ Money::format($m['ventas'], $currency) }}</strong></td>
                <td class="num">{{ Money::number((int) $m['pedidos']) }}</td>
                <td class="num">{{ Money::percent((float) $m['participacion_pct'] / 100) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<h2>{{ __('Indicadores frente al mes anterior') }}</h2>
<table class="list">
    <thead><tr><th>{{ __('Indicador') }}</th><th class="num">{{ __('Este mes') }}</th><th class="num">{{ __('Mes anterior') }}</th><th class="num">{{ __('Cambio') }}</th></tr></thead>
    <tbody>
    @foreach ((array) ($facts['kpis'] ?? []) as $k)
        <tr>
            <td>{{ $k['nombre'] }}</td>
            <td class="num"><strong>{{ $fmt($k) }}</strong></td>
            <td class="num muted">{{ $fmtPrev($k) }}</td>
            <td class="num">{{ $change($k['cambio_pct']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

@if (count((array) ($facts['hallazgos'] ?? [])) > 0)
    <h2>{{ __('Lo que encontramos') }}</h2>
    <table class="list">
        <tbody>
        @foreach ($facts['hallazgos'] as $i)
            <tr>
                <td style="width: 14%;"><span class="pill">{{ $severity[$i['severidad']] ?? $i['severidad'] }}</span></td>
                <td>{{ $i['titulo'] }}</td>
                <td class="num">@if ($i['impacto'] !== null){{ __('Impacto estimado: :amount', ['amount' => Money::format($i['impacto'], $currency)]) }}@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@unless ($services)
<h2>{{ __('Lo que viene') }}</h2>
<table class="list">
    <tbody>
        <tr><td>{{ __('Clientes con riesgo alto de no volver') }}</td><td class="num"><strong>{{ Money::number((int) ($facts['predicciones']['clientes_en_riesgo_alto'] ?? 0)) }}</strong></td></tr>
        <tr><td>{{ __('Compras esperadas en los próximos 30 días') }}</td><td class="num"><strong>{{ Money::number((int) ($facts['predicciones']['compras_esperadas_30_dias'] ?? 0)) }}</strong></td></tr>
    </tbody>
</table>
@endunless
</body>
</html>
