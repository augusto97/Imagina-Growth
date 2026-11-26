@extends('bookings.layout')
@section('title', $service['name'])
@section('content')
    @include('bookings._steps', ['step' => isset($old['start']) && is_string($old['start']) && $old['start'] !== '' ? 3 : 2])
    @php
        $chosen = collect($slots)->firstWhere('value', $old['start'] ?? null);
    @endphp
    <div class="intro">
        <h1>{{ $settings['page_title'] ?: __('Agenda tu cita con :business', ['business' => $business]) }}</h1>
    </div>
    <div class="layout">
        <aside class="sum" aria-label="{{ __('Resumen de tu cita') }}">
            <div class="card">
                @if ($servicesUrl)<a class="back" href="{{ $servicesUrl }}">@include('bookings._icon', ['name' => 'left']){{ __('Todos los servicios') }}</a>@endif
                <h3>{{ __('Tu cita') }}</h3>
                <p class="svc">{{ $service['name'] }}</p>
                <ul class="chips">
                    <li>@include('bookings._icon', ['name' => 'clock']){{ __(':min min', ['min' => $service['duration']]) }}</li>
                    <li>@include('bookings._icon', ['name' => $service['location_kind']]){{ $service['location'] }}</li>
                    @if ($service['price'])<li class="price">{{ $service['price'] }}</li>@endif
                </ul>
                @if ($service['description'])
                    <p class="desc">{{ $service['description'] }}</p>
                @endif
                <div class="when {{ $chosen ? 'set' : '' }}" data-when-box aria-live="polite">
                    @include('bookings._icon', ['name' => 'calendar'])
                    <span><strong data-when-text>{{ $chosen['full'] ?? __('Elige un día y una hora') }}</strong>{{ __('Horas de :zone', ['zone' => $zone]) }}</span>
                </div>
            </div>
        </aside>

        <div class="main">
            <section class="card" aria-labelledby="step-when">
                <h2 id="step-when"><span class="n" aria-hidden="true">1</span>{{ __('Elige el día y la hora') }}</h2>
                <p class="lead">{{ $settings['intro'] ?: __('Solo ves los horarios libres. Toca uno para elegirlo.') }}</p>
                @include('bookings._picker', ['formId' => 'booking-form'])
            </section>

            @if (count($slots) > 0)
                <section class="card" aria-labelledby="step-data">
                    <h2 id="step-data"><span class="n" aria-hidden="true">2</span>{{ __('Tus datos') }}</h2>
                    <p class="lead">{{ __('Para confirmarte la cita y avisarte si algo cambia.') }}</p>
                    @if (isset($errors['form']))<div class="alert" role="alert">@include('bookings._icon', ['name' => 'alert']){{ $errors['form'] }}</div>@endif
                    @if (count($errors) > 0 && ! isset($errors['form']))<div class="alert" role="alert" tabindex="-1" id="form-errors">@include('bookings._icon', ['name' => 'alert']){{ __('Revisa los campos marcados.') }}</div>@endif
                    <form method="post" action="{{ $action }}" id="booking-form" novalidate>
                        @foreach ($carry as [$name, $value])
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endforeach
                        @if ($persona)<input type="hidden" name="persona" value="{{ $persona }}">@endif
                        <label class="field" for="name" style="margin-top:0">{{ __('Nombre') }}</label>
                        <input type="text" id="name" name="name" autocomplete="name" required maxlength="80" value="{{ is_string($old['name'] ?? null) ? $old['name'] : '' }}" @if (isset($errors['name'])) aria-invalid="true" aria-describedby="name-error" @endif>
                        @if (isset($errors['name']))<p class="error" id="name-error">{{ $errors['name'] }}</p>@endif

                        <div class="two">
                            <div>
                                <label class="field" for="phone">{{ __('WhatsApp') }}@if ($settings['require_email']) <span class="opt">({{ __('opcional') }})</span>@endif</label>
                                <input type="tel" id="phone" name="phone" autocomplete="tel" inputmode="tel" maxlength="30" placeholder="{{ __('Ej.: 300 123 4567') }}" value="{{ is_string($old['phone'] ?? null) ? $old['phone'] : '' }}" @if (isset($errors['phone'])) aria-invalid="true" aria-describedby="phone-error" @endif>
                                @if (isset($errors['phone']))<p class="error" id="phone-error">{{ $errors['phone'] }}</p>@endif
                            </div>
                            @if ($settings['ask_email'] || $settings['require_email'])
                                <div>
                                    <label class="field" for="email">{{ __('Correo') }}@if (! $settings['require_email']) <span class="opt">({{ __('opcional') }})</span>@endif</label>
                                    <input type="email" id="email" name="email" autocomplete="email" inputmode="email" maxlength="255" placeholder="{{ __('tu@correo.com') }}" value="{{ is_string($old['email'] ?? null) ? $old['email'] : '' }}" @if (isset($errors['email'])) aria-invalid="true" aria-describedby="email-error" @endif>
                                    @if (isset($errors['email']))<p class="error" id="email-error">{{ $errors['email'] }}</p>@endif
                                </div>
                            @endif
                        </div>

                        <label class="field" for="notes">{{ __('¿Algo que debamos saber?') }} <span class="opt">({{ __('opcional') }})</span></label>
                        <textarea id="notes" name="notes" maxlength="500" placeholder="{{ __('Ej.: es mi primera vez, tengo una duda sobre…') }}">{{ is_string($old['notes'] ?? null) ? $old['notes'] : '' }}</textarea>

                        @if (count($consents) > 0)
                            <div class="consents">
                                <p>{{ __('Novedades y ofertas (opcional)') }}</p>
                                @foreach ($consents as $c)
                                    <label class="check">
                                        <input type="checkbox" name="consent[{{ $c['channel'] }}]" value="1" @checked(! empty($old['consent'][$c['channel']] ?? null))>
                                        <span>{{ $c['label'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif

                        <div class="submit">
                            <button type="submit" class="block" @disabled(! $canBook)>{{ __('Agendar cita') }}</button>
                            <p class="note">@include('bookings._icon', ['name' => 'shield']){{ __('Te enviaremos la confirmación y los recordatorios de esta cita por WhatsApp o correo. Puedes reprogramarla o cancelarla desde el enlace que te llega.') }}</p>
                        </div>
                        @if (! $canBook)<p class="muted small" style="margin-top:8px">{{ __('Este negocio todavía no puede recibir reservas en línea. Escríbele directamente.') }}</p>@endif
                    </form>
                </section>
            @endif
        </div>
    </div>
@endsection
@section('scripts')
    @include('bookings._script', ['form' => 'booking-form', 'busy' => __('Agendando…')])
@endsection
