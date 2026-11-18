<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Cliente del servicio de predicciones (hub/ml). */
final class MlClient
{
    public function configured(): bool
    {
        return (string) config('igr-predictions.ml.token') !== '' && (string) config('igr-predictions.ml.url') !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RuntimeException si el servicio no responde o rechaza el pedido
     */
    public function fitLifetime(array $payload): array
    {
        if (! $this->configured()) {
            throw new RuntimeException(__('El servicio de predicciones no está configurado.'));
        }
        try {
            $response = Http::timeout((int) config('igr-predictions.ml.timeout', 300))
                ->withHeaders(['X-IGR-ML-Token' => (string) config('igr-predictions.ml.token')])
                ->acceptJson()
                ->post(rtrim((string) config('igr-predictions.ml.url'), '/').'/v1/clv/fit', $payload)
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new RuntimeException(__('El servicio de predicciones no respondió.'), 0, $e);
        }
        $data = $response->json();
        if (! is_array($data) || ! is_array($data['predictions'] ?? null)) {
            throw new RuntimeException(__('El servicio de predicciones devolvió una respuesta inválida.'));
        }

        return $data;
    }
}
