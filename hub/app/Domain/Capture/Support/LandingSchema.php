<?php

declare(strict_types=1);

namespace App\Domain\Capture\Support;

use App\Domain\Capture\Models\LandingPage;
use App\Domain\Onsite\Support\PublicWidgetConfig;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Forma de una página de captura (modulos.md M12 «Páginas de captura y QR»): valores por defecto, validación del
 * contenido, los campos y las casillas, y el slug (único en toda la plataforma).
 *
 * - `content`: `title`, `body` (texto plano con saltos de línea), `logo_key`/`image_key` (imágenes subidas) o
 *   `logo_url`/`image_url` (https), `color` (#RRGGBB), `button`, `success_title`, `success_message`, `redirect_url`
 *   (https) y `whatsapp` (`enabled`, `label`, `message`).
 * - `fields`: hasta `max_fields` de `name`, `email`, `phone`, `message`, `service` (con `options`) y propios
 *   `custom_<clave>` de tipo `text`, `textarea`, `select` o `checkbox`. Debe haber correo o teléfono.
 * - `consent_channels`: los canales con casilla (sin marcar, con el texto legal vigente de ConsentTexts); cada uno
 *   exige su campo (correo para `email`, teléfono para `whatsapp` y `sms`).
 * - `settings`: `indexable` (falso por defecto: la página lleva noindex).
 */
final class LandingSchema
{
    /** Campos propios del producto con su tipo. */
    public const BUILTIN = ['name' => 'text', 'email' => 'email', 'phone' => 'tel', 'message' => 'textarea', 'service' => 'select'];

    public const CUSTOM_TYPES = ['text', 'textarea', 'select', 'checkbox'];

    /** @return array{content: array<string, mixed>, fields: list<array<string, mixed>>, consent_channels: list<string>, settings: array<string, mixed>} */
    public static function defaults(Workspace $workspace, bool $hasWhatsApp): array
    {
        $business = BusinessProfile::for($workspace)['name'] ?: $workspace->name;

        return [
            'content' => [
                'title' => __('Te ayudamos hoy mismo'),
                'body' => __('Déjanos tus datos y una persona de :business te escribe por WhatsApp para resolver tus dudas.', ['business' => $business]),
                'button' => __('Quiero que me contacten'),
                'success_title' => __('¡Gracias! Recibimos tus datos'),
                'success_message' => __('Te escribiremos muy pronto. Si es urgente, escríbenos por WhatsApp.'),
                'redirect_url' => null,
                'color' => null,
                'logo_key' => null,
                'logo_url' => null,
                'image_key' => null,
                'image_url' => null,
                'whatsapp' => ['enabled' => $hasWhatsApp, 'label' => __('Escríbenos por WhatsApp'), 'message' => __('Hola, quiero más información')],
            ],
            'fields' => [
                ['key' => 'name', 'label' => __('Nombre'), 'type' => 'text', 'required' => true],
                ['key' => 'phone', 'label' => __('WhatsApp'), 'type' => 'tel', 'required' => true],
                ['key' => 'email', 'label' => __('Correo'), 'type' => 'email', 'required' => false],
                ['key' => 'message', 'label' => __('¿En qué te podemos ayudar?'), 'type' => 'textarea', 'required' => false],
            ],
            'consent_channels' => ['whatsapp', 'email'],
            'settings' => ['indexable' => false],
        ];
    }

    /** «Clínica Dental Sonrisa» → «clinica-dental-sonrisa». */
    public static function slugify(string $text): string
    {
        $slug = Str::slug(Str::ascii($text), '-', 'es');
        $slug = trim(mb_substr($slug, 0, 70), '-');

        return strlen($slug) >= 3 ? $slug : ($slug !== '' ? $slug.'-'.strtolower(Str::random(4)) : 'pagina-'.strtolower(Str::random(6)));
    }

    /** Un slug libre a partir de `$base` (agrega -2, -3… o un sufijo al azar). */
    public static function uniqueSlug(string $base, ?string $ignoreId = null): string
    {
        $base = self::slugify($base);
        for ($i = 1; $i <= 20; $i++) {
            $candidate = $i === 1 ? $base : mb_substr($base, 0, 74).'-'.$i;
            if (self::slugProblem($candidate, $ignoreId) === null) {
                return $candidate;
            }
        }

        return mb_substr($base, 0, 70).'-'.strtolower(Str::random(6));
    }

    public static function slugProblem(string $slug, ?string $ignoreId = null): ?string
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{1,78})[a-z0-9]$/', $slug) !== 1 || str_contains($slug, '--')) {
            return (string) __('Usa de 3 a 80 letras minúsculas, números o guiones (sin tildes ni espacios).');
        }
        if (in_array($slug, (array) config('igr-capture.reserved_slugs'), true)) {
            return (string) __('Esa dirección está reservada. Elige otra.');
        }
        $taken = LandingPage::withoutWorkspaceScope()->where('slug', $slug)->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->exists();

        return $taken ? (string) __('Esa dirección ya está en uso. Elige otra.') : null;
    }

    /**
     * Valida y normaliza lo que llega del panel (solo las claves presentes).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> columnas para guardar
     *
     * @throws ValidationException
     */
    public static function validate(Workspace $workspace, LandingPage $page, array $input): array
    {
        $rules = [
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'slug' => ['sometimes', 'string', 'max:80'],
            'content' => ['sometimes', 'array'],
            'content.title' => ['sometimes', 'required', 'string', 'max:120'],
            'content.body' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'content.button' => ['sometimes', 'nullable', 'string', 'max:40'],
            'content.success_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'content.success_message' => ['sometimes', 'nullable', 'string', 'max:600'],
            'content.redirect_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'content.logo_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'content.image_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'content.color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'content.whatsapp' => ['sometimes', 'array'],
            'content.whatsapp.enabled' => ['sometimes', 'boolean'],
            'content.whatsapp.label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'content.whatsapp.message' => ['sometimes', 'nullable', 'string', 'max:300'],
            'fields' => ['sometimes', 'array', 'min:1', 'max:'.(int) config('igr-capture.max_fields', 12)],
            'fields.*' => ['array'],
            'fields.*.key' => ['required', 'string', 'regex:/^(name|email|phone|message|service|custom_[a-z0-9_]{1,30})$/'],
            'fields.*.label' => ['required', 'string', 'max:80'],
            'fields.*.type' => ['sometimes', 'nullable', 'string'],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.placeholder' => ['sometimes', 'nullable', 'string', 'max:80'],
            'fields.*.options' => ['sometimes', 'nullable', 'array', 'max:20'],
            'fields.*.options.*' => ['string', 'max:80'],
            'consent_channels' => ['sometimes', 'array'],
            'consent_channels.*' => ['string'],
            'settings' => ['sometimes', 'array'],
            'settings.indexable' => ['sometimes', 'boolean'],
        ];
        $data = Validator::make($input, $rules, [], [
            'content.title' => __('título'), 'content.redirect_url' => __('dirección de redirección'), 'content.color' => __('color'),
            'content.logo_url' => __('logo'), 'content.image_url' => __('imagen'),
        ])->validate();

        $out = [];
        if (array_key_exists('name', $data)) {
            $out['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('slug', $data)) {
            $slug = strtolower(trim((string) $data['slug']));
            if (($problem = self::slugProblem($slug, $page->id)) !== null) {
                throw ValidationException::withMessages(['slug' => $problem]);
            }
            $out['slug'] = $slug;
        }
        if (array_key_exists('content', $data)) {
            $out['content'] = self::content((array) ($page->content ?? []), (array) $data['content']);
        }
        $fields = array_key_exists('fields', $data) ? self::fields((array) $data['fields']) : (array) ($page->fields ?? []);
        if (array_key_exists('fields', $data)) {
            $out['fields'] = $fields;
        }
        if (array_key_exists('consent_channels', $data) || array_key_exists('fields', $data)) {
            $out['consent_channels'] = self::consents($workspace, $fields, array_key_exists('consent_channels', $data) ? (array) $data['consent_channels'] : (array) ($page->consent_channels ?? []));
        }
        if (array_key_exists('settings', $data)) {
            $out['settings'] = array_replace((array) ($page->settings ?? []), ['indexable' => (bool) ($data['settings']['indexable'] ?? ($page->settings['indexable'] ?? false))]);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $given
     * @return array<string, mixed>
     */
    private static function content(array $current, array $given): array
    {
        $clean = fn (mixed $v, int $max): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim(strip_tags($v)), 0, $max) : null;
        $out = $current;
        foreach (['title' => 120, 'body' => 2000, 'button' => 40, 'success_title' => 120, 'success_message' => 600] as $key => $max) {
            if (array_key_exists($key, $given)) {
                $out[$key] = $clean($given[$key], $max);
            }
        }
        foreach (['redirect_url', 'logo_url', 'image_url', 'color'] as $key) {
            if (array_key_exists($key, $given)) {
                $out[$key] = is_string($given[$key]) && $given[$key] !== '' ? $given[$key] : null;
            }
        }
        if (isset($given['whatsapp']) && is_array($given['whatsapp'])) {
            $wa = (array) ($current['whatsapp'] ?? []);
            if (array_key_exists('enabled', $given['whatsapp'])) {
                $wa['enabled'] = (bool) $given['whatsapp']['enabled'];
            }
            foreach (['label' => 40, 'message' => 300] as $key => $max) {
                if (array_key_exists($key, $given['whatsapp'])) {
                    $wa[$key] = $clean($given['whatsapp'][$key], $max);
                }
            }
            $out['whatsapp'] = $wa;
        }
        if (($out['title'] ?? null) === null) {
            throw ValidationException::withMessages(['content.title' => __('Escribe el título de la página.')]);
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $given
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private static function fields(array $given): array
    {
        $out = [];
        $seen = [];
        foreach (array_values($given) as $i => $field) {
            $key = (string) $field['key'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["fields.{$i}.key" => __('El campo «:key» está repetido.', ['key' => $key])]);
            }
            $seen[$key] = true;
            $type = self::BUILTIN[$key] ?? (string) ($field['type'] ?? 'text');
            if (! isset(self::BUILTIN[$key]) && ! in_array($type, self::CUSTOM_TYPES, true)) {
                throw ValidationException::withMessages(["fields.{$i}.type" => __('Tipo de campo desconocido.')]);
            }
            $options = array_values(array_unique(array_filter(array_map(fn ($o) => trim(strip_tags((string) $o)), (array) ($field['options'] ?? [])), fn ($o) => $o !== '')));
            if ($type === 'select' && $options === [] && $key !== 'service') {
                throw ValidationException::withMessages(["fields.{$i}.options" => __('Agrega las opciones de la lista.')]);
            }
            $out[] = array_filter([
                'key' => $key,
                'label' => mb_substr(trim(strip_tags((string) $field['label'])), 0, 80),
                'type' => $key === 'service' && $options === [] ? 'text' : $type,
                'required' => (bool) ($field['required'] ?? false),
                'placeholder' => isset($field['placeholder']) && is_string($field['placeholder']) && trim($field['placeholder']) !== '' ? mb_substr(trim($field['placeholder']), 0, 80) : null,
                'options' => in_array($type, ['select'], true) && $options !== [] ? $options : null,
            ], fn ($v) => $v !== null);
        }
        if (! isset($seen['email']) && ! isset($seen['phone'])) {
            throw ValidationException::withMessages(['fields' => __('La página necesita el campo de correo o el de WhatsApp para poder responderle a la persona.')]);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<int, mixed>  $given
     * @return list<string>
     *
     * @throws ValidationException
     */
    private static function consents(Workspace $workspace, array $fields, array $given): array
    {
        $offered = array_column(app(PublicWidgetConfig::class)->legalConsents($workspace), 'channel');
        $keys = array_column($fields, 'key');
        $out = [];
        foreach (array_values(array_unique(array_map('strval', $given))) as $channel) {
            if (! in_array($channel, $offered, true)) {
                throw ValidationException::withMessages(['consent_channels' => __('El canal «:channel» no tiene texto legal publicado.', ['channel' => $channel])]);
            }
            $needs = $channel === 'email' ? 'email' : 'phone';
            if (in_array($needs, $keys, true)) {
                $out[] = $channel; // sin su campo, la casilla no tendría a dónde escribir: se quita
            }
        }

        return $out;
    }
}
