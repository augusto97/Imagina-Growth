<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Models;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Un arreglo cifrado dentro de una columna JSON (`{"enc": "<cifrado>"}`): la columna
 * sigue siendo JSON válido para MariaDB y los tokens nunca quedan en claro.
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|null>
 */
final class EncryptedJson implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $wrapped = json_decode($value, true);
        if (! is_array($wrapped) || ! is_string($wrapped['enc'] ?? null)) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($wrapped['enc']), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) json_encode(['enc' => Crypt::encryptString((string) json_encode($value))]);
    }
}
