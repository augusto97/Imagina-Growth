<?php

declare(strict_types=1);

namespace App\Domain\Connections\Support;

use App\Domain\Connections\Enums\ConnectionProvider;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * `state` de OAuth (RFC 6749 §10.12, RFC 9700 §4.7): `{nonce}.{firma}`. El nonce es aleatorio (256 bits) y la
 * firma es un HMAC con la llave de la app que amarra el nonce al proveedor. En `oauth_states` se guarda el hash
 * del nonce con la persona, el espacio de trabajo y el resto cifrado (uso, retorno y el verificador de PKCE).
 *
 * Es de un solo uso: `consume` borra la fila y solo acepta si el borrado afectó exactamente una (dos pestañas
 * o un atacante que repite la URL no pasan). Vence en `igr-connections.state_ttl_minutes`.
 */
final class OAuthStates
{
    /**
     * @param  array<string, mixed>  $payload  `subject`, `purpose`, `return` y, si aplica, `code_verifier`
     */
    public function issue(string $workspaceId, string $userId, ConnectionProvider $provider, array $payload): string
    {
        DB::table('oauth_states')->where('expires_at', '<', now())->delete();
        $nonce = self::base64url(random_bytes(32));
        DB::table('oauth_states')->insert([
            'id' => hash('sha256', $nonce),
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'provider' => $provider->value,
            'payload' => Crypt::encryptString((string) json_encode($payload)),
            'expires_at' => now()->addMinutes((int) config('igr-connections.state_ttl_minutes', 10)),
        ]);

        return $nonce.'.'.self::sign($nonce, $provider);
    }

    /**
     * Valida y gasta el `state`. Devuelve null si la firma no cuadra, si no existe, si venció, si ya se usó, si
     * es de otro proveedor o de otra persona (la sesión que vuelve debe ser la misma que empezó).
     *
     * @return array{workspace_id: string, user_id: string, payload: array<string, mixed>}|null
     */
    public function consume(string $state, ConnectionProvider $provider, ?string $userId): ?array
    {
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2 || strlen($parts[0]) < 40 || ! hash_equals(self::sign($parts[0], $provider), $parts[1])) {
            return null;
        }
        $id = hash('sha256', $parts[0]);
        $row = DB::table('oauth_states')->where('id', $id)->first();
        // Se borra antes de mirar el resto: un state equivocado tampoco se puede volver a intentar.
        if ($row === null || DB::table('oauth_states')->where('id', $id)->delete() !== 1) {
            return null;
        }
        if ((string) $row->provider !== $provider->value || $userId === null || ! hash_equals((string) $row->user_id, $userId)
            || now()->greaterThan((string) $row->expires_at)) {
            return null;
        }
        try {
            $payload = json_decode(Crypt::decryptString((string) $row->payload), true);
        } catch (\Throwable) {
            return null;
        }

        return ['workspace_id' => (string) $row->workspace_id, 'user_id' => (string) $row->user_id, 'payload' => is_array($payload) ? $payload : []];
    }

    /**
     * PKCE (RFC 7636): verificador aleatorio y su desafío S256.
     *
     * @return array{0: string, 1: string}
     */
    public static function pkce(): array
    {
        $verifier = self::base64url(random_bytes(48));

        return [$verifier, self::base64url(hash('sha256', $verifier, true))];
    }

    private static function sign(string $nonce, ConnectionProvider $provider): string
    {
        return self::base64url(hash_hmac('sha256', 'igr-oauth-state|'.$provider->value.'|'.$nonce, (string) config('app.key'), true));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
