<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookSubscription;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Ingest\Events\EventIngested;
use App\Support\Hmac\Signature;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

require_once dirname(__DIR__).'/Api/ApiHelpers.php';

// Webhooks salientes (M19): firma, sin datos personales por defecto, reintentos, pausa, reenvío y prueba.
beforeEach(function () {
    apiEntitlements();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    $this->api = fn (string $path) => '/api/w/'.$this->ws->publicId().'/'.ltrim($path, '/');
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
    $this->orderPaid = fn (?string $contactId = null, string $eventId = '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b7a') => new EventIngested(
        $this->ws->id, $this->site->id, 'order.paid', $eventId, $contactId,
        ['event' => 'order.paid', 'occurred_at' => '2026-11-17T18:05:00-05:00', 'properties' => ['order' => [
            'order_id' => '1234', 'totals' => ['total' => '282000.00'], 'billing' => ['email' => 'ana@ejemplo.com', 'phone' => '3001234567', 'city' => 'Manizales'],
        ]]],
    );
    $this->subscribe = function (array $events = ['order.*'], bool $pii = false): array {
        $res = $this->actingAs($this->owner)->postJson(($this->api)('webhooks'), ['name' => 'CRM', 'url' => 'https://hooks.ejemplo.test/igr', 'events' => $events, 'include_pii' => $pii])->assertCreated();

        return [$res->json('data.id'), $res->json('secret')];
    };
});

it('crea la suscripción con el secreto una sola vez y solo para quien administra la API', function () {
    [$id, $secret] = ($this->subscribe)();
    expect($secret)->toStartWith('whsec_');
    $list = $this->actingAs($this->owner)->getJson(($this->api)('webhooks'))->assertOk();
    expect($list->json('data.0.id'))->toBe($id)->and(json_encode($list->json()))->not->toContain($secret);

    $viewer = $this->addMember($this->ws, WorkspaceRole::Marketing, true);
    $this->actingAs($viewer)->getJson(($this->api)('webhooks'))->assertForbidden();
    $this->actingAs($this->owner)->postJson(($this->api)('webhooks'), ['url' => 'https://hooks.ejemplo.test', 'events' => ['order.inventado']])->assertUnprocessable();
});

it('firma cada entrega con el esquema del connector y no manda datos personales por defecto', function () {
    Http::fake(['hooks.ejemplo.test/*' => Http::response('ok', 200)]);
    [, $secret] = ($this->subscribe)();
    event(($this->orderPaid)());

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request) use ($secret) {
        $body = $request->body();

        return Signature::verify($request->header('X-IGR-Signature')[0] ?? null, $body, [$secret])
            && $request->header('X-IGR-Event')[0] === 'order.paid'
            && str_starts_with($request->header('X-IGR-Delivery')[0], 'whd_')
            && ! str_contains($body, 'ana@ejemplo.com') && ! str_contains($body, '3001234567')
            && json_decode($body, true)['data']['order']['totals']['total'] === '282000.00'
            && json_decode($body, true)['workspace_id'] === $this->ws->publicId();
    });
    $delivery = WebhookDelivery::withoutWorkspaceScope()->sole();
    expect($delivery->state())->toBe('ok')->and($delivery->status_code)->toBe(200);
    // El mismo evento no se entrega dos veces.
    event(($this->orderPaid)());
    Http::assertSentCount(1);
});

it('con include_pii manda el bloque de contacto y los datos completos', function () {
    Http::fake(['hooks.ejemplo.test/*' => Http::response('ok', 200)]);
    ($this->subscribe)(['order.paid'], true);
    $contact = ($this->in)(fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'email' => 'ana@ejemplo.com', 'first_name' => 'Ana']));
    event(($this->orderPaid)($contact->id));
    Http::assertSent(fn (HttpRequest $r) => json_decode($r->body(), true)['contact']['email'] === 'ana@ejemplo.com'
        && json_decode($r->body(), true)['data']['order']['billing']['email'] === 'ana@ejemplo.com');
});

it('reintenta con espera creciente y vuelve a activa al responder bien', function () {
    $this->freezeSecond(); // next_attempt_at se guarda en segundos: sin fracción, la espera mide 60 exactos
    Http::fakeSequence('hooks.ejemplo.test/*')->push('caído', 500)->push('ok', 200);
    [$id] = ($this->subscribe)();
    event(($this->orderPaid)());

    $sub = ($this->in)(fn () => WebhookSubscription::findByPublicId($id));
    expect($sub->status)->toBe('failing')->and($sub->consecutive_failures)->toBe(1);
    $retry = WebhookDelivery::withoutWorkspaceScope()->where('attempt', 2)->sole();
    expect($retry->kind)->toBe('retry')->and($retry->state())->toBe('pending')
        ->and((int) round(now()->diffInSeconds($retry->next_attempt_at)))->toBe(60);

    $this->artisan('igr:webhooks:retry')->assertSuccessful();
    expect($retry->refresh()->state())->toBe('pending'); // aún no toca
    $this->travel(61)->seconds();
    $this->artisan('igr:webhooks:retry')->assertSuccessful();
    expect($retry->refresh()->state())->toBe('ok');
    $sub->refresh();
    expect($sub->status)->toBe('active')->and($sub->consecutive_failures)->toBe(0)->and($sub->last_success_at)->not->toBeNull();
});

it('pausa la suscripción tras N fallos seguidos, avisa a los dueños y deja de enviar', function () {
    config(['igr.webhooks.pause_after_failures' => 2]);
    $sent = [];
    app()->instance(MessageDispatcher::class, new class($sent) implements MessageDispatcher
    {
        public function __construct(public array &$sent) {}

        public function dispatch(OutboundMessage $message): DispatchResult
        {
            $this->sent[] = $message;

            return DispatchResult::queued('msg_1');
        }
    });
    Http::fake(['hooks.ejemplo.test/*' => Http::response('caído', 503)]);
    [$id] = ($this->subscribe)();
    event(($this->orderPaid)(null, '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b71'));
    event(($this->orderPaid)(null, '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b72'));

    $sub = ($this->in)(fn () => WebhookSubscription::findByPublicId($id));
    expect($sub->status)->toBe('paused')->and($sent)->toHaveCount(1)
        ->and($sent[0]->to)->toBe($this->owner->email)->and($sent[0]->context['aviso']['url'])->toEndWith('/integraciones');

    event(($this->orderPaid)(null, '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b73'));
    Http::assertSentCount(2);

    // Al reactivarla vuelve a recibir y su contador se reinicia.
    $this->actingAs($this->owner)->patchJson(($this->api)('webhooks/'.$id), ['status' => 'active'])->assertOk()->assertJsonPath('data.consecutive_failures', 0);
});

it('reenvía una entrega con el mismo cuerpo y envía una prueba al instante', function () {
    Http::fakeSequence('hooks.ejemplo.test/*')->push('no', 500)->push('ok', 200)->push('ok', 200);
    [$id] = ($this->subscribe)();
    event(($this->orderPaid)());
    $first = WebhookDelivery::withoutWorkspaceScope()->where('attempt', 1)->sole();

    $replay = $this->actingAs($this->owner)->postJson(($this->api)('webhook-deliveries/whd_'.$first->id.'/replay'))->assertOk();
    expect($replay->json('data.kind'))->toBe('replay')->and($replay->json('data.state'))->toBe('ok')->and($replay->json('data.attempt'))->toBe(3);
    $bodies = collect(Http::recorded())->map(fn ($pair) => json_decode($pair[0]->body(), true)['id'])->all();
    expect($bodies)->toBe([$first->event_id, $first->event_id]);

    $this->actingAs($this->owner)->postJson(($this->api)('webhooks/'.$id.'/ping'))->assertOk()->assertJsonPath('data.state', 'ok')->assertJsonPath('data.event', 'webhook.ping');
    $log = $this->actingAs($this->owner)->getJson(($this->api)('webhooks/'.$id.'/deliveries'))->assertOk()->json('data');
    expect(collect($log)->pluck('kind')->all())->toBe(['ping', 'replay', 'retry', 'event']);
    expect($this->actingAs($this->owner)->getJson(($this->api)('webhooks/'.$id.'/deliveries?status=failed'))->json('data'))->toHaveCount(1);
});

it('avisa contact.created una sola vez por contacto nuevo', function () {
    Http::fake(['hooks.ejemplo.test/*' => Http::response('ok', 200)]);
    ($this->subscribe)(['contact.created']);
    $contact = ($this->in)(fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Ana']));
    event(($this->orderPaid)($contact->id));
    event(($this->orderPaid)($contact->id, '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b79'));
    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $r) => json_decode($r->body(), true)['event'] === 'contact.created'
        && json_decode($r->body(), true)['data']['contact_id'] === $contact->publicId());
});

it('las URL deben ser HTTPS y públicas', function () {
    config(['igr.webhooks.allow_private_urls' => false]);
    foreach (['http://hooks.ejemplo.com/x', 'https://localhost/x', 'https://10.0.0.5/x', 'https://127.0.0.1/x'] as $url) {
        $this->actingAs($this->owner)->postJson(($this->api)('webhooks'), ['url' => $url, 'events' => ['order.paid']])->assertUnprocessable()->assertJsonValidationErrors('url');
    }
});

it('Zapier, Make y n8n se suscriben por la API y datos personales exigen contacts:pii', function () {
    [$token] = apiToken($this->ws, ['webhooks:manage']);
    $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.zapier.test/x', 'events' => ['lead.*'], 'include_pii' => true], apiHeaders($token))
        ->assertForbidden()->assertJsonPath('error.code', 'missing_scope');
    $res = $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.zapier.test/x', 'events' => ['lead.*']], apiHeaders($token))->assertCreated();
    expect($res->json('data.secret'))->toStartWith('whsec_')->and($res->json('data.name'))->toBe('Zapier');
    expect($this->getJson('/api/v1/webhooks', apiHeaders($token))->json('data'))->toHaveCount(1);
    $this->deleteJson('/api/v1/webhooks/'.$res->json('data.id'), [], apiHeaders($token))->assertNoContent();
    expect(($this->in)(fn () => WebhookSubscription::query()->count()))->toBe(0);
});
