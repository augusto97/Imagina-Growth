<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Brief;

use Illuminate\Support\Str;

/**
 * La ficha del lead que se llena con la conversación (M18, docs/auditoria-2026-11.md
 * §5.1 función 2): qué necesita, cantidad, ciudad, zona, para cuándo, presupuesto y
 * la temperatura (fría, tibia o caliente) con su razón.
 *
 * Cada campo guarda `{value, quote, by, at}`: el valor, la frase del cliente de la
 * que salió, quién lo puso (`ai` o `user`) y cuándo. **Lo que escribió una persona
 * nunca lo pisa la IA**, ni siquiera un campo que la persona dejó vacío a propósito
 * (queda `{value: null, by: user}`).
 *
 * «La IA no inventa»: un valor solo se acepta si aparece, palabra por palabra
 * (sin tildes ni mayúsculas), en lo que **escribió el cliente**; la frase de origen
 * también debe estar ahí y contener el valor (si no, se toma la frase del mensaje
 * donde aparece). Lo que no cumple se descarta.
 */
final class LeadBrief
{
    /** Campos de texto que se extraen, con su largo máximo. */
    public const TEXT_FIELDS = ['need' => 160, 'quantity' => 80, 'city' => 80, 'zone' => 80, 'timing' => 80, 'budget' => 80];

    public const TEMPERATURES = ['cold', 'warm', 'hot'];

    /** Todos los campos de la ficha (los de texto y la temperatura). */
    public const FIELDS = ['need', 'quantity', 'city', 'zone', 'timing', 'budget', 'temperature'];

    public const QUOTE_MAX = 200;

    /** Minúsculas, sin tildes ni signos, con un espacio entre palabras (para comparar frases). */
    public static function normalize(string $text): string
    {
        $ascii = Str::lower(Str::ascii($text));
        $clean = (string) preg_replace('/[^a-z0-9$%]+/', ' ', $ascii);

        return trim((string) preg_replace('/\s+/', ' ', $clean));
    }

    /** ¿`needle` aparece como palabras completas dentro de `haystack`? (ambos normalizados) */
    public static function containsWords(string $haystack, string $needle): bool
    {
        $h = self::normalize($haystack);
        $n = self::normalize($needle);

        return $n !== '' && str_contains(' '.$h.' ', ' '.$n.' ');
    }

    /**
     * Valida un valor contra lo que escribió el cliente. Devuelve la frase de origen
     * (la que propuso el modelo si es real y contiene el valor, o la del mensaje
     * donde aparece) y el índice del mensaje, o null si el valor no está en el texto.
     *
     * @param  list<string>  $customerTexts  mensajes del cliente, del más viejo al más nuevo
     * @return array{quote: string, index: int}|null
     */
    public static function ground(string $value, ?string $quote, array $customerTexts): ?array
    {
        $value = trim($value);
        if ($value === '' || $customerTexts === []) {
            return null;
        }
        $quote = is_string($quote) ? trim($quote) : '';
        // El mensaje más reciente que lo dice gana: si cambió de opinión, vale lo último.
        for ($i = count($customerTexts) - 1; $i >= 0; $i--) {
            $text = $customerTexts[$i];
            if (! self::containsWords($text, $value)) {
                continue;
            }
            if ($quote !== '' && self::containsWords($text, $quote) && self::containsWords($quote, $value)) {
                return ['quote' => mb_substr($quote, 0, self::QUOTE_MAX), 'index' => $i];
            }

            return ['quote' => self::sentence($text, $value), 'index' => $i];
        }

        return null;
    }

    /** La frase del mensaje que contiene el valor (o el mensaje recortado). */
    public static function sentence(string $text, string $value): string
    {
        $parts = preg_split('/(?<=[.!?¿¡\n])\s*/u', $text) ?: [$text];
        foreach ($parts as $part) {
            if (self::containsWords($part, $value)) {
                return mb_substr(trim($part), 0, self::QUOTE_MAX);
            }
        }

        return mb_substr(trim($text), 0, self::QUOTE_MAX);
    }

    /**
     * Mezcla lo nuevo de la IA con la ficha guardada: solo cambia los campos que no
     * tocó una persona y cuyo valor es distinto. Devuelve la ficha y los campos que cambiaron.
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, array{value: string, quote: string, reason?: string}>  $found
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
     */
    public static function merge(?array $current, array $found, string $at, ?string $messageId = null): array
    {
        /** @var array<string, array<string, mixed>> $brief */
        $brief = [];
        foreach ((array) $current as $key => $field) {
            if (in_array($key, self::FIELDS, true) && is_array($field)) {
                $brief[$key] = $field;
            }
        }
        $changed = [];
        foreach ($found as $key => $item) {
            if (! in_array($key, self::FIELDS, true)) {
                continue;
            }
            $existing = $brief[$key] ?? null;
            if (is_array($existing) && ($existing['by'] ?? null) === 'user') {
                continue; // lo editó una persona
            }
            $same = is_array($existing) && self::normalize((string) ($existing['value'] ?? '')) === self::normalize($item['value'])
                && ($key !== 'temperature' || ($existing['reason'] ?? null) === ($item['reason'] ?? null));
            if ($same) {
                continue;
            }
            $brief[$key] = array_filter([
                'value' => $item['value'],
                'quote' => $item['quote'],
                'reason' => $item['reason'] ?? null,
                'by' => 'ai',
                'at' => $at,
                'message_id' => $messageId,
            ], fn ($v) => $v !== null);
            $changed[] = $key;
        }

        return [$brief, $changed];
    }

    /**
     * Lo que edita una persona: queda como suyo (la IA ya no lo cambia). Vacío = la
     * persona lo borró a propósito.
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, string|null>  $edits
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
     */
    public static function edit(?array $current, array $edits, string $at, string $userId): array
    {
        $brief = [];
        foreach ((array) $current as $key => $field) {
            if (in_array($key, self::FIELDS, true) && is_array($field)) {
                $brief[$key] = $field;
            }
        }
        $changed = [];
        foreach ($edits as $key => $value) {
            if (! in_array($key, self::FIELDS, true)) {
                continue;
            }
            $value = $value !== null && trim($value) !== '' ? trim($value) : null;
            if ($key === 'temperature' && $value !== null && ! in_array($value, self::TEMPERATURES, true)) {
                continue;
            }
            if ($key !== 'temperature' && $value !== null) {
                $value = mb_substr($value, 0, self::TEXT_FIELDS[$key]);
            }
            $old = $brief[$key] ?? null;
            if (is_array($old) && ($old['by'] ?? null) === 'user' && ($old['value'] ?? null) === $value) {
                continue;
            }
            $brief[$key] = ['value' => $value, 'by' => 'user', 'at' => $at, 'user_id' => $userId];
            $changed[] = $key;
        }

        return [$brief, $changed];
    }

    /**
     * El valor de un campo, o null.
     *
     * @param  array<string, mixed>|null  $brief
     */
    public static function value(?array $brief, string $key): ?string
    {
        $v = is_array($brief[$key] ?? null) ? ($brief[$key]['value'] ?? null) : null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * La ficha para la SPA: cada campo con su valor, quién lo puso, la frase de origen y cuándo.
     *
     * @param  array<string, mixed>|null  $brief
     * @return array<string, array{value: string|null, by: string, quote: string|null, reason: string|null, at: string|null}|null>
     */
    public static function present(?array $brief): array
    {
        $out = [];
        foreach (self::FIELDS as $key) {
            $f = is_array($brief[$key] ?? null) ? $brief[$key] : null;
            $out[$key] = $f === null ? null : [
                'value' => is_string($f['value'] ?? null) ? $f['value'] : null,
                'by' => ($f['by'] ?? 'ai') === 'user' ? 'user' : 'ai',
                'quote' => is_string($f['quote'] ?? null) ? $f['quote'] : null,
                'reason' => is_string($f['reason'] ?? null) ? $f['reason'] : null,
                'at' => is_string($f['at'] ?? null) ? $f['at'] : null,
            ];
        }

        return $out;
    }

    /**
     * Los hechos de la ficha (solo valores) para otros textos con IA: el seguimiento y «Hoy».
     *
     * @param  array<string, mixed>|null  $brief
     * @return array<string, string>
     */
    public static function facts(?array $brief): array
    {
        $out = [];
        foreach (self::FIELDS as $key) {
            $v = self::value($brief, $key);
            if ($v !== null) {
                $out[$key] = $v;
            }
        }

        return $out;
    }
}
