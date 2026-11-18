@php
    $decimals = $invoice->currency === 'COP' ? 0 : 2;
    $money = fn ($v) => $invoice->currency.' '.number_format((float) $v, $decimals, ',', '.');
    $color = preg_match('/^#[0-9A-Fa-f]{6}$/', $brand->primaryColor) ? $brand->primaryColor : '#4F46E5';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ __('Factura :number', ['number' => $invoice->number]) }}</title>
<style>
    @page { margin: 36px 40px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; }
    .brand { font-size: 20px; font-weight: bold; color: {{ $color }}; }
    .muted { color: #6b7280; }
    .head { width: 100%; border-bottom: 2px solid {{ $color }}; padding-bottom: 12px; margin-bottom: 18px; }
    .head td { vertical-align: top; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 18px; }
    table.lines th { text-align: left; font-size: 10px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 6px 4px; }
    table.lines td { border-bottom: 1px solid #f3f4f6; padding: 7px 4px; }
    .num { text-align: right; }
    .total td { font-size: 13px; font-weight: bold; border-bottom: none; padding-top: 12px; }
    .status { display: inline-block; padding: 2px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 10px; }
    .pay { margin-top: 22px; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <div class="brand">{{ $brand->name }}</div>
            @if ($brand->supportEmail)<div class="muted">{{ $brand->supportEmail }}</div>@endif
        </td>
        <td class="num">
            <div style="font-size: 16px; font-weight: bold;">{{ __('Factura') }} {{ $invoice->number }}</div>
            <div class="muted">{{ __('Periodo') }}: {{ $invoice->period->locale('es')->isoFormat('MMMM YYYY') }}</div>
            <div class="muted">{{ __('Emitida') }}: {{ $invoice->created_at?->locale('es')->isoFormat('D MMM YYYY') }}</div>
            @if ($invoice->due_at)<div class="muted">{{ __('Vence') }}: {{ $invoice->due_at->locale('es')->isoFormat('D MMM YYYY') }}</div>@endif
            <div style="margin-top: 4px;"><span class="status">{{ __('billing.invoice_status.'.$invoice->status) }}</span></div>
        </td>
    </tr>
</table>

<div class="muted">{{ __('Facturar a') }}</div>
<div style="font-weight: bold;">{{ $account->legal_name ?: $account->name }}</div>
@if ($account->tax_id)<div>{{ __('Identificación tributaria') }}: {{ $account->tax_id }}</div>@endif
@if ($account->billing_email)<div>{{ $account->billing_email }}</div>@endif

<table class="lines">
    <thead>
        <tr><th>{{ __('Concepto') }}</th><th class="num">{{ __('Cantidad') }}</th><th class="num">{{ __('Valor unitario') }}</th><th class="num">{{ __('Total') }}</th></tr>
    </thead>
    <tbody>
        @foreach ($invoice->lines as $line)
            <tr>
                <td>{{ $line['concept'] }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $line['quantity'], 2, ',', '.'), '0'), ',') }}</td>
                <td class="num">{{ $money($line['unit_price']) }}</td>
                <td class="num">{{ $money($line['total']) }}</td>
            </tr>
        @endforeach
        <tr class="total"><td colspan="3" class="num">{{ __('Total a pagar') }}</td><td class="num">{{ $money($invoice->total) }}</td></tr>
    </tbody>
</table>

@if ($invoice->payment_url)
    <div class="pay">{{ __('Paga en línea') }}: <a href="{{ $invoice->payment_url }}">{{ $invoice->payment_url }}</a></div>
@endif
@if ($invoice->notes)
    <p class="muted" style="margin-top: 16px;">{{ $invoice->notes }}</p>
@endif
<p class="muted" style="margin-top: 28px; font-size: 9px;">{{ __('Documento de cobro. No reemplaza la factura electrónica cuando la ley la exige.') }}</p>
</body>
</html>
