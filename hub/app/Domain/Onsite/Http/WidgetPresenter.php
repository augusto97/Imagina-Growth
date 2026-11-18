<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Http;

use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Support\WidgetSchema;
use App\Domain\Sites\Models\Site;
use App\Support\Ids\PrefixedId;

/** Un widget para la SPA (con toda su configuración en el detalle). */
final class WidgetPresenter
{
    /** @return array<string, mixed> */
    public static function make(OnsiteWidget $widget, bool $detail = false): array
    {
        $out = [
            'id' => $widget->publicId(),
            'name' => $widget->name,
            'kind' => $widget->kind,
            'status' => $widget->status,
            'live' => $widget->isLive(),
            'priority' => $widget->priority,
            'site_id' => $widget->site_id !== null ? PrefixedId::encode(Site::ID_PREFIX, $widget->site_id) : null,
            'variants_count' => count(WidgetSchema::variants($widget)),
            'gives_coupon' => WidgetSchema::givesCoupon($widget),
            'starts_at' => $widget->starts_at?->toIso8601String(),
            'ends_at' => $widget->ends_at?->toIso8601String(),
            'updated_at' => $widget->updated_at?->toIso8601String(),
            'created_at' => $widget->created_at?->toIso8601String(),
        ];
        if (! $detail) {
            return $out;
        }
        $defaults = WidgetSchema::defaults($widget->kind);

        return $out + [
            'targeting' => $widget->targeting ?? $defaults['targeting'],
            'variants' => WidgetSchema::variants($widget),
            'consent_config' => $widget->consent_config ?? $defaults['consent_config'],
            'coupon_config' => $widget->coupon_config ?? $defaults['coupon_config'],
            'quiz' => $widget->kind === 'quiz' ? [
                'questions' => $widget->quiz->questions ?? [],
                'outcomes' => $widget->quiz->outcomes ?? WidgetSchema::sampleQuiz()['outcomes'],
            ] : null,
            'ab' => [
                'auto_winner' => (bool) ($widget->stats['auto_winner'] ?? false),
                'winner' => $widget->stats['winner'] ?? null,
            ],
        ];
    }
}
