<?php

declare(strict_types=1);

namespace App\Ingest\Enrichment;

/**
 * Agrupación de canales (docs/inteligencia.md §2). Se evalúa en orden y gana
 * la primera regla. Las listas de dominios viven en config/channels.php.
 */
final class ChannelClassifier
{
    public const CTWA = 'ctwa';

    public const IGR_WHATSAPP = 'igr_whatsapp';

    public const IGR_EMAIL = 'igr_email';

    public const IGR_SMS = 'igr_sms';

    public const PAID_META = 'paid_meta';

    public const PAID_GOOGLE = 'paid_google';

    public const PAID_TIKTOK = 'paid_tiktok';

    public const PAID_OTHER = 'paid_other';

    public const EMAIL = 'email';

    public const WHATSAPP = 'whatsapp';

    public const AI_ASSISTANTS = 'ai_assistants';

    public const ORGANIC_SEARCH = 'organic_search';

    public const ORGANIC_SOCIAL = 'organic_social';

    public const AFFILIATES = 'affiliates';

    public const REFERRAL = 'referral';

    public const DIRECT = 'direct';

    /**
     * @param  array{utm?: array<string, string|null>, click_ids?: array<string, string|null>, referrer?: string|null, site_host?: string|null, link_token?: string|null, ctwa_clid?: string|null, has_ad_referral?: bool, referral_code?: string|null}  $touch
     */
    public function classify(array $touch): string
    {
        $utm = array_map(fn ($v) => mb_strtolower(trim((string) $v)), $touch['utm'] ?? []);
        $source = $utm['source'] ?? '';
        $medium = $utm['medium'] ?? '';
        $clicks = array_filter($touch['click_ids'] ?? [], fn ($v) => (string) $v !== '');
        $refHost = self::host($touch['referrer'] ?? null);
        if ($refHost !== null && $refHost === self::stripWww((string) ($touch['site_host'] ?? ''))) {
            $refHost = null; // navegación interna
        }

        /** @var array<string, list<string>> $cfg */
        $cfg = config('channels');
        $paidMediums = $cfg['paid_mediums'];

        // 1. Anuncio de clic a WhatsApp.
        if (! empty($touch['ctwa_clid']) || ! empty($touch['has_ad_referral'])) {
            return self::CTWA;
        }
        // 2. Enlaces de mensajes de Imagina Growth.
        if (! empty($touch['link_token']) || $source === 'igr') {
            return match ($medium) {
                'email' => self::IGR_EMAIL,
                'sms' => self::IGR_SMS,
                default => self::IGR_WHATSAPP,
            };
        }
        $isPaidMedium = in_array($medium, $paidMediums, true);
        // 3. Pago Meta.
        if ((isset($clicks['fbclid']) && $isPaidMedium) || (in_array($source, $cfg['meta_sources'], true) && $isPaidMedium)) {
            return self::PAID_META;
        }
        // 4. Pago Google.
        if (isset($clicks['gclid']) || isset($clicks['gbraid']) || isset($clicks['wbraid']) || ($source === 'google' && $isPaidMedium)) {
            return self::PAID_GOOGLE;
        }
        // 5. Pago TikTok.
        if (isset($clicks['ttclid']) || ($source === 'tiktok' && $isPaidMedium)) {
            return self::PAID_TIKTOK;
        }
        // 6. Otros pagos.
        if (in_array($medium, $cfg['other_paid_mediums'], true) || isset($clicks['msclkid'])) {
            return self::PAID_OTHER;
        }
        // 7. Correo (otros).
        if ($medium === 'email' || ($refHost !== null && self::matches($refHost, $cfg['webmail']))) {
            return self::EMAIL;
        }
        // 8. WhatsApp orgánico.
        if ($source === 'whatsapp' || ($refHost !== null && self::matches($refHost, $cfg['whatsapp']))) {
            return self::WHATSAPP;
        }
        // 9. Asistentes de IA.
        if (in_array($source, $cfg['ai_sources'], true) || ($refHost !== null && self::matches($refHost, $cfg['ai_assistants']))) {
            return self::AI_ASSISTANTS;
        }
        // 10. Búsqueda orgánica (sin click ID).
        if ($refHost !== null && $clicks === [] && self::matchesSearch($refHost, $cfg['search_engines'])) {
            return self::ORGANIC_SEARCH;
        }
        // 11. Social orgánico.
        if (($refHost !== null && self::matches($refHost, $cfg['social'])) || in_array($source, $cfg['social_sources'], true)) {
            return self::ORGANIC_SOCIAL;
        }
        // 12. Afiliados y referidos.
        if (! empty($touch['referral_code']) || in_array($medium, ['affiliate', 'referral_program'], true)) {
            return self::AFFILIATES;
        }
        // 13. Referido (sitios).
        if ($refHost !== null) {
            return self::REFERRAL;
        }
        // Con UTM pero sin otra regla: se trata como referido de la fuente declarada.
        if ($source !== '') {
            return self::REFERRAL;
        }

        // 14. Directo.
        return self::DIRECT;
    }

    public static function host(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? self::stripWww(strtolower($host)) : null;
    }

    private static function stripWww(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** @param list<string> $domains */
    private static function matches(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * google.com, google.com.co, google.es… y los demás buscadores.
     *
     * @param  list<string>  $engines
     */
    private static function matchesSearch(string $host, array $engines): bool
    {
        foreach ($engines as $engine) {
            if (preg_match('/(^|\.)'.preg_quote($engine, '/').'\.[a-z.]{2,10}$/', $host)) {
                return true;
            }
        }

        return false;
    }
}
