@extends('bookings.layout')
@section('title', __('Tu cita'))
@section('content')
    <div class="card">
        @if ($ok === 'nueva')<div class="notice ok" role="status">✓ {{ __('¡Listo! Tu cita quedó agendada.') }}</div>@endif
        @if ($ok === 'confirmada')<div class="notice ok" role="status">✓ {{ __('Gracias, confirmaste tu cita.') }}</div>@endif
        @if ($ok === 'reprogramada')<div class="notice ok" role="status">✓ {{ __('Tu cita quedó en el nuevo horario.') }}</div>@endif
        @if ($ok === 'cancelada')<div class="notice" role="status">{{ __('Tu cita quedó cancelada.') }}</div>@endif
        @if (isset($errors['form']))<div class="alert" role="alert">{{ $errors['form'] }}</div>@endif

        <h1>{{ $service }}</h1>
        <p style="margin:0;font-size:18px"><strong>{{ \Illuminate\Support\Str::ucfirst($day) }}</strong> · {{ $time }}</p>
        <ul class="meta">
            <li>{{ __('Hora de :zone', ['zone' => $zone]) }}</li>
            @if ($duration)<li>{{ __(':min min', ['min' => $duration]) }}</li>@endif
            <li>{{ $location }}</li>
            @if ($staff)<li>{{ __('Con :name', ['name' => $staff]) }}</li>@endif
        </ul>
        <p style="margin-top:12px">
            @switch($status)
                @case('confirmed') <span class="ok">{{ __('Confirmada') }}</span> @break
                @case('cancelled') {{ __('Cancelada') }} @break
                @case('completed') {{ __('Cumplida') }} @break
                @case('no_show') {{ __('No asististe') }} @break
                @default {{ __('Agendada') }}
            @endswitch
        </p>
        @if ($status !== 'cancelled')
            <p class="small"><a href="{{ $icsUrl }}">{{ __('Agregar a mi calendario') }}</a></p>
        @endif

        @if ($changeable && ! $rescheduling)
            <div class="row" style="margin-top:14px">
                @if ($status !== 'confirmed')
                    <form method="post" action="{{ $base }}/confirmar"><button type="submit" class="block">{{ __('Confirmo') }}</button></form>
                @endif
                <a class="btn secondary" href="{{ $base }}?reprogramar=1">{{ __('Reprogramar') }}</a>
            </div>
            <form method="post" action="{{ $base }}/cancelar" style="margin-top:10px" onsubmit="return confirm(@json(__('¿Seguro que quieres cancelar tu cita?')))">
                <button type="submit" class="danger block">{{ __('Cancelar la cita') }}</button>
            </form>
        @endif
    </div>

    @if ($rescheduling)
        <div class="card">
            <h2>{{ __('Elige el nuevo horario') }}</h2>
            @include('bookings._picker', ['formId' => 'reschedule-form'])
            @if (count($slots) > 0)
                <form method="post" action="{{ $base }}/reprogramar" id="reschedule-form" style="margin-top:16px">
                    <button type="submit" class="block">{{ __('Cambiar a este horario') }}</button>
                </form>
            @endif
            <p class="small" style="margin-top:12px"><a href="{{ $base }}">{{ __('Mantener mi horario') }}</a></p>
        </div>
    @endif
@endsection
