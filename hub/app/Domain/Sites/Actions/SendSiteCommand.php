<?php

declare(strict_types=1);

namespace App\Domain\Sites\Actions;

use App\Domain\Sites\Models\Site;
use App\Support\Hmac\Signature;
use App\Support\Net\SafeHttp;
use App\Support\Net\UnsafeUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Comando firmado del hub al sitio (api-connector.md §6).
 */
final class SendSiteCommand
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed> el campo `data` de la respuesta
     */
    public function handle(Site $site, string $command, array $args = [], ?string $signWith = null, int $timeout = 15): array
    {
        $url = $site->commandsUrl() ?? throw new RuntimeException(__('El sitio no informó su URL de comandos.'));

        $body = json_encode([
            'command' => $command,
            'args' => (object) $args,
            'request_id' => strtoupper((string) Str::ulid()),
            'nonce' => bin2hex(random_bytes(16)),
            'issued_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            // `rest_url` lo informa el sitio: solo direcciones públicas, resueltas al enviar y sin redirecciones (S5).
            $request = SafeHttp::for($url, httpsOnly: false);
        } catch (UnsafeUrl $e) {
            throw new RuntimeException(__('No se pudo contactar al sitio: :msg', ['msg' => $e->getMessage()]), 0, $e);
        }

        try {
            $response = $request->timeout($timeout)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-IGR-Signature' => Signature::sign($signWith ?? $site->secret, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException(__('No se pudo contactar al sitio: :msg', ['msg' => $e->getMessage()]), 0, $e);
        }

        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ($json['ok'] ?? false) !== true) {
            $error = is_array($json) ? ($json['error']['message'] ?? $json['error']['code'] ?? null) : null;
            throw new RuntimeException(__('El sitio rechazó el comando :cmd (:status): :error', [
                'cmd' => $command, 'status' => $response->status(), 'error' => $error ?? 'sin detalle',
            ]));
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }
}
