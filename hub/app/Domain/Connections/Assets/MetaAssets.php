<?php

declare(strict_types=1);

namespace App\Domain\Connections\Assets;

use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\MetaProvider;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Connections\Support\ConnectionTokens;

/**
 * Lo que la persona autorizó en Meta (Login for Business): cuentas publicitarias, conjuntos de datos (píxeles)
 * para la Conversions API y las audiencias, y páginas con su cuenta profesional de Instagram para la bandeja.
 * Nunca devuelve tokens: el de la página se pide al conectarla (pageToken).
 * Marketing API: /me/adaccounts y /act_{id}/adspixels; páginas: /me/accounts. [verificar con la app real]
 */
final class MetaAssets
{
    private const MAX_AD_ACCOUNTS = 25;

    public function __construct(private readonly ConnectionTokens $tokens, private readonly MetaProvider $meta = new MetaProvider) {}

    /** @return array{ad_accounts: list<array<string, mixed>>, datasets: list<array<string, mixed>>, pages: list<array<string, mixed>>} */
    public function list(OAuthConnection $connection): array
    {
        $token = $this->tokens->accessToken($connection);
        $accounts = [];
        foreach ($this->pages('me/adaccounts', ['fields' => 'id,account_id,name,currency', 'limit' => 100], $token) as $row) {
            $accounts[] = ['id' => (string) ($row['account_id'] ?? preg_replace('/^act_/', '', (string) ($row['id'] ?? ''))), 'name' => (string) ($row['name'] ?? ''), 'currency' => isset($row['currency']) ? (string) $row['currency'] : null];
        }
        $datasets = [];
        foreach (array_slice($accounts, 0, self::MAX_AD_ACCOUNTS) as $account) {
            foreach ($this->pages('act_'.$account['id'].'/adspixels', ['fields' => 'id,name', 'limit' => 100], $token, false) as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id !== '' && ! isset($datasets[$id])) {
                    $datasets[$id] = ['id' => $id, 'name' => (string) ($row['name'] ?? $id), 'ad_account_id' => $account['id']];
                }
            }
        }
        $pages = [];
        foreach ($this->pages('me/accounts', ['fields' => 'id,name,instagram_business_account{id,username}', 'limit' => 100], $token) as $row) {
            $ig = (array) ($row['instagram_business_account'] ?? []);
            $pages[] = [
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'instagram' => isset($ig['id']) ? ['id' => (string) $ig['id'], 'username' => isset($ig['username']) ? (string) $ig['username'] : null] : null,
            ];
        }

        return ['ad_accounts' => $accounts, 'datasets' => array_values($datasets), 'pages' => $pages];
    }

    /**
     * Token de la página (para Messenger e Instagram) con su cuenta de Instagram. Con un token de usuario del
     * sistema, el de la página tampoco vence.
     *
     * @return array{id: string, name: string, access_token: string, instagram_id: string|null}
     */
    public function pageToken(OAuthConnection $connection, string $pageId): array
    {
        $response = $this->meta->get($pageId, ['fields' => 'id,name,access_token,instagram_business_account{id}'], $this->tokens->accessToken($connection));
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new ConnectionError(__('Meta no entregó el acceso a esa página: :msg', ['msg' => MetaProvider::errorMessage($response)]), 'asset');
        }

        return [
            'id' => (string) $response->json('id', $pageId),
            'name' => (string) $response->json('name', ''),
            'access_token' => $token,
            'instagram_id' => is_scalar($response->json('instagram_business_account.id')) ? (string) $response->json('instagram_business_account.id') : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function pages(string $path, array $query, string $token, bool $strict = true): array
    {
        $out = [];
        $response = $this->meta->get($path, $query, $token);
        for ($page = 0; $page < 5; $page++) {
            if (! $response->successful()) {
                if ($strict) {
                    throw new ConnectionError(__('Meta no entregó la lista: :msg', ['msg' => MetaProvider::errorMessage($response)]), $response->status() === 401 ? 'expired' : 'provider', $response->status() === 401);
                }

                return $out;
            }
            foreach ((array) $response->json('data', []) as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            $after = $response->json('paging.cursors.after');
            if (! is_string($after) || $response->json('paging.next') === null) {
                break;
            }
            $response = $this->meta->get($path, $query + ['after' => $after], $token);
        }

        return $out;
    }
}
