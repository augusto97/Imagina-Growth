<?php

declare(strict_types=1);

namespace App\Domain\Templates\Library;

use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use InvalidArgumentException;

/**
 * Biblioteca de plantillas en español por industria (modulos.md M08). Fuente:
 * library.json + email/<clave>.mjml|html compilados con build.mjs. Los textos van
 * como `[[token]]` y aquí se reemplazan por la copia de la industria (o la
 * general).
 *
 * Los flujos de la biblioteca (M09) las piden por clave con install(): es
 * idempotente (si el espacio de trabajo ya tiene esa plantilla, la devuelve).
 */
final class TemplateLibrary
{
    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    /** @return array{version: int, industries: list<string>, templates: list<array<string, mixed>>} */
    public static function manifest(): array
    {
        if (self::$manifest === null) {
            $decoded = json_decode((string) file_get_contents(__DIR__.'/library.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$manifest = is_array($decoded) ? $decoded : [];
        }

        /** @var array{version: int, industries: list<string>, templates: list<array<string, mixed>>} */
        return self::$manifest;
    }

    /** @return list<string> */
    public static function industries(): array
    {
        return self::manifest()['industries'];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_values(array_map(fn (array $e) => (string) $e['key'], self::manifest()['templates']));
    }

    /** @return array<string, mixed>|null */
    public static function entry(string $key): ?array
    {
        foreach (self::manifest()['templates'] as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Catálogo para la SPA, con la copia de la industria.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(?string $industry = null): array
    {
        return array_map(function (array $entry) use ($industry): array {
            $copy = self::copy($entry, $industry);

            return [
                'key' => $entry['key'],
                'name' => $entry['name'],
                'flow' => $entry['flow'],
                'channel' => $entry['channel'],
                'category' => $entry['category'],
                'description' => $entry['description'] ?? '',
                'subject' => $copy['subject'] ?? null,
                'preheader' => $copy['preheader'] ?? null,
                'text' => $copy['text'] ?? null,
                'has_industry_copy' => $industry !== null && isset($entry['copy'][$industry]),
            ];
        }, self::manifest()['templates']);
    }

    /**
     * Atributos de la plantilla ya con la copia de la industria (sin guardar).
     *
     * @return array<string, mixed>
     */
    public static function attributes(string $key, ?string $industry = null): array
    {
        $entry = self::entry($key) ?? throw new InvalidArgumentException(__('La plantilla «:key» no existe en la biblioteca.', ['key' => $key]));
        $copy = self::copy($entry, $industry);
        $attributes = [
            'channel' => $entry['channel'],
            'name' => $entry['name'],
            'category' => $entry['category'],
            'library_key' => $key,
            'status' => 'ready',
        ];
        if ($entry['channel'] === 'email') {
            $attributes['subject'] = $copy['subject'] ?? '';
            $attributes['preheader'] = $copy['preheader'] ?? '';
            $attributes['body_mjml'] = self::fill((string) file_get_contents(__DIR__.'/email/'.$key.'.mjml'), $copy);
            $attributes['body_html'] = self::fill((string) file_get_contents(__DIR__.'/email/'.$key.'.html'), $copy);
        } else {
            $attributes['body_text'] = $copy['text'] ?? '';
        }

        return $attributes;
    }

    /**
     * Instala la plantilla en el espacio de trabajo actual. Idempotente salvo con
     * `copy` (el usuario pidió otra copia desde la biblioteca).
     */
    public static function install(Workspace $workspace, string $key, ?string $industry = null, ?string $createdBy = null, bool $copy = false): Template
    {
        return app(CurrentWorkspace::class)->run($workspace, function () use ($workspace, $key, $industry, $createdBy, $copy): Template {
            if (! $copy) {
                $existing = Template::query()->where('library_key', $key)->where('status', '!=', 'archived')->orderBy('created_at')->orderBy('id')->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
            $industry ??= is_string($workspace->getAttribute('industry')) ? $workspace->getAttribute('industry') : null;

            return Template::query()->create(['workspace_id' => $workspace->id, 'created_by' => $createdBy] + self::attributes($key, $industry));
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, string>
     */
    private static function copy(array $entry, ?string $industry): array
    {
        $copy = (array) ($entry['copy']['default'] ?? []);
        if ($industry !== null && is_array($entry['copy'][$industry] ?? null)) {
            $copy = array_replace($copy, $entry['copy'][$industry]);
        }

        return array_map(fn ($v) => (string) $v, $copy);
    }

    /** @param  array<string, string>  $copy */
    private static function fill(string $source, array $copy): string
    {
        return (string) preg_replace_callback('/\[\[([a-z_]+)\]\]/', fn (array $m) => htmlspecialchars($copy[$m[1]] ?? '', ENT_NOQUOTES, 'UTF-8', false), $source);
    }
}
