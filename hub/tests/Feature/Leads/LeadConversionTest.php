<?php

require_once __DIR__.'/LeadsHelpers.php';

use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Measurement\Conversions\TargetRegistry;
use App\Domain\Measurement\Enums\DestinationMode;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Leads\Actions\ChangeLeadStage;
use App\Domain\Services\Leads\Jobs\SendLeadConversion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    leadsSetUp($this);
    $this->ai = FakeAiGateway::install();
    $this->ai->enabled = false;
    FakeMessageDispatcher::install();
    Http::fake([
        'graph.facebook.com/*' => Http::response(['events_received' => 1]),
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600]),
        'datamanager.googleapis.com/*' => Http::response(['requestId' => 'r1']),
    ]);
    $make = fn (Platform $p, array $config, ?array $events = null) => leadsRun($this, fn () => AdDestination::query()->create([
        'workspace_id' => $this->ws->id, 'platform' => $p, 'name' => $p->label(), 'config' => $config, 'mode' => DestinationMode::Full,
        'status' => 'active', 'events_enabled' => $events,
    ]));
    $this->meta = $make(Platform::Meta, ['dataset_id' => '123456789012345', 'access_token' => 'EAAB-secreto']);
    $this->google = $make(Platform::Google, ['ads_customer_id' => '123-456-7890', 'conversion_action_lead_won' => '777', 'oauth_client_id' => 'cid', 'oauth_client_secret' => 'cs', 'oauth_refresh_token' => 'rt',
        'measurement_id' => 'G-ABC', 'api_secret' => 'ga4']);
});

/** Peticiones de la conversión offline (no las del formulario, que van por el ConversionRouter). */
function leadsWonRequests(string $host): Collection
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_contains($r->url(), $host))
        ->filter(fn ($r) => str_contains($r->body(), 'Purchase') || str_contains($r->body(), 'close_convert_lead') || str_contains($r->body(), '"777"'))->values();
}

function leadsWin($test, string $value = '2500000'): void
{
    leadsRun($test, fn () => app(ChangeLeadStage::class)->handle($test->ws, leadsOne($test), 'won', 'user', null, $value));
}

it('un lead ganado va a Meta y a Google Ads como conversión offline con su valor, hash y gclid, una sola vez', function () {
    leadsForm($this, leadsContact($this, ['phone' => '+573001234567']), [], ['click_ids' => ['gclid' => 'Cj0KCQ-test', 'fbclid' => 'IwAR-fb']]);
    leadsWin($this);

    expect(leadsWonRequests('graph.facebook.com'))->toHaveCount(1);
    $meta = leadsWonRequests('graph.facebook.com')->first();
    $event = $meta['data'][0];
    expect($event)->toMatchArray(['event_name' => 'Purchase', 'action_source' => 'system_generated'])
        ->and($event['custom_data'])->toMatchArray(['value' => 2500000.0, 'currency' => 'COP'])
        ->and($event['user_data']['em'][0])->toBe(hash('sha256', 'ana@ruizabogados.co'))
        ->and($event['user_data']['ph'][0])->toBe(hash('sha256', '573001234567'))
        ->and($event['user_data']['fbc'])->toStartWith('fb.1.')->toEndWith('.IwAR-fb');

    expect(leadsWonRequests('datamanager.googleapis.com'))->toHaveCount(1);
    $google = leadsWonRequests('datamanager.googleapis.com')->first();
    $g = $google['events'][0];
    expect($google['destinations'][0]['productDestinationId'])->toBe('777')
        ->and($g)->toMatchArray(['conversionValue' => 2500000.0, 'currency' => 'COP', 'eventSource' => 'OTHER'])
        ->and($g['adIdentifiers'])->toBe(['gclid' => 'Cj0KCQ-test'])
        ->and($g['transactionId'])->toBe(leadsOne($this)->publicId());
    // GA4 no recibe la conversión offline.
    expect(leadsWonRequests('google-analytics.com'))->toHaveCount(0);

    $lead = leadsOne($this);
    expect($lead->offline_conversion_at)->not->toBeNull()
        ->and(leadsActivities($this, $lead)->firstWhere('kind', 'conversion')->data)->toMatchArray(['status' => 'queued'])
        ->and(collect(leadsActivities($this, $lead)->firstWhere('kind', 'conversion')->data['platforms'])->sort()->values()->all())->toBe(['google', 'meta']);

    // Idempotente: otro intento no envía nada más.
    leadsRun($this, fn () => (new SendLeadConversion($this->ws->id, $lead->id))->handle(app(TargetRegistry::class)));
    $lead->forceFill(['offline_conversion_at' => null])->saveQuietly();
    leadsRun($this, fn () => (new SendLeadConversion($this->ws->id, $lead->id))->handle(app(TargetRegistry::class)));
    expect(leadsWonRequests('graph.facebook.com'))->toHaveCount(1)->and(leadsWonRequests('datamanager.googleapis.com'))->toHaveCount(1);
});

it('respeta el consentimiento de anuncios (modo estricto) y los eventos activos del destino', function () {
    $this->ws->forceFill(['settings' => array_merge((array) $this->ws->settings, ['consent_mode' => 'strict'])])->save();
    leadsForm($this, leadsContact($this));
    leadsWin($this);
    expect(leadsWonRequests('graph.facebook.com'))->toHaveCount(0)
        ->and(leadsActivities($this, leadsOne($this))->firstWhere('kind', 'conversion')->data)->toMatchArray(['status' => 'skipped', 'reason' => 'consent']);

    // Con el consentimiento `ads` concedido sí sale, salvo al destino que no tiene lead.won activo.
    leadsRun($this, fn () => $this->meta->forceFill(['events_enabled' => ['order.paid']])->save());
    $contact = leadsContact($this, ['email' => 'luis@constructora.co']);
    DB::table('contact_consents')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $contact->id, 'channel' => 'ads', 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()]);
    leadsForm($this, $contact);
    leadsWin($this);
    expect(leadsWonRequests('graph.facebook.com'))->toHaveCount(0)
        ->and(leadsWonRequests('datamanager.googleapis.com'))->toHaveCount(1);
});
