{{-- Progreso de la reserva: servicio → día y hora → tus datos → confirmada. `$step` es el paso actual (1 a 4). --}}
@php($labels = [__('Servicio'), __('Día y hora'), __('Tus datos'), __('Confirmada')])
<ol class="steps" aria-label="{{ __('Pasos para agendar') }}" data-steps>
    @foreach ($labels as $i => $label)
        <li class="{{ $i + 1 < $step ? 'done' : ($i + 1 === $step ? 'now' : '') }}" @if ($i + 1 === $step) aria-current="step" @endif data-step="{{ $i + 1 }}">
            <span><span class="sr">{{ __('Paso :n de 4:', ['n' => $i + 1]) }} </span>{{ $label }}</span>
        </li>
    @endforeach
</ol>
