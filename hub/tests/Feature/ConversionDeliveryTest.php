<?php

use App\Analytics\Ch;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Jobs\DeliverConversion;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MeasurementFixtures;

uses(MeasurementFixtures::class);

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    $this->meta = $this->destination($this->ws, Platform::Meta, ['test_event_code' => 'TEST123']);
});

function measurementDeliveryJob($test, int $attempt = 1, ?int $firstAttemptAt = null): DeliverConversion
{
    return new DeliverConversion($test->ws->id, $test->meta->id, 'meta_capi', ['event_name' => 'Purchase', 'event_id' => '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b7a', 'event_time' => 1763420700, 'action_source' => 'website', 'user_data' => ['em' => ['h']], 'custom_data' => []], [
        'source_event_id' => '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b7a', 'source_event' => 'order.paid', 'conversion' => 'Purchase',
        'contact_id' => 'CONTACT1', 'match_keys' => ['email'], 'first_attempt_at' => $firstAttemptAt ?? time(), 'attempt' => $attempt,
    ]);
}

/** Corre el job como lo haría el worker (con su espacio de trabajo), sin pasar por la cola. */
function measurementHandle($test, DeliverConversion $job): void
{
    app(CurrentWorkspace::class)->run($test->ws, fn () => app()->call([$job, 'handle']));
}

function measurementFresh($test, $model)
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => $model->fresh());
}

function measurementDeliveries($test): array
{
    return Ch::forWorkspace($test->ws)->select('SELECT status, final, attempts, error, error_code, test, target FROM conversion_deliveries WHERE workspace_id = {workspace_id:UInt32} ORDER BY delivered_at');
}

it('entrega a Meta con token en cabecera y código de prueba, y lo registra', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'x'])]);
    measurementHandle($this, measurementDeliveryJob($this));

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/123456789012345/events')
        && $r->hasHeader('Authorization', 'Bearer EAAB-secreto-meta')
        && $r['test_event_code'] === 'TEST123'
        && ! str_contains($r->url(), 'access_token'));
    expect(measurementDeliveries($this))->toMatchArray([['status' => 'delivered', 'final' => 1, 'attempts' => 1, 'error' => '', 'error_code' => '', 'test' => 1, 'target' => 'meta_capi']])
        ->and(measurementFresh($this, $this->meta)->last_delivered_at)->not->toBeNull()
        ->and($this->chRows($this->ws, "SELECT count() AS c FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'conversion.delivered'")[0]['c'])->toBe(1);
});

it('reintenta un error temporal a 1 m, 5 m, 30 m y 2 h', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 503)]);
    Queue::fake();
    foreach ([1 => 60, 2 => 300, 3 => 1800, 4 => 7200, 5 => 7200] as $attempt => $delay) {
        measurementHandle($this, measurementDeliveryJob($this, $attempt));
        Queue::assertPushedOn('conversions:meta', DeliverConversion::class, fn (DeliverConversion $j) => $j->meta['attempt'] === $attempt + 1
            && abs($j->delay->getTimestamp() - now()->addSeconds($delay)->getTimestamp()) <= 2);
    }
    expect(collect(measurementDeliveries($this))->pluck('status')->unique()->all())->toBe(['retrying']);
});

it('se rinde después de 24 h y explica el último error', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 2]], 500)]);
    Queue::fake();
    measurementHandle($this, measurementDeliveryJob($this, 9, time() - 23 * 3600));  // el próximo intento caería después de las 24 h
    Queue::assertNotPushed(DeliverConversion::class);
    $row = measurementDeliveries($this)[0];
    expect($row['status'])->toBe('failed')->and($row['final'])->toBe(1)->and($row['error'])->toContain('Se agotaron los reintentos');
});

it('no reintenta un token inválido y deja un mensaje humano en el destino', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 400)]);
    Queue::fake();
    measurementHandle($this, measurementDeliveryJob($this));
    Queue::assertNotPushed(DeliverConversion::class);
    expect(measurementDeliveries($this)[0])->toMatchArray(['status' => 'failed', 'error_code' => 'meta:190'])
        ->and(measurementFresh($this, $this->meta)->last_error)->toContain('token de acceso de Meta no es válido');
});

it('no envía si el destino se pausó mientras la conversión esperaba', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
    $this->meta->forceFill(['status' => 'paused'])->save();
    measurementHandle($this, measurementDeliveryJob($this));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    expect(measurementDeliveries($this)[0]['status'])->toBe('skipped');
});

it('entiende los errores de TikTok (200 con code distinto de 0) y de Google', function () {
    $tiktok = $this->destination($this->ws, Platform::TikTok);
    Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 40001, 'message' => 'Access token is invalid'])]);
    Queue::fake();
    measurementHandle($this, new DeliverConversion($this->ws->id, $tiktok->id, 'tiktok_events', ['event' => 'CompletePayment', 'event_id' => 'x'], [
        'source_event_id' => 'x', 'source_event' => 'order.paid', 'conversion' => 'CompletePayment', 'contact_id' => null, 'match_keys' => [], 'first_attempt_at' => time(), 'attempt' => 1,
    ]));
    expect(measurementFresh($this, $tiktok)->last_error)->toContain('token de acceso de TikTok');

    $google = $this->destination($this->ws, Platform::Google);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600]),
        'datamanager.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'denied']], 403),
    ]);
    measurementHandle($this, new DeliverConversion($this->ws->id, $google->id, 'google_data_manager', ['events' => [[]]], [
        'source_event_id' => 'y', 'source_event' => 'order.paid', 'conversion' => 'purchase', 'contact_id' => null, 'match_keys' => [], 'first_attempt_at' => time(), 'attempt' => 1,
    ]));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'datamanager.googleapis.com') && $r->hasHeader('Authorization', 'Bearer ya29.x'));
    expect(measurementFresh($this, $google)->last_error)->toContain('no tiene acceso');
});
