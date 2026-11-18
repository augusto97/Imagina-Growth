<?php

declare(strict_types=1);

namespace App\Domain\Flows\Http;

use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowVersion;
use App\Domain\Flows\Support\FlowSecrets;

/** Forma JSON de un flujo para la SPA. Los IDs internos de exclusiones salen como públicos. */
final class FlowPresenter
{
    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    public static function summary(Flow $flow, array $stats = []): array
    {
        return [
            'id' => $flow->publicId(),
            'name' => $flow->name,
            'description' => $flow->description,
            'status' => $flow->status,
            'template_key' => $flow->template_key,
            'trigger' => $flow->trigger,
            'goal' => $flow->goal,
            'holdout_pct' => $flow->holdout_pct,
            'has_published' => $flow->current_version_id !== null,
            'activated_at' => $flow->activated_at?->toIso8601String(),
            'updated_at' => $flow->updated_at?->toIso8601String(),
            'stats' => $stats + ['entered' => 0, 'live' => 0, 'converted' => 0],
        ];
    }

    /**
     * @param  array<string, list<string>>  $issues
     * @return array<string, mixed>
     */
    public static function detail(Flow $flow, array $issues, bool $canManage): array
    {
        $draft = $flow->draftVersion();
        $current = $flow->currentVersion();
        $versions = FlowVersion::query()->where('flow_id', $flow->id)->whereNotNull('published_at')->orderByDesc('version')->limit(50)->get();
        $exclusions = Flow::query()->whereKey((array) ($flow->exclusions ?? []))->get()->map(fn (Flow $f) => $f->publicId())->values()->all();

        return self::summary($flow) + [
            'reentry_policy' => $flow->reentry_policy ?? ['mode' => 'once'],
            'exclusions' => $exclusions,
            'graph' => ($draft ?? $current) === null ? ['nodes' => [], 'edges' => []] : ($draft ?? $current)->graph,
            'has_draft' => $draft !== null,
            'draft_version' => $draft?->version,
            'current_version' => $current === null ? null : ['id' => $current->publicId(), 'version' => $current->version, 'published_at' => $current->published_at?->toIso8601String()],
            'versions' => $versions->map(fn (FlowVersion $v) => ['id' => $v->publicId(), 'version' => $v->version, 'published_at' => $v->published_at?->toIso8601String()])->values()->all(),
            'issues' => (object) $issues,
            'webhook' => $canManage && ($flow->trigger['type'] ?? null) === 'webhook'
                ? ['url' => rtrim((string) config('app.url'), '/').'/webhooks/flows/'.FlowSecrets::inboundToken($flow)]
                : null,
            'webhook_secret' => $canManage ? FlowSecrets::webhookSecret($flow) : null,
        ];
    }
}
