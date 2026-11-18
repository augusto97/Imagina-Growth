<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

/** Códigos de enlaces rastreados: 8 caracteres aleatorios sin 0/O ni 1/l/I (hub/CLAUDE.md §8). */
final class LinkCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    public static function generate(int $length = 8): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    public static function looksValid(string $code): bool
    {
        return preg_match('/^[A-Za-z0-9]{8,16}$/', $code) === 1;
    }
}
