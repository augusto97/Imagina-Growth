<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Http;

use App\Domain\Campaigns\Models\Campaign;
use App\Support\Ids\PrefixedId;

/** Forma JSON de una campaña para la SPA. */
final class CampaignPresenter
{
    public const TEMPLATE_PREFIX = 'tpl';

    /** @return array<string, mixed> */
    public static function make(Campaign $c, bool $detail = false): array
    {
        $data = [
            'id' => $c->publicId(),
            'name' => $c->name,
            'channel' => $c->channel,
            'status' => $c->status,
            'send_mode' => $c->send_mode,
            'send_at' => $c->send_at?->toIso8601String(),
            'started_at' => $c->started_at?->toIso8601String(),
            'finished_at' => $c->finished_at?->toIso8601String(),
            'holdout_pct' => $c->holdout_pct,
            'is_ab' => $c->hasAbTest(),
            'winner_variant' => $c->winner_variant,
            'recipients_estimate' => $c->compliance_check['will_send'] ?? null,
            'cost_estimate_usd' => $c->cost_estimate['total_usd'] ?? null,
            'summary' => $c->stats['summary'] ?? null,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
        if ($detail) {
            $data += [
                'audience' => $c->audience,
                'template_id' => self::template($c->template_id),
                'variants' => array_map(fn (array $v) => ['key' => $v['key'], 'template_id' => self::template($v['template_id']), 'weight' => $v['weight']], $c->variants === null ? [] : $c->variantList()),
                'ab_config' => $c->ab_config,
                'cost_estimate' => $c->cost_estimate,
                'compliance_check' => $c->compliance_check,
                'editable' => $c->isEditable(),
            ];
        }

        return $data;
    }

    public static function template(?string $id): ?string
    {
        return $id === null ? null : PrefixedId::encode(self::TEMPLATE_PREFIX, $id);
    }

    /** Acepta `tpl_…` o el ULID interno; devuelve el interno. */
    public static function templateKey(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return PrefixedId::decode(self::TEMPLATE_PREFIX, $id) ?? (PrefixedId::isUlid($id) ? $id : null);
    }
}
