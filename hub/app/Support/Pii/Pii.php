<?php

declare(strict_types=1);

namespace App\Support\Pii;

/**
 * Enmascara datos personales para logs y pantallas (CLAUDE.md §6: nunca se
 * escriben en logs en claro).
 */
final class Pii
{
    /** Claves que se consideran datos personales en cualquier nivel de un arreglo. */
    private const KEYS = [
        'email', 'phone', 'first_name', 'last_name', 'name', 'company', 'address_1', 'address_2',
        'postcode', 'ip', 'user_agent', 'password', 'token', 'secret', 'message',
    ];

    public static function mask(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = is_string($key) && in_array(strtolower($key), self::KEYS, true)
                    ? self::maskString(is_scalar($item) ? (string) $item : (json_encode($item) ?: ''))
                    : (is_array($item) ? self::mask($item) : $item);
            }

            return $out;
        }

        return is_string($value) ? self::maskString($value) : $value;
    }

    public static function maskString(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '@')) {
            return self::maskEmail($value);
        }
        if (preg_match('/^\+?[0-9 ()-]{7,}$/', $value)) {
            return self::maskPhone($value);
        }
        $len = mb_strlen($value);

        return mb_substr($value, 0, 1).str_repeat('*', max(2, min($len - 1, 8)));
    }

    /**
     * Quita correos y secuencias largas de dígitos (teléfonos, documentos) de un
     * texto libre, como un mensaje de error, sin destruir el resto.
     */
    public static function scrub(string $text): string
    {
        $text = (string) preg_replace_callback('/[^\s@"\'<>]+@[^\s@"\'<>]+\.[A-Za-z]{2,}/', fn ($m) => self::maskEmail($m[0]), $text);

        return (string) preg_replace_callback('/\+?\d[\d ()-]{6,}\d/', fn ($m) => self::maskPhone($m[0]), $text);
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $dot = strrpos($domain, '.');
        $host = $dot === false ? $domain : substr($domain, 0, $dot);
        $tld = $dot === false ? '' : substr($domain, $dot);

        return mb_substr($local, 0, 1).str_repeat('*', max(2, mb_strlen($local) - 1))
            .'@'.mb_substr($host, 0, 1).str_repeat('*', max(2, mb_strlen($host) - 1)).$tld;
    }

    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 6) {
            return str_repeat('*', strlen($digits));
        }
        $prefix = str_starts_with($phone, '+') ? '+'.substr($digits, 0, 2) : substr($digits, 0, 2);

        return $prefix.str_repeat('*', strlen($digits) - 5).substr($digits, -3);
    }
}
