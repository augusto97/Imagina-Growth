<x-mail::message>
# {{ __('Factura :number', ['number' => $invoice->number]) }}

{{ __('Hola, :account. Adjuntamos la factura del periodo :period por :total.', ['account' => $accountName, 'period' => $invoice->period->locale('es')->isoFormat('MMMM YYYY'), 'total' => $invoice->currency.' '.number_format((float) $invoice->total, $invoice->currency === 'COP' ? 0 : 2, ',', '.')]) }}

@if ($invoice->due_at)
{{ __('Vence el :date.', ['date' => $invoice->due_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY')]) }}
@endif

@if ($invoice->payment_url)
<x-mail::button :url="$invoice->payment_url">
{{ __('Pagar en línea') }}
</x-mail::button>
@endif

<x-mail::button :url="$url" color="success">
{{ __('Ver mis facturas') }}
</x-mail::button>

{{ $brand->name }}
</x-mail::message>
