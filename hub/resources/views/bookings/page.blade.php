@extends('bookings.layout')
@section('title', $service['name'])
@section('content')
    <div class="card">
        @if ($servicesUrl)<p class="small"><a href="{{ $servicesUrl }}">← {{ __('Todos los servicios') }}</a></p>@endif
        <h1>{{ $settings['page_title'] ?: __('Agenda tu cita con :business', ['business' => $business]) }}</h1>
        <p style="margin:0"><strong>{{ $service['name'] }}</strong></p>
        <ul class="meta">
            <li>{{ __(':min min', ['min' => $service['duration']]) }}</li>
            <li>{{ $service['location'] }}</li>
            @if ($service['price'])<li>{{ $service['price'] }}</li>@endif
        </ul>
        @if ($service['description'])
            <p class="muted" style="margin-top:10px">{{ $service['description'] }}</p>
        @elseif ($settings['intro'])
            <p class="muted" style="margin-top:10px">{{ $settings['intro'] }}</p>
        @endif
    </div>

    <div class="card">
        @include('bookings._picker', ['formId' => 'booking-form'])
    </div>

    @if (count($slots) > 0)
        <div class="card">
            <h2>{{ __('Tus datos') }}</h2>
            @if (isset($errors['form']))<div class="alert" role="alert">{{ $errors['form'] }}</div>@endif
            @if (count($errors) > 0 && ! isset($errors['form']))<div class="alert" role="alert">{{ __('Revisa los campos marcados.') }}</div>@endif
            <form method="post" action="{{ $action }}" id="booking-form" novalidate>
                @foreach ($carry as [$name, $value])
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                @if ($persona)<input type="hidden" name="persona" value="{{ $persona }}">@endif
                <label class="field" for="name">{{ __('Nombre') }}</label>
                <input type="text" id="name" name="name" autocomplete="name" required maxlength="80" value="{{ is_string($old['name'] ?? null) ? $old['name'] : '' }}" @if (isset($errors['name'])) aria-invalid="true" aria-describedby="name-error" @endif>
                @if (isset($errors['name']))<p class="error" id="name-error">{{ $errors['name'] }}</p>@endif

                <label class="field" for="phone">{{ __('WhatsApp') }}</label>
                <input type="tel" id="phone" name="phone" autocomplete="tel" inputmode="tel" maxlength="30" placeholder="{{ __('Ej.: 300 123 4567') }}" value="{{ is_string($old['phone'] ?? null) ? $old['phone'] : '' }}" @if (isset($errors['phone'])) aria-invalid="true" aria-describedby="phone-error" @endif>
                @if (isset($errors['phone']))<p class="error" id="phone-error">{{ $errors['phone'] }}</p>@endif

                @if ($settings['ask_email'] || $settings['require_email'])
                    <label class="field" for="email">{{ $settings['require_email'] ? __('Correo') : __('Correo (opcional)') }}</label>
                    <input type="email" id="email" name="email" autocomplete="email" inputmode="email" maxlength="255" value="{{ is_string($old['email'] ?? null) ? $old['email'] : '' }}" @if (isset($errors['email'])) aria-invalid="true" aria-describedby="email-error" @endif>
                    @if (isset($errors['email']))<p class="error" id="email-error">{{ $errors['email'] }}</p>@endif
                @endif

                <label class="field" for="notes">{{ __('¿Algo que debamos saber? (opcional)') }}</label>
                <textarea id="notes" name="notes" maxlength="500">{{ is_string($old['notes'] ?? null) ? $old['notes'] : '' }}</textarea>

                <p class="muted small" style="margin-top:12px">{{ __('Te enviaremos la confirmación y los recordatorios de esta cita por WhatsApp o correo.') }}</p>
                @foreach ($consents as $c)
                    <label class="check">
                        <input type="checkbox" name="consent[{{ $c['channel'] }}]" value="1" @checked(! empty($old['consent'][$c['channel']] ?? null))>
                        <span>{{ $c['label'] }}</span>
                    </label>
                @endforeach

                <div style="margin-top:18px">
                    <button type="submit" class="block" @disabled(! $canBook)>{{ __('Agendar cita') }}</button>
                </div>
                @if (! $canBook)<p class="muted small" style="margin-top:8px">{{ __('Este negocio todavía no puede recibir reservas en línea. Escríbele directamente.') }}</p>@endif
            </form>
        </div>
    @endif
@endsection
@section('scripts')
<script>
    // Sin recargar la página dos veces: evita el doble envío.
    document.getElementById('booking-form')?.addEventListener('submit', function (e) {
        var b = this.querySelector('button[type=submit]');
        if (b) { if (b.dataset.sent) { e.preventDefault(); return; } b.dataset.sent = '1'; b.textContent = @json(__('Agendando…')); }
    });
</script>
@endsection
