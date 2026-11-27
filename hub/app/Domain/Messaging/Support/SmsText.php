<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

/**
 * Cuánto ocupa un SMS (modulos.md M07/M08): el mismo cálculo en el hub y en la SPA
 * (`resources/js/features/templates/sms.ts`, con los casos de `tests/fixtures/sms/cases.json`).
 *
 * - **GSM-7** (3GPP TS 23.038, alfabeto por defecto): 160 septetos en un mensaje y 153 por parte si se divide (la
 *   cabecera de la concatenación ocupa 7). Los de la tabla de extensión (`^ { } \ [ ~ ] | €` y el salto de página)
 *   van precedidos de ESC y cuentan doble; nunca se parten entre dos partes.
 * - **UCS-2** en cuanto aparece un carácter que no está en GSM-7: 70 unidades de UTF-16 en uno y 67 por parte; un
 *   emoji fuera del plano básico ocupa dos y tampoco se parte.
 *
 * En GSM-7 están «é É ñ Ñ ü Ü ä ö à è ì ò ù ¿ ¡», pero no «á í ó ú Á Í Ó Ú», las comillas tipográficas ni los
 * emojis: una tilde aguda que no sea la «é» pasa el SMS a 70 caracteres. `transliterate()` cambia lo que tiene un
 * equivalente seguro (á→a, comillas → rectas, raya → guion); la «ñ» no se toca porque cabe.
 */
final class SmsText
{
    public const GSM_SINGLE = 160;

    public const GSM_PART = 153;

    public const UCS_SINGLE = 70;

    public const UCS_PART = 67;

    /** Con «Permitir mensajes de 2 partes», el máximo; sin él, uno. */
    public const MAX_PARTS_ALLOWED = 2;

    /** Alfabeto básico de GSM 03.38 (128 posiciones; la 0x1B es el ESC de la extensión). */
    private const BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** Tabla de extensión (ESC + carácter): cuentan doble. */
    private const EXTENSION = "\f^{}\\[~]|€";

    /** Lo que se cambia sin perder sentido para que el SMS quede en GSM-7. */
    private const TRANSLITERATION = [
        'á' => 'a', 'â' => 'a', 'ã' => 'a', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A',
        'ê' => 'e', 'ë' => 'e', 'Ê' => 'E', 'Ë' => 'E', 'È' => 'E',
        'í' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'ú' => 'u', 'û' => 'u', 'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U',
        'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'Y', 'ª' => 'a', 'º' => 'o', '°' => 'o',
        '‘' => "'", '’' => "'", '‚' => "'", '′' => "'", '´' => "'", '`' => "'",
        '“' => '"', '”' => '"', '„' => '"', '″' => '"', '«' => '"', '»' => '"',
        '–' => '-', '—' => '-', '‐' => '-', '‑' => '-', '−' => '-', '•' => '-', '·' => '-',
        '…' => '...', "\t" => ' ', "\u{00A0}" => ' ', "\u{2007}" => ' ', "\u{2009}" => ' ', "\u{202F}" => ' ', "\u{200B}" => '',
    ];

    /**
     * @return array{encoding: 'GSM-7'|'UCS-2', length: int, segments: int, single_limit: int, part_limit: int, offenders: list<string>}
     */
    public static function measure(string $text): array
    {
        $chars = mb_str_split($text);
        $offenders = [];
        $septets = [];
        foreach ($chars as $char) {
            if (str_contains(self::BASIC, $char)) {
                $septets[] = 1;
            } elseif (str_contains(self::EXTENSION, $char)) {
                $septets[] = 2;
            } elseif (! in_array($char, $offenders, true)) {
                $offenders[] = $char;
            }
        }
        if ($offenders === []) {
            return ['encoding' => 'GSM-7', 'length' => array_sum($septets), 'segments' => self::parts($septets, self::GSM_SINGLE, self::GSM_PART),
                'single_limit' => self::GSM_SINGLE, 'part_limit' => self::GSM_PART, 'offenders' => []];
        }
        $units = array_map(fn (string $c): int => mb_ord($c) > 0xFFFF ? 2 : 1, $chars);

        return ['encoding' => 'UCS-2', 'length' => array_sum($units), 'segments' => self::parts($units, self::UCS_SINGLE, self::UCS_PART),
            'single_limit' => self::UCS_SINGLE, 'part_limit' => self::UCS_PART, 'offenders' => array_slice($offenders, 0, 10)];
    }

    /** Cambia lo que tiene equivalente en GSM-7 (tildes agudas, comillas tipográficas, rayas). Lo demás queda igual. */
    public static function transliterate(string $text): string
    {
        return strtr($text, self::TRANSLITERATION);
    }

    /** Cuántas partes como máximo se aceptan. */
    public static function maxParts(bool $allowMultipart): int
    {
        return $allowMultipart ? self::MAX_PARTS_ALLOWED : 1;
    }

    /**
     * El texto que de verdad se envía: tal cual si cabe; si no, sin tildes si así cabe; si tampoco, null (no se envía
     * cortado ni partido).
     *
     * @return array{text: string, segments: int, transliterated: bool}|null
     */
    public static function forSending(string $text, bool $allowMultipart): ?array
    {
        $max = self::maxParts($allowMultipart);
        $measure = self::measure($text);
        if ($measure['segments'] <= $max) {
            return ['text' => $text, 'segments' => max(1, $measure['segments']), 'transliterated' => false];
        }
        $plain = self::transliterate($text);
        $measure = self::measure($plain);
        if ($measure['segments'] <= $max) {
            return ['text' => $plain, 'segments' => max(1, $measure['segments']), 'transliterated' => true];
        }

        return null;
    }

    /**
     * Partes que ocupan las unidades: una si caben en el límite de un mensaje; si no, se llenan partes del límite
     * menor sin partir un par (ESC + carácter, o un par sustituto de UTF-16).
     *
     * @param  list<int>  $units
     */
    private static function parts(array $units, int $single, int $part): int
    {
        $total = array_sum($units);
        if ($total === 0) {
            return 0;
        }
        if ($total <= $single) {
            return 1;
        }
        $parts = 1;
        $used = 0;
        foreach ($units as $u) {
            if ($used + $u > $part) {
                $parts++;
                $used = 0;
            }
            $used += $u;
        }

        return $parts;
    }
}
