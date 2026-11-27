<?php

use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;

beforeEach(function () {
    config(['igr-messaging.links_url' => 'https://go.test']);
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->ws->forceFill(['name' => 'Pinturas La 14'])->save();
    $this->url = "/api/w/{$this->ws->publicId()}/templates";
    $this->marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
});

function smsPayload(string $text, array $extra = []): array
{
    return $extra + ['channel' => 'sms', 'name' => 'Carrito', 'category' => 'transactional', 'body_text' => $text];
}

it('guarda un SMS que en el peor caso cabe en uno y no uno que se partiría', function () {
    // Pinturas La 14 (14) + texto + enlace rastreado https://go.test/c/XXXXXXXX (26) = cabe.
    $ok = $this->actingAs($this->marketing)->postJson($this->url, smsPayload('{{ tienda.nombre }}: tu pedido va en camino. Siguelo: {{ pedido.url_pago }}'))
        ->assertCreated();
    expect($ok->json('sms_limits'))->toBe(['link_length' => 26, 'unsubscribe_length' => $ok->json('sms_limits.unsubscribe_length'), 'store_name_length' => 14])
        ->and($ok->json('sms_limits.unsubscribe_length'))->toBeGreaterThan(100)
        ->and($ok->json('sms_allow_multipart'))->toBeFalse()
        ->and(array_column($ok->json('checks'), 'code'))->not->toContain('sms_too_long');

    // El nombre del producto cuenta 40: 150 de texto fijo + 40 no caben en 160.
    $long = str_repeat('a', 150).' {{ producto.nombre }}';
    $this->actingAs($this->marketing)->postJson($this->url, smsPayload($long))
        ->assertUnprocessable()->assertJsonValidationErrors(['body_text'])
        ->assertJsonPath('errors.body_text.0', fn (string $m) => str_contains($m, '191 de 160') && str_contains($m, 'quita 31'));

    // Editarlo para que se pase tampoco se guarda; renombrarlo sí.
    $id = $ok->json('id');
    $this->actingAs($this->marketing)->patchJson("{$this->url}/{$id}", ['body_text' => $long])->assertUnprocessable()->assertJsonValidationErrors(['body_text']);
    $this->actingAs($this->marketing)->patchJson("{$this->url}/{$id}", ['name' => 'Pedido en camino'])->assertOk();
});

it('solo el dueño permite SMS de 2 partes, y aun así no de 3', function () {
    $long = str_repeat('a', 200);
    $this->actingAs($this->marketing)->postJson($this->url, smsPayload($long, ['sms_allow_multipart' => true]))
        ->assertUnprocessable()->assertJsonValidationErrors(['sms_allow_multipart']);

    $id = $this->actingAs($this->owner)->postJson($this->url, smsPayload($long, ['sms_allow_multipart' => true]))->assertCreated()
        ->assertJsonPath('sms_allow_multipart', true)->json('id');
    // Marketing edita el texto sin tocar el permiso: lo que ya permitió el dueño sigue.
    $this->actingAs($this->marketing)->patchJson("{$this->url}/{$id}", ['body_text' => str_repeat('b', 300)])->assertOk();
    $this->actingAs($this->marketing)->patchJson("{$this->url}/{$id}", ['body_text' => str_repeat('b', 307)])
        ->assertUnprocessable()->assertJsonPath('errors.body_text.0', fn (string $m) => str_contains($m, 'de 306'));
    // Quitar el permiso también es del dueño.
    $this->actingAs($this->marketing)->patchJson("{$this->url}/{$id}", ['sms_allow_multipart' => false])->assertUnprocessable();
    $this->actingAs($this->owner)->patchJson("{$this->url}/{$id}", ['sms_allow_multipart' => false])
        ->assertUnprocessable()->assertJsonValidationErrors(['body_text']);
});

it('dice qué letra pasa el SMS a 70 caracteres y no deja marcar lista una plantilla que ya no cabe', function () {
    $show = $this->actingAs($this->marketing)->postJson($this->url, smsPayload('Tu cotización está lista'))->assertCreated();
    $unicode = collect($show->json('checks'))->firstWhere('code', 'sms_unicode');
    expect($unicode['level'])->toBe('warning')->and($unicode['message'])->toContain('«ó», «á»');

    // Una plantilla de antes, más larga que un SMS, se ve con el error y no se puede marcar lista.
    $legacy = app(CurrentWorkspace::class)->run($this->ws, fn () => Template::query()->create([
        'workspace_id' => $this->ws->id, 'channel' => 'sms', 'name' => 'Vieja', 'category' => 'transactional', 'body_text' => str_repeat('a', 170),
    ]));
    $checks = $this->actingAs($this->marketing)->getJson("{$this->url}/{$legacy->publicId()}")->assertOk()->json('checks');
    expect(collect($checks)->firstWhere('code', 'sms_too_long')['level'] ?? null)->toBe('error');
    $this->actingAs($this->marketing)->patchJson("{$this->url}/{$legacy->publicId()}", ['status' => 'ready'])
        ->assertUnprocessable()->assertJsonValidationErrors(['status']);
});
