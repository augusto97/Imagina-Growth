<?php

require_once __DIR__.'/../Leads/LeadsHelpers.php';

use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Measurement\Conversions\Platforms\DataManagerTarget;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Jobs\DeliverConversion;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Ingest\Events\EventIngested;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MeasurementFixtures;

/*
 * Clics que valen (rediseño v2 §11.3): los clics del sitio a WhatsApp, a llamar o a escribir un correo (`cta.clicked`,
 * los clasifica el tracker) suman la señal de intención del puntaje del lead y, si el espacio de trabajo lo enciende
 * en su destino (apagado por defecto), salen como conversión «Contact» con el mismo event_id del clic.
 */
uses(MeasurementFixtures::class);

beforeEach(function () {
    leadsSetUp($this);
    $this->ai = FakeAiGateway::install();
    $this->ai->enabled = false;
    FakeMessageDispatcher::install();
});

/** Un clic del tracker como lo publica la ingesta (con consentimiento de marketing). */
function captureClick(object $test, string $kind, ?string $eventId = null, ?string $contactId = null): string
{
    $eventId ??= (string) Str::uuid7();
    $envelope = [
        'schema_version' => 4, 'event_id' => $eventId, 'event' => 'cta.clicked', 'occurred_at' => now()->toIso8601String(),
        'source' => ['type' => 'browser', 'platform' => 'wordpress', 'site_id' => $test->site->publicId(), 'sdk' => 'tracker/0.5.0'],
        'identity' => ['anonymous_id' => 'aid-1', 'session_id' => 's_1'],
        'context' => ['page' => ['path' => '/contacto/', 'url' => 'https://asesorias.co/contacto/'], 'click_ids' => ['gclid' => 'Cj0KCQ-clic', 'fbclid' => 'IwAR-clic'],
            'ad_cookies' => ['fbp' => 'fb.1.1.2'], 'consent' => ['analytics' => true, 'marketing' => true, 'source' => 'complianz']],
        'properties' => ['kind' => $kind, 'target' => $kind === 'email' ? 'mailto:hola@asesorias.co' : 'tel:+573001234567', 'label' => 'Escríbenos'],
    ];
    event(new EventIngested($test->ws->id, $test->site->id, 'cta.clicked', $eventId, $contactId, $envelope, '190.24.1.10', 'Mozilla/5.0 (iPhone)'));

    return $eventId;
}

it('el clic a correo también suma la señal de intención, en vivo y en la navegación previa', function () {
    $contact = leadsContact($this, ['email' => 'ana@gmail.com']);
    leadsChVisit($this, $contact, 'cta.clicked', '/contacto/', now()->subDays(2)->toIso8601String(), ['kind' => 'email', 'target' => 'mailto:hola@asesorias.co']);
    leadsForm($this, $contact);
    expect(collect(leadsOne($this)->score_reasons)->firstWhere('signal', 'whatsapp_click')['detail'])->toBe(['clicks' => 1]);

    $other = leadsContact($this, ['email' => 'pedro@gmail.com', 'phone' => '+573001112233']);
    leadsForm($this, $other);
    $before = leadsOne($this)->score;
    leadsBrowse($this, $other, 'cta.clicked', '/', ['kind' => 'email', 'target' => 'mailto:hola@asesorias.co']);
    $lead = leadsOne($this);
    expect($lead->score)->toBe($before + 15)->and(collect($lead->score_reasons)->firstWhere('signal', 'whatsapp_click')['detail'])->toBe(['kind' => 'email']);
});

it('la conversión «Contact» está apagada por defecto y, encendida, sale solo para WhatsApp, llamada y correo con el event_id del clic', function () {
    Queue::fake();
    $meta = $this->destination($this->ws, Platform::Meta);
    captureClick($this, 'whatsapp');
    Queue::assertNotPushed(DeliverConversion::class);

    $meta->forceFill(['events_enabled' => ['order.paid', 'cta.clicked']])->save();
    $this->destination($this->ws, Platform::TikTok, ['events_enabled' => ['cta.clicked']]);
    $google = $this->destination($this->ws, Platform::Google, ['events_enabled' => ['cta.clicked']]);
    $ids = [captureClick($this, 'whatsapp'), captureClick($this, 'phone'), captureClick($this, 'email')];
    captureClick($this, 'download');
    captureClick($this, 'custom');

    $pushed = [];
    Queue::assertPushed(DeliverConversion::class, function (DeliverConversion $job) use (&$pushed) {
        $pushed[] = $job;

        return true;
    });
    $byTarget = collect($pushed)->groupBy(fn (DeliverConversion $j) => $j->target);
    expect($byTarget['meta_capi'])->toHaveCount(3)->and($byTarget['tiktok_events'])->toHaveCount(3)->and($byTarget['ga4'])->toHaveCount(3)
        ->and($byTarget->has('google_data_manager'))->toBeFalse();
    $metaJob = $byTarget['meta_capi']->first();
    expect($metaJob->payload['data'][0]['event_name'] ?? $metaJob->payload['event_name'] ?? null)->toBe('Contact')
        ->and($byTarget['meta_capi']->pluck('meta.source_event_id')->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all())
        ->and($metaJob->meta['conversion'])->toBe('Contact')
        ->and($byTarget['tiktok_events']->first()->meta['conversion'])->toBe('Contact')
        ->and($byTarget['ga4']->first()->meta['conversion'])->toBe('contact');
    // El mismo clic reprocesado no se encola otra vez.
    $count = count($pushed);
    captureClick($this, 'whatsapp', $ids[0]);
    $again = 0;
    Queue::assertPushed(DeliverConversion::class, function () use (&$again) {
        $again++;

        return true;
    });
    expect($again)->toBe($count);

    // Google Ads (Data Manager) solo con la acción de conversión de contacto configurada.
    expect(app(DataManagerTarget::class)->supports($google, 'cta.clicked'))->toBeFalse();
    $google->forceFill(['config' => $google->config + ['conversion_action_contact' => '777']])->save();
    expect(app(DataManagerTarget::class)->supports($google->fresh(), 'cta.clicked'))->toBeTrue();
});
