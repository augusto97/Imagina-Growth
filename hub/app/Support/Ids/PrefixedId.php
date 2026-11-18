<?php

declare(strict_types=1);

namespace App\Support\Ids;

final class PrefixedId
{
    private const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public static function isUlid(string $value): bool
    {
        return (bool) preg_match(self::ULID, $value);
    }

    /** Devuelve el ULID sin prefijo, o null si el valor no tiene el prefijo esperado. */
    public static function decode(string $prefix, ?string $publicId): ?string
    {
        if ($publicId === null || ! str_starts_with($publicId, $prefix.'_')) {
            return null;
        }
        $ulid = substr($publicId, strlen($prefix) + 1);

        return self::isUlid($ulid) ? $ulid : null;
    }

    public static function encode(string $prefix, string $ulid): string
    {
        return $prefix.'_'.$ulid;
    }
}
