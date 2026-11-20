<?php

declare(strict_types=1);

namespace App\Domain\Connections\Actions;

use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\MetaProvider;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Connections\Support\ConnectionTokens;
use App\Domain\Messaging\Actions\ConnectWhatsAppNumber;
use App\Domain\Messaging\Models\ChannelAccount;

/**
 * Deja un número de la cuenta de WhatsApp (WABA) conectada por Embedded Signup como canal del espacio de trabajo
 * (pasos del proveedor de tecnología: https://developers.facebook.com/docs/whatsapp/embedded-signup/onboarding-customers-as-a-tech-provider):
 *
 * - **Número nuevo en la Cloud API:** se registra (`POST /{número}/register` con un PIN de verificación en dos
 *   pasos de 6 dígitos que se guarda cifrado en la cuenta).
 * - **Coexistencia** (el número sigue en la app de WhatsApp Business): no se registra (ya lo está) y se piden,
 *   una sola vez, la copia de los contactos y del historial (`POST /{número}/smb_app_data` con
 *   `smb_app_state_sync` y `history`; hay 24 h para el historial). El número queda con 20 mensajes por segundo.
 *   https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users
 *
 * Después la cuenta se crea con ConnectWhatsAppNumber (número, calidad, límite y plantillas sincronizadas) y su
 * token sale de la conexión. Un error de registro deja la cuenta en `error` con el motivo; se puede reintentar.
 */
final class AttachWhatsAppNumber
{
    public function __construct(private readonly ConnectionTokens $tokens, private readonly ConnectWhatsAppNumber $connect, private readonly MetaProvider $meta = new MetaProvider(ConnectionProvider::MetaWhatsApp)) {}

    /** @throws ConnectionError */
    public function handle(OAuthConnection $connection, string $phoneNumberId): ChannelAccount
    {
        if ($connection->provider !== ConnectionProvider::MetaWhatsApp) {
            throw new ConnectionError(__('Esta conexión no es de WhatsApp.'), 'asset');
        }
        $token = $this->tokens->accessToken($connection);
        $wa = $connection->asset('whatsapp');
        $waba = (string) ($wa['waba_id'] ?? '');
        $phone = null;
        foreach ($this->phones($waba, $token) as $row) {
            if ($row['id'] === $phoneNumberId) {
                $phone = $row;
            }
        }
        if ($phone === null) {
            throw new ConnectionError(__('Ese número no está en la cuenta de WhatsApp que conectaste.'), 'asset');
        }
        $coexistence = (bool) ($wa['coexistence'] ?? false) || $phone['is_on_biz_app'];
        $existing = ChannelAccount::query()->where('provider', 'meta_cloud')->where('external_id', $phoneNumberId)->where('oauth_connection_id', $connection->id)->first();
        $pin = (string) ($existing?->config['two_step_pin'] ?? str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT));
        $config = ['waba_id' => $waba, 'phone_number_id' => $phoneNumberId, 'connected_via' => 'embedded_signup', 'coexistence' => $coexistence];
        // Al reconectar se conserva lo ya copiado de la app (cada copia se pide una sola vez) y su avance.
        $config += array_intersect_key($existing->config ?? [], array_flip(['synced', 'sync_requests', 'history', 'contacts']));
        $errors = [];
        $status = 'active';

        if (! $coexistence) {
            $config['two_step_pin'] = $pin;
            $response = $this->meta->send('post', $phoneNumberId.'/register', $token, ['messaging_product' => 'whatsapp', 'pin' => $pin]);
            if (! $response->successful()) {
                $status = 'error';
                $errors[] = __('Meta no activó el número en la API (:msg). Si el número tiene un PIN de verificación en dos pasos, quítalo en el Administrador de WhatsApp y vuelve a intentar.', ['msg' => MetaProvider::errorMessage($response)]);
            }
        }
        if (! (bool) ($wa['subscribed'] ?? false)) {
            $errors[] = __('No se pudo suscribir la app a los avisos de la cuenta: los mensajes que lleguen no se verán en la bandeja hasta reintentar.');
        }

        $account = $this->connect->handle($config, $phone['verified_name'], $connection->id, $status, $errors !== [] ? implode(' ', $errors) : null);
        if ($coexistence) {
            // Después de crear la cuenta activa: los webhooks `smb_app_state_sync` e `history` empiezan a llegar en
            // segundos y se buscan por el ID del número.
            $this->requestSync($account, $phoneNumberId, $token, $errors);
        }

        return $account;
    }

    /**
     * Coexistencia: pide una sola vez la copia de los contactos y del historial de la app (`POST /{número}/smb_app_data`
     * con `smb_app_state_sync` y `history`; hay 24 h desde la conexión). Guarda el `request_id` que pide soporte de Meta.
     *
     * @param  list<string>  $errors
     */
    private function requestSync(ChannelAccount $account, string $phoneNumberId, string $token, array $errors): void
    {
        $synced = (array) ($account->config['synced'] ?? []);
        $requests = (array) ($account->config['sync_requests'] ?? []);
        foreach (['smb_app_state_sync', 'history'] as $sync) {
            if (isset($synced[$sync])) {
                continue;  // cada copia se puede pedir una sola vez
            }
            $response = $this->meta->send('post', $phoneNumberId.'/smb_app_data', $token, ['messaging_product' => 'whatsapp', 'sync_type' => $sync]);
            if ($response->successful()) {
                $synced[$sync] = now()->toIso8601String();
                $requests[$sync] = is_scalar($response->json('request_id')) ? mb_substr((string) $response->json('request_id'), 0, 120) : null;
            } else {
                $errors[] = __('Meta no copió :what desde la app de WhatsApp Business (:msg).', ['what' => $sync === 'history' ? __('el historial') : __('los contactos'), 'msg' => MetaProvider::errorMessage($response)]);
            }
        }
        $account->mergeConfig(['synced' => $synced, 'sync_requests' => array_filter($requests)]);
        $account->forceFill(['last_error' => $errors !== [] ? mb_substr(implode(' ', $errors), 0, 500) : null])->save();
    }

    /**
     * Números de la cuenta de WhatsApp: GET /{waba}/phone_numbers.
     *
     * @return list<array{id: string, display_phone_number: string|null, verified_name: string|null, is_on_biz_app: bool}>
     */
    public function phones(string $waba, string $token): array
    {
        if ($waba === '') {
            return [];
        }
        $response = $this->meta->get($waba.'/phone_numbers', ['fields' => 'id,display_phone_number,verified_name,quality_rating,is_on_biz_app,platform_type', 'limit' => 50], $token);
        if (! $response->successful()) {
            throw new ConnectionError(__('Meta no entregó los números de la cuenta: :msg', ['msg' => MetaProvider::errorMessage($response)]), 'provider');
        }
        $out = [];
        foreach ((array) $response->json('data', []) as $row) {
            if (is_array($row) && isset($row['id'])) {
                $out[] = [
                    'id' => (string) $row['id'],
                    'display_phone_number' => isset($row['display_phone_number']) ? (string) $row['display_phone_number'] : null,
                    'verified_name' => isset($row['verified_name']) ? (string) $row['verified_name'] : null,
                    'is_on_biz_app' => (bool) ($row['is_on_biz_app'] ?? false),
                ];
            }
        }

        return $out;
    }
}
