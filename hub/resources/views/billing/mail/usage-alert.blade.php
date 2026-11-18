<x-mail::message>
# {{ $threshold >= 100 ? __('Llegaste al límite de tu plan') : __('Vas en el :pct % de tu plan', ['pct' => $threshold]) }}

{{ __(':workspace lleva :value de :limit :metric incluidos este mes.', ['workspace' => $workspaceName, 'value' => number_format($value, 0, ',', '.'), 'limit' => number_format($limit, 0, ',', '.'), 'metric' => __('billing.metric.'.$metric)]) }}

@if ($threshold >= 100)
{{ __('No cortamos nada de golpe: todo sigue funcionando y tienes un periodo de gracia para cambiar de plan o sumar un paquete.') }}
@else
{{ __('Si crees que lo vas a superar, revisa los planes con tiempo.') }}
@endif

<x-mail::button :url="$url">
{{ __('Ver mi plan y uso') }}
</x-mail::button>

{{ $brand->name }}
</x-mail::message>
