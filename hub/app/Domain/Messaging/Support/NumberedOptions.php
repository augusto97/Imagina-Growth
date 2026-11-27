<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use Illuminate\Support\Str;

/**
 * Opciones para elegir en un canal que no muestra botones ni listas (WhatsApp no oficial, Messenger, Instagram):
 * salen numeradas en el texto y la respuesta escrita —el número, «2.», «opción 2», 2️⃣, el texto de la opción o la
 * hora de un horario— vuelve como si la persona hubiera tocado el botón (`button_payload` = el ID de la opción).
 *
 * Las opciones quedan en `messages.payload.options` del saliente ([{n, id, title}]); `InboundHandler` las busca en el
 * último saliente de esa conversación (hasta 48 h) y, si la respuesta corresponde a una sola, la traduce.
 */
final class NumberedOptions
{
    /** Por cuánto tiempo una respuesta escrita todavía elige una de las opciones. */
    public const TTL_HOURS = 48;

    /**
     * Las opciones de un mensaje de botones o de lista (WhatsAppInteractive), numeradas desde 1.
     *
     * @param  array<string, mixed>  $interactive
     * @return list<array{n: int, id: string, title: string}>
     */
    public static function fromInteractive(array $interactive): array
    {
        $out = [];
        if (($interactive['type'] ?? '') === 'list') {
            foreach ((array) ($interactive['action']['sections'] ?? []) as $section) {
                $sectionTitle = is_array($section) ? trim((string) ($section['title'] ?? '')) : '';
                foreach ((array) ($section['rows'] ?? []) as $row) {
                    if (! is_array($row) || ! is_string($row['id'] ?? null) || ! is_string($row['title'] ?? null)) {
                        continue;
                    }
                    $title = $sectionTitle !== '' && ! str_contains(mb_strtolower($row['title']), mb_strtolower($sectionTitle))
                        ? $sectionTitle.' · '.$row['title'] : $row['title'];
                    $out[] = ['n' => count($out) + 1, 'id' => $row['id'], 'title' => trim($title)];
                }
            }
        } else {
            foreach ((array) ($interactive['action']['buttons'] ?? []) as $button) {
                $reply = is_array($button) ? (array) ($button['reply'] ?? []) : [];
                if (is_string($reply['id'] ?? null) && is_string($reply['title'] ?? null)) {
                    $out[] = ['n' => count($out) + 1, 'id' => $reply['id'], 'title' => trim($reply['title'])];
                }
            }
        }

        return $out;
    }

    /**
     * El texto con las opciones numeradas y cómo responder.
     *
     * @param  list<array{n: int, id: string, title: string}>  $options
     */
    public static function text(string $body, array $options): string
    {
        $lines = [trim($body), ''];
        foreach ($options as $o) {
            $lines[] = $o['n'].'. '.$o['title'];
        }
        $lines[] = '';
        $lines[] = count($options) === 1
            ? __('Responde «1» para elegirla.')
            : __('Responde con el número de tu opción (:first a :last).', ['first' => 1, 'last' => count($options)]);

        return trim(implode("\n", $lines));
    }

    /**
     * El ID de la opción que eligió con lo que escribió, o null si no corresponde a una sola.
     *
     * @param  list<array{n: int, id: string, title: string}>  $options
     */
    public static function match(?string $reply, array $options): ?string
    {
        if ($reply === null || $options === []) {
            return null;
        }
        $text = self::normalize($reply);
        if ($text === '' || mb_strlen($text) > 120) {
            return null;
        }
        // «2», «2.», «#2», «opción 2», «la 2», «el 2»
        if (preg_match('/^(?:(?:la|el|opcion|numero|num|no)\s*)?#?\s*(\d{1,2})\s*$/', $text, $m) === 1) {
            foreach ($options as $o) {
                if ($o['n'] === (int) $m[1]) {
                    return $o['id'];
                }
            }

            return null;
        }
        // El texto de la opción, igual o dentro de la respuesta («si cancelar por favor»).
        $equal = array_values(array_filter($options, fn (array $o) => self::normalize($o['title']) === $text));
        if (count($equal) === 1) {
            return $equal[0]['id'];
        }
        $inside = array_values(array_filter($options, function (array $o) use ($text): bool {
            $title = self::normalize($o['title']);

            return mb_strlen($title) >= 3 && str_contains(' '.$text.' ', ' '.$title.' ');
        }));
        if (count($inside) === 1) {
            return $inside[0]['id'];
        }
        // Una hora («10:30», «10.30 am», «a las 3:00 pm») que aparece en una sola opción.
        if (preg_match('/\b(\d{1,2})[:.](\d{2})\b/', $text, $t) === 1) {
            $time = ((int) $t[1]).':'.$t[2];
            $byTime = array_values(array_filter($options, fn (array $o) => preg_match('/(?<!\d)'.preg_quote($time, '/').'(?!\d)/', self::normalize($o['title'])) === 1));
            if (count($byTime) === 1) {
                return $byTime[0]['id'];
            }
        }

        return null;
    }

    /**
     * Las opciones guardadas en un saliente, o [] si no hay.
     *
     * @return list<array{n: int, id: string, title: string}>
     */
    public static function fromPayload(mixed $payload): array
    {
        $options = is_array($payload) ? ($payload['options'] ?? null) : null;
        if (! is_array($options)) {
            return [];
        }
        $out = [];
        foreach ($options as $o) {
            if (is_array($o) && is_int($o['n'] ?? null) && is_string($o['id'] ?? null) && is_string($o['title'] ?? null)) {
                $out[] = ['n' => $o['n'], 'id' => $o['id'], 'title' => $o['title']];
            }
        }

        return $out;
    }

    /** Minúsculas sin tildes ni signos; los emojis de número (2️⃣) quedan como el número. */
    private static function normalize(string $text): string
    {
        $text = (string) preg_replace('/(\d)\x{FE0F}?\x{20E3}/u', '$1', $text);
        $text = Str::lower(Str::ascii($text));
        $text = (string) preg_replace('/(\d)\s*([ap])\.?\s*m\.?/', '$1 $2m', $text);
        $text = (string) preg_replace('/[^a-z0-9:. #]+/', ' ', $text);
        $text = (string) preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
