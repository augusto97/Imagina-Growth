<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use InvalidArgumentException;

/**
 * Mensajes interactivos de WhatsApp (Cloud API): botones de respuesta y listas.
 * Arma el objeto `interactive` del cuerpo de `POST /{phone-number-id}/messages`
 * recortando cada texto a los límites de Meta, y su versión en texto para los
 * canales que no los muestran (Zender) y para la historia de la conversación.
 *
 * Límites verificados en la documentación oficial el 20 de noviembre de 2026:
 * - Lista: https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/interactive-list-messages
 *   cuerpo 4.096, encabezado y pie 60, botón 20, hasta 10 secciones y 10 filas en total,
 *   título de sección 24, ID de fila 200, título de fila 24 y descripción 72.
 * - Botones: https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/interactive-reply-buttons-messages
 *   hasta 3 botones, ID 256, título 20 (únicos entre sí), cuerpo 1.024 y pie 60.
 * - Solo son mensajes de servicio: van dentro de la ventana de atención de 24 h
 *   (https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/send-messages);
 *   fuera de ella solo salen plantillas aprobadas. El despachador lo hace cumplir (SendPolicy).
 *
 * La respuesta llega por el webhook como `interactive.button_reply` o `interactive.list_reply`
 * con el `id` y el `title` elegidos (MetaCloudDriver los deja en `button_payload` y `text`).
 */
final class WhatsAppInteractive
{
    public const LIST_BODY = 4096;

    public const LIST_BUTTON = 20;

    public const LIST_MAX_ROWS = 10;

    public const LIST_MAX_SECTIONS = 10;

    public const SECTION_TITLE = 24;

    public const ROW_ID = 200;

    public const ROW_TITLE = 24;

    public const ROW_DESCRIPTION = 72;

    public const BUTTONS_BODY = 1024;

    public const BUTTONS_MAX = 3;

    public const BUTTON_ID = 256;

    public const BUTTON_TITLE = 20;

    public const FOOTER = 60;

    /**
     * Botones de respuesta (1 a 3).
     *
     * @param  list<array{id: string, title: string}>  $buttons
     * @return array<string, mixed>
     */
    public static function buttons(string $body, array $buttons, ?string $footer = null): array
    {
        $out = [];
        $titles = [];
        foreach (array_slice($buttons, 0, self::BUTTONS_MAX) as $b) {
            $id = self::id($b['id'], self::BUTTON_ID);
            $title = self::cut($b['title'], self::BUTTON_TITLE);
            if ($id === '' || $title === '' || in_array($title, $titles, true)) {
                throw new InvalidArgumentException('Cada botón necesita un ID y un título únicos.');
            }
            $titles[] = $title;
            $out[] = ['type' => 'reply', 'reply' => ['id' => $id, 'title' => $title]];
        }
        if ($out === []) {
            throw new InvalidArgumentException('Un mensaje con botones necesita al menos uno.');
        }

        return array_filter([
            'type' => 'button',
            'body' => ['text' => self::cut($body, self::BUTTONS_BODY)],
            'footer' => $footer !== null && trim($footer) !== '' ? ['text' => self::cut($footer, self::FOOTER)] : null,
            'action' => ['buttons' => $out],
        ], fn ($v) => $v !== null);
    }

    /**
     * Lista con secciones (máximo 10 filas en total).
     *
     * @param  list<array{title: string, rows: list<array{id: string, title: string, description?: string|null}>}>  $sections
     * @return array<string, mixed>
     */
    public static function list(string $body, string $button, array $sections, ?string $footer = null): array
    {
        $left = self::LIST_MAX_ROWS;
        $out = [];
        foreach (array_slice($sections, 0, self::LIST_MAX_SECTIONS) as $section) {
            $rows = [];
            foreach ($section['rows'] as $row) {
                if ($left === 0) {
                    break;
                }
                $id = self::id($row['id'], self::ROW_ID);
                $title = self::cut($row['title'], self::ROW_TITLE);
                if ($id === '' || $title === '') {
                    continue;
                }
                $description = isset($row['description']) ? self::cut((string) $row['description'], self::ROW_DESCRIPTION) : '';
                $rows[] = array_filter(['id' => $id, 'title' => $title, 'description' => $description !== '' ? $description : null], fn ($v) => $v !== null);
                $left--;
            }
            if ($rows !== []) {
                $out[] = ['title' => self::cut($section['title'], self::SECTION_TITLE), 'rows' => $rows];
            }
        }
        if ($out === []) {
            throw new InvalidArgumentException('Una lista necesita al menos una opción.');
        }

        return array_filter([
            'type' => 'list',
            'body' => ['text' => self::cut($body, self::LIST_BODY)],
            'footer' => $footer !== null && trim($footer) !== '' ? ['text' => self::cut($footer, self::FOOTER)] : null,
            'action' => ['button' => self::cut($button, self::LIST_BUTTON), 'sections' => $out],
        ], fn ($v) => $v !== null);
    }

    /**
     * El mismo mensaje con otro cuerpo (p. ej. la respuesta del agente sobre las opciones que armó una herramienta).
     *
     * @param  array<string, mixed>  $interactive
     * @return array<string, mixed>
     */
    public static function withBody(array $interactive, string $body): array
    {
        $limit = ($interactive['type'] ?? '') === 'list' ? self::LIST_BODY : self::BUTTONS_BODY;
        $interactive['body'] = ['text' => self::cut($body, $limit)];

        return $interactive;
    }

    /**
     * Texto con las opciones, para los canales que no muestran botones ni listas y para la historia.
     *
     * @param  array<string, mixed>  $interactive
     */
    public static function asText(array $interactive): string
    {
        $lines = [trim((string) ($interactive['body']['text'] ?? ''))];
        if (($interactive['type'] ?? '') === 'list') {
            foreach ((array) ($interactive['action']['sections'] ?? []) as $section) {
                foreach ((array) ($section['rows'] ?? []) as $row) {
                    $lines[] = '• '.trim($section['title'].' · '.$row['title'], ' ·');
                }
            }
        } else {
            foreach ((array) ($interactive['action']['buttons'] ?? []) as $b) {
                $lines[] = '• '.$b['reply']['title'];
            }
        }

        return trim(implode("\n", array_filter($lines, fn ($l) => $l !== '')));
    }

    /** ¿Es un objeto `interactive` que este módulo sabe enviar? */
    public static function valid(mixed $interactive): bool
    {
        if (! is_array($interactive) || ! is_string($interactive['body']['text'] ?? null) || trim($interactive['body']['text']) === '') {
            return false;
        }

        return match ($interactive['type'] ?? null) {
            'button' => is_array($interactive['action']['buttons'] ?? null) && $interactive['action']['buttons'] !== [],
            'list' => is_array($interactive['action']['sections'] ?? null) && $interactive['action']['sections'] !== [] && is_string($interactive['action']['button'] ?? null),
            default => false,
        };
    }

    /** Un ID no se recorta: si no cabe, el que llama armó mal la opción. */
    private static function id(string $id, int $max): string
    {
        $id = trim($id);
        if (mb_strlen($id) > $max) {
            throw new InvalidArgumentException('El ID de la opción es demasiado largo.');
        }

        return $id;
    }

    private static function cut(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', $text));

        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)).'…';
    }
}
