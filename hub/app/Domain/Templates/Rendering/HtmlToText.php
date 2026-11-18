<?php

declare(strict_types=1);

namespace App\Domain\Templates\Rendering;

/**
 * Versión de texto de un correo HTML (cuando la plantilla no trae `body_text`):
 * sin estilos ni scripts, con saltos de línea en bloques y celdas, los enlaces
 * como «texto (url)» y las entidades decodificadas. Los clientes de correo y los
 * filtros de spam esperan las dos partes (multipart/alternative).
 */
final class HtmlToText
{
    public static function convert(string $html): string
    {
        $text = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = preg_replace('#<!--.*?-->#s', '', $text) ?? $text;
        // Preheader oculto de MJML: no va en el texto.
        $text = preg_replace('#<div[^>]*display:\s*none[^>]*>.*?</div>#is', '', $text) ?? $text;
        $text = preg_replace_callback('#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function (array $m): string {
            $label = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($url === '' || str_starts_with($url, '#') || (str_starts_with($url, 'mailto:') && $label !== '')) {
                return $label;
            }

            return $label === '' || $label === $url ? $url : $label.' ('.$url.')';
        }, $text) ?? $text;
        $text = preg_replace('#<img\b[^>]*alt\s*=\s*(["\'])(.*?)\1[^>]*>#is', '$2', $text) ?? $text;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|h[1-6]|tr|table|li|section|blockquote)>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<li\b[^>]*>#i', "\n• ", $text) ?? $text;
        $text = preg_replace('#</t[dh]>#i', ' ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $lines = array_map(fn (string $l) => trim(preg_replace('/[ \t]+/', ' ', $l) ?? $l), explode("\n", $text));
        $text = implode("\n", $lines);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
