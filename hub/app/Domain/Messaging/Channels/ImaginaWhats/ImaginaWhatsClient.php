<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\ImaginaWhats;

use App\Domain\Messaging\Models\ChannelAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente mínimo de la API REST v1 de Imagina Whats (`{url}/api/v1`, token Bearer de la clave del cliente). La URL es
 * la de la plataforma (`igr-messaging.imagina_whats.url`), nunca la elige el cliente. Las escrituras van con
 * `Idempotency-Key` estable: un reintento repite la misma acción, no la duplica.
 */
final class ImaginaWhatsClient
{
    public function __construct(private readonly string $apiKey) {}

    public static function for(ChannelAccount $account): ?self
    {
        $key = $account->credential('api_key');

        return $key !== null ? new self($key) : null;
    }

    /** @return array<string, mixed> `data` de GET /me (perfil, espacio de trabajo, créditos y la clave con sus `abilities`) */
    public function me(): array
    {
        return $this->data('GET', '/me');
    }

    /** @return list<array<string, mixed>> los números de WhatsApp que la clave puede ver */
    public function waAccounts(): array
    {
        return array_values(array_filter($this->data('GET', '/whatsapp/accounts?limit=100'), 'is_array'));
    }

    /** @return array<string, mixed> */
    public function waAccount(int $id): array
    {
        return $this->data('GET', '/whatsapp/accounts/'.$id);
    }

    /**
     * POST /messages/whatsapp (202). `reference` vuelve en los webhooks.
     *
     * @return array<string, mixed>
     */
    public function sendText(int $waAccountId, string $to, string $body, string $reference, string $idempotencyKey): array
    {
        return $this->data('POST', '/messages/whatsapp', ['type' => 'text', 'to' => $to, 'body' => $body, 'wa_account_id' => $waAccountId, 'reference' => $reference], $idempotencyKey);
    }

    /**
     * Un archivo por URL pública (imagen, video, audio o PDF) con su pie; el audio va sin pie.
     *
     * @return array<string, mixed>
     */
    public function sendMedia(int $waAccountId, string $to, string $url, ?string $caption, string $reference, string $idempotencyKey): array
    {
        return $this->data('POST', '/messages/whatsapp', array_filter([
            'type' => 'media', 'to' => $to, 'media_url' => $url, 'body' => $caption, 'wa_account_id' => $waAccountId, 'reference' => $reference,
        ], fn ($v) => $v !== null && $v !== ''), $idempotencyKey);
    }

    /** @return list<array<string, mixed>> */
    public function webhooks(): array
    {
        return array_values(array_filter($this->data('GET', '/webhooks?limit=100'), 'is_array'));
    }

    /**
     * @param  list<string>  $events
     * @return array<string, mixed> con `id` y `secret` (se ve una sola vez)
     */
    public function createWebhook(string $name, string $url, array $events, string $idempotencyKey): array
    {
        return $this->data('POST', '/webhooks', ['name' => $name, 'url' => $url, 'events' => $events, 'is_active' => true], $idempotencyKey);
    }

    /**
     * @param  list<string>  $events
     * @return array<string, mixed>
     */
    public function updateWebhook(int $id, string $name, string $url, array $events): array
    {
        return $this->data('PATCH', '/webhooks/'.$id, ['name' => $name, 'url' => $url, 'events' => $events], 'igr-webhook-'.$id.'-'.sha1($url.implode(',', $events)));
    }

    /** @return array<string, mixed> con el `secret` nuevo (el anterior deja de valer al instante) */
    public function rotateWebhookSecret(int $id, string $idempotencyKey): array
    {
        return $this->data('POST', '/webhooks/'.$id.'/rotate-secret', [], $idempotencyKey);
    }

    public function activateWebhook(int $id): void
    {
        $this->call('POST', '/webhooks/'.$id.'/toggle', ['is_active' => true]);
    }

    public function testWebhook(int $id): void
    {
        $this->call('POST', '/webhooks/'.$id.'/test', []);
    }

    public function deleteWebhook(int $id): void
    {
        $this->call('DELETE', '/webhooks/'.$id);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<mixed>
     */
    private function data(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $json = $this->call($method, $path, $body, $idempotencyKey)->json();
        $data = is_array($json) ? ($json['data'] ?? null) : null;

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed>|null $body */
    private function call(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): Response
    {
        $url = rtrim((string) config('igr-messaging.imagina_whats.url'), '/').'/api/v1'.$path;
        $request = Http::withToken($this->apiKey)->acceptJson()->timeout((int) config('igr-messaging.imagina_whats.timeout', 20))
            ->withHeaders($idempotencyKey !== null ? ['Idempotency-Key' => mb_substr($idempotencyKey, 0, 255)] : []);
        try {
            $response = $body !== null ? $request->send($method, $url, ['json' => (object) $body]) : $request->send($method, $url);
        } catch (ConnectionException $e) {
            throw new ImaginaWhatsError('connection', $e->getMessage());
        }
        if ($response->successful()) {
            return $response;
        }
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        $retryAfter = $response->header('Retry-After') !== '' ? (int) $response->header('Retry-After') : (is_numeric($details['retry_after'] ?? null) ? (int) $details['retry_after'] : null);

        throw new ImaginaWhatsError(
            is_string($error['code'] ?? null) ? $error['code'] : 'http_'.$response->status(),
            is_string($error['message'] ?? null) ? $error['message'] : '',
            $response->status(),
            $details,
            $retryAfter,
        );
    }
}
