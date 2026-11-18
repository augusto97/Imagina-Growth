@extends('reviews.layout')
@section('title', __('Una pregunta'))
@section('head')
    <style>
        .scale { display: grid; gap: 6px; grid-template-columns: repeat({{ $max - $min + 1 > 6 ? 6 : $max - $min + 1 }}, 1fr); margin: 14px 0 6px; }
        @media (min-width: 480px) { .scale { grid-template-columns: repeat({{ $max - $min + 1 }}, 1fr); } }
        .scale input { position: absolute; opacity: 0; width: 1px; height: 1px; }
        .scale label { display: flex; align-items: center; justify-content: center; min-height: 44px; border: 1px solid var(--line); border-radius: 10px; font-weight: 600; cursor: pointer; }
        .scale input:checked + label { background: var(--accent); color: var(--accent-fg); border-color: var(--accent); }
        .scale input:focus-visible + label { outline: 3px solid var(--accent); outline-offset: 2px; }
        .ends { display: flex; justify-content: space-between; font-size: 13px; color: var(--muted); }
    </style>
@endsection
@section('content')
    <h1>{{ $question }}</h1>
    @if ($answered)
        <p class="muted">{{ __('Ya respondiste. Puedes cambiar tu respuesta si quieres.') }}</p>
    @endif
    @if ($error)
        <div class="alert" role="alert">{{ $error }}</div>
    @endif
    <form method="post" action="{{ $action }}">
        <fieldset style="border:0;padding:0;margin:0">
            <legend class="sr">{{ __('Tu nota') }}</legend>
            <div class="scale">
                @for ($i = $min; $i <= $max; $i++)
                    <input type="radio" id="score-{{ $i }}" name="score" value="{{ $i }}" @checked($score === $i) required>
                    <label for="score-{{ $i }}">{{ $i }}</label>
                @endfor
            </div>
            <div class="ends">
                <span>{{ $kind === 'nps' ? __('Nada probable') : __('Muy insatisfecho') }}</span>
                <span>{{ $kind === 'nps' ? __('Muy probable') : __('Muy satisfecho') }}</span>
            </div>
        </fieldset>
        @if ($commentLabel !== '')
            <label class="field" for="comment">{{ $commentLabel }} <span class="muted">{{ __('(opcional)') }}</span></label>
            <textarea id="comment" name="comment" maxlength="2000"></textarea>
        @endif
        <div style="margin-top:18px"><button type="submit" class="block">{{ __('Enviar mi respuesta') }}</button></div>
    </form>
@endsection
