<x-mail::message>
@if ($status === 'suspended')
# {{ __('Pausamos los envíos de marketing') }}

{{ __('Terminó el periodo de gracia de :account. Mientras se resuelve, no salen campañas ni mensajes de marketing de los flujos y no se pueden hacer importaciones nuevas. Los datos, la medición, la bandeja y los mensajes de servicio siguen funcionando.', ['account' => $accountName]) }}
@else
# {{ __('Empezó tu periodo de gracia') }}

{{ $overdue ? __(':account tiene una factura vencida.', ['account' => $accountName]) : __(':account superó el volumen de su plan este mes.', ['account' => $accountName]) }}
{{ __('Todo sigue funcionando hasta el :date. Después se pausan solo los envíos de marketing y las importaciones nuevas.', ['date' => $graceEnds?->locale('es')->isoFormat('D [de] MMMM') ?? '']) }}
@endif

<x-mail::button :url="$url">
{{ __('Ver mi plan y facturas') }}
</x-mail::button>

{{ $brand->name }}
</x-mail::message>
