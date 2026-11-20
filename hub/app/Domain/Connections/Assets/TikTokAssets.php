<?php

declare(strict_types=1);

namespace App\Domain\Connections\Assets;

use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\TikTokProvider;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Connections\Support\ConnectionTokens;

/**
 * Anunciantes que autorizaron la app (`/oauth2/advertiser/get/`) y los píxeles de cada uno (`/pixel/list/`) para
 * la Events API y las audiencias. [verificar con la app real los campos de `pixel/list`]
 */
final class TikTokAssets
{
    public function __construct(private readonly ConnectionTokens $tokens, private readonly TikTokProvider $tiktok = new TikTokProvider) {}

    /** @return array{advertisers: list<array{id: string, name: string}>} */
    public function list(OAuthConnection $connection): array
    {
        $response = $this->tiktok->advertisers($this->tokens->accessToken($connection));
        if (! TikTokProvider::ok($response)) {
            throw new ConnectionError(__('TikTok no entregó los anunciantes: :msg', ['msg' => TikTokProvider::errorMessage($response)]), 'provider');
        }
        $out = [];
        foreach ((array) $response->json('data.list', []) as $row) {
            if (is_array($row) && isset($row['advertiser_id'])) {
                $out[] = ['id' => (string) $row['advertiser_id'], 'name' => (string) ($row['advertiser_name'] ?? $row['advertiser_id'])];
            }
        }

        return ['advertisers' => $out];
    }

    /** @return list<array{id: string, name: string}> */
    public function pixels(OAuthConnection $connection, string $advertiserId): array
    {
        $this->assertAdvertiser($connection, $advertiserId);
        $response = $this->tiktok->call('get', 'pixel/list/', ['advertiser_id' => $advertiserId, 'page_size' => 20], $this->tokens->accessToken($connection));
        if (! TikTokProvider::ok($response)) {
            throw new ConnectionError(__('TikTok no entregó los píxeles: :msg', ['msg' => TikTokProvider::errorMessage($response)]), 'provider');
        }
        $out = [];
        foreach ((array) $response->json('data.pixels', []) as $row) {
            if (is_array($row) && isset($row['pixel_code'])) {
                $out[] = ['id' => (string) $row['pixel_code'], 'name' => (string) ($row['pixel_name'] ?? $row['pixel_code'])];
            }
        }

        return $out;
    }

    /** El anunciante tiene que estar entre los que autorizaron esta conexión. */
    public function assertAdvertiser(OAuthConnection $connection, string $advertiserId): void
    {
        $ids = array_map('strval', $connection->asset('advertiser_ids'));
        if ($ids !== [] && ! in_array($advertiserId, $ids, true)) {
            throw new ConnectionError(__('Ese anunciante no está entre los que autorizaste en TikTok.'), 'asset');
        }
    }
}
