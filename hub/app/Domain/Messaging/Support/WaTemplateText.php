<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Domain\Messaging\Models\WaTemplate;
use App\Domain\Templates\Rendering\WhatsAppComponents;

/**
 * Texto de una plantilla de WhatsApp con sus parámetros puestos: para la vista previa y para los WhatsApp no
 * oficiales (Zender, Imagina Whats), que no envían plantillas de Meta (ChannelCapabilities):
 *
 * - encabezado de texto, cuerpo y pie, con sus parámetros;
 * - botones de enlace, llamada y código escritos en el texto («Ver mi pedido: https://…»), y las respuestas rápidas
 *   como opciones numeradas (`NumberedOptions`, con su payload como ID: el «1» vuelve como si la hubiera tocado);
 * - el archivo del encabezado (imagen, video o documento) aparte, para mandarlo si el proveedor lo envía.
 */
final class WaTemplateText
{
    /**
     * @param  list<array<string, mixed>>  $components  parámetros en el formato de la Cloud API
     */
    public static function compose(WaTemplate $template, array $components): string
    {
        return self::degrade($template, $components)['text'];
    }

    /**
     * @param  list<array<string, mixed>>  $components  parámetros en el formato de la Cloud API
     * @return array{text: string, options: list<array{n: int, id: string, title: string}>, media: array{type: string, url: string}|null, dropped: list<string>}
     */
    public static function degrade(WaTemplate $template, array $components): array
    {
        $params = [];
        $buttons = [];
        $media = null;
        foreach ($components as $component) {
            $type = strtolower((string) ($component['type'] ?? ''));
            if ($type === 'button') {
                $param = ((array) ($component['parameters'] ?? []))[0] ?? [];
                $param = is_array($param) ? $param : [];
                $buttons[(int) ($component['index'] ?? 0)] = (string) ($param['text'] ?? $param['payload'] ?? $param['coupon_code'] ?? '');

                continue;
            }
            foreach (array_values((array) ($component['parameters'] ?? [])) as $i => $param) {
                if (! is_array($param)) {
                    continue;
                }
                $kind = (string) ($param['type'] ?? 'text');
                if ($type === 'header' && in_array($kind, ['image', 'video', 'document'], true)) {
                    $link = $param[$kind]['link'] ?? null;
                    $media = is_string($link) && $link !== '' ? ['type' => $kind, 'url' => $link] : $media;

                    continue;
                }
                $params[$type][$i + 1] = (string) ($param['text'] ?? '');
            }
        }

        $parts = [];
        $lines = [];
        $options = [];
        $dropped = [];
        foreach ($template->components as $component) {
            $type = strtolower((string) ($component['type'] ?? ''));
            if (in_array($type, ['header', 'body', 'footer'], true) && is_string($component['text'] ?? null)) {
                $parts[] = (string) preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', fn (array $m) => $params[$type][(int) $m[1]] ?? '', $component['text']);

                continue;
            }
            if ($type !== 'buttons') {
                continue;
            }
            foreach (array_values((array) ($component['buttons'] ?? [])) as $i => $button) {
                if (! is_array($button)) {
                    continue;
                }
                $label = trim((string) ($button['text'] ?? ''));
                $value = $buttons[$i] ?? '';
                switch (strtoupper((string) ($button['type'] ?? ''))) {
                    case 'URL':
                        $url = (string) preg_replace('/\{\{\s*\d+\s*\}\}/', $value, (string) ($button['url'] ?? ''));
                        $url !== '' ? $lines[] = ($label !== '' ? $label.': ' : '').$url : $dropped[] = 'button';
                        break;
                    case 'PHONE_NUMBER':
                        $phone = (string) ($button['phone_number'] ?? '');
                        $phone !== '' ? $lines[] = ($label !== '' ? $label.': ' : '').$phone : $dropped[] = 'button';
                        break;
                    case 'COPY_CODE':
                        $value !== '' ? $lines[] = __('Código: :code', ['code' => $value]) : $dropped[] = 'button';
                        break;
                    case 'QUICK_REPLY':
                        if ($label !== '') {
                            $options[] = ['n' => count($options) + 1, 'id' => $value !== '' ? $value : WhatsAppComponents::slug($label, $i), 'title' => $label];
                        }
                        break;
                    default:
                        $dropped[] = 'button'; // catálogo, flujos, OTP: no tienen equivalente en texto
                }
            }
        }

        $text = trim(implode("\n\n", array_filter($parts, fn (string $p) => trim($p) !== '')));
        if ($lines !== []) {
            $text = trim($text."\n\n".implode("\n", $lines));
        }
        if ($options !== []) {
            $text = NumberedOptions::text($text, $options);
        }

        return ['text' => $text, 'options' => $options, 'media' => $media, 'dropped' => array_values(array_unique($dropped))];
    }
}
