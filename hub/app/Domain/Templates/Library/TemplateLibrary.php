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
 *
 * Las de WhatsApp (`channel: whatsapp`, flujos 21–35 y galería) traen el texto
 * propuesto para Meta: al instalarse quedan en borrador con el texto y el mapeo
 * de variables (WhatsAppLibrary) hasta enlazarse con la plantilla aprobada
 * (InstallLibraryTemplate las envía a revisión si hay un número oficial).
 *
 * La **galería** (rediseño v2 §11.5) es la misma biblioteca filtrada por
 * objetivo (`goal`) y vertical (`verticals`, `all` = todos).
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

    /** Objetivos de la galería (rediseño v2 §11.5) y el de los flujos de seguimiento. */
    public const GOALS = ['welcome', 'promotion', 'news', 'reminder', 'event_invite', 'seasonal', 'survey', 'review', 'follow_up'];

    /**
     * Catálogo para la SPA, con la copia de la industria.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(?string $industry = null): array
    {
        return array_map(fn (array $entry): array => self::present($entry, $industry), self::manifest()['templates']);
    }

    /**
     * La galería: las plantillas con objetivo, filtradas por objetivo, vertical y canal.
     *
     * @return list<array<string, mixed>>
     */
    public static function gallery(?string $goal = null, ?string $vertical = null, ?string $channel = null, ?string $industry = null): array
    {
        $out = [];
        foreach (self::manifest()['templates'] as $entry) {
            $verticals = array_values(array_map('strval', (array) ($entry['verticals'] ?? [])));
            if (! is_string($entry['goal'] ?? null) || ($goal !== null && $entry['goal'] !== $goal) || ($channel !== null && $entry['channel'] !== $channel)) {
                continue;
            }
            if ($vertical !== null && ! in_array('all', $verticals, true) && ! in_array($vertical, $verticals, true)) {
                continue;
            }
            $out[] = self::present($entry, $industry);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function present(array $entry, ?string $industry): array
    {
        $copy = self::copy($entry, $industry);
        $wa = $entry['channel'] === 'whatsapp' ? WhatsAppLibrary::compile($entry, (string) ($copy['text'] ?? '')) : null;

        return [
            'key' => $entry['key'],
            'name' => $entry['name'],
            'flow' => $entry['flow'],
            'channel' => $entry['channel'],
            'category' => $entry['category'],
            'description' => $entry['description'] ?? '',
            'goal' => $entry['goal'] ?? null,
            'verticals' => array_values(array_map('strval', (array) ($entry['verticals'] ?? []))),
            'subject' => $copy['subject'] ?? null,
            'preheader' => $copy['preheader'] ?? null,
            'text' => $copy['text'] ?? null,
            'whatsapp' => $wa === null ? null : ['name' => $wa['name'], 'category' => strtolower($wa['category']), 'body' => $wa['body'], 'footer' => $wa['footer'], 'buttons' => $wa['buttons']],
            'has_industry_copy' => $industry !== null && isset($entry['copy'][$industry]),
        ];
    }

    /**
     * Atributos de la plantilla ya con la copia de la industria (sin guardar).
     * `$copyOverride` reemplaza textos de la copia (asunto, titular, introducción…;
     * lo usa el copiloto de la Fase 4); los vacíos se ignoran.
     *
     * @param  array<string, string|null>  $copyOverride
     * @return array<string, mixed>
     */
    public static function attributes(string $key, ?string $industry = null, array $copyOverride = []): array
    {
        $entry = self::entry($key) ?? throw new InvalidArgumentException(__('La plantilla «:key» no existe en la biblioteca.', ['key' => $key]));
        $copy = array_replace(self::copy($entry, $industry), array_map('strval', array_filter($copyOverride, fn ($v) => is_string($v) && trim($v) !== '')));
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
        } elseif ($entry['channel'] === 'whatsapp') {
            // Borrador hasta enlazarla con la aprobada en Meta: el texto es el propuesto y el mapeo llena sus parámetros.
            $attributes['body_text'] = $copy['text'] ?? '';
            $attributes['variables'] = WhatsAppLibrary::compile($entry, (string) ($copy['text'] ?? ''))['variables'];
            $attributes['status'] = 'draft';
        } else {
            $attributes['body_text'] = $copy['text'] ?? '';
        }

        return $attributes;
    }

    /**
     * Instala la plantilla en el espacio de trabajo actual. Idempotente salvo con
     * `copy` (el usuario pidió otra copia desde la biblioteca).
     */
    /**
     * @param  array<string, string|null>  $copyOverride  textos propios (p. ej. los de un momento del calendario); con ellos siempre es una copia nueva
     * @param  string|null  $name  nombre de la copia
     */
    public static function install(Workspace $workspace, string $key, ?string $industry = null, ?string $createdBy = null, bool $copy = false, array $copyOverride = [], ?string $name = null): Template
    {
        return app(CurrentWorkspace::class)->run($workspace, function () use ($workspace, $key, $industry, $createdBy, $copy, $copyOverride, $name): Template {
            if (! $copy && $copyOverride === []) {
                $existing = Template::query()->where('library_key', $key)->where('status', '!=', 'archived')->orderBy('created_at')->orderBy('id')->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
            $industry ??= is_string($workspace->getAttribute('industry')) ? $workspace->getAttribute('industry') : null;
            $attributes = self::attributes($key, $industry, $copyOverride);
            if ($name !== null && trim($name) !== '') {
                $attributes['name'] = mb_substr(trim($name), 0, 160);
            }

            return Template::query()->create(['workspace_id' => $workspace->id, 'created_by' => $createdBy] + $attributes);
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
