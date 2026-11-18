<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use Illuminate\Validation\ValidationException;

/**
 * Del formulario del editor a los `components` de la Graph API (M08 para
 * WhatsApp): encabezado, cuerpo con variables {{1}}…{{n}} y sus ejemplos, pie y
 * botones. Un botón URL «con seguimiento» apunta a `{links_url}/c/{{1}}`: al
 * enviar, el parámetro es el código del enlace rastreado (LinkRewriter).
 */
final class WaTemplateBuilder
{
    /** Palabras que delatan marketing en una plantilla de utilidad (cumplimiento.md §5.1). */
    public const MARKETING_WORDS = ['descuento', 'oferta', 'promo', 'promoción', 'cupón', 'cupon', 'gratis', 'rebaja', 'aprovecha', 'compra ya', 'compra ahora', 'black friday', 'liquidación', '%', 'últimas unidades', '2x1', 'novedades', 'lanzamiento'];

    /**
     * @param  array<string, mixed>  $data  header, body, footer, buttons
     * @return list<array<string, mixed>>
     */
    public static function components(array $data): array
    {
        $components = [];
        $header = (array) ($data['header'] ?? []);
        $format = strtoupper((string) ($header['format'] ?? 'NONE'));
        if ($format === 'TEXT' && trim((string) ($header['text'] ?? '')) !== '') {
            $text = trim((string) $header['text']);
            $component = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $text];
            if (self::variables($text) !== []) {
                $component['example'] = ['header_text' => [(string) ($header['example'] ?? 'Ana')]];
            }
            $components[] = $component;
        } elseif (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            // [verificar] Meta pide un handle de carga reanudable; la URL de ejemplo sirve para la vista previa.
            $components[] = ['type' => 'HEADER', 'format' => $format, 'example' => ['header_handle' => [(string) ($header['example'] ?? '')]]];
        }

        $body = trim((string) ($data['body']['text'] ?? ''));
        $vars = self::variables($body);
        $examples = array_values(array_map('strval', (array) ($data['body']['examples'] ?? [])));
        if ($vars !== [] && $vars !== range(1, count($vars))) {
            throw ValidationException::withMessages(['body.text' => __('Las variables deben ir en orden: {{1}}, {{2}}, {{3}}…')]);
        }
        if (count($examples) < count($vars) || in_array('', array_slice($examples, 0, count($vars)), true)) {
            throw ValidationException::withMessages(['body.examples' => __('Escribe un ejemplo para cada variable: Meta lo pide para revisar la plantilla.')]);
        }
        $component = ['type' => 'BODY', 'text' => $body];
        if ($vars !== []) {
            $component['example'] = ['body_text' => [array_slice($examples, 0, count($vars))]];
        }
        $components[] = $component;

        if (trim((string) ($data['footer']['text'] ?? '')) !== '') {
            $components[] = ['type' => 'FOOTER', 'text' => trim((string) $data['footer']['text'])];
        }

        $buttons = [];
        foreach ((array) ($data['buttons'] ?? []) as $button) {
            $type = strtoupper((string) ($button['type'] ?? ''));
            $text = trim((string) ($button['text'] ?? ''));
            $buttons[] = match ($type) {
                'URL' => ! empty($button['tracked'])
                    ? ['type' => 'URL', 'text' => $text, 'url' => ContactLinkToken::base().'/c/{{1}}', 'example' => [ContactLinkToken::base().'/c/Ab3dEf7h']]
                    : ['type' => 'URL', 'text' => $text, 'url' => (string) ($button['url'] ?? '')],
                'QUICK_REPLY' => ['type' => 'QUICK_REPLY', 'text' => $text],
                'PHONE_NUMBER' => ['type' => 'PHONE_NUMBER', 'text' => $text, 'phone_number' => (string) ($button['phone'] ?? '')],
                'COPY_CODE' => ['type' => 'COPY_CODE', 'example' => (string) ($button['example'] ?? 'CUPON10')],
                default => throw ValidationException::withMessages(['buttons' => __('Tipo de botón no válido.')]),
            };
        }
        if ($buttons !== []) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return $components;
    }

    /** @return list<int> números de las variables {{n}} en orden de aparición, sin repetir */
    public static function variables(string $text): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $m);
        $vars = array_values(array_unique(array_map('intval', $m[1])));
        sort($vars);

        return $vars;
    }

    /**
     * Palabras de marketing en una plantilla que no es de marketing.
     *
     * @param  list<array<string, mixed>>  $components
     * @return list<string>
     */
    public static function marketingWords(string $category, array $components): array
    {
        if ($category === 'MARKETING') {
            return [];
        }
        $text = '';
        foreach ($components as $c) {
            $text .= ' '.mb_strtolower((string) ($c['text'] ?? ''));
        }

        return array_values(array_filter(self::MARKETING_WORDS, fn (string $w) => str_contains($text, $w)));
    }
}
