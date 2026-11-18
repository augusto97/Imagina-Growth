<?php

declare(strict_types=1);

namespace App\Domain\Templates\Rendering;

use App\Domain\Templates\Exceptions\TemplateRenderException;
use Closure;

/**
 * Parámetros de una plantilla aprobada de WhatsApp en el formato de la Cloud API
 * (`template.components`), a partir de sus componentes en Meta y del mapeo
 * `templates.variables`:
 *
 * ```json
 * {"header": {"1": "{{ contacto.nombre }}"}, "header_media": "{{ producto.imagen }}",
 *  "body": {"1": "{{ contacto.nombre | default: 'hola' }}", "2": "{{ cupon.codigo }}"},
 *  "buttons": {"0": "{{ carrito.token_restaurar }}"}}
 * ```
 *
 * Las claves son el número del parámetro (`{{1}}`) o su nombre (plantillas con
 * parámetros con nombre). WhatsApp no acepta parámetros vacíos ni con saltos de
 * línea: uno vacío es un error, y los saltos se cambian por espacios.
 */
final class WhatsAppComponents
{
    /** @return list<string> claves de parámetros en un texto: `{{1}}` o `{{nombre}}` */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Ranuras que pide la plantilla de Meta, para la interfaz de mapeo.
     *
     * @param  list<array<string, mixed>>  $components
     * @return array{header: list<string>, header_media: string|null, body: list<string>, buttons: list<array{index: int, type: string, text: string, needs_value: bool}>}
     */
    public static function slots(array $components): array
    {
        $out = ['header' => [], 'header_media' => null, 'body' => [], 'buttons' => []];
        foreach ($components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));
            if ($type === 'HEADER') {
                $format = strtoupper((string) ($component['format'] ?? 'TEXT'));
                if ($format === 'TEXT') {
                    $out['header'] = self::placeholders((string) ($component['text'] ?? ''));
                } elseif (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                    $out['header_media'] = strtolower($format);
                }
            } elseif ($type === 'BODY') {
                $out['body'] = self::placeholders((string) ($component['text'] ?? ''));
            } elseif ($type === 'BUTTONS') {
                foreach (array_values((array) ($component['buttons'] ?? [])) as $i => $button) {
                    $kind = strtoupper((string) ($button['type'] ?? ''));
                    $needs = match ($kind) {
                        'URL' => self::placeholders((string) ($button['url'] ?? '')) !== [],
                        'COPY_CODE' => true,
                        default => false,
                    };
                    $out['buttons'][] = ['index' => $i, 'type' => strtolower($kind), 'text' => (string) ($button['text'] ?? ''), 'needs_value' => $needs];
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $components  los de wa_templates.components
     * @param  array<string, mixed>  $mapping  templates.variables
     * @param  Closure(string, string): string  $render  renderiza una expresión Liquid (texto, parte)
     * @return list<array{type: string, sub_type?: string, index?: int, parameters: list<array<string, mixed>>}>
     */
    public static function build(array $components, array $mapping, Closure $render): array
    {
        $slots = self::slots($components);
        $out = [];

        $header = [];
        foreach ($slots['header'] as $key) {
            $header[] = self::text($key, self::value($mapping, 'header', $key, $render));
        }
        if ($slots['header_media'] !== null) {
            $source = is_string($mapping['header_media'] ?? null) ? $mapping['header_media'] : '';
            $link = trim($render($source, __('encabezado')));
            if ($link === '') {
                throw new TemplateRenderException(__('Falta la dirección del archivo del encabezado de WhatsApp.'));
            }
            $header[] = ['type' => $slots['header_media'], $slots['header_media'] => ['link' => $link]];
        }
        if ($header !== []) {
            $out[] = ['type' => 'header', 'parameters' => $header];
        }

        $body = [];
        foreach ($slots['body'] as $key) {
            $body[] = self::text($key, self::value($mapping, 'body', $key, $render));
        }
        if ($body !== []) {
            $out[] = ['type' => 'body', 'parameters' => $body];
        }

        foreach ($slots['buttons'] as $button) {
            $index = $button['index'];
            if ($button['type'] === 'url' && $button['needs_value']) {
                $out[] = ['type' => 'button', 'sub_type' => 'url', 'index' => $index, 'parameters' => [['type' => 'text', 'text' => self::value($mapping, 'buttons', (string) $index, $render)]]];
            } elseif ($button['type'] === 'copy_code') {
                $out[] = ['type' => 'button', 'sub_type' => 'copy_code', 'index' => $index, 'parameters' => [['type' => 'coupon_code', 'coupon_code' => self::value($mapping, 'buttons', (string) $index, $render)]]];
            } elseif ($button['type'] === 'quick_reply') {
                // El payload vuelve en el webhook (message.replied.button_payload).
                $payload = is_string($mapping['buttons'][(string) $index] ?? null) ? trim($render($mapping['buttons'][(string) $index], __('botón'))) : '';
                $out[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => $index, 'parameters' => [['type' => 'payload', 'payload' => $payload !== '' ? $payload : self::slug($button['text'], $index)]]];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  Closure(string, string): string  $render
     */
    private static function value(array $mapping, string $part, string $key, Closure $render): string
    {
        $source = is_array($mapping[$part] ?? null) ? ($mapping[$part][$key] ?? null) : null;
        $label = $part === 'buttons' ? __('botón :n', ['n' => (int) $key + 1]) : __('parámetro :key', ['key' => '{{'.$key.'}}']);
        if (! is_string($source) || trim($source) === '') {
            throw new TemplateRenderException(__('Falta la variable del :label en la plantilla de WhatsApp.', ['label' => $label]));
        }
        $value = trim(preg_replace('/\s{4,}/', '   ', str_replace(["\r\n", "\n", "\r", "\t"], ' ', $render($source, $label))) ?? '');
        if ($value === '') {
            throw new TemplateRenderException(__('La variable del :label quedó vacía: WhatsApp no acepta parámetros vacíos. Usa `| default: "…"`.', ['label' => $label]));
        }

        return mb_substr($value, 0, 1024);
    }

    /** @return array<string, string> */
    private static function text(string $key, string $value): array
    {
        $param = ['type' => 'text', 'text' => $value];
        if (! ctype_digit($key)) {
            $param['parameter_name'] = $key;
        }

        return $param;
    }

    private static function slug(string $text, int $index): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text)) ?? '', '_');

        return $slug !== '' ? mb_substr($slug, 0, 120) : 'boton_'.$index;
    }
}
