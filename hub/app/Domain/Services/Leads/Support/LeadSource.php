<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Support;

use App\Ingest\Enrichment\ChannelClassifier;

/**
 * De dónde vino un lead (`leads.source`): canal agrupado (inteligencia.md §2),
 * campaña, página, formulario e identificadores de clic de anuncios (gclid,
 * fbclid…) para la conversión offline. Sin datos personales.
 */
final class LeadSource
{
    private const CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid'];

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, string>
     */
    public static function fromEnvelope(array $envelope, ?string $siteHost = null): array
    {
        $ctx = is_array($envelope['context'] ?? null) ? $envelope['context'] : [];
        $props = is_array($envelope['properties'] ?? null) ? $envelope['properties'] : [];
        $touch = is_array($ctx['last_touch'] ?? null) ? $ctx['last_touch'] : [];
        $utm = array_filter(array_merge(
            is_array($touch['utm'] ?? null) ? $touch['utm'] : [],
            is_array($ctx['utm'] ?? null) ? array_filter($ctx['utm'], fn ($v) => is_string($v) && $v !== '') : [],
        ), fn ($v) => is_string($v) && $v !== '');
        $clicks = array_filter(array_merge(
            is_array($touch['click_ids'] ?? null) ? $touch['click_ids'] : [],
            is_array($ctx['click_ids'] ?? null) ? $ctx['click_ids'] : [],
        ), fn ($v) => is_string($v) && $v !== '');
        $page = is_array($ctx['page'] ?? null) ? $ctx['page'] : [];
        $referrer = is_string($touch['referrer'] ?? null) ? $touch['referrer'] : (is_string($page['referrer'] ?? null) ? $page['referrer'] : null);

        $channel = app(ChannelClassifier::class)->classify([
            'utm' => $utm,
            'click_ids' => $clicks,
            'referrer' => $referrer,
            'site_host' => $siteHost,
            'link_token' => is_string($envelope['identity']['link_token'] ?? null) ? $envelope['identity']['link_token'] : null,
        ]);

        $source = [
            'channel' => $channel,
            'utm_source' => self::cut($utm['source'] ?? null, 120),
            'utm_medium' => self::cut($utm['medium'] ?? null, 120),
            'utm_campaign' => self::cut($utm['campaign'] ?? null, 200),
            'page' => self::cut($props['page_path'] ?? ($page['path'] ?? null), 300),
            'form_id' => isset($props['form_id']) ? self::cut(($props['form_plugin'] ?? 'form').':'.$props['form_id'], 64) : null,
            'form_name' => self::cut($props['form_name'] ?? null, 200),
            'referrer_host' => self::cut($referrer !== null ? (parse_url($referrer, PHP_URL_HOST) ?: null) : null, 120),
        ];
        foreach (self::CLICK_IDS as $key) {
            $source[$key] = self::cut($clicks[$key] ?? null, 255);
        }

        return array_filter($source, fn ($v) => $v !== null && $v !== '');
    }

    private static function cut(mixed $value, int $max): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, $max) : null;
    }
}
