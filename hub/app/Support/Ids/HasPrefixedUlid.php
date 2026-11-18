<?php

declare(strict_types=1);

namespace App\Support\Ids;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

/**
 * ULID en mayúsculas (Crockford) como clave primaria, con un prefijo legible
 * solo en la API: `ws_01JD...`, `st_01JD...` (docs/modelo-de-datos.md).
 *
 * El modelo debe declarar `public const ID_PREFIX = 'ws';`.
 */
trait HasPrefixedUlid
{
    use HasUlids;

    public function newUniqueId(): string
    {
        return strtoupper((string) Str::ulid());
    }

    public function isValidUniqueId(mixed $value): bool
    {
        return is_string($value) && PrefixedId::isUlid($value);
    }

    public function publicId(): string
    {
        return static::ID_PREFIX.'_'.$this->getKey();
    }

    public static function findByPublicId(?string $publicId): ?static
    {
        $id = PrefixedId::decode(static::ID_PREFIX, $publicId);

        return $id === null ? null : static::query()->find($id);
    }
}
