<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa\Concerns;

use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;

/** Formas JSON compartidas por los controladores de la SPA (IDs con prefijo). */
final class Presenters
{
    /** @return array<string, mixed> */
    public static function workspace(Workspace $ws, ?WorkspaceRole $role = null): array
    {
        return [
            'id' => $ws->publicId(),
            'name' => $ws->name,
            'slug' => $ws->slug,
            'account_id' => 'acc_'.$ws->account_id,
            'country' => $ws->country,
            'timezone' => $ws->timezone,
            'currency' => $ws->currency,
            'locale' => $ws->locale,
            'industry' => $ws->getAttribute('industry'),
            'legal_name' => $ws->getAttribute('legal_name'),
            'privacy_policy_url' => $ws->getAttribute('privacy_policy_url'),
            'settings' => $ws->settings ?? [],
            'status' => $ws->status,
            'role' => $role?->value,
            'abilities' => $role?->grantedAbilities() ?? [],
            'requires_two_factor' => $role?->requiresTwoFactor() ?? false,
        ];
    }

    /** @return array<string, mixed> */
    public static function site(Site $site, bool $detail = false): array
    {
        $data = [
            'id' => $site->publicId(),
            'name' => $site->name,
            'url' => $site->url,
            'host' => $site->host,
            'platform' => $site->platform,
            'status' => $site->status,
            'plugin_version' => $site->getAttribute('plugin_version'),
            'wp_version' => $site->getAttribute('wp_version'),
            'wc_version' => $site->getAttribute('wc_version'),
            'connected_at' => $site->connected_at?->toIso8601String(),
            'last_seen_at' => $site->last_seen_at?->toIso8601String(),
            'last_event_at' => $site->last_event_at?->toIso8601String(),
            'backfill' => self::backfill($site->backfill_state),
        ];
        if ($detail) {
            $data += [
                'public_key' => $site->public_key,
                'rest_url' => $site->rest_url,
                'timezone' => $site->timezone,
                'currency' => $site->currency,
                'php_version' => $site->getAttribute('php_version'),
                'capabilities' => $site->capabilities ?? [],
                'settings' => $site->effectiveSettings(),
                'health' => $site->health ?? [],
                'secret_rotated_at' => $site->getAttribute('secret_rotated_at')?->toIso8601String(),
            ];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>|null
     */
    public static function backfill(?array $state): ?array
    {
        if ($state === null) {
            return null;
        }
        $entities = is_array($state['entities'] ?? null) ? $state['entities'] : [];
        $total = 0;
        $sent = 0;
        foreach ($entities as $e) {
            $total += (int) ($e['total'] ?? 0);
            $sent += (int) ($e['sent'] ?? 0);
        }

        return [
            'status' => $state['status'] ?? null,
            'phase' => $state['phase'] ?? null,
            'from' => $state['from'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
            'entities' => $entities,
            'progress' => $total > 0 ? round($sent / $total, 4) : null,
            'error' => $state['error'] ?? null,
            'reported_at' => $state['reported_at'] ?? null,
        ];
    }
}
