<?php

namespace Tests\Support;

use App\Domain\Measurement\Enums\DestinationMode;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use App\Support\Tenancy\CurrentWorkspace;

/** Datos de prueba de Medición (M06). */
trait MeasurementFixtures
{
    /** @param array<string, mixed> $attributes */
    protected function destination(Workspace $ws, Platform $platform, array $attributes = []): AdDestination
    {
        $config = match ($platform) {
            Platform::Meta => ['dataset_id' => '123456789012345', 'access_token' => 'EAAB-secreto-meta', 'ad_account_id' => '998877'],
            Platform::Google => ['measurement_id' => 'G-ABC123', 'api_secret' => 'ga4-secreto', 'ads_customer_id' => '123-456-7890', 'conversion_action_purchase' => '555', 'oauth_client_id' => 'cid', 'oauth_client_secret' => 'csecret', 'oauth_refresh_token' => 'rtoken', 'ads_developer_token' => 'devtoken'],
            Platform::TikTok => ['pixel_code' => 'C9ABCDEF12345', 'access_token' => 'tt-secreto', 'advertiser_id' => '7001'],
        };

        return app(CurrentWorkspace::class)->run($ws, fn () => AdDestination::query()->create($attributes + [
            'workspace_id' => $ws->id,
            'platform' => $platform,
            'name' => $platform->label(),
            'config' => $config,
            'mode' => DestinationMode::Full,
            'pixel_id' => null,
            'status' => 'active',
        ]));
    }

    /**
     * Un order.paid en vivo como lo publica la ingesta.
     *
     * @param  array<string, mixed>  $context
     */
    protected function paidEvent(Workspace $ws, string $siteId, array $context = [], ?string $contactId = 'CONTACT1'): EventIngested
    {
        $envelope = [
            'schema_version' => 2,
            'event_id' => '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b7a',
            'event' => 'order.paid',
            'occurred_at' => '2026-11-17T18:05:00-05:00',
            'source' => ['type' => 'server', 'platform' => 'woocommerce', 'site_id' => 'st_X', 'sdk' => 'connector/0.2.0'],
            'identity' => ['anonymous_id' => 'anon-1', 'session_id' => 's_1', 'customer_ref' => 'wc:77', 'email' => ' Ana@Ejemplo.com ', 'phone' => null],
            'context' => array_replace_recursive([
                'page' => ['url' => 'https://tienda.co/finalizar-compra/order-received/1234/', 'referrer' => 'https://l.facebook.com/'],
                'click_ids' => ['fbclid' => 'IwAR0abc', 'gclid' => 'Cj0KCQ', 'ttclid' => 'E.C.P.tt'],
                'ad_cookies' => ['fbp' => 'fb.1.1731880000000.123456789', 'ga_client_id' => 'GA1.1.111222333.1731880000', 'ttp' => 'ttp-cookie'],
                'consent' => ['analytics' => true, 'marketing' => true, 'source' => 'wp-consent-api'],
                'currency' => 'COP',
            ], $context),
            'properties' => ['order' => $this->orderSnapshot()],
        ];

        return new EventIngested($ws->id, $siteId, 'order.paid', $envelope['event_id'], $contactId, $envelope, '190.24.1.10', 'Mozilla/5.0 (iPhone)');
    }

    /** Compara con un snapshot JSON; con UPDATE_SNAPSHOTS=1 lo reescribe. */
    protected function assertGolden(string $name, mixed $actual): void
    {
        $path = dirname(__DIR__).'/fixtures/measurement/'.$name.'.json';
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        if (getenv('UPDATE_SNAPSHOTS') === '1' || ! file_exists($path)) {
            file_put_contents($path, $json);
        }
        expect($json)->toBe(file_get_contents($path));
    }
}
