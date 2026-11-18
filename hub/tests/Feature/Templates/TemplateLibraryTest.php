<?php

use App\Domain\Templates\Contracts\TemplateRenderer;
use App\Domain\Templates\Data\RenderContext;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Templates\Models\Template;
use App\Domain\Templates\Rendering\LiquidTemplateRenderer;
use App\Domain\Templates\Rendering\SampleData;
use App\Domain\Templates\Support\TemplateChecks;
use App\Support\Tenancy\CurrentWorkspace;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->ws->forceFill(['settings' => ['business_profile' => ['name' => 'Pinturas La 14', 'address' => 'Calle 23 # 21-40', 'contact' => 'hola@tienda.co']] + ($this->ws->settings ?? [])])->save();
});

it('cada plantilla de la biblioteca se renderiza con datos de ejemplo en todas las industrias y pasa sus revisiones', function () {
    $renderer = app(TemplateRenderer::class);
    $count = 0;
    foreach ([null, ...TemplateLibrary::industries()] as $industry) {
        foreach (TemplateLibrary::keys() as $key) {
            $template = new Template(['workspace_id' => $this->ws->id] + TemplateLibrary::attributes($key, $industry));
            $template->wa_template_id = null;
            $rendered = app(CurrentWorkspace::class)->run($this->ws, fn () => app(LiquidTemplateRenderer::class)->renderTemplate($template, new RenderContext($this->ws, null, SampleData::vars())));
            $label = $key.' / '.($industry ?? 'general');
            if ($template->channel === 'email') {
                expect($rendered->subject)->not->toBe('', $label)
                    ->and($rendered->html)->not->toContain('[[', $label)
                    ->and($rendered->html)->not->toContain('{{', $label)
                    ->and($rendered->html)->not->toContain('{%', $label);
            } else {
                expect($rendered->text)->not->toBe('', $label)->and($rendered->text)->not->toContain('{{', $label);
            }
            $errors = array_filter(TemplateChecks::run($template, $this->ws), fn (array $c) => $c['level'] === 'error');
            expect($errors)->toBe([], $label.': '.json_encode(array_values($errors), JSON_UNESCAPED_UNICODE));
            $count++;
        }
    }
    expect($count)->toBe(count(TemplateLibrary::keys()) * (count(TemplateLibrary::industries()) + 1));
    expect($renderer)->toBeInstanceOf(TemplateRenderer::class);
});

it('trae las plantillas que necesitan los 20 flujos, con la copia de la industria', function () {
    $flows = array_unique(array_map(fn (array $e) => (int) $e['flow'], TemplateLibrary::manifest()['templates']));
    sort($flows);
    expect($flows)->toBe(range(1, 20))
        ->and(TemplateLibrary::keys())->toContain('welcome_1', 'abandoned_cart_1', 'abandoned_cart_2', 'abandoned_checkout', 'browse_abandonment', 'payment_pending_1', 'post_purchase', 'review_request', 'replenishment', 'winback_1', 'birthday', 'sms_abandoned_cart');

    $pets = TemplateLibrary::attributes('replenishment', 'mascotas');
    $general = TemplateLibrary::attributes('replenishment');
    expect($pets['body_html'])->toContain('¿Ya se está acabando el concentrado?')
        ->and($general['body_html'])->toContain('¿Ya se te está acabando?')
        ->and($pets['library_key'])->toBe('replenishment');
});

it('instalar es idempotente para los flujos y crea otra copia solo si se pide; usa la industria del espacio de trabajo', function () {
    $this->ws->forceFill(['industry' => 'mascotas'])->save();
    $first = TemplateLibrary::install($this->ws, 'replenishment');
    $again = TemplateLibrary::install($this->ws, 'replenishment');
    expect($again->id)->toBe($first->id)->and($first->status)->toBe('ready')->and($first->body_html)->toContain('concentrado');
    $copy = TemplateLibrary::install($this->ws, 'replenishment', copy: true);
    expect($copy->id)->not->toBe($first->id);

    $url = "/api/w/{$this->ws->publicId()}/templates/library";
    $list = $this->actingAs($this->owner)->getJson($url)->assertOk();
    expect($list->json('industry'))->toBe('mascotas')
        ->and(collect($list->json('data'))->firstWhere('key', 'replenishment')['installed_id'])->toBe($first->publicId());
    $this->actingAs($this->owner)->postJson("{$url}/winback_1", ['industry' => 'moda'])->assertCreated();
    $this->actingAs($this->owner)->postJson("{$url}/winback_1")->assertOk();
    $this->actingAs($this->owner)->postJson("{$url}/no_existe")->assertNotFound();
    $preview = $this->actingAs($this->owner)->getJson("{$url}/abandoned_cart_1/preview?industry=moda")->assertOk();
    expect($preview->json('html'))->toContain('Las tallas se agotan rápido')->and($preview->json('subject'))->toBe('Laura, dejaste algo en tu carrito');
});

it('el HTML compilado de la biblioteca está al día con library.json (build.mjs --check)', function () {
    $node = (new ExecutableFinder)->find('node');
    if ($node === null || ! is_dir(base_path('node_modules/grapesjs-mjml'))) {
        $this->markTestSkipped('Sin Node o sin node_modules: lo revisa el trabajo del frontend.');
    }
    $process = new Process([$node, base_path('app/Domain/Templates/Library/build.mjs'), '--check'], base_path());
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
});

it('un producto del carrito sin imagen no deja una imagen rota en el correo', function () {
    $template = TemplateLibrary::install($this->ws, 'abandoned_cart_1');
    $render = fn (array $item) => app(CurrentWorkspace::class)->run($this->ws, fn () => app(TemplateRenderer::class)->render($template->publicId(), new RenderContext($this->ws->fresh(), null, [
        'carrito' => ['items' => [$item], 'subtotal' => '11800.00', 'url_restaurar' => 'https://tienda.co/?igr_cart=x'],
    ])));
    $without = $render(['nombre' => 'Chazo Plástico 1/4', 'cantidad' => 2, 'precio' => '5900.00', 'url' => 'https://tienda.co/p/chazo']);
    $empty = $render(['nombre' => 'Chazo Plástico 1/4', 'cantidad' => 2, 'precio' => '5900.00', 'url' => 'https://tienda.co/p/chazo', 'imagen' => '']);
    $with = $render(['nombre' => 'Chazo Plástico 1/4', 'cantidad' => 2, 'precio' => '5900.00', 'url' => 'https://tienda.co/p/chazo', 'imagen' => 'https://tienda.co/img/chazo.jpg']);
    expect($without->html)->toContain('Chazo Plástico 1/4')->not->toContain('<img src=""')
        ->and($empty->html)->not->toContain('<img src=""')
        ->and($with->html)->toContain('src="https://tienda.co/img/chazo.jpg"');
});
