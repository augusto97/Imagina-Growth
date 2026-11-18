<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Sites\Actions\ExchangeConnectCode;
use App\Domain\Sites\Exceptions\ConnectException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/connect/exchange (api-connector.md §3.3). */
final class ExchangeController
{
    public function __invoke(Request $request, ExchangeConnectCode $exchange): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'nonce' => ['nullable', 'string', 'max:64'],
            'site_url' => ['required', 'url', 'max:2048'],
            'rest_url' => ['nullable', 'url', 'max:2048'],
            'platform' => ['nullable', 'in:woocommerce,wordpress'],
            'name' => ['nullable', 'string', 'max:255'],
            'plugin_version' => ['nullable', 'string', 'max:20'],
            'wp_version' => ['nullable', 'string', 'max:20'],
            'wc_version' => ['nullable', 'string', 'max:20'],
            'php_version' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'timezone'],
            'currency' => ['nullable', 'string', 'size:3'],
            'locale' => ['nullable', 'string', 'max:10'],
            'capabilities' => ['nullable', 'array'],
        ]);

        try {
            return response()->json($exchange->handle($data));
        } catch (ConnectException $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], 422);
        }
    }
}
