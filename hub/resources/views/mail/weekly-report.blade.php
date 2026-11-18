<x-mail::message>
# {{ __('Hola, :name', ['name' => explode(' ', $name)[0] ?? $name]) }}

{{ __('Así le fue a :workspace del :from al :to.', ['workspace' => $workspace, 'from' => \Carbon\Carbon::parse($period['from'])->translatedFormat('j \d\e F'), 'to' => \Carbon\Carbon::parse($period['to'])->translatedFormat('j \d\e F')]) }}

<x-mail::table>
| {{ __('Indicador') }} | {{ __('Semana') }} | {{ __('Frente a la anterior') }} |
|:--|--:|--:|
@foreach ($numbers as $n)
| {{ $n['label'] }} | **{{ $n['value'] }}** | {{ $n['change'] ?? '—' }} |
@endforeach
</x-mail::table>

@if ($analysis)
## {{ __('El análisis de la semana') }}

{{ $analysis['body'] }}

@if ($analysis['ai'])
_{{ __('Escrito con IA a partir de tus cifras; cada número se verificó contra los datos.') }}_
@endif

@foreach ($analysis['actions'] as $i => $a)
**{{ $i + 1 }}. {{ $a['title'] }}** — {{ $a['detail'] }} [{{ __('Hacerlo') }}]({{ $a['url'] }})

@endforeach
@endif

@if (count($insights) > 0)
## {{ __('Lo que encontramos') }}

@foreach ($insights as $i)
- **{{ $i['title'] }}**@if ($i['impact_text']) — {{ __('impacto estimado: :impact', ['impact' => $i['impact_text']]) }}@endif

@endforeach
@endif

## {{ __('Tu acción de esta semana') }}

{{ $action['label'] }}

<x-mail::button :url="$actionUrl">
{{ __('Hazlo ahora') }}
</x-mail::button>

{{ __('Las cifras usan la misma definición de venta que WooCommerce (pedidos en estado pagado). Los impactos son estimaciones conservadoras con sus supuestos en el panel.') }}

[{{ __('Ver todos los tableros') }}]({{ $dashboardUrl }})
</x-mail::message>
