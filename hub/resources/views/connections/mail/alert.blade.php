<x-mail::message>
@if ($state === 'expires_7d' || $state === 'expires_1d')
# {{ $state === 'expires_1d' ? __('Tu conexión con :provider vence mañana', ['provider' => $provider]) : __('Tu conexión con :provider vence pronto', ['provider' => $provider]) }}

{{ __('El permiso que diste a :workspace para usar :provider:account vence el :date. Vuelve a conectarlo antes de esa fecha: toma un minuto y no se pierde nada.', ['workspace' => $workspaceName, 'provider' => $provider, 'account' => $account !== null ? ' ('.$account.')' : '', 'date' => (string) $expiresOn]) }}
@elseif ($state === 'disconnected')
# {{ __('Tu :provider se desconectó', ['provider' => $provider]) }}

{{ __('Meta desconectó :account de :workspace. Mientras no lo vuelvas a conectar, no salen mensajes por ese número y lo que te escriban no llega a la bandeja.', ['account' => $account ?? $provider, 'workspace' => $workspaceName]) }}
@else
# {{ __('Hay que volver a conectar :provider', ['provider' => $provider]) }}

{{ __('La conexión de :workspace con :provider:account se cerró o venció. Mientras no la vuelvas a conectar, no podemos usarla: no salen las ventas hacia tus anuncios, los mensajes de ese canal ni lo que dependa de ella.', ['workspace' => $workspaceName, 'provider' => $provider, 'account' => $account !== null ? ' ('.$account.')' : '']) }}
@endif

@if ($detail !== null && $detail !== '')
{{ __('Detalle: :detail', ['detail' => $detail]) }}
@endif

<x-mail::button :url="$url">
{{ __('Reconectar') }}
</x-mail::button>

{{ __('Te avisamos una sola vez por cada cambio. Si ya lo resolviste, ignora este correo.') }}

{{ $brand->name }}
</x-mail::message>
