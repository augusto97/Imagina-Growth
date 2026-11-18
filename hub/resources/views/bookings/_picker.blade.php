{{-- Días con horario libre y horarios del día elegido. Funciona sin JavaScript: cada día es un enlace. --}}
@php($query = fn (array $extra) => '?'.http_build_query(array_filter(array_merge(array_column($carry, 1, 0), ['desde' => $from, 'persona' => $persona], $extra), fn ($v) => $v !== null && $v !== '')))
@if (count($staffOptions) > 0)
    <form method="get" action="{{ $selfUrl }}" class="row" style="align-items:flex-end">
        @foreach ($carry as [$name, $value])
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <div>
            <label class="field" for="persona">{{ __('¿Con quién?') }}</label>
            <select id="persona" name="persona" onchange="this.form.submit()">
                <option value="">{{ __('Con quien esté disponible') }}</option>
                @foreach ($staffOptions as $id => $name)
                    <option value="{{ $id }}" @selected($persona === $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button type="submit" class="secondary">{{ __('Ver horarios') }}</button></noscript>
    </form>
@endif
<h2 style="margin-top:16px">{{ __('Elige el día') }}</h2>
@if (count($days) === 0)
    <p class="muted">{{ __('No hay horarios libres en estas fechas.') }}</p>
@else
    <nav class="days" aria-label="{{ __('Días con horarios libres') }}">
        @foreach ($days as $d)
            <a class="day" href="{{ $selfUrl.$query(['dia' => $d['date']]) }}" @if ($d['date'] === $selectedDay) aria-current="date" @endif>
                <span>{{ $d['weekday'] }}</span><strong>{{ $d['label'] }}</strong>
            </a>
        @endforeach
    </nav>
@endif
<div class="nav">
    @if ($prevFrom)<a href="{{ $selfUrl.$query(['desde' => $prevFrom]) }}">← {{ __('Fechas anteriores') }}</a>@else<span></span>@endif
    @if ($nextFrom)<a href="{{ $selfUrl.$query(['desde' => $nextFrom]) }}">{{ __('Más fechas') }} →</a>@endif
</div>
@if ($selectedDay)
    <fieldset style="border:0;padding:0;margin:8px 0 0;min-width:0">
        <legend style="font-weight:600;font-size:16px;padding:0;margin:0 0 4px;text-transform:none">{{ \Illuminate\Support\Str::ucfirst($selectedDayLabel) }}</legend>
        <p class="muted small" style="margin:0">{{ __('Horas de :zone', ['zone' => $zone]) }}</p>
        <div class="slots">
            @foreach ($slots as $s)
                <label class="slot">
                    <input type="radio" name="start" value="{{ $s['value'] }}" form="{{ $formId }}" required @checked(($old['start'] ?? null) === $s['value'])>
                    <span>{{ $s['label'] }}</span>
                </label>
            @endforeach
        </div>
        @if (isset($errors['start']))<p class="error" role="alert">{{ $errors['start'] }}</p>@endif
    </fieldset>
@endif
