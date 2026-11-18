<x-mail::message>
# {{ __('Pedido de cambio de plan') }}

{{ __(':account pide pasar de :from a :to.', ['account' => $accountName, 'from' => $fromPlan ?? __('sin plan'), 'to' => $toPlan]) }}

@if ($note)
> {{ $note }}
@endif

<x-mail::button :url="$url">
{{ __('Abrir el panel de la plataforma') }}
</x-mail::button>
</x-mail::message>
