<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Referrals\Models\ReferralProgram;
use App\Domain\Templates\Contracts\TemplateRenderer;
use App\Domain\Templates\Data\RenderContext;
use App\Domain\Templates\Exceptions\TemplateRenderException;
use App\Domain\Templates\Models\Template;
use App\Domain\Templates\Rendering\HtmlToText;
use App\Domain\Templates\Support\TemplateChecks;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->ws->forceFill(['settings' => ['business_profile' => ['name' => 'Pinturas La 14', 'address' => 'Calle 23 # 21-40, Manizales', 'contact' => 'hola@tienda.co', 'whatsapp' => '573001234567']] + ($this->ws->settings ?? [])])->save();
    $this->renderer = app(TemplateRenderer::class);
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
    $this->contact = ($this->in)(fn () => Contact::query()->create([
        'workspace_id' => $this->ws->id, 'first_name' => 'Ana <b>', 'last_name' => 'Ruiz', 'city' => 'Manizales',
        'email' => 'ana@ejemplo.com', 'phone' => '+573001234567',
    ]));
});

function templatesTpl($test, array $attributes): Template
{
    return ($test->in)(fn () => Template::query()->create($attributes + ['workspace_id' => $test->ws->id, 'name' => 'Prueba', 'channel' => 'email', 'category' => 'marketing']));
}

function templatesRender($test, Template $template, array $vars = [], bool $withContact = true)
{
    return ($test->in)(fn () => $test->renderer->render($template->publicId(), new RenderContext($test->ws->fresh(), $withContact ? $test->contact : null, $vars)));
}

it('renderiza el correo con contacto, tienda, carrito, cupón y filtros en español', function () {
    $t = templatesTpl($this, [
        'subject' => "{{ contacto.nombre | default: 'Hola' }}, tu carrito de {{ carrito.subtotal | moneda }}",
        'preheader' => 'Vence el {{ cupon.vence | fecha }}',
        'body_html' => '<html><body><p>Hola {{ contacto.nombre }}</p>{% for item in carrito.items %}<li>{{ item.nombre }} × {{ item.cantidad }} — {{ item.precio | moneda }}</li>{% endfor %}<p>Código {{ cupon.codigo }}</p><a href="{{ carrito.url_restaurar }}">Terminar mi compra</a><p>{{ tienda.nombre }} · {{ tienda.direccion }}</p></body></html>',
    ]);
    $r = templatesRender($this, $t, [
        'carrito' => ['items' => [['nombre' => 'Pintura Azul', 'cantidad' => 2, 'precio' => '141000']], 'subtotal' => '282000', 'url_restaurar' => 'https://tienda.co/?igr_cart=a.b&x=1'],
        'cupon' => ['codigo' => 'VUELVE-7K2Q', 'vence' => '2026-11-25'],
    ]);

    // El asunto va sin escapar; el HTML escapa los textos (un nombre con etiquetas no rompe el correo).
    expect($r->subject)->toBe('Ana <b>, tu carrito de $ 282.000')
        ->and($r->preheader)->toBe('Vence el 25 de noviembre de 2026')
        ->and($r->html)->toContain('Hola Ana &lt;b&gt;')
        ->and($r->html)->toContain('Pintura Azul × 2 — $ 141.000')
        ->and($r->html)->toContain('href="https://tienda.co/?igr_cart=a.b&amp;x=1"')
        ->and($r->html)->toContain('Pinturas La 14 · Calle 23 # 21-40, Manizales')
        ->and($r->html)->toContain('data-igr-preheader');
    // Texto derivado del HTML: enlaces como «texto (url)», sin el preheader oculto.
    expect($r->text)->toContain('Terminar mi compra (https://tienda.co/?igr_cart=a.b&x=1)')
        ->and($r->text)->toContain('Código VUELVE-7K2Q')
        ->and($r->text)->not->toContain('Vence el 25');
});

it('expone cualquier variable que llegue del flujo o la campaña (p. ej. `campana`), sin lista fija', function () {
    $t = templatesTpl($this, ['subject' => '{{ campana.nombre }} · variante {{ campana.variante }}', 'body_html' => '<body>{{ campana.nombre }}</body>']);
    $r = templatesRender($this, $t, ['campana' => ['nombre' => 'Black Friday VIP', 'variante' => 'B']]);
    expect($r->subject)->toBe('Black Friday VIP · variante B')->and($r->html)->toContain('Black Friday VIP');
});

it('nunca expone el correo ni el teléfono del contacto, y una variable desconocida queda vacía', function () {
    $t = templatesTpl($this, ['subject' => 'x', 'body_html' => '<body>[{{ contacto.email }}][{{ contacto.phone }}][{{ contacto.telefono }}][{{ algo.que.no.existe }}]{{ contacto.nombre_completo }}</body>']);
    $html = templatesRender($this, $t)->html;
    expect($html)->toContain('[][][][]')->and($html)->not->toContain('ana@ejemplo.com')->and($html)->not->toContain('3001234567');
});

it('el respaldo `default` cubre al contacto sin nombre y la moneda respeta la del espacio de trabajo', function () {
    $this->ws->forceFill(['currency' => 'MXN', 'locale' => 'es-MX'])->save();
    $t = templatesTpl($this, ['subject' => "{{ contacto.nombre | default: 'Hola' }}: {{ 1234.5 | moneda }}", 'body_html' => '<body>x</body>']);
    expect(templatesRender($this, $t, withContact: false)->subject)->toBe('Hola: $1,234.50');
});

it('es un sandbox: no incluye archivos, corta los bucles enormes y rechaza filtros desconocidos', function (string $body, string $message) {
    $t = templatesTpl($this, ['subject' => 'x', 'body_html' => $body]);
    expect(fn () => templatesRender($this, $t))->toThrow(TemplateRenderException::class, $message);
})->with([
    'render' => ["<body>{% render 'secreto' %}</body>", 'no pueden incluir'],
    'bucle' => ['<body>{% for i in (1..5000000) %}xxxxxxxx{% endfor %}</body>', 'demasiado pesada'],
    'filtro' => ['<body>{{ contacto.nombre | ejecutar }}</body>', 'Filtro desconocido'],
    'sintaxis' => ['<body>{% if contacto.nombre %}</body>', 'sintaxis'],
]);

it('un correo sin HTML compilado o de otro espacio de trabajo no se renderiza', function () {
    $t = templatesTpl($this, ['subject' => 'x', 'body_mjml' => '<mjml></mjml>']);
    expect(fn () => templatesRender($this, $t))->toThrow(TemplateRenderException::class, 'no tiene HTML');

    $other = $this->workspaceFor($this->owner, 'Otra tienda');
    $foreign = app(CurrentWorkspace::class)->run($other, fn () => Template::query()->create(['workspace_id' => $other->id, 'name' => 'Ajena', 'channel' => 'email', 'subject' => 'x', 'body_html' => '<body>x</body>']));
    expect(fn () => ($this->in)(fn () => $this->renderer->render($foreign->id, new RenderContext($this->ws, null))))->toThrow(TemplateRenderException::class, 'no existe');
});

it('recomienda los más vendidos de la tienda y, para ti, de las categorías que el contacto ya compró', function () {
    $site = $this->connectedSite($this->ws)[0];
    $product = fn (string $id, string $name, array $cats) => DB::table('products')->insert([
        'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $site->id, 'external_id' => $id, 'type' => 'simple',
        'name' => $name, 'permalink' => "https://tienda.co/p/{$id}", 'price' => '10000', 'stock_status' => 'instock', 'status' => 'publish',
        'category_ids' => json_encode($cats), 'content_hash' => md5($id),
    ]);
    $product('1', 'Pintura Azul', ['12']);
    $product('2', 'Rodillo', ['12']);
    $product('3', 'Martillo', ['30']);
    $order = strtoupper((string) Str::ulid());
    DB::table('orders')->insert(['id' => $order, 'workspace_id' => $this->ws->id, 'site_id' => $site->id, 'external_id' => '99', 'contact_id' => $this->contact->id, 'status' => 'completed', 'is_paid' => true, 'currency' => 'COP', 'total' => '20000', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('order_items')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'order_id' => $order, 'external_product_id' => '1', 'name' => 'Pintura Azul', 'category_ids' => json_encode(['12']), 'qty' => 5, 'unit_price' => '10000', 'line_total' => '50000']);

    $t = templatesTpl($this, ['subject' => 'x', 'body_html' => '<body>{% for p in recomendaciones.para_ti limit: 3 %}[{{ p.nombre }}]{% endfor %}|{% for p in recomendaciones.mas_vendidos limit: 1 %}{{ p.nombre }}{% endfor %}</body>']);
    // Para ti: de la categoría 12 que no tiene (Rodillo), no la Pintura que ya compró ni el Martillo de otra categoría.
    expect(templatesRender($this, $t)->html)->toContain('[Rodillo]|Pintura Azul')->not->toContain('[Martillo]')->not->toContain('[Pintura Azul]');
});

it('arma los parámetros de WhatsApp en el formato de la Cloud API', function () {
    $waId = strtoupper((string) Str::ulid());
    DB::table('wa_templates')->insert([
        'id' => $waId, 'workspace_id' => $this->ws->id, 'channel_account_id' => strtoupper((string) Str::ulid()), 'name' => 'carrito_1', 'language' => 'es_CO',
        'category' => 'MARKETING', 'status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now(),
        'components' => json_encode([
            ['type' => 'HEADER', 'format' => 'IMAGE'],
            ['type' => 'BODY', 'text' => 'Hola {{1}}, tu carrito de {{2}} te espera.'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Ver carrito', 'url' => 'https://tienda.co/{{1}}'],
                ['type' => 'QUICK_REPLY', 'text' => 'No me interesa'],
                ['type' => 'COPY_CODE', 'text' => 'Copiar código'],
            ]],
        ]),
    ]);
    $t = templatesTpl($this, ['channel' => 'whatsapp', 'wa_template_id' => $waId, 'variables' => [
        'header_media' => '{{ producto.imagen }}',
        'body' => ['1' => "{{ contacto.nombre | default: 'hola' }}", '2' => "{{ carrito.subtotal | moneda }}\n"],
        'buttons' => ['0' => '{{ carrito.ruta }}', '2' => '{{ cupon.codigo }}'],
    ]]);
    $r = templatesRender($this, $t, ['producto' => ['imagen' => 'https://cdn.tienda.co/a.jpg'], 'carrito' => ['subtotal' => '282000', 'ruta' => '?igr_cart=abc'], 'cupon' => ['codigo' => 'VUELVE-1']]);

    expect($r->waTemplateName)->toBe('carrito_1')->and($r->waLanguage)->toBe('es_CO')
        ->and($r->waComponents)->toBe([
            ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://cdn.tienda.co/a.jpg']]]],
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ana <b>'], ['type' => 'text', 'text' => '$ 282.000']]],
            ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => '?igr_cart=abc']]],
            ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 1, 'parameters' => [['type' => 'payload', 'payload' => 'no_me_interesa']]],
            ['type' => 'button', 'sub_type' => 'copy_code', 'index' => 2, 'parameters' => [['type' => 'coupon_code', 'coupon_code' => 'VUELVE-1']]],
        ]);

    // Un parámetro vacío es un error (WhatsApp lo rechazaría); una plantilla sin aprobar también.
    expect(fn () => templatesRender($this, $t, ['producto' => ['imagen' => 'x'], 'carrito' => ['subtotal' => '1']]))->toThrow(TemplateRenderException::class, 'quedó vacía');
    DB::table('wa_templates')->where('id', $waId)->update(['status' => 'PENDING']);
    expect(fn () => templatesRender($this, $t))->toThrow(TemplateRenderException::class, 'no está aprobada');
});

it('acepta parámetros con nombre de WhatsApp', function () {
    $waId = strtoupper((string) Str::ulid());
    DB::table('wa_templates')->insert(['id' => $waId, 'workspace_id' => $this->ws->id, 'channel_account_id' => strtoupper((string) Str::ulid()), 'name' => 'pago', 'language' => 'es', 'category' => 'UTILITY', 'status' => 'APPROVED',
        'components' => json_encode([['type' => 'BODY', 'text' => 'Tu pedido {{numero}} espera el pago.']]), 'created_at' => now(), 'updated_at' => now()]);
    $t = templatesTpl($this, ['channel' => 'whatsapp', 'category' => 'utility', 'wa_template_id' => $waId, 'variables' => ['body' => ['numero' => '#{{ pedido.numero }}']]]);
    expect(templatesRender($this, $t, ['pedido' => ['numero' => '10620']])->waComponents)
        ->toBe([['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '#10620', 'parameter_name' => 'numero']]]]);
});

it('renderiza SMS y cuenta sus partes (GSM-7 o UCS-2)', function () {
    $t = templatesTpl($this, ['channel' => 'sms', 'body_text' => '{{ tienda.nombre }}: tu codigo es {{ cupon.codigo }}. Responde BAJA para no recibir mas.']);
    expect(templatesRender($this, $t, ['cupon' => ['codigo' => 'AB12']])->text)->toBe('Pinturas La 14: tu codigo es AB12. Responde BAJA para no recibir mas.');
    expect(TemplateChecks::smsSegments(str_repeat('a', 160)))->toBe(['encoding' => 'GSM-7', 'length' => 160, 'segments' => 1])
        ->and(TemplateChecks::smsSegments(str_repeat('a', 161))['segments'])->toBe(2)
        ->and(TemplateChecks::smsSegments('Canción {}'))->toMatchArray(['encoding' => 'UCS-2', 'segments' => 1])
        ->and(TemplateChecks::smsSegments('€')['length'])->toBe(2);
});

it('convierte HTML a texto plano legible', function () {
    $html = '<html><head><style>p{color:red}</style><title>t</title></head><body><div style="display:none">oculto</div><h1>Hola&nbsp;Ana</h1><p>Primera<br>línea</p><table><tr><td>Total</td><td>$ 10</td></tr></table><a href="https://x.co/a?b=1&amp;c=2">Ver</a> <a href="https://x.co">https://x.co</a><ul><li>Uno</li></ul></body></html>';
    expect(HtmlToText::convert($html))->toBe("Hola Ana\nPrimera\nlínea\nTotal $ 10\n\nVer (https://x.co/a?b=1&c=2) https://x.co\n• Uno");
});

it('llena la URL de la tienda, el nivel VIP y el enlace de referido con el programa activo', function () {
    [$site] = $this->connectedSite($this->ws);
    $t = templatesTpl($this, ['subject' => 'Hola', 'body_html' => '<p>{{ tienda.url }}|{{ contacto.nivel }}|{{ enlaces.referido }}</p>']);
    expect(templatesRender($this, $t)->html)->toContain('<p>https://tienda.co||</p>');

    ($this->in)(function () {
        $program = LoyaltyProgram::query()->create(['workspace_id' => $this->ws->id, 'status' => 'active', 'currency_name' => 'Puntos', 'earn_rules' => [], 'redeem_rules' => [], 'expiry_policy' => [], 'wallet_rules' => []]);
        $tier = LoyaltyTier::query()->create(['workspace_id' => $this->ws->id, 'program_id' => $program->id, 'name' => 'Oro', 'rank' => 1, 'threshold_type' => 'spend_12m', 'threshold' => 0]);
        $this->contact->forceFill(['tier_id' => $tier->id])->save();
        ReferralProgram::query()->create(['workspace_id' => $this->ws->id, 'status' => 'active', 'referrer_reward' => [], 'friend_reward' => [], 'rules' => []]);
    });
    $html = templatesRender($this, $t)->html;
    $code = (string) $this->contact->fresh()->referral_code;
    expect($code)->not->toBe('')
        ->and($html)->toContain('<p>https://tienda.co|Oro|https://tienda.co/?ref='.$code.'</p>');
});
