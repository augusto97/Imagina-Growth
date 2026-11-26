@extends('quotes.layout')

@php
    $oldA = (array) ($old['a'] ?? []);
    $oldC = (array) ($old['c'] ?? []);
    $oldConsent = (array) ($old['consent'] ?? []);
    $total = count($steps) + ($capture !== null ? 1 : 0);
    $contactLabels = ['first_name' => [__('Nombre'), 'text', 'given-name'], 'last_name' => [__('Apellido'), 'text', 'family-name'],
        'phone' => [__('WhatsApp'), 'tel', 'tel'], 'email' => [__('Correo'), 'email', 'email']];
    $num = fn (?float $v) => $v === null ? '' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
@endphp

@section('title', $name)

@section('head')
    <style>
        .top { margin: 0 0 18px; }
        .progress { display: none; align-items: center; gap: 12px; margin: 0 0 20px; }
        .js .progress { display: flex; }
        .progress .track { flex: 1; height: 6px; border-radius: 99px; background: var(--soft-2); overflow: hidden; }
        .progress .fill { height: 100%; width: 0; border-radius: inherit; background: var(--accent); transition: width .35s ease; }
        .progress .count { font-size: 13px; color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
        fieldset.step { border: 0; padding: 0; margin: 0 0 26px; min-width: 0; }
        .js fieldset.step { margin: 0; }
        .js fieldset.step[hidden] { display: none; }
        .js fieldset.step:not([hidden]) { animation: in .28s ease both; }
        @keyframes in { from { opacity: 0; transform: translateX(14px); } to { opacity: 1; transform: none; } }
        legend { display: flex; align-items: flex-start; gap: 10px; padding: 0; margin: 0 0 6px; font-size: 19px; font-weight: 650; line-height: 1.3; letter-spacing: -.005em; width: 100%; }
        legend .ic { font-size: 22px; line-height: 1.2; }
        .help { color: var(--muted); font-size: 14px; margin: 0 0 14px; }
        .hint { display: inline-block; margin: 0 0 14px; padding: 3px 10px; border-radius: 99px; background: var(--soft); color: var(--fg); font-size: 13px; font-weight: 600; }
        .opts { display: grid; gap: 10px; margin-top: 12px; }
        @media (min-width: 520px) { .opts.cols { grid-template-columns: 1fr 1fr; } }
        .opt { position: relative; display: block; }
        .opt input { position: absolute; opacity: 0; inset: 0; margin: 0; cursor: pointer; }
        .opt .box { display: flex; align-items: center; gap: 12px; min-height: 64px; padding: 12px 14px; border: 1.5px solid var(--line); border-radius: 14px; background: var(--card);
            transition: border-color .15s, background .15s, box-shadow .15s, transform .15s; }
        .opt:hover .box { border-color: var(--line-strong); }
        .opt input:checked + .box { border-color: var(--accent); background: var(--soft); box-shadow: 0 0 0 1px var(--accent) inset; }
        .opt input:focus-visible + .box { outline: 3px solid rgba(var(--accent-rgb), .55); outline-offset: 2px; }
        .opt .emoji { flex: none; display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: var(--soft); font-size: 22px; }
        .opt .txt { flex: 1; min-width: 0; }
        .opt .txt strong { display: block; font-size: 15px; font-weight: 600; line-height: 1.3; }
        .opt .txt span { display: block; color: var(--muted); font-size: 13px; line-height: 1.35; margin-top: 2px; }
        .opt .price { flex: none; font-size: 13px; font-weight: 600; color: var(--muted); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .opt .mark { flex: none; width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid var(--line-strong); display: grid; place-items: center; transition: all .15s; }
        .opt input[type=checkbox] + .box .mark { border-radius: 7px; }
        .opt input:checked + .box .mark { background: var(--accent); border-color: var(--accent); }
        .opt input:checked + .box .mark::after { content: ""; width: 10px; height: 6px; border: 2px solid var(--accent-fg); border-top: 0; border-right: 0; transform: rotate(-45deg) translate(1px, -1px); }
        .numrow { display: flex; align-items: stretch; gap: 8px; margin-top: 12px; }
        .numrow button { flex: none; width: 52px; border: 1.5px solid var(--line); border-radius: 14px; background: var(--card); font: inherit; font-size: 24px; color: var(--fg); cursor: pointer; display: none; }
        .js .numrow button { display: block; }
        .numrow button:hover { border-color: var(--line-strong); }
        .numwrap { position: relative; flex: 1; }
        .numwrap input { width: 100%; height: 60px; padding: 0 70px 0 16px; border: 1.5px solid var(--line); border-radius: 14px; font: inherit; font-size: 24px; font-weight: 650;
            font-variant-numeric: tabular-nums; color: var(--fg); background: var(--card); -moz-appearance: textfield; }
        .numwrap input::-webkit-outer-spin-button, .numwrap input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .numwrap input:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px var(--soft-2); }
        .numwrap .unit { position: absolute; right: 16px; top: 50%; transform: translateY(-50%); color: var(--muted); font-weight: 600; pointer-events: none; }
        .limits { color: var(--muted); font-size: 13px; margin: 8px 0 0; }
        label.field { display: block; font-weight: 600; font-size: 14px; margin: 14px 0 6px; }
        input.text { width: 100%; font: inherit; padding: 12px 14px; border-radius: 12px; border: 1.5px solid var(--line); background: var(--card); color: var(--fg); min-height: 50px; }
        input.text:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px var(--soft-2); }
        .check { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; color: var(--muted); margin: 14px 0 0; }
        .check input { width: 20px; height: 20px; margin: 1px 0 0; accent-color: var(--accent); flex: none; }
        .error { color: var(--err); font-size: 14px; margin: 8px 0 0; }
        .error:empty { display: none; }
        .alert { border: 1px solid var(--err); color: var(--err); border-radius: 12px; padding: 10px 12px; margin: 0 0 16px; font-size: 14px; }
        .nav { display: flex; gap: 10px; margin-top: 24px; }
        .nav .btn { flex: 1; }
        .nav .ghost { flex: 0 0 auto; display: none; }
        .js .nav .ghost:not([hidden]) { display: inline-flex; }
        .js .nav [hidden] { display: none; }
        .next { display: none; }
        .js .next:not([hidden]) { display: inline-flex; }
        .trap { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        .note { margin: 16px 0 0; color: var(--muted); font-size: 13px; text-align: center; }
    </style>
@endsection

@section('content')
    <div class="card">
        <div class="top">
            <h1>{{ $name }}</h1>
            @if ($intro)
                <p class="muted">{{ $intro }}</p>
            @endif
        </div>

        <div class="progress" aria-hidden="true">
            <div class="track"><div class="fill" data-fill></div></div>
            <span class="count" data-count></span>
        </div>
        <p class="sr" aria-live="polite" data-live></p>

        @if (!empty($errors['form']))
            <p class="alert" role="alert">{{ $errors['form'] }}</p>
        @endif

        <form id="q" method="{{ $method }}" action="{{ $action }}" novalidate data-total="{{ $total }}"
              data-l-step="{{ __('Paso :n de :total') }}" data-l-pick="{{ __('Elige una opción para seguir.') }}"
              data-l-number="{{ __('Escribe un número válido.') }}" data-l-range="{{ __('Escribe un número entre :min y :max.') }}"
              data-l-busy="{{ __('Calculando…') }}">
            @foreach ($utm as $k => $v)
                <input type="hidden" name="utm[{{ $k }}]" value="{{ $v }}">
            @endforeach

            @foreach ($steps as $i => $step)
                @php
                    $err = $step['key'] === 'package' ? ($errors['answers.package'] ?? null) : ($errors['answers.'.$step['key']] ?? null);
                    $eid = 'e-'.$i;
                @endphp
                <fieldset class="step" data-step data-type="{{ $step['type'] }}" data-required="{{ $step['required'] ? '1' : '0' }}" aria-describedby="{{ $eid }}">
                    <legend>
                        @if ($step['icon'])<span class="ic" aria-hidden="true">{{ $step['icon'] }}</span>@endif
                        <span>{{ $step['label'] }}@if ($step['type'] === 'checkbox') <span class="sr">{{ __('(opcional, puedes elegir varias)') }}</span>@endif</span>
                    </legend>
                    @if ($step['help'])
                        <p class="help">{{ $step['help'] }}</p>
                    @elseif ($step['type'] === 'checkbox')
                        <p class="help">{{ __('Elige las que quieras, o sigue sin elegir ninguna.') }}</p>
                    @endif

                    @if ($step['type'] === 'number')
                        @php $value = $oldA[$step['key']] ?? $num($step['default']); @endphp
                        @if (!empty($step['price']))
                            <span class="hint">{{ $step['price'] }}</span>
                        @endif
                        <div class="numrow">
                            <button type="button" data-dec aria-label="{{ __('Menos') }}">−</button>
                            <div class="numwrap">
                                <input id="f-{{ $i }}" name="a[{{ $step['key'] }}]" type="number" inputmode="decimal" value="{{ $value }}"
                                       min="{{ $num($step['min']) }}" @if ($step['max'] !== null) max="{{ $num($step['max']) }}" @endif step="{{ $num($step['step']) }}"
                                       data-min="{{ $num($step['min']) }}" data-max="{{ $num($step['max']) }}" @if ($step['required']) required @endif
                                       aria-label="{{ $step['label'] }}" aria-describedby="{{ $eid }}">
                                @if ($step['unit'])<span class="unit">{{ $step['unit'] }}</span>@endif
                            </div>
                            <button type="button" data-inc aria-label="{{ __('Más') }}">+</button>
                        </div>
                        @if ($step['max'] !== null)
                            <p class="limits">{{ __('Entre :min y :max', ['min' => $num($step['min']), 'max' => $num($step['max'])]) }} {{ $step['unit'] }}</p>
                        @endif
                    @else
                        @php
                            $multi = $step['type'] === 'checkbox';
                            $chosen = $multi ? (array) ($oldA[$step['key']] ?? []) : (string) ($oldA[$step['key']] ?? '');
                            $cols = count($step['options']) >= 4 && collect($step['options'])->every(fn ($o) => empty($o['description']) && mb_strlen($o['label']) <= 22);
                        @endphp
                        <div class="opts {{ $cols ? 'cols' : '' }}">
                            @foreach ($step['options'] as $o)
                                <label class="opt">
                                    <input type="{{ $multi ? 'checkbox' : 'radio' }}" name="a[{{ $step['key'] }}]{{ $multi ? '[]' : '' }}" value="{{ $o['value'] }}"
                                           @checked($multi ? in_array($o['value'], $chosen, true) : $chosen === $o['value'])>
                                    <span class="box">
                                        @if ($o['icon'])<span class="emoji" aria-hidden="true">{{ $o['icon'] }}</span>@endif
                                        <span class="txt"><strong>{{ $o['label'] }}</strong>@if ($o['description'])<span>{{ $o['description'] }}</span>@endif</span>
                                        @if ($o['price'])<span class="price">{{ $o['price'] }}</span>@endif
                                        <span class="mark" aria-hidden="true"></span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    <p class="error" id="{{ $eid }}" data-error>{{ $err }}</p>
                </fieldset>
            @endforeach

            @if ($capture !== null)
                <fieldset class="step" data-step data-type="contact" data-required="1">
                    <legend><span class="ic" aria-hidden="true">📩</span><span>{{ $capture['title'] }}</span></legend>
                    <p class="help">{{ __('Te mostramos el precio en la siguiente pantalla.') }}</p>
                    @foreach ($capture['fields'] as $field)
                        @php [$label, $type, $auto] = $contactLabels[$field]; $fe = $errors['fields.'.$field] ?? null; @endphp
                        <label class="field" for="c-{{ $field }}">{{ $label }}</label>
                        <input class="text" id="c-{{ $field }}" name="c[{{ $field }}]" type="{{ $type }}" autocomplete="{{ $auto }}" value="{{ $oldC[$field] ?? '' }}"
                               @if ($type === 'tel') inputmode="tel" @endif @if ($fe) aria-invalid="true" aria-describedby="ce-{{ $field }}" @endif>
                        @if ($fe)<p class="error" id="ce-{{ $field }}" data-error>{{ $fe }}</p>@endif
                    @endforeach
                    @foreach ($capture['consents'] as $c)
                        <label class="check"><input type="checkbox" name="consent[{{ $c['channel'] }}]" value="1" @checked(!empty($oldConsent[$c['channel']]))><span>{{ $c['label'] }}</span></label>
                    @endforeach
                    <div class="trap" aria-hidden="true"><label>{{ __('No llenes este campo') }} <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                </fieldset>
            @endif

            <div class="nav">
                <button type="button" class="btn ghost" data-back hidden>{{ __('Atrás') }}</button>
                <button type="button" class="btn next" data-next hidden>{{ __('Siguiente') }}</button>
                <button type="submit" class="btn" data-submit>{{ $cta }}</button>
            </div>
        </form>
        @if ($note)
            <p class="note">{{ $note }}</p>
        @endif
    </div>
@endsection

@section('scripts')
<script nonce="{{ $nonce }}">
(function () {
    var f = document.getElementById('q');
    if (!f) return;
    var steps = [].slice.call(f.querySelectorAll('[data-step]'));
    var back = f.querySelector('[data-back]'), next = f.querySelector('[data-next]'), submit = f.querySelector('[data-submit]');
    var fill = document.querySelector('[data-fill]'), count = document.querySelector('[data-count]'), live = document.querySelector('[data-live]');
    var d = f.dataset, i = 0;
    document.documentElement.className += ' js';
    function t(s, o) { for (var k in o) s = s.replace(':' + k, o[k]); return s; }
    function show(n, focus) {
        i = Math.max(0, Math.min(n, steps.length - 1));
        steps.forEach(function (s, k) { s.hidden = k !== i; });
        var last = i === steps.length - 1, label = t(d.lStep, { n: i + 1, total: steps.length });
        fill.style.width = ((i + 1) / steps.length * 100) + '%';
        count.textContent = label; live.textContent = label;
        back.hidden = i === 0; next.hidden = last; submit.hidden = !last;
        if (focus) { var el = steps[i].querySelector('input:not([type=hidden]):not([tabindex="-1"])'); if (el) el.focus({ preventScroll: true }); }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function check(s) {
        var e = s.querySelector('[data-error]'), type = s.dataset.type, msg = '';
        if (type === 'number') {
            var inp = s.querySelector('input'), v = inp.value.replace(',', '.').trim(), n = Number(v), mn = inp.dataset.min, mx = inp.dataset.max;
            if (v === '' ? s.dataset.required === '1' : isNaN(n)) msg = d.lNumber;
            else if (v !== '' && ((mn !== '' && n < Number(mn)) || (mx !== '' && n > Number(mx)))) msg = t(d.lRange, { min: mn || '0', max: mx });
        } else if ((type === 'select' || type === 'package') && s.dataset.required === '1' && !s.querySelector('input:checked')) {
            msg = d.lPick;
        }
        if (e) e.textContent = msg;
        return msg === '';
    }
    steps.forEach(function (s, k) {
        s.addEventListener('change', function (ev) {
            var e = s.querySelector('[data-error]'); if (e && s.dataset.type !== 'contact') e.textContent = '';
            if ((s.dataset.type === 'select' || s.dataset.type === 'package') && ev.target.type === 'radio' && k < steps.length - 1) {
                setTimeout(function () { if (i === k) show(k + 1, true); }, 220);
            }
        });
        var inp = s.querySelector('input[type=number]');
        if (!inp) return;
        function bump(dir) {
            var st = Number(inp.step) || 1, v = Number(inp.value.replace(',', '.')) || 0, n = Math.round((v + dir * st) * 100) / 100;
            if (inp.dataset.min !== '') n = Math.max(n, Number(inp.dataset.min));
            if (inp.dataset.max !== '') n = Math.min(n, Number(inp.dataset.max));
            inp.value = String(n); check(s);
        }
        s.querySelector('[data-dec]').addEventListener('click', function () { bump(-1); });
        s.querySelector('[data-inc]').addEventListener('click', function () { bump(1); });
        inp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && k < steps.length - 1) { ev.preventDefault(); if (check(s)) show(k + 1, true); } });
    });
    next.addEventListener('click', function () { if (check(steps[i])) show(i + 1, true); });
    back.addEventListener('click', function () { show(i - 1, true); });
    f.addEventListener('submit', function (ev) {
        for (var k = 0; k < steps.length; k++) { if (!check(steps[k])) { ev.preventDefault(); show(k, true); return; } }
        submit.disabled = true; submit.textContent = d.lBusy;
    });
    var first = 0;
    for (var k = 0; k < steps.length; k++) { var er = steps[k].querySelector('[data-error]'); if (er && er.textContent.trim() !== '') { first = k; break; } }
    show(first, first > 0);
})();
</script>
@endsection
