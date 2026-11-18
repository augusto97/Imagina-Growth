<?php

declare(strict_types=1);

namespace App\Support\Pii;

use RuntimeException;

/**
 * Índice ciego: HMAC-SHA256 con una clave por espacio de trabajo derivada con
 * HKDF de IGR_BLIND_INDEX_KEY. Permite buscar por igualdad sin guardar el dato
 * en claro (CLAUDE.md §3.9, modelo-de-datos.md). También es el valor de
 * contact_identities para email_hash y phone_hash.
 */
final class BlindIndex
{
    /** @var array<string, string> */
    private static array $keys = [];

    public static function email(string $workspaceId, string $email): string
    {
        return self::hmac($workspaceId, 'email', self::normalizeEmail($email));
    }

    /** @param string $e164 teléfono ya normalizado a E.164 */
    public static function phone(string $workspaceId, string $e164): string
    {
        return self::hmac($workspaceId, 'phone', $e164);
    }

    public static function value(string $workspaceId, string $purpose, string $value): string
    {
        return self::hmac($workspaceId, $purpose, $value);
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function hmac(string $workspaceId, string $purpose, string $value): string
    {
        return hash_hmac('sha256', $purpose.':'.$value, self::key($workspaceId));
    }

    private static function key(string $workspaceId): string
    {
        if (! isset(self::$keys[$workspaceId])) {
            $master = (string) config('igr.blind_index_key');
            if ($master === '') {
                throw new RuntimeException('Falta IGR_BLIND_INDEX_KEY.');
            }
            if (str_starts_with($master, 'base64:')) {
                $master = base64_decode(substr($master, 7), true) ?: throw new RuntimeException('IGR_BLIND_INDEX_KEY inválida.');
            }
            self::$keys[$workspaceId] = hash_hkdf('sha256', $master, 32, 'igr-blind-index', $workspaceId);
        }

        return self::$keys[$workspaceId];
    }

    /** Para pruebas: olvida las claves derivadas en memoria. */
    public static function flush(): void
    {
        self::$keys = [];
    }
}
