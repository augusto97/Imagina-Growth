<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Support;

/**
 * Mínimo de datos personales (inteligencia.md §6.1): antes de mandar hechos de
 * análisis al modelo se borran correos, teléfonos y números de documento que se
 * hayan colado en textos libres (nombres de productos, búsquedas, notas).
 */
final class PiiScrubber
{
    private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    // +57 300 123 4567, 3001234567, (604) 123-4567: 7 o más dígitos con separadores.
    private const PHONE = '/(?<![\w.,$])\+?\d[\d\s().\-]{8,}\d(?![\w%])/u';

    /** Mismo tipo que recibe: texto limpio, o el arreglo con sus textos limpios. */
    public static function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = (string) preg_replace(self::EMAIL, '[correo]', $value);

            return (string) preg_replace_callback(self::PHONE, function (array $m): string {
                // Un número con puntos de miles (1.232.100) es una cifra, no un teléfono.
                return preg_match('/^\d{1,3}(\.\d{3})+$/', trim($m[0])) === 1 ? $m[0] : '[teléfono]';
            }, $value);
        }
        if (is_array($value)) {
            return array_map(fn ($v) => self::scrub($v), $value);
        }

        return $value;
    }
}
