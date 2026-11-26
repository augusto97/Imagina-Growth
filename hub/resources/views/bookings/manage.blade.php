@extends('bookings.layout')
@section('title', __('Tu cita'))
@section('content')
    @if ($ok === 'nueva')
        @include('bookings._steps', ['step' => 4])
    @endif
    <div class="center">
        @if ($ok === 'nueva' || $ok === 'confirmada' || $ok === 'reprogramada')
            <div class="hero" role="status">
                <span class="mark">@include('bookings._icon', ['name' => 'check'])</span>
                <h1>
                    @if ($ok === 'nueva') {{ __('¡Listo! Tu cita quedó agendada.') }}
                    @elseif ($ok === 'confirmada') {{ __('Gracias, confirmaste tu cita.') }}
                    @else {{ __('Tu cita quedó en el nuevo horario.') }}
                    @endif
                </h1>
                <p>{{ __('Te enviamos los detalles por WhatsApp o correo. Guarda este enlace para cambiarla si lo necesitas.') }}</p>
            </div>
        @elseif ($ok === 'cancelada')
            <div class="hero" role="status">
                <span class="mark neutral">@include('bookings._icon', ['name' => 'calendar'])</span>
                <h1>{{ __('Tu cita quedó cancelada.') }}</h1>
                @if ($bookUrl)<p><a href="{{ $bookUrl }}">{{ __('Agendar otra cita') }}</a></p>@endif
            </div>
        @endif

        <section class="card" aria-labelledby="appt-title">
            @if (isset($errors['form']))<div class="alert" role="alert">@include('bookings._icon', ['name' => 'alert']){{ $errors['form'] }}</div>@endif
            <div class="ticket">
                <div class="date" aria-hidden="true"><small>{{ $weekday }}</small><strong>{{ $dayNumber }}</strong><span>{{ $month }}</span></div>
                <div>
                    @if ($ok === null)<h1 class="sr">{{ __('Tu cita') }}</h1>@endif
                    <h2 id="appt-title">{{ $service }}</h2>
                    <p class="time"><strong>{{ \Illuminate\Support\Str::ucfirst($day) }}</strong> · {{ $time }}@if ($endTime) – {{ $endTime }}@endif</p>
                    @switch($status)
                        @case('confirmed') <span class="badge ok">@include('bookings._icon', ['name' => 'check']){{ __('Confirmada') }}</span> @break
                        @case('cancelled') <span class="badge off">{{ __('Cancelada') }}</span> @break
                        @case('completed') <span class="badge ok">{{ __('Cumplida') }}</span> @break
                        @case('no_show') <span class="badge off">{{ __('No asististe') }}</span> @break
                        @default <span class="badge">{{ __('Agendada') }}</span>
                    @endswitch
                </div>
            </div>
            <ul class="details">
                @if ($duration)<li>@include('bookings._icon', ['name' => 'clock']){{ __(':min min', ['min' => $duration]) }}</li>@endif
                <li>@include('bookings._icon', ['name' => $locationKind]){{ $location }}</li>
                @if ($staff)<li>@include('bookings._icon', ['name' => 'user']){{ __('Con :name', ['name' => $staff]) }}</li>@endif
                <li>@include('bookings._icon', ['name' => 'globe']){{ __('Hora de :zone', ['zone' => $zone]) }}</li>
            </ul>
        </section>

        @if ($status !== 'cancelled' && $changeable && ! $rescheduling)
            <section class="card">
                <div class="actions">
                    <h3>{{ __('Agrégala a tu calendario') }}</h3>
                    <div class="row">
                        <a class="btn secondary" href="{{ $googleUrl }}" target="_blank" rel="noopener noreferrer">@include('bookings._icon', ['name' => 'calendar']){{ __('Google Calendar') }}</a>
                        <a class="btn secondary" href="{{ $icsUrl }}" title="{{ __('Archivo .ics para Apple, Outlook y otros calendarios') }}">@include('bookings._icon', ['name' => 'download']){{ __('Apple u Outlook') }}</a>
                    </div>
                </div>
                <div class="actions">
                    <h3>{{ __('¿Necesitas cambiarla?') }}</h3>
                    <div class="row">
                        @if ($status !== 'confirmed')
                            <form method="post" action="{{ $base }}/confirmar"><button type="submit">{{ __('Confirmo') }}</button></form>
                        @endif
                        <a class="btn secondary" href="{{ $base }}?reprogramar=1">{{ __('Reprogramar') }}</a>
                        <form method="post" action="{{ $base }}/cancelar" data-confirm="{{ __('¿Seguro que quieres cancelar tu cita?') }}"><button type="submit" class="danger">{{ __('Cancelar la cita') }}</button></form>
                    </div>
                </div>
                @if ($whatsappUrl)
                    <div class="actions">
                        <h3>{{ __('¿Tienes alguna duda?') }}</h3>
                        <a class="btn wa block" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">@include('bookings._icon', ['name' => 'wa']){{ __('Escríbenos por WhatsApp') }}</a>
                    </div>
                @endif
            </section>
        @elseif ($status !== 'cancelled' && ! $rescheduling)
            <section class="card">
                <p class="small muted" style="margin:0">{{ __('Esta cita ya no se puede cambiar desde aquí. Si necesitas algo, escríbele al negocio.') }}</p>
            </section>
        @endif

        @if ($rescheduling)
            <section class="card" aria-labelledby="resched">
                <h2 id="resched">{{ __('Elige el nuevo horario') }}</h2>
                <p class="lead">{{ __('Tu horario actual sigue apartado hasta que confirmes el cambio.') }}</p>
                @include('bookings._picker', ['formId' => 'reschedule-form'])
                @if (count($slots) > 0)
                    <form method="post" action="{{ $base }}/reprogramar" id="reschedule-form" style="margin-top:16px">
                        <button type="submit" class="block">{{ __('Cambiar a este horario') }}</button>
                    </form>
                @endif
                <p class="small" style="margin:12px 0 0;text-align:center"><a href="{{ $base }}">{{ __('Mantener mi horario') }}</a></p>
            </section>
        @endif
    </div>
@endsection
@section('scripts')
    @include('bookings._script', ['form' => 'reschedule-form', 'busy' => __('Cambiando…')])
    <script>
        document.querySelectorAll('form[data-confirm]').forEach(function (f) {
            f.addEventListener('submit', function (e) { if (!window.confirm(f.dataset.confirm)) { e.preventDefault(); } });
        });
    </script>
@endsection
