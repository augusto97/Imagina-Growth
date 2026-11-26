@extends('capture.layout')
@section('title', $title)
@section('description', \Illuminate\Support\Str::limit($paragraphs[0] ?? $title, 155))
@section('content')
    <header class="brand">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $business }}" referrerpolicy="no-referrer" width="160" height="48">
        @else
            <span>{{ $business }}</span>
        @endif
    </header>
    @if ($preview)<p class="notice" role="status">{{ __('Vista previa: así se verá la página cuando la publiques. Los envíos de esta vista no se guardan.') }}</p>@endif
    <main class="grid">
        <section class="lead" aria-labelledby="page-title">
            <h1 id="page-title">{{ $title }}</h1>
            @foreach ($paragraphs as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        </section>
        <section class="form-col" aria-label="{{ __('Formulario de contacto') }}">
            <div class="card">
                @if (count($errors) > 0)
                    <div class="alert" role="alert" id="form-errors" tabindex="-1">{{ $errors['form'] ?? __('Revisa los campos marcados.') }}</div>
                @endif
                <form method="post" action="{{ $action }}" novalidate id="capture-form">
                    <input type="hidden" name="t" value="{{ $token }}">
                    @foreach ($carry as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <div class="hp" aria-hidden="true">
                        <label for="website">{{ __('No llenes este campo') }}</label>
                        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>
                    @foreach ($fields as $field)
                        @php
                            $value = is_string($old[$field['key']] ?? null) ? $old[$field['key']] : '';
                            $error = $errors[$field['key']] ?? null;
                            $describedBy = $error !== null ? $field['id'].'-error' : null;
                        @endphp
                        @if ($field['type'] === 'checkbox')
                            <label class="check" for="{{ $field['id'] }}">
                                <input type="checkbox" id="{{ $field['id'] }}" name="f[{{ $field['key'] }}]" value="1" @checked($value === '1') @if ($field['required']) required aria-required="true" @endif @if ($error) aria-invalid="true" aria-describedby="{{ $describedBy }}" @endif>
                                <span>{{ $field['label'] }}@if ($field['required']) <span class="req">({{ __('obligatorio') }})</span>@endif</span>
                            </label>
                        @else
                            <label class="field" for="{{ $field['id'] }}">{{ $field['label'] }}@if (! $field['required']) <span class="req">({{ __('opcional') }})</span>@endif</label>
                            @if ($field['type'] === 'textarea')
                                <textarea id="{{ $field['id'] }}" name="f[{{ $field['key'] }}]" maxlength="2000" @if ($field['placeholder'] !== '') placeholder="{{ $field['placeholder'] }}" @endif @if ($field['required']) required aria-required="true" @endif @if ($error) aria-invalid="true" aria-describedby="{{ $describedBy }}" @endif>{{ $value }}</textarea>
                            @elseif ($field['type'] === 'select')
                                <select id="{{ $field['id'] }}" name="f[{{ $field['key'] }}]" @if ($field['required']) required aria-required="true" @endif @if ($error) aria-invalid="true" aria-describedby="{{ $describedBy }}" @endif>
                                    <option value="">{{ __('Elige una opción') }}</option>
                                    @foreach ($field['options'] as $option)
                                        <option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="{{ $field['type'] === 'email' ? 'email' : ($field['type'] === 'tel' ? 'tel' : 'text') }}" id="{{ $field['id'] }}" name="f[{{ $field['key'] }}]" value="{{ $value }}"
                                    maxlength="{{ $field['key'] === 'phone' ? 40 : 190 }}" autocomplete="{{ $field['autocomplete'] }}" @if ($field['inputmode']) inputmode="{{ $field['inputmode'] }}" @endif
                                    @if ($field['placeholder'] !== '') placeholder="{{ $field['placeholder'] }}" @endif @if ($field['required']) required aria-required="true" @endif @if ($error) aria-invalid="true" aria-describedby="{{ $describedBy }}" @endif>
                            @endif
                        @endif
                        @if ($error)<p class="error" id="{{ $describedBy }}">{{ $error }}</p>@endif
                        @foreach ($consents as $consent)
                            @if ($consent['after'] === $field['key'])
                                <label class="check">
                                    <input type="checkbox" name="consent[{{ $consent['channel'] }}]" value="1" @checked($consent['checked'])>
                                    <span>{{ $consent['label'] }}</span>
                                </label>
                            @endif
                        @endforeach
                    @endforeach
                    <div style="margin-top:20px">
                        <button type="submit" class="btn" @disabled($preview)>{{ $button }}</button>
                    </div>
                    <p class="legal">
                        {{ __(':business usará tus datos solo para responder tu solicitud.', ['business' => $business]) }}
                        {{ __('Puedes conocer, actualizar, rectificar o suprimir tus datos cuando quieras.') }}
                        @if ($privacyUrl)<a href="{{ $privacyUrl }}" target="_blank" rel="noopener noreferrer">{{ __('Política de tratamiento de datos') }}</a>@endif
                    </p>
                </form>
                @if ($whatsapp)
                    <p class="or">{{ __('¿Prefieres escribirnos?') }}</p>
                    <a class="btn wa" href="{{ $whatsapp['url'] }}" rel="noopener">@include('capture._wa_icon'){{ $whatsapp['label'] }}</a>
                @endif
            </div>
        </section>
        @if ($image)
            <div class="media"><img class="hero" src="{{ $image }}" alt="" referrerpolicy="no-referrer" loading="lazy" width="1200" height="750"></div>
        @endif
    </main>
@endsection
@section('scripts')
<script nonce="{{ $nonce }}">
    (function () {
        var f = document.getElementById('capture-form'), e = document.getElementById('form-errors');
        if (e) { e.focus(); }
        if (f) { f.addEventListener('submit', function (ev) { var b = f.querySelector('button[type=submit]'); if (b.dataset.sent) { ev.preventDefault(); return; } b.dataset.sent = '1'; b.textContent = @json(__('Enviando…')); }); }
    })();
</script>
@endsection
