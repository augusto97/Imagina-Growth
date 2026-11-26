{{-- Días con horario libre y horarios del día elegido. Funciona sin JavaScript: cada día es un enlace y cada hora un radio del formulario `$formId`. --}}
@php
    $query = fn (array $extra) => '?'.http_build_query(array_filter(array_merge(array_column($carry, 1, 0), ['desde' => $from, 'persona' => $persona], $extra), fn ($v) => $v !== null && $v !== ''));
    $periods = ['morning' => __('Mañana'), 'afternoon' => __('Tarde'), 'evening' => __('Noche')];
    $grouped = [];
    foreach ($slots as $s) {
        $grouped[$s['period']][] = $s;
    }
@endphp
@if (count($staffOptions) > 0)
    <form method="get" action="{{ $selfUrl }}" class="field-row">
        @foreach ($carry as [$name, $value])
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <div>
            <label class="field" for="persona" style="margin-top:0">{{ __('¿Con quién?') }}</label>
            <select id="persona" name="persona" data-autosubmit>
                <option value="">{{ __('Con quien esté disponible') }}</option>
                @foreach ($staffOptions as $id => $name)
                    <option value="{{ $id }}" @selected($persona === $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button type="submit" class="secondary">{{ __('Ver horarios') }}</button></noscript>
    </form>
@endif
<div class="cal-head">
    <strong>{{ $rangeLabel }}</strong>
    <span class="arrows">
        @if ($prevFrom)
            <a class="arrow" href="{{ $selfUrl.$query(['desde' => $prevFrom]) }}" aria-label="{{ __('Fechas anteriores') }}">@include('bookings._icon', ['name' => 'left'])</a>
        @else
            <span class="arrow" aria-disabled="true">@include('bookings._icon', ['name' => 'left'])</span>
        @endif
        @if ($nextFrom)
            <a class="arrow" href="{{ $selfUrl.$query(['desde' => $nextFrom]) }}" aria-label="{{ __('Más fechas') }}">@include('bookings._icon', ['name' => 'right'])</a>
        @else
            <span class="arrow" aria-disabled="true">@include('bookings._icon', ['name' => 'right'])</span>
        @endif
    </span>
</div>
@if (count($days) === 0)
    <p class="empty">{{ __('No hay horarios libres en estas fechas.') }}@if ($nextFrom) <a href="{{ $selfUrl.$query(['desde' => $nextFrom]) }}">{{ __('Ver más fechas') }}</a>@endif</p>
@else
    <nav class="days" aria-label="{{ __('Días con horarios libres') }}">
        @foreach ($days as $d)
            <a class="day" href="{{ $selfUrl.$query(['dia' => $d['date']]) }}#horarios" @if ($d['date'] === $selectedDay) aria-current="date" @endif aria-label="{{ $d['full'] }} · {{ trans_choice(':count horario|:count horarios', $d['count'], ['count' => $d['count']]) }}">
                <small>{{ $d['weekday'] }}</small><strong>{{ $d['number'] }}</strong><em>{{ $d['month'] }}</em>
            </a>
        @endforeach
    </nav>
@endif
@if ($selectedDay)
    <fieldset class="slots" id="horarios">
        <legend>{{ \Illuminate\Support\Str::ucfirst($selectedDayLabel) }}</legend>
        <p class="muted small" style="margin:0">{{ __('Horas de :zone', ['zone' => $zone]) }}</p>
        @foreach ($periods as $key => $label)
            @if (! empty($grouped[$key]))
                <div class="period">
                    <h4>@include('bookings._icon', ['name' => $key]){{ $label }}</h4>
                    <div class="grid-slots">
                        @foreach ($grouped[$key] as $s)
                            <label class="slot">
                                <input type="radio" name="start" value="{{ $s['value'] }}" form="{{ $formId }}" required data-when="{{ $s['full'] }}" @checked(($old['start'] ?? null) === $s['value'])>
                                <span>{{ $s['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
        @if (isset($errors['start']))<p class="error" role="alert">@include('bookings._icon', ['name' => 'alert']){{ $errors['start'] }}</p>@endif
    </fieldset>
@endif
