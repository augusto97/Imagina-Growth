<?php

use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Messaging\Support\WaTemplateBuilder;
use App\Domain\Templates\Contracts\TemplateRenderer;
use App\Domain\Templates\Data\RenderContext;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Templates\Library\WhatsAppLibrary;
use App\Domain\Templates\Liquid\LiquidSandbox;
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
            $label = $key.' / '.($industry ?? 'general');
            if ($template->channel === 'whatsapp') {
                // WhatsApp: el texto propuesto se renderiza y lo que va a Meta es válido (las revisiones piden la aprobada).
                $text = app(CurrentWorkspace::class)->run($this->ws, fn () => app(LiquidSandbox::class)->render((string) $template->body_text, SampleData::vars() + ['tienda' => ['nombre' => 'Pinturas La 14', 'url' => 'https://tienda.co']], ['currency' => 'COP', 'locale' => 'es_CO', 'timezone' => 'America/Bogota']));
                expect(trim($text))->not->toBe('', $label)->and($text)->not->toContain('{{', $label)->and($template->status)->toBe('draft');
                $count++;

                continue;
            }
            $rendered = app(CurrentWorkspace::class)->run($this->ws, fn () => app(LiquidTemplateRenderer::class)->renderTemplate($template, new RenderContext($this->ws, null, SampleData::vars())));
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

it('trae las plantillas que necesitan los 35 flujos, con la copia de la industria', function () {
    $flows = array_values(array_unique(array_map(fn (array $e) => (int) $e['flow'], array_filter(TemplateLibrary::manifest()['templates'], fn (array $e) => $e['flow'] !== null))));
    sort($flows);
    expect($flows)->toBe(range(1, 35))
        ->and(array_diff(FlowLibrary::EMAIL_KEYS, TemplateLibrary::keys()))->toBe([])
        // Las de WhatsApp de los flujos 21 a 35 traen su texto propuesto para Meta.
        ->and(array_diff(array_slice(FlowLibrary::WHATSAPP_KEYS, array_search('wa_quote_followup_1', FlowLibrary::WHATSAPP_KEYS, true)), TemplateLibrary::keys()))->toBe([])
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
        ->and(collect($list->json('data'))->firstWhere('key', 'replenishment')['installed_id'])->toBe($first->publicId())
        // Sin canal, la lista de siempre (correo y SMS); las de WhatsApp se piden aparte o salen en la galería.
        ->and(collect($list->json('data'))->pluck('channel')->unique()->sort()->values()->all())->not->toContain('whatsapp')
        ->and(collect($this->actingAs($this->owner)->getJson("{$url}?channel=whatsapp")->assertOk()->json('data'))->pluck('channel')->unique()->all())->toBe(['whatsapp'])
        ->and(count($this->actingAs($this->owner)->getJson("{$url}?channel=all")->assertOk()->json('data')))->toBe(count(TemplateLibrary::manifest()['templates']));
    $this->actingAs($this->owner)->getJson("{$url}?channel=fax")->assertUnprocessable();
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

it('las de WhatsApp se convierten en una plantilla válida para Meta, con su categoría y su mapeo', function () {
    $whatsapp = array_filter(TemplateLibrary::manifest()['templates'], fn (array $e) => $e['channel'] === 'whatsapp');
    expect($whatsapp)->not->toBeEmpty();
    $names = [];
    foreach ($whatsapp as $entry) {
        foreach ([null, ...TemplateLibrary::industries()] as $industry) {
            $attrs = TemplateLibrary::attributes($entry['key'], $industry);
            $compiled = WhatsAppLibrary::compile($entry, (string) $attrs['body_text']);
            $label = $entry['key'].' / '.($industry ?? 'general');
            $components = WhatsAppLibrary::components($compiled);
            expect(preg_match('/^[a-z0-9_]{1,512}$/', $compiled['name']))->toBe(1, $label)
                ->and(in_array($entry['category'], ['marketing', 'utility'], true))->toBeTrue($label)
                // Meta no acepta un cuerpo que empiece o termine con una variable ni dos variables seguidas.
                ->and(preg_match('/^\s*\{\{\d+\}\}|\{\{\d+\}\}\s*[.!?]?\s*$|\}\}\s*\{\{/', $compiled['body']))->toBe(0, $label)
                ->and(mb_strlen($compiled['body']))->toBeLessThanOrEqual(1024)
                ->and(count($compiled['examples']))->toBe(count($compiled['variables']['body']))
                ->and($attrs['variables'])->toBe($compiled['variables']);
            // Disfrazar marketing de utilidad está prohibido (cumplimiento.md §5.1).
            if ($entry['category'] === 'utility') {
                expect(WaTemplateBuilder::marketingWords('UTILITY', $components))->toBe([], $label);
            } else {
                expect(str_contains((string) $compiled['footer'], 'BAJA'))->toBeTrue($label);
            }
            foreach (array_keys($compiled['buttons']) as $i) {
                expect(isset($compiled['variables']['buttons'][$i]))->toBeTrue("{$label}: botón {$i} sin valor");
            }
        }
        $names[] = $compiled['name'];
    }
    expect(count(array_unique($names)))->toBe(count($names));

    // La de confirmar la cita usa los botones que ya entiende la agenda.
    $entry = (array) TemplateLibrary::entry('wa_appointment_confirm');
    $confirm = WhatsAppLibrary::compile($entry, (string) TemplateLibrary::attributes('wa_appointment_confirm')['body_text']);
    expect($confirm['category'])->toBe('UTILITY')->and($confirm['variables']['buttons'])->toBe(['appt_confirm', 'appt_reschedule'])
        ->and($confirm['body'])->toStartWith('Hola {{1}}, tienes una cita de {{2}} el {{3}} a las {{4}}.');
});
