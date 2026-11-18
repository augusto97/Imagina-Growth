<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Domain\Messaging\Models\WaTemplate;

/**
 * Texto de una plantilla de WhatsApp con sus parámetros puestos: para la vista
 * previa y para Zender, que solo envía texto.
 */
final class WaTemplateText
{
    /**
     * @param  list<array<string, mixed>>  $components  parámetros en el formato de la Cloud API
     */
    public static function compose(WaTemplate $template, array $components): string
    {
        $params = [];
        foreach ($components as $component) {
            $type = strtolower((string) ($component['type'] ?? ''));
            foreach (array_values((array) ($component['parameters'] ?? [])) as $i => $param) {
                $params[$type][$i + 1] = is_array($param) ? (string) ($param['text'] ?? '') : '';
            }
        }
        $parts = [];
        foreach ($template->components as $component) {
            $type = strtolower((string) ($component['type'] ?? ''));
            if (! in_array($type, ['header', 'body', 'footer'], true) || ! is_string($component['text'] ?? null)) {
                continue;
            }
            $parts[] = (string) preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', fn (array $m) => $params[$type][(int) $m[1]] ?? '', $component['text']);
        }

        return trim(implode("\n\n", array_filter($parts, fn (string $p) => trim($p) !== '')));
    }
}
