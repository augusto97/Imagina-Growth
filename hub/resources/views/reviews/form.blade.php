@extends('reviews.layout')
@section('title', __('Tu opinión'))
@section('head')
    <style>
        .products { display: grid; gap: 8px; margin: 0 0 6px; }
        .product { display: flex; align-items: center; gap: 12px; padding: 10px; border: 1px solid var(--line); border-radius: 12px; cursor: pointer; }
        .product img, .product .ph { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; background: var(--soft); flex: none; }
        .product input { width: 20px; height: 20px; accent-color: var(--accent); flex: none; }
        .product.done { opacity: .6; cursor: default; }
        .product:has(input:checked) { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
        fieldset { border: 0; padding: 0; margin: 0; min-width: 0; }
        legend { font-weight: 600; font-size: 14px; margin: 16px 0 6px; padding: 0; }
        .stars { display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 4px; }
        .stars input { position: absolute; opacity: 0; width: 1px; height: 1px; }
        .stars label { font-size: 44px; line-height: 1; color: var(--star-off); cursor: pointer; padding: 2px; user-select: none; -webkit-tap-highlight-color: transparent; }
        .stars input:checked ~ label, .stars label:hover, .stars label:hover ~ label { color: var(--star); }
        .stars input:focus-visible + label { outline: 3px solid var(--accent); border-radius: 6px; }
        .stars-hint { min-height: 20px; color: var(--muted); font-size: 14px; }
        .drop { display: block; border: 1px dashed var(--line); border-radius: 12px; padding: 14px; text-align: center; color: var(--muted); font-size: 14px; cursor: pointer; }
        .drop input { display: block; margin: 8px auto 0; max-width: 100%; }
        .previews { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .previews figure { margin: 0; width: 72px; height: 72px; border-radius: 10px; overflow: hidden; background: var(--soft); display: flex; align-items: center; justify-content: center; font-size: 12px; color: var(--muted); }
        .previews img { width: 100%; height: 100%; object-fit: cover; }
        .reward { background: var(--soft); border-radius: 10px; padding: 10px 12px; font-size: 14px; margin: 14px 0 0; }
        .actions { margin-top: 20px; }
    </style>
@endsection
@section('content')
    <h1>{{ $firstName !== '' ? __('¿Qué te pareció tu compra, :name?', ['name' => $firstName]) : __('¿Qué te pareció tu compra?') }}</h1>
    <p class="muted">{{ __('Te toma un minuto y ayuda a otras personas a decidir.') }}</p>

    @if (count($errors) > 0)
        <div class="alert" role="alert">{{ __('Revisa los campos marcados.') }}</div>
    @endif

    <form method="post" action="{{ $action }}" enctype="multipart/form-data" id="review-form" novalidate>
        @if (count($products) > 1)
            <fieldset>
                <legend>{{ __('¿Qué quieres calificar?') }}</legend>
                <div class="products">
                    @foreach ($products as $p)
                        <label class="product {{ $p['reviewed'] ? 'done' : '' }}">
                            <input type="radio" name="product" value="{{ $p['id'] }}" @checked((string) $selected === $p['id']) @disabled($p['reviewed'])>
                            @if ($p['image'])
                                <img src="{{ $p['image'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                            @else
                                <span class="ph" aria-hidden="true"></span>
                            @endif
                            <span>
                                {{ $p['name'] }}
                                @if ($p['reviewed'])
                                    <br><span class="ok small">✓ {{ __('Ya la calificaste') }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @if (isset($errors['product']))<p class="error">{{ $errors['product'] }}</p>@endif
            </fieldset>
        @else
            <input type="hidden" name="product" value="{{ $selected }}">
        @endif

        <fieldset>
            <legend id="rating-label">{{ __('Tu calificación') }}</legend>
            <div class="stars" role="radiogroup" aria-labelledby="rating-label">
                @for ($i = 5; $i >= 1; $i--)
                    <input type="radio" id="star-{{ $i }}" name="rating" value="{{ $i }}" @checked((int) ($old['rating'] ?? $rating) === $i) required>
                    <label for="star-{{ $i }}" title="{{ trans_choice(':count estrella|:count estrellas', $i) }}"><span aria-hidden="true">★</span><span class="sr">{{ trans_choice(':count estrella|:count estrellas', $i) }}</span></label>
                @endfor
            </div>
            <div class="stars-hint" id="stars-hint" aria-live="polite"></div>
            @if (isset($errors['rating']))<p class="error">{{ $errors['rating'] }}</p>@endif
        </fieldset>

        <label class="field" for="title">{{ __('Título (opcional)') }}</label>
        <input type="text" id="title" name="title" maxlength="120" value="{{ $old['title'] ?? '' }}" placeholder="{{ __('Ej.: Excelente cubrimiento') }}">
        @if (isset($errors['title']))<p class="error">{{ $errors['title'] }}</p>@endif

        <label class="field" for="body">{{ __('Cuéntanos más (opcional)') }}</label>
        <textarea id="body" name="body" maxlength="3000" placeholder="{{ __('¿Qué te gustó? ¿Cómo lo usaste?') }}">{{ $old['body'] ?? '' }}</textarea>
        @if (isset($errors['body']))<p class="error">{{ $errors['body'] }}</p>@endif

        <label class="field" for="media">{{ $photos ? __('Fotos o video (opcional)') : __('Video (opcional)') }}</label>
        <label class="drop">
            {{ $photos ? __('Hasta :n archivos. Un video de hasta :mb MB.', ['n' => $maxFiles, 'mb' => $videoMb]) : __('Un video de hasta :mb MB.', ['mb' => $videoMb]) }}
            <input type="file" id="media" name="media[]" multiple accept="{{ $photos ? 'image/jpeg,image/png,image/webp,video/mp4,video/quicktime' : 'video/mp4,video/quicktime' }}" data-max="{{ $maxFiles }}">
        </label>
        <div class="previews" id="previews" aria-live="polite"></div>
        @if (isset($errors['media']))<p class="error">{{ $errors['media'] }}</p>@endif
        @foreach ($errors as $k => $e)
            @if (str_starts_with((string) $k, 'media.'))<p class="error">{{ $e }}</p>@endif
        @endforeach

        @if (($rewards['review'] ?? 0) > 0 || ($rewards['media'] ?? 0) > 0)
            <p class="reward">
                {{ __('Te damos :points puntos por tu reseña', ['points' => $rewards['review']]) }}@if (($rewards['media'] ?? 0) > 0){{ __(' y :points más si subes una foto o video', ['points' => $rewards['media']]) }}@endif.
            </p>
        @endif

        <label class="field" for="author_name">{{ __('Así aparecerá tu nombre') }}</label>
        <input type="text" id="author_name" name="author_name" maxlength="40" required value="{{ $author }}" autocomplete="off">
        @if (isset($errors['author_name']))<p class="error">{{ $errors['author_name'] }}</p>@endif
        <p class="muted small" style="margin-top:8px">{{ __('Tu reseña puede publicarse en la tienda. No escribas datos personales como tu teléfono o tu dirección.') }}</p>

        <div class="actions">
            <button type="submit" class="block" id="submit">{{ __('Enviar mi reseña') }}</button>
        </div>
    </form>
@endsection
@section('scripts')
<script>
(function () {
    var labels = {1: @json(__('Muy malo')), 2: @json(__('Malo')), 3: @json(__('Regular')), 4: @json(__('Bueno')), 5: @json(__('Excelente'))};
    var hint = document.getElementById('stars-hint');
    function showHint() { var c = document.querySelector('input[name=rating]:checked'); hint.textContent = c ? labels[c.value] : ''; }
    document.querySelectorAll('input[name=rating]').forEach(function (i) { i.addEventListener('change', showHint); });
    showHint();

    // Fotos: se reducen en el navegador (máx. 1600 px, JPEG) para subir rápido con datos móviles.
    // El servidor las vuelve a procesar y les quita los metadatos de todas formas.
    var input = document.getElementById('media');
    var previews = document.getElementById('previews');
    var form = document.getElementById('review-form');
    var max = parseInt(input.getAttribute('data-max'), 10) || 5;
    var busy = false;
    function shrink(file) {
        return new Promise(function (resolve) {
            if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) { resolve(file); return; }
            createImageBitmap(file).then(function (bmp) {
                var scale = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(bmp.width * scale); canvas.height = Math.round(bmp.height * scale);
                var ctx = canvas.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(function (blob) { resolve(blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', {type: 'image/jpeg'}) : file); }, 'image/jpeg', 0.85);
            }).catch(function () { resolve(file); });
        });
    }
    input.addEventListener('change', function () {
        var files = Array.prototype.slice.call(input.files, 0, max);
        previews.innerHTML = '';
        files.forEach(function (f) {
            var fig = document.createElement('figure');
            if (/^image\//.test(f.type)) { var img = document.createElement('img'); img.alt = ''; img.src = URL.createObjectURL(f); fig.appendChild(img); }
            else { fig.textContent = '▶ ' + @json(__('Video')); }
            previews.appendChild(fig);
        });
        if (!window.DataTransfer) { return; }
        busy = true;
        Promise.all(files.map(shrink)).then(function (out) {
            var dt = new DataTransfer(); out.forEach(function (f) { dt.items.add(f); }); input.files = dt.files; busy = false;
        }).catch(function () { busy = false; });
    });
    form.addEventListener('submit', function (e) {
        if (busy) { e.preventDefault(); setTimeout(function () { form.requestSubmit ? form.requestSubmit() : form.submit(); }, 300); return; }
        if (!document.querySelector('input[name=rating]:checked')) { e.preventDefault(); hint.textContent = @json(__('Elige de 1 a 5 estrellas.')); hint.style.color = 'var(--err)'; return; }
        var btn = document.getElementById('submit'); btn.disabled = true; btn.textContent = @json(__('Enviando…'));
    });
})();
</script>
@endsection
