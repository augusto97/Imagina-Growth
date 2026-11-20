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

@foreach ($leads as $funnel)
## {{ count($leads) > 1 ? __('Tu embudo de leads: :name', ['name' => $funnel['name']]) : __('Tu embudo de leads') }}

<x-mail::table>
| {{ __('Etapa') }} | {{ __('Semana') }} | {{ __('Anterior') }} | {{ __('Pasaron de la etapa anterior') }} |
|:--|--:|--:|--:|
@foreach ($funnel['stages'] as $s)
| {{ $s['label'] }} | **{{ $s['reached'] }}** | {{ $s['previous'] }} | {{ $s['conversion'] ?? '—' }}@if ($s['previous_conversion']) ({{ __('antes :rate', ['rate' => $s['previous_conversion']]) }})@endif |
@endforeach
</x-mail::table>

@if ($funnel['drop'])
**{{ __('Dónde se cae la venta:') }}** {{ __('entre «:from» y «:to» pasa :rate de los leads de los últimos 90 días.', ['from' => $funnel['drop']['from'], 'to' => $funnel['drop']['to'], 'rate' => $funnel['drop']['rate']]) }}

@endif
@if ($funnel['lost'] > 0)
**{{ trans_choice('{1} :n lead perdido|[2,*] :n leads perdidos', $funnel['lost'], ['n' => $funnel['lost']]) }}**@if (count($funnel['reasons']) > 0): @foreach ($funnel['reasons'] as $i => $r){{ $i > 0 ? ', ' : '' }}{{ $r['reason'] }} ({{ $r['count'] }})@endforeach @endif

@endif
@if (count($funnel['stalled']) > 0)
**{{ __('Quietos más de :days días:', ['days' => $funnel['stale_days']]) }}** @foreach ($funnel['stalled'] as $i => $s){{ $i > 0 ? ', ' : '' }}{{ $s['label'] }} ({{ $s['count'] }})@endforeach

@endif
@if ($funnel['conclusion'] !== '')
{{ $funnel['conclusion'] }}

@if ($funnel['ai'])
_{{ __('Escrito con IA a partir de tus cifras; cada número se verificó contra los datos.') }}_

@endif
@endif
[{{ __('Ver el embudo') }}]({{ $leadsUrl }}) · [{{ __('A quién escribirle hoy') }}]({{ $todayUrl }})

@endforeach
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
