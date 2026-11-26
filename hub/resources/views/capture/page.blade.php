@extends('capture.layout', ['template' => $template, 'sticky' => ! $preview && (count($benefits) > 0 || count($faq) > 0 || $reviews !== null)])
@section('title', $title)
@section('description', \Illuminate\Support\Str::limit($paragraphs[0] ?? $title, 155))
@section('content')
    @php
        $stars = fn (float|int $n) => str_repeat('★', (int) round($n)).str_repeat('☆', 5 - (int) round($n));
        $avg = $reviews !== null ? number_format((float) $reviews['avg'], 1, ',', '.') : null;
        $long = count($benefits) > 0 || count($faq) > 0 || $reviews !== null;
    @endphp
    <section class="hero {{ $template === 'hero' && $image ? 'has-img' : '' }}" aria-labelledby="page-title">
        @if ($template === 'hero' && $image)
            <img class="hero-bg" src="{{ $image }}" alt="" referrerpolicy="no-referrer">
        @endif
        <div class="wrap">
            <header class="brand">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $business }}" referrerpolicy="no-referrer" width="160" height="48">
                @else
                    <span>{{ $business }}</span>
                @endif
            </header>
            @if ($preview)<p class="notice" role="status">{{ __('Vista previa: así se verá la página cuando la publiques. Los envíos de esta vista no se guardan.') }}</p>@endif
            <div class="hero-in">
                <div class="lead">
                    @if ($eyebrow !== '')<span class="eyebrow">{{ $eyebrow }}</span>@endif
                    <h1 id="page-title">{{ $title }}</h1>
                    @foreach ($paragraphs as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                    @if (count($benefits) > 0)
                        <ul class="benefits">
                            @foreach ($benefits as $benefit)
                                <li><span class="tick" aria-hidden="true"><svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span><span>{{ $benefit }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($reviews !== null)
                        <p class="rating-line"><span class="stars" aria-hidden="true">{{ $stars($reviews['avg']) }}</span><span><strong>{{ $avg }}</strong> · {{ trans_choice(':count reseña|:count reseñas', $reviews['count'], ['count' => $reviews['count']]) }}</span></p>
                    @endif
                </div>
                <div class="form-col" id="formulario">
                    <div class="card">
                        <h2 class="form-title">{{ __('Déjanos tus datos') }}</h2>
                        <p class="form-sub">{{ __('Toma menos de un minuto.') }}</p>
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
                </div>
                @if ($image && $template !== 'hero')
                    <div class="media"><img src="{{ $image }}" alt="" referrerpolicy="no-referrer" loading="lazy" width="1200" height="750"></div>
                @endif
            </div>
        </div>
    </section>

    @if ($reviews !== null && count($reviews['items']) > 0)
        <section class="block" aria-labelledby="reviews-title">
            <div class="wrap">
                <h2 id="reviews-title">{{ __('Lo que dicen nuestros clientes') }}</h2>
                <p class="sub"><span aria-hidden="true" style="color:#f59e0b">{{ $stars($reviews['avg']) }}</span> {{ __(':avg de 5 en :count', ['avg' => $avg, 'count' => trans_choice(':count reseña|:count reseñas', $reviews['count'], ['count' => $reviews['count']])]) }}</p>
                <div class="reviews-grid">
                    @foreach ($reviews['items'] as $review)
                        <figure class="review" style="margin:0">
                            <span class="stars" role="img" aria-label="{{ __(':n de 5 estrellas', ['n' => $review['rating']]) }}">{{ $stars($review['rating']) }}</span>
                            @if ($review['title'])<p style="margin-bottom:0"><strong>{{ $review['title'] }}</strong></p>@endif
                            <p>{{ $review['body'] }}</p>
                            <figcaption><footer>{{ $review['author'] }}</footer></figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if (count($faq) > 0)
        <section class="block" aria-labelledby="faq-title">
            <div class="wrap">
                <div class="faq">
                    <h2 id="faq-title">{{ __('Preguntas frecuentes') }}</h2>
                    <p class="sub">{{ __('Si tienes otra duda, escríbenos.') }}</p>
                    @foreach ($faq as $item)
                        <details>
                            <summary>{{ $item['q'] }}</summary>
                            @if (($item['a'] ?? '') !== '')<p>{{ $item['a'] }}</p>@endif
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($long)
        <section class="block final" aria-label="{{ __('Formulario de contacto') }}">
            <div class="wrap">
                <div class="box">
                    <h2>{{ $title }}</h2>
                    <p class="sub">{{ __('Déjanos tus datos y te respondemos muy pronto.') }}</p>
                    <a class="btn" href="#formulario">{{ $button }}</a>
                </div>
            </div>
        </section>
    @endif

    @if (! $preview && $long)
        <a class="btn sticky-cta" href="#formulario" id="sticky-cta">{{ $button }}</a>
    @endif
@endsection
@section('scripts')
    @unless ($preview)
        <script nonce="{{ $nonce }}">
            (function () {
                var f = document.getElementById('capture-form'), e = document.getElementById('form-errors'), cta = document.getElementById('sticky-cta'), col = document.getElementById('formulario');
                if (e) { e.focus(); }
                if (f) { f.addEventListener('submit', function (ev) { var b = f.querySelector('button[type=submit]'); if (b.dataset.sent) { ev.preventDefault(); return; } b.dataset.sent = '1'; b.textContent = @json(__('Enviando…')); }); }
                if (cta && col && 'IntersectionObserver' in window) { new IntersectionObserver(function (en) { cta.hidden = en[0].isIntersecting; }).observe(col); }
            })();
        </script>
    @endunless
@endsection
