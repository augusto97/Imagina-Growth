<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Attribution;

use App\Ingest\Enrichment\ChannelClassifier;

/**
 * Canal a partir de la atribución nativa de WooCommerce (_wc_order_attribution_*),
 * para los pedidos sin datos del tracker (la importación de 24 meses).
 * source_type: utm | organic | referral | typein | admin | mobile_app | unknown.
 */
final class WcAttributionChannel
{
    public function __construct(private readonly ChannelClassifier $classifier) {}

    /** @param array<string, mixed>|null $wc */
    public function touch(?array $wc, ?string $siteHost, ?string $at): ?Touch
    {
        if ($wc === null || $wc === []) {
            return null;
        }
        $type = strtolower((string) ($wc['source_type'] ?? ''));
        $source = strtolower(trim((string) ($wc['utm_source'] ?? '')));
        $medium = strtolower(trim((string) ($wc['utm_medium'] ?? '')));
        $campaign = trim((string) ($wc['utm_campaign'] ?? ''));
        $host = $source !== '' && $source !== '(direct)' ? (str_contains($source, '.') ? $source : $source.'.com') : null;

        $channel = match ($type) {
            'utm' => $this->classifier->classify(['utm' => ['source' => $source, 'medium' => $medium, 'campaign' => $campaign], 'site_host' => $siteHost]),
            'organic' => $host !== null && $this->classifier->classify(['referrer' => 'https://'.$host, 'site_host' => $siteHost]) === ChannelClassifier::AI_ASSISTANTS
                ? ChannelClassifier::AI_ASSISTANTS : ChannelClassifier::ORGANIC_SEARCH,
            'referral' => $host !== null ? $this->classifier->classify(['referrer' => 'https://'.$host, 'site_host' => $siteHost]) : ChannelClassifier::REFERRAL,
            'typein' => ChannelClassifier::DIRECT,
            default => null, // admin, mobile_app, unknown: sin canal de marketing
        };

        return $channel === null ? null : new Touch(
            $channel,
            $source !== '' && $source !== '(direct)' ? $source : null,
            $medium !== '' && $medium !== '(none)' ? $medium : null,
            $campaign !== '' && $campaign !== '(none)' ? $campaign : null,
            $at,
            'wc_attribution',
        );
    }
}
