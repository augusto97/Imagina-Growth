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
        // Lo que falte (un widget creado a mano o con una forma anterior) se completa con los valores de su tipo, para
        // que el constructor siempre reciba la configuración entera.
        $variants = array_map(fn (array $v): array => [
            'design' => array_replace($defaults['design'], $v['design']),
            'content' => array_replace($defaults['content'], $v['content']),
        ] + $v, WidgetSchema::variants($widget));

        return $out + [
            'targeting' => array_replace($defaults['targeting'], (array) ($widget->targeting ?? [])),
            'variants' => $variants,
            'consent_config' => array_replace($defaults['consent_config'], (array) ($widget->consent_config ?? [])),
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
