<?php

declare(strict_types=1);

namespace App\Domain\Templates\Library;

use App\Domain\Messaging\Support\WaTemplateBuilder;
use App\Domain\Templates\Liquid\LiquidSandbox;
use App\Domain\Templates\Rendering\SampleData;
use Throwable;

/**
 * Plantillas de WhatsApp de la biblioteca (M08, flujos 21–35 y galería). Cada entrada de library.json con
 * `channel: whatsapp` trae su texto con variables Liquid (`copy.<industria>.text`) y, en `whatsapp`, el nombre
 * en Meta (`igr_<clave>`), el pie y los botones. De ahí sale lo que se envía a revisión de Meta: el cuerpo con
 * `{{1}}`, `{{2}}`… (una por expresión, en orden de aparición), los ejemplos que pide Meta (la expresión con
 * los datos de ejemplo) y el mapeo `templates.variables` que llena cada parámetro al enviar (los botones de
 * respuesta rápida con su payload fijo y los de URL rastreada con su enlace).
 *
 * La categoría (marketing o utilidad) es la de la entrada: una de utilidad no puede tener palabras de
 * marketing (cumplimiento.md §5.1), lo revisan las pruebas de la biblioteca.
 */
final class WhatsAppLibrary
{
    /**
     * @param  array<string, mixed>  $entry
     * @return array{name: string, category: string, body: string, examples: list<string>, footer: string|null,
     *     buttons: list<array<string, mixed>>, variables: array{body: array<int, string>, buttons: array<int, string>}}
     */
    public static function compile(array $entry, string $text): array
    {
        $numbers = [];
        $mapping = [];
        $body = (string) preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/', function (array $m) use (&$numbers, &$mapping): string {
            $expr = '{{ '.trim($m[1]).' }}';
            if (! isset($numbers[$expr])) {
                $numbers[$expr] = count($numbers) + 1;
                $mapping[(string) $numbers[$expr]] = $expr;
            }

            return '{{'.$numbers[$expr].'}}';
        }, $text);
        $spec = (array) ($entry['whatsapp'] ?? []);
        $buttons = [];
        $buttonMap = [];
        foreach (array_values((array) ($spec['buttons'] ?? [])) as $i => $button) {
            $button = (array) $button;
            $type = strtoupper((string) ($button['type'] ?? 'QUICK_REPLY'));
            $buttons[] = array_filter(['type' => $type, 'text' => (string) ($button['text'] ?? ''), 'tracked' => $type === 'URL' ? true : null], fn ($v) => $v !== null);
            if ($type === 'QUICK_REPLY' && is_string($button['payload'] ?? null)) {
                $buttonMap[(string) $i] = $button['payload'];
            } elseif ($type === 'URL' && is_string($button['value'] ?? null)) {
                $buttonMap[(string) $i] = $button['value'];
            }
        }

        return [
            'name' => (string) ($spec['name'] ?? 'igr_'.preg_replace('/^wa_/', '', (string) $entry['key'])),
            'category' => strtoupper((string) ($entry['category'] ?? 'marketing')) === 'MARKETING' ? 'MARKETING' : 'UTILITY',
            'body' => $body,
            'examples' => array_values(array_map(fn (string $expr) => self::example($expr), $mapping)),
            'footer' => is_string($spec['footer'] ?? null) ? $spec['footer'] : null,
            'buttons' => $buttons,
            'variables' => ['body' => $mapping, 'buttons' => $buttonMap],
        ];
    }

    /**
     * Componentes de la Graph API listos para `message_templates` (WaTemplateBuilder: valida el orden de las
     * variables y que cada una tenga ejemplo).
     *
     * @param  array{body: string, examples: list<string>, footer: string|null, buttons: list<array<string, mixed>>}  $compiled
     * @return list<array<string, mixed>>
     */
    public static function components(array $compiled): array
    {
        return WaTemplateBuilder::components([
            'body' => ['text' => $compiled['body'], 'examples' => $compiled['examples']],
            'footer' => ['text' => $compiled['footer']],
            'buttons' => $compiled['buttons'],
        ]);
    }

    /** Idioma de la plantilla en Meta según el país del negocio. */
    public static function language(?string $country): string
    {
        return strtoupper((string) $country) === 'MX' ? 'es_MX' : 'es';
    }

    /** Ejemplo de una expresión con los datos de ejemplo (Meta lo pide para revisar la plantilla). */
    private static function example(string $expr): string
    {
        $place = SampleData::country('CO');
        $data = SampleData::vars() + ['tienda' => ['nombre' => $place['negocio'], 'url' => $place['url'], 'whatsapp_url' => 'https://wa.me/'.$place['whatsapp'], 'moneda' => 'COP']];
        try {
            $value = trim(app(LiquidSandbox::class)->render($expr, $data, ['currency' => 'COP', 'locale' => 'es_CO', 'timezone' => 'America/Bogota'], __('ejemplo')));
        } catch (Throwable) {
            $value = '';
        }

        return $value !== '' ? mb_substr($value, 0, 200) : __('ejemplo');
    }
}
