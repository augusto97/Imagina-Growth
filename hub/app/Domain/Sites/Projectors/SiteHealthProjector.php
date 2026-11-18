<?php

declare(strict_types=1);

namespace App\Domain\Sites\Projectors;

use App\Domain\Sites\Models\Site;

/**
 * site.heartbeat y site.changed: versiones, estado del outbox y capacidades.
 */
final class SiteHealthProjector
{
    /** @param array<string, mixed> $envelope */
    public function project(Site $site, array $envelope): void
    {
        $p = $envelope['properties'] ?? [];
        if ($envelope['event'] === 'site.heartbeat') {
            $site->forceFill(array_filter([
                'wp_version' => $p['wp_version'] ?? null,
                'wc_version' => $p['wc_version'] ?? null,
                'php_version' => $p['php_version'] ?? null,
                'plugin_version' => $p['plugin_version'] ?? null,
                'capabilities' => is_array($p['capabilities'] ?? null) && $p['capabilities'] !== [] ? $p['capabilities'] : null,
            ], fn ($v) => $v !== null) + [
                'health' => [
                    'outbox_pending' => (int) ($p['outbox_pending'] ?? 0),
                    'outbox_dead' => (int) ($p['outbox_dead'] ?? 0),
                    'oldest_pending_at' => $p['oldest_pending_at'] ?? null,
                    'last_error' => isset($p['last_error']) ? mb_substr((string) $p['last_error'], 0, 500) : null,
                    'heartbeat_at' => $envelope['occurred_at'],
                ],
                'last_seen_at' => now(),
            ])->save();
        }
        if ($envelope['event'] === 'site.changed') {
            $name = (string) ($p['name'] ?? '');
            $to = $p['to_version'] ?? null;
            if ($to !== null && $name === 'woocommerce') {
                $site->forceFill(['wc_version' => $to])->save();
            } elseif ($to !== null && ($p['change'] ?? '') === 'wp_updated') {
                $site->forceFill(['wp_version' => $to])->save();
            }
        }
    }
}
