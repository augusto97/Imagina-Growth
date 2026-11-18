<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Support;

use App\Domain\Intelligence\Ai\Data\NumberValidation;

/**
 * «La IA no inventa números» (CLAUDE.md §3.6, inteligencia.md §6.1): toda cifra de
 * un texto generado debe existir en los hechos que se le pasaron, con tolerancia
 * por redondeo.
 *
 * - Lee cifras en formato de Colombia y México: miles con punto (`1.232.100`),
 *   decimales con coma (`2,1`), dinero (`$185.000`), porcentajes (`18 %`),
 *   multiplicadores (`2,1×`) y escalas (`1,2 millones`, `37 mil`).
 * - No revisa fechas («10 de noviembre», «del 3 al 9»), años sueltos, horas
 *   (`7:00`), viñetas al inicio de línea ni los enteros de
 *   `igr-ai.validator.free_integers` («3 acciones»). Tampoco lo que va pegado a
 *   letras (códigos de cupón como `PRIMA15`).
 * - Una cifra coincide con un hecho si, redondeado el hecho a la precisión con que
 *   se escribió la cifra, son iguales. Un porcentaje también coincide con una
 *   proporción (0,183 → 18,3 %). Los ceros finales cuentan como redondeo
 *   (`$185.000` cubre 185.432). El signo no cuenta: la dirección va en palabras.
 */
final class NumberValidator
{
    private const MONTHS = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre|ene|feb|mar|abr|may|jun|jul|ago|sep|oct|nov|dic';

    private const NUMBER = '/(?<![\p{L}\d_.,])(?:US)?\$?\s?(\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:[.,]\d+)?)(\s?(?:%|por\s?ciento|x|×|veces)|\s(?:mil\s+millones|millones|millón|mil|M)\b)?(?![\p{L}\d_])/u';

    /**
     * @param  array<mixed>  $facts
     */
    public function validate(string $text, array $facts): NumberValidation
    {
        $known = $this->factNumbers($facts);
        $free = array_map('floatval', (array) config('igr-ai.validator.free_integers', [1, 2, 3]));
        $clean = $this->stripIgnored($text);
        $checked = [];
        $unknown = [];
        if (preg_match_all(self::NUMBER, $clean, $matches, PREG_SET_ORDER) === false) {
            return new NumberValidation([], []);
        }
        foreach ($matches as $m) {
            $raw = trim($m[0]);
            [$value, $decimals] = self::parse($m[1]);
            $suffix = mb_strtolower(trim($m[2] ?? ''));
            $scale = match (true) {
                str_contains($suffix, 'mil millones') => 1_000_000_000,
                str_contains($suffix, 'millon') || str_contains($suffix, 'millón') || $suffix === 'm' => 1_000_000,
                $suffix === 'mil' => 1_000,
                default => 1,
            };
            $percent = str_contains($suffix, '%') || str_contains($suffix, 'ciento');
            if ($scale === 1 && ! $percent && $decimals === 0 && in_array($value, $free, true)) {
                continue;
            }
            $checked[] = $raw;
            if (! $this->matches($value, $decimals, $scale, $percent, $known)) {
                $unknown[] = $raw;
            }
        }

        return new NumberValidation($checked, array_values(array_unique($unknown)));
    }

    /**
     * @return array{0: float, 1: int} valor y decimales con que se escribió
     */
    public static function parse(string $s): array
    {
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s) === 1) {
            [$int, $dec] = array_pad(explode(',', $s), 2, '');

            return [(float) (str_replace('.', '', $int).($dec !== '' ? '.'.$dec : '')), strlen($dec)];
        }
        if (str_contains($s, ',')) {
            [$int, $dec] = explode(',', $s, 2);

            return [(float) ($int.'.'.$dec), strlen($dec)];
        }
        if (str_contains($s, '.')) {
            [$int, $dec] = explode('.', $s, 2);
            // 2.500 con tres decimales es un millar escrito a la colombiana.
            if (strlen($dec) === 3) {
                return [(float) ($int.$dec), 0];
            }

            return [(float) $s, strlen($dec)];
        }

        return [(float) $s, 0];
    }

    /**
     * @param  list<float>  $known
     */
    private function matches(float $value, int $decimals, int $scale, bool $percent, array $known): bool
    {
        $shown = abs($value) * $scale;
        $tolerance = 0.5 * 10 ** (-$decimals) * $scale + 1e-9;
        if ($decimals === 0 && $scale === 1 && $shown >= 1000) {
            // $185.000 redondea a miles: cuenta los ceros finales.
            $zeros = strlen((string) (int) $shown) - strlen(rtrim((string) (int) $shown, '0'));
            $tolerance = max($tolerance, 0.5 * 10 ** min($zeros, 6));
        }
        foreach ($known as $fact) {
            $f = abs($fact);
            if (abs($f - $shown) <= $tolerance) {
                return true;
            }
            if ($percent && $f <= 1.5 && abs($f * 100 - $shown) <= $tolerance) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $facts
     * @return list<float>
     */
    private function factNumbers(array $facts): array
    {
        $out = [];
        array_walk_recursive($facts, function (mixed $v) use (&$out): void {
            if (is_int($v) || is_float($v)) {
                $out[] = (float) $v;
            } elseif (is_string($v) && is_numeric($v)) {
                $out[] = (float) $v;
            } elseif (is_string($v) && preg_match_all(self::NUMBER, $v, $m) > 0) {
                foreach ($m[1] as $n) {
                    $out[] = self::parse($n)[0];
                }
            }
        });

        return array_values(array_unique($out, SORT_REGULAR));
    }

    /** Quita lo que no es una cifra para validar: fechas, años, horas y viñetas. */
    private function stripIgnored(string $text): string
    {
        $months = self::MONTHS;
        $text = (string) preg_replace('/\b\d{1,2}(\s*(?:,|y|al|a|-|–)\s*\d{1,2})*\s+de\s+(?:'.$months.')\b/iu', ' ', $text);
        $text = (string) preg_replace('/\b(?:'.$months.')\.?\s+\d{1,2}\b/iu', ' ', $text);
        $text = (string) preg_replace('/\b\d{1,2}\/\d{1,2}(\/\d{2,4})?\b|\b\d{4}-\d{2}-\d{2}\b/u', ' ', $text);
        $text = (string) preg_replace('/(?<![\d.,$])\b(19|20)\d{2}\b(?![.,]\d)/u', ' ', $text);
        $text = (string) preg_replace('/\b\d{1,2}:\d{2}\b/u', ' ', $text);
        $text = (string) preg_replace('/^\s*\d+[.)]\s/mu', ' ', $text);

        return (string) preg_replace('/\bsemana\s+\d{1,2}\b/iu', ' ', $text);
    }
}
