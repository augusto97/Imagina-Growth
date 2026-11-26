{{-- Mejoras con JavaScript (la página funciona sin él): el resumen sigue la hora elegida, la persona cambia sin botón y el formulario no se envía dos veces. --}}
<script>
    (function () {
        var form = document.getElementById(@json($form));
        var box = document.querySelector('[data-when-box]');
        var text = document.querySelector('[data-when-text]');
        var errors = document.getElementById('form-errors');
        if (errors) { errors.focus(); }
        document.querySelectorAll('select[data-autosubmit]').forEach(function (s) {
            s.addEventListener('change', function () { s.form && s.form.submit(); });
        });
        document.querySelectorAll('input[name=start]').forEach(function (r) {
            r.addEventListener('change', function () {
                if (text && r.dataset.when) { text.textContent = r.dataset.when; }
                if (box) { box.classList.add('set'); }
                document.querySelectorAll('[data-steps] li').forEach(function (li) {
                    var n = Number(li.dataset.step);
                    li.classList.toggle('done', n < 3);
                    li.classList.toggle('now', n === 3);
                    if (n === 3) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
                });
            });
        });
        if (form) {
            form.addEventListener('submit', function (e) {
                var b = form.querySelector('button[type=submit]');
                if (!b) { return; }
                if (b.dataset.sent) { e.preventDefault(); return; }
                b.dataset.sent = '1';
                b.textContent = @json($busy);
            });
        }
    })();
</script>
