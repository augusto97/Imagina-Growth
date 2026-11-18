<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Copilot\Support;

use Carbon\CarbonInterface;

/** Cifras en formato de Colombia y México para los textos sin IA. */
final class Fmt
{
    public static function number(float $n, int $decimals = 0): string
    {
        return number_format($n, $decimals, ',', '.');
    }

    /** «lunes 24 de noviembre a las 07:00» en la zona horaria que ya trae la fecha. */
    public static function dateTime(CarbonInterface $at): string
    {
        $days = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return sprintf('%s %d de %s a las %s', $days[$at->dayOfWeekIso - 1], $at->day, $months[$at->month - 1], $at->format('H:i'));
    }

    public static function money(float $n, string $currency): string
    {
        return $currency === 'USD' ? 'US$'.self::number($n, 2) : '$'.self::number($n).($currency !== '' && $currency !== 'COP' ? ' '.$currency : '');
    }
}
