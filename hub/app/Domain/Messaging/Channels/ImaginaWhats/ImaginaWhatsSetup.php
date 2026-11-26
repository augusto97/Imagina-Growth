<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\ImaginaWhats;

use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Support\ContactLinkToken;
use App\Support\Phone\Phone;
use Illuminate\Support\Str;
use Throwable;

/**
 * Conectar Imagina Whats (M07, cumplimiento.md §5.2):
 *
 * - `inspect`: con la clave de API, qué cuenta es, qué permisos le faltan (`igr-messaging.imagina_whats.abilities`)
 *   y qué números de WhatsApp puede usar.
 * - `registerWebhook`: el webhook firmado que avisa al hub de los mensajes entrantes, los estados y las bajas. No
 *   duplica: si ya hay uno con la URL de la cuenta, lo reutiliza con un secreto nuevo (el anterior no se puede leer).
 *   Guarda `webhook_id` y `webhook_secret` cifrados y manda un `webhook.test`.
 * - `forget`: al desconectar, borra el webhook en Imagina Whats (si falla, el hub responde 410 y allá se apaga solo).
 */
final class ImaginaWhatsSetup
{
    /**
     * @return array{workspace: string|null, credits: float|null, missing: list<string>, numbers: list<array{id: int, phone: string|null, name: string|null, status: string, enabled: bool}>}
     */
    public function inspect(string $apiKey, string $country): array
    {
        $client = new ImaginaWhatsClient($apiKey);
        $me = $client->me();
        $abilities = is_array($me['token']['abilities'] ?? null) ? array_values(array_filter($me['token']['abilities'], 'is_string')) : [];
        $missing = in_array('*', $abilities, true) ? [] : array_values(array_diff((array) config('igr-messaging.imagina_whats.abilities'), $abilities));
        $numbers = [];
        if (! in_array('wa.accounts.view', $missing, true)) {
            foreach ($client->waAccounts() as $row) {
                if (! is_numeric($row['id'] ?? null) || ($row['status'] ?? null) === 'deleted') {
                    continue;
                }
                $phone = is_string($row['phone'] ?? null) ? Phone::toE164($row['phone'], $country) : null;
                $numbers[] = [
                    'id' => (int) $row['id'],
                    'phone' => $phone,
                    'name' => is_string($row['label'] ?? null) && $row['label'] !== '' ? $row['label'] : (is_string($row['display_name'] ?? null) ? $row['display_name'] : null),
                    'status' => is_string($row['status'] ?? null) ? $row['status'] : 'unknown',
                    'enabled' => (bool) ($row['is_enabled'] ?? true),
                ];
            }
        }

        return [
            'workspace' => is_string($me['workspace']['name'] ?? null) ? $me['workspace']['name'] : null,
            'credits' => is_numeric($me['credits']['balance'] ?? null) ? (float) $me['credits']['balance'] : null,
            'missing' => $missing,
            'numbers' => $numbers,
        ];
    }

    /** El webhook de la cuenta en Imagina Whats; devuelve el problema en palabras o null si quedó listo. */
    public function registerWebhook(ChannelAccount $account): ?string
    {
        $client = ImaginaWhatsClient::for($account);
        if ($client === null) {
            return __('Falta la clave de API de Imagina Whats.');
        }
        $url = self::webhookUrl($account);
        $events = array_values((array) config('igr-messaging.imagina_whats.events'));
        $name = __('Imagina Growth');
        try {
            $existing = collect($client->webhooks())->first(fn (array $w) => ($w['url'] ?? null) === $url && is_numeric($w['id'] ?? null));
            $stamp = (string) Str::ulid();
            if ($existing !== null) {
                $id = (int) $existing['id'];
                $client->updateWebhook($id, $name, $url, $events);
                $created = $client->rotateWebhookSecret($id, 'igr-rotate-'.$account->publicId().'-'.$stamp);
                if (($existing['is_active'] ?? true) === false) {
                    $client->activateWebhook($id);
                }
            } else {
                $created = $client->createWebhook($name, $url, $events, 'igr-webhook-'.$account->publicId().'-'.$stamp);
                $id = is_numeric($created['id'] ?? null) ? (int) $created['id'] : null;
            }
            $secret = is_string($created['secret'] ?? null) ? $created['secret'] : null;
            if ($id === null || $secret === null) {
                return __('Imagina Whats no devolvió el webhook ni su secreto.');
            }
            $account->mergeConfig(['webhook_id' => (string) $id, 'webhook_secret' => $secret]);
            $account->save();
        } catch (ImaginaWhatsError $e) {
            return __('No se pudo registrar el aviso de mensajes entrantes en Imagina Whats: :msg', ['msg' => $e->human()]);
        }
        try {
            $client->testWebhook($id);
        } catch (Throwable) {
            // La prueba es opcional (máximo 10 por minuto): los mensajes reales llegan igual.
        }

        return null;
    }

    public function forget(ChannelAccount $account): void
    {
        $client = ImaginaWhatsClient::for($account);
        $id = $account->credential('webhook_id');
        if ($client === null || $id === null || ! ctype_digit($id)) {
            return;
        }
        try {
            $client->deleteWebhook((int) $id);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function webhookUrl(ChannelAccount $account): string
    {
        return ContactLinkToken::base().'/webhooks/imagina-whats/'.$account->publicId();
    }
}
