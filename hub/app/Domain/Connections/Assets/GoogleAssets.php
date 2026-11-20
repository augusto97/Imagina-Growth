<?php

declare(strict_types=1);

namespace App\Domain\Connections\Assets;

use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Connections\Support\ConnectionTokens;
use App\Domain\Measurement\Support\GoogleAdsHeaders;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Lo que se elige después de autorizar Google:
 * - Google Ads API (v26): cuentas con `customers:listAccessibleCustomers` y, por cada una, su nombre y si es
 *   administradora (MCC); de una MCC, sus clientes directos (`customer_client`). Acciones de conversión con GAQL.
 *   https://developers.google.com/google-ads/api/docs/account-management/listing-accounts
 * - Google Analytics Admin API (v1beta): propiedades (`accountSummaries`), flujos web (`dataStreams`) y el
 *   secreto del Measurement Protocol (`measurementProtocolSecrets`, alcance analytics.edit).
 *   https://developers.google.com/analytics/devguides/config/admin/v1/rest/v1beta/properties.dataStreams.measurementProtocolSecrets/create
 */
final class GoogleAssets
{
    private const MAX_CUSTOMERS = 20;

    private const ADS = 'https://www.googleapis.com/auth/adwords';

    private const ANALYTICS = 'https://www.googleapis.com/auth/analytics.edit';

    public function __construct(private readonly ConnectionTokens $tokens) {}

    /** @return array{ads_customers: list<array<string, mixed>>, properties: list<array<string, mixed>>, scopes: array{ads: bool, data_manager: bool, analytics: bool}} */
    public function list(OAuthConnection $connection): array
    {
        $token = $this->tokens->accessToken($connection);
        $customers = $connection->hasScope(self::ADS) ? $this->customers($token) : [];
        $properties = [];
        if ($connection->hasScope(self::ANALYTICS)) {
            $response = $this->get($this->admin('accountSummaries'), ['pageSize' => 200], $token);
            foreach ((array) $response->json('accountSummaries', []) as $account) {
                foreach ((array) ($account['propertySummaries'] ?? []) as $p) {
                    if (is_string($p['property'] ?? null)) {
                        $properties[] = ['id' => $p['property'], 'name' => (string) ($p['displayName'] ?? $p['property']), 'account' => (string) ($account['displayName'] ?? '')];
                    }
                }
            }
        }

        return [
            'ads_customers' => $customers,
            'properties' => $properties,
            'scopes' => [
                'ads' => $connection->hasScope(self::ADS),
                'data_manager' => $connection->hasScope('https://www.googleapis.com/auth/datamanager'),
                'analytics' => $connection->hasScope(self::ANALYTICS),
            ],
        ];
    }

    /** @return list<array{id: string, name: string, category: string|null}> */
    public function conversionActions(OAuthConnection $connection, string $customerId, ?string $loginCustomerId): array
    {
        $rows = $this->search($this->tokens->accessToken($connection), $customerId, $loginCustomerId,
            "SELECT conversion_action.id, conversion_action.name, conversion_action.category FROM conversion_action WHERE conversion_action.status = 'ENABLED'");
        $out = [];
        foreach ($rows as $row) {
            $action = (array) ($row['conversionAction'] ?? []);
            if (isset($action['id'])) {
                $out[] = ['id' => (string) $action['id'], 'name' => (string) ($action['name'] ?? $action['id']), 'category' => isset($action['category']) ? (string) $action['category'] : null];
            }
        }

        return $out;
    }

    /** @return list<array{id: string, name: string, measurement_id: string}> */
    public function webStreams(OAuthConnection $connection, string $property): array
    {
        $response = $this->get($this->admin(self::property($property).'/dataStreams'), ['pageSize' => 50], $this->tokens->accessToken($connection));
        $out = [];
        foreach ((array) $response->json('dataStreams', []) as $stream) {
            $measurementId = $stream['webStreamData']['measurementId'] ?? null;
            if (($stream['type'] ?? '') === 'WEB_DATA_STREAM' && is_string($measurementId) && is_string($stream['name'] ?? null)) {
                $out[] = ['id' => $stream['name'], 'name' => (string) ($stream['displayName'] ?? $measurementId), 'measurement_id' => $measurementId];
            }
        }

        return $out;
    }

    /**
     * El ID de medición del flujo web y un secreto nuevo del Measurement Protocol para GA4.
     *
     * @return array{measurement_id: string, api_secret: string}
     */
    public function measurementProtocol(OAuthConnection $connection, string $stream): array
    {
        if (! preg_match('#^properties/\d+/dataStreams/\d+$#', $stream)) {
            throw new ConnectionError(__('Ese flujo de datos de Analytics no es válido.'), 'asset');
        }
        $token = $this->tokens->accessToken($connection);
        $measurementId = $this->get($this->admin($stream), [], $token)->json('webStreamData.measurementId');
        if (! is_string($measurementId) || $measurementId === '') {
            throw new ConnectionError(__('Ese flujo de datos no es de un sitio web.'), 'asset');
        }
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(15)->post($this->admin($stream.'/measurementProtocolSecrets'), ['displayName' => 'Imagina Growth']);
        } catch (ConnectionException) {
            throw new ConnectionError(__('No pudimos comunicarnos con Google. Vuelve a intentarlo en unos minutos.'), 'provider');
        }
        $secret = $response->json('secretValue');
        if (! $response->successful() || ! is_string($secret) || $secret === '') {
            // Google pide aceptar antes el aviso de recolección de datos de la propiedad [verificar].
            throw new ConnectionError(__('Analytics no creó el secreto del Measurement Protocol (:msg). Créalo en Analytics → Flujos de datos → Secretos de la API y pégalo a mano.', ['msg' => self::message($response)]), 'asset');
        }

        return ['measurement_id' => $measurementId, 'api_secret' => $secret];
    }

    /** @return list<array<string, mixed>> */
    private function customers(string $token): array
    {
        $response = $this->send('get', GoogleAdsHeaders::url('customers:listAccessibleCustomers'), [], $token, []);
        $out = [];
        foreach (array_slice((array) $response->json('resourceNames', []), 0, self::MAX_CUSTOMERS) as $name) {
            $id = (string) preg_replace('/\D/', '', (string) $name);
            if ($id === '') {
                continue;
            }
            $row = $this->search($token, $id, null, 'SELECT customer.id, customer.descriptive_name, customer.manager, customer.currency_code FROM customer LIMIT 1', false)[0] ?? [];
            $customer = (array) ($row['customer'] ?? []);
            $manager = (bool) ($customer['manager'] ?? false);
            $out[$id] = ['id' => $id, 'name' => (string) ($customer['descriptiveName'] ?? $id), 'manager' => $manager, 'currency' => $customer['currencyCode'] ?? null, 'login_customer_id' => null];
            if ($manager) {
                $clients = $this->search($token, $id, $id, 'SELECT customer_client.id, customer_client.descriptive_name, customer_client.manager, customer_client.currency_code FROM customer_client WHERE customer_client.level = 1', false);
                foreach (array_slice($clients, 0, 50) as $client) {
                    $c = (array) ($client['customerClient'] ?? []);
                    $cid = (string) ($c['id'] ?? '');
                    if ($cid !== '' && ! isset($out[$cid]) && ! ($c['manager'] ?? false)) {
                        $out[$cid] = ['id' => $cid, 'name' => (string) ($c['descriptiveName'] ?? $cid), 'manager' => false, 'currency' => $c['currencyCode'] ?? null, 'login_customer_id' => $id];
                    }
                }
            }
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>> */
    private function search(string $token, string $customerId, ?string $loginCustomerId, string $gaql, bool $strict = true): array
    {
        $customer = (string) preg_replace('/\D/', '', $customerId);
        $response = $this->send('post', GoogleAdsHeaders::url("customers/{$customer}/googleAds:search"), ['query' => $gaql], $token, GoogleAdsHeaders::build(null, $loginCustomerId), $strict);
        $rows = [];
        foreach ((array) $response->json('results', []) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $url, array $body, string $token, array $headers, bool $strict = true): Response
    {
        try {
            $request = Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(20);
            $response = $method === 'get' ? $request->get($url) : $request->asJson()->post($url, $body);
        } catch (ConnectionException) {
            throw new ConnectionError(__('No pudimos comunicarnos con Google. Vuelve a intentarlo en unos minutos.'), 'provider');
        }
        if ($strict && ! $response->successful()) {
            throw new ConnectionError(__('Google Ads no entregó la lista (:msg).', ['msg' => self::message($response)]), 'provider');
        }

        return $response;
    }

    /** @param array<string, mixed> $query */
    private function get(string $url, array $query, string $token): Response
    {
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(15)->get($url, $query);
        } catch (ConnectionException) {
            throw new ConnectionError(__('No pudimos comunicarnos con Google. Vuelve a intentarlo en unos minutos.'), 'provider');
        }
        if (! $response->successful()) {
            throw new ConnectionError(__('Google Analytics no entregó la lista (:msg).', ['msg' => self::message($response)]), 'provider');
        }

        return $response;
    }

    private function admin(string $path): string
    {
        return rtrim((string) config('igr-connections.google.analytics_admin_url'), '/').'/'.ltrim($path, '/');
    }

    private static function property(string $property): string
    {
        $id = (string) preg_replace('/\D/', '', $property);
        if ($id === '') {
            throw new ConnectionError(__('Esa propiedad de Analytics no es válida.'), 'asset');
        }

        return 'properties/'.$id;
    }

    private static function message(Response $response): string
    {
        $message = $response->json('error.message') ?? $response->json('0.error.message');

        return is_string($message) ? mb_substr($message, 0, 200) : (string) $response->status();
    }
}
