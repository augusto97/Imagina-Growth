<?php

namespace Tests;

use App\Analytics\Ch;
use App\Analytics\ClickHouseMigrator;
use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Support\SiteKeys;
use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\IngestStream;
use App\Support\Hmac\Signature;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    private static bool $clickhouseReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        BlindIndex::flush();
    }

    /** Persona con 2FA confirmada (obligatoria para owner y admin). */
    protected function userWith2fa(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['a-b'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    protected function workspaceFor(User $owner, string $name = 'Pinturas La 14', string $currency = 'COP'): Workspace
    {
        return app(CreateWorkspace::class)->handle($owner, new WorkspaceData(name: $name, currency: $currency));
    }

    protected function addMember(Workspace $ws, WorkspaceRole $role, bool $twoFactor = false): User
    {
        $user = $twoFactor ? $this->userWith2fa() : User::factory()->create();
        $ws->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    protected function connectedSite(Workspace $ws, string $host = 'tienda.co'): array
    {
        $secret = SiteKeys::secret();
        $site = app(CurrentWorkspace::class)->run($ws, fn () => Site::query()->create([
            'workspace_id' => $ws->id,
            'platform' => 'woocommerce',
            'name' => $host,
            'url' => 'https://'.$host,
            'host' => $host,
            'rest_url' => 'https://'.$host.'/wp-json/imagina-growth/v1/',
            'domains' => [$host, 'www.'.$host],
            'public_key' => SiteKeys::publicKey(),
            'secret' => $secret,
            'status' => Site::STATUS_CONNECTED,
            'timezone' => 'America/Bogota',
            'currency' => $ws->currency,
            'connected_at' => now(),
        ]));

        return [$site, $secret];
    }

    /**
     * Sobre de evento válido según hub/resources/schemas/events/_envelope.json.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $overrides  se fusionan sobre el sobre
     * @return array<string, mixed>
     */
    protected function envelope(Site $site, string $event, array $properties, array $overrides = []): array
    {
        $base = [
            'schema_version' => 1,
            'event_id' => (string) Str::uuid7(),
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'source' => ['type' => 'server', 'platform' => 'woocommerce', 'site_id' => $site->publicId(), 'sdk' => 'connector/0.1.0'],
            'identity' => [],
            'context' => ['currency' => 'COP', 'locale' => 'es-CO'],
            'properties' => $properties,
        ];

        $envelope = array_replace_recursive($base, $overrides);
        foreach (['identity', 'context', 'properties'] as $key) {
            if ($envelope[$key] === []) {
                $envelope[$key] = new \stdClass;  // JSON {} y no []
            }
        }

        return $envelope;
    }

    /** @return array<string, mixed> pedido de ejemplo del contrato (eventos.md §5) */
    protected function orderSnapshot(array $overrides = []): array
    {
        $order = [
            'order_id' => '1234', 'number' => '1234', 'status' => 'processing',
            'created_at' => '2026-11-17T18:01:00-05:00', 'paid_at' => '2026-11-17T18:05:00-05:00', 'modified_at' => '2026-11-17T18:05:00-05:00',
            'created_via' => 'checkout', 'currency' => 'COP',
            'totals' => ['subtotal' => '300000.00', 'discount' => '30000.00', 'shipping' => '12000.00', 'tax' => '0.00', 'total' => '282000.00', 'refunded' => '0.00'],
            'payment_method' => ['id' => 'wompi', 'title' => 'Wompi - PSE', 'is_offline' => false],
            'shipping_method' => ['id' => 'flat_rate', 'title' => 'Envío nacional'],
            'coupons' => ['BIENVENIDA10'],
            'items' => [[
                'item_id' => '55', 'product_id' => '88', 'variation_id' => '91', 'sku' => 'PNT-AZ-1G', 'name' => 'Pintura Azul 1 galón',
                'category_ids' => ['12'], 'brand' => 'Pintuco', 'qty' => 2, 'unit_price' => '150000.00', 'line_total' => '300000.00', 'cost' => '90000.00',
            ]],
            'billing' => ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@ejemplo.com', 'phone' => '300 123 4567', 'city' => 'Manizales', 'state' => 'CO-CAL', 'country' => 'CO', 'postcode' => ''],
            'shipping' => ['city' => 'Manizales', 'state' => 'CO-CAL', 'country' => 'CO'],
            'customer_ref' => 'wc:77',
            'consents' => ['email_marketing' => true, 'whatsapp_marketing' => false, 'text_version' => 'v3'],
            'meta' => new \stdClass,
        ];

        return array_replace_recursive($order, $overrides);
    }

    /** POST /v1/s firmado y comprimido como lo hace el connector. */
    protected function postSigned(Site $site, string $secret, array $events, ?int $timestamp = null, bool $gzip = true): TestResponse
    {
        $json = json_encode(['sent_at' => now()->toIso8601String(), 'events' => $events]);
        $body = $gzip ? gzencode($json) : $json;
        $headers = [
            'X-IGR-Site' => $site->publicId(),
            'X-IGR-Signature' => Signature::sign($secret, $body, $timestamp),
            'CONTENT_TYPE' => 'application/json',
        ];
        if ($gzip) {
            $headers['Content-Encoding'] = 'gzip';
        }

        return $this->call('POST', '/v1/s', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    protected function signedJson(string $method, string $uri, Site $site, string $secret, ?array $payload = null): TestResponse
    {
        $body = $payload === null ? '' : json_encode($payload);
        $headers = [
            'X-IGR-Site' => $site->publicId(),
            'X-IGR-Signature' => Signature::sign($secret, $body),
            'CONTENT_TYPE' => 'application/json',
            'Accept' => 'application/json',
        ];

        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /** Prepara ClickHouse (igr_test) y vacía Redis de ingesta. */
    protected function useIngestInfrastructure(): void
    {
        if (! self::$clickhouseReady) {
            app(ClickHouseMigrator::class)->fresh();
            self::$clickhouseReady = true;
        } else {
            app(ClickHouseMigrator::class)->truncateAll();
        }
        app(IngestStream::class)->connection()->command('flushdb');
    }

    protected function runWorker(): void
    {
        $this->artisan('igr:ingest:work', ['--once' => true])->assertSuccessful();
    }

    /** @return list<array<string, mixed>> */
    protected function chRows(Workspace $ws, string $sql, array $params = []): array
    {
        return Ch::forWorkspace($ws)->select($sql, $params);
    }
}
