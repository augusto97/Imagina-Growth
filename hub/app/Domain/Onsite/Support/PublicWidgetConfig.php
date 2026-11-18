<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Support;

use App\Domain\Consent\Actions\ConsentTexts;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Lo que publica `widgets.json` de cada widget (api-connector.md §8.3): el mismo para
 * todos los visitantes, sin probabilidades de la ruleta, sin reglas del quiz ni la
 * configuración del cupón. Las casillas de consentimiento llevan el texto legal
 * vigente y su versión (cumplimiento.md §3.1).
 */
final class PublicWidgetConfig
{
    public function __construct(private readonly ConsentTexts $texts) {}

    /**
     * Widgets activos del sitio para ese tipo de página y ruta, por prioridad.
     *
     * @return Collection<int, OnsiteWidget>
     */
    public function live(Site $site, ?string $pageType = null, ?string $path = null): Collection
    {
        $now = now();

        return OnsiteWidget::query()->with('quiz')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $site->id))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('priority')->orderBy('created_at')->limit(50)->get()
            ->filter(fn (OnsiteWidget $w) => self::matchesPage($w, $pageType, $path))
            ->values();
    }

    /** El checkout nunca muestra widgets; los tipos de página y las rutas se filtran aquí y otra vez en el navegador. */
    public static function matchesPage(OnsiteWidget $widget, ?string $pageType, ?string $path): bool
    {
        if ($pageType === 'checkout') {
            return false;
        }
        $t = $widget->targeting ?? [];
        $types = (array) ($t['page_types'] ?? []);
        if ($pageType !== null && $pageType !== '' && $types !== [] && ! in_array($pageType, $types, true)) {
            return false;
        }
        if ($path === null || $path === '') {
            return true;
        }
        foreach ((array) ($t['pages']['exclude'] ?? []) as $pattern) {
            if (is_string($pattern) && self::pathMatches($pattern, $path)) {
                return false;
            }
        }
        $include = (array) ($t['pages']['include'] ?? []);
        if ($include === []) {
            return true;
        }
        foreach ($include as $pattern) {
            if (is_string($pattern) && self::pathMatches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /** `/tienda/*` cubre `/tienda/` y todo lo de abajo; sin `*`, la ruta exacta (con o sin `/` final). */
    public static function pathMatches(string $pattern, string $path): bool
    {
        $path = '/'.ltrim((string) (parse_url($path, PHP_URL_PATH) ?? $path), '/');
        $regex = '#^'.str_replace('\*', '.*', preg_quote(rtrim($pattern, '/'), '#')).'/?$#i';

        return preg_match($regex, $path) === 1 || ($pattern === '/' && $path === '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(Workspace $workspace, OnsiteWidget $widget): array
    {
        $consents = $this->consents($workspace, $widget);
        $variants = array_map(fn (array $v) => [
            'key' => $v['key'],
            'weight' => $v['weight'],
            'design' => self::design($widget, $v['design']),
            'content' => $this->content($widget, $v['content'], $consents),
        ], WidgetSchema::variants($widget));
        $targeting = $widget->targeting ?? WidgetSchema::defaults($widget->kind)['targeting'];
        $targeting['utm'] = (object) ($targeting['utm'] ?? []);
        $targeting['segments'] = array_values((array) ($targeting['segments'] ?? []));

        $entry = [
            'id' => $widget->publicId(),
            'kind' => $widget->kind,
            'slot' => $widget->slot(),
            'priority' => $widget->priority,
            'variants' => $variants,
            'targeting' => $targeting,
            'design' => $variants[0]['design'],
            'content' => $variants[0]['content'],
            'gives_coupon' => WidgetSchema::givesCoupon($widget),
        ];
        if ($widget->kind === 'quiz') {
            $entry['quiz'] = ['questions' => array_map(fn (array $q) => [
                'id' => (string) $q['id'],
                'text' => (string) ($q['text'] ?? ''),
                'options' => array_map(fn (array $o) => array_filter([
                    'key' => (string) $o['key'], 'label' => (string) ($o['label'] ?? ''), 'next' => $o['next'] ?? null,
                ], fn ($v) => $v !== null), (array) ($q['options'] ?? [])),
            ], (array) ($widget->quiz->questions ?? []))];
        }
        if ($widget->kind === 'wa_widget') {
            $wa = (array) (WidgetSchema::variants($widget)[0]['content']['wa'] ?? []);
            $entry['wa'] = ['number' => (string) ($wa['number'] ?? ''), 'text' => (string) ($wa['text'] ?? ''), 'position' => (string) ($variants[0]['design']['position'] ?? 'right')];
        }

        return $entry;
    }

    /**
     * Diseño como lo lee el paquete de widgets del tracker: `layout` es dónde va un popup
     * (centro, esquina o pantalla completa), `position` el lado o borde (barra, teaser,
     * WhatsApp), los colores planos (`bg`, `text`, `accent`, `accent_text`), `image` y
     * `image_layout` (solo texto, imagen arriba o a la izquierda).
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    public static function design(OnsiteWidget $widget, array $design): array
    {
        $theme = (array) ($design['theme'] ?? []);
        $position = (string) ($design['position'] ?? '');
        $overlay = in_array($position, ['center', 'corner', 'fullscreen'], true);

        return [
            'layout' => $overlay ? $position : null,
            'position' => $overlay ? ($position === 'corner' ? 'right' : null) : ($position !== '' ? $position : null),
            'display' => $widget->kind === 'quiz' ? ($design['display'] ?? 'popup') : null,
            'bg' => (string) ($theme['background'] ?? '#FFFFFF'),
            'text' => (string) ($theme['text'] ?? '#111827'),
            'accent' => (string) ($theme['accent'] ?? '#4F46E5'),
            'accent_text' => (string) ($theme['accent_text'] ?? '#FFFFFF'),
            'image' => $design['image_url'] ?? null,
            'image_layout' => (string) ($design['layout'] ?? 'text_only'),
        ];
    }

    /**
     * Textos legales vigentes de cada canal, para los formularios que no son widgets
     * (p. ej. «avísame» de M16): `[{channel, text, version}]`.
     *
     * @return list<array{channel: string, text: string, version: string}>
     */
    public function legalConsents(Workspace $workspace): array
    {
        $out = array_map(fn (array $t) => ['channel' => $t['channel'], 'text' => $t['label'], 'version' => $t['version']], $this->texts->current($workspace));
        $sms = $this->texts->latest($workspace, 'sms');
        if ($sms !== null) {
            $out[] = ['channel' => 'sms', 'text' => $sms->body, 'version' => $sms->version];
        }

        return $out;
    }

    /**
     * Las casillas del widget con el texto legal vigente de cada canal.
     *
     * @return list<array{channel: string, label: string, version: string}>
     */
    public function consents(Workspace $workspace, OnsiteWidget $widget): array
    {
        $channels = (array) ($widget->consent_config['channels'] ?? []);
        if ($channels === []) {
            return [];
        }
        $current = collect($this->texts->current($workspace))->keyBy('channel');
        $out = [];
        foreach ($channels as $channel) {
            $text = $current[$channel] ?? null;
            if ($text === null && is_string($channel)) {
                $latest = $this->texts->latest($workspace, $channel);
                $text = $latest !== null ? ['channel' => $channel, 'label' => $latest->body, 'version' => $latest->version] : null;
            }
            if (is_array($text)) {
                $out[] = ['channel' => (string) $text['channel'], 'label' => (string) $text['label'], 'version' => (string) $text['version']];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  list<array{channel: string, label: string, version: string}>  $consents
     * @return array<string, mixed>
     */
    private function content(OnsiteWidget $widget, array $content, array $consents): array
    {
        $content['consents'] = $consents;
        if ($widget->kind === 'two_step' && is_array($content['step2'] ?? null)) {
            // Pasos del formulario como los recorre el tracker: los mismos campos y textos de cada paso.
            $step2 = (array) $content['step2'];
            $content['no'] = (string) ($step2['skip'] ?? '');
            $content['steps'] = [
                ['title' => $content['title'] ?? '', 'body' => $content['body'] ?? '', 'fields' => $content['fields'] ?? [], 'cta' => $content['cta'] ?? '', 'success' => $content['success'] ?? ''],
                ['title' => $step2['title'] ?? '', 'body' => $step2['body'] ?? '', 'fields' => $step2['fields'] ?? [], 'cta' => $step2['cta'] ?? '', 'success' => $step2['success'] ?? '', 'no' => $step2['skip'] ?? ''],
            ];
        }
        if ($widget->kind === 'spin_wheel') {
            // Solo las etiquetas: las probabilidades y los cupones quedan en el hub.
            $content['slices'] = array_map(fn (array $p) => ['key' => (string) $p['key'], 'label' => (string) $p['label']], (array) ($widget->coupon_config['prizes'] ?? []));
        }
        unset($content['wa']);

        return $content;
    }
}
